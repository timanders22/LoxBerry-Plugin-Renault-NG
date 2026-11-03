<?php
/**
 * Renault - Datenabruf und Befehlsempfang
 *
 * Wird aufgerufen
 *   - vom Cron alle 3 Minuten:  php abruf.php cron
 *   - vom Endpunkt webfrontend/html/index.php, wenn Loxone einen Befehl
 *     schickt (dort wird das Token geprueft und $rn_auftrag gesetzt)
 *
 * ===================================================================
 * WAS SICH MIT 2.1.0 GEAENDERT HAT - und warum
 * ===================================================================
 *
 * 1. DIE KONFIGURATION KOMMT UEBER rn_config_read(), NICHT UEBER require.
 *    Bis 2.0.6 stand hier  require rn_paths()['config'];  und alle Werte
 *    wurden als globale Variablen benutzt. Zwei Fehler folgten daraus:
 *    fehlte die Datei (Erstinstallation, bevor jemand die Oberflaeche
 *    geoeffnet hatte), brach das Programm alle drei Minuten fatal ab -
 *    ohne eine Zeile im Protokoll, weil logger.php erst danach kam.
 *    Und fehlte ein einzelner Schluessel (jede Konfiguration aus 1.4),
 *    war die Variable undefiniert: bei $cron_ncs ergab das
 *    date_interval_create_from_date_string(' minutes') === false und
 *    unter PHP 8 einen TypeError.
 *
 * 2. BEFEHLE STEHEN VOR DER ABRUFBREMSE.
 *    Der Endpunkt setzte bis 2.0.6  $_GET['cron'] = '1'  - allein wegen
 *    des Ausgabeformats. Damit griff die Intervallsperre, und die stand
 *    VOR den Befehlsbloecken. Ein Befehl aus Loxone kam nur in etwa einer
 *    von sechs Minuten durch; in den uebrigen antwortete das Plugin mit
 *    HTTP 200 und dem Text "INTERVAL NOT REACHED" - also einer
 *    Erfolgsmeldung fuer eine nicht ausgefuehrte Handlung.
 *
 * 3. DER ZEITSTEMPEL WIRD NUR BEI ERFOLG AUFGEFRISCHT.
 *    phpCall kam aus der aktuellen Uhrzeit und wurde bei jedem Lauf neu
 *    gesetzt, auch wenn Renault mit einem Fehler geantwortet hatte. Die
 *    Ausfallerkennung, die der Reiter "Einbindung in Loxone" beschreibt,
 *    konnte damit nie ansprechen. Dazu kommt das Sammelthema "ok".
 *
 * 4. DIE ANMELDUNG IST GEMEINSAM, DIE DATEN SIND JE FAHRZEUG.
 *    Gigya-Token und Kamereon-Konto gelten fuer das Konto und stehen in
 *    data/plugins/<ordner>/anmeldung. Jedes Fahrzeug hat seine eigene
 *    session-Datei. Fahrzeug 1 behaelt die alten Dateinamen.
 *
 * 5. FAIL CLOSED BEI FEHLENDEN ZUGANGSDATEN.
 *    Bis 2.0.6 wurde das nur protokolliert, und das Programm schickte
 *    danach alle drei Minuten eine Anmeldung mit leerem Benutzernamen an
 *    Gigya.
 *
 * Jeder curl-Aufruf traegt Zeitschranken (CURLOPT_CONNECTTIMEOUT 10 s,
 * CURLOPT_TIMEOUT 30 s). Ohne sie haengt ein Abruf, den Gigya oder die
 * Renault-Schnittstelle nicht beantwortet, bis zum Systemende - im Cron
 * staut sich das auf.
 *
 * ===================================================================
 * WAS SICH MIT 2.1.20 GEAENDERT HAT (Gruppe 40)
 * ===================================================================
 * - Alle Aufrufe an Gigya und Kamereon gehen ueber EINE Nahtstelle,
 *   rn_http_senden() in rn_lib.php; rn_get()/rn_post() und die Anmeldung
 *   (rn_anmelden()) stehen dort, ebenso der Befehl (rn_befehl_annehmen()) -
 *   dieselben Funktionen fuer Endpunkt, Befehlsbruecke und Vorklimatisierung.
 * - Betriebsarten: cron, takt (Minutentakt aus cron.01min), abruf [n],
 *   --haus-lesen (abgekoppelter Leser), --mqtt-leeren (Deinstallation) und die
 *   Befehlsnamen. Alles andere: Satz auf STDERR, Rueckgabe 2, ohne Wirkung.
 * - Die Broker-Verbindung entsteht VOR der Anmeldung; scheitert die Anmeldung
 *   im Cron, gehen je Fahrzeug ok = 0 und das Lebenszeichen hinaus (F3).
 * - Eine Zeile der Aufzeichnung nur noch, wenn sich der Stand aendert (V3).
 */

require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once 'phpMQTT/phpMQTT.php';

require_once __DIR__ . '/rn_lib.php';

/* Unbekannte Betriebsart (V7-f wie MG, Bauvorschrift 2.4): Satz auf STDERR,
 * Rueckgabe 2, OHNE Wirkung. Bis 2.1.19 lief jedes unbekannte Argument als
 * Aktionsname in die Befehlspruefung - "--mqt-leeren" (ein Tippfehler) meldete
 * sich an und rief Renault, statt nichts zu tun. Steht vor allem anderen. */
if (!isset($rn_auftrag)) {
    $rn_erlaubt = array_merge(array('cron', 'takt', 'abruf', '--haus-lesen', '--mqtt-leeren', '--hf-lauschen'),
                              array_keys(rn_befehle()));
    $rn_a1 = isset($argv[1]) ? (string) $argv[1] : '';
    $rn_a2_ok = !isset($argv[2]) || preg_match('/^[1-' . RN_MAX_FAHRZEUGE . ']$/', (string) $argv[2]) === 1;
    if (!in_array($rn_a1, $rn_erlaubt, true) || !$rn_a2_ok || count($argv) > 3) {
        fwrite(STDERR, 'abruf.php: unbekannte Betriebsart oder unbekannter Schalter ('
            . ($rn_a1 === '' ? 'keiner' : $rn_a1) . '). Erlaubt: ' . implode(', ', $rn_erlaubt)
            . ' [Fahrzeugnummer 1-' . RN_MAX_FAHRZEUGE . ']. Es geschah nichts.' . "\n");
        exit(2);
    }
}

/* Ohne Anlage nichts holen, nichts senden, nichts schreiben - und das VOR
 * logger.php, dessen erste Zeile ueber rn_paths() die Ordner anlegt, und vor
 * rn_umzug(). Bis 2.1.10 lief diese Datei aus einem ausgepackten Archiv
 * unter einer echten Wurzel gegen die Anlage (mit LBHOMEDIR ebenso wie
 * ohne) und legte dort Ordner namens htmlauth an; in einem fremden Baum ohne
 * general.json nahm sie den Baum als Wurzel (in WSL gemessen,
 * Pruefung-Renault-NG-2.1.11, Faelle W1 bis W3). Ueber den Endpunkt
 * antwortet sie mit 503. */
$rn_ohne = rn_ohne_anlage();
if ($rn_ohne !== '') {
    if (!isset($rn_auftrag)) {
        fwrite(STDERR, 'abruf.php: ' . $rn_ohne . "\n");
        exit(1);
    }
    $rn_http_status = 503;
    echo "NO LOXBERRY ROOT\n";
    return;
}

/* Aus der Deinstallation (uninstall/uninstall): die zurueckbehaltenen Themen
 * der eingerichteten Fahrzeuge leeren und beim Broker nachlesen - ohne
 * Abruf, ohne Protokoll, ohne eine Datei zu schreiben (rn_mqtt_leeren() in
 * rn_lib.php). Steht vor logger.php, damit auch kein Protokollordner
 * entsteht. */
if (!isset($rn_auftrag) && isset($argv[1]) && $argv[1] === '--mqtt-leeren') {
    exit(max(rn_mqtt_leeren(), rn_hf_leeren()));     // 2.1.25: auch haus/fahrzeug/<name>/
}

/* Der Minutentakt (2.4, cron.01min): Waechter der Befehlsbruecke, Leser der
 * Haus-Themen starten, Vorklimatisierung, Ankunft, Preisproben. KEIN Abruf.
 * Ist nichts eingeschaltet, endet er nach dem Lesen der Konfiguration - ohne
 * Protokoll (logger.php wird erst geladen, wenn eine Zeile faellig ist). */
