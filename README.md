# LoxBerry-Plugin: Renault NG

Verbindet Renault-Elektrofahrzeuge (Zoe PH1/PH2, Twingo Electric u. a.) mit dem
Loxone Miniserver – über den LoxBerry. Batteriestand, Reichweite, Ladestatus,
Kilometerstand, Position u. v. m. werden alle 3 Minuten abgerufen und per
**MQTT** (LoxBerry MQTT Gateway) bereitgestellt. Vorklimatisierung und
Ladesteuerung lassen sich aus Loxone heraus auslösen.

Fortführung des [Renault-Plugins von **PeterB**](https://wiki.loxberry.de/plugins/renault_my_ze/start),
das seinerseits auf [ZoePHP](https://github.com/db-EV/ZoePHP) von db-EV
aufbaut. Apache-Lizenz 2.0; die Liste der Änderungen steht in `NOTICE`.

## Umstieg auf 2.0.0 — das Plugin heißt jetzt Renault NG

Ab 2.0.0 trägt diese Fortführung eine **eigene Kennung** und einen eigenen
Namen: `[PLUGIN] NAME` und `FOLDER` lauten `renault_ng`, `TITLE` ist
`Renault NG`.

**Warum.** Die Felder unter `[AUTHOR]` sind kein Urhebervermerk, sondern das,
woraus LoxBerry zusammen mit `[PLUGIN] NAME` die Kennzahl bildet, unter der es
Installation und Updates führt. Bis 1.6.0 stand dort der Originalautor mit
seiner privaten Mailadresse, während `AUTOMATIC_UPDATES` bereits hierher
zeigte. Fehlerberichte zu dieser Fassung wären bei ihm gelandet.

**In Loxone Config nachziehen.** Der Ordner bestimmt die Adresse des
Endpunkts:

| bisher | ab 2.0.0 |
|---|---|
| `/plugins/Renault_API/index.php?token=<TOKEN>&aktion=…` | `/plugins/renault_ng/index.php?token=<TOKEN>&aktion=…` |

Jeder virtuelle Ausgang, der Vorklimatisierung, Sofortladen oder den Ladeplan
auslöst, muss umgestellt werden. Die fertigen Adressen samt Token stehen im
Reiter **Einbindung in Loxone**.

**An den MQTT-Themen ändert sich nichts** — sie lauten weiterhin
`Renault/<Autoname>/…` und hängen nicht am Ordnernamen. Die virtuellen
*Eingänge* bleiben also unberührt.

**Was Sie sonst tun müssen.** LoxBerry sieht ab 2.0.0 ein anderes Plugin; ein
vorhandener Stand bekommt dieses Update *nicht* angeboten. Einmal neu
installieren, die Zugangsdaten zur My-Renault-Anmeldung neu eintragen, danach
läuft das Auto-Update von selbst. Ein Blick in die alte Oberfläche vor der
Deinstallation lohnt sich.

**Nebenbei behoben:** der Ordnername stand in `rn_lib.php` fest im Quelltext.
Hängt LoxBerry bei der Installation einen Zähler an, weil der Name schon
belegt ist, zeigten alle Pfade der Zweitinstallation auf die erste. Der Name
wird jetzt ermittelt.

## Funktionen

- Abruf von Batterie-/Lade-/Fahrzeugdaten über die My-Renault-API (Gigya/Kamereon)
- MQTT-Publish über das LoxBerry MQTT Gateway (Topics `Renault/<Autoname>/...`)
- Kommandos: Vorklimatisierung, Sofortladen, Ladeplan ein/aus über den Endpunkt `/plugins/renault_ng/index.php?token=<TOKEN>&aktion=acnow` (ohne LoxBerry-Anmeldung, dafür mit Token)
- Ladehistorie (CSV) mit Diagramm-Seite
- Reiter **gesp. Konfiguration** (gespeicherte Einstellungen + Schnell-Diagnose),
  **Log** (jeder API-Schritt wird protokolliert) und **Anleitung**
  (Schritt-für-Schritt-Einbindung in Loxone für Einsteiger)

## Version 1.6.0 — Prüfung eines Mitlesers

Vier Meldungen. Zwei treffen zu, eine nicht, und bei der wichtigsten liegt
die Ursache eine Ebene tiefer als gemeldet.

### Die `/tmp`-Sicherung beim Update — zutreffend, aber nur das Symptom

Gemeldet: `preupgrade.sh` sichert nach `/tmp/renault_api_upgrade`, und `/tmp`
ist eine Ramdisk. Stimmt. Ein Neustart zwischen den beiden Schritten hätte
Ladehistorie, Sitzung und Protokoll gekostet.

Die Frage ist nur, warum es diese Sicherung überhaupt gab. Antwort: Weil die
Nutzdaten am falschen Ort lagen — `config.php`, `session`, `database.csv` und
`renault.log` standen in `webfrontend/htmlauth`, also **im Programmordner**.
Genau den löscht LoxBerry bei jedem Update und legt ihn neu an. Die Sicherung
war eine Krücke um einen Konstruktionsfehler herum, und sie hätte auch am
sicheren Ort nur die Krücke verbessert.

*Jetzt* liegen die Daten dort, wo LoxBerry sie ohnehin stehen lässt:

| | bis 1.4 | ab 1.6.0 |
|---|---|---|
| Konfiguration | `webfrontend/htmlauth/config.php` | `config/plugins/renault_ng/` (0600) |
| Sitzung, Ladehistorie | `webfrontend/htmlauth/` | `data/plugins/renault_ng/` |
| Protokoll | `webfrontend/htmlauth/` | `log/plugins/renault_ng/` |

Damit entfällt die Sicherung ersatzlos. `rn_umzug()` holt beim ersten Aufruf
nach, was noch am alten Ort liegt — geprüft: vier Dateien umgezogen, Inhalt
erhalten, Rechte 0600, ein zweiter Aufruf bewegt nichts mehr.

Nebenbei: Der zuerst vorgeschlagene Ausweichort — der Temp-Ordner des
Installers — hätte dasselbe Problem gehabt. Erst die zweite Anregung, in den
Konfigordner zu schreiben, trägt; sie ist umgesetzt und um `data/` und `log/`
erweitert.

### `var_export` in der `config.php` — **nicht nachvollziehbar**

Gemeldet als „hochkritischer Designfehler", SSTI und Code Execution.
Nachgestellt mit vier Angriffsformen — Wert schließt die Zeichenkette, Wert
mit Zeilenumbruch, Wert mit `?>` und angehängtem PHP, Wert mit Backslash am
Ende:

```
ausgefuehrt=nein   $zoename='x\'; system(\'touch …\'); $y=\''
ausgefuehrt=nein   $zoename='x\'; ?><?php system(\'touch …\'); ?>'
```

`var_export()` erzeugt eine einfach zitierte Zeichenkette und verdoppelt die
Backslashes; ausgeführt wurde nichts. Der zweite denkbare Weg — ein
bösartiger **Schlüsselname**, der `$k` und damit den Variablennamen
vergiftet — ist ebenfalls zu, weil die Schlüssel aus einer festen Liste im
Formular kommen (`zoename`, `username`, `vin`, `country`, `zoeph`,
`save_in_db`, `password`) und nicht aus `$_POST`-Schlüsseln.

Die Umstellung auf JSON habe ich deshalb **nicht** gemacht: Sie hätte
`abruf.php` und `history.php` angefasst, die die Werte als globale Variablen
erwarten — rund 600 Zeilen fremder Logik ohne Testabdeckung, für einen
Gewinn, den ich nicht belegen kann. Was an dem Unbehagen berechtigt war, ist
der Ablageort, und der ist behoben.

### Zwei Funde, die nicht gemeldet waren

**Vertauschte Variable.** In `abruf.php` prüfte die Bedingung `$exec_csf`
(„Ladung beendet"), ausgeführt wurde aber `$exec_bl` („Akkustand erreicht"):

```php
if (!empty($exec_csf)) shell_exec($exec_bl.' "'.$sendmessage.'"');
```

Wer beide Felder gefüllt hatte, bekam zweimal denselben Befehl; wer nur
`exec_csf` gefüllt hatte, bekam einen leeren Aufruf und damit gar nichts.

**Befehlseinschleusung über die Meldung.** Dieselbe Zeile setzte
`$sendmessage` unmaskiert zwischen Anführungszeichen. Die Meldung wird aus
Werten der Renault-Schnittstelle gebaut. Nachgestellt:

```
alte Bauweise  /bin/echo "boese"; touch /tmp/ren/PWNED_sh; ""   -> ausgefuehrt: JA
neue Bauweise  /bin/echo 'boese"; touch /tmp/ren/PWNED_sh; "'   -> ausgefuehrt: nein
```

Jetzt `escapeshellarg()`. Der Befehl selbst bleibt unmaskiert — er ist eine
bewusste Eingabe des Betreibers in den Einstellungen, kein Fremdwert.

### Der Webhook — kein Einschleusungsweg

Gemeldet mit dem Vorbehalt, `abruf.php` liege nicht vor. Sie lag hier vor:
Die beiden `shell_exec`-Aufrufe verwenden `$exec_bl`/`$exec_csf` aus der
Konfiguration und `$sendmessage` aus den Schnittstellendaten. `$_GET` erreicht
sie nicht. Der `require`-Umweg bleibt ein Altlast-Muster, ist aber kein
Sicherheitsloch.

### Cron-Skripte

Der stille Fehlschlag stimmt und ist behoben — die Meldung geht allerdings
**nicht** nach `/tmp/renault_cron.log`, wie vorgeschlagen: dieselbe Ramdisk,
und ausgerechnet nach einem Neustart sucht man den Hinweis. Sie geht ins
Protokoll des Plugins, das die Oberfläche im Reiter *Protokoll* anzeigt.
Trockenlauf mit fehlendem Verzeichnis: Rückgabewert 1, Zeile im Protokoll.

### Hausstandard

Die Reiter waren `<div>` ohne Verweis, `sm-active` vergab allein das
JavaScript — ohne JavaScript war die Seite leer und die Reiter nicht einmal
anklickbar. Jetzt echte Verweise mit serverseitigem `sm-active`, alle sechs
über `?form=…` geprüft. Dazu `data-role="none"` an allen 15 Bedienelementen
(vorher keinem), `prerelease.cfg` ergänzt (`PRERELEASECFG` war leer bei
eingeschaltetem Auto-Update) und das Symbol auf das neue Hausmuster gebracht.
Beide PHP-Fassungen liefern in beiden Sprachen zeichengleiche Ausgabe ohne
eine Meldung.

## Version 1.6.0

- **Nur noch ein Sprachsystem.** Die beiden geerbten Seiten `abruf.php` und
  `history.php` holten ihre Texte bisher aus `webfrontend/htmlauth/lng/*.php`,
  die Oberfläche dagegen aus `templates/lang/language_*.ini` über `rn_t()`. Wer
  einen Text ändern wollte, musste wissen, welches der beiden Systeme gerade
  zuständig ist. Die noch benutzten Texte stehen jetzt im Abschnitt
  `[MELDUNG]` der beiden INI-Dateien, `lng/` ist entfallen. Damit gilt auch
  hier: Deutsch und Englisch gepflegt, Englisch als Rückfallebene.
- **Neues Plugin-Symbol** (`icons/icon.svg` und die vier PNG). Bewusst ohne
  Raute und ohne Herstellerzeichen – die Raute ist eine eingetragene Marke von
  Renault. Das Plugin spricht die Schnittstelle des Herstellers an, gehört ihm
  aber nicht.
- **Ballast der ZoePHP-Anwendung entfernt**, siehe unten „Aufgeräumt".

## Version 1.4.1

- Tippfehler korrigiert: MQTT-Topic heißt jetzt `ChargingStatus` (vorher
  `CargingStatus`, ohne h). **Achtung:** in der Loxone-Konfiguration muss das
  Topic entsprechend angepasst werden.
- Menü-Reiter umbenannt: **Einstellungen** (vorher „Settings"),
  **gesp. Konfiguration** (vorher „Konfiguration") und **Ladehistorie**
  (vorher „Load History")

## Version 1.4

- Konfiguration, Ladehistorie und Log **überstehen Plugin-Updates**
  (Sicherung/Wiederherstellung über pre-/postupgrade; zusätzlich dauerhafte
  Konfigurationssicherung im LoxBerry-Config-Verzeichnis)
- Neuer Reiter **Anleitung**: Einbindung in Loxone Schritt für Schritt
  (MQTT Gateway, Topics-Tabelle, Virtuelle Ausgänge, Beispiele, Stolperfallen)
- Einheitliches LoxBerry-Grün auf allen Plugin-Seiten

### Ältere Änderungen

- 1.3: Automatische Wahl des richtigen Renault-Accounts (MYRENAULT statt
  SFDC/SALES), Diagnose der im Account verknüpften VINs bei 404-Fehlern
- 1.2: Reiter Konfiguration + Log, Logging aller API-Schritte, Bugfix:
  fehlgeschlagener Login wird nicht mehr bis Mitternacht gecacht
- 1.1: Aktuelle API-Keys (ZoePHP 2026), nicht-fatale Behandlung entfallener
  API-Endpunkte (hvac-status, batteryTemperature)

## Installation

ZIP über die LoxBerry-Pluginverwaltung installieren, dann unter
**Settings** die My-Renault-Zugangsdaten, VIN und Fahrzeuggeneration eintragen.
Alles Weitere erklärt der Reiter **Anleitung** im Plugin.

## Hinweise

- Max. 1 API-Abruf pro Minute (wird automatisch eingehalten)
- Bei „There is no data for this vin and uid": Datenfreigabe im Fahrzeug
  aktivieren und prüfen, ob die My-Renault-App Live-Daten zeigt
- Die Zugangsdaten bleiben lokal auf dem LoxBerry (config.php, passwortgeschützter Bereich)

## Aufgeräumt

### Vorlagenreste, die etwas getan haben

- **`daemon/daemon`** war die wortwörtliche LoxBerry-Beispieldatei. Ihr einziger
  wirksamer Inhalt:
  `logger "This is just a sample DAEMON script from Sample Plugin"`.
  LoxBerry benennt sie bei der Installation um und führt sie bei **jedem
  Systemstart als root** aus — seither stand diese Zeile im Systemprotokoll.
  Das Plugin arbeitet über cron (`cron.03min`, `cron.10min`), nicht über einen
  Dienst. Der ganze Ordner ist entfallen.
- **`cron/crontab`** war ebenfalls unverändert aus der Vorlage und enthielt
  `0 * * * * loxberry echo "Do something funny" > /dev/null`. LoxBerry legt
  diese Datei nach `/etc/cron.d/` — dort lief stündlich ein Echo ins Nichts.
- **Drei Echo-Zeilen druckten leere Werte.** In fünf Skripten gaben sie
  `$TEMPDIR`, `$ARGV3` und `$ARGV4` aus; die Skripte weisen aber `PTEMPDIR`,
  `PDIR` und `PVERSION` zu. Im Installationsprotokoll stand deshalb seit jeher
  „Installation folder is:" ohne Wert. Die eine Zeile, die sich sinnvoll
  reparieren ließ, zeigt jetzt `$PDIR`; die beiden anderen sind entfallen.
- **Zwölf tote Vorlagenvariablen** (`PTEMPDIR`, `PVERSION`, `PSBIN`, `PBIN`)
  aus `preinstall.sh`, `preroot.sh`, `postinstall.sh` und `postroot.sh`.

### Leere Ordner aus dem Vorlagengerüst

`apt`, `bin`, `config`, `data`, `sbin` und `sudoers` waren allesamt leer.
`apt` lag zusätzlich an der falschen Stelle — LoxBerry erwartet `dpkg/apt`; da
er leer war, hat das Plugin ohnehin keine Paketabhängigkeiten.

Dazu `icons/README.txt` (Vorlagentext „Please copy your plugin icons here",
während alle Icons daneben liegen) und der tote Aufruf
`$L = LBSystem::readlanguage('language.ini');` in `index.php`: `$L` wurde nie
gelesen, und eine Datei `language.ini` gibt es hier gar nicht — nur
`language_de.ini` und `language_en.ini`, die `rn_t()` unmittelbar liest.

### Erbe der ursprünglichen Web-App — jetzt abgelöst

`webfrontend/htmlauth/` enthielt Dateien aus der eigenständigen
ZoePHP-Anwendung: `lng/AT.php`, `DE.php`, `EN.php`, `IT.php`, `SE.php`,
`stylesheet.css`, `zoephp.webmanifest`, `icon-192x192.png`, `icon-512x512.png`
und `favicon.ico`.

Diese Dateien waren **in Gebrauch**, nicht einfach vergessen: `abruf.php` und
`history.php` bauten daraus eigene HTML-Seiten mitsamt eigener Sprachdateien.
Löschen allein hätte die beiden Seiten kaputtgemacht — deshalb erst der Umbau,
dann das Aufräumen:

- **`abruf.php`** wird vom cron aufgerufen und hat gar keinen menschlichen
  Leser. Die HTML-Ausgabe (55 Zeilen mit Kopf, Stylesheet-Verweis und
  Manifest-Einbindung) ist durch einen kurzen Klartext-Hinweis ersetzt, der auf
  die Plugin-Oberfläche verweist. Die drei Benachrichtigungstexte, die
  tatsächlich beim Anwender ankommen, kommen jetzt aus `[MELDUNG]`.
- **`history.php`** liefert eine Datenauswertung. Sie sendet jetzt
  `Content-Type: text/plain` und gibt ihre neun Textbausteine über `rn_t()`
  aus; `LBWeb::lbheader()`/`lbfooter()` sind entfallen, weil die Seite kein
  HTML mehr ist. Die Darstellung als Diagramm gehört in die Oberfläche und ist
  dort auch vorhanden.

Damit hing an `lng/`, `stylesheet.css`, dem Webmanifest und den beiden
App-Icons nichts mehr; sie sind entfallen. `favicon.ico` war schon vorher von
keiner Seite eingebunden und ist mit dem Rest gegangen — für die Oberfläche
liefert LoxBerry sein eigenes.

Nachprüfbar: `templates/lang/language_de.ini` und `language_en.ini` haben je
223 Schlüssel, deckungsgleich; jeder davon wird benutzt, und jeder benutzte
Schlüssel ist vorhanden. In `webfrontend/` kommt `$lng` nicht mehr vor.

