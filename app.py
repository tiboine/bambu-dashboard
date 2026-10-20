import os
import json
import time
import ssl
import shutil
import socket
import threading
import base64
from datetime import datetime, timezone
from urllib.parse import urlparse
import requests
import paho.mqtt.client as mqtt
from flask import Flask, jsonify, render_template, request as flask_request
from dotenv import load_dotenv
from pathlib import Path

load_dotenv()

app = Flask(__name__)
def _app_version():
    """Git-commit + oppstartstid. Endres ved hver restart, så kiosken laster seg selv på nytt."""
    try:
        import subprocess
        commit = subprocess.run(["git", "rev-parse", "--short", "HEAD"], cwd=Path(__file__).parent,
                                capture_output=True, text=True, timeout=5).stdout.strip()
    except Exception:
        commit = ""
    return f"{commit or 'ukjent'}-{int(time.time())}"

APP_VERSION = _app_version()

TOKEN_FILE  = Path(__file__).parent / ".token.json"
STATS_FILE  = Path(__file__).parent / "stats_store.json"
STATS_BAK   = STATS_FILE.with_suffix(".json.bak")

WP_URL     = os.getenv("WP_URL", "").rstrip("/")
WP_API_KEY = os.getenv("WP_API_KEY", "")
WP_PUSH_MAX_PER_SYNC = 50   # maks antall WP-push per sync (unngå flom ved første kjøring)

# Bambu task-status: 0/1 = opprettet/pågår, 2 = fullført. Alt annet (3 = feilet,
# 4 = avbrutt, ukjente koder) regnes som ferdig men mislykket, slik som før.
TASK_SUCCESS = 2
TASK_RUNNING = (0, 1)

def is_final_status(status):
    return status not in TASK_RUNNING and status is not None

# ── Lokal stats-lagring ──────────────────────────────────────────
# Full task-liste lagres lokalt. Bambu API brukes kun for å fylle hull.
# Schema: {
#   "tasks":   { "<id>": {id, deviceId, title, startTime, costTime, weight, length, status, cover, wp_synced} },
#   "devices": { "<dev_id>": {name, model} },
#   "sync":    { boundary, complete, newest, api_total, last_sync }
# }
_sync_lock = threading.Lock()   # én sync av gangen
_store_unreadable = False       # True hvis både fil og .bak er korrupt – da lagrer vi ikke (unngå å slette historikk)

def default_stats_store():
    return {
        "tasks":   {},
        "devices": {},
        "sync":    {"boundary": None, "complete": False, "newest": None, "api_total": 0, "last_sync": None},
    }

def _parse_store(data):
    if "tasks" in data:
        return data
    # Migrer fra gammel aggregat-only schema: behold enhetsnavn, resync tasks
    migrated = default_stats_store()
    for dev_id, s in data.get("stats", {}).items():
        migrated["devices"][dev_id] = {
            "name":  s.get("name", dev_id),
            "model": s.get("model", "Ukjent"),
        }
    return migrated

def load_stats_store():
    global _store_unreadable
    if not STATS_FILE.exists() and not STATS_BAK.exists():
        return default_stats_store()
    for path in (STATS_FILE, STATS_BAK):
        try:
            if path.exists():
                data = _parse_store(json.loads(path.read_text()))
                if path == STATS_BAK:
                    print(f"[Stats] Bruker sikkerhetskopi {STATS_BAK.name}")
                return data
        except Exception as e:
            print(f"[Stats] Kunne ikke lese {path.name}: {e}")
    # Begge filer finnes men er uleselige – ikke overskriv dem
    _store_unreadable = True
    print("[Stats] Lagring er korrupt – lagring deaktivert til filene er fikset")
    return default_stats_store()

def save_stats_store(store):
    if _store_unreadable:
        print("[Stats] Hopper over lagring (eksisterende fil er uleselig)")
        return
    try:
        tmp = STATS_FILE.with_suffix(".json.tmp")
        with open(tmp, "w") as f:
            json.dump(store, f)
            f.flush()
            os.fsync(f.fileno())
        # Ta vare på forrige fil som .bak, men kun hvis den er gyldig
        if STATS_FILE.exists():
            try:
                json.loads(STATS_FILE.read_text())
                shutil.copy2(STATS_FILE, STATS_BAK)
            except Exception:
                pass
        os.replace(tmp, STATS_FILE)  # atomisk: lesere ser enten gammel eller ny fil
    except Exception as e:
        print(f"[Stats] Kunne ikke lagre stats_store.json: {e}")

def merge_task_into_store(store, task):
    tid = task.get("id")
    if tid is None:
        return
    old = store["tasks"].get(str(tid), {})
    status = task.get("status", 0)
    store["tasks"][str(tid)] = {
        "id":        tid,
        "deviceId":  task.get("deviceId", "unknown"),
        "title":     task.get("title") or task.get("name") or "",
        "startTime": task.get("startTime", ""),
        "endTime":   task.get("endTime", ""),
        "costTime":  task.get("costTime", 0) or 0,
        "weight":    task.get("weight", 0)   or 0,
        "length":    task.get("length", 0)   or 0,
        "status":    status,
        "cover":     task.get("cover", ""),
        # Behold synk-flagget bare hvis statusen ikke har endret seg siden push
        "wp_synced": bool(old.get("wp_synced")) and old.get("status") == status,
    }

