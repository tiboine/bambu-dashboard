# Makerspace Ringebu – 3D-print dashboard

Enkel webside som viser live status for alle Bambu Lab-printere via Bambu Cloud API.

## Krav

- Python 3.10+
- Bambu Lab-konto med printerne koblet til

## Oppsett

1. **Installer avhengigheter**
   ```
   pip install -r requirements.txt
   ```

2. **Lag `.env`-fil** (kopier fra eksempelet)
   ```
   copy .env.example .env
   ```
   Fyll inn Bambu-epost og passord i `.env`.

3. **Start serveren**
   ```
   python app.py
   ```

4. Åpne `http://localhost:5000` i nettleseren.

## Filer

| Fil | Beskrivelse |
|-----|-------------|
| `app.py` | Flask-backend, henter data fra Bambu Cloud API |
| `templates/index.html` | Dashboard-frontend |
| `.env` | Påloggingsinfo (ikke del denne!) |
| `requirements.txt` | Python-avhengigheter |

## Sikkerhet

- `.env`-filen inneholder passordet ditt – legg den aldri ut på GitHub.
- Dashbordet er kun ment for lokalt nettverk / intern bruk.
