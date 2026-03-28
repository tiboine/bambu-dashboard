import os
import time
import threading
import requests
from flask import Flask, jsonify, render_template
from dotenv import load_dotenv

load_dotenv()

app = Flask(__name__)

BAMBU_API = "https://api.bambulab.com"
POLL_INTERVAL = 15  # seconds

state = {
    "token": None,
    "token_expires": 0,
    "printers": [],
    "last_updated": None,
    "error": None,
}


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
    token = data.get("accessToken") or data.get("access_token")
    if not token:
        raise ValueError(f"Fikk ikke token fra Bambu API: {data}")
    state["token"] = token
    state["token_expires"] = time.time() + 86400  # ~24t / token varer ~3 mnd


def ensure_token():
    if not state["token"] or time.time() > state["token_expires"]:
        login()


def headers():
    return {"Authorization": f"Bearer {state['token']}"}


def fetch_printers():
    ensure_token()

    bind_resp = requests.get(
        f"{BAMBU_API}/v1/iot-service/api/user/bind",
        headers=headers(),
        timeout=10,
    )
    bind_resp.raise_for_status()
    devices = bind_resp.json().get("devices", [])

    print_resp = requests.get(
        f"{BAMBU_API}/v1/iot-service/api/user/print",
        headers=headers(),
        timeout=10,
    )
    print_resp.raise_for_status()
    print_devices = {d["dev_id"]: d for d in print_resp.json().get("devices", [])}

    printers = []
    for dev in devices:
        dev_id = dev["dev_id"]
        pd = print_devices.get(dev_id, {})

        progress = pd.get("progress")
        start_time = pd.get("start_time")
        prediction = pd.get("prediction")

        # Beregn estimert gjenværende tid
        time_remaining = None
        if start_time and prediction and progress:
            try:
                elapsed = time.time() - (int(start_time) / 1000)
                total = float(prediction)
                pct = float(progress) / 100
                if pct > 0:
                    estimated_total = elapsed / pct
                    time_remaining = max(0, int(estimated_total - elapsed))
            except Exception:
                pass

        printers.append({
            "id": dev_id,
            "name": dev.get("name", dev_id),
            "model": dev.get("dev_product_name", "Ukjent"),
            "online": dev.get("online", False),
            "print_status": dev.get("print_status", ""),
            "task_name": pd.get("task_name"),
            "task_status": pd.get("task_status"),
            "progress": progress,
            "thumbnail": pd.get("thumbnail"),
            "time_remaining": time_remaining,
        })

    state["printers"] = printers
    state["last_updated"] = time.strftime("%H:%M:%S")
    state["error"] = None


def poll_loop():
    while True:
        try:
            fetch_printers()
        except Exception as e:
            state["error"] = str(e)
            print(f"[Feil] {e}")
        time.sleep(POLL_INTERVAL)


@app.route("/")
def index():
    return render_template("index.html")


@app.route("/api/status")
def api_status():
    return jsonify({
        "printers": state["printers"],
        "last_updated": state["last_updated"],
        "error": state["error"],
    })


if __name__ == "__main__":
    thread = threading.Thread(target=poll_loop, daemon=True)
    thread.start()
    app.run(host="0.0.0.0", port=5000, debug=False)
