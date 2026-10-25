# YouWillLikeIT Care Dispatch

Einsatz- und Tourenplanung für ambulante Pflegedienste auf **Silverstripe CMS 6**.

- **PDL-Board** (React): Zeitleiste je Pflegekraft mit Soll (Dienst) und Ist (Kommen/Pause/Gehen), Drag & Drop aus dem Pool, SOS-Tour-Split, Routenvorschläge.
- **Mobile Ansicht** (`.ss` + Alpine.js): Kommen/Gehen/Pause, eigene Tour, Leistungs-Checkliste, Einsatz starten/abschließen, SOS.
- **Pflegeplan → Einsätze**: Tourvorlagen und Pflegeplan-Positionen erzeugen die Einsätze des Tages, inklusive Leistungen.
- **Qualifikationsregeln**: Die Mindestqualifikation eines Einsatzes ergibt sich aus seinen Leistungen. Es gibt harte und weiche Regeln, weiche können mit Begründung übersteuert werden.
- **Audit-Trail**: Append-only Protokoll aller Änderungen und Ereignisse. Optional zusätzlich ein Zugriffsprotokoll.
- **Routenoptimierung**: VROOM + OSRM self-hosted, Fallback ist eine PHP-Heuristik ohne externe Dienste.

Single-Tenant: eine Installation und Datenbank pro Pflegedienst.

## Anforderungen

- PHP 8.3 oder 8.4 (Silverstripe 6 unterstützt PHP 8.2 nicht mehr)
- `silverstripe/framework` ^6, `silverstripe/admin` ^3
- MySQL 8 oder MariaDB (Zeilensperren über `SELECT … FOR UPDATE`)
- Node 20+ nur zum Neubauen des Frontends. `client/dist` ist gebaut enthalten.

## Installation

```bash
composer require youwilllikeit/silverstripe-care-dispatch
vendor/bin/sake db:build --flush
```

Eingerichtete Routen:

| URL | Zweck | Berechtigung |
|---|---|---|
| `/pflege/board` | PDL-Board | `PFLEGE_DISPATCH_ACCESS` |
| `/pflege/mobil` | Mobile Ansicht | `PFLEGE_MOBILE_ACCESS` |
| `/api/dispatch/*` | Board-API (JSON) | `PFLEGE_DISPATCH_ACCESS` |
| `/api/mobile/*` | Mobile-API (JSON) | `PFLEGE_MOBILE_ACCESS` |
| `/admin/einsatzplanung` | Stammdaten (ModelAdmin) | CMS-Zugriff + `PFLEGE_MASTERDATA_EDIT` |

Weitere Berechtigung: `PFLEGE_AUDIT_VIEW` erlaubt das Lesen des Änderungsprotokolls.

Jede Pflegekraft (`Caregiver`) ist mit genau einem `Member` verknüpft. Die mobile API arbeitet ausschließlich mit der Pflegekraft des eingeloggten Kontos.

## Domänenmodell

| Klasse | Inhalt |
|---|---|
| `Caregiver` | Qualifikation, Wochenstunden, Führerschein, optionale eigene Startadresse |
| `Shift` | Dienst (Soll). Ende ≤ Beginn bedeutet Nachtdienst über Mitternacht. Teildienst = zwei Dienste am Tag |
| `TimeEntry` | Kommen/Pause/Gehen (Ist), höchstens eine offene Buchung je Pflegekraft |
| `CareTask` | Leistungskatalog mit Mindestqualifikation und Planzeit |
| `Patient` | Adresse (geokodiert), Zugangs- und Pflegehinweise, bevorzugte und ausgeschlossene Pflegekräfte |
| `CarePlanItem` | Pflegeplan: Leistung, Wochentage, Slot (Früh/Mittag/Spät/Nacht), Zeitfenster, Gültigkeit, Anweisung, 1 oder 2 Personen |
| `TourTemplate`, `TourTemplateItem` | Tourvorlage mit Stopps (Patient + Slot + Uhrzeit), Bezugspflegekraft |
| `DailyTour` | Tour eines Tages. Lebenszyklus: Draft → Assigned → InProgress → Completed. `Interrupted` ist endgültig |
| `PatientVisit` | Einsatz. **1 Einsatz = höchstens 1 Tour = 1 Pflegekraft**. Die zweite Person eines Doppeleinsatzes ist ein eigener Begleit-Einsatz (`Role = Begleitung`, `PrimaryVisitID`) |
| `VisitTask` | Leistung im Einsatz (Checkliste). „Nicht erbracht“ erfordert eine Begründung |
| `AuditEntry` | Unveränderliches Protokoll |

### Qualifikation