if (!isset($rn_auftrag) && isset($argv[1]) && $argv[1] === 'takt') {
    rn_takt_lauf(__FILE__);
    exit(0);
}
/* 2.1.25: einmal je Stunde auf fremde Zaehler unter den eigenen haus/fahrzeug-Staemmen hoeren (aus dem Takt gestartet, bis 65 s). */
if (!isset($rn_auftrag) && isset($argv[1]) && $argv[1] === '--hf-lauschen') {
    rn_hf_lausch_lauf();
    exit(0);
}
/* Der abgekoppelte Leser (aus dem Takt gestartet, eigene Sperre, bis 65 s). */
if (!isset($rn_auftrag) && isset($argv[1]) && $argv[1] === '--haus-lesen') {
    rn_haus_leser_lauf();
    exit(0);
}

require_once __DIR__ . '/logger.php';
list($gigya_api, $kamereon_api) = rn_api_schluessel();   // api-keys.php, einmal gelesen

rn_umzug();                      // einmaliger Umzug aus dem Programmordner

session_cache_limiter('nocache');

/* ==================================================================
 * Auftrag bestimmen
 *
 * $rn_auftrag setzt der Endpunkt (webfrontend/html/index.php), bevor er
 * diese Datei einbindet. Ueber die Kommandozeile kommt derselbe Auftrag
 * als Argument. Beides landet in denselben zwei Variablen - es gibt
 * keinen zweiten Weg mehr, und "cron" ist kein Ausgabeformat mehr,
 * sondern nur noch die Betriebsart.
 * ================================================================== */
$rn_ist_cron  = false;
$rn_aktion    = '';
$rn_fahrzeugnr = 1;

/* Der HTTP-Statuscode, den der Endpunkt aus diesem Lauf ableitet.
 *
 * Bis 2.1.5 antwortete webfrontend/html/index.php auf JEDEN Ausgang dieser
 * Datei mit HTTP 200 - auch auf LOGIN FAILED, NO CREDENTIALS und OK=0. Das
 * ist wortgleich die Klasse, die der Kopf jener Datei fuer behoben erklaert
 * ("eine Erfolgsmeldung fuer eine nicht ausgefuehrte Handlung"): der Text
 * hatte gewechselt, der Statuscode nicht. Loxone wertet nur den Code aus.
 *
 * Ueber die Kommandozeile (Cron) hat die Zahl keine Bedeutung; sie stoert
 * dort auch nicht. */
$rn_http_status = 200;

if (isset($rn_auftrag) && is_array($rn_auftrag)) {
    $rn_aktion     = isset($rn_auftrag['aktion']) ? (string) $rn_auftrag['aktion'] : '';
    $rn_fahrzeugnr = isset($rn_auftrag['fahrzeug']) ? (int) $rn_auftrag['fahrzeug'] : 1;
} elseif (isset($argv[1])) {
    if ($argv[1] === 'cron') { $rn_ist_cron = true; }
    else { $rn_aktion = (string) $argv[1]; }
    if (isset($argv[2])) { $rn_fahrzeugnr = (int) $argv[2]; }
}
header('Content-Type: text/plain; charset=utf-8');

/* Ohne die curl-Erweiterung geht hier gar nichts.
 *
 * Sie steht in dpkg/apt, wird also mitinstalliert - aber "Call to undefined
 * function curl_init()" ist ein fataler Fehler, den kein @ und kein
 * error_reporting aufhaelt. Ueber den Endpunkt faengt ihn der catch-Zweig;
 * im Cron stirbt der Lauf mit Rueckgabewert 255 und schreibt nach
 * /dev/null. Eine gelesene Zeile ist billiger als eine Stunde Suchen. */
if (!function_exists('curl_init')) {
    renault_log('ERROR', 'Die PHP-Erweiterung "curl" fehlt. Ohne sie kann das '
        . 'Plugin Renault nicht erreichen. Auf dem LoxBerry nachinstallieren: '
        . 'sudo apt-get install php-curl, danach den Webserver neu starten.');
    $rn_http_status = 500;
    echo "NO CURL\n";
    return;
}

$rn_cfg   = rn_config_read();
$rn_autos = rn_fahrzeuge($rn_cfg);

/* ==================================================================
 * Fail closed: ohne Zugangsdaten wird nichts versucht
 * ================================================================== */
if ($rn_cfg['username'] === '' || $rn_cfg['password'] === '') {
    /* Der Cron einer nicht eingerichteten Anlage schweigt (Renault-a1,
     * Entscheidung Nr. 16): eine INFO-Zeile je Tag statt einer ERROR-Zeile je
     * Stunde samt Sammelzeile (bis 2.1.14 rund 48 Zeilen am Tag). Nur der
     * Cron - ein Befehl ohne Zugangsdaten ist ein Fehler und bleibt ERROR. */
    if ($rn_ist_cron) {
        renault_log_taeglich('INFO', 'Keine Zugangsdaten eingetragen (Benutzer oder Passwort '
            . 'leer) - der Abruf ruht, an Renault wird nichts gesendet. Sobald beides in den '
            . 'Einstellungen steht, laeuft er von selbst an. Diese Zeile steht hoechstens '
            . 'einmal am Tag.');
    } else {
        renault_log('ERROR', 'Zugangsdaten unvollstaendig (Benutzer oder Passwort leer) - '
            . 'bitte im Plugin die Einstellungen ausfuellen. Es wird nichts an Renault gesendet.');
    }
    $rn_http_status = 503;        // Einrichtung unvollstaendig
    echo "NO CREDENTIALS\n";
    return;
}
$rn_mit_vin = array();
foreach ($rn_autos as $rn_f) {
    if ($rn_f['vin'] !== '') { $rn_mit_vin[] = $rn_f; }
}
if (!$rn_mit_vin) {
    renault_log('ERROR', 'Keine Fahrgestellnummer eingetragen - bitte im Plugin die '
        . 'Einstellungen ausfuellen. Es wird nichts an Renault gesendet.');
    $rn_http_status = 503;
    echo "NO VIN\n";
    return;
}

/* ==================================================================
 * Broker - VOR der Anmeldung (Gruppe 40, F3/E6)
 *
 * Bis 2.1.19 entstand die Verbindung erst nach der Anmeldung; bei jedem
 * Anmeldefehler ging gar nichts hinaus, und Loxone sah weiter "ok = 1" und
 * ein laufendes Lebenszeichen vom letzten guten Lauf. Ohne Zugangsdaten bzw.
 * ohne VIN wird weiter NICHTS gesendet (oben ausgestiegen): nicht
 * eingerichtet ist kein Ausfall.
 * ================================================================== */
$rn_broker = mqtt_connectiondetails();
$rn_mqtt = null;
if (is_array($rn_broker) && !empty($rn_broker['brokerhost'])) {
    $rn_mqtt = new Bluerhinos\phpMQTT($rn_broker['brokerhost'], $rn_broker['brokerport'],
        uniqid(gethostname() . '_client'));
    if (!$rn_mqtt->connect(true, NULL, $rn_broker['brokeruser'], $rn_broker['brokerpass'])) {
        renault_log('ERROR', 'MQTT-Verbindung fehlgeschlagen (Broker '
            . $rn_broker['brokerhost'] . ':' . $rn_broker['brokerport'] . ').');
        $rn_mqtt = null;
    }
} else {
    renault_log('ERROR', 'Kein MQTT-Broker in general.json - System, MQTT Gateway einrichten.');
}

/**
 * Ein Thema veroeffentlichen - retained oder nicht, je Thema.
 *
 * Bis 2.1.5 stand hier fest publish(…, 0, 1): alle 29 Themen gingen mit
 * gesetztem Retain-Merker hinaus, auch der Zeitstempel des Plugins. Welches
 * Thema retained geht, entscheidet rn_thema_retained() in rn_lib.php; die
 * Nutzlast eines Themas ohne Wert rn_mqtt_nutzlast() (seit 2.1.20 mit dem
 * Fehlwert aus rn_fehlwert()). Gesendet wird ueber rn_mqtt_senden() - dieselbe
 * Stelle wie in history.php und im Minutentakt.
 */
function rn_sende($mqtt, $name, $thema, $wert)
{
    rn_mqtt_senden($mqtt, $name, $thema, $wert);
}

