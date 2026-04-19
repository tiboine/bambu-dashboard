import os
import json
import time
import ssl
import threading
import base64
import requests
import paho.mqtt.client as mqtt
from flask import Flask, jsonify, render_template, request as flask_request
from dotenv import load_dotenv
from pathlib import Path

load_dotenv()

app = Flask(__name__)
TOKEN_FILE  = Path(__file__).parent / ".token.json"
STATS_FILE  = Path(__file__).parent / "stats_store.json"

# ── Lokal stats-lagring ──────────────────────────────────────────
# Struktur: { "stats": {dev_id: {...}}, "boundary": last_id, "complete": bool, "newest": id }
_stats_lock  = threading.Lock()

def load_stats_store():
    try:
        if STATS_FILE.exists():
            return json.loads(STATS_FILE.read_text())
    except Exception as e:
        print(f"[Stats] Kunne ikke lese stats_store.json: {e}")
    return {"stats": {}, "boundary": None, "complete": False, "newest": None, "api_total": 0}

def save_stats_store(store):
    try:
        STATS_FILE.write_text(json.dumps(store))
    except Exception as e:
        print(f"[Stats] Kunne ikke lagre stats_store.json: {e}")

def merge_task_into_store(store, task, devices):
    dev_id = task.get("deviceId", "unknown")
    if dev_id not in store["stats"]:
        store["stats"][dev_id] = {
            "name":            devices.get(dev_id, {}).get("name", dev_id),
            "model":           devices.get(dev_id, {}).get("model", "Ukjent"),
            "total_prints":    0,
            "successful":      0,
            "failed":          0,
            "total_time_s":    0,
            "total_weight_g":  0,
            "total_length_cm": 0,
        }
    s = store["stats"][dev_id]
    s["total_prints"]    += 1
    s["successful" if task.get("status") == 2 else "failed"] += 1
    s["total_time_s"]    += task.get("costTime", 0) or 0
    s["total_weight_g"]  += task.get("weight", 0)   or 0
    s["total_length_cm"] += task.get("length", 0)   or 0

def fetch_task_page_raw(after=None):
    """Henter én side med tasks direkte fra Bambu API."""
    params = {"limit": 100}
    if after:
        params["after"] = after
    resp = requests.get(
        f"{BAMBU_API}/v1/user-service/my/tasks",
        headers=headers(),
        params=params,
        timeout=20,
    )
    resp.raise_for_status()
    data = resp.json()
    return data.get("hits", []), data.get("total", 0)

def sync_stats_store():
    """Synkroniserer lokal stats-lagring med Bambu API. Kjøres i bakgrunn."""
    if not state["token"]:
        return
    with _stats_lock:
        store = load_stats_store()
        devices = state.get("devices", {})

        # Hent ferske tasks (alltid)
        recent, api_total = fetch_task_page_raw()
        if api_total:
            store["api_total"] = api_total
        if not recent:
            return

        boundary_id = recent[-1].get("id")

        # Historikk: hent alt eldre enn de ferske, med loop-deteksjon
        if not store["complete"] and boundary_id:
            after = store["boundary"] or boundary_id
            deadline = time.time() + 25
            seen = set()
            while time.time() < deadline:
                try:
                    hits, _ = fetch_task_page_raw(after)
                except Exception:
                    break
                if not hits:
                    store["complete"] = True
                    break
                first_id = hits[0].get("id")
                if first_id in seen:
                    store["complete"] = True
                    break
                seen.add(first_id)
                for task in hits:
                    merge_task_into_store(store, task, devices)
                store["boundary"] = hits[-1].get("id")
                if len(hits) < 100:
                    store["complete"] = True
                    break
                after = store["boundary"]
            if not store.get("newest"):
                store["newest"] = boundary_id

        # Oppdater med nye tasks siden sist
        elif store["complete"] and store.get("newest") != boundary_id:
            stop = store["newest"]
            for task in recent:
                if task.get("id") == stop:
                    break
                merge_task_into_store(store, task, devices)
            store["newest"] = boundary_id

        # Oppdater enhetsnavn (kan ha endret seg)
        for dev_id, s in store["stats"].items():
            if dev_id in devices:
                s["name"]  = devices[dev_id].get("name", s["name"])
                s["model"] = devices[dev_id].get("model", s["model"])

        save_stats_store(store)
        print(f"[Stats] Lagret. API-total: {store['api_total']}, komplett: {store['complete']}")


