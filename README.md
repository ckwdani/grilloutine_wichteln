# Grilloutine Wichteln

Eine erste Iteration eines kleinen Wichtel-Tools in PHP. Es ermöglicht die Anmeldung mit Nicknamen bis zu einem gesetzten Stichtag und zeigt danach die ausgelosten Wichtelkinder an.

## Einrichtung

1. Kopiere die Beispielumgebung:

   ```bash
   cp .env.example .env
   ```

2. Passe den `DEADLINE` Wert im `.env` an (`YYYY-MM-DD HH:MM`).
3. Stelle sicher, dass PHP lokal verfügbar ist (z. B. `php -S localhost:8000`).

## Nutzung

- **Vor der Deadline**: Nutzer:innen geben ihren Nicknamen ein. Doppelte Namen werden abgelehnt.
- **Nach der Deadline**: Beim ersten Aufruf wird automatisch gelost und für ungerade Teilnehmerzahlen eine Person mit zwei Wichtelkindern bestimmt. Anschließend kann jede Person mit ihrem Nicknamen ihre Zuordnung einsehen.

## Datenablage

- Teilnehmer:innen werden in `data/participants.xml` gespeichert.
- Auslosungen landen in `data/pairings.xml` und werden nur einmal erzeugt.

Die Dateien sind in `.gitignore` hinterlegt; der Ordner `data/` enthält ein `.gitkeep`, damit er im Repository bleibt.
