# LoxBerry-Plugin: Renault NG

Version 2.1.34 · LoxBerry ab 3.0 · PHP 7.4 und 8.x · an keiner Anlage gemessen

Verbindet Renault-Elektrofahrzeuge (Zoe PH1/PH2, Twingo Electric u. a.) mit dem
Loxone Miniserver – über den LoxBerry. Batteriestand, Reichweite, Ladestatus,
Kilometerstand, Position u. v. m. werden regelmäßig abgerufen und per **MQTT**
(LoxBerry MQTT Gateway) bereitgestellt. Vorklimatisierung und Ladesteuerung
lassen sich aus Loxone heraus auslösen.

Fortführung des [Renault-Plugins von **PeterB**](https://wiki.loxberry.de/plugins/renault_my_ze/start),
das seinerseits auf [ZoePHP](https://github.com/db-EV/ZoePHP) von db-EV
aufbaut. Apache-Lizenz 2.0; die Liste der Änderungen steht in `NOTICE`.

---

## Neu in 2.1.34

- **Verhalten geändert – Einstellungen sichern und zurückspielen nach der gemeinsamen Vorlage:** Die Knöpfe im Reiter *Einstellungen* arbeiten jetzt mit demselben Sicherungsmodul wie die übrigen Plugins dieser Reihe. Für den Anwender ändert sich:
  - Eine Datei eines anderen Plugins und eine Datei ohne Kennzeichen `_plugin` werden als Ganzes abgewiesen; es ändert sich dann nichts. Bis 2.1.33 wurden beide gelesen wie eine eigene Sicherung. Sicherungen dieses Plugins von 2.1.6 bis 2.1.33 tragen das Kennzeichen `renault_ng` und werden weiter angenommen. Sicherungen von 2.1.4 und 2.1.5 hatten gar keinen Kopf; sie lassen sich von einer fremden Datei nicht sicher unterscheiden und werden seit dieser Fassung abgewiesen (bis 2.1.33 wurden sie angenommen).
  - Eine Einstellung, die diese Fassung nicht kennt, wird nicht übernommen und mit ihrem Namen gemeldet, nie mit ihrem Wert; die übrigen Einstellungen werden zurückgespielt. Bis 2.1.33 wies ein unbekannter Name die ganze Datei ab.
  - Ein leerer Wert in der Sicherung überschreibt keinen bestehenden; der bisherige Wert bleibt, und die Meldung nennt den Namen. Bis 2.1.33 galt das nur für das Aktionstoken: ein leeres Kennwort überschrieb das hinterlegte, und ein leeres Feld mit fester Form (etwa das Land) wies die ganze Datei ab.
- Unverändert: Eine Datei mit einem unzulässigen Wert ändert gar nichts, und alle Beanstandungen werden genannt. Aktionstoken, Benutzername und Kennwort des Renault-Kontos gehören in die Datei, die Sprechtoken nicht; eine Datei, die ein Sprechtoken trägt, wird abgewiesen. Einstellungen, die eine ältere Sicherung noch nicht kennt (Sprachausgabe, die Einstellungen von 2.1.20, die weiteren Ausgabeziele), behalten ihren Wert, und die Meldung nennt sie. Nach dem Zurückspielen zieht die Zweitschrift mit, Zwischenspeicher und Anmeldung werden verworfen, wenn sich Konto oder Fahrzeug geändert haben, die Befehlsbrücke folgt dem Haken, und die Meldung sagt, wann sich der Abruf neu anmeldet.
- **Berichtigt – virtuelle Eingänge für das MQTT-Gateway:** In der Anleitung zur Einbindung in Loxone stand, das MQTT-Gateway lege die Eingänge beim ersten Wert selbst an und ein verschönerter Titel ergebe einen zweiten Eingang. Gemessen ist beides nicht. Jetzt steht dort: Die Eingänge legt man in Loxone Config mit genau dem Titel aus der Tabelle an; das MQTT-Gateway ordnet nur über den Namen zu.
- **Verhalten geändert – Zurückspielen nur mit Haken:** Zwischen dem Feld für die Sicherungsdatei und dem Knopf „Einstellungen zurückspielen“ steht ein Haken „Ja, die geltenden Einstellungen durch die Sicherung ersetzen“, ab Werk aus. Ohne ihn wird die Datei nicht gelesen und nichts geändert, und die Meldung sagt es. Haken, Text und die Prüfung der hochgeladenen Datei (über das Formular gekommen, höchstens 256 KB) kommen aus dem gemeinsamen Sicherungsmodul 1.1.0 und sind in allen Plugins dieser Reihe gleich. Die Grenze für die Datei lag bis 2.1.33 bei 64 KB.
- **Gemeinsames Sicherungsmodul 1.2.0:** Das Modul kann jetzt, was einzelne Plugins dieser Reihe bisher selbst darum herum gebaut haben (Entscheidung „Einheitlichkeit“). Die Prüfung „die Datei trägt ein Sprechtoken“ liest den Block der Datei jetzt über das Modul statt über eine eigene Umgehung; am Verhalten ändert sich nichts.
- **Gemeinsames Sicherungsmodul 1.2.1:** Ein Vermerk, der nie aus einer Sicherungsdatei kommt (etwa, dass ein Sprechtoken aus einem Nachbar-Plugin übernommen wurde), behält beim Zurückspielen seinen geltenden Wert. An diesem Plugin ändert sich dadurch nichts; es setzte den Vermerk schon bisher selbst wieder ein.
- **Gemeinsames Sicherungsmodul 1.2.2:** Fehlt eine Einstellung in der Sicherung und setzt das Plugin ihren Wert nach einer eigenen Übergangsregel (etwa aus einer verwandten Einstellung derselben Sicherung), bleibt dieser Wert beim Zurückspielen, und die Meldung nennt ihn als nach einer Übergangsregel abgeleitet – nicht als Werkseinstellung und nicht als bisherigen Wert. An diesem Plugin ändert sich dadurch nichts.
- Gemessen mit einem Prüfstand auf dem Arbeitsrechner unter PHP 7.4 und 8.4, der über einen Webserver die echten Knöpfe drückt, mit den Sicherungen aller 30 Vorfassungen von 2.1.4 bis 2.1.33; am Gerät nicht gemessen.

## Neu in 2.1.33

- **Konfiguration wird vervollständigt:** Fehlen in `config.php` Einstellungen, die eine neuere Fassung mitbringt, trägt der Minutentakt (`abruf.php takt` aus `cron.01min`) sie jetzt einmal mit ihrer Vorgabe in die Datei ein. Bisher ergänzte das Plugin fehlende Einstellungen nur beim Lesen; in die Datei kamen sie erst beim nächsten Speichern im Formular. Das Protokoll nennt die ergänzten Namen in einer Zeile, nie die Werte.
- Geschrieben werden die Werte, die in der Datei stehen, plus die fehlenden Vorgaben, auf demselben Weg wie beim Speichern (Rechte 0600, Prüfung der Datei, Zweitschrift). Einstellungen, die das Plugin nicht kennt, bleiben stehen; geschrieben wird nur, wenn etwas fehlt. Eine fehlende Einstellung galt beim Lesen schon bisher mit ihrer Vorgabe, am Verhalten ändert sich deshalb nichts – auch ein fehlender Haken für den HTTP-Endpunkt wird mit „aus“ eingetragen, so wie er bisher gelesen wurde.
- Während einer Aktualisierung geschieht nichts: Der Übergang in `postupgrade.sh`, der einer bestehenden Anlage den HTTP-Endpunkt offen lässt, läuft vorher. Nicht angefasst werden eine fehlende, leere oder beschädigte Datei, eine Datei ohne Aktionstoken, eine Datei ohne das abschließende `?>` (sie kann abgeschnitten sein) und eine Datei mit einer Zuweisung, die das Plugin nicht lesen kann; im letzten Fall steht höchstens einmal je Stunde eine Warnung im Protokoll. Das Aktionstoken selbst wird nie eingetragen.
- Gemessen ist das im Prüfstand unter PHP 7.4 und 8.4 auf dem Arbeitsrechner. Nicht gemessen ist es an einem LoxBerry und nicht an einem vollständigen Lauf von `abruf.php takt`; die Aufrufstelle ist nur im Quelltext geprüft.
- **`endpunkt_ein` bleibt außen vor:** Fehlt die Einstellung, trägt das Vervollständigen sie nicht ein. Den Übergang einer bestehenden Anlage setzt weiter allein die Aktualisierung (`bin/rn_bestand.php`); ein hier festgeschriebenes „N“ könnte eine spätere Aktualisierung nicht mehr berichtigen.
- **Berichtigt: Zweitschrift beim Speichern.** Endete `config.php` nicht mit `?>` und fehlten ihr Einstellungen, die die Zweitschrift trägt, war die Datei womöglich nach einer Zuweisung abgeschnitten. Das Lesen hielt sie für heil, und das nächste Speichern überschrieb die Zweitschrift mit den Vorgaben für den verlorenen Rest. Jetzt bleibt die Zweitschrift in diesem Fall unberührt, und das Protokoll nennt die betroffenen Einstellungen. Geprüft auf dem Arbeitsrechner unter PHP 7.4 und 8.4 (vier Fälle; die Vorfassung ist im Fall „abgeschnitten“ rot).

## Neu in 2.1.32

- Gemeinsames Sprachmodul 1.4.3: Als LoxBerry-Wurzel aus `LBHOMEDIR` gilt für die Sprachausgabe nur noch ein Ordner mit `config/plugins`, `data/plugins` und `config/system/general.json`; bis 1.4.2 genügte `config/system`, und ein Prüfstand oder Rest mit diesem Ordner wurde wie ein LoxBerry gelesen. Auf einem LoxBerry ändert sich nichts.

## Neu in 2.1.31

- Selbsttest: Der Prüffall für die Zahl der Vorgabe-Einstellungen kennt jetzt die vier Einstellungen für Dashboard-Tafeln und HomePod. Seit 2.1.26 meldete er deshalb einen Fehlschlag, obwohl die Einstellungen richtig waren. Am Verhalten des Plugins ändert sich nichts.

## Neu in 2.1.30

- Gemeinsames Sprachmodul 1.4.2: Wird eine Ansage bewusst nicht gesprochen (gerade eben schon gesagt, gesperrt oder Ruhezeit), steht das im Protokoll jetzt so – und nicht mehr als Fehler.

## Neu in 2.1.29

- Heimzone: Unter dem Feld „Breitengrad des Hauses“ steht ein Link zu einer Karte (mapatlas.eu). Dort die Adresse eingeben und die Zahlen unter „Dezimalgrad“ kopieren. Beide Zahlen dürfen auch zusammen ins Feld „Breitengrad des Hauses“ kopiert werden („50.123, 8.456“, getrennt durch Komma, Semikolon, Schrägstrich oder Leerzeichen, Dezimalpunkt); das Plugin teilt sie beim Speichern auf, wenn das Feld Längengrad leer ist oder schon die zweite Zahl trägt. Eine einzelne Zahl mit Komma („51,3“) wird nicht aufgeteilt.

## Neu in 2.1.28

- **Update:** Scheitert die Sicherung oder die Marke, bricht das Update ab und die bisherige Fassung bleibt.
- **Sprechtoken:** Ein von Hand eingetragenes Alexa- oder Google-Token wird nicht mehr vom Token des Nachbar-Plugins überschrieben.
- **Formular:** Tafel- und HomePod-Felder gehören jetzt zu den Feldern, die eine Beanstandung im Formular mitführt und markiert. Ein beanstandeter Tafel- oder HomePod-Name bleibt zur Korrektur im Feld stehen.
- **HomePod:** Schalter und Gerätename aus dem Formular werden jetzt gespeichert. Bisher gingen sie beim Speichern verloren.

## Neu in 2.1.27

- Vor einem Download wird jeder offene Ausgabepuffer geleert: PHP-Warnungen oder Reste landen nicht mehr in der heruntergeladenen Datei.

## Neu in 2.1.26

* **Ansagen auch auf den offenen Dashboard-Tafeln, ab Werk an.** Mit Dashboard ab 0.9.40 und einer offenen Tafel kommt jede Ansage dieses Plugins auch dort an: Gong, Sprache und der Text groß. Ohne Dashboard oder ohne offene Tafel wird das Ziel ohne Fehler übergangen. Ausschalten: Reiter *Einstellungen*, Haken „Auch auf offenen Dashboard-Tafeln ansagen“; ein gespeichertes Aus bleibt. Es gelten dieselben Regeln wie für die Lautsprecher.
* **Gemeinsame Sprachausgabe 1.4.0.** Einstellungen und ein gespeichertes Aus bleiben, wie sie sind; am Gerät ist nichts einzurichten.

## Neu in 2.1.25

Diese Fassung baut auf 2.1.24 auf, die als Probe am Gerät lief, aber nie veröffentlicht wurde; **sie bringt also auch alles aus 2.1.24 mit** (Sprachansagen ab Werk an, Abschnitt darunter).

* **Sammelthemen, ab Werk an:** `haus/fahrzeug/<name>/soc`, `reichweite_km`, `kabel`, `laedt`, `zuhause` (retained, ohne Aussage `-`) und `zaehler` (je Minute, nie retained), damit andere Plugins und Loxone ein Fahrzeug lesen können, gleich welcher Marke. `<name>` kommt aus dem Fahrzeugnamen. Kein Standort, keine Fahrgestellnummer. Die Zustände kommen aus dem Abruf, der Zähler aus dem Minutentakt. **Liefert Renault keine Fahrzeugdaten (`battery-status` 404, zum Beispiel ohne Online-Abo), steht überall der Strich, nie ein alter Wert;** ein Stand, der älter als 6 Stunden ist, ebenso. Nicht eingerichtet: nichts, auch kein Zähler. Ausschalten: Reiter *MQTT*; das räumt die Themen ab, die Deinstallation ebenfalls. Sendet unter demselben Namen noch eine andere Linie (fremder Zähler), warnt der Reiter *Test*, und die Themen dort bleiben stehen.
* **Kabel vergessen:** Steht das Auto zu Hause ohne Kabel und liegt der Akku unter 30 %, meldet es sich ab 20 Uhr, am Morgen nach der Nachtruhe oder vor einer Abfahrt über Ansage und (ab Werk aus) SignalBot. In der Nachtruhe wartet es. Braucht die Heimzone. Unbekannte Werte warnen nie, ohne Fahrzeugdaten also nie.
* **Vorklimatisierung mit Mindest-Akku:** Unter 20 % (einstellbar) unterbleibt sie, ohne einen Versuch zu verbrauchen; `Vorklima` zeigt 5. Wahlweise nur mit gestecktem Kabel (ab Werk aus, Stand 6). Ist der Stand unbekannt, klimatisiert das Plugin wie bisher: Die Vorklimatisierung geht ohne Online-Abo also weiter, wenn Renault den Befehl annimmt.

Geprüft mit echtem SDK im Minutenlauf, Fahrzeug- und Broker-Attrappe; an einem Fahrzeug und am Gerät nicht gemessen.

## Neu in 2.1.24

**Ab dieser Fassung sind die Sprachansagen ab Werk an, auch nach einem Update, wenn Sie die Ausgabeart nie gespeichert haben.** Eine gespeicherte Einstellung bleibt, wie sie ist, auch „aus“. **Ausschalten:** Reiter *Einstellungen*, Abschnitt Sprachausgabe, Ausgabeart **„aus“** wählen und speichern; das bleibt auch nach jedem weiteren Update aus.

* **Was angesagt wird:** Laden fertig, Laden abgebrochen, Akku niedrig und Ausfall des Abrufs, je Anlass mit eigenem Haken. Bisher galt „höchstens eine je Stunde“, jetzt einstellbar, ab Werk 30 Minuten.
* **Wohin:** ab Werk zugleich Loxone Music Server (braucht seine Adresse), Google-Lautsprecher über Chromecast4lox (braucht dort ein Standardgerät oder hier den Namen) und Amazon Echo über Alexa-NG. Ein Ziel, das nicht bereitsteht, wird ohne Fehler übergangen; der Reiter *Test* sagt, was ihm fehlt. Das Sprechtoken der beiden Plugins müssen Sie nicht abschreiben: Es wird übernommen, in der Konfiguration gespeichert und nachgezogen; ein von Hand eingetragenes bleibt.
* **Wann nicht:** Nachtruhe 22 bis 7 Uhr (einstellbar, wird nicht nachgeholt; Dringendes spricht trotzdem), dieselbe Ansage höchstens einmal in 30 Minuten (einstellbar) und solange Loxone das retained MQTT-Thema `haus/ansage/ruhe` auf 1 setzt (das gilt auch für Dringendes). Die Testansage im Reiter *Test* spricht immer.
* **Der Minutenlauf wartet nicht auf Lautsprecher:** Gesprochen wird in einem eigenen Vorgang.
* **Berichtigt:** Ein Hilfsprogramm, das nicht mehr reagiert, wird in der SignalBot-Probe und beim Melden nach einer festen Frist hart beendet. Der Leser der Haus-Themen hat jetzt auch beim Lesen eines Pakets eine feste Frist.


## Neu in 2.1.23

Eine zweite Liste aus derselben Durchsicht, wieder jede Aussage nachgemessen; was stimmt, ist behoben. Renault-seitig
weiter nur mit Attrappen geprüft – das Plugin bleibt **BETA**.

Aus der Einrichtung am Gerät kam dazu: Wies das Speichern ein anderes Feld ab, standen Breiten- und Längengrad danach
leer da, und ein eingetipptes Sprechtoken war ohne Hinweis weg. Jetzt bleiben die Koordinaten stehen; bei Kennwort,
Schlüssel und Sprechtoken sagt die Meldung, dass sie neu einzutragen sind, und das Feld ist markiert. Jede Abweisung
steht mit den Feldnamen im Protokoll. Gerätenamen mit Apostroph werden angenommen. Die Felder heißen jetzt
„Breitengrad des Hauses“ und „Längengrad des Hauses“.

Antwortet Renault für ein Fahrzeug wiederholt mit HTTP 404 (`err.func.wired.notFound`, häufig: Online-Abo ausgelaufen,
belegt ist das nicht), führt das Plugin den Zustand „keine Fahrzeugdaten“: Nach drei gleichen Antworten über mindestens
10 Minuten steht eine WARN-Zeile im Protokoll, danach höchstens eine Zeile je Tag. Abgefragt wird alle 30 Minuten statt
alle 5, die Ladehistorie ruht, und sobald wieder Daten kommen, schaltet es mit einer Zeile von selbst zurück. Kopf und
Reiter Test zeigen einen Hinweis, der Healthcheck ebenso. `ok` bleibt 0, denn es kommen ja keine Daten. Mail, SignalBot
und Ansage gibt es dafür nicht. Andere Fehler (401, 5xx, Netz, falsche Fahrgestellnummer) melden sich unverändert.

* **Hinweis-Befehl:** Der Text an den eingetragenen Befehl behält Umlaute und andere Sonderzeichen. Bisher fielen sie unter
  PHP 7.4 ohne jede Meldung weg.
* **Leser der Haus-Themen:** Reagiert `mosquitto_sub` nicht auf das Beenden, wird es nach höchstens 2 Sekunden hart
  beendet. Bisher konnte der Leser daran stehen bleiben und hielt so lange seine Sperre. Bei MGiSmart ist das am Gerät
  passiert, hier nur mit Attrappe nachgestellt.
* **Endpunkt:** Eine Variable in `index.php` ist umbenannt, damit das LoxBerry-SDK sie nicht überschreiben kann. Mit
  dem Sprachmodul dieser Fassung trat das nicht auf; nachgestellt brach der Aufruf ab.
* **`?selftest=0` und `?selftest=`** lösen keinen Selbsttest mehr aus. `?selftest=0&aktion=abruf&token=…` ruft jetzt
  wirklich ab; `?selftest=1` antwortet wie bisher.
* **Reiter Test, Zeile Endpunkt:** Ist die Oberfläche über https offen, zeigte die Zeile ein Kreuz, obwohl der
  Endpunkt heil ist – gefragt wurde per http auf dem SSL-Port. Jetzt gilt dort der eingetragene Webport.
* **Cron:** Ist `cron.err` nicht schreibbar, laufen die Abrufe trotzdem. Bisher brach schon die Umleitung den Aufruf
  ab, bevor php startete, ohne jede Spur. `cron.01min` meldet das und eine nicht zu öffnende Sperrdatei einmal je
  Stunde im Protokoll.
* **„Vorklimatisierung gescheitert“ an SignalBot** geht jetzt im nächsten Takt hinaus, wenn der Broker im Moment des
  Aufgebens nicht erreichbar war. Bisher wartete die Meldung auf den nächsten Wechsel von `Vorklima`, meist bis nach
  der Abfahrt.
* **Deinstallation:** Eine überschrittene Zeitschranke beim Leeren der MQTT-Themen gibt eine `<WARNING>`; bisher endete
  das Skript still mit 0 und nannte die Themen geleert. Der Aufruf von Hand ohne Argumente (mit `LBHOMEDIR`) räumt
  jetzt auf, statt sich für ein anderes Plugin zu halten.
* **Befehlsbrücke:** `bin/befehle_zuordnung.php` lädt `rn_lib.php` nur noch aus dem eigenen Plugin.

Nachgemessen und nicht geändert: `Vorklima` ist nicht retained und kommt bei jedem gelungenen Abruf, ein abgeschalteter
Haken hinterlässt also nichts im Broker; `befehlsbruecke.sh stop` findet die Wurzel beim Deinstallieren selbst.

## Neu in 2.1.22

Ein zweiter Programmierer hat das Plugin durchgesehen. Jede seiner Aussagen ist am Code nachgemessen; die
zutreffenden sind behoben. Renault-seitig weiter nur mit Attrappen geprüft (im Haus kein Konto) – das Plugin bleibt
**BETA**.

* **Ein Update verliert die Ladehistorie nicht mehr still.** Lässt sich die Aufzeichnung beim Update nicht sichern
  (etwa bei voller Karte), bricht das Update ab, und die bisherige Fassung bleibt installiert. Bisher lief es mit
  einer Warnung weiter, und die Aufzeichnung war danach fort. Ein Rettungsordner aus einem früheren Update wird erst
  ersetzt, wenn der neue vollständig ist.
* **Die Ladekosten überstehen ein Update.** Die Liste der Ladungen, eine gerade laufende Ladung und die Sicherung
  `database*.csv.vor_2120` gehen beim Update mit. Bisher waren sie danach fort, und das einmalige Zusammenfassen
  gleicher Zeilen lief bei jedem Update erneut (das Update auf 2.1.22 legt die Sicherung deshalb noch einmal an).
* **`LadungKosten`, `LadungEnergie` und `LadungPreis`** kommen jetzt bei jedem gelungenen Abruf – die letzte Ladung,
  ohne Ladung je -1 –, nicht nur am Ende einer Ladung. So erscheinen die drei Datenpunkte im Gateway V2 und lassen
  sich anhaken; der Selbsttest nennt sie nicht mehr dauerhaft als fehlend.
* **Einstellungen zurückspielen** startet oder hält die Befehlsbrücke sofort passend zum Haken „Befehle über MQTT
  annehmen“ (bisher erst der Minutentakt). Die Meldung danach sagt nicht mehr, das Plugin habe keinen Dienst.
* **Reiter Test:** Die Gateway-Fassung wird auch ohne die Umgebungsvariable `LBHOMEDIR` erkannt; „Importdatei prüfen“
  prüft jetzt auch die Vorlage der Befehlsbrücke.
* **Installationsprotokoll:** Die Zeilen „Plugin CGI folder“ und „SBIN folder“ nannten `/renault_ng`; jetzt steht dort
  der Ordner `htmlauth`.
* **Protokoll:** „Befehlsbruecke:“ steht nur noch einmal vor jeder Zeile der Befehlsbrücke (bisher doppelt).

## Neu in 2.1.21

Bilder der Einbindung erneuert: Die Seitenbilder zeigen jetzt die Musterprojekt-Seiten in der Hausform (MQTT-Gateway V2 und Befehlsbrücke statt der früheren HTTP-Vorlagen). Sonst keine Änderung gegen 2.1.20.

## Neu in 2.1.20

Hausform wie MGiSmart 1.1.28: MQTT-Gateway V2, Befehlsbrücke, Haus-Themen. Renault-seitig nur mit Attrappen
geprüft (im Haus kein Konto) – das Plugin bleibt **BETA**.

* **Wer die HTTP-Vorlage nutzt, ändert nichts** – der Endpunkt bleibt beim Update offen (Haken
  „Befehle über den bisherigen HTTP-Endpunkt annehmen“); eine **Neuinstallation hat ihn aus**. Ein Befehl geht
  sofort hinaus; der Abruf danach nur, wenn kein anderer läuft (sonst die Zeile `ABRUF=SKIP`).
* **Gateway V2:** Datenpunkte anhaken (Tabelle im Reiter MQTT, `ok`, `status/zaehler` und `Renault/status/befehle`
  als Noncached), Brückenvorlage importieren. Zahlthemen ohne Wert kommen mit Fehlwert (-1, Temperaturen -99,
  GPS -999); `ChargingStatus` ohne Wert wird nicht gesendet (bei Renault heißt -1 Ladefehler). Das Abo
  `Renault/#` bleibt als Rückfall für Gateway V1. Ein Fahrzeug darf nicht mehr `status` oder `befehl` heißen.
* **Befehle über MQTT** (Befehlsbrücke 1.0.2, ab Werk aus): `Renault/befehl/laden 1/0`, `klima_an 1`,
  `klima_aus 1`, `ladeplan 1/0`, `auffrischen 1`, `aussentemp <°C>`; Fahrzeug 2–4 unter `Renault/befehl/<n>/…`.
  Quittung unter `…/quittung`. UDP 11884 ist im Heimnetz ohne Anmeldung erreichbar – Schutz sind die Haken
  „Befehle über MQTT annehmen“ und (für alles Schaltende) „Steuerung“.
* **Anmeldefehler:** im Cron gehen je Fahrzeug `ok = 0` und das Lebenszeichen hinaus (bis 2.1.19 gar nichts).
* **Minutentakt** `cron.01min` (leicht, kein Abruf): Wächter der Brücke, abgekoppelter Leser der Haus-Themen.
* **Vorklimatisierung zur Abfahrt** aus `haus/abfahrt` (ab Werk aus; nur mit Steuerung): höchstens zwei
  „Klima ein“ je Abfahrt (Senden – Abruf – Senden), wahlweise nur bei Anwesenheit (`haus/anwesenheit/jemand`)
  und nur bei Kälte oder Hitze (Außentemperatur aus Loxone über `Renault/befehl/aussentemp`, sonst die des
  Fahrzeugs). Schweigt das Haus-Thema, klimatisiert das Plugin nicht. Nur eine Quelle nutzen: Schaltuhr in Loxone
  **oder** dieser Haken.
* **Heimzone** (ab Werk aus, Generation 2): `Zuhause` 1/0/-1 und `Entfernung` in km. Die GPS-Zeit gilt, solange
  sie höchstens 60 Minuten älter ist als die letzte Fahrzeugantwort. Die Koordinaten stehen nur in `config.php`.
* **Ankunft** (ab Werk aus): BLE-Tag oder Person live 0 → 1 fragt das Fahrzeug einmal ab (höchstens je 30 min).
* **Ladekosten** je Ladung aus `haus/strompreis` (ab Werk aus). **Rechenweg:** Renault liefert die Ladeleistung
  nicht im Minutentakt. Die Energie je Abschnitt zwischen zwei Abrufen kommt aus der vom Fahrzeug gemeldeten
  Energie im Akku, sonst aus Akkustand-Differenz × eingetragener Kapazität; jede Minute trägt den Preis, den das
  Plugin in ihr mitschreibt (Endpreis ohne Grundpreis). Gerechnet wird die in den Akku geladene Energie, ohne
  Ladeverluste. Kosten -1, wenn die Energie nicht bestimmbar ist, der Preis für mehr als 10 Prozent der Energie
  fehlt oder die Ladung nicht zu Hause begann – nie geschätzt. Die Minuten zwischen der Fahrzeugzeit der ersten
  Meldung und dem Abruf, der die Ladung erkennt, nimmt das Plugin aus der Preisvorschau (`haus/strompreis/vorschau`),
  nie aus dem aktuellen Preis. MQTT `LadungKosten`, `LadungEnergie`,
  `LadungPreis` (retained), Tabelle im Reiter Ladehistorie.
* **Meldungen an SignalBot** (ab Werk aus) über dessen Brücke; das Ergebnis von `mail()` steht im Protokoll.
* **Healthcheck** auf der LoxBerry-Systemseite (nur lesend).
* **Behoben:** Kontowechsel verwirft die Anmeldung, nach 401/403 wird das Konto höchstens einmal je Stunde neu
  ermittelt; Speichern löscht die Merker nur noch bei geänderter VIN/Phase/Zugangsdaten, das Update rettet
  `session*`; die Aufzeichnung schreibt nur bei einer Änderung – **das Update fasst alte gleiche Zeilen einmal
  zusammen**, die alte Datei liegt als `database*.csv.vor_2120` (0600) daneben; die Sicherung beim Update ist
  unteilbar (ohne heile Zweitschrift bricht das Update ab, statt die Einstellungen zu verlieren); die
  Deinstallation folgt keinem Verweis und tut für fremde Plugins nichts; ABRP nur für Fahrzeug 1; die Ladehistorie
  meldet Serverfehler als Warnung; Fehler des Cron stehen in `cron.err`; der Reiter Test lädt neu; die Sperre
  liegt unter `log/plugins/<ordner>/abruf.lock`; Sprachausgabe 1.2.1 mit Sonos4Lox und SonoX. Die Einstellung trägt
  dafür den neuen Schlüssel `tts_ziele` (leer = keine weiteren Ziele).
* **Mehrere Lautsprecher gleichzeitig (Sprachmodul 1.2.1):** Unter der Ausgabeart (jetzt „Ziel 1“) nimmt die Tabelle
  *Weitere Ausgabeziele (gleichzeitig)* bis zu fünf weitere Ziele auf – etwa Google-Lautsprecher (Chromecast 4 Lox NG)
  in einem Raum und Amazon Echo (Alexa-NG) in einem anderen neben dem Loxone Music Server. Die Ansage geht an alle
  eingeschalteten Ziele zugleich (eine Ansage dauert so lange wie das langsamste Ziel, höchstens 10 s, mit Sonos 30 s);
  scheitert eines, sprechen die anderen. Protokoll und Reiter Test nennen jedes Ziel, im Reiter Test gibt es je Ziel
  eine Testansage. Die bestehende Einstellung ist unverändert Ziel 1; ohne weitere Ziele verhält sich alles wie in
  2.1.19. Antwort an Loxone, MQTT-Themen und Brückenbefehl `ansage` bleiben, wie sie sind.
* **Vor einem Zurückgehen** auf 2.1.19 oder älter die weiteren Ziele entfernen: ältere Fassungen kennen die Liste nicht
  und fielen beim Laden auf die Werkseinstellung der Sprachausgabe zurück.
* **Reiter Test:** *Testansage* spricht wie bisher an alle Ziele; die Knöpfe *Testansage an Ziel n* daneben sprechen
  den Testsatz nur an diesem Ziel.
* Das Plugin regelt das Laden nicht nach Preis oder Überschuss; dafür sind Loxone (Wallbox-Regelung) und die Werte
  dieses Plugins da.

## Neu in 2.1.19

Einbindung in Loxone in Vollausstattung: drei Seiten aus dem gemeinsamen Musterprojekt mit drei Bildern und
Schritt-für-Schritt-Anleitung; Befehlstitel der Importdatei fest deutsch.

* **Reiter „Einbindung in Loxone“, Schritt 6:** Statt der bisherigen Liste mit 16 Zeilen stehen dort die drei
  Seiten des [LoxBerry-Plugins Musterprojekts](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt),
  je mit Kasten „So übernehmen Sie diese Seite“, Tabelle, Hinweisen und Bild: „Renault NG“ (32 Zeilen: läuft das
  Plugin, Ladestand, Reichweite, lädt es, steckt das Kabel; Meldungen gestört, Ladestand knapp, Ladung beendet und
  Ladestörung), „Renault NG Laden und Klima“ (35 Zeilen: Ladeleistung, Restzeit, Ladeziel aus Loxone mit
  automatischem Anhalten – ab Werk aus –, Laden starten und anhalten, Ladeplan als Schalter, Vorklimatisierung per
  Schaltuhr nur bei Anwesenheit, Klima jetzt und aus) und „Renault NG Fahrzeug“ (21 Zeilen: Kilometerstand und
  heute gefahrene Kilometer, Position, Generation und Batterietemperatur, letzter Ladevorgang, Jetzt abrufen).
* **Namen der Eingänge:** Die Tabellen nennen für die Werte über das MQTT-Gateway den Namen, den das Gateway
  vergibt (`Renault_<Fahrzeugname>_<Thema>`, aus dem eingestellten Namen von Fahrzeug 1 gerechnet) – der Titel
  des virtuellen Eingangs muss genau so lauten, der lesbare Name kommt in die Beschreibung.
* **Wer die alte Liste gebaut hat:** Ihr Treppenlichtschalter an `phpCall` stand im Normalbetrieb auf Ein und
  meldete beim Anlauf statt beim Ausfall; die Schwelle 20 meldete ohne Verzögerung, auch beim Laden. Die neue
  Seite „Renault NG“ erkennt den Ausfall über das Lebenszeichen `status/zaehler` (Analogwertvalidierung, 900 s)
  und `ok`, mit Einschaltverzögerungen vor jeder Meldung. Schritt 5 erklärt das jetzt so.
* **Befehlstitel fest deutsch:** Die Importdatei für die Befehle benennt ihre sieben Befehle jetzt auch bei
  englischer Oberfläche deutsch („Vorklimatisierung starten“ …), wie Liste, Bild und Musterprojekt; die
  Kommentare bleiben übersetzt. Bei deutscher Oberfläche ist die Datei unverändert; bestehende Projekte behalten
  ihre Ausgänge.
* **Texte berichtigt:** Bedeutung von `ChargingStatus` (1 = lädt, unter 0 = Störung), Ausfallerkennung in
  Schritt 5 und in der Hilfe; die Schlüsselzahl im Abschnitt „Nachprüfbar“ (stand noch auf 287).
* Kein Verhalten geändert: Abruf, MQTT, Endpunkt und Befehle wie bisher. Gerendert unter PHP 7.4, 8.4 und 8.5,
  nicht am Gerät angesehen.

## Neu in 2.1.18

Baustein-Liste in der Schreibweise des Leitungswerkzeugs, gemeinsame Sprachausgabe 1.1.2.

* **Baustein-Liste (Reiter Einbindung in Loxone, Schritt 6):** Die Spalte „Eingänge verbinden mit“
  nennt die Quellen in fester Form: `#1` statt „Eingang ← #1“, am Statusbaustein `V1 = #3`, am ODER
  `I1 = #8, I2 = #9`; die MQTT-Eingänge tragen ihr Thema als Bemerkung (`– (MQTT BattSOC)`). Was von
  der Visualisierung kommt und die Flankenerkennung vor #9, bleibt in Worten. Gleiche Bausteine,
  gleiche Verbindungen.
* **Sprachausgabe 1.1.2:** gemeinsames Modul und Abschnitt [ANSAGE] mit 157 Sätzen. Den Satz zu einem
  unbekannten Eintrag im Block der Sprachausgabe bringt jetzt das Modul mit; die eigene Umlenkung ist
  gestrichen (gleicher Wortlaut). Dazu aus dem Modul: Zeichenzahl bei kaputtem UTF-8 in Zeichen, die
  Meldung „Port abgewiesen“ nennt das Feld nicht mehr doppelt.
* Gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Neu in 2.1.17

Kopf wie alle Hausplugins: Statusübersicht über den Reitern, Zusammenfassung oben im ersten Reiter.

* **Statusübersicht über den Reitern** als Tabelle: Abruf (ohne Dienst, Cron-Takt), ob Zugangsdaten
  zum My-Renault-Konto eingetragen sind, letzter erfolgreicher Abruf je Fahrzeug, MQTT-Broker und ob
  Schaltbefehle aus Loxone erlaubt sind. Nur Werte, die die Seite schon kennt; keine neue Abfrage.
* **Zusammenfassung** des Plugins in einem grünen Kasten oben im Reiter Einstellungen.
* Nur Oberfläche; gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

## Version 2.1.16 — Ansagen über die gemeinsame Sprachausgabe

Gemessen unter PHP 7.4 und 8.5 gegen Attrappen (Music Server, Alexa-NG); nicht am Gerät, nicht an einem
echten Lautsprecher, nicht mit einem echten Fahrzeug.

* **Neu: der Abruf sagt einzelne Ereignisse an (ab Werk aus).** Reiter Einstellungen, Abschnitt
  „Sprachausgabe“: Loxone Music Server, MusicServer4Home, eine eigene Adressvorlage, Alexa-NG oder
  Google-Lautsprecher (Chromecast 4 Lox NG). Anlässe, jeder einzeln abwählbar: Ladung beendet, Ladung unter
  dem Ladeziel oder mit Störung beendet, Ladeschwelle erreicht (dieselbe Schwelle wie die Meldung), keine
  Daten mehr. Angesagt wird nur ein Wechsel, derselbe Anlass je Fahrzeug höchstens einmal je Stunde; nach
  einer Neuinstallation oder einer Pause von mehr als sechs Stunden setzt der erste Abruf nur den
  Ausgangsstand. MQTT, Loxone, Mail und exec bleiben unverändert.
* Adresse des Music Servers und Adressvorlage müssen im Heimnetz liegen.
* Testansage per Knopf im Reiter Test; die Selbstprüfung zeigt Ausgabeart, letzte Ansage und die
  eingeschalteten Anlässe.
* Die Sprechtoken für Alexa-NG und Chromecast 4 Lox NG stehen nie in der Seite, im Protokoll oder in einer
  Sicherung; eine Sicherungsdatei, die eines trägt, wird abgewiesen. Eine Sicherung von 2.1.15 lässt sich
  weiter zurückspielen.

## Version 2.1.15

Welle 4 der Verbesserungsliste (`Pruefung-Durchgang-2026-09-29/VERBESSERUNGEN_OFFEN.md`, Entscheidungen 16, 19 und 21).
Gemessen mit einer Renault-Attrappe unter PHP 7.4, 8.3 und 8.5; nicht an einem Fahrzeug.

* **Ohne Zugangsdaten** schreibt der Takt nur noch eine INFO-Zeile am Tag statt
  rund 48 ERROR-Zeilen.
* **Befehlsbremse:** Derselbe Schaltbefehl (Klima an/aus samt Zieltemperatur,
  Laden an/aus, Ladeplan an/aus) innerhalb von 60 s geht nur einmal hinaus
  (`UNVERAENDERT=1`); der Abruf ist ausgenommen.
* Eine **zurückgespielte Sicherung** räumt die MQTT-Themen alter Fahrzeugnamen ab,
  wie das Speichern.
* **Halb lesbare Konfiguration:** Der Reiter Einstellungen bietet die noch
  lesbaren Felder einer beiseitegelegten Datei zum Übernehmen an (Passwort nur
  per Haken, nie im HTML; Löschen nur mit Bestätigung).
* **Neuinstallation:** `preinstall.sh` legt Altlasten vor dem Kopieren der
  Cron-Datei beiseite; ein Takt in der Installationslücke holt kein altes Token
  mehr zurück.
* **Nach einer Beanstandung wird nichts gespeichert,** Zeichen werden nicht mehr
  still entfernt, die Eingaben kommen markiert zurück; der Ländercode wird
  großgeschrieben. „Einstellungen sichern“ warnt bei auffälligen Werten.

## Version 2.1.14 — Sammelnachzug curl_close

Sammelnachzug vom 30.09.2026, sonst keine Änderung: `curl_close()` wird nur
noch unter PHP 7 aufgerufen. Ab PHP 8.0 wirkt der Aufruf nicht mehr, und
PHP 8.5 meldet ihn zur Laufzeit als veraltet. Bei eingeschalteter
Fehleranzeige konnte diese Meldung vor einer Antwort an Loxone landen. Am
LoxBerry mit PHP 7.4 ändert sich nichts.

## Version 2.1.13 — der Endpunkt antwortet für den Befehl, nicht für den Abruf

Durchsicht vom 29.09.2026 mit vier Prüfern (Code, Oberfläche, Installer, MQTT).
Jeder Punkt ist gemessen und hat eine Gegenprobe, die an 2.1.12 rot und an
2.1.13 grün ist. Gemessen wurde in WSL mit einer Attrappe der Renault-Cloud und
des Brokers. Im Haus gibt es kein Renault-Konto.

**Steuerbefehle aus Loxone.** Der HTTP-Code richtet sich jetzt nach dem Befehl.

* Ein gescheiterter Befehl antwortet mit 502 und „NICHT ausgeführt“. Das gilt
  auch dann, wenn der Abruf danach gelang; bisher kam in diesem Fall 200.
* Ein gelungener Befehl antwortet mit 200. Scheitert danach nur der Abruf,
  nennt die Antwort das in einer eigenen Zeile; bisher kam in diesem Fall 502.

**Volle Speicherkarte.** Bisher verloren Konfiguration und Zweitschrift das
Aktionstoken, und jede Loxone-Adresse antwortete danach mit 403. Jetzt gilt
nur als geschrieben, was ganz geschrieben und zurückgelesen ist. Ist der
Zwischenspeicher nicht schreibbar, lösen Haken, Mail und Lademodus-Befehl
nicht bei jedem Abruf erneut aus.

**MQTT**

* Alte zurückbehaltene Werte, etwa `BatTemp` bei Phase 2 oder
  `ChargingTime` außerhalb des Ladens, räumt der erste volle Lauf ab. Der
  Broker bestätigt das Abräumen; danach öffnet kein Lauf mehr eine eigene
  Broker-Verbindung dafür.
* Leere Messwerte gehen nicht mehr hinaus; ein leerer Zustand geht als `-`.
* Wird ein Fahrzeug umbenannt oder entfernt, räumt „Speichern“ seine Themen
  unter dem alten Namen ab.
* Scheitert die Abfrage von Klimatisierung oder Lademodus, bleibt der
  zurückbehaltene Stand stehen. Bisher wurde er mit `n/a` überschrieben;
  `n/a` kommt jetzt nur noch, wenn der Endpunkt das Fahrzeug nicht kennt.
* `InTemp` und `OutTemp` stammen nur noch aus dem aktuellen Abruf.

**Oberfläche**

* Nach jedem Absenden wird umgeleitet; F5 wiederholt nichts mehr. Nach „Neues
  Token“ wird der nächste Klick nicht mehr abgewiesen.
* Wetterschlüssel und ABRP-Token stehen nicht mehr im Klartext in der Seite.
  Kennwort und beide Schlüssel lassen sich mit einem Haken löschen; ein leeres
  Feld lässt den Wert unverändert.
* Ein Befehl mit `&` wird schon beim Speichern beanstandet. Bisher wurde er
  gespeichert, aber nie ausgeführt, und die eigene Sicherung ließ sich danach
  nicht zurückspielen.
* Eine Sicherung ohne Token behält das geltende Token und sagt es.
* Die Prüfzeile „Tragen alle Formulare das Merkmal?“ stand auf jeder Anlage
  rot; sie hatte ihr eigenes Suchmuster mitgezählt.
* Die Adressen im Reiter „Einbindung in Loxone“ tragen den Rechnernamen.
* PHP 8.5 meldet keine Verfallswarnung mehr.

**Installation**

* Eine Neuinstallation spielt keine Zugangsdaten einer früheren Installation
  mehr ein. Zweitschrift und Rettungsordner werden als `.alt` beiseitegelegt
  und einmal genannt; die Deinstallation räumt sie ab.
* Ein Update erkennt sich an einer Marke statt am Alter. Bisher meldete jedes
  Update ohne Ladehistorie eine Warnung, und ein Rettungsordner blieb liegen.
* Eine Konfiguration, die das Update verwirft, bleibt als `.kaputt.<Zeit>`
  liegen.

**Bekannte Grenze.** Läuft ein Cron-Takt genau zwischen dem Kopieren der
Dateien und `postinstall.sh` einer Neuinstallation, kann die Bibliothek eine
liegengebliebene Zweitschrift noch einspielen. Das ist abgeleitet, nicht
gemessen. Es bleibt so, weil sich die Konfiguration nach einem versehentlichen
Löschen weiter selbst heilen soll.

## Version 2.1.12 — „nicht zu fragen“ heißt nicht „leer“

Die Rückfrage beim Broker, ob früher zurückbehaltene Werte (`ok`, `BattSOC`,
`Range` … und die Ladehistorie) noch dastehen, liest jetzt die Antwort auf das
Abonnement (SUBACK). Lehnt der Broker das Lesen ab (Rückgabe 0x80, etwa durch eine
Zugriffsregel) oder antwortet er nicht zu jedem Thema, gilt er als „nicht zu
fragen“: kein Merker „vom Broker bestätigt“, die Altwerte werden weiter unmittelbar
vor dem gültigen Wert gelöscht, das Protokoll sagt es, und die Deinstallation meldet
nicht mehr „der Broker bestaetigt … nichts zu leeren“, sondern warnt, dass sie nicht
nachlesen konnte. Bis 2.1.11 galt eine Ablehnung als „nichts belegt“ (gemessen in
WSL, `Pruefung-Renault-NG-2.1.12`, Fälle S3, S4, S7, S9, S11).

## Version 2.1.11 — `ok` nicht mehr zurückbehalten, alte Werte abgeräumt, die Ladehistorie übersteht jedes Update

**Was Sie bemerken:**

- **`Renault/<Fahrzeug>/ok` geht ohne Retain hinaus.** Es sagt, ob der letzte
  Abruf *des Plugins* gelang — eine Aussage über das Plugin, nicht über das
  Fahrzeug. Zurückbehalten blieb eine 1 im Broker stehen, auch wenn der Abruf
  gar nicht mehr lief, und nach einem Neustart von Broker oder Gateway las
  Loxone „in Ordnung" von einem angehaltenen Plugin. Nach einem Neustart fehlt
  `ok` jetzt, bis der nächste Abruf es sendet. Die Zustände des Fahrzeugs
  (Ladestatus, Kabel, Lademodus, Klimatisierung, Kilometerstand, Name, Ende
  des letzten Ladevorgangs) bleiben zurückbehalten.
- **Alte, zurückbehaltene Werte werden einmal abgeräumt.** Bis 2.1.5 gingen
  alle Themen retained hinaus; seit 2.1.6 sind die Messwerte und Zeitangaben
  flüchtig. Ein flüchtiges Senden ersetzt einen zurückbehaltenen Wert aber
  nicht — auf einer Anlage, die von 2.1.5 oder früher kommt, standen die
  alten Werte bis heute im Broker und kamen nach jedem Neustart als frisch
  bei Loxone an. Das Plugin fragt den Broker jetzt, was noch dasteht, löscht
  genau das unmittelbar vor dem gültigen Wert (leere Nachricht mit Retain)
  und merkt es sich erst, wenn der Broker bestätigt, dass nichts mehr
  dasteht. Dafür meldet es sich mit den Zugangsdaten des MQTT-Gateways am
  Broker an; geht das nicht, löscht es bei jedem Abruf vor dem Wert und fragt
  beim nächsten wieder.
- **Die Deinstallation leert die zurückbehaltenen Themen** der eingerichteten
  Fahrzeuge und liest beim Broker nach. Bis 2.1.10 blieben sie stehen, und
  Loxone las den Ladestand vom Tag der Deinstallation nach jedem Neustart
  wieder ein.

**Gefunden bei der Nachlese am 25.09.2026** (in WSL gemessen, nicht am Gerät;
Prüfstand `Pruefung-Renault-NG-2.1.11`, 51 Fälle, vorher 36 rot, nachher 0):

- **Die Ladehistorie ging bei einem Update verloren, wenn der Abruf in die
  Lücke fiel.** Zwischen dem Löschen des Datenordners und dem Zurückspielen
  läuft `cron.03min` weiter; bei eingeschalteter Aufzeichnung legte der Abruf
  eine neue `database.csv` mit einer Zeile an, und `postupgrade.sh` spielte
  die gerettete Historie dann nicht mehr zurück („lagen schon da"). Jetzt
  werden beide nach Inhalt zusammengeführt: die gerettete Datei, dahinter die
  Zeilen aus der Lücke.
- **Ein alter Rettungsordner wurde bei jedem Update wieder eingespielt.** Er
  blieb nach jedem Update liegen, und eine Datei, die es seit Monaten nicht
  mehr gab, kam beim nächsten Update zurück. Der Rettungsordner trägt jetzt
  den Zeitpunkt seines Updates; zurückgespielt wird nur einer aus diesem
  Update (höchstens eine Stunde alt, Zeitpunkt und Uhr als Zahl geprüft), und
  er wird entfernt, sobald jede Datei nachweislich zurück ist. Ein alter
  bleibt unberührt liegen, und das Installationsprotokoll nennt den Ort.
- **Die Konfiguration wurde beim Update nach Größe statt nach Inhalt
  behandelt.** `preupgrade.sh` kopierte auch eine abgeschnittene
  `config.php` über die heile Zweitschrift; `postupgrade.sh` spielte nur
  zurück, wenn `config.php` leer war, und meldete „wiederhergestellt" auch bei
  einer leeren Zweitschrift. Jetzt zählt der Inhalt (vollständig geschrieben
  und mit Aktionstoken oder Benutzername); eine verdrängte Datei bleibt als
  `config.php.kaputt` daneben liegen.
- **Ein ausgepacktes Archiv wirkte auf die Anlage.** Unter einem LoxBerry
  entpackt, legte `abruf.php` dort Ordner namens `htmlauth` an; in einem
  fremden Verzeichnis ohne `config/system/general.json` galt dieses als
  LoxBerry; ohne jede Wurzel wurden Pfade ab `/` gebildet. Jetzt gilt die
  Anlage nur, wenn das Plugin dort installiert ist oder `LBHOMEDIR` und
  `LBPPLUGINDIR` sie ausdrücklich nennen; sonst holen, senden und schreiben
  `abruf.php` und `history.php` nichts. Endpunkt und Selbsttest suchen ihre
  Bibliothek nur noch am eigenen Ablageort. Die Hakenskripte tun nichts, wenn
  der Installer sie nicht aufgerufen hat.
- **Der Selbsttest verlangte `ok` retained** und verlangt jetzt flüchtig; er
  prüft außerdem, dass keines der abzuräumenden Themen heute retained geht.

**Grenzen:** nichts davon ist am Gerät gemessen. Der Broker ist im Prüfstand
ein Nachbau, der das Retain-Kennzeichen jedes empfangenen Pakets aufschreibt;
`phpMQTT` ist nachgebildet. Themen eines Fahrzeugs, das vorher aus den
Einstellungen entfernt wurde, kennt die Deinstallation nicht.

**Unverändert:** Themennamen, Befehle, Konfiguration, Sicherung.

## Version 2.1.9 — das Abo kommt mit, die Eingangsvorlage geht, ein leerer Wert löscht nichts mehr

**Zwei Änderungen, die Sie bemerken:**

- **Das Abonnement im MQTT-Gateway kommt mit.** Das Plugin liefert
  `config/mqtt_subscriptions.cfg` mit dem Thema `Renault/#`. Das Gateway
  liest diese Datei bei jeder Installation und jedem Update selbst (am
  Gateway des LoxBerry 4.0.0.15 im Quelltext nachgelesen). Ein Eintrag von
  Hand unter *System → MQTT Gateway → Subscriptions* ist nicht mehr nötig; ein
  vorhandener darf stehen bleiben. Der Reiter Test prüft, ob die Datei da
  ist. Beim Entfernen des Plugins verschwindet das Abo mit dem
  Konfigurationsordner.
- **Die Importdatei für die Eingänge ist entfallen.** Sie legte über einen
  Kunstgriff (virtueller HTTP-Eingang mit `http://localhost` und einem
  Abfragezyklus von einer Woche) nur die Zahlenthemen an; die Werte kamen
  trotzdem vom Gateway. An ihrer Stelle steht im Reiter *Einbindung in
  Loxone* die **vollständige Namenstabelle**: jeder Eingangsname, wie das
  Gateway ihn bildet, mit Retain-Angabe, Einheit und Bedeutung. **Wer die
  Datei früher eingelesen hat, muss nichts tun** — die Eingänge bleiben und
  bekommen weiter Werte. Die Importdatei für die **Befehle** bleibt.

**Gefunden bei der Durchsicht am 17.09.2026**, am installierten 2.1.8 und am
Quelltext gemessen:

- **Ein leerer Zustand ging retained hinaus — und löschte damit den letzten
  guten Stand im Broker.** Eine leere Nutzlast mit Retain-Merker entfernt ein
  zurückbehaltenes Thema. Liefert die Schnittstelle etwa `plugStatus` einmal
  nicht, ging `CableStatus` leer und retained hinaus; nach einem Neustart des
  Miniservers stand dort nichts mehr. Gemessen an beiden Sendefunktionen
  (`abruf.php`, `history.php`): in 2.1.8 trugen 8 von 16 Probesendungen den
  falschen Merker, in 2.1.9 keine. Ein leerer Wert geht jetzt **nie**
  retained; die Null (`ok = 0`, Kabel = 0) ist kein leerer Wert und bleibt es.
- **Der Reiter MQTT behauptete „Alle Werte werden retained gesendet".** Das
  stimmt seit 2.1.6 nicht mehr; ebenso die Hilfe und die Funktionsliste
  dieser Datei. Die Themen-Tabelle hat jetzt eine Spalte **retained**, die aus
  derselben Tabelle kommt, nach der gesendet wird.
- **Die Fassungsnummer** wird bei LoxBerry über den Ordnernamen erfragt. Ohne
  ihn hängt die Antwort daran, welches Skript zuerst geladen wurde; am Gerät
  gemessen lieferte derselbe Aufruf je nach Einstieg `2.1.8` oder nichts.
- Der Deinstallationshinweis nennt alle zurückbehaltenen Themen
  (`HvAcStatusBin` und `RenaultPHMode` fehlten).
- `plugin.cfg`, `release.cfg` und `prerelease.cfg` haben jetzt LF-Zeilenenden
  wie alle übrigen Dateien.

**Unverändert:** Themennamen, Befehle, Konfiguration, Sicherung. Wer nichts
tut, bekommt beim nächsten erfolgreichen Abruf alle Zustände wieder in den
Broker.

## Version 2.1.7 — die sieben Steuerbefehle tragen einen Namen

Bis 2.1.6 lieferte die Vorlage der Steuerbefehle **sieben Ausgänge ohne
Beschriftung**.

Loxone Config nimmt den `Comment` einer Vorlage als **Anzeigenamen**. Stand
dort nichts, zeigte Config den Titel. Jetzt steht dort ein Name mit
Gerätevorsatz — die Bausteinsuche des Miniservers kennt den Geräteknoten
nicht, und „Automatik" gibt es auch im Batterie-Plugin.

**Die Titel sind unverändert geblieben** — ein geänderter Titel legt beim
erneuten Import neue Ausgänge **neben** die alten. Wer die Vorlage neu
einliest, bekommt dieselben Ausgänge, nur mit Namen.

`Vorklimatisierung starten` → **Renault: Vorklimatisierung starten**; bei
mehreren Fahrzeugen steht der Fahrzeugname dazwischen
(**Renault Zoe: …**), so wie der Titel es schon tut.

Gemessen: 7 von 7 Titeln und Befehlen byteweise wie in 2.1.6, 0 Ausgänge ohne
Beschriftung (vorher 7). Ein Name liegt mit 42 Zeichen zwei über dem
Richtwert von etwa 40 — `Renault: Ladeplan abschalten (immer laden)`; der
Klammerzusatz steht so in der Beschriftung, aus der auch der Titel kommt, und
wurde nicht angetastet. Im Kopf der Vorlage steht jetzt außerdem, dass Loxone
Config beim Import neu anlegt und nichts überschreibt.

## Version 2.1.6 — was eine Durchsicht bei grünem Prüfwerkzeug noch findet

Am 04./05.09.2026 ist 2.1.5 vollständig gegengelesen worden. Die
Freigabeprüfung des Hauses meldete dabei **14 Prüfungen, 0 Beanstandungen** —
und trotzdem fanden sich 33 Befunde, fünf davon schwer. Alle liegen außerhalb
dessen, was ein Prüfwerkzeug am Quelltext sehen kann; drei findet man nur,
indem man die Konfigurationsdatei absichtlich beschädigt und den Endpunkt
gegen einen echten Webserver fährt.

### Woran Sie merken, dass Sie betroffen waren

- **Ihr Aktionstoken hat sich ohne Ihr Zutun geändert**, und die Adressen im
  Miniserver antworteten plötzlich mit 403 (ein Virtueller Ausgang zeigt das
  nicht an — er wirkt nur nicht mehr). Ursache: eine leere oder unlesbare
  `config.php` wurde wie eine Werkseinstellung gelesen.
- **Ihre Ladehistorie ist nach einem Update verschwunden.**
- **Ein Loxone-Baustein meldete Erfolg, obwohl nichts geschaltet wurde** — der
  Endpunkt antwortete auf jeden Fehler mit HTTP 200.

### Behoben

- **Die Konfiguration heilt sich nach Inhalt, nicht nach Form.** Eine leere,
  unlesbare oder halb geschriebene `config.php` wird erkannt, als `.kaputt`
  beiseitegelegt, aus der Zweitschrift wiederhergestellt und **einmal**
  protokolliert. Bis 2.1.5 hing die Selbstheilung allein daran, ob die Datei
  *fehlte* — eine vorhandene, aber unlesbare galt als in Ordnung, das Plugin
  würfelte ein neues Token und überschrieb damit die heile Zweitschrift.
- **`config.php` wird nicht mehr mit `include` gelesen**, sondern zerlegt. Ein
  Syntaxfehler darin ist damit kein `E_COMPILE_ERROR` mehr, der Oberfläche,
  Cron und Endpunkt gleichzeitig stilllegt.
- **Die Ladehistorie überlebt ein Update** (`data/plugins/<ordner>.rettung/`).
- **Der Endpunkt sagt die Wahrheit:** 502 bei einer abweisenden Gegenstelle,
  503 bei fehlenden Zugangsdaten, 403 bei ausgeschalteter Steuerung, 409 bei
  einer unzulässigen Zieltemperatur. Ein Ladefehler ergibt keinen leeren
  HTTP 500 mehr, sondern eine lesbare Zeile.
- **Jede Abweisung am Endpunkt steht im Protokoll**, mit der Adresse des
  Anrufers und ohne das Token.
- **Der unangemeldete Endpunkt legt nichts mehr an.** Bis 2.1.5 entstanden aus
  einem einzigen Aufruf ohne Token sechs Verzeichnisse im LoxBerry-Baum.
- **Eine Fahrzeugnummer, die nicht aus Ziffern besteht, wird abgewiesen.**
  `fahrzeug=2abc`, `2.9`, `" 2"` wurden alle zu 2 — und `fahrzeug[]=2` zu 1;
  ein `chargestop` traf damit das falsche Fahrzeug.
- **Zurückspielen prüft jeden Wert und behält, was in der Datei fehlt.** Eine
  Sicherung mit einem einzigen Schlüssel setzte bis 2.1.5 alles Übrige auf
  Werk — Token, Kennwort und Fahrgestellnummer eingeschlossen — und meldete
  dazu „1 Werte übernommen“. Die Datei trägt jetzt einen lesbaren `_`-Kopf.
- **Retain je Thema.** Zustände bleiben zurückbehalten, Messwerte mit
  Zeitbezug nicht mehr, das Lebenszeichen nie. Bis 2.1.5 gingen alle Themen
  retained hinaus, auch der Zeitstempel — ein zurückbehaltenes Lebenszeichen
  meldet immer „lebt“.
- **Zwei neue Themen: `status/ts` und `status/zaehler`.** Sie gehen bei
  **jedem** Cron-Durchgang hinaus, auch wenn die Abrufbremse den Datenabruf
  überspringt. Bis 2.1.5 wurde in einem übersprungenen Lauf gar nichts
  veröffentlicht — bei der Werkseinstellung jeder zweite.
- **Der Reiter Test kennt einen dritten Ausgang** (Punkt statt Kreuz) und ruft
  den eigenen Endpunkt wirklich auf. Dazu vier neue Prüfzeilen: Formularmerkmal,
  serverseitiger Reiter, Vollständigkeit der Konfiguration, Cron-Einträge.
- **Die Auswahlfelder haben wieder einen Pfeil**, die Ladehistorie eine
  Kopfzeile und einen Rollbehälter, und „Daten sofort neu abrufen“ steht grün
  bei den lesenden Knöpfen statt orange unter „Schalten“.
- **Die Ausgaben der fünf Prüfknöpfe sind zweisprachig.**
- **`uninstall` entfernt die Zweitschrift.** Sie liegt neben dem Konfigordner
  und blieb deshalb nach dem Entfernen des Plugins mit dem My-Renault-Kennwort
  im Klartext liegen; eine spätere Neuinstallation holte sie von selbst zurück.
- **`php-curl` steht in `dpkg/apt`**, und es gibt eine Wache davor.
- Kleineres: `ac_temp` außerhalb 16–30 wird abgewiesen statt still auf 21
  gebogen; die Sperrdatei der Cron-Läufe kommt aus dem Ordnernamen (eine
  Zweitinstallation sperrte sich sonst selbst aus); Obergrenze der
  Sicherungsdatei 64 kB; kein fester Pfad `/home/loxberry/loxberry` mehr;
  tote CSS-Regeln entfernt; die Hilfe nennt keine veraltete Fassungsnummer
  mehr.

### Bewusst nicht gemacht

- **Die Themennamen bleiben.** `chargeDuration(min)` ist unhandlich, aber an
  jedem Namen hängt ein virtueller Eingang in einer fremden Anlage.
- **Der Themenvorsatz `Renault/` bleibt unveränderlich.** Ein Eingabefeld
  dafür würde auf jeder bestehenden Anlage sämtliche Themen umbenennen.

## Version 2.1.1 — Formularschutz und zwei geprüfte Befehlszeilen

### Kein Formular trug ein Einmalmerkmal

`htmlauth` schützt gegen den unangemeldeten Aufruf — nicht dagegen, dass der
Browser eines angemeldeten Bedieners ein Formular abschickt, das auf einer
**fremden Seite** steht. Bei diesem Plugin war das besonders teuer, denn zu den
Einstellungen gehören `exec_bl` und `exec_csf`: zwei Befehlszeilen, die der
Abruf später ausführt. Eine fremde Seite konnte also einen Befehl eintragen
und danach mit *Protokoll leeren* die Spur beseitigen.

Alle **sieben** Formulare tragen jetzt ein Merkmal, das aus dem Aktionstoken
abgeleitet wird — nicht gespeichert, denn ein weiterer Schlüssel in der
Konfiguration ist ein weiterer Schlüssel, den ein Speichern-Handler vergessen
kann. Geprüft wird an **einer** Stelle vor allen Handlern; fehlt das Merkmal,
wird `$_POST` bis auf den aktiven Reiter geleert. Das ist mit Absicht
gründlicher als eine Abfrage vor jedem Handler: der achte Handler, den jemand
später ergänzt, ist damit von selbst mitgeschützt.

Die Reiterwahl steht jetzt **nach** dem Wachposten. Vorher hätte ein
abgewiesenes Formular wenigstens noch den Reiter umschalten können.

### Die beiden Befehlszeilen werden geprüft, bevor sie laufen

`exec_bl` und `exec_csf` liefen unverändert durch `shell_exec`. Der übergebene
**Text** war maskiert, der **Befehl** nicht — er ist eine bewusste Eingabe des
Betreibers, und das bleibt richtig. „Bewusst" ist aber nicht dasselbe wie
„geprüft": ein Semikolon in dem Feld sind zwei Befehle, und der zweite steht
dann in einer Konfigurationsdatei, in die niemand mehr sieht.

Geprüft wird jetzt **an der Stelle des Aufrufs**, nicht nur beim Speichern —
eine Datei kann sich dazwischen geändert haben, und die Konfiguration einer
1.4-Installation wurde nie geprüft:

* Sonderzeichen der Schale (`; & | ` `` ` `` `$ ( ) < >`, Zeilenumbruch) → abgewiesen
* das erste Wort muss eine vorhandene, **ausführbare** Datei sein
* jedes Wort wird einzeln maskiert, die Meldung kommt als letztes Argument

Abgewiesen heißt: es läuft nichts, und im Protokoll steht warum. Ein still
übergangener Haken ist schlimmer als ein Fehler. **Wer bisher `mein_skript`
ohne Pfad eingetragen hatte, muss den vollen Pfad nachtragen** — die
Protokollzeile sagt es.

### `api-keys.php` sagt jetzt, was darin steht

Die Datei trägt zwei feste Schlüssel im Quelltext, und jedes Freigabewerkzeug
meldet das — zu Recht, denn es kann den Unterschied nicht kennen. Es sind die
Schlüssel der Renault-**App**, öffentlich bekannt und in jedem freien
Renault-Projekt nachzulesen; ohne Benutzername und Passwort eines
Renault-Kontos ist mit ihnen nichts zu erreichen. Persönlich ist einzig, was in
`config.php` steht. Das steht jetzt über der Datei — samt der Bitte, sie nicht
„aufzuräumen": ohne die Schlüssel gibt es keine Anmeldung.

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
- MQTT über das LoxBerry MQTT Gateway, **Zustände retained**, Messwerte und
  Lebenszeichen ohne Retain (Spalte „retained" im Reiter MQTT), Themen
  `Renault/<Fahrzeugname>/…`
- Befehle über `/plugins/renault_ng/index.php?token=<TOKEN>&aktion=…` (ohne
  LoxBerry-Anmeldung, dafür mit Token; ab Werk gesperrt)
- Ladehistorie mit Tagesdiagramm, Ausfallerkennung über das Lebenszeichen `status/zaehler` und `ok`
- Einbindung in Loxone mit drei fertig verbundenen Seiten aus dem
  [LoxBerry-Plugins Musterprojekt](https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt) – die ganze
  Projektdatei mit allen Plugin-Seiten und Vorlagen; im Reiter je Seite Tabelle, Anleitung und Bild
- Sechs Reiter: **Einstellungen**, **MQTT**, **Einbindung in Loxone**, **Test**,
  **Ladehistorie**, **Logdateien**

## Wo die Daten liegen

| | bis 1.4 | ab 1.6.0 |
|---|---|---|
| Konfiguration | `webfrontend/htmlauth/config.php` | `config/plugins/renault_ng/` (0600) |
| Zweitschrift | – | `config/plugins/renault_ng.backup.config.php` (0600, ab 2.1.0 **neben** dem Ordner) |
| Anmeldung, Sitzungen, Ladehistorie | `webfrontend/htmlauth/` | `data/plugins/renault_ng/` |
| Protokoll | `webfrontend/htmlauth/` | `log/plugins/renault_ng/` |
| Gateway-Abo | von Hand | `config/plugins/renault_ng/mqtt_subscriptions.cfg` (ab 2.1.9, kommt mit jedem Update neu) |

`webfrontend/htmlauth` gehört zum **Programm** und wird bei jedem Update
gelöscht und neu angelegt. Deshalb gab es bis 1.4 eine Sicherung nach `/tmp` –
eine Krücke um einen Konstruktionsfehler herum, und eine brüchige dazu, weil
`/tmp` auf dem LoxBerry eine Ramdisk ist. `rn_umzug()` holt beim ersten Aufruf
nach, was noch am alten Ort liegt.

### Was ein Update wirklich überlebt

Gemessen an `sbin/plugininstall.pl` (Zweig `master`, 2054 Zeilen, geholt am
04.09.2026): die Unterroutine `purge_installation` hat **zwei** Aufrufstellen —
eine bei der Deinstallation und eine im Upgrade-Zweig. Ihr Löschblock steht
ohne Prüfung auf die Betriebsart und entfernt bei **jedem** Update unter
anderem `config/plugins/<ordner>/` **und** `data/plugins/<ordner>/`. Nur
`log/plugins/<ordner>/` hängt an der Betriebsart und bleibt stehen.

| Was | überlebt ein Update? | wie |
|---|---|---|
| Konfiguration | ja | `preupgrade.sh` legt die Zweitschrift **neben** den Ordner, `postupgrade.sh` holt sie zurück |
| Ladehistorie `database*.csv` | **seit 2.1.6** ja | ebenso, über `data/plugins/<ordner>.rettung/` |
| Anmeldung, Zwischenspeicher | nein — und das ist richtig | beides wird beim nächsten Abruf neu geholt |
| Protokoll | ja, aber nicht über einen Neustart | `log/plugins` ist eine Ramdisk |

Bis 2.1.5 stand hier und in drei weiteren Texten das Gegenteil: `data/`
überstehe ein Update. Die Ladehistorie war deshalb nach jedem Auto-Update
fort — ohne Meldung, und der Reiter *Ladehistorie* hatte im selben Atemzug
versprochen, die Datei überlebe Updates und Neuinstallationen.

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

`templates/lang/language_de.ini` und `language_en.ini` führen je **760
Schlüssel** (603 eigene und die 157 Sätze der gemeinsamen Sprachausgabe im Abschnitt `[ANSAGE]`),
deckungsgleich; jeder davon wird benutzt, und jeder benutzte
Schlüssel ist vorhanden. Beide Dateien werden aus einer Quelle erzeugt, damit
die Mengen gar nicht auseinanderlaufen können — bis 2.0.6 war das nur eine
Zusage in dieser Datei, und sie nannte dazu die falsche Zahl (223 statt der tatsächlichen 227).