Rangfolge: Hilfskraft (1) < Pflegehelfer LG1 (2) < Fachkraft (3). Azubi hat Rang 0 und kann nie primäre Pflegekraft sein.

`PatientVisit.RequiredQualification` = höchste `MinQualification` der Leistungen des Einsatzes. Die PDL kann diesen Wert mit `QualificationOverride` nur **anheben**. Begleit-Einsätze darf jede/r übernehmen, auch Azubis.

### Zuweisungsregeln

**Hart** (HTTP 422, nicht übersteuerbar):
- Qualifikation reicht nicht aus
- Azubi als primäre Pflegekraft
- Pflegekraft vom Patienten ausgeschlossen
- Pflegekraft inaktiv
- Einsatz ist nicht Pending/Unassigned
- Tour ist geschlossen
- Datum passt nicht
- Begleitung = Hauptpflegekraft

**Weich** (HTTP 409, bestätigen mit `force: true` + `reason`; beides wird protokolliert):
- Zeitüberschneidung
- außerhalb des Dienstes oder kein Dienst
- nicht eingestempelt (nur heute)
- außerhalb des Zeitfensters aus dem Pflegeplan

### SOS / Tour-Split

`POST /api/dispatch/tour/{id}/interrupt` (PDL) oder `POST /api/mobile/sos` (Pflegekraft):

1. Erledigte Einsätze bleiben bei der Pflegekraft (Dokumentation, Abrechnung).
2. Läuft gerade ein Einsatz, ist `current_visit_outcome` Pflicht:
   - `completed`: Der Einsatz wird jetzt abgeschlossen.
   - `not_completed`: Der Einsatz geht zurück in den Pool. `ActualStart` wird ins Audit geschrieben. **Bereits dokumentierte Leistungen bleiben erledigt**, damit z. B. kein Medikament doppelt gegeben wird.
3. Alle offenen Einsätze gehen in den Pool. Begleitungen dieser Einsätze auf anderen Touren gehen ebenfalls in den Pool.
4. Die Tour wird `Interrupted`.

## API

Alle POST-Requests senden einen JSON-Body und den Header `X-SecurityID` (CSRF-Token aus der Boot-Config der Seite).

Fehlerformat: `{"error":{"code","message","violations":[{code,message,severity,visitId}]}}`.

Mutierende Board-Endpunkte antworten mit `{"result": …, "board": <frischer Board-Stand>}`.

**Board (`/api/dispatch`)**

| Methode | Pfad | Body |
|---|---|---|
| GET | `board-data?date=YYYY-MM-DD` | – |
| POST | `assign-visit` | `visit_id`, `target_tour_id` **oder** `target_caregiver_id`, `target_time` (HH:MM), `force`, `reason` |
| POST | `unassign-visit` | `visit_id`, `reason` |
| POST | `tour/{id}/assign-caregiver` | `caregiver_id` (oder `null`), `force`, `reason` |
| POST | `tour/{id}/interrupt` | `reason`, `current_visit_outcome` |
| POST | `visit/{id}/companion` | `caregiver_id`, `force`, `reason` |
| POST | `generate-day` | `date` |
| POST | `optimize` | `date`, `mode: "tour"` + `tour_id` oder `mode: "pool"` (liefert nur einen Vorschlag) |
| POST | `apply-proposal` | `date`, `proposal`, `reason` |
| GET | `patient/{id}` | – (Zugriffsprotokoll, falls aktiviert) |
| GET | `patient/{id}/history?limit=100` | – (`PFLEGE_AUDIT_VIEW`) |

Bei `target_caregiver_id` landet der Einsatz in der offenen Tour der Pflegekraft. Hat sie an dem Tag keine, wird automatisch eine **Ad-hoc-Tour** angelegt.

**Mobil (`/api/mobile`)**: `GET my-tour`, `POST check-in`, `POST check-out`, `POST break {action: start|end}`, `POST visit/{id}/status {action: start|complete, tasks:[{id,status,note}], notes}`, `POST task/{id} {status, note}`, `POST sos {reason, current_visit_outcome, tour_id?}`.

## BuildTasks (Silverstripe 6 / `sake`)

```bash
vendor/bin/sake tasks                                          # Übersicht
vendor/bin/sake tasks:care-dispatch-generate-tours             # morgen (idempotent)
vendor/bin/sake tasks:care-dispatch-generate-tours --date=2026-11-02 --days=7
vendor/bin/sake tasks:care-dispatch-close-stale-entries --max-hours=14 [--dry-run]
vendor/bin/sake tasks:care-dispatch-geocode [--all]
vendor/bin/sake tasks:care-dispatch-purge-audit --years=10 [--dry-run]   # nur CLI
```

