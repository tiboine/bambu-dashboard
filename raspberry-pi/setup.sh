#!/bin/bash
# ─────────────────────────────────────────────────────────────────
#  Bambu Lab Dashboard – Raspberry Pi oppsett
#  Kjør som vanlig bruker (ikke root): bash setup.sh
# ─────────────────────────────────────────────────────────────────
set -e

ORANGE='\033[0;33m'
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

info()    { echo -e "${ORANGE}▶ $*${NC}"; }
success() { echo -e "${GREEN}✔ $*${NC}"; }
error()   { echo -e "${RED}✘ $*${NC}"; exit 1; }

# ── Finn prosjektmappe (én katalog opp fra dette skriptet) ────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
SERVICE_USER="$USER"

echo ""
echo "  Bambu Lab Dashboard – Raspberry Pi oppsett"
echo "  Prosjektmappe: $PROJECT_DIR"
echo ""

# ── 1. Systemavhengigheter ────────────────────────────────────────
info "Oppdaterer pakkeliste..."
sudo apt-get update -qq

info "Installerer Python 3, venv, Chromium..."
sudo apt-get install -y -qq \
    python3 python3-pip python3-venv \
    chromium-browser \
    unclutter \
    xdotool \
    2>/dev/null || sudo apt-get install -y -qq \
    python3 python3-pip python3-venv \
    chromium \
    unclutter \
    xdotool
success "Pakker installert"

# ── 2. Python virtuelt miljø ──────────────────────────────────────
VENV_DIR="$PROJECT_DIR/.venv"
if [ ! -d "$VENV_DIR" ]; then
    info "Oppretter virtuelt Python-miljø i $VENV_DIR..."
    python3 -m venv "$VENV_DIR"
fi

info "Installerer Python-avhengigheter..."
"$VENV_DIR/bin/pip" install -q --upgrade pip
"$VENV_DIR/bin/pip" install -q -r "$PROJECT_DIR/requirements.txt"
success "Python-avhengigheter installert"

# ── 3. .env-konfigurasjon ─────────────────────────────────────────
ENV_FILE="$PROJECT_DIR/.env"
if [ ! -f "$ENV_FILE" ]; then
    info "Setter opp .env-fil..."
    echo ""
    read -rp "  Bambu Lab e-post: " BAMBU_EMAIL
    read -rsp "  Bambu Lab passord: " BAMBU_PASSWORD
    echo ""
    cat > "$ENV_FILE" <<EOF
BAMBU_EMAIL=$BAMBU_EMAIL
BAMBU_PASSWORD=$BAMBU_PASSWORD
EOF
    chmod 600 "$ENV_FILE"
    success ".env opprettet"
else
    success ".env finnes allerede – hopper over"
fi

# ── 4. Systemd-tjeneste ───────────────────────────────────────────
SERVICE_FILE="/etc/systemd/system/bambu-dashboard.service"
info "Installerer systemd-tjeneste..."

sudo tee "$SERVICE_FILE" > /dev/null <<EOF
[Unit]
Description=Bambu Lab 3D Print Dashboard
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$SERVICE_USER
WorkingDirectory=$PROJECT_DIR
ExecStart=$VENV_DIR/bin/python app.py
Restart=on-failure
RestartSec=10
EnvironmentFile=$ENV_FILE

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable bambu-dashboard.service
sudo systemctl restart bambu-dashboard.service
success "Tjeneste installert og startet (bambu-dashboard.service)"

# ── 5. Kiosk-autostart ────────────────────────────────────────────
KIOSK_SCRIPT="$SCRIPT_DIR/start-kiosk.sh"
chmod +x "$KIOSK_SCRIPT"

AUTOSTART_DIR="$HOME/.config/autostart"
mkdir -p "$AUTOSTART_DIR"

cat > "$AUTOSTART_DIR/bambu-kiosk.desktop" <<EOF
[Desktop Entry]
Type=Application
Name=Bambu Kiosk
Exec=bash -c 'sleep 5 && $KIOSK_SCRIPT'
Hidden=false
NoDisplay=false
X-GNOME-Autostart-enabled=true
EOF

success "Kiosk-autostart installert"

# ── 6. Ferdig ─────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  Oppsett fullført!                               ║${NC}"
echo -e "${GREEN}╠══════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║  Flask kjører på: http://localhost:5000          ║${NC}"
echo -e "${GREEN}║  Kiosk starter automatisk ved neste oppstart     ║${NC}"
echo -e "${GREEN}║                                                  ║${NC}"
echo -e "${GREEN}║  Nyttige kommandoer:                             ║${NC}"
echo -e "${GREEN}║    sudo systemctl status bambu-dashboard         ║${NC}"
echo -e "${GREEN}║    sudo journalctl -u bambu-dashboard -f         ║${NC}"
echo -e "${GREEN}║    bash raspberry-pi/start-kiosk.sh              ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════╝${NC}"
echo ""
echo "  Start kiosk nå? (trykk Enter, eller Ctrl+C for å avbryte)"
read -r
bash "$KIOSK_SCRIPT" &