@app.after_request
def add_cors_headers(response):
    response.headers["Access-Control-Allow-Origin"] = "*"
    response.headers["Access-Control-Allow-Headers"] = "Content-Type"
    response.headers["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS"
    return response

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


# ── Token-persistering ──

def save_token():
    try:
        TOKEN_FILE.write_text(json.dumps({
            "token": state["token"],
            "user_id": state["user_id"],
            "token_expires": state["token_expires"],
        }))
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
    resp.raise_for_status()
    data = resp.json()

    login_type = data.get("loginType", "")

    if login_type == "verifyCode":
        state["needs_verify_code"] = True
        state["error"] = "Verifiseringskode sendt til e-post. Skriv inn koden nedenfor."
        return

    _store_token(data)


def _store_token(data):
    token = data.get("accessToken") or data.get("access_token")
    if not token:
        raise ValueError(f"Fikk ikke token fra Bambu API: {data}")
    state["token"] = token
    state["token_expires"] = time.time() + 86400 * 90
    state["needs_verify_code"] = False
    state["error"] = None

    # Hent user ID fra JWT (før save_token)
    try:
        payload = token.split(".")[1]
        payload += "=" * (4 - len(payload) % 4)
        decoded = json.loads(base64.b64decode(payload))
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


# ── Device list (REST, kjøres én gang) ──

def fetch_device_list():
    ensure_token()

    bind_resp = requests.get(
        f"{BAMBU_API}/v1/iot-service/api/user/bind",
        headers=headers(),
        timeout=10,
    )
    bind_resp.raise_for_status()
    devices = bind_resp.json().get("devices", [])

    for dev in devices:
        dev_id = dev["dev_id"]
        state["devices"][dev_id] = {
            "id": dev_id,
            "name": dev.get("name", dev_id),
            "model": dev.get("dev_product_name", "Ukjent"),
            "online": dev.get("online", False),
        }

    state["last_updated"] = time.strftime("%H:%M:%S")
    print(f"[Info] Hentet {len(devices)} enheter fra Bambu Lab")


# ── MQTT for sanntidsstatus ──

def on_connect(client, userdata, flags, reason_code, properties=None):
    print(f"[MQTT] Koblet til (reason: {reason_code})")
    state["mqtt_connected"] = True

    # Abonner på alle enheter
    for dev_id in state["devices"]:
        topic = f"device/{dev_id}/report"
        client.subscribe(topic)
        print(f"[MQTT] Abonnerer på {topic}")

    # Be om full status-dump fra alle printere
    time.sleep(1)
    for dev_id in state["devices"]:
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


def on_disconnect(client, userdata, flags, reason_code, properties=None):
    print(f"[MQTT] Frakoblet (reason: {reason_code})")
    state["mqtt_connected"] = False


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

    # Oppdater MQTT-status (merge delta-oppdateringer)
    if dev_id not in state["mqtt_status"]:
        state["mqtt_status"][dev_id] = {}

    prev_state = state["mqtt_status"][dev_id].get("gcode_state", "")
    state["mqtt_status"][dev_id].update(print_data)
    new_state = state["mqtt_status"][dev_id].get("gcode_state", "")

    # Registrer tidspunkt når en ny jobb starter
    if new_state in ("RUNNING", "PREPARE") and prev_state not in ("RUNNING", "PREPARE"):
        state["mqtt_status"][dev_id]["job_started_at"] = time.strftime("%Y-%m-%dT%H:%M:%S")
    # Nullstill job_started_at når jobben er ferdig/avbrutt
    elif new_state in ("IDLE", "FAILED", "FINISH") and prev_state in ("RUNNING", "PREPARE", "PAUSE"):
        state["mqtt_status"][dev_id]["job_started_at"] = None

    # Oppdater online-status
    if dev_id in state["devices"]:
        state["devices"][dev_id]["online"] = True

    state["last_updated"] = time.strftime("%H:%M:%S")


def start_mqtt():
    global mqtt_client

    if not state["token"] or not state["user_id"]:
        print("[MQTT] Mangler token eller user_id, kan ikke koble til")
        return

    mqtt_client = mqtt.Client(
        callback_api_version=mqtt.CallbackAPIVersion.VERSION2,
        client_id=f"dashboard_{int(time.time())}",
        protocol=mqtt.MQTTv311,
    )
    mqtt_client.username_pw_set(
        f"u_{state['user_id']}",
        state["token"],
    )
    mqtt_client.tls_set(cert_reqs=ssl.CERT_REQUIRED, tls_version=ssl.PROTOCOL_TLS_CLIENT)

    mqtt_client.on_connect = on_connect
    mqtt_client.on_disconnect = on_disconnect
    mqtt_client.on_message = on_message

    try:
        mqtt_client.connect(MQTT_BROKER, MQTT_PORT, keepalive=60)
        mqtt_client.loop_start()
        print(f"[MQTT] Starter tilkobling til {MQTT_BROKER}...")
    except Exception as e:
        print(f"[MQTT] Feil ved tilkobling: {e}")
        state["error"] = f"MQTT-feil: {e}"


def stop_mqtt():
    global mqtt_client
    if mqtt_client:
        mqtt_client.loop_stop()
        mqtt_client.disconnect()
        mqtt_client = None
        state["mqtt_connected"] = False


# ── Hent forhåndsvisningsbilder fra tasks API ──

def fetch_task_covers():
    """Hent cover-bilder for aktive print-jobber fra tasks API."""
    if not state["token"]:
        return

    try:
        resp = requests.get(
            f"{BAMBU_API}/v1/user-service/my/tasks",
            headers=headers(),
            params={"limit": 50},
            timeout=10,
        )
        resp.raise_for_status()
        tasks = resp.json().get("hits", [])

        # Lagre siste cover per device (første treff = nyeste oppgave)
        covers = {}
        for task in tasks:
            dev_id = task.get("deviceId")
            cover = task.get("cover")
            if dev_id and cover and dev_id not in covers:
                covers[dev_id] = cover
        state["task_covers"] = covers
    except Exception as e:
        print(f"[Feil] Kunne ikke hente task covers: {e}")


# ── Bygg printer-liste fra devices + MQTT-data ──

def build_printer_list():
    printers = []
    for dev_id, dev in state["devices"].items():
        mqtt_data = state["mqtt_status"].get(dev_id, {})

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

        time_remaining = None
        if remaining_min is not None:
            try:
                time_remaining = int(remaining_min) * 60  # konverter til sekunder
            except (ValueError, TypeError):
                pass

        # Hent cover-bilde fra tasks API
        thumbnail = state.get("task_covers", {}).get(dev_id)
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
    import socket
    print("[Nettverk] Venter på internettforbindelse...")
    for i in range(timeout):
        try:
            socket.setdefaulttimeout(3)
            socket.socket(socket.AF_INET, socket.SOCK_STREAM).connect(("8.8.8.8", 53))
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
                if not state["mqtt_connected"]:
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
    })


