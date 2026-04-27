#!/bin/bash
# ─────────────────────────────────────────────────────────────────
#  Bambu Lab Dashboard – Kiosk-launcher for Raspberry Pi
#  Sjekker git-oppdateringer, starter/restarter Flask, åpner Chromium
# ─────────────────────────────────────────────────────────────────

URL="http://localhost:5000"
MAX_WAIT=180   # sekunder å vente på Flask (nettverksforbindelse kan ta tid)
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
APP_PY="$REPO_DIR/app.py"

# ── Deaktiver skjermsparer og strømstyring ────────────────────────
export DISPLAY="${DISPLAY:-:0}"
xset s off          2>/dev/null || true
xset s noblank      2>/dev/null || true
xset -dpms          2>/dev/null || true

# ── Skjul musepeker ───────────────────────────────────────────────
unclutter -idle 0.5 -root &

# ── Git-oppdatering ───────────────────────────────────────────────
echo "Sjekker git-oppdateringer i $REPO_DIR..."
cd "$REPO_DIR"
git fetch origin 2>/dev/null
LOCAL=$(git rev-parse HEAD 2>/dev/null)
REMOTE=$(git rev-parse origin/master 2>/dev/null || git rev-parse origin/main 2>/dev/null)

if [ -n "$REMOTE" ] && [ "$LOCAL" != "$REMOTE" ]; then
    echo "Ny versjon funnet – puller oppdateringer..."
    git pull --ff-only origin "$(git rev-parse --abbrev-ref HEAD)" 2>/dev/null && \
        echo "Oppdatering fullført (${LOCAL:0:7} → ${REMOTE:0:7})" || \
        echo "Pull feilet – fortsetter med eksisterende versjon"
else
    echo "Ingen oppdateringer (${LOCAL:0:7})"
fi

# ── Start/restart Flask ───────────────────────────────────────────
# Drep eventuell kjørende Flask-instans
FLASK_PID=$(pgrep -f "python.*app.py" 2>/dev/null)
if [ -n "$FLASK_PID" ]; then
    echo "Stopper Flask (PID $FLASK_PID)..."
    kill "$FLASK_PID" 2>/dev/null
    sleep 2
fi

echo "Starter Flask..."
cd "$REPO_DIR"
nohup python3 app.py > /tmp/bambu-flask.log 2>&1 &
echo "Flask PID: $!"

# ── Vent til Flask svarer ─────────────────────────────────────────
echo "Venter på Flask ($URL)..."
for i in $(seq 1 $MAX_WAIT); do
    if curl -sf "$URL" > /dev/null 2>&1; then
        echo "Flask klar etter ${i}s"
        break
    fi
    if [ "$i" -eq "$MAX_WAIT" ]; then
        echo "Flask svarer ikke etter ${MAX_WAIT}s – starter Chromium likevel"
    fi
    sleep 1
done

# ── Start Chromium i kiosk-modus ──────────────────────────────────
# Prøv chromium-browser (Raspberry Pi OS), fall tilbake til chromium
CHROMIUM=$(command -v chromium-browser 2>/dev/null || command -v chromium 2>/dev/null)

if [ -z "$CHROMIUM" ]; then
    echo "Feil: Chromium ikke funnet. Kjør setup.sh først."
    exit 1
fi

# Fjern Chromium crash-flagg som blokkerer kiosk-modus
rm -f ~/.config/chromium/Default/Preferences.bak 2>/dev/null || true
sed -i 's/"exited_cleanly":false/"exited_cleanly":true/g' \
    ~/.config/chromium/Default/Preferences 2>/dev/null || true
sed -i 's/"exit_type":"Crashed"/"exit_type":"Normal"/g' \
    ~/.config/chromium/Default/Preferences 2>/dev/null || true

exec "$CHROMIUM" \
    --kiosk \
    --noerrdialogs \
    --disable-infobars \
    --no-first-run \
    --disable-translate \
    --disable-features=TranslateUI \
    --disable-session-crashed-bubble \
    --disable-restore-session-state \
    --autoplay-policy=no-user-gesture-required \
    --check-for-update-interval=31536000 \
    --start-fullscreen \
    --force-device-scale-factor=1 \
    --disable-dev-shm-usage \
    --no-sandbox \
    2>/dev/null \
    "$URL"