/**
 * Das Lebenszeichen - bei JEDEM Durchgang, nie retained.
 *
 * $ts sind Unix-Sekunden, nicht HHMM. Der Zaehler laeuft 0…999 um; -1 gibt
 * es nicht, weil er bei jedem Lauf fortgeschrieben wird.
 */
function rn_lebenszeichen($mqtt, $name, $datadir)
{
    $zdatei = $datadir . '/zaehler';
    $z = is_readable($zdatei) ? (int) @file_get_contents($zdatei) : -1;
    $z = ($z + 1) % 1000;
    @file_put_contents($zdatei, (string) $z);
    rn_sende($mqtt, $name, 'status/ts',      time());
    rn_sende($mqtt, $name, 'status/zaehler', $z);
}

/**
 * Bei einem Anmeldefehler im Cron (2.1, F3/E6): je Fahrzeug mit VIN ok = 0 und
 * das Lebenszeichen - der Lauf hat stattgefunden, der Abruf ist gescheitert.
 * Ein Befehl ueber Endpunkt oder Bruecke sendet das nicht (er hat keinen Abruf
 * gemacht).
 */
function rn_ausfall_senden($mqtt, array $autos, $grund)
{
    if ($mqtt === null) { return; }
    foreach ($autos as $f) {
        if ((string) $f['vin'] === '') { continue; }
        rn_sende($mqtt, $f['name'], 'ok', 0);
        rn_lebenszeichen($mqtt, $f['name'], dirname($f['session']));
    }
    renault_log('INFO', 'Anmeldung gescheitert (' . $grund . ') - je Fahrzeug ok = 0 und das Lebenszeichen gesendet.');
}

/* ==================================================================
 * BEFEHLE - vor der Abrufbremse, siehe Kopf dieser Datei
 *
 * Seit 2.1.20 ueber rn_befehl_annehmen() (rn_lib.php) - dieselbe Funktion wie
 * Endpunkt, Befehlsbruecke und Vorklimatisierung, mit X-7 fuer alle Wege. Der
 * Endpunkt ruft diese Datei nur noch fuer den Abruf; ein Befehl kommt hier nur
 * noch ueber die Kommandozeile an. Die Antwortzeilen sind die bisherigen.
 * ================================================================== */
$rn_ausgabe = array();
$rn_befehl_ok = null;
$rn_abruf_gescheitert = false;   // fuer die Protokollzeile des Endpunkts (Befund C2)

if ($rn_aktion !== '' && $rn_aktion !== 'abruf') {
    $rn_be = rn_befehl_annehmen($rn_aktion, $rn_fahrzeugnr, 'http', $rn_cfg);
    if (!in_array($rn_be[1], array('OK', 'UNVERAENDERT'), true) && strpos($rn_be[1], 'RENAULT_') !== 0) {
        $rn_http_status = (int) $rn_be[2];
        echo $rn_be[3] . "\n";
        if ($rn_mqtt !== null) { $rn_mqtt->close(); }
        return;
    }
    $rn_befehl_ok = ((int) $rn_be[0] === 1);
    $rn_ausgabe[] = $rn_be[3];
    if (!$rn_befehl_ok) { $rn_http_status = 502; }
}

/* ==================================================================
 * Anmeldung - einmal je Tag, gemeinsam fuer alle Fahrzeuge (rn_anmelden())
 * ================================================================== */
$rn_jetzt = date('YmdHi');
$rn_am = rn_anmelden($rn_cfg);
if (!$rn_am[0]) {
    if ($rn_ist_cron) {
        rn_ausfall_senden($rn_mqtt, $rn_mit_vin, $rn_am[3]);
    }
    if ($rn_mqtt !== null) { $rn_mqtt->close(); }
    if ($rn_befehl_ok === null) { $rn_http_status = 502; }
    $rn_ausgabe[] = $rn_am[4];
    echo implode("\n", $rn_ausgabe) . "\n";
    return;
}
$rn_anm   = $rn_am[6];
$rn_konto = $rn_am[1];
$rn_jwt   = $rn_am[2];
$rn_land  = $rn_cfg['country'];

/* ==================================================================
 * Abruf je Fahrzeug
 * ================================================================== */
$rn_irgendwas_ok = false;

