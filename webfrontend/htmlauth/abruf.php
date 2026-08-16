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
 */

require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once 'phpMQTT/phpMQTT.php';

require_once __DIR__ . '/rn_lib.php';
require_once __DIR__ . '/logger.php';
require 'api-keys.php';

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

if (isset($rn_auftrag) && is_array($rn_auftrag)) {
    $rn_aktion     = isset($rn_auftrag['aktion']) ? (string) $rn_auftrag['aktion'] : '';
    $rn_fahrzeugnr = isset($rn_auftrag['fahrzeug']) ? (int) $rn_auftrag['fahrzeug'] : 1;
} elseif (isset($argv[1])) {
    if ($argv[1] === 'cron') { $rn_ist_cron = true; }
    else { $rn_aktion = (string) $argv[1]; }
    if (isset($argv[2])) { $rn_fahrzeugnr = (int) $argv[2]; }
}
header('Content-Type: text/plain; charset=utf-8');

$rn_cfg   = rn_config_read();
$rn_autos = rn_fahrzeuge($rn_cfg);

/* ==================================================================
 * Fail closed: ohne Zugangsdaten wird nichts versucht
 * ================================================================== */
if ($rn_cfg['username'] === '' || $rn_cfg['password'] === '') {
    renault_log('ERROR', 'Zugangsdaten unvollstaendig (Benutzer oder Passwort leer) - '
        . 'bitte im Plugin die Einstellungen ausfuellen. Es wird nichts an Renault gesendet.');
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
    echo "NO VIN\n";
    return;
}

/* ==================================================================
 * Hilfsmittel
 * ================================================================== */

/** Ein GET an die Kamereon-Schnittstelle. Gibt array(httpcode, daten, roh). */
function rn_get($url, $kamereon_api, $jwt, $name = '')
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'apikey: ' . $kamereon_api,
        'x-gigya-id_token: ' . $jwt,
    ));
    $antwort = curl_exec($ch);
    if ($name !== '') { renault_log_api($name, $ch, $antwort); }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($antwort === FALSE) { return array(0, null, ''); }
    return array($code, json_decode($antwort, TRUE), $antwort);
}

/** Ein POST an die Kamereon-Schnittstelle. Gibt array(httpcode, daten, roh). */
function rn_post($url, $kamereon_api, $jwt, $json, $name = '')
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-type: application/vnd.api+json',
        'apikey: ' . $kamereon_api,
        'x-gigya-id_token: ' . $jwt,
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    $antwort = curl_exec($ch);
    if ($name !== '') { renault_log_api($name, $ch, $antwort); }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($antwort === FALSE) { return array(0, null, ''); }
    return array($code, json_decode($antwort, TRUE), $antwort);
}

define('RN_BASIS', 'https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1');

/** Der Pfad zum Fahrzeug in der kca-Welt. */
function rn_kca($konto, $vin, $rest, $land, $v = 'v1')
{
    return RN_BASIS . '/accounts/' . rawurlencode($konto) . '/kamereon/kca/car-adapter/'
         . $v . '/cars/' . rawurlencode($vin) . '/' . $rest . '?country=' . rawurlencode($land);
}

/** Der Pfad zum Fahrzeug in der kcm-Welt (neuere Plattformen). */
function rn_kcm($konto, $vin, $rest, $land)
{
    return RN_BASIS . '/accounts/' . rawurlencode($konto) . '/kamereon/kcm/v1/vehicles/'
         . rawurlencode($vin) . '/' . $rest . '?country=' . rawurlencode($land);
}

/* ==================================================================
 * Anmeldung - einmal je Tag, gemeinsam fuer alle Fahrzeuge
 * ================================================================== */
$rn_anm = rn_anmeldung_lesen();          // 0 Datum, 1 JWT, 2 Konto, 3 personId
$rn_heute = date('md');
$rn_jetzt = date('YmdHi');