@app.route("/api/today")
def api_today():
    """Hent dagens printstatistikk."""
    if not state["token"]:
        return jsonify({"prints": 0, "weight_g": 0, "time_s": 0})

    try:
        from datetime import datetime, timezone
        today = datetime.now(timezone.utc).strftime("%Y-%m-%d")

        resp = requests.get(
            f"{BAMBU_API}/v1/user-service/my/tasks",
            headers=headers(),
            params={"limit": 100},
            timeout=15,
        )
        resp.raise_for_status()
        tasks = resp.json().get("hits", [])

        prints = 0
        weight = 0
        time_s = 0
        for t in tasks:
            start = t.get("startTime", "")
            if start and start[:10] == today:
                prints += 1
                weight += t.get("weight", 0) or 0
                time_s += t.get("costTime", 0) or 0

        return jsonify({"prints": prints, "weight_g": weight, "time_s": time_s})
    except Exception as e:
        return jsonify({"prints": 0, "weight_g": 0, "time_s": 0, "error": str(e)})


@app.route("/stats")
def stats_page():
    return render_template("stats.html")


@app.route("/api/stats")
def api_stats():
    """Returner akkumulert statistikk fra lokal lagring."""
    if not state["token"]:
        return jsonify({"error": "Ikke innlogget"}), 401
    with _stats_lock:
        store = load_stats_store()
    total = sum(d["total_prints"] for d in store["stats"].values())
    return jsonify({
        "devices":       list(store["stats"].values()),
        "total_tasks":   total,
        "api_total":     store.get("api_total", 0),
        "data_complete": store.get("complete", False),
    })


@app.route("/api/ams-colors")
def api_ams_colors():
    """Returner nåværende AMS-filamentfarger per enhet."""
    result = {}
    for dev_id, mqtt_data in state["mqtt_status"].items():
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
    email = os.getenv("BAMBU_EMAIL")
    if not email:
        return jsonify({"ok": False, "error": "BAMBU_EMAIL ikke satt."}), 400
    try:
        resp = requests.post(
            f"{BAMBU_API}/v1/user-service/user/sendemail/code",
            json={"email": email, "type": "codeLogin"},
            timeout=10,
        )
        resp.raise_for_status()
        return jsonify({"ok": True})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 400


@app.route("/api/verify", methods=["POST"])
def api_verify():
    data = flask_request.get_json()
    code = data.get("code", "").strip()
    if not code:
        return jsonify({"ok": False, "error": "Ingen kode oppgitt."}), 400
    try:
        verify_code(code)
        fetch_device_list()
        fetch_task_covers()
        start_mqtt()
        return jsonify({"ok": True})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 400


if __name__ == "__main__":
    init_connection()
    thread = threading.Thread(target=poll_loop, daemon=True)
    thread.start()
    app.run(host="0.0.0.0", port=5000, debug=False)
