# LoxBerry-Plugin: Renault NG

Verbindet Renault-Elektrofahrzeuge (Zoe PH1/PH2, Twingo Electric u. a.) mit dem
Loxone Miniserver – über den LoxBerry. Batteriestand, Reichweite, Ladestatus,
Kilometerstand, Position u. v. m. werden regelmäßig abgerufen und per **MQTT**
(LoxBerry MQTT Gateway) bereitgestellt. Vorklimatisierung und Ladesteuerung
lassen sich aus Loxone heraus auslösen.

Fortführung des [Renault-Plugins von **PeterB**](https://wiki.loxberry.de/plugins/renault_my_ze/start),
das seinerseits auf [ZoePHP](https://github.com/db-EV/ZoePHP) von db-EV
aufbaut. Apache-Lizenz 2.0; die Liste der Änderungen steht in `NOTICE`.

---

## Version 2.1.0 — was Sie wissen müssen, bevor Sie aktualisieren

Diese Fassung behebt zwei Fehler, die dem Plugin von außen nie anzusehen waren,
und ändert **eine Vorgabe**. Bitte die ersten drei Punkte lesen.

### 1. Der Loxone-Endpunkt hat nie gewirkt — jetzt tut er es

`webfrontend/html/index.php` band seine Bibliothek über
`__DIR__ . '/../htmlauth/rn_lib.php'` ein. Das stimmt im entpackten Archiv, wo
`html/` und `htmlauth/` nebeneinander liegen. Auf einem **installierten**
LoxBerry liegen sie in getrennten Bäumen:

| | Pfad |
|---|---|
| Endpunkt | `<home>/webfrontend/html/plugins/<ordner>/` |
| Bibliothek | `<home>/webfrontend/htmlauth/plugins/<ordner>/` |

Der Ausdruck ergab dort `webfrontend/html/plugins/htmlauth/` — ein Verzeichnis,
das es nicht gibt. `require_once` brach fatal ab, und weil zwei Zeilen darüber
`display_errors` abgeschaltet wird, kam beim Miniserver ein **leerer HTTP 500**
an: keine Meldung, kein Protokolleintrag. Sämtliche Loxone-Befehle dieses
Plugins — Vorklimatisierung, Sofortladen, Ladeplan — haben deshalb an einer
echten Anlage nie gewirkt.

Gemessen am 17.08.2026 mit Gegenprobe: in getrennten Bäumen Rückgabewert 255 und
leere Antwort, im entpackten Archiv derselbe Aufruf regulär. Die Korrektur
übernimmt die Kandidatenliste aus dem Heimkino-Plugin, das dieselbe Klasse bis
1.2.10 hatte. **Nach dem Update bitte einmal den Selbsttest aufrufen** (siehe
Reiter *Einbindung in Loxone*, letzte Zeile der Befehlstabelle).

### 2. Fünf MQTT-Themen der Anleitung gab es nie

Die Oberfläche, die Baustein-Liste **und die erzeugte Importdatei für Loxone
Config** nannten Themen, die der Sendecode nie veröffentlicht hat:

| in der Anleitung (falsch) | tatsächlich gesendet |
|---|---|
| `BatteryLevel` | `BattSOC` |
| `RangeHvacOff` | `Range` |
| `PlugStatus` | `CableStatus` |
| `ChargingRemaining` | `ChargingTime` |
| `ChargingPower` | `ChargingEffekt` |

Wer die Importdatei einlas, bekam für **Batteriestand, Reichweite und
Kabelzustand** virtuelle Eingänge, die dauerhaft auf 0 standen — ohne
Fehlermeldung. Angeglichen wurde die **Anleitung an den Sendecode**, nicht
umgekehrt: Umbenennen im Sendecode hätte jede bestehende Anlage gebrochen.
`uninstall/uninstall` nannte die richtigen Namen seit jeher.

**Für Bestandsanlagen ändert sich damit nichts.** Wer die falschen Eingänge
angelegt hatte, legt die richtigen neu an oder benennt sie um.

Damit das nicht wieder auseinanderläuft, prüft der Reiter *Test* die Liste jetzt
gegen die `publish()`-Zeilen im Programmcode („Themen abgleichen") und die
erzeugte Importdatei gegen dieselbe Quelle („Importdateien prüfen").

### 3. Schaltende Befehle sind ab Werk gesperrt

Neu ist der Schalter **Einstellungen → Schalten → Befehle aus Loxone dürfen
schalten**. Er steht ab Werk auf *aus*; der Endpunkt weist schaltende Befehle
dann mit HTTP 403 ab und sagt dazu, wo der Schalter sitzt. Lesende Aufrufe
(`aktion=abruf`) und der Selbsttest sind nicht betroffen.

Der Grund ist die Hausregel „neue Funktionen ab Werk aus". Da Punkt 1 die
Befehle überhaupt erst wirksam macht, wäre eine Fassung, die sie zugleich
freischaltet, ein Vorgabewert, der ungefragt schaltet. **Wer aus Loxone schalten
will, schaltet es einmal ein.**

---

## Version 2.1.0 — die übrigen Änderungen

### Behoben

- **Befehle hingen an der Abrufbremse.** Der Endpunkt setzte `$_GET['cron']=1`,
  allein wegen des Ausgabeformats. `abruf.php` las daraus die Betriebsart
  „Cron" und lief in die Intervallsperre — und die stand **vor** den
  Befehlsblöcken. Ein Befehl kam nur in etwa einer von sechs Minuten durch; in
  den übrigen antwortete das Plugin mit HTTP 200 und dem Text
  `INTERVAL NOT REACHED`, also einer Erfolgsmeldung für eine nicht ausgeführte
  Handlung. Ausgabeformat und Betriebsart hängen nicht mehr am selben Merker.
- **Erstinstallation: der Cron starb, bevor er etwas protokollieren konnte.**
  `abruf.php` las die Konfiguration mit `require`. Vor dem ersten Öffnen der
  Oberfläche gibt es die Datei nicht — also alle drei Minuten ein fataler
  Abbruch, ohne eine Zeile im Protokoll, weil `logger.php` erst danach kam.
- **Aktualisierungsfall: fehlende Schlüssel rissen den Abruf ab.** Dieselbe
  Ursache. Eine `config.php` aus 1.4 kennt `cron_ncs` nicht; das ergab
  `date_interval_create_from_date_string(' minutes') === false` und unter PHP 8
  einen `TypeError`. Konfiguration kommt jetzt überall über `rn_config_read()`,
  das fehlende Schlüssel aus den Vorgaben ergänzt.
- **Die Oberfläche vernichtete den Umzug der Altdaten.** `rn_umzug()` wurde nur
  vom Cron gerufen, die Oberfläche legte aber als erste Handlung eine leere
  `config.php` an — und `rn_umzug()` zieht nur um, solange dort nichts steht.
  Wer nach einem Update von 1.4 zuerst die Oberfläche öffnete, verlor Benutzer,
  Passwort und Fahrgestellnummer. Jetzt ruft auch die Oberfläche den Umzug.
- **Die Zweitschrift lag im selben Ordner wie das Original.** Der Kommentar
  sagte „außerhalb des Plugin-Ordners", der Code schrieb nach
  `config/plugins/<ordner>/config.php.backup` — also genau dorthin, was LoxBerry
  bei einer Deinstallation abräumt. Sie liegt jetzt **neben** dem Ordner, als
  `config/plugins/<ordner>.backup.config.php`, und bekommt dieselben Rechte
  0600 wie das Original; sie enthält dasselbe Passwort.
- **Rechte vor Inhalt.** `config.php` und die Zweitschrift werden über eine
  Nebendatei geschrieben, die *vor* dem Füllen auf 0600 gesetzt wird. Vorher
  stand die Datei für die Dauer des Schreibens mit den Rechten der umask da.
- **Die Ausfallerkennung trägt.** `phpCall` kam aus der aktuellen Uhrzeit und
  wurde bei jedem Lauf gesetzt, auch wenn Renault mit einem Fehler geantwortet
  hatte — die Erkennung, die der Reiter beschreibt, konnte nie ansprechen. Der
  Zeitstempel wird jetzt nur bei **Erfolg** aufgefrischt, und es gibt das
  Sammelthema `ok` (1/0). Bei einem Fehlschlag wird ausschließlich `ok=0`
  gesendet; alle übrigen Themen behalten ihren zurückbehaltenen Wert.
- **Ladehistorie: ein Datensatz aus zwei Vorgängen.** `publish()` stand
  innerhalb der Schleife über alle Ladevorgänge und las die Werte aus
  `$data[0]`, die Dauer aber aus dem gerade bearbeiteten Durchlauf. Am Ende
  stand die Energie des **neuesten** Vorgangs geteilt durch die Dauer des
  **ältesten** im Broker. Veröffentlicht wird jetzt nur der neueste Vorgang,
  und alle Werte stammen aus derselben Zeile.
- **`history.php` protokolliert.** Bis 2.0.6 band es `logger.php` gar nicht
  ein — ein Programm, das alle zehn Minuten ins Netz geht, war im Reiter
  *Logdateien* unsichtbar. Dazu Prüfungen auf `FALSE` beim Lesen der
  Sitzungsdatei und auf `cookieValue`/`id_token`, die vorher fehlten.
- **Eine Sperre für beide Cron-Skripte.** Sie hatten je eine eigene
  (`/tmp/renault_abruf.lock` gegen `/tmp/renault_history.lock`) und sperrten
  sich damit gerade *nicht* gegeneinander, obwohl beide dieselben Dateien
  schreiben. Jetzt `/tmp/renault_ng.lock` für beide.
- **Zeilenenden der Cron-Skripte.** `cron.03min` und `cron.10min` waren
  vollständig CRLF. Der Kernel sucht dann `/bin/bash\r`, findet ihn nicht und
  bricht mit 126 und *bad interpreter* ab — der Abruf lief nie. Beide Dateien
  sind jetzt durchgehend LF. Es genügt nicht, nur die Shebang zu richten: mit
  CRLF im Rest wird aus `VERZ` der Wert `…\r`, und die Verzeichnisprüfung
  schlägt fehl.
- **HTTP-Fehler steuern jetzt etwas.** Antwortet Kamereon mit 401 oder 403
  (etwa nach einem Passwortwechsel bei Renault), wird die Anmeldung verworfen
  und beim nächsten Lauf erneuert. Vorher stand das Plugin bis Mitternacht.
- **`$md5` und die GPS-Zerlegung.** `$md5` wurde undefiniert in den
  Zwischenspeicher geschrieben, wenn der Abruf wegen der Minutensperre
  ausfiel — der Vergleichswert ging verloren und der nächste erfolgreiche
  Abruf fragte alles nach. Die Themen `GPS-Latitude_1..3` und
  `GPS-Longitude_1..3` entfallen: sie entstanden aus `str_split($wert, 5)` und
  lasen drei Teile, bei üblicher Genauigkeit gibt es aber nur zwei. Der volle
  Wert steht weiterhin unter `GPS-Latitude` und `GPS-Longitude`.
- **Speichern hing an einem `php` im Suchpfad.** Die Syntaxprüfung der neuen
  `config.php` wertete „php nicht gefunden" wie einen Syntaxfehler; das
  Speichern schlug fehl, und die Oberfläche schickte den Benutzer mit „Rechte
  im Plugin-Ordner prüfen" in die falsche Richtung.
- **Log-Kappung nach Hausstandard:** ab 500 kB bleiben die letzten 200 Zeilen.
  Vorher 1 MB plus eine Sicherung, also bis 2 MB — auf einer Ramdisk. Dazu eine
  Wiederholungsbremse: dieselbe Meldung höchstens einmal je Stunde, die
  übrigen werden gezählt und zusammengefasst.

### Neu

- **Bis zu vier Fahrzeuge** desselben Kontos. Jedes bekommt seinen eigenen
  Themenpfad, Zwischenspeicher und seine eigene Aufzeichnung; die Anmeldung bei
  Renault ist gemeinsam und steht in `data/plugins/<ordner>/anmeldung`.
  Fahrzeug 1 behält Name und Dateinamen — eine bestehende Anlage merkt nichts.
- **Zehn Einstellungen, die es nur in der Datei gab, haben Bedienfelder:**
  Abruftakt normal und beim Laden, Meldeschwelle, E-Mail und Fremdbefehl bei
  erreichtem Akkustand und bei beendetem Laden, Umschalten auf Ladeplan,
  OpenWeatherMap-Schlüssel, ABRP-Token und -Modell. Alle stehen ab Werk aus.
  Die beiden toten Schlüssel `hide_cm` und `map_provider` sind entfallen — sie
  wurden nirgends gelesen.
- **Zwei neue Befehle:** `acoff` (Vorklimatisierung beenden) und `chargestop`
  (Laden anhalten). Beide versuchen zuerst die Form, die die meisten Fahrzeuge
  kennen, und weichen bei einem Fehlercode auf die zweite aus — bei
  `chargestop` also `charging-start` mit `stop`, danach
  `kcm/…/charge/pause-resume` mit `pause` (Zoe PH2, Dacia Spring).
- **Zieltemperatur der Vorklimatisierung** einstellbar, 16 bis 30 Grad. Vorher
  stand 21 fest im Quelltext.
- **Ladeziel** (`socMin`/`socTarget` über `kcm/…/ev/soc-levels`). Beide Felder
  leer heißt: das Plugin fasst es nicht an. Das können **nur die neueren
  Plattformen** (Megane E-Tech, Scenic, R5, R4, A290, Master); bei Zoe PH1 und
  PH2 antwortet der Endpunkt mit einem Fehler, der einmal im Protokoll steht.
- **Innen- und Außentemperatur aus dem Fahrzeug** (`hvac-status` liefert neben
  `hvacStatus` auch `internalTemperature` und `externalTemperature`). Der
  Wetterdienst ist damit nur noch Ersatz — der dort benutzte Endpunkt der
  Fassung 2.5 ist bei OpenWeatherMap ohnehin abgekündigt.
- **`?selftest=1&token=…`** am Endpunkt: prüft das in Loxone eingetragene Token,
  ohne am Fahrzeug etwas zu schalten. Antwort `SELFTEST;OK=1;TOKEN=OK`. Genau
  das hätte den Fehler aus Punkt 1 am ersten Tag gezeigt.
- **Tagesdiagramm im Reiter Ladehistorie** — reines SVG, ohne Fremdbibliothek.
  Die README versprach seit 1.4 eine „Diagramm-Seite"; es gab nur eine Tabelle.
- **Der Reiter Test prüft mehr:** Rechte der Konfigurationsdatei, Autostart des
  Gateways, Alter des letzten erfolgreichen Abrufs je Fahrzeug, Länge des
  Tokens, Übereinstimmung von Themenliste und Sendecode, Kongruenz von
  Reiterleiste und Bereichen.
- **Die Modellkennung wird ermittelt und angezeigt**, aber **nicht ausgewertet**.
  Welche Felder `/vehicles/<vin>/details` genau führt, lässt sich ohne echtes
  Konto nicht belegen; ein Programm, das die Fahrzeuggeneration anhand eines
  geratenen Feldnamens selbst umstellt, wäre schlechter als eine Auswahlliste.
  Der Wert steht in den Einstellungen unter der Generation und im Reiter Test.

### Bewusst **nicht** gemacht

- **Ladepläne schreiben.** Lesen wäre möglich, das Schreiben verlangt einen
  JSON-Rumpf, dessen Aufbau je Plattform abweicht und den ich nicht belegen
  kann. Ein falsch geformter Schreibzugriff auf den Ladeplan eines Fahrzeugs
  ist kein Fehler, den man einem Anwender zumutet. Bleibt offen.
- **`lock-status`, `res-state`, `pressure`, `horn-lights`, `alerts`.** Für diese
  Endpunkte gibt es entweder kein veröffentlichtes Antwortbeispiel oder sie
  sind für kein Fahrzeug als funktionierend belegt. Nicht eingebaut.

---

## Umstieg auf 2.0.0 — das Plugin heißt Renault NG

Ab 2.0.0 trägt diese Fortführung eine **eigene Kennung** und einen eigenen
Namen: `[PLUGIN] NAME` und `FOLDER` lauten `renault_ng`, `TITLE` ist
`Renault NG`.

**Warum.** Die Felder unter `[AUTHOR]` sind kein Urhebervermerk, sondern das,
woraus LoxBerry zusammen mit `[PLUGIN] NAME` die Kennzahl bildet, unter der es
Installation und Updates führt. Bis 1.6.0 stand dort der Originalautor mit
seiner privaten Mailadresse, während `AUTOMATIC_UPDATES` bereits hierher zeigte.
Fehlerberichte zu dieser Fassung wären bei ihm gelandet.

**In Loxone Config nachziehen.** Der Ordner bestimmt die Adresse des Endpunkts:

| bisher | ab 2.0.0 |
|---|---|
| `/plugins/Renault_API/index.php?token=<TOKEN>&aktion=…` | `/plugins/renault_ng/index.php?token=<TOKEN>&aktion=…` |

**An den MQTT-Themen ändert sich nichts** — sie lauten weiterhin
`Renault/<Fahrzeugname>/…` und hängen nicht am Ordnernamen.

**Nebenbei behoben:** der Ordnername stand in `rn_lib.php` fest im Quelltext.
Hängt LoxBerry bei der Installation einen Zähler an, weil der Name schon belegt
ist, zeigten alle Pfade der Zweitinstallation auf die erste.

## Funktionen

- Abruf von Batterie-, Lade- und Fahrzeugdaten über die My-Renault-Schnittstelle
  (Gigya/Kamereon), für bis zu vier Fahrzeuge desselben Kontos
- MQTT über das LoxBerry MQTT Gateway, alle Werte **retained**, Themen
  `Renault/<Fahrzeugname>/…`
- Befehle über `/plugins/renault_ng/index.php?token=<TOKEN>&aktion=…` (ohne
  LoxBerry-Anmeldung, dafür mit Token; ab Werk gesperrt)
- Ladehistorie mit Tagesdiagramm, Ausfallerkennung über `ok` und `phpCall`
- Sechs Reiter: **Einstellungen**, **MQTT**, **Einbindung in Loxone**, **Test**,
  **Ladehistorie**, **Logdateien**

## Wo die Daten liegen

| | bis 1.4 | ab 1.6.0 |
|---|---|---|
| Konfiguration | `webfrontend/htmlauth/config.php` | `config/plugins/renault_ng/` (0600) |
| Zweitschrift | – | `config/plugins/renault_ng.backup.config.php` (0600, ab 2.1.0 **neben** dem Ordner) |
| Anmeldung, Sitzungen, Ladehistorie | `webfrontend/htmlauth/` | `data/plugins/renault_ng/` |
| Protokoll | `webfrontend/htmlauth/` | `log/plugins/renault_ng/` |

`webfrontend/htmlauth` gehört zum **Programm** und wird bei jedem Update
gelöscht und neu angelegt. Deshalb gab es bis 1.4 eine Sicherung nach `/tmp` –
eine Krücke um einen Konstruktionsfehler herum, und eine brüchige dazu, weil
`/tmp` auf dem LoxBerry eine Ramdisk ist. `rn_umzug()` holt beim ersten Aufruf
nach, was noch am alten Ort liegt.

## Installation

ZIP über die LoxBerry-Pluginverwaltung installieren, dann im Reiter
**Einstellungen** die My-Renault-Zugangsdaten, die Fahrgestellnummer und die
Fahrzeuggeneration eintragen. Alles Weitere erklärt der Reiter **Einbindung in
Loxone**.

Nach der Installation lohnt sich ein Blick in den Reiter **Logdateien**: der
Drei-Minuten-Cron meldet dort, wenn etwas fehlt.

## Hinweise

- Höchstens ein Abruf je Minute – das hält das Plugin selbst ein
- Bei „There is no data for this vin and uid": Datenfreigabe im Fahrzeug
  aktivieren und prüfen, ob die My-Renault-App Live-Daten zeigt. Das Protokoll
  listet in diesem Fall die im Konto verknüpften Fahrgestellnummern auf.
- Die Zugangsdaten bleiben lokal auf dem LoxBerry

## Ältere Änderungen

- **2.0.1 bis 2.0.6**: Pflege der Fassungsstände und der Sprachdateien; am
  Programmcode hat sich zwischen 2.0.5 und 2.0.6 nichts geändert (byte-gleich).
- **1.6.0**: Nutzdaten aus dem Programmordner nach `config/`, `data/` und `log/`
  verlagert; `preupgrade.sh` sichert nicht mehr nach `/tmp`. Nur noch ein
  Sprachsystem (`templates/lang/language_*.ini`), `webfrontend/htmlauth/lng/`
  entfallen. Vertauschte Variable in `abruf.php` behoben (`$exec_csf` geprüft,
  `$exec_bl` ausgeführt) und `escapeshellarg()` für die Meldung. Reiter als
  echte Verweise mit serverseitigem `sm-active`. Neues Symbol ohne
  Herstellerzeichen. Vorlagenreste entfernt: `daemon/daemon` schrieb bei jedem
  Systemstart eine Beispielzeile ins Systemprotokoll, `cron/crontab` ließ
  stündlich ein Echo ins Nichts laufen.
- **1.4.1**: MQTT-Thema heißt `ChargingStatus` (vorher `CargingStatus`).
- **1.4**: Konfiguration, Ladehistorie und Protokoll überstehen Updates; Reiter
  *Anleitung*.
- **1.3**: Automatische Wahl des MYRENAULT-Kontos, Diagnose der verknüpften
  Fahrgestellnummern bei 404.
- **1.2**: Reiter Konfiguration und Log; fehlgeschlagener Login wird nicht mehr
  bis Mitternacht zwischengespeichert.
- **1.1**: Aktuelle Schlüssel (ZoePHP 2026), nicht-fatale Behandlung entfallener
  Endpunkte.

## Nachprüfbar

`templates/lang/language_de.ini` und `language_en.ini` führen je **287
Schlüssel**, deckungsgleich; jeder davon wird benutzt, und jeder benutzte
Schlüssel ist vorhanden. Beide Dateien werden aus einer Quelle erzeugt, damit
die Mengen gar nicht auseinanderlaufen können — bis 2.0.6 war das nur eine
Zusage in dieser Datei, und sie nannte dazu die falsche Zahl (223 statt der tatsächlichen 227).