Cron-Beispiel:

```cron
0 18 * * *  cd /var/www && vendor/bin/sake tasks:care-dispatch-generate-tours --days=2
30 3 * * *  cd /var/www && vendor/bin/sake tasks:care-dispatch-close-stale-entries
```

## Routenoptimierung

| Komponente | Standard | Produktion |
|---|---|---|
| Geokodierung | **aus** (`NullGeocoder`) | eigene Nominatim-Instanz (`NominatimGeocoder`) |
| Fahrzeiten | Luftlinie × Umwegfaktor | OSRM (`CARE_DISPATCH_OSRM_URL`) |
| Solver | PHP-Heuristik (Einfügen + Relocate) | VROOM (`CARE_DISPATCH_VROOM_URL`) |

Self-hosting: siehe `docker/routing` (`./prepare.sh europe/germany`, danach `docker compose up -d`).

An die Routing-Server gehen **nur Koordinaten**, keine Namen oder Pflegedaten. Bei Ausfall von OSRM oder VROOM greift automatisch der Fallback, und das Board zeigt einen Hinweis an.

`.env`:

```dotenv
CARE_DISPATCH_DEPOT_LAT="52.5200"        # Firmenstandort = Start/Ende der Routen
CARE_DISPATCH_DEPOT_LNG="13.4050"
CARE_DISPATCH_OSRM_URL="http://osrm:5000"
CARE_DISPATCH_VROOM_URL="http://vroom:3000"
CARE_DISPATCH_NOMINATIM_URL="https://nominatim.intern.example"
```

Geocoder aktivieren (`app/_config/care-dispatch.yml`):

```yaml
SilverStripe\Core\Injector\Injector:
  YouWillLikeIT\CareDispatch\Routing\GeocoderInterface:
    class: YouWillLikeIT\CareDispatch\Routing\NominatimGeocoder
YouWillLikeIT\CareDispatch\Routing\NominatimGeocoder:
  min_interval: 0
```

Regeln in der Optimierung:
- Qualifikation, Ausschlüsse, Dienstzeiten und Zeitfenster werden eingehalten.
- Bevorzugte Pflegekräfte sind weich (nur Heuristik, VROOM kennt keine Präferenzen).
- Bereits geplante Einsätze bleiben im Modus „Pool verteilen“ fix.
- Doppeleinsätze und Begleitungen behalten ihre Uhrzeit.
- Heute startet die Route am letzten besuchten Patienten, frühestens jetzt.

Vorschläge ändern nichts. Erst „Übernehmen“ wendet jeden Stopp über die normale Zuweisung an, mit allen harten Regeln. Zwischenzeitlich geänderte Einsätze werden als `STALE` übersprungen.

## Audit und Datenschutz

- `AuditableExtension` protokolliert `create`/`update`/`delete` aller Modelle mit Feld-Diff, Benutzer, IP und Zeitpunkt. Domänenereignisse (SOS, Zuweisung, Overrides, Kommen/Gehen …) werden mit Begründung protokolliert.
- `AuditEntry` ist über das ORM unveränderlich. Löschen ist nur mit `care-dispatch-purge-audit` möglich (Aufbewahrungsfrist, Standard 10 Jahre).
- Zugriffsprotokoll (wer hat welche Patientenakte geöffnet): `AuditLogger.log_read_access: true`. Standard ist `false`, wiederholte Zugriffe werden 15 Minuten lang zusammengefasst.
- API- und Seitenantworten sind `no-store`, Session-Cookies in SS6 standardmäßig `Secure` + `SameSite=Strict`.

## Frontend

```bash
npm install
npm run build      # → client/dist/{board,mobile}.{js,css}
npm run dev        # Watch-Build mit Sourcemaps
```

Vite 8, React 19, dnd-kit, Tailwind 4, Alpine.js 3. Der Einstieg erfolgt über `Requirements::javascript(…, ['type' => 'module'])`. Die Boot-Config (API-URL, CSRF-Token, Datum) liegt als JSON in `data-config`.

## Tests

```bash
vendor/bin/phpunit vendor/youwilllikeit/silverstripe-care-dispatch/tests
```

## Bekannte Grenzen

- Änderungen an `many_many`-Relationen (bevorzugte/ausgeschlossene Pflegekräfte) erscheinen nicht im Feld-Diff des Audit-Trails.
- Bei Teildiensten nutzt die Routenoptimierung das Fenster vom ersten Beginn bis zum letzten Ende. Die Pause dazwischen wird nicht als Sperrzeit berücksichtigt.
- Einsätze über Mitternacht werden nicht unterstützt (Nachtdienste schon).
