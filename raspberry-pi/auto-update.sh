#!/bin/bash
# ─────────────────────────────────────────────────────────────────
#  Bambu Lab Dashboard – automatisk oppdatering
#  Kjøres av start-kiosk.sh ved oppstart og hvert 10. minutt. Leses på nytt fra
#  disk hver gang, så endringer her virker uten omstart av Pi-en.
#
#  1) Ny versjon på master testes i en egen kopi («boblen») på egen port.
#  2) Består den, hentes den og appen restartes.
#  3) Svarer ikke appen etterpå, rulles det tilbake til forrige versjon.
#  Resultatet skrives til .update-status og vises i dashboardet (hold over versjonen).
# ─────────────────────────────────────────────────────────────────

REPO_DIR="${REPO_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
URL="http://localhost:5000/?kiosk=1"
STATUS_FILE="$REPO_DIR/.update-status"
cd "$REPO_DIR" || exit 1

# Status for dashboardet: unix-tid|tilstand|melding
set_status() {
    printf '%s|%s|%s\n' "$(date +%s)" "$1" "$2" > "$STATUS_FILE.tmp" && mv -f "$STATUS_FILE.tmp" "$STATUS_FILE"
}

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

# Vent til den ekte appen svarer OG kjører den nye versjonen (ikke den gamle
# prosessen som ennå ikke har rukket å stoppe). Maks $2 sekunder.
wait_version() {
    local i
    for i in $(seq 1 "$2"); do
        curl -sf "http://localhost:5000/api/status" 2>/dev/null | grep -Eq "\"commit\": ?\"$1\"" && return 0
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
    if bash -n "$STAGE_DIR/raspberry-pi/start-kiosk.sh" && bash -n "$STAGE_DIR/raspberry-pi/auto-update.sh"; then
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
        echo "Ny versjon har feil i kiosk-/oppdateringsskriptet"
    fi
    git worktree remove --force "$STAGE_DIR" 2>/dev/null; rm -rf "$STAGE_DIR"
    return $ok
}

restart_app() {
    if sudo -n systemctl restart bambu-dashboard 2>/dev/null; then
        echo "Tjenesten bambu-dashboard restartet"
        return 0
    fi
    # Uten sudo: stopp appen – systemd (Restart=always) starter den igjen med ny kode
    if pkill -f "$PY app.py" 2>/dev/null; then
        echo "Appen stoppet – systemd starter den på nytt"
        return 0
    fi
    echo "Klarte ikke å restarte appen"
    return 1
}

update_repo() {
    git fetch -q origin 2>/dev/null || { echo "Fikk ikke kontakt med GitHub – prøver igjen senere"; set_status error "Fikk ikke kontakt med GitHub"; return; }
    local LOCAL REMOTE
    LOCAL=$(git rev-parse HEAD 2>/dev/null)
    REMOTE=$(git rev-parse origin/master 2>/dev/null || git rev-parse origin/main 2>/dev/null)
    if [ -z "$REMOTE" ] || [ "$LOCAL" = "$REMOTE" ]; then
        echo "Ingen oppdateringer (${LOCAL:0:7})"
        set_status ok "Ingen nye versjoner"
        return
    fi
    if [ "$(cat "$SKIP_FILE" 2>/dev/null)" = "$REMOTE" ]; then
        echo "Hopper over ${REMOTE:0:7} – den feilet tidligere. Venter på en nyere versjon."
        set_status skipped "Ny versjon ${REMOTE:0:7} feilet tidligere – venter på en nyere"
        return
    fi

    # 1) Test i boblen før noe endres i den ekte appen
    echo "Ny versjon ${REMOTE:0:7} – tester den i egen kopi først..."
    if ! test_in_bubble "$REMOTE"; then
        echo "Ny versjon ${REMOTE:0:7} startet ikke i testen – beholder ${LOCAL:0:7}"
        echo "$REMOTE" > "$SKIP_FILE"
        set_status skipped "Ny versjon ${REMOTE:0:7} startet ikke i testen – beholdt gammel"
        return
    fi

    # 2) Bytt til ny versjon
    if ! git pull -q --ff-only origin "$(git rev-parse --abbrev-ref HEAD)" 2>/dev/null; then
        echo "Pull feilet – fortsetter med eksisterende versjon"
        set_status error "git pull feilet (lokale endringer på Pi-en?)"
        return
    fi
    echo "Oppdatert ${LOCAL:0:7} → ${REMOTE:0:7}"
    [ -x "$PIP" ] && { "$PIP" install -q -r "$REPO_DIR/requirements.txt" || echo "Kunne ikke oppdatere Python-pakker"; }
    if ! restart_app; then
        set_status error "Hentet ${REMOTE:0:7}, men klarte ikke å restarte appen"
        return
    fi

    # 3) Sjekk at den ekte appen faktisk kom opp – ellers rull tilbake
    if wait_version "$(git rev-parse --short HEAD)" 90 && curl -sf -o /dev/null "$URL"; then
        echo "Ny versjon kjører"
        rm -f "$SKIP_FILE"
        set_status updated "Oppdatert til ${REMOTE:0:7}"
        return
    fi
    echo "Ny versjon svarer ikke – ruller tilbake til ${LOCAL:0:7}"
    echo "$REMOTE" > "$SKIP_FILE"
    git reset -q --hard "$LOCAL"
    [ -x "$PIP" ] && "$PIP" install -q -r "$REPO_DIR/requirements.txt" >/dev/null 2>&1
    restart_app
    set_status rolledback "Ny versjon ${REMOTE:0:7} svarte ikke – rullet tilbake"
}

echo "[$(date '+%F %T')] Sjekker oppdateringer i $REPO_DIR..."
update_repo