def push_unsynced_to_wordpress(store):
    """Push ferdige (ikke pågående) tasks som ikke er synket til WP. Kjøres i sync-tråden.
    Setter wp_synced kun ved HTTP 2xx. Returnerer antall pushet."""
    if not WP_URL or not WP_API_KEY:
        return 0
    pending = [t for t in store["tasks"].values()
               if not t.get("wp_synced") and is_final_status(t.get("status"))]
    pending.sort(key=lambda t: t.get("startTime", ""), reverse=True)
    pushed = 0
    for task in pending[:WP_PUSH_MAX_PER_SYNC]:
        payload = {k: v for k, v in task.items() if k != "wp_synced"}
        try:
            resp = requests.post(
                f"{WP_URL}/wp-json/bambu/v1/push-print",
                json=payload,
                headers={"X-Bambu-Key": WP_API_KEY, "Content-Type": "application/json"},
                timeout=10,
            )
        except Exception as e:
            print(f"[WP] Push feilet, prøver igjen ved neste sync: {e}")
            break  # WP utilgjengelig – ikke prøv resten nå
        if 200 <= resp.status_code < 300:
            task["wp_synced"] = True
            pushed += 1
        else:
            print(f"[WP] Push av {task.get('id')} feilet (HTTP {resp.status_code})")
            if resp.status_code in (401, 403):
                break  # feil nøkkel – resten vil også feile
    if pushed:
        print(f"[WP] Pushet {pushed} tasks ({len(pending) - pushed} gjenstår)")
    return pushed


def bambu_get(path, **kwargs):
    """GET mot Bambu API. Ved 401 fornyes token én gang og kallet prøves på nytt."""
    resp = requests.get(f"{BAMBU_API}{path}", headers=headers(), **kwargs)
    if resp.status_code == 401 and handle_unauthorized():
        resp = requests.get(f"{BAMBU_API}{path}", headers=headers(), **kwargs)
    resp.raise_for_status()
    return resp


def fetch_task_page_raw(after=None):
    """Henter én side med tasks direkte fra Bambu API."""
    params = {"limit": 100}
    if after:
        params["after"] = after
    resp = bambu_get("/v1/user-service/my/tasks", params=params, timeout=20)
    data = resp.json()
    return data.get("hits", []), data.get("total", 0)

def sync_stats_store():
    """Fyll lokal lagring fra Bambu API. Server er kun kilde for nye/manglende tasks."""
    if not state["token"]:
        return
    if not _sync_lock.acquire(blocking=False):
        return  # en sync kjører allerede
    try:
        store = load_stats_store()
        with _state_lock:
            devices = {k: dict(v) for k, v in state["devices"].items()}

        # Oppdater enhets-metadata fra bind-API
        for dev_id, dev in devices.items():
            store["devices"][dev_id] = {
                "name":  dev.get("name", dev_id),
                "model": dev.get("model", "Ukjent"),
            }

        # Hent ferske tasks (nyeste først)
        try:
            recent, api_total = fetch_task_page_raw()
        except Exception as e:
            print(f"[Stats] Kunne ikke hente tasks: {e}")
            return
        if api_total:
            store["sync"]["api_total"] = api_total
        if not recent:
            push_unsynced_to_wordpress(store)
            save_stats_store(store)
            return

        for task in recent:
            merge_task_into_store(store, task)
        boundary_id = recent[-1].get("id")

        # Historikk: fyll bakover til komplett, med loop-deteksjon og tidsbudsjett
        if not store["sync"]["complete"] and boundary_id:
            after = store["sync"]["boundary"] or boundary_id
            deadline = time.time() + 25
            seen = set()
            while time.time() < deadline:
                try:
                    hits, _ = fetch_task_page_raw(after)
                except Exception:
                    break
                if not hits:
                    store["sync"]["complete"] = True
                    break
                first_id = hits[0].get("id")
                if first_id in seen:
                    store["sync"]["complete"] = True
                    break
                seen.add(first_id)
                for task in hits:
                    merge_task_into_store(store, task)
                store["sync"]["boundary"] = hits[-1].get("id")
                if len(hits) < 100:
                    store["sync"]["complete"] = True
                    break
                after = store["sync"]["boundary"]

        store["sync"]["newest"]    = boundary_id
        store["sync"]["last_sync"] = time.strftime("%Y-%m-%dT%H:%M:%S")

        save_stats_store(store)
        print(f"[Stats] Lagret {len(store['tasks'])} tasks. API-total: {store['sync']['api_total']}, komplett: {store['sync']['complete']}")

        # Push til WordPress etter lagring, og lagre wp_synced-flaggene
        if push_unsynced_to_wordpress(store):
            save_stats_store(store)
    finally:
        _sync_lock.release()


