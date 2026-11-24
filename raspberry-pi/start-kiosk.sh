#!/bin/bash
# ─────────────────────────────────────────────────────────────────
#  Bambu Lab Dashboard – Kiosk-launcher for Raspberry Pi
#  Sjekker git-oppdateringer, venter på Flask, åpner Chromium
#  Flask kjøres av systemd-tjenesten bambu-dashboard (ikke av dette skriptet)
# ─────────────────────────────────────────────────────────────────

URL="http://localhost:5000/?kiosk=1"   # ?kiosk tvinger tilpass-til-skjerm-modus
MAX_WAIT=180   # sekunder å vente på Flask (nettverksforbindelse kan ta tid)
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"

# ── Logg til fil, så den kan leses på http://<pi>.local:5000/diag ──
# Ny logg ved hver oppstart; maks 3000 linjer så minnekortet ikke fylles.
KIOSK_LOG="$REPO_DIR/.kiosk.log"
CHROMIUM_LOG="$REPO_DIR/.chromium.log"
if [ -z "$KIOSK_REEXEC" ]; then
    exec > >(awk 'NR<=3000 { print; fflush() }' > "$KIOSK_LOG") 2>&1
fi
echo "[$(date '+%F %T')] Kiosk starter (bruker $(id -un), WAYLAND_DISPLAY=${WAYLAND_DISPLAY:-}, DISPLAY=${DISPLAY:-})"

# ── Deaktiver skjermsparer og strømstyring ────────────────────────
export DISPLAY="${DISPLAY:-:0}"
xset s off          2>/dev/null || true
xset s noblank      2>/dev/null || true
xset -dpms          2>/dev/null || true

# ── Maks 1080p: en 4K-skjerm gir Pi-en fire ganger så mange piksler å tegne ──
# Slå av med KIOSK_MAX_1080P=0 (f.eks. i ~/.profile) hvis du vil ha full oppløsning.
if [ "${KIOSK_MAX_1080P:-1}" = "1" ]; then
    if [ -n "$WAYLAND_DISPLAY" ] && command -v wlr-randr >/dev/null 2>&1; then
        OUT=$(wlr-randr 2>/dev/null | awk '/^[^ ]/{print $1; exit}')
        CUR_W=$(wlr-randr 2>/dev/null | awk '/current/{split($1,a,"x"); print a[1]; exit}')
        if [ -n "$OUT" ] && [ "${CUR_W:-0}" -gt 1920 ] && wlr-randr 2>/dev/null | grep -q " 1920x1080 px"; then
            wlr-randr --output "$OUT" --mode 1920x1080 2>/dev/null && echo "Oppløsning satt til 1920x1080 ($OUT)"
        fi
    elif command -v xrandr >/dev/null 2>&1; then
        OUT=$(xrandr --query 2>/dev/null | awk '/ connected/{print $1; exit}')
        CUR_W=$(xrandr --query 2>/dev/null | awk '/\*/{split($1,a,"x"); print a[1]; exit}')
        if [ -n "$OUT" ] && [ "${CUR_W:-0}" -gt 1920 ] && xrandr --query 2>/dev/null | grep -q "^ *1920x1080 "; then
            xrandr --output "$OUT" --mode 1920x1080 2>/dev/null && echo "Oppløsning satt til 1920x1080 ($OUT)"
        fi
    fi
fi

# ── Skjul musepeker ───────────────────────────────────────────────
pgrep -x unclutter >/dev/null || unclutter -idle 0.5 -root &

# ── Git-oppdatering ───────────────────────────────────────────────
# Selve logikken ligger i auto-update.sh (test i egen kopi, bytte, tilbakerulling).
# Den leses på nytt hver gang, så forbedringer virker uten omstart.
UPDATE_INTERVAL="${KIOSK_UPDATE_INTERVAL:-600}"   # sekunder mellom hver sjekk (0 = av)
UPDATER="$REPO_DIR/raspberry-pi/auto-update.sh"
SELF="$REPO_DIR/raspberry-pi/start-kiosk.sh"
cd "$REPO_DIR"

SELF_BEFORE=$(cksum < "$SELF" 2>/dev/null)
bash "$UPDATER"

# Ble dette skriptet selv oppdatert? Start den nye versjonen med en gang
# (ellers ville nye Chromium-flagg o.l. først virke ved neste omstart).
if [ -z "$KIOSK_REEXEC" ] && [ "$(cksum < "$SELF" 2>/dev/null)" != "$SELF_BEFORE" ]; then
    echo "start-kiosk.sh er oppdatert – starter den nye versjonen"
    export KIOSK_REEXEC=1
    exec bash "$SELF" "$@"
fi

# Bakgrunnssjekk mens kiosken kjører. flock hindrer at det startes flere
# løkker hvis kiosk-skriptet kjøres på nytt uten omstart.
if [ "$UPDATE_INTERVAL" -gt 0 ] 2>/dev/null && command -v flock >/dev/null 2>&1; then
    (
        flock -n 9 || exit 0
        while sleep "$UPDATE_INTERVAL"; do
            [ -f "$UPDATER" ] && bash "$UPDATER"
        done
    ) 9>"/tmp/bambu-dashboard-update.lock" >>"/tmp/bambu-dashboard-update.log" 2>&1 &
fi

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

echo "[$(date '+%F %T')] Starter $CHROMIUM"
exec "$CHROMIUM" \
    --kiosk \
    --noerrdialogs \
    --hide-scrollbars \
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
    --ignore-gpu-blocklist \
    --enable-gpu-rasterization \
    --enable-zero-copy \
    --no-sandbox \
    2> >(awk 'NR<=3000 { print; fflush() }' > "$CHROMIUM_LOG") \
    "$URL"
