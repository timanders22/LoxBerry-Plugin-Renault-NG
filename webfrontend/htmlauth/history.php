<?php
/**
 * Renault - Ladevorgaenge des letzten Monats abholen und per MQTT melden
 *
 * Wird vom Cron alle zehn Minuten aufgerufen (cron.10min).
 *
 * ===================================================================
 * WAS SICH MIT 2.1.0 GEAENDERT HAT
 * ===================================================================
 *
 * 1. ES WIRD PROTOKOLLIERT.
 *    Bis 2.0.6 band diese Datei logger.php gar nicht ein - kein einziger
 *    renault_log()-Aufruf. Ein Programm, das alle zehn Minuten ins Netz
 *    geht, war damit im Reiter "Logdateien" unsichtbar, auch im
 *    Stoerungsfall.
 *
 * 2. VEROEFFENTLICHT WIRD DER NEUESTE LADEVORGANG, EINMAL.
 *    Bis 2.0.6 stand publish() INNERHALB der Schleife ueber alle
 *    Ladevorgaenge und las die Werte aus $data[0] - die Dauer $cdm kam
 *    aber aus dem gerade bearbeiteten Durchlauf. Am Ende der Schleife
 *    stand also die Energie des NEUESTEN Ladevorgangs geteilt durch die
 *    Dauer des AELTESTEN im Broker.
 *
 * 3. KEINE EIGENE ANMELDUNG MEHR.
 *    Bis 2.0.6 meldete sich diese Datei selbst bei Gigya an und schrieb
 *    danach die GANZE Sitzungsdatei zurueck - mit dem Stand, den sie vor
 *    ihren bis zu 80 Sekunden Netzarbeit gelesen hatte. Lief in der
 *    Zwischenzeit abruf.php, war dessen frischer Abruf fort, und der
 *    Zeitstempel sprang zurueck. Die beiden Cron-Skripte sperrten sich
 *    nicht gegenseitig, weil sie verschiedene Sperrdateien benutzten.
 *    Jetzt teilen sie sich eine Sperre, und die Anmeldung steht in einer
 *    eigenen Datei, die history.php nur LIEST.
 *
 * 4. DIE ANTWORTEN WERDEN GEPRUEFT.
 *    file_get_contents() ohne FALSE-Pruefung, id_token und cookieValue
 *    ohne isset - fehlte die Sitzungsdatei, ging die Anfrage mit leerem
 *    Token und leerem Konto an Kamereon.
 *
 * Zeitschranken wie ueberall: 10 s verbinden, 30 s gesamt.
 */

require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once 'phpMQTT/phpMQTT.php';

require_once __DIR__ . '/rn_lib.php';
require_once __DIR__ . '/logger.php';
require 'api-keys.php';

rn_umzug();
session_cache_limiter('nocache');
header('Content-Type: text/plain; charset=utf-8');

$rn_cfg = rn_config_read();
if ($rn_cfg['username'] === '' || $rn_cfg['password'] === '') {
    echo "NO CREDENTIALS\n";
    return;
}

/* Die Anmeldung kommt aus der gemeinsamen Datei und wird hier NICHT
 * erneuert - das macht abruf.php alle drei Minuten. Fehlt sie, ist der
 * Drei-Minuten-Cron entweder noch nicht gelaufen oder er scheitert; beides
 * steht dann bereits im Protokoll und muss hier nicht ein zweites Mal. */
$rn_anm = rn_anmeldung_lesen();
if ($rn_anm[1] === '' || $rn_anm[2] === '') {
    renault_log('INFO', 'Ladehistorie uebersprungen: es liegt noch keine gueltige Anmeldung vor. '
        . 'Der Drei-Minuten-Cron holt sie; danach laeuft auch dieser Abruf.');
    echo "NO SESSION\n";
    return;
}
$rn_jwt   = $rn_anm[1];
$rn_konto = $rn_anm[2];
$rn_land  = $rn_cfg['country'];

$rn_broker = mqtt_connectiondetails();
$rn_mqtt = null;
if (is_array($rn_broker) && !empty($rn_broker['brokerhost'])) {
    $rn_mqtt = new Bluerhinos\phpMQTT($rn_broker['brokerhost'], $rn_broker['brokerport'],
        uniqid(gethostname() . '_client'));
    if (!$rn_mqtt->connect(true, NULL, $rn_broker['brokeruser'], $rn_broker['brokerpass'])) {
        renault_log('ERROR', 'Ladehistorie: MQTT-Verbindung fehlgeschlagen (Broker '
            . $rn_broker['brokerhost'] . ':' . $rn_broker['brokerport'] . ').');
        $rn_mqtt = null;
    }
}