def aggregate_stats(store):
    """Beregn per-enhet aggregater + siste tasks fra lagret data."""
    agg = {}
    for task in store.get("tasks", {}).values():
        dev_id = task.get("deviceId", "unknown")
        if dev_id not in agg:
            meta = store.get("devices", {}).get(dev_id, {})
            agg[dev_id] = {
                "device_id":       dev_id,
                "name":            meta.get("name", dev_id),
                "model":           meta.get("model", "Ukjent"),
                "total_prints":    0,
                "successful":      0,
                "failed":          0,
                "total_time_s":    0,
                "total_weight_g":  0,
                "total_length_cm": 0,
                "_tasks":          [],
            }
        d = agg[dev_id]
        d["_tasks"].append(task)
        status = task.get("status")
        if not is_final_status(status):
            continue  # pågående/ukjent: ikke med i tellinger eller summer
        d["total_prints"] += 1
        d["successful" if status == TASK_SUCCESS else "failed"] += 1
        d["total_time_s"]    += task.get("costTime", 0) or 0
        d["total_weight_g"]  += task.get("weight", 0)   or 0
        d["total_length_cm"] += task.get("length", 0)   or 0

    for d in agg.values():
        d["_tasks"].sort(key=lambda t: t.get("startTime", ""), reverse=True)
        d["tasks"] = [
            {
                "title":      t.get("title", ""),
                "status":     t.get("status", 0),
                "start_time": t.get("startTime", ""),
                "cost_time":  t.get("costTime", 0),
            }
            for t in d["_tasks"][:10]
        ]
        del d["_tasks"]
    return agg


@app.after_request
def add_response_headers(response):
    # CORS kun for lese-endepunkter (gammel WP-plugin leser dem fra annet domene)
    if flask_request.method in ("GET", "HEAD"):
        response.headers["Access-Control-Allow-Origin"] = "*"
        response.headers["Access-Control-Allow-Methods"] = "GET"
    # Kiosk-Chromium cachet gamle sider – aldri cache HTML
    if response.mimetype == "text/html":
        response.headers["Cache-Control"] = "no-store"
    return response


def is_same_origin():
    """POST-endepunkter godtar kun forespørsler uten Origin eller fra samme vert."""
    origin = flask_request.headers.get("Origin")
    if not origin:
        return True
    return urlparse(origin).netloc == flask_request.host

BAMBU_API = "https://api.bambulab.com"
MQTT_BROKER = "us.mqtt.bambulab.com"
MQTT_PORT = 8883

state = {
    "token": None,
    "token_expires": 0,
    "user_id": None,
    "devices": {},       # dev_id -> device info from bind API
    "mqtt_status": {},   # dev_id -> live MQTT status
    "last_updated": None,
    "error": None,
    "needs_verify_code": False,
    "mqtt_connected": False,
}

mqtt_client = None
_mqtt_lock = threading.Lock()      # start/stopp av MQTT-klient
_state_lock = threading.RLock()    # beskytter state["devices"] og state["mqtt_status"]
_mqtt_down_since = None            # tidspunkt MQTT sist mistet/manglet forbindelse
MQTT_RESTART_AFTER = 300           # sekunder frakoblet før vi lager ny klient (ellers reconnecter paho selv)


# ── Token-persistering ──

def save_token():
    try:
        TOKEN_FILE.write_text(json.dumps({
            "token": state["token"],
            "user_id": state["user_id"],
            "token_expires": state["token_expires"],
        }))
        os.chmod(TOKEN_FILE, 0o600)
    except Exception as e:
        print(f"[Advarsel] Kunne ikke lagre token: {e}")


def load_token():
    try:
        if TOKEN_FILE.exists():
            data = json.loads(TOKEN_FILE.read_text())
            if data.get("token") and data.get("token_expires", 0) > time.time():
                state["token"] = data["token"]
                state["user_id"] = data.get("user_id")
                state["token_expires"] = data["token_expires"]
                print(f"[Info] Lastet lagret token (utløper om {int((data['token_expires'] - time.time()) / 86400)} dager)")
                return True
    except Exception as e:
        print(f"[Advarsel] Kunne ikke laste token: {e}")
    return False


# ── Auth ──

def send_verify_code(email):
    """Be Bambu sende innloggingskode på e-post. Kaster ValueError med Bambus svar ved feil."""
    resp = requests.post(
        f"{BAMBU_API}/v1/user-service/user/sendemail/code",
        json={"email": email, "type": "codeLogin"},
        timeout=10,
    )
    if not resp.ok:
        try:
            msg = resp.json().get("message") or ""
        except ValueError:
            msg = resp.reason or ""
        raise ValueError(f"HTTP {resp.status_code}{': ' + str(msg)[:200] if msg else ''}")