if ($rn_anm[1] === '' || $rn_anm[0] !== $rn_heute) {
    $rn_ch = curl_init('https://accounts.eu1.gigya.com/accounts.login');
    curl_setopt($rn_ch, CURLOPT_POST, TRUE);
    curl_setopt($rn_ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($rn_ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($rn_ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($rn_ch, CURLOPT_POSTFIELDS, array(
        'ApiKey'            => $gigya_api,
        'loginId'           => $rn_cfg['username'],
        'password'          => $rn_cfg['password'],
        'include'           => 'data',
        'sessionExpiration' => 60,
    ));
    $rn_r = curl_exec($rn_ch);
    renault_log_api('Gigya Login (accounts.login)', $rn_ch, $rn_r);
    curl_close($rn_ch);
    if ($rn_r === FALSE) {
        renault_log('ERROR', 'Gigya Login: keine Antwort (Zeitueberschreitung oder Netzfehler).');
        echo "LOGIN FAILED\n";
        return;
    }
    $rn_d = json_decode($rn_r, TRUE);
    if (isset($rn_d['errorCode']) && $rn_d['errorCode'] != 0) {
        renault_log('ERROR', 'Gigya Login fehlgeschlagen: errorCode=' . $rn_d['errorCode']
            . ' (' . (isset($rn_d['errorDetails']) ? $rn_d['errorDetails'] : '') . ') - '
            . 'Benutzername und Passwort pruefen.');
    }
    $rn_person = isset($rn_d['data']['personId']) ? $rn_d['data']['personId'] : '';
    $rn_oauth  = isset($rn_d['sessionInfo']['cookieValue']) ? $rn_d['sessionInfo']['cookieValue'] : '';
    if ($rn_oauth === '') {
        renault_log('ERROR', 'Gigya Login: kein Sitzungswert (cookieValue) erhalten - Anmeldung abgebrochen.');
        echo "LOGIN FAILED\n";
        return;
    }

    $rn_ch = curl_init('https://accounts.eu1.gigya.com/accounts.getJWT');
    curl_setopt($rn_ch, CURLOPT_POST, TRUE);
    curl_setopt($rn_ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($rn_ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($rn_ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($rn_ch, CURLOPT_POSTFIELDS, array(
        'login_token' => $rn_oauth,
        'ApiKey'      => $gigya_api,
        'fields'      => 'data.personId,data.gigyaDataCenter',
        'expiration'  => 87000,
    ));
    $rn_r = curl_exec($rn_ch);
    renault_log_api('Gigya JWT (accounts.getJWT)', $rn_ch, $rn_r);
    curl_close($rn_ch);
    $rn_d = ($rn_r === FALSE) ? null : json_decode($rn_r, TRUE);
    if (empty($rn_d['id_token'])) {
        // Einen fehlgeschlagenen Login NICHT bis Mitternacht zwischenspeichern.
        renault_log('ERROR', 'Gigya JWT: kein id_token erhalten. Antwort: '
            . substr(preg_replace('/\s+/', ' ', (string) $rn_r), 0, 300));
        rn_anmeldung_schreiben(array('0000', '', $rn_anm[2], $rn_person));
        echo "LOGIN FAILED\n";
        return;
    }
    renault_log('INFO', 'Gigya JWT Token erfolgreich erhalten.');
    $rn_anm = array($rn_heute, $rn_d['id_token'], $rn_anm[2], $rn_person);
    rn_anmeldung_schreiben($rn_anm);
}

/* ---- Kamereon-Konto, falls noch keines bekannt ist ----
 *
 * Bis 2.0.6 stand die personId nur im Anmeldeblock. War die Kontoabfrage
 * einmal fehlgeschlagen, das JWT aber gueltig, lief die Abfrage fuer den
 * Rest des Tages gegen /persons/?country=DE - also ohne Person. Die
 * personId steht deshalb jetzt mit in der Anmeldedatei. */
if ($rn_anm[2] === '') {
    if ($rn_anm[3] === '') {
        renault_log('ERROR', 'Kein Kamereon-Konto und keine personId bekannt - '
            . 'die Anmeldung wird beim naechsten Lauf wiederholt.');
        rn_anmeldung_schreiben(array('0000', '', '', ''));
        echo "NO ACCOUNT\n";
        return;
    }
    list($rn_code, $rn_d) = rn_get(RN_BASIS . '/persons/' . rawurlencode($rn_anm[3])
        . '?country=' . rawurlencode($rn_cfg['country']),
        $kamereon_api, $rn_anm[1], 'Kamereon Konto (persons)');
    $rn_konten = isset($rn_d['accounts']) ? $rn_d['accounts'] : null;
    if (is_array($rn_konten)) {
        foreach ($rn_konten as $rn_k) {
            renault_log('INFO', 'Renault-Konto gefunden: ' . (isset($rn_k['accountId']) ? $rn_k['accountId'] : '?')
                . ' (Typ: ' . (isset($rn_k['accountType']) ? $rn_k['accountType'] : '?')
                . ', Status: ' . (isset($rn_k['accountStatus']) ? $rn_k['accountStatus'] : '?') . ')');
        }
        // Das MYRENAULT-Konto bevorzugen - manche Nutzer haben ein zweites
        // (etwa SFDC), das das Fahrzeug nicht kennt und mit 404
        // "no data for this vin and uid" antwortet.
        foreach ($rn_konten as $rn_k) {
            if (isset($rn_k['accountType']) && $rn_k['accountType'] === 'MYRENAULT') {
                $rn_anm[2] = $rn_k['accountId']; break;
            }
        }
        if ($rn_anm[2] === '' && isset($rn_konten[0]['accountId'])) {
            $rn_anm[2] = $rn_konten[0]['accountId'];
        }
    }
    if ($rn_anm[2] === '') {
        renault_log('ERROR', 'Keine Konto-Kennung erhalten (HTTP ' . $rn_code . ').');
        echo "NO ACCOUNT\n";
        return;
    }
    renault_log('INFO', 'Verwende Konto-Kennung: ' . $rn_anm[2]);
    rn_anmeldung_schreiben($rn_anm);
}

$rn_konto = $rn_anm[2];
$rn_jwt   = $rn_anm[1];
$rn_land  = $rn_cfg['country'];

/* ==================================================================
 * BEFEHLE - vor der Abrufbremse, siehe Kopf dieser Datei
 * ================================================================== */
$rn_ausgabe = array();
$rn_befehl_ok = null;

if ($rn_aktion !== '' && $rn_aktion !== 'abruf') {
    $rn_ziel = rn_fahrzeug($rn_fahrzeugnr, $rn_cfg);
    if (!$rn_ziel || $rn_ziel['vin'] === '') {
        renault_log('ERROR', 'Befehl ' . $rn_aktion . ': Fahrzeug ' . $rn_fahrzeugnr . ' ist nicht eingerichtet.');
        echo "UNKNOWN VEHICLE\n";
        return;
    }
    /* Schaltende Befehle nur, wenn die Steuerung eingeschaltet ist.
     * Ab Werk steht sie aus - ein Plugin, das sofort nach der
     * Installation die Vorklimatisierung starten kann, waere ein
     * Vorgabewert, der ungefragt schaltet. */
    if (rn_befehl_schaltet($rn_aktion) && $rn_cfg['steuerung_ein'] !== 'Y') {
        renault_log('WARN', 'Befehl ' . $rn_aktion . ' abgewiesen: die Steuerung ist in den '
            . 'Einstellungen ausgeschaltet (Vorgabe). Wer aus Loxone schalten will, schaltet sie dort ein.');
        echo "STEUERUNG AUS\n";
        return;
    }

    $rn_vin = $rn_ziel['vin'];
    $rn_temp = (int) $rn_cfg['ac_temp'];
    if ($rn_temp < 16 || $rn_temp > 30) { $rn_temp = 21; }

    switch ($rn_aktion) {
        case 'acnow':
            list($rn_code) = rn_post(rn_kca($rn_konto, $rn_vin, 'actions/hvac-start', $rn_land),
                $kamereon_api, $rn_jwt,
                '{"data":{"type":"HvacStart","attributes":{"action":"start","targetTemperature":"' . $rn_temp . '"}}}',
                'Vorklimatisierung starten (hvac-start)');
            break;
        case 'acoff':
            /* "cancel" ist die Form, die die Renault-Schnittstelle fuer die
             * meisten Fahrzeuge fuehrt; einzelne (A290, R5, Spring) kennen
             * stattdessen "stop". Antwortet die erste Form mit einem
             * Fehlercode, wird die zweite versucht - das kostet einen
             * zusaetzlichen Aufruf und erspart eine Auswahlliste, die
             * niemand belegen kann. */
            list($rn_code) = rn_post(rn_kca($rn_konto, $rn_vin, 'actions/hvac-start', $rn_land),
                $kamereon_api, $rn_jwt,
                '{"data":{"type":"HvacStart","attributes":{"action":"cancel"}}}',
                'Vorklimatisierung beenden (hvac-start cancel)');
            if ($rn_code >= 400) {
                list($rn_code) = rn_post(rn_kca($rn_konto, $rn_vin, 'actions/hvac-start', $rn_land),
                    $kamereon_api, $rn_jwt,
                    '{"data":{"type":"HvacStart","attributes":{"action":"stop"}}}',
                    'Vorklimatisierung beenden (hvac-start stop)');
            }
            break;
        case 'chargenow':
            list($rn_code) = rn_post(rn_kca($rn_konto, $rn_vin, 'actions/charging-start', $rn_land),
                $kamereon_api, $rn_jwt,
                '{"data":{"type":"ChargingStart","attributes":{"action":"start"}}}',
                'Sofort laden (charging-start)');
            break;
        case 'chargestop':
            /* Erst der Weg, den Zoe PH1 und Twingo kennen. Zoe PH2 und
             * Dacia Spring antworten darauf mit einem Fehler und halten das
             * Laden ueber kcm/charge/pause-resume an. */
            list($rn_code) = rn_post(rn_kca($rn_konto, $rn_vin, 'actions/charging-start', $rn_land),
                $kamereon_api, $rn_jwt,
                '{"data":{"type":"ChargingStart","attributes":{"action":"stop"}}}',
                'Laden anhalten (charging-start stop)');
            if ($rn_code >= 400) {
                list($rn_code) = rn_post(rn_kcm($rn_konto, $rn_vin, 'charge/pause-resume', $rn_land),
                    $kamereon_api, $rn_jwt,
                    '{"data":{"type":"ChargePauseResume","attributes":{"action":"pause"}}}',
                    'Laden anhalten (charge/pause-resume)');
            }
            break;
        case 'cmon':
            list($rn_code) = rn_post(rn_kca($rn_konto, $rn_vin, 'actions/charge-mode', $rn_land),
                $kamereon_api, $rn_jwt,
                '{"data":{"type":"ChargeMode","attributes":{"action":"schedule_mode"}}}',
                'Ladeplan aktivieren (charge-mode)');
            break;
        case 'cmoff':
            list($rn_code) = rn_post(rn_kca($rn_konto, $rn_vin, 'actions/charge-mode', $rn_land),
                $kamereon_api, $rn_jwt,
                '{"data":{"type":"ChargeMode","attributes":{"action":"always_charging"}}}',
                'Ladeplan abschalten (charge-mode)');
            break;
        default:
            renault_log('ERROR', 'Unbekannter Befehl: ' . $rn_aktion);
            echo "UNKNOWN ACTION\n";
            return;
    }
    $rn_befehl_ok = ($rn_code > 0 && $rn_code < 400);
    renault_log($rn_befehl_ok ? 'INFO' : 'ERROR',
        'Befehl ' . $rn_aktion . ' an ' . $rn_ziel['name'] . ': HTTP ' . $rn_code
        . ($rn_befehl_ok ? '' : ' - der Befehl wurde NICHT ausgefuehrt.'));
    $rn_ausgabe[] = strtoupper($rn_aktion) . ';OK=' . ($rn_befehl_ok ? '1' : '0') . ';HTTP=' . $rn_code;
}

/* ==================================================================
 * Abruf je Fahrzeug
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

/** Ein Thema veroeffentlichen, retained. */
function rn_sende($mqtt, $name, $thema, $wert)
{
    if ($mqtt === null) { return; }
    $mqtt->publish('Renault/' . $name . '/' . $thema, (string) $wert, 0, 1);
}

$rn_irgendwas_ok = false;

foreach ($rn_mit_vin as $rn_f) {

    // Nur das angesprochene Fahrzeug, wenn ein Befehl kam.
    if ($rn_aktion !== '' && (int) $rn_f['nr'] !== (int) $rn_fahrzeugnr) { continue; }

    $rn_vin  = $rn_f['vin'];
    $rn_ph   = (string) $rn_f['zoeph'];
    $rn_name = $rn_f['name'];

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
     * 27 Innentemperatur  28 Aussentemperatur des Fahrzeugs */
    $rn_roh = @file_get_contents($rn_f['session']);
    if ($rn_roh === FALSE || $rn_roh === '') {
        $rn_s = array_fill(0, 29, '');
        $rn_s[4] = '202001010000';
        $rn_s[5] = 'N';
        $rn_s[6] = 'N';
        $rn_s[25] = '';
    } else {
        $rn_s = array_pad(explode('|', $rn_roh), 29, '');
    }

    /* ---- Abrufbremse: nur im Cron, und nur fuer den Datenabruf ---- */
    $rn_hole = true;
    if ($rn_ist_cron) {
        $rn_takt = ($rn_s[10] == 1 || $rn_s[6] === 'Y')
            ? max(1, (int) $rn_cfg['cron_acs'])
            : max(1, (int) $rn_cfg['cron_ncs']);
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
        $rn_ausgabe[] = $rn_name . ';SKIP';
        continue;
    }

    $rn_s[4] = $rn_jetzt;
    $rn_erfolg = false;

    /* ---- Batterie- und Ladestatus ---- */
    list($rn_code, $rn_d, $rn_rohantwort) = rn_get(
        rn_kca($rn_konto, $rn_vin, 'battery-status', $rn_land, 'v2'),
        $kamereon_api, $rn_jwt, 'Batterie-Status ' . $rn_name . ' (battery-status)');

    if (!isset($rn_d['data']['attributes'])) {
        renault_log('ERROR', 'Batterie-Status ' . $rn_name . ': keine Daten (HTTP ' . $rn_code
            . '). Antwort: ' . substr(preg_replace('/\s+/', ' ', $rn_rohantwort), 0, 400));
        /* 401/403: das Token ist ungueltig geworden (etwa nach einem
         * Passwortwechsel bei Renault). Bis 2.0.6 wurde erst um Mitternacht
         * neu angemeldet - das Plugin stand also bis zum Tageswechsel. */
        if ($rn_code === 401 || $rn_code === 403) {
            renault_log('WARN', 'Die Anmeldung wird verworfen und beim naechsten Lauf erneuert.');
            rn_anmeldung_schreiben(array('0000', '', $rn_anm[2], $rn_anm[3]));
        }
        // Diagnose bei 404 "no data for this vin and uid".
        if (strpos($rn_rohantwort, 'notFound') !== FALSE) {
            list($rn_c2, $rn_d2) = rn_get(RN_BASIS . '/accounts/' . rawurlencode($rn_konto)
                . '/vehicles?country=' . rawurlencode($rn_land), $kamereon_api, $rn_jwt);
            if (isset($rn_d2['vehicleLinks'])) {
                $rn_vins = array();
                foreach ($rn_d2['vehicleLinks'] as $rn_vl) {
                    if (!empty($rn_vl['vin'])) { $rn_vins[] = $rn_vl['vin']; }
                }
                if (!$rn_vins) {
                    renault_log('ERROR', 'Diagnose: In Konto ' . $rn_konto . ' ist KEIN Fahrzeug '
                        . 'verknuepft. Fahrzeug in der My-Renault-App diesem Konto hinzufuegen, oder '
                        . 'es wird das falsche Konto verwendet (Zwischenspeicher im Reiter Test leeren).');
                } else {
                    renault_log('WARN', 'Diagnose: Im Konto verknuepfte Fahrgestellnummern: '
                        . implode(', ', $rn_vins) . ' - eingetragen: ' . $rn_vin);
                    if (!in_array($rn_vin, $rn_vins, true)) {
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
            $rn_s[24] = isset($rn_dd['data']['attributes']['chargeMode'])
                ? $rn_dd['data']['attributes']['chargeMode'] : 'n/a';

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
                $rn_ch = curl_init('https://api.openweathermap.org/data/2.5/onecall/timemachine?lat='
                    . rawurlencode($rn_s[17]) . '&lon=' . rawurlencode($rn_s[18])
                    . '&dt=' . rawurlencode($rn_wetter_dt) . '&units=metric&appid='
                    . rawurlencode($rn_cfg['weather_api_key']));
                curl_setopt($rn_ch, CURLOPT_RETURNTRANSFER, TRUE);
                curl_setopt($rn_ch, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($rn_ch, CURLOPT_TIMEOUT, 30);
                $rn_wr = curl_exec($rn_ch);
                $rn_wc = (int) curl_getinfo($rn_ch, CURLINFO_HTTP_CODE);
                curl_close($rn_ch);
                if ($rn_wr === FALSE || $rn_wc >= 400) {
                    renault_log('WARN', 'Wetterdienst antwortete mit HTTP ' . $rn_wc
                        . '. Hinweis: die hier benutzte Fassung 2.5 von OpenWeatherMap ist '
                        . 'abgekuendigt; die Aussentemperatur liefert bei vielen Fahrzeugen '
                        . 'ohnehin die Fahrzeug-Schnittstelle selbst (Thema OutTemp).');
                } else {
                    $rn_wd = json_decode($rn_wr, TRUE);
                    if (isset($rn_wd['current']['temp'])) { $rn_s[22] = $rn_wd['current']['temp']; }
                    if (isset($rn_wd['current']['weather'][0]['description'])) {
                        $rn_s[23] = $rn_wd['current']['weather'][0]['description'];
                    }
                }
            }
        }
        $rn_s[3] = $rn_md5;
    }

    /* ---- Vorklimatisierung: Zustand und Temperaturen ----
     *
     * hvac-status liefert neben hvacStatus auch externalTemperature und bei
     * einem Teil der Fahrzeuge internalTemperature. Bis 2.0.6 wurde nur der
     * erste Wert gelesen und die Aussentemperatur stattdessen bei einem
     * Wetterdienst geholt - ein zweiter Dienst mit eigenem Schluessel fuer
     * einen Wert, den das Fahrzeug selbst kennt. */
    $rn_hvac = 'n/a';
    if ($rn_erfolg) {
        list($rn_c, $rn_dd) = rn_get(rn_kca($rn_konto, $rn_vin, 'hvac-status', $rn_land),
            $kamereon_api, $rn_jwt);
        if (isset($rn_dd['data']['attributes'])) {
            $rn_ha = $rn_dd['data']['attributes'];
            if (isset($rn_ha['hvacStatus'])) { $rn_hvac = $rn_ha['hvacStatus']; }
            if (isset($rn_ha['internalTemperature'])) { $rn_s[27] = $rn_ha['internalTemperature']; }
            if (isset($rn_ha['externalTemperature'])) { $rn_s[28] = $rn_ha['externalTemperature']; }
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
    if ($rn_erfolg && ($rn_cfg['mail_bl'] === 'Y' || $rn_cfg['cmon_bl'] === 'Y' || $rn_cfg['exec_bl'] !== '')) {
        if ($rn_s[12] !== '' && $rn_s[12] >= $rn_schwelle && $rn_s[10] == 1 && $rn_s[5] !== 'Y') {
            $rn_rest = ($rn_s[15] !== '') ? $rn_s[15] : rn_t('MELDUNG.WENIGE');
            $rn_text = rn_t('MELDUNG.AKKUSTAND_ERREICHT') . ' (' . $rn_name . ')' . "\n"
                     . rn_t('MELDUNG.AKKUSTAND') . ': ' . $rn_s[12] . ' %' . "\n"
                     . rn_t('MELDUNG.RESTLADEZEIT') . ': ' . $rn_rest . ' ' . rn_t('MELDUNG.MINUTEN') . "\n"
                     . rn_t('MELDUNG.REICHWEITE') . ': ' . $rn_s[14] . ' km' . "\n"
                     . rn_t('MELDUNG.STATUSUPDATE') . ': ' . $rn_s[8] . ' ' . $rn_s[9];
            if ($rn_cfg['mail_bl'] === 'Y') { @mail($rn_cfg['username'], $rn_name, $rn_text); }
            if ($rn_cfg['cmon_bl'] === 'Y') {
                list($rn_c) = rn_post(rn_kca($rn_konto, $rn_vin, 'actions/charge-mode', $rn_land),
                    $kamereon_api, $rn_jwt,
                    '{"data":{"type":"ChargeMode","attributes":{"action":"schedule_mode"}}}',
                    'Ladeplan bei erreichtem Akkustand (charge-mode)');
            }
            /* escapeshellarg statt Anfuehrungszeichen von Hand: die Meldung
             * wird aus Werten der Renault-Schnittstelle zusammengesetzt. Der
             * Befehl selbst bleibt unmaskiert - er ist eine bewusste Eingabe
             * des Betreibers in den Einstellungen, kein Fremdwert. */
            if ($rn_cfg['exec_bl'] !== '') { shell_exec($rn_cfg['exec_bl'] . ' ' . escapeshellarg($rn_text)); }
            $rn_s[5] = 'Y';
            renault_log('INFO', 'Akkustand ' . $rn_schwelle . ' % erreicht (' . $rn_name . ') - Meldung ausgeloest.');
        } elseif ($rn_s[5] === 'Y' && $rn_s[10] != 1) {
            $rn_s[5] = 'N';
        }
    }
    if ($rn_erfolg && ($rn_cfg['mail_csf'] === 'Y' || $rn_cfg['exec_csf'] !== '')) {
        if ($rn_s[6] === 'Y' && $rn_s[10] != 1) {
            $rn_text = rn_t('MELDUNG.LADEN_BEENDET') . ' (' . $rn_name . ')' . "\n"
                     . rn_t('MELDUNG.AKKUSTAND') . ': ' . $rn_s[12] . ' %' . "\n"
                     . rn_t('MELDUNG.REICHWEITE') . ': ' . $rn_s[14] . ' km' . "\n"
                     . rn_t('MELDUNG.STATUSUPDATE') . ': ' . $rn_s[8] . ' ' . $rn_s[9];
            if ($rn_cfg['mail_csf'] === 'Y') { @mail($rn_cfg['username'], $rn_name, $rn_text); }
            // Bis 1.4 stand hier $exec_bl, obwohl die Bedingung $exec_csf prueft.
            if ($rn_cfg['exec_csf'] !== '') { shell_exec($rn_cfg['exec_csf'] . ' ' . escapeshellarg($rn_text)); }
            renault_log('INFO', 'Ladung beendet (' . $rn_name . ') - Meldung ausgeloest.');
        }
        $rn_s[6] = ($rn_s[10] == 1) ? 'Y' : 'N';
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
        @file_put_contents($rn_csv, implode(';', array(
            $rn_s[8], $rn_s[9], $rn_s[7], $rn_s[12], $rn_s[13], $rn_s[14], $rn_s[11], $rn_s[10],
            $rn_s[16], $rn_s[15], $rn_s[17], $rn_s[18], $rn_s[19], $rn_s[20],
            ($rn_s[28] !== '' ? $rn_s[28] : $rn_s[22]), $rn_s[23], $rn_s[24],
        )) . "\n", FILE_APPEND);
    }

    /* ---- ABRP ---- */
    if ($rn_erfolg && $rn_cfg['abrp_token'] !== '' && $rn_cfg['abrp_model'] !== '') {
        $rn_tlm = urlencode(json_encode(array(
            'car_model'   => $rn_cfg['abrp_model'],
            'utc'         => $rn_utc,
            'soc'         => $rn_s[12] === '' ? 0 : (float) $rn_s[12],
            'odometer'    => $rn_s[7] === '' ? 0 : (float) $rn_s[7],
            'is_charging' => ($rn_s[10] == 1) ? 1 : 0,
        )));
        $rn_ch = curl_init('https://api.iternio.com/1/tlm/send?api_key=fd99255b-91a0-45cd-9df5-d6baa8e50ef8&token='
            . rawurlencode($rn_cfg['abrp_token']) . '&tlm=' . $rn_tlm);
        curl_setopt($rn_ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($rn_ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($rn_ch, CURLOPT_TIMEOUT, 30);
        $rn_ar = curl_exec($rn_ch);
        $rn_ac = (int) curl_getinfo($rn_ch, CURLINFO_HTTP_CODE);
        curl_close($rn_ch);
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
    if ($rn_erfolg) {
        $rn_s[25] = $rn_jetzt;
        $rn_irgendwas_ok = true;

        rn_sende($rn_mqtt, $rn_name, 'BattSOC',        $rn_s[12]);
        rn_sende($rn_mqtt, $rn_name, 'Range',          $rn_s[14]);
        rn_sende($rn_mqtt, $rn_name, 'ChargingStatus', $rn_s[10]);
        rn_sende($rn_mqtt, $rn_name, 'CableStatus',    $rn_s[11]);
        rn_sende($rn_mqtt, $rn_name, 'ChargingTime',   $rn_s[15]);
        rn_sende($rn_mqtt, $rn_name, 'ChargingEffekt', $rn_s[16]);
        rn_sende($rn_mqtt, $rn_name, 'ChargeMode',     $rn_s[24]);
        rn_sende($rn_mqtt, $rn_name, 'Mileage',        $rn_s[7]);
        rn_sende($rn_mqtt, $rn_name, 'Name',           $rn_name);
        rn_sende($rn_mqtt, $rn_name, 'HvAcStatus',     $rn_hvac);
        if ($rn_hvac === 'on' || $rn_hvac === 'off') {
            rn_sende($rn_mqtt, $rn_name, 'HvAcStatusBin', $rn_hvac === 'on' ? 1 : 0);
        }
        rn_sende($rn_mqtt, $rn_name, 'InTemp',  $rn_s[27]);
        // Aussentemperatur: die des Fahrzeugs hat Vorrang vor der des
        // Wetterdienstes.
        rn_sende($rn_mqtt, $rn_name, 'OutTemp', $rn_s[28] !== '' ? $rn_s[28] : $rn_s[22]);

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
        $rn_ausgabe[] = $rn_name . ';OK=1;SOC=' . $rn_s[12] . ';RANGE=' . $rn_s[14];
    } else {
        rn_sende($rn_mqtt, $rn_name, 'ok', 0);
        $rn_ausgabe[] = $rn_name . ';OK=0';
    }

    /* ---- Zwischenspeicher schreiben ---- */
    @file_put_contents($rn_f['session'], implode('|', $rn_s));
}

if ($rn_mqtt !== null) { $rn_mqtt->close(); }

if (!$rn_ausgabe) { $rn_ausgabe[] = 'NOTHING TO DO'; }
echo implode("\n", $rn_ausgabe) . "\n";
