#!/bin/bash
# ─────────────────────────────────────────────────────────────────
#  Bambu Lab Dashboard – Kiosk-launcher for Raspberry Pi
#  Sjekker git-oppdateringer, venter på Flask, åpner Chromium
#  Flask kjøres av systemd-tjenesten bambu-dashboard (ikke av dette skriptet)
# ─────────────────────────────────────────────────────────────────

URL="http://localhost:5000/?kiosk=1"   # ?kiosk tvinger tilpass-til-skjerm-modus
MAX_WAIT=180   # sekunder å vente på Flask (nettverksforbindelse kan ta tid)
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"

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
unclutter -idle 0.5 -root &

# ── Git-oppdatering ───────────────────────────────────────────────
# Kjøres ved oppstart og deretter jevnlig i bakgrunnen. Når appen restartes med ny
# kode, laster dashboardet seg selv på nytt – ingen mus, tastatur eller omstart trengs.
# Endringer i dette skriptet (f.eks. Chromium-flagg) tas i bruk ved neste omstart.
UPDATE_INTERVAL="${KIOSK_UPDATE_INTERVAL:-600}"   # sekunder mellom hver sjekk (0 = av)
cd "$REPO_DIR"

PY="$REPO_DIR/.venv/bin/python"
PIP="$REPO_DIR/.venv/bin/pip"
STAGE_DIR="${TMPDIR:-/tmp}/bambu-dashboard-test"   # «boblen»: egen kopi av ny versjon
STAGE_PORT=5099
SKIP_FILE="$REPO_DIR/.update-skip"                  # versjon som feilet – prøves ikke igjen

# Vent til en adresse svarer (maks $2 sekunder)
wait_http() {
    local i
    for i in $(seq 1 "$2"); do
        curl -sf -o /dev/null "$1" && return 0
        sleep 1
    done
    return 1
}

# Test ny versjon i en egen kopi på egen port, uten innlogging, MQTT eller .env.
# Fanger opp kode som ikke starter (syntaksfeil, manglende pakker, ødelagte sider).
test_in_bubble() {
    local commit="$1" pid ok=1
    rm -rf "$STAGE_DIR"; git worktree prune 2>/dev/null
    git worktree add -q --detach "$STAGE_DIR" "$commit" 2>/dev/null || return 1
    # Kiosk-skriptet må også være gyldig, ellers starter ikke Pi-en neste gang
    if bash -n "$STAGE_DIR/raspberry-pi/start-kiosk.sh"; then
        [ -x "$PIP" ] && "$PIP" install -q -r "$STAGE_DIR/requirements.txt" >/dev/null 2>&1
        (cd "$STAGE_DIR" && exec env -i PATH="$PATH" HOME="$HOME" \
            "$PY" -c "import app; app.app.run(host='127.0.0.1', port=$STAGE_PORT)") >/dev/null 2>&1 &
        pid=$!
        if wait_http "http://127.0.0.1:$STAGE_PORT/api/status" 30 \
            && curl -sf -o /dev/null "http://127.0.0.1:$STAGE_PORT/" \
            && curl -sf -o /dev/null "http://127.0.0.1:$STAGE_PORT/stats"; then
            ok=0
        fi
        kill "$pid" 2>/dev/null; wait "$pid" 2>/dev/null
    else
        echo "Ny versjon har feil i start-kiosk.sh"
    fi
    git worktree remove --force "$STAGE_DIR" 2>/dev/null; rm -rf "$STAGE_DIR"
    return $ok
}

restart_app() {
    if sudo -n systemctl restart bambu-dashboard 2>/dev/null; then
        echo "Tjenesten bambu-dashboard restartet"
        return 0
    fi
    echo "Kunne ikke restarte tjenesten uten passord – ny versjon tas i bruk ved neste omstart"
    return 1
}

update_repo() {
    git fetch -q origin 2>/dev/null || { echo "Fikk ikke kontakt med GitHub – prøver igjen senere"; return; }
    local LOCAL REMOTE
    LOCAL=$(git rev-parse HEAD 2>/dev/null)
    REMOTE=$(git rev-parse origin/master 2>/dev/null || git rev-parse origin/main 2>/dev/null)
    if [ -z "$REMOTE" ] || [ "$LOCAL" = "$REMOTE" ]; then
        echo "Ingen oppdateringer (${LOCAL:0:7})"
        return
    fi
    if [ "$(cat "$SKIP_FILE" 2>/dev/null)" = "$REMOTE" ]; then
        echo "Hopper over ${REMOTE:0:7} – den feilet tidligere. Venter på en nyere versjon."
        return
    fi

    # 1) Test i boblen før noe endres i den ekte appen
    echo "Ny versjon ${REMOTE:0:7} – tester den i egen kopi først..."
    if ! test_in_bubble "$REMOTE"; then
        echo "Ny versjon ${REMOTE:0:7} startet ikke i testen – beholder ${LOCAL:0:7}"
        echo "$REMOTE" > "$SKIP_FILE"
        return
    fi

    # 2) Bytt til ny versjon
    if ! git pull -q --ff-only origin "$(git rev-parse --abbrev-ref HEAD)" 2>/dev/null; then
        echo "Pull feilet – fortsetter med eksisterende versjon"
        return
    fi
    echo "Oppdatert ${LOCAL:0:7} → ${REMOTE:0:7}"
    [ -x "$PIP" ] && { "$PIP" install -q -r "$REPO_DIR/requirements.txt" || echo "Kunne ikke oppdatere Python-pakker"; }
    restart_app || return

    # 3) Sjekk at den ekte appen faktisk kom opp – ellers rull tilbake
    if wait_http "$URL" 90 && curl -sf -o /dev/null "http://localhost:5000/api/status"; then
        echo "Ny versjon kjører"
        rm -f "$SKIP_FILE"
        return
    fi
    echo "Ny versjon svarer ikke – ruller tilbake til ${LOCAL:0:7}"
    echo "$REMOTE" > "$SKIP_FILE"
    git reset -q --hard "$LOCAL"
    [ -x "$PIP" ] && "$PIP" install -q -r "$REPO_DIR/requirements.txt" >/dev/null 2>&1
    restart_app
}

echo "Sjekker git-oppdateringer i $REPO_DIR..."
update_repo

# Bakgrunnssjekk mens kiosken kjører. flock hindrer at det startes flere
# løkker hvis kiosk-skriptet kjøres på nytt uten omstart.
if [ "$UPDATE_INTERVAL" -gt 0 ] 2>/dev/null && command -v flock >/dev/null 2>&1; then
    (
        flock -n 9 || exit 0
        while sleep "$UPDATE_INTERVAL"; do
            cd "$REPO_DIR" && update_repo
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
    2>/dev/null \
    "$URL"