def login():
    email = os.getenv("BAMBU_EMAIL")
    password = os.getenv("BAMBU_PASSWORD")
    if not email or not password:
        raise ValueError("BAMBU_EMAIL og BAMBU_PASSWORD må settes i .env-filen")

    resp = requests.post(
        f"{BAMBU_API}/v1/user-service/user/login",
        json={"account": email, "password": password},
        timeout=10,
    )
    try:
        data = resp.json()
    except ValueError:
        data = {}
    if not resp.ok:
        # Ta med Bambus egen forklaring (feilsvar inneholder ingen token)
        msg = data.get("message") or data.get("error") or resp.reason or ""
        raise ValueError(f"Bambu avviste innlogging (HTTP {resp.status_code}){': ' + str(msg)[:200] if msg else ''}")

    login_type = data.get("loginType", "")

    if login_type in ("verifyCode", "tfa"):
        state["needs_verify_code"] = True
        try:
            send_verify_code(email)
            state["error"] = "Verifiseringskode sendt til e-post. Skriv inn koden nedenfor."
        except Exception as e:
            state["error"] = f"Bambu krever kode, men utsending feilet: {e}"
        return

    _store_token(data)


def _store_token(data):
    token = data.get("accessToken") or data.get("access_token")
    if not token:
        # Ikke ta med selve responsen – den kan inneholde hemmeligheter
        raise ValueError(f"Fikk ikke token fra Bambu API (loginType: {data.get('loginType') or 'ukjent'})")
    state["token"] = token
    try:
        expires_in = int(data.get("expiresIn") or 0)
    except (TypeError, ValueError):
        expires_in = 0
    state["token_expires"] = time.time() + (expires_in if expires_in > 0 else 86400 * 90)
    state["needs_verify_code"] = False
    state["error"] = None

    # Hent user ID fra JWT (før save_token)
    try:
        payload = token.split(".")[1]
        payload += "=" * (-len(payload) % 4)
        decoded = json.loads(base64.urlsafe_b64decode(payload))
        state["user_id"] = str(decoded.get("sub") or decoded.get("uid") or "")
    except Exception:
        # Fallback: hent fra preference-endepunkt
        try:
            resp = requests.get(
                f"{BAMBU_API}/v1/design-user-service/my/preference",
                headers={"Authorization": f"Bearer {token}"},
                timeout=10,
            )
            resp.raise_for_status()
            state["user_id"] = str(resp.json().get("uid", ""))
        except Exception:
            pass

    save_token()


def verify_code(code):
    email = os.getenv("BAMBU_EMAIL")
    resp = requests.post(
        f"{BAMBU_API}/v1/user-service/user/login",
        json={"account": email, "code": code},
        timeout=10,
    )
    resp.raise_for_status()
    _store_token(resp.json())


def ensure_token():
    if not state["token"] or time.time() > state["token_expires"]:
        login()


def headers():
    return {"Authorization": f"Bearer {state['token']}"}


_reauth_lock = threading.Lock()

def handle_unauthorized():
    """Token avvist (HTTP 401): logg inn på nytt én gang og restart MQTT. True hvis ny token."""
    with _reauth_lock:
        print("[Auth] Token avvist (401) – logger inn på nytt")
        state["token"] = None
        state["token_expires"] = 0
        if state["needs_verify_code"]:
            return False
        try:
            login()
        except Exception as e:
            state["error"] = f"Innlogging feilet: {e}"
            return False
        if not state["token"]:
            return False  # krever verifiseringskode
        start_mqtt()
        return True


# ── Device list (REST, kjøres én gang) ──

def fetch_device_list():
    ensure_token()

    bind_resp = bambu_get("/v1/iot-service/api/user/bind", timeout=10)
    devices = bind_resp.json().get("devices", [])

    with _state_lock:
        for dev in devices:
            dev_id = dev["dev_id"]
            state["devices"][dev_id] = {
                "id": dev_id,
                "name": dev.get("name", dev_id),
                "model": dev.get("dev_product_name", "Ukjent"),
                "online": dev.get("online", False),
            }

    state["last_updated"] = time.strftime("%H:%M:%S")
    state["error"] = None
    print(f"[Info] Hentet {len(devices)} enheter fra Bambu Lab")


# ── MQTT for sanntidsstatus ──

def safe_callback(fn):
    """Fang alle feil i MQTT-callbacks så nettverkstråden ikke dør."""
    def wrapper(*args, **kwargs):
        try:
            return fn(*args, **kwargs)
        except Exception as e:
            print(f"[MQTT] Feil i {fn.__name__}: {e}")
    wrapper.__name__ = fn.__name__
    return wrapper


