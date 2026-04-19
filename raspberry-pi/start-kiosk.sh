#!/bin/bash
# ─────────────────────────────────────────────────────────────────
#  Bambu Lab Dashboard – Kiosk-launcher for Raspberry Pi
#  Åpner Chromium i fullskjerm-kiosk-modus når Flask er klar
# ─────────────────────────────────────────────────────────────────

URL="http://localhost:5000"
MAX_WAIT=180   # sekunder å vente på Flask (nettverksforbindelse kan ta tid)

# ── Deaktiver skjermsparer og strømstyring ────────────────────────
export DISPLAY="${DISPLAY:-:0}"
xset s off          2>/dev/null || true
xset s noblank      2>/dev/null || true
xset -dpms          2>/dev/null || true

# ── Skjul musepeker ───────────────────────────────────────────────
unclutter -idle 0.5 -root &

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
    --disable-gpu \
    --disable-software-rasterizer \
    --disable-dev-shm-usage \
    --no-sandbox \
    2>/dev/null \
    "$URL"