foreach ($rn_mit_vin as $rn_f) {

    // Nur das angesprochene Fahrzeug, wenn ein Befehl kam.
    if ($rn_aktion !== '' && (int) $rn_f['nr'] !== (int) $rn_fahrzeugnr) { continue; }

    $rn_vin  = $rn_f['vin'];
    $rn_ph   = (string) $rn_f['zoeph'];
    $rn_name = $rn_f['name'];
    /* EINE Meldung fuer jeden Fehlschlag beim Schreiben des Zwischenspeichers,
     * damit rn_melden_stuendlich() sie als dieselbe erkennt (Befund C3). */
    $rn_sess_fehl = 'Der Zwischenspeicher ' . $rn_f['session'] . ' laesst sich nicht '
        . 'schreiben (Rechte, freier Platz). Solange das so ist, werden die Meldungen '
        . '(Mail, exec_bl, exec_csf, Ladeplan) NICHT ausgeloest - sonst gingen sie bei '
        . 'jedem Abruf erneut hinaus -, und die Abrufbremse greift nicht.';

    /* Zwischenspeicher lesen. Feldbelegung:
     *  0-2 frei (bis 2.0.6 Anmeldung, jetzt in der Datei "anmeldung")
     *  3 MD5 der letzten Antwort   4 Zeitpunkt des letzten VERSUCHS
     *  5 Meldung bei Akkustand gesendet (Y/N)   6 laedt (Y/N)
     *  7 Kilometerstand  8 Datum  9 Uhrzeit  10 Ladestatus  11 Kabel
     * 12 Batteriestand  13 Batterietemperatur (Ph1) / Energie (Ph2)
     * 14 Reichweite  15 Restladezeit  16 Ladeleistung
     * 17 GPS-Breite (Ph2)  18 GPS-Laenge  19 GPS-Datum  20 GPS-Zeit
     * 21 frei (bis 2.0.6 Schwelle, jetzt in der Konfiguration)
     * 22 Aussentemperatur Wetterdienst  23 Wetterlage  24 Lademodus
     * 25 Zeitpunkt des letzten ERFOLGREICHEN Abrufs  26 Modellkennung
     * 27 Innentemperatur  28 Aussentemperatur des Fahrzeugs
     * seit 2.1.20 (Gruppe 40): 29 GPS-Zeit als Unix-Sekunden (3.1)
     * 30 Zeitstempel der Fahrzeugantwort als Unix-Sekunden (E-E1 B, 3.2)
     * 31 HvAcStatus des letzten Abrufs (Vorklimatisierung, Versuch 2)
     * Aeltere Dateien haben die Felder leer (array_pad). */
    $rn_roh = @file_get_contents($rn_f['session']);
    if ($rn_roh === FALSE || $rn_roh === '') {
        $rn_s = array_fill(0, 32, '');
        $rn_s[4] = '202001010000';
        $rn_s[5] = 'N';
        $rn_s[6] = 'N';
        $rn_s[25] = '';
    } else {
        $rn_s = array_pad(explode('|', $rn_roh), 32, '');
    }

    /* ---- Abrufbremse: nur im Cron, und nur fuer den Datenabruf ---- */
    $rn_hole = true;
    if ($rn_ist_cron) {
        $rn_takt = ($rn_s[10] == 1 || $rn_s[6] === 'Y')
            ? max(1, (int) $rn_cfg['cron_acs'])
            : max(1, (int) $rn_cfg['cron_ncs']);
        /* 2.1.23: im Zustand "keine Fahrzeugdaten" seltener (rn_kd_schritt()). */
        if (rn_keine_daten_aktiv($rn_f)) { $rn_takt = max($rn_takt, RN_KD_TAKT_MIN); }
        $rn_d = date_create_from_format('YmdHi', $rn_s[4] !== '' ? $rn_s[4] : '202001010000');
        if ($rn_d === false) { $rn_d = date_create('2020-01-01'); }
        date_add($rn_d, date_interval_create_from_date_string($rn_takt . ' minutes'));
        if ($rn_jetzt < date_format($rn_d, 'YmdHi')) { $rn_hole = false; }
    }
    /* Hoechstens ein Abruf je Minute - unabhaengig davon, wer ruft. */
    $rn_d = date_create_from_format('YmdHi', $rn_s[4] !== '' ? $rn_s[4] : '202001010000');
    if ($rn_d === false) { $rn_d = date_create('2020-01-01'); }
    date_add($rn_d, date_interval_create_from_date_string('1 minutes'));
    if ($rn_jetzt < date_format($rn_d, 'YmdHi')) { $rn_hole = false; }

    if (!$rn_hole) {
        /* Der Lauf holt nichts - aber er hat stattgefunden, und genau das
         * soll am Broker ankommen. Das Lebenszeichen geht deshalb VOR dem
         * continue hinaus, am Doppelt-senden-Filter vorbei. */
        rn_lebenszeichen($rn_mqtt, $rn_name, dirname($rn_f['session']));
        $rn_ausgabe[] = $rn_name . ';SKIP';
        continue;
    }

    $rn_s[4] = $rn_jetzt;
    $rn_erfolg = false;
    $rn_cm_senden = true;        // ChargeMode in diesem Lauf senden? (Befund M4)
    /* Innen- und Aussentemperatur nur aus der Antwort DIESES Laufs (Befund
     * M5). Bis 2.1.12 blieben sie aus dem Zwischenspeicher stehen, wenn
     * hvac-status scheiterte oder das Feld fehlte, und gingen bei jedem
     * Abruf als frischer Messwert hinaus (in WSL gemessen, Fall H, Lauf 2:
     * InTemp=21, OutTemp=12 aus dem Vorlauf). Fehlt der Wert, wird nicht
     * gesendet (rn_mqtt_nutzlast()). */
    $rn_s[27] = '';
    $rn_s[28] = '';
    $rn_wetter_temp = '';        // Aussentemperatur des Wetterdienstes aus DIESEM Lauf

    /* ---- Batterie- und Ladestatus ---- */
    /* 2.1.23: Zustand "keine Fahrzeugdaten" (rn_kd_schritt() in rn_lib.php). Nur das 404 notFound wird still. */
    $rn_kd = rn_kd_lesen($rn_f);
    rn_antwort_leise(!empty($rn_kd['aktiv']));
    list($rn_code, $rn_d, $rn_rohantwort) = rn_get(
        rn_kca($rn_konto, $rn_vin, 'battery-status', $rn_land, 'v2'),
        $kamereon_api, $rn_jwt, 'Batterie-Status ' . $rn_name . ' (battery-status)');
    rn_antwort_leise(false);
    $rn_kd_art = isset($rn_d['data']['attributes']) ? 'daten'
        : (rn_ist_keine_daten($rn_code, $rn_rohantwort) ? 'notfound' : 'anders');
    $rn_kd_leise = !empty($rn_kd['aktiv']) && $rn_kd_art === 'notfound';

    if (!isset($rn_d['data']['attributes'])) {
        if (!$rn_kd_leise) {
            renault_log('ERROR', 'Batterie-Status ' . $rn_name . ': keine Daten (HTTP ' . $rn_code
                . '). Antwort: ' . substr(preg_replace('/\s+/', ' ', $rn_rohantwort), 0, 400));
        }
        /* 401/403: das Token ist ungueltig geworden (etwa nach einem
         * Passwortwechsel bei Renault). Bis 2.0.6 wurde erst um Mitternacht
         * neu angemeldet - das Plugin stand also bis zum Tageswechsel. */
        if ($rn_code === 401 || $rn_code === 403) {
            /* V1 (Gruppe 40, T2-2/F1): das Konto wird neu ermittelt - aber
             * hoechstens einmal je Stunde (RN_KONTO_NEU_S), kein persons-Abruf
             * je 3-min-Lauf. Bis 2.1.19 blieb die Konto-Kennung fuer immer. */
            $rn_anm_alt = rn_anmeldung_lesen();
            list($rn_anm_neu, $rn_leer) = rn_anmeldung_nach_abweisung($rn_anm_alt, time(), (int) $rn_anm_alt[4]);
            renault_log('WARN', 'Die Anmeldung wird verworfen und beim naechsten Lauf erneuert'
                . ($rn_anm_neu[2] === '' ? '; die Konto-Kennung wird neu ermittelt.' : '.'));
            rn_anmeldung_schreiben($rn_anm_neu);
        }
        // Diagnose bei 404 "no data for this vin and uid".
        if (!$rn_kd_leise && strpos($rn_rohantwort, 'notFound') !== FALSE) {
            list($rn_c2, $rn_d2) = rn_get(RN_BASIS . '/accounts/' . rawurlencode($rn_konto)
                . '/vehicles?country=' . rawurlencode($rn_land), $kamereon_api, $rn_jwt);
            if (isset($rn_d2['vehicleLinks'])) {
                $rn_vins = array();
                foreach ($rn_d2['vehicleLinks'] as $rn_vl) {
                    if (!empty($rn_vl['vin'])) { $rn_vins[] = $rn_vl['vin']; }
                }
                if (!$rn_vins) {
                    $rn_kd_art = 'anders';     // kein Fahrzeug im Konto: Einrichtungsfehler, bleibt laut
                    renault_log('ERROR', 'Diagnose: In Konto ' . $rn_konto . ' ist KEIN Fahrzeug '
                        . 'verknuepft. Fahrzeug in der My-Renault-App diesem Konto hinzufuegen, oder '
                        . 'es wird das falsche Konto verwendet (Zwischenspeicher im Reiter Test leeren).');
                } else {
                    renault_log('WARN', 'Diagnose: Im Konto verknuepfte Fahrgestellnummern: '
                        . implode(', ', $rn_vins) . ' - eingetragen: ' . $rn_vin);
                    if (!in_array($rn_vin, $rn_vins, true)) {
                        $rn_kd_art = 'anders';     // falsche Fahrgestellnummer: Einrichtungsfehler, bleibt laut
                        renault_log('ERROR', 'Diagnose: Die eingetragene Fahrgestellnummer stimmt mit '
                            . 'KEINER im Konto ueberein. Bitte in den Einstellungen berichtigen.');
                    }
                }
            }
        }
    } else {
        $rn_a = $rn_d['data']['attributes'];
        $rn_md5 = md5($rn_rohantwort);
        $rn_erfolg = true;
        $rn_zeit = date_create_from_format(DATE_ISO8601, isset($rn_a['timestamp']) ? $rn_a['timestamp'] : '',
            timezone_open('UTC'));
        if ($rn_zeit === false) { $rn_zeit = date_create('now', timezone_open('UTC')); }
        $rn_utc = date_timestamp_get($rn_zeit);
        $rn_s[30] = (string) $rn_utc;          // Fahrzeugzeit (E-E1 B)
        $rn_wetter_dt = date_format($rn_zeit, 'U');
        $rn_zeit = date_timezone_set($rn_zeit, timezone_open('Europe/Berlin'));
        $rn_s[8]  = date_format($rn_zeit, 'd.m.Y');
        $rn_s[9]  = date_format($rn_zeit, 'H:i');
        $rn_s[10] = isset($rn_a['chargingStatus']) ? $rn_a['chargingStatus'] : '';
        $rn_s[11] = isset($rn_a['plugStatus']) ? $rn_a['plugStatus'] : '';
        $rn_s[12] = isset($rn_a['batteryLevel']) ? $rn_a['batteryLevel'] : '';
        // batteryTemperature/batteryAvailableEnergy liefert die Schnittstelle
        // fuer die meisten Fahrzeuge nicht mehr - deshalb nicht fatal.
        $rn_s[13] = ($rn_ph === '1')
            ? (isset($rn_a['batteryTemperature']) ? $rn_a['batteryTemperature'] : '')
            : (isset($rn_a['batteryAvailableEnergy']) ? $rn_a['batteryAvailableEnergy'] : '');
        $rn_s[14] = isset($rn_a['batteryAutonomy']) ? $rn_a['batteryAutonomy'] : '';
        $rn_s[15] = isset($rn_a['chargingRemainingTime']) ? $rn_a['chargingRemainingTime'] : '';
        $rn_leistung = isset($rn_a['chargingInstantaneousPower']) ? $rn_a['chargingInstantaneousPower'] : '';
        // Zoe Phase 1 meldet Watt, Phase 2 Kilowatt.
        $rn_s[16] = ($rn_ph === '1' && $rn_leistung !== '') ? ($rn_leistung / 1000) : $rn_leistung;
        renault_log('INFO', 'Batterie-Status ' . $rn_name . ' OK: ' . $rn_s[12] . ' %, Reichweite '
            . $rn_s[14] . ' km, Ladestatus ' . $rn_s[10]);

        /* ---- Zusatzabfragen nur, wenn sich etwas geaendert hat ---- */
        if ($rn_md5 !== $rn_s[3]) {

            list($rn_c, $rn_dd) = rn_get(rn_kca($rn_konto, $rn_vin, 'cockpit', $rn_land),
                $kamereon_api, $rn_jwt);
            if (isset($rn_dd['data']['attributes']['totalMileage'])) {
                $rn_s[7] = $rn_dd['data']['attributes']['totalMileage'];
            }

            list($rn_c, $rn_dd) = rn_get(rn_kca($rn_konto, $rn_vin, 'charge-mode', $rn_land),
                $kamereon_api, $rn_jwt);
            /* Scheitert der Nebenabruf, bleibt der letzte Stand stehen, und
             * ChargeMode geht in diesem Lauf nicht hinaus (Befund M4). Bis
             * 2.1.12 wurde daraus "n/a", retained - ein einzelner Fehler
             * ueberschrieb den gemessenen Zustand im Broker (in WSL gemessen,
             * Fall C). "n/a" nur, wenn das Fahrzeug den Endpunkt nachweislich
             * nicht kennt. */
            if (isset($rn_dd['data']['attributes']['chargeMode'])) {
                $rn_s[24] = $rn_dd['data']['attributes']['chargeMode'];
            } elseif (rn_endpunkt_unbekannt($rn_c)) {
                $rn_s[24] = 'n/a';
            } else {
                $rn_cm_senden = false;
            }

            if ($rn_ph !== '1') {
                list($rn_c, $rn_dd) = rn_get(rn_kca($rn_konto, $rn_vin, 'location', $rn_land),
                    $kamereon_api, $rn_jwt);
                if (isset($rn_dd['data']['attributes']['gpsLatitude'])) {
                    $rn_ga = $rn_dd['data']['attributes'];
                    $rn_s[17] = $rn_ga['gpsLatitude'];
                    $rn_s[18] = isset($rn_ga['gpsLongitude']) ? $rn_ga['gpsLongitude'] : '';
                    $rn_gz = isset($rn_ga['lastUpdateTime'])
                        ? date_create_from_format(DATE_ISO8601, $rn_ga['lastUpdateTime'], timezone_open('UTC'))
                        : false;
                    if ($rn_gz !== false) {
                        $rn_s[29] = (string) date_timestamp_get($rn_gz);   // GPS-Zeit (3.1)
                        $rn_gz = date_timezone_set($rn_gz, timezone_open('Europe/Berlin'));
                        $rn_s[19] = date_format($rn_gz, 'd.m.Y');
                        $rn_s[20] = date_format($rn_gz, 'H:i');
                    }
                }
            }

            /* Modellkennung: sie wird ERMITTELT UND ANGEZEIGT, aber nicht
             * ausgewertet. Welche Felder /vehicles/<vin>/details genau
             * fuehrt, ist ausserhalb eines echten Kontos nicht belegbar -
             * und ein Programm, das die Phase anhand eines geratenen
             * Feldnamens selbst umstellt, waere schlimmer als die
             * Auswahlliste. Der Reiter Test zeigt den Wert an; die
             * Phase bleibt eine Eingabe. */
            list($rn_c, $rn_dd, $rn_dr) = rn_get(RN_BASIS . '/accounts/' . rawurlencode($rn_konto)
                . '/vehicles/' . rawurlencode($rn_vin) . '/details?country=' . rawurlencode($rn_land),
                $kamereon_api, $rn_jwt);
            $rn_modell = '';
            foreach (array(
                array('data', 'attributes', 'modelCode'),
                array('data', 'attributes', 'model', 'code'),
                array('modelCode'),
                array('model', 'code'),
            ) as $rn_weg) {
                $rn_x = $rn_dd;
                foreach ($rn_weg as $rn_st) {
                    if (!is_array($rn_x) || !isset($rn_x[$rn_st])) { $rn_x = null; break; }
                    $rn_x = $rn_x[$rn_st];
                }
                if (is_string($rn_x) && $rn_x !== '') { $rn_modell = $rn_x; break; }
            }
            if ($rn_modell !== '' && $rn_modell !== $rn_s[26]) {
                renault_log('INFO', 'Fahrzeug ' . $rn_name . ' meldet die Modellkennung ' . $rn_modell . '.');
            }
            if ($rn_modell !== '') { $rn_s[26] = $rn_modell; }

            /* Wetterdienst nur, wenn ein Schluessel hinterlegt ist UND das
             * Fahrzeug keine eigene Aussentemperatur liefert - siehe unten. */
            if ($rn_ph !== '1' && $rn_cfg['weather_api_key'] !== ''
                && $rn_s[17] !== '' && $rn_s[18] !== '') {
                /* Ueber die Nahtstelle (seit 2.1.20) - dieselben Zeitschranken. */
                list($rn_wc, $rn_wr) = rn_http_senden('GET', 'https://api.openweathermap.org/data/2.5/onecall/timemachine?lat='
                    . rawurlencode($rn_s[17]) . '&lon=' . rawurlencode($rn_s[18])
                    . '&dt=' . rawurlencode($rn_wetter_dt) . '&units=metric&appid='
                    . rawurlencode($rn_cfg['weather_api_key']), array(), null, rn_http_zeitschranke());
                $rn_wc = (int) $rn_wc;
                if ($rn_wr === FALSE || $rn_wc >= 400) {
                    renault_log('WARN', 'Wetterdienst antwortete mit HTTP ' . $rn_wc
                        . '. Hinweis: die hier benutzte Fassung 2.5 von OpenWeatherMap ist '
                        . 'abgekuendigt; die Aussentemperatur liefert bei vielen Fahrzeugen '
                        . 'ohnehin die Fahrzeug-Schnittstelle selbst (Thema OutTemp).');
                } else {
                    $rn_wd = json_decode($rn_wr, TRUE);
                    if (isset($rn_wd['current']['temp'])) {
                        $rn_s[22] = $rn_wd['current']['temp'];
                        $rn_wetter_temp = $rn_s[22];
                    }
                    if (isset($rn_wd['current']['weather'][0]['description'])) {
                        $rn_s[23] = $rn_wd['current']['weather'][0]['description'];
                    }
                }
            }
        }
        $rn_s[3] = $rn_md5;
    }

    /* ---- 2.1.23: Zustand "keine Fahrzeugdaten" fortschreiben ----
     * Beim Wechsel und einmal am Tag zwei Nachfragen, die den Hinweis belegen: steht das Fahrzeug im Konto, antwortet
     * der Kilometerstand? Beides ohne eigene Protokollzeile. */
    $rn_kd_alt = $rn_kd;
    list($rn_kd, $rn_kd_ereignis) = rn_kd_schritt($rn_kd, $rn_kd_art, time());
    if ($rn_kd != $rn_kd_alt) { rn_kd_schreiben($rn_f, $rn_kd); }
    if ($rn_kd_ereignis === 'wechsel' || $rn_kd_ereignis === 'tag') {
        list(, $rn_d3) = rn_get(RN_BASIS . '/accounts/' . rawurlencode($rn_konto) . '/vehicles?country=' . rawurlencode($rn_land),
            $kamereon_api, $rn_jwt);
        $rn_kd_konto = 'unbekannt';
        if (isset($rn_d3['vehicleLinks']) && is_array($rn_d3['vehicleLinks'])) {
            $rn_kd_konto = 'nein';
            foreach ($rn_d3['vehicleLinks'] as $rn_vl) {
                if (isset($rn_vl['vin']) && $rn_vl['vin'] === $rn_vin) { $rn_kd_konto = 'ja'; }
            }
        }
        list($rn_kd_c) = rn_get(rn_kca($rn_konto, $rn_vin, 'cockpit', $rn_land), $kamereon_api, $rn_jwt);
        renault_log($rn_kd_ereignis === 'wechsel' ? 'WARN' : 'INFO', rn_kd_satz($rn_name, $rn_kd, $rn_kd_konto, $rn_kd_c));
    } elseif ($rn_kd_ereignis === 'zurueck') {
        renault_log('INFO', 'Renault liefert fuer ' . $rn_name . ' wieder Batteriedaten (ohne Daten seit '
            . date('d.m. H:i', (int) $rn_kd_alt['seit']) . '). Abruf und Ladehistorie wieder im normalen Takt.');
    }

    /* ---- Vorklimatisierung: Zustand und Temperaturen ----
     *
     * hvac-status liefert neben hvacStatus auch externalTemperature und bei
     * einem Teil der Fahrzeuge internalTemperature. Bis 2.0.6 wurde nur der
     * erste Wert gelesen und die Aussentemperatur stattdessen bei einem
     * Wetterdienst geholt - ein zweiter Dienst mit eigenem Schluessel fuer
     * einen Wert, den das Fahrzeug selbst kennt. */
    /* null = in diesem Lauf nicht senden; der retained Stand im Broker bleibt
     * der zuletzt gemessene (Befund M4). Bis 2.1.12 war 'n/a' die Vorgabe:
     * ein einzelner misslungener hvac-status-Abruf ueberschrieb den Zustand
     * im Broker, und HvAcStatus ("n/a") und HvAcStatusBin (1) widersprachen
     * sich (in WSL gemessen, Fall H). "n/a" gibt es nur noch, wenn das
     * Fahrzeug den Endpunkt nachweislich nicht kennt - dann fuer Text UND
     * Zahl. */
    $rn_hvac = null;
    if ($rn_erfolg) {
        list($rn_c, $rn_dd) = rn_get(rn_kca($rn_konto, $rn_vin, 'hvac-status', $rn_land),
            $kamereon_api, $rn_jwt);
        if (isset($rn_dd['data']['attributes'])) {
            $rn_ha = $rn_dd['data']['attributes'];
            if (isset($rn_ha['hvacStatus'])) { $rn_hvac = $rn_ha['hvacStatus']; $rn_s[31] = (string) $rn_hvac; }
            if (isset($rn_ha['internalTemperature'])) { $rn_s[27] = $rn_ha['internalTemperature']; }
            if (isset($rn_ha['externalTemperature'])) { $rn_s[28] = $rn_ha['externalTemperature']; }
        } elseif (rn_endpunkt_unbekannt($rn_c)) {
            $rn_hvac = 'n/a';
        }
    }

    /* ---- Ladeziel setzen, wenn hinterlegt (leer = nicht anfassen) ----
     *
     * Nur die neueren Plattformen (Megane E-Tech, R5, R4, A290, Master)
     * fuehren ev/soc-levels. Bei allen anderen antwortet der Endpunkt mit
     * einem Fehler; dann wird nichts geschrieben und einmal gewarnt.
     * Geschrieben wird ausserdem nur, wenn der Wert vom gewuenschten
     * abweicht - sonst stuende bei jedem Abruf ein Schreibzugriff. */
    if ($rn_erfolg && ($rn_cfg['soc_min'] !== '' || $rn_cfg['soc_target'] !== '')) {
        list($rn_c, $rn_dd) = rn_get(rn_kcm($rn_konto, $rn_vin, 'ev/soc-levels', $rn_land),
            $kamereon_api, $rn_jwt);
        if (!isset($rn_dd['data']['attributes'])) {
            renault_log('WARN', 'Ladeziel: ' . $rn_name . ' kennt ev/soc-levels nicht (HTTP ' . $rn_c
                . '). Das koennen nur die neueren Plattformen; der Wert bleibt unbeachtet.');
        } else {
            $rn_sa = $rn_dd['data']['attributes'];
            $rn_neu = array();
            if ($rn_cfg['soc_min'] !== '' && (string) (isset($rn_sa['socMin']) ? $rn_sa['socMin'] : '') !== (string) (int) $rn_cfg['soc_min']) {
                $rn_neu['socMin'] = (int) $rn_cfg['soc_min'];
            }
            if ($rn_cfg['soc_target'] !== '' && (string) (isset($rn_sa['socTarget']) ? $rn_sa['socTarget'] : '') !== (string) (int) $rn_cfg['soc_target']) {
                $rn_neu['socTarget'] = (int) $rn_cfg['soc_target'];
            }
            if ($rn_neu) {
                list($rn_c) = rn_post(rn_kcm($rn_konto, $rn_vin, 'ev/soc-levels', $rn_land),
                    $kamereon_api, $rn_jwt,
                    json_encode(array('data' => array('type' => 'SocLevels', 'attributes' => $rn_neu))),
                    'Ladeziel setzen ' . $rn_name . ' (ev/soc-levels)');
                renault_log($rn_c < 400 ? 'INFO' : 'ERROR', 'Ladeziel ' . $rn_name . ': '
                    . json_encode($rn_neu) . ' -> HTTP ' . $rn_c);
            }
        }
    }

    /* ---- Meldungen ---- */
    $rn_schwelle = (int) $rn_cfg['bl_schwelle'];
    /* signal_bl gehoert in die Bedingung (3.3): sonst wuerde die Meldung bei nur
     * eingeschaltetem SignalBot gar nicht berechnet. */
    if ($rn_erfolg && ($rn_cfg['mail_bl'] === 'Y' || $rn_cfg['cmon_bl'] === 'Y' || $rn_cfg['exec_bl'] !== ''
                       || $rn_cfg['signal_bl'] === 'Y')) {
        if ($rn_s[12] !== '' && $rn_s[12] >= $rn_schwelle && $rn_s[10] == 1 && $rn_s[5] !== 'Y') {
            $rn_rest = ($rn_s[15] !== '') ? $rn_s[15] : rn_t('MELDUNG.WENIGE');
            $rn_text = rn_t('MELDUNG.AKKUSTAND_ERREICHT') . ' (' . $rn_name . ')' . "\n"
                     . rn_t('MELDUNG.AKKUSTAND') . ': ' . $rn_s[12] . ' %' . "\n"
                     . rn_t('MELDUNG.RESTLADEZEIT') . ': ' . $rn_rest . ' ' . rn_t('MELDUNG.MINUTEN') . "\n"
                     . rn_t('MELDUNG.REICHWEITE') . ': ' . $rn_s[14] . ' km' . "\n"
                     . rn_t('MELDUNG.STATUSUPDATE') . ': ' . $rn_s[8] . ' ' . $rn_s[9];
            /* Erst den Merker "gesendet" festhalten, dann ausloesen (Befund C3).
             * Laesst er sich nicht schreiben, wird in diesem Lauf NICHTS
             * ausgeloest: der naechste Lauf wuesste nicht, dass schon gemeldet
             * wurde, und jeder weitere loeste erneut aus. */
            $rn_s[5] = 'Y';
            if (!rn_session_schreiben($rn_f['session'], $rn_s)) {
                $rn_s[5] = 'N';
                rn_melden_stuendlich('ERROR', $rn_sess_fehl);
            } else {
                /* V7-i (F4): das Ergebnis von mail() zaehlt - bis 2.1.19 hiess es
                 * "Meldung ausgeloest", auch wenn sendmail fehlte. */
                $rn_wege = array('mail' => null, 'signal' => null, 'befehl' => null);
                if ($rn_cfg['mail_bl'] === 'Y') { $rn_wege['mail'] = (bool) @mail($rn_cfg['username'], $rn_name, $rn_text); }
                if ($rn_cfg['signal_bl'] === 'Y') { $rn_wege['signal'] = rn_signal_senden($rn_mqtt, $rn_cfg, $rn_text); }
                if ($rn_cfg['cmon_bl'] === 'Y') {
                    list($rn_c) = rn_post(rn_kca($rn_konto, $rn_vin, 'actions/charge-mode', $rn_land),
                        $kamereon_api, $rn_jwt,
                        '{"data":{"type":"ChargeMode","attributes":{"action":"schedule_mode"}}}',
                        'Ladeplan bei erreichtem Akkustand (charge-mode)');
                }
                /* Gepruefter Aufruf, siehe rn_hook_ausfuehren(): der Befehl ist
                 * eine bewusste Eingabe des Betreibers - das heisst aber nicht,
                 * dass ein Semikolon darin harmlos waere. */
                if (trim((string) $rn_cfg['exec_bl']) !== '') {
                    $rn_wege['befehl'] = rn_hook_ausfuehren($rn_cfg['exec_bl'], $rn_text, 'exec_bl');
                }
                list($rn_ws, $rn_wt) = rn_meldewege_text($rn_wege);
                renault_log($rn_ws, 'Akkustand ' . $rn_schwelle . ' % erreicht (' . $rn_name . ') - ' . $rn_wt . '.');
            }
        } elseif ($rn_s[5] === 'Y' && $rn_s[10] != 1) {
            $rn_s[5] = 'N';
        }
    }
    if ($rn_erfolg && ($rn_cfg['mail_csf'] === 'Y' || $rn_cfg['exec_csf'] !== '' || $rn_cfg['signal_csf'] === 'Y')) {
        if ($rn_s[6] === 'Y' && $rn_s[10] != 1) {
            $rn_text = rn_t('MELDUNG.LADEN_BEENDET') . ' (' . $rn_name . ')' . "\n"
                     . rn_t('MELDUNG.AKKUSTAND') . ': ' . $rn_s[12] . ' %' . "\n"
                     . rn_t('MELDUNG.REICHWEITE') . ': ' . $rn_s[14] . ' km' . "\n"
                     . rn_t('MELDUNG.STATUSUPDATE') . ': ' . $rn_s[8] . ' ' . $rn_s[9];
            /* Wie oben: erst den Merker (Feld 6 auf N) festhalten (Befund C3). */
            $rn_s[6] = 'N';
            if (!rn_session_schreiben($rn_f['session'], $rn_s)) {
                $rn_s[6] = 'Y';
                rn_melden_stuendlich('ERROR', $rn_sess_fehl);
            } else {
                $rn_wege = array('mail' => null, 'signal' => null, 'befehl' => null);
                if ($rn_cfg['mail_csf'] === 'Y') { $rn_wege['mail'] = (bool) @mail($rn_cfg['username'], $rn_name, $rn_text); }
                if ($rn_cfg['signal_csf'] === 'Y') { $rn_wege['signal'] = rn_signal_senden($rn_mqtt, $rn_cfg, $rn_text); }
                // Bis 1.4 stand hier $exec_bl, obwohl die Bedingung $exec_csf prueft.
                if (trim((string) $rn_cfg['exec_csf']) !== '') {
                    $rn_wege['befehl'] = rn_hook_ausfuehren($rn_cfg['exec_csf'], $rn_text, 'exec_csf');
                }
                list($rn_ws, $rn_wt) = rn_meldewege_text($rn_wege);
                renault_log($rn_ws, 'Ladung beendet (' . $rn_name . ') - ' . $rn_wt . '.');
            }
        }
        $rn_s[6] = ($rn_s[10] == 1) ? 'Y' : 'N';
    }

    /* ---- Sprachausgabe (Nr. 36 b, seit 2.1.16) ----
     * Nach den Meldungen und vor dem Zwischenspeicher: der eigene Stand liegt in
     * ansage_stand.json. Ins Protokoll kommen Anlass, Fahrzeugnummer und die Kurzform
     * des Ergebnisses - nie der Text, nie ein Token. Ein Fehler hier haelt den Abruf
     * nicht auf. */
    try {
        /* 2.1.23: das 404 notFound zaehlt nicht als Ausfall - keine Ansage dafuer (rn_kd_schritt()). */
        foreach (($rn_kd_art === 'notfound' ? array() : rn_ansage_lauf($rn_cfg, $rn_f, $rn_erfolg, $rn_s)) as $rn_an) {
            renault_log($rn_an['stufe'], 'Ansage ' . $rn_an['anlass'] . ' (Fahrzeug ' . (int) $rn_f['nr']
                . '): ' . $rn_an['kurz']);
        }
    } catch (\Throwable $rn_ae) {
        renault_log('ERROR', 'Ansage: ' . get_class($rn_ae));
    }

    /* ---- Aufzeichnung ---- */
    if ($rn_erfolg && $rn_cfg['save_in_db'] === 'Y') {
        $rn_csv = $rn_f['csv'];
        if (!file_exists($rn_csv)) {
            @file_put_contents($rn_csv, 'Date;Time;Mileage;Battery level;Battery capacity;Range;'
                . 'Cable status;Charging status;Charging speed;Remaining charging time;'
                . 'GPS Latitude;GPS Longitude;GPS date;GPS time;Outside temperature;'
                . 'Weather condition;Charging schedule' . "\n");
        }
        /* V3 (Gruppe 40, T2-4): eine Zeile nur, wenn sich der Stand des
         * Fahrzeugs aendert (Zeitstempel, Kilometer, Ladestand, Reichweite,
         * Kabel, Ladestatus). Bis 2.1.19 entstand bei jedem gelungenen Abruf
         * eine - bis 720 je Tag mit denselben Werten. Gelesen werden nur die
         * letzten 4096 Byte (rn_csv_letzte_zeile()). */
        $rn_zeile = array(
            $rn_s[8], $rn_s[9], $rn_s[7], $rn_s[12], $rn_s[13], $rn_s[14], $rn_s[11], $rn_s[10],
            $rn_s[16], $rn_s[15], $rn_s[17], $rn_s[18], $rn_s[19], $rn_s[20],
            ($rn_s[28] !== '' ? $rn_s[28] : $rn_s[22]), $rn_s[23], $rn_s[24],
        );
        if (rn_csv_neu(rn_csv_letzte_zeile($rn_csv), $rn_zeile)) {
            @file_put_contents($rn_csv, implode(';', $rn_zeile) . "\n", FILE_APPEND);
        }
    }

    /* ---- ABRP ---- */
    /* V7-d (T2-6): ABRP gilt nur fuer Fahrzeug 1 - es gibt EIN Token. Bis
     * 2.1.19 ging jeder Lauf mit dem Stand jedes Fahrzeugs an dasselbe Token,
     * und ABRP sah zwei Autos abwechselnd. */
    if ($rn_erfolg && (int) $rn_f['nr'] === 1 && $rn_cfg['abrp_token'] !== '' && $rn_cfg['abrp_model'] !== '') {
        $rn_tlm = urlencode(json_encode(array(
            'car_model'   => $rn_cfg['abrp_model'],
            'utc'         => $rn_utc,
            'soc'         => $rn_s[12] === '' ? 0 : (float) $rn_s[12],
            'odometer'    => $rn_s[7] === '' ? 0 : (float) $rn_s[7],
            'is_charging' => ($rn_s[10] == 1) ? 1 : 0,
        )));
        list($rn_ac, $rn_ar) = rn_http_senden('GET', 'https://api.iternio.com/1/tlm/send?api_key=fd99255b-91a0-45cd-9df5-d6baa8e50ef8&token='
            . rawurlencode($rn_cfg['abrp_token']) . '&tlm=' . $rn_tlm, array(), null, rn_http_zeitschranke());
        $rn_ac = (int) $rn_ac;
        if ($rn_ar === FALSE || $rn_ac >= 400) {
            renault_log('WARN', 'ABRP antwortete mit HTTP ' . $rn_ac . ' - Telemetrie nicht uebernommen.');
        }
    }

    /* ==============================================================
     * MQTT
     *
     * Bei einem FEHLGESCHLAGENEN Abruf wird nur "ok" gesendet. Alle
     * uebrigen Themen behalten ihren zurueckbehaltenen Wert - und der
     * Zeitstempel bleibt stehen, damit die Ausfallerkennung in Loxone
     * ueberhaupt ansprechen kann. Bis 2.0.6 wurden auch im Fehlerfall
     * alle alten Werte samt frischem Zeitstempel erneut gesendet; das
     * sah in Loxone aus wie ein gesunder Abruf.
     * ============================================================== */
    /* Altwerte frueher zurueckbehaltener Themen: "ok" bis 2.1.10, die
     * Messwerte bis 2.1.5 (rn_mqtt_frueher_behalten()). Der Broker wird
     * gefragt, was noch dasteht; genau das geht unmittelbar vor seinem
     * gueltigen Wert als leere retain-Nutzlast hinaus. Der Merker entsteht
     * erst, wenn der Broker bestaetigt, dass nichts mehr dasteht. */
    if ($rn_mqtt !== null) {
        $rn_alt = rn_mqtt_altlast('abruf', $rn_name);
        rn_altlast_faellig($rn_name, '', $rn_alt['themen']);
    }

    if ($rn_erfolg) {
        $rn_s[25] = $rn_jetzt;
        $rn_irgendwas_ok = true;

        /* 3.1 Heimzone (E-E1 B) und E-E2 Ladekosten. Die Heimkoordinaten gehen
         * nie ins Protokoll, nie an MQTT. */
        list($rn_zh, $rn_ent) = rn_zuhause($rn_cfg, $rn_s[17], $rn_s[18], $rn_s[29], $rn_s[30], $rn_ph);
        $rn_ladung = rn_ladung_fortschreiben($rn_cfg, (int) $rn_f['nr'], $rn_s[10] == 1, (int) $rn_s[30],
            $rn_s[12], $rn_ph !== '1' ? $rn_s[13] : null, $rn_zh);
        if (is_array($rn_ladung)) {
            renault_log('INFO', 'Ladung beendet (' . $rn_name . '): ' . ($rn_ladung['kwh'] >= 0
                ? round($rn_ladung['kwh'], 2) . ' kWh in den Akku' : 'Energie nicht bestimmbar') . ', Kosten '
                . ($rn_ladung['kosten_eur'] >= 0 ? number_format($rn_ladung['kosten_eur'], 2, ',', '') . ' EUR'
                   : 'ohne Aussage') . ' (' . $rn_ladung['kosten_grund'] . ').');
        }

        rn_sende($rn_mqtt, $rn_name, 'BattSOC',        $rn_s[12]);
        rn_sende($rn_mqtt, $rn_name, 'Range',          $rn_s[14]);
        rn_sende($rn_mqtt, $rn_name, 'ChargingStatus', $rn_s[10]);
        rn_sende($rn_mqtt, $rn_name, 'CableStatus',    $rn_s[11]);
        rn_sende($rn_mqtt, $rn_name, 'ChargingTime',   $rn_s[15]);
        rn_sende($rn_mqtt, $rn_name, 'ChargingEffekt', $rn_s[16]);
        if ($rn_cm_senden) {
            rn_sende($rn_mqtt, $rn_name, 'ChargeMode',     $rn_s[24]);
        }
        rn_sende($rn_mqtt, $rn_name, 'Mileage',        $rn_s[7]);
        rn_sende($rn_mqtt, $rn_name, 'Name',           $rn_name);
        if ($rn_hvac !== null) {
            rn_sende($rn_mqtt, $rn_name, 'HvAcStatus',     $rn_hvac);
            if ($rn_hvac === 'on' || $rn_hvac === 'off') {
                rn_sende($rn_mqtt, $rn_name, 'HvAcStatusBin', $rn_hvac === 'on' ? 1 : 0);
            } elseif ($rn_hvac === 'n/a') {
                // Text und Zahl gemeinsam: unbekannt heisst "-", nicht der alte Stand.
                rn_sende($rn_mqtt, $rn_name, 'HvAcStatusBin', '-');
            }
        }
        rn_sende($rn_mqtt, $rn_name, 'Zuhause',    $rn_zh);
        rn_sende($rn_mqtt, $rn_name, 'Entfernung', $rn_ent);
        rn_sende($rn_mqtt, $rn_name, 'Vorklima',   rn_vorklima_feld($rn_cfg, (int) $rn_f['nr']));
        /* 2.1.22 (Fremdpruefung 2A): die letzte Ladung geht bei JEDEM gelungenen Abruf hinaus, ohne
         * eingetragene Ladung je -1 (rn_ladung_letzte(); wie MGiSmart 1.1.28 ladung_kosten/kwh/preis).
         * Bis 2.1.21 nur im Abruf, in dem eine Ladung endete - ohne Strompreis-Haken nie: die drei
         * Datenpunkte erschienen im Gateway V2 nicht, und der Selbsttest nannte sie als fehlende Haken.
         * Retained bleibt (Zustand, rn_thema_retained()); -1 ist kein leerer Wert. */
        $rn_lz = is_array($rn_ladung) ? $rn_ladung : rn_ladung_letzte((int) $rn_f['nr']);
        rn_sende($rn_mqtt, $rn_name, 'LadungKosten',  $rn_lz['kosten_eur']);
        rn_sende($rn_mqtt, $rn_name, 'LadungEnergie', $rn_lz['kwh']);
        rn_sende($rn_mqtt, $rn_name, 'LadungPreis',   $rn_lz['preis_ct']);
        rn_sende($rn_mqtt, $rn_name, 'InTemp',  $rn_s[27]);
        // Aussentemperatur: die des Fahrzeugs hat Vorrang vor der des
        // Wetterdienstes - beide nur aus diesem Lauf (Befund M5).
        rn_sende($rn_mqtt, $rn_name, 'OutTemp', $rn_s[28] !== '' ? $rn_s[28] : $rn_wetter_temp);

        if ($rn_ph === '1') {
            rn_sende($rn_mqtt, $rn_name, 'BatTemp',       $rn_s[13]);
            rn_sende($rn_mqtt, $rn_name, 'RenaultPHMode', '1');
        } else {
            rn_sende($rn_mqtt, $rn_name, 'GPS-Latitude',   $rn_s[17]);
            rn_sende($rn_mqtt, $rn_name, 'GPS-Longitude',  $rn_s[18]);
            rn_sende($rn_mqtt, $rn_name, 'GPSTime',        $rn_s[20]);
            rn_sende($rn_mqtt, $rn_name, 'EnergieOnBoard', $rn_s[13]);
            rn_sende($rn_mqtt, $rn_name, 'RenaultPHMode',  '2');
        }
        /* GPS-Latitude_1..3 sind mit 2.1.0 entfallen. Sie entstanden aus
         * str_split($wert, 5) und lasen anschliessend drei Teile - bei
         * ueblicher Genauigkeit gibt es aber nur zwei, und der dritte
         * Zugriff erzeugte bei jedem Abruf eine PHP-Warnung, die im
         * Cron-Lauf in die Klartextausgabe wanderte. Wer die Zerlegung in
         * Loxone braucht, macht sie dort - der volle Wert steht unter
         * GPS-Latitude bereit. */

        $rn_hhmm = substr($rn_s[25], 8, 4);
        rn_sende($rn_mqtt, $rn_name, 'phpCall',           $rn_hhmm);
        rn_sende($rn_mqtt, $rn_name, 'LastDataRetrieval', $rn_hhmm);
        rn_sende($rn_mqtt, $rn_name, 'ok', 1);
        /* Altwerte, die in diesem Lauf keinen gueltigen Wert bekamen: jetzt
         * direkt loeschen und nachlesen (Befund M1). Nur im vollen Lauf. */
        if ($rn_mqtt !== null) { rn_mqtt_altlast_abschluss('abruf', $rn_name, $rn_alt); }
        rn_lebenszeichen($rn_mqtt, $rn_name, dirname($rn_f['session']));
        $rn_ausgabe[] = $rn_name . ';OK=1;SOC=' . $rn_s[12] . ';RANGE=' . $rn_s[14];
    } else {
        /* Bei einer Stoerung geht nur das Signal hinaus, nicht die Werte:
         * die Werte im Broker sind das Letzte, was wirklich gemessen wurde.
         * Das Lebenszeichen gehoert trotzdem dazu - sonst ist ein toter
         * Cron von einem gestoerten Abruf nicht zu unterscheiden. */
        rn_sende($rn_mqtt, $rn_name, 'ok', 0);
        rn_lebenszeichen($rn_mqtt, $rn_name, dirname($rn_f['session']));
        /* 502 nur ohne Befehl. Nach einem Befehl gilt dessen Ergebnis (oben);
         * dass der Abruf danach scheiterte, sagen die eigene Zeile OK=0 und
         * die Protokollzeile des Endpunkts (Befund C2). */
        if ($rn_befehl_ok === null) { $rn_http_status = 502; }
        $rn_abruf_gescheitert = true;
        $rn_ausgabe[] = $rn_name . ';OK=0';
    }

    /* ---- Zwischenspeicher schreiben ----
     * Unteilbar und geprueft; bis 2.1.12 ein @file_put_contents() ohne
     * Rueckgabepruefung (Befund C3). Hoechstens eine Zeile je Stunde. */
    if (!rn_session_schreiben($rn_f['session'], $rn_s)) {
        rn_melden_stuendlich('ERROR', $rn_sess_fehl);
    }
}

if ($rn_mqtt !== null) { $rn_mqtt->close(); }

if (!$rn_ausgabe) { $rn_ausgabe[] = 'NOTHING TO DO'; }
echo implode("\n", $rn_ausgabe) . "\n";