@safe_callback
def on_connect(client, userdata, flags, reason_code, properties=None):
    global _mqtt_down_since
    # paho v2 kaller on_connect også når CONNACK avvises
    if reason_code.is_failure:
        print(f"[MQTT] Tilkobling avvist (reason: {reason_code})")
        state["mqtt_connected"] = False
        state["error"] = f"MQTT-tilkobling avvist: {reason_code}"
        return

    print(f"[MQTT] Koblet til (reason: {reason_code})")
    state["mqtt_connected"] = True
    state["error"] = None
    _mqtt_down_since = None

    with _state_lock:
        dev_ids = list(state["devices"])

    # Abonner på alle enheter
    for dev_id in dev_ids:
        topic = f"device/{dev_id}/report"
        client.subscribe(topic)
        print(f"[MQTT] Abonnerer på {topic}")

    # Be om full status-dump fra alle printere
    time.sleep(1)
    for dev_id in dev_ids:
        request_topic = f"device/{dev_id}/request"
        pushall = json.dumps({
            "pushing": {
                "sequence_id": "0",
                "command": "pushall",
                "version": 1,
                "push_target": 1,
            }
        })
        client.publish(request_topic, pushall)
        print(f"[MQTT] Sendte pushall til {dev_id}")


@safe_callback
def on_disconnect(client, userdata, flags, reason_code, properties=None):
    global _mqtt_down_since
    print(f"[MQTT] Frakoblet (reason: {reason_code})")
    state["mqtt_connected"] = False
    if _mqtt_down_since is None:
        _mqtt_down_since = time.time()


def _has_tray_data(ams):
    return isinstance(ams, dict) and any(
        isinstance(u, dict) and u.get("tray") for u in ams.get("ams", []) or []
    )


@safe_callback
def on_message(client, userdata, msg):
    try:
        data = json.loads(msg.payload)
    except Exception:
        return

    # Finn dev_id fra topic: device/{dev_id}/report
    parts = msg.topic.split("/")
    if len(parts) < 2:
        return
    dev_id = parts[1]

    print_data = data.get("print", {})
    if not print_data:
        return

    job_finished = False
    with _state_lock:
        # Oppdater MQTT-status (merge delta-oppdateringer)
        current = state["mqtt_status"].setdefault(dev_id, {})

        # AMS-delta uten tray-data skal ikke overskrive kjente filamentfarger
        if "ams" in print_data:
            print_data = dict(print_data)
            incoming = print_data.pop("ams")
            old_ams = current.get("ams") or {}
            if _has_tray_data(incoming):
                current["ams"] = {**old_ams, **incoming}
            elif isinstance(incoming, dict):
                current["ams"] = {**old_ams, **{k: v for k, v in incoming.items() if k != "ams"}}

        prev_state = current.get("gcode_state", "")
        current.update(print_data)
        new_state = current.get("gcode_state", "")

        # Registrer tidspunkt når en ny jobb starter
        if new_state in ("RUNNING", "PREPARE") and prev_state not in ("RUNNING", "PREPARE"):
            current["job_started_at"] = time.strftime("%Y-%m-%dT%H:%M:%S")
            if prev_state:  # ekte overgang (ikke første melding etter oppstart)
                current["job_started_epoch"] = time.time()
                # Hent ny task (bilde + filamentplan) snart, ikke vent på neste minutt-runde
                threading.Timer(20, fetch_task_covers).start()
        # Nullstill job_started_at når jobben er ferdig/avbrutt
        elif new_state in ("IDLE", "FAILED", "FINISH") and prev_state in ("RUNNING", "PREPARE", "PAUSE"):
            current["job_started_at"] = None
            current["ended_at"] = time.time()  # brukes for å slippe gammel feil/ferdig-status etter en stund
            job_finished = True
        elif new_state in ("RUNNING", "PREPARE"):
            current.pop("ended_at", None)
        if new_state not in ("RUNNING", "PREPARE", "PAUSE"):
            current.pop("job_started_epoch", None)

        # Oppdater online-status
        if dev_id in state["devices"]:
            state["devices"][dev_id]["online"] = True

    if job_finished:
        threading.Thread(target=sync_stats_store, daemon=True).start()

    state["last_updated"] = time.strftime("%H:%M:%S")


def _stop_client_locked():
    """Stopp eksisterende klient. Må kalles med _mqtt_lock holdt."""
    global mqtt_client
    if mqtt_client:
        try:
            mqtt_client.loop_stop()
            mqtt_client.disconnect()
        except Exception as e:
            print(f"[MQTT] Feil ved stopp av klient: {e}")
        mqtt_client = None
    state["mqtt_connected"] = False


def start_mqtt():
    """(Re)start MQTT. Eksisterende klient stoppes først så vi ikke får flere parallelle."""
    global mqtt_client, _mqtt_down_since

    if not state["token"] or not state["user_id"]:
        print("[MQTT] Mangler token eller user_id, kan ikke koble til")
        return

    with _mqtt_lock:
        _stop_client_locked()
        _mqtt_down_since = time.time()  # nullstilles i on_connect ved suksess

        client = mqtt.Client(
            callback_api_version=mqtt.CallbackAPIVersion.VERSION2,
            client_id=f"dashboard_{int(time.time())}",
            protocol=mqtt.MQTTv311,
        )
        client.username_pw_set(
            f"u_{state['user_id']}",
            state["token"],
        )
        client.tls_set(cert_reqs=ssl.CERT_REQUIRED, tls_version=ssl.PROTOCOL_TLS_CLIENT)

        client.on_connect = on_connect
        client.on_disconnect = on_disconnect
        client.on_message = on_message

        try:
            client.connect(MQTT_BROKER, MQTT_PORT, keepalive=60)
            client.loop_start()
            mqtt_client = client
            print(f"[MQTT] Starter tilkobling til {MQTT_BROKER}...")
        except Exception as e:
            print(f"[MQTT] Feil ved tilkobling: {e}")
            state["error"] = f"MQTT-feil: {e}"