function rn_h_sende($mqtt, $name, $thema, $wert)
{
    if ($mqtt === null) { return; }
    $mqtt->publish('Renault/' . $name . '/' . $thema, (string) $wert, 0, 1);
}

/** Dauer eines Ladevorgangs in Minuten, aus Anfang und Ende. */
function rn_h_dauer($start, $ende)
{
    $a = date_create_from_format(DATE_ISO8601, (string) $start, timezone_open('UTC'));
    $b = date_create_from_format(DATE_ISO8601, (string) $ende, timezone_open('UTC'));
    if ($a === false || $b === false) { return null; }
    $d = date_diff($a, $b);
    return ((int) date_interval_format($d, '%a') * 24 * 60)
         + ((int) date_interval_format($d, '%h') * 60)
         + (int) date_interval_format($d, '%i');
}

$rn_von = date('Ymd', strtotime('-1 months'));
$rn_bis = date('Ymd');

foreach (rn_fahrzeuge($rn_cfg) as $rn_f) {
    if ($rn_f['vin'] === '') { continue; }
    $rn_name = $rn_f['name'];

    $rn_url = 'https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/'
            . rawurlencode($rn_konto) . '/kamereon/kca/car-adapter/v1/cars/'
            . rawurlencode($rn_f['vin']) . '/charges?country=' . rawurlencode($rn_land)
            . '&start=' . $rn_von . '&end=' . $rn_bis;

    $rn_ch = curl_init($rn_url);
    curl_setopt($rn_ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($rn_ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($rn_ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($rn_ch, CURLOPT_HTTPHEADER, array(
        'apikey: ' . $kamereon_api,
        'x-gigya-id_token: ' . $rn_jwt,
    ));
    $rn_r = curl_exec($rn_ch);
    renault_log_api('Ladehistorie ' . $rn_name . ' (charges)', $rn_ch, $rn_r);
    $rn_code = (int) curl_getinfo($rn_ch, CURLINFO_HTTP_CODE);
    curl_close($rn_ch);

    if ($rn_r === FALSE) {
        renault_log('ERROR', 'Ladehistorie ' . $rn_name . ': keine Antwort (Zeitueberschreitung oder Netzfehler).');
        echo $rn_name . ";OK=0\n";
        continue;
    }
    $rn_d = json_decode($rn_r, TRUE);
    $rn_liste = array();
    if (isset($rn_d['data']['attributes']['charges']) && is_array($rn_d['data']['attributes']['charges'])) {
        $rn_liste = $rn_d['data']['attributes']['charges'];
    }
    if (!$rn_liste) {
        /* Nicht jedes Fahrzeug kennt diesen Endpunkt - fuer Clio V, Espace VI
         * und Duster III ist er nicht belegt. Deshalb eine Auskunft, keine
         * Fehlermeldung. */
        renault_log('INFO', 'Ladehistorie ' . $rn_name . ': keine Ladevorgaenge im Zeitraum '
            . $rn_von . ' bis ' . $rn_bis . ' (HTTP ' . $rn_code . ').');
        echo $rn_name . ";OK=0;N=0\n";
        continue;
    }

    // Neueste zuerst.
    usort($rn_liste, function ($a, $b) {
        return strcmp((string) (isset($b['chargeStartDate']) ? $b['chargeStartDate'] : ''),
                      (string) (isset($a['chargeStartDate']) ? $a['chargeStartDate'] : ''));
    });

    /* ---- Klartextausgabe fuer den Menschen (Aufruf von Hand) ---- */
    echo $rn_name . "\n" . str_repeat('-', 40) . "\n";
    $rn_gezaehlt = 0;
    foreach ($rn_liste as $rn_v) {
        if (empty($rn_v['chargeStartDate']) || empty($rn_v['chargeEndDate'])) { continue; }
        $rn_dauer = rn_h_dauer($rn_v['chargeStartDate'], $rn_v['chargeEndDate']);
        if ($rn_dauer === null) { continue; }
        $rn_gezaehlt++;
        $rn_sz = date_timezone_set(
            date_create_from_format(DATE_ISO8601, $rn_v['chargeStartDate'], timezone_open('UTC')),
            timezone_open('Europe/Berlin'));
        $rn_ez = date_timezone_set(
            date_create_from_format(DATE_ISO8601, $rn_v['chargeEndDate'], timezone_open('UTC')),
            timezone_open('Europe/Berlin'));
        echo rn_t('MELDUNG.START') . ': ' . date_format($rn_sz, 'd.m.Y H:i') . "\n";
        if ((string) $rn_f['zoeph'] === '1') {
            echo rn_t('MELDUNG.LADEVORGANG') . ': '
               . (isset($rn_v['chargeStartBatteryLevel']) ? $rn_v['chargeStartBatteryLevel'] : '?') . ' % '
               . rn_t('MELDUNG.BIS') . ' '
               . (isset($rn_v['chargeEndBatteryLevel']) ? $rn_v['chargeEndBatteryLevel'] : '?') . ' % '
               . rn_t('MELDUNG.IN') . ' ' . $rn_dauer . ' ' . rn_t('MELDUNG.MINUTEN') . "\n";
            if (isset($rn_v['chargePower'])) {
                echo rn_t('MELDUNG.LEISTUNG') . ': ' . $rn_v['chargePower']
                   . (isset($rn_v['chargeStartInstantaneousPower'])
                      ? ' (' . round($rn_v['chargeStartInstantaneousPower'] / 1000, 2) . ' kW)' : '') . "\n";
            }
        } else {
            echo rn_t('MELDUNG.LADEVORGANG') . ': '
               . round((float) (isset($rn_v['chargeEnergyRecovered']) ? $rn_v['chargeEnergyRecovered'] : 0), 2)
               . ' kWh ' . rn_t('MELDUNG.IN') . ' ' . $rn_dauer . ' ' . rn_t('MELDUNG.MINUTEN') . "\n";
        }
        echo rn_t('MELDUNG.STATUS') . ': '
           . (isset($rn_v['chargeEndStatus']) ? $rn_v['chargeEndStatus'] : '?') . ' '
           . rn_t('MELDUNG.UM') . ' ' . date_format($rn_ez, 'd.m.Y H:i') . "\n"
           . str_repeat('-', 40) . "\n";
    }

    /* ---- MQTT: NUR der neueste Ladevorgang, und alle Werte aus DERSELBEN
     *      Zeile. Genau das war bis 2.0.6 nicht der Fall. ---- */
    $rn_neu = null;
    foreach ($rn_liste as $rn_v) {
        if (!empty($rn_v['chargeStartDate']) && !empty($rn_v['chargeEndDate'])
            && rn_h_dauer($rn_v['chargeStartDate'], $rn_v['chargeEndDate']) !== null) {
            $rn_neu = $rn_v; break;
        }
    }
    if ($rn_neu === null) {
        echo $rn_name . ";OK=0;N=" . $rn_gezaehlt . "\n";
        continue;
    }
    $rn_dauer  = rn_h_dauer($rn_neu['chargeStartDate'], $rn_neu['chargeEndDate']);
    $rn_energie = isset($rn_neu['chargeEnergyRecovered']) ? (float) $rn_neu['chargeEnergyRecovered'] : null;
    // Durchschnittsleistung nur rechnen, wenn beide Zaehlreihen da sind und
    // die Dauer nicht null ist. Eine Zahl ohne Grundlage stuende sonst in
    // Loxone und saehe richtig aus.
    $rn_schnitt = ($rn_energie !== null && $rn_dauer > 0) ? round($rn_energie * 60 / $rn_dauer, 2) : '';

    rn_h_sende($rn_mqtt, $rn_name, 'chargeStartBatteryLevel(Prozent)',
        isset($rn_neu['chargeStartBatteryLevel']) ? $rn_neu['chargeStartBatteryLevel'] : '');
    rn_h_sende($rn_mqtt, $rn_name, 'chargeEndBatteryLevel(Prozent)',
        isset($rn_neu['chargeEndBatteryLevel']) ? $rn_neu['chargeEndBatteryLevel'] : '');
    rn_h_sende($rn_mqtt, $rn_name, 'chargeDuration(min)', $rn_dauer);
    rn_h_sende($rn_mqtt, $rn_name, 'chargePowerAverage(kW)', $rn_schnitt);
    rn_h_sende($rn_mqtt, $rn_name, 'chargeEnergyRecovered(kWh)', $rn_energie === null ? '' : $rn_energie);
    rn_h_sende($rn_mqtt, $rn_name, 'chargeEndStatus',
        isset($rn_neu['chargeEndStatus']) ? $rn_neu['chargeEndStatus'] : '');
    rn_h_sende($rn_mqtt, $rn_name, 'chargeStartInstantaneousPower',
        isset($rn_neu['chargeStartInstantaneousPower']) ? $rn_neu['chargeStartInstantaneousPower'] : '');

    renault_log('INFO', 'Ladehistorie ' . $rn_name . ': ' . $rn_gezaehlt . ' Ladevorgaenge, '
        . 'juengster ueber ' . $rn_dauer . ' min'
        . ($rn_energie === null ? '' : ' und ' . round($rn_energie, 2) . ' kWh') . '.');
    echo $rn_name . ';OK=1;N=' . $rn_gezaehlt . ';DAUER=' . $rn_dauer . "\n";
}

if ($rn_mqtt !== null) { $rn_mqtt->close(); }