def stop_mqtt():
    with _mqtt_lock:
        _stop_client_locked()


def mqtt_needs_restart():
    """Ny klient trengs kun hvis ingen finnes, eller den har vært nede lenge (paho reconnecter selv ellers)."""
    if mqtt_client is None:
        return True
    if state["mqtt_connected"] or _mqtt_down_since is None:
        return False
    return time.time() - _mqtt_down_since > MQTT_RESTART_AFTER


# ── Hent forhåndsvisningsbilder fra tasks API ──

def fetch_task_covers():
    """Hent cover-bilder for aktive print-jobber fra tasks API."""
    if not state["token"]:
        return

    try:
        resp = bambu_get("/v1/user-service/my/tasks", params={"limit": 50}, timeout=10)
        tasks = resp.json().get("hits", [])

        # Lagre siste cover + nyeste task per device (første treff = nyeste oppgave).
        # Nyeste task brukes til å regne dagens filamentforbruk for pågående prints.
        covers = {}
        latest = {}
        for task in tasks:
            dev_id = task.get("deviceId")
            if not dev_id:
                continue
            # Kun nyeste task per printer – ikke fall tilbake på bildet fra en eldre print
            if dev_id not in latest:
                covers[dev_id] = task.get("cover") or None
                latest[dev_id] = {
                    "id":        str(task.get("id", "")),
                    "startTime": task.get("startTime", ""),
                    "weight":    task.get("weight", 0) or 0,
                    "length":    task.get("length", 0) or 0,
                    "status":    task.get("status"),
                    "endTime":   task.get("endTime", ""),
                }
        state["task_covers"] = {k: v for k, v in covers.items() if v}
        state["latest_tasks"] = latest
    except Exception as e:
        print(f"[Feil] Kunne ikke hente task covers: {e}")


# ── Bygg printer-liste fra devices + MQTT-data ──

STALE_FAIL_S   = 15 * 60  # vis "Feil" i 15 min etter at printen feilet
STALE_FINISH_S = 5 * 60   # "Ferdig" i 5 min (frontend viser grønn puls de første 2)


def _iso_to_epoch(iso):
    """Bambu ISO-tid (UTC) → unix-sekunder, eller None."""
    if not iso:
        return None
    try:
        dt = datetime.fromisoformat(str(iso).replace("Z", "+00:00"))
    except ValueError:
        return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.timestamp()


def build_printer_list():
    printers = []
    # Snapshot under lås – MQTT-tråden kan endre dataene samtidig
    with _state_lock:
        snapshot = [(dev_id, dict(dev), dict(state["mqtt_status"].get(dev_id, {})))
                    for dev_id, dev in state["devices"].items()]
    for dev_id, dev, mqtt_data in snapshot:

        gcode_state = mqtt_data.get("gcode_state", "")
        progress = mqtt_data.get("mc_percent")
        remaining_min = mqtt_data.get("mc_remaining_time")
        task_name = mqtt_data.get("subtask_name", "")
        layer = mqtt_data.get("layer_num")
        total_layers = mqtt_data.get("total_layer_num")
        nozzle_temp = mqtt_data.get("nozzle_temper")
        bed_temp = mqtt_data.get("bed_temper")
        spd_lvl = mqtt_data.get("spd_lvl")

        # Hent AMS filamentfarger
        ams_colors = []
        ams_data = mqtt_data.get("ams", {})
        for ams_unit in ams_data.get("ams", []):
            for tray in ams_unit.get("tray", []):
                color_hex = tray.get("tray_color", "")
                tray_type = tray.get("tray_type", "")
                if color_hex and color_hex != "00000000":
                    ams_colors.append({
                        "color": "#" + color_hex[:6],
                        "type": tray_type,
                    })
        # Ekstern spole
        vt = mqtt_data.get("vt_tray", {})
        vt_color = vt.get("tray_color", "")
        if vt_color and vt_color != "00000000":
            ams_colors.append({
                "color": "#" + vt_color[:6],
                "type": vt.get("tray_type", ""),
            })

        # Oversett gcode_state til print_status
        status_map = {
            "RUNNING": "RUNNING",
            "PREPARE": "RUNNING",
            "PAUSE": "PAUSED",
            "FINISH": "SUCCESS",
            "FAILED": "FAIL",
            "IDLE": "IDLE",
        }
        print_status = status_map.get(gcode_state, gcode_state or "")

        # Printeren rapporterer FAILED/FINISH helt til neste jobb starter. Etter en
        # stund er den bare ledig – ikke vis forrige print som om den var aktuell.
        if gcode_state in ("FAILED", "FINISH"):
            ended = mqtt_data.get("ended_at") or _iso_to_epoch(
                state.get("latest_tasks", {}).get(dev_id, {}).get("endTime"))
            keep = STALE_FAIL_S if gcode_state == "FAILED" else STALE_FINISH_S
            if not ended or time.time() - ended > keep:
                print_status = "IDLE"

        time_remaining = None
        if remaining_min is not None:
            try:
                time_remaining = int(remaining_min) * 60  # konverter til sekunder
            except (ValueError, TypeError):
                pass

        # Hent cover-bilde fra tasks API
        thumbnail = state.get("task_covers", {}).get(dev_id)
        # Ny jobb startet, men nyeste kjente task startet før den → ny task ikke hentet
        # ennå, så bildet tilhører forrige print. (Kun når vi selv så jobben starte.)
        started = mqtt_data.get("job_started_epoch")
        task_start = _iso_to_epoch(state.get("latest_tasks", {}).get(dev_id, {}).get("startTime"))
        if print_status in ("RUNNING", "PAUSED") and started and task_start and task_start < started - 300:
            thumbnail = None
        if print_status in ("IDLE", "") or not dev.get("online", False):
            thumbnail = None
            task_name = ""
            progress = None
            time_remaining = None
        job_started_at = mqtt_data.get("job_started_at")

        printers.append({
            "id": dev_id,
            "name": dev.get("name", dev_id),
            "model": dev.get("model", "Ukjent"),
            "online": dev.get("online", False),
            "print_status": print_status,
            "task_name": task_name or None,
            "progress": progress,
            "time_remaining": time_remaining,
            "job_started_at": job_started_at,
            "layer": layer,
            "total_layers": total_layers,
            "nozzle_temp": nozzle_temp,
            "bed_temp": bed_temp,
            "spd_lvl": spd_lvl,
            "ams_colors": ams_colors,
            "thumbnail": thumbnail,
        })

    return printers


# ── Bakgrunnstråd: periodisk refresh av enhetsliste ──

def wait_for_network(timeout=60):
    """Vent til internett er tilgjengelig (maks timeout sekunder)."""
    print("[Nettverk] Venter på internettforbindelse...")
    for i in range(timeout):
        try:
            # Timeout per socket (ikke globalt), og lukk socketen etterpå
            with socket.create_connection(("8.8.8.8", 53), timeout=3):
                pass
            print(f"[Nettverk] Tilkoblet etter {i}s")
            return True
        except OSError:
            time.sleep(1)
    print(f"[Nettverk] Ingen forbindelse etter {timeout}s – fortsetter likevel")
    return False


def init_connection():
    """Forsøk innlogging ved oppstart. Bruker lagret token hvis tilgjengelig."""
    wait_for_network(timeout=60)
    try:
        if not load_token():
            login()
        if state["token"]:
            fetch_device_list()
            fetch_task_covers()
            start_mqtt()
            # Kjør første stats-sync i bakgrunn slik at dagens tall fylles ASAP
            threading.Thread(target=sync_stats_store, daemon=True).start()
    except Exception as e:
        state["error"] = str(e)
        print(f"[Feil ved oppstart] {e}")


def poll_loop():
    # Vent litt før første kjøring (init_connection kjører først)
    time.sleep(30)
    stats_tick = 0
    while True:
        if not state["needs_verify_code"] and state["token"]:
            try:
                fetch_device_list()
                fetch_task_covers()
                if mqtt_needs_restart():
                    start_mqtt()
                # Sync stats hvert 5. minutt (eller første gang)
                stats_tick += 1
                if stats_tick == 1 or stats_tick % 5 == 0:
                    threading.Thread(target=sync_stats_store, daemon=True).start()
            except Exception as e:
                state["error"] = str(e)
                print(f"[Feil] {e}")
        time.sleep(60)


# ── Flask-ruter ──

@app.route("/")
def index():
    return render_template("index.html")


@app.route("/api/status")
def api_status():
    return jsonify({
        "printers": build_printer_list(),
        "last_updated": state["last_updated"],
        "error": state["error"],
        "needs_verify_code": state["needs_verify_code"],
        "mqtt_connected": state["mqtt_connected"],
        "app_version":    APP_VERSION,
    })


def local_date(iso_utc):
    """Konverter Bambu startTime (ISO, UTC) til lokal dato. None hvis ugyldig."""
    if not iso_utc:
        return None
    try:
        dt = datetime.fromisoformat(iso_utc.replace("Z", "+00:00"))
    except ValueError:
        return None
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone().date()


@app.route("/api/today")
def api_today():
    """Dagens printstatistikk: ferdige prints i dag + pågående prints regnet ut fra fremdrift."""
    now = datetime.now(timezone.utc)
    today = now.astimezone().date()  # lokal dato
    store = load_stats_store()
    prints = 0
    weight = 0.0
    time_s = 0
    length_cm = 0.0
    final_ids = set()

    # 1) Ferdige prints som startet i dag – fullt forbruk
    for tid, task in store.get("tasks", {}).items():
        if not is_final_status(task.get("status")):
            continue  # pågående telles live under
        final_ids.add(str(tid))
        if local_date(task.get("startTime", "")) != today:
            continue
        prints    += 1
        time_s    += task.get("costTime", 0) or 0
        weight    += task.get("weight", 0) or 0
        length_cm += task.get("length", 0) or 0

    # 2) Pågående prints – planlagt forbruk × fremdrift, tid = medgått tid så langt
    with _state_lock:
        live = [(dev_id, dict(m)) for dev_id, m in state["mqtt_status"].items()]
    latest = state.get("latest_tasks", {})
    active = 0
    for dev_id, m in live:
        if m.get("gcode_state") not in ("RUNNING", "PREPARE", "PAUSE"):
            continue
        active += 1
        prints += 1
        task = latest.get(dev_id)
        if not task or task["id"] in final_ids:
            continue  # task ikke hentet ennå (startet nettopp) – telles uten forbruk
        try:
            pct = max(0.0, min(100.0, float(m.get("mc_percent") or 0))) / 100
        except (TypeError, ValueError):
            pct = 0.0
        weight    += task["weight"] * pct
        length_cm += task["length"] * pct
        try:
            start = datetime.fromisoformat(task["startTime"].replace("Z", "+00:00"))
            if start.tzinfo is None:
                start = start.replace(tzinfo=timezone.utc)
            # Bare tiden som faller i dag (print som startet før midnatt)
            day_start = datetime.combine(today, datetime.min.time()).astimezone()
            time_s += max(0, int((now - max(start, day_start)).total_seconds()))
        except (ValueError, AttributeError):
            pass

    return jsonify({
        "prints":    prints,
        "active":    active,
        "weight_g":  round(weight, 1),
        "time_s":    time_s,
        "length_cm": round(length_cm, 1),
        "last_sync": store.get("sync", {}).get("last_sync"),
    })


@app.route("/stats")
def stats_page():
    return render_template("stats.html")


@app.route("/api/stats")
def api_stats():
    """Returner akkumulert statistikk fra lokal lagring."""
    store = load_stats_store()
    agg = aggregate_stats(store)
    total = sum(d["total_prints"] for d in agg.values())
    # Printere som ikke lenger er koblet til kontoen telles i totalene, men får ikke eget kort.
    # Bare når enhetslisten faktisk er hentet, ellers ville alt blitt skjult ved oppstart/API-feil.
    with _state_lock:
        current = set(state["devices"].keys())
    for d in agg.values():
        d["removed"] = bool(current) and d["device_id"] not in current
    return jsonify({
        "devices":       list(agg.values()),
        "total_tasks":   total,
        "api_total":     store.get("sync", {}).get("api_total", 0),
        "data_complete": store.get("sync", {}).get("complete", False),
        "last_sync":     store.get("sync", {}).get("last_sync"),
    })


@app.route("/api/ams-colors")
def api_ams_colors():
    """Returner nåværende AMS-filamentfarger per enhet."""
    result = {}
    with _state_lock:
        snapshot = [(dev_id, dict(d)) for dev_id, d in state["mqtt_status"].items()]
    for dev_id, mqtt_data in snapshot:
        colors = []
        ams_data = mqtt_data.get("ams", {})
        for ams_unit in ams_data.get("ams", []):
            for tray in ams_unit.get("tray", []):
                color_hex = tray.get("tray_color", "")
                tray_type = tray.get("tray_type", "")
                if color_hex and color_hex != "00000000":
                    colors.append({
                        "color": "#" + color_hex[:6],
                        "type": tray_type,
                    })
        if colors:
            result[dev_id] = colors
    return jsonify(result)


@app.route("/api/send-code", methods=["POST"])
def api_send_code():
    if not is_same_origin():
        return jsonify({"ok": False, "error": "Forespørsel fra annet domene avvist."}), 403
    email = os.getenv("BAMBU_EMAIL")
    if not email:
        return jsonify({"ok": False, "error": "BAMBU_EMAIL ikke satt."}), 400
    try:
        send_verify_code(email)
        state["needs_verify_code"] = True
        return jsonify({"ok": True})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 400


@app.route("/api/verify", methods=["POST"])
def api_verify():
    if not is_same_origin():
        return jsonify({"ok": False, "error": "Forespørsel fra annet domene avvist."}), 403
    data = flask_request.get_json(silent=True) or {}
    code = str(data.get("code", "")).strip()
    if not code:
        return jsonify({"ok": False, "error": "Ingen kode oppgitt."}), 400
    try:
        verify_code(code)
        fetch_device_list()
        fetch_task_covers()
        start_mqtt()
        threading.Thread(target=sync_stats_store, daemon=True).start()
        return jsonify({"ok": True})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 400


if __name__ == "__main__":
    init_connection()
    thread = threading.Thread(target=poll_loop, daemon=True)
    thread.start()
    app.run(host="0.0.0.0", port=5000, debug=False)
