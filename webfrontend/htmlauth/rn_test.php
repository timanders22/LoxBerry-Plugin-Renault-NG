<?php
/**
 * Renault - die Selbstpruefungen des Reiters Test
 *
 * Jede Funktion antwortet ohne Loxone auf die Frage, ob die Einrichtung
 * traegt. Es wird nur gelesen; nichts wird an Renault gesendet.
 *
 * Zwei der Pruefungen sind aus einem konkreten Schaden entstanden und
 * gehoeren deshalb hierher und nicht in ein Werkzeug auf dem Rechner des
 * Autors:
 *
 *   "Themen abgleichen" haelt die Liste in rn_themen() gegen die
 *   publish()-Zeilen in abruf.php und history.php. Bis 2.0.6 nannte die
 *   Oberflaeche fuenf Themen, die es nie gab (BatteryLevel, RangeHvacOff,
 *   PlugStatus, ChargingRemaining, ChargingPower) - wer der Anleitung
 *   folgte, bekam stumme Eingaenge. Eine Liste, die niemand nachmisst,
 *   laeuft wieder auseinander.
 *
 *   "Vorlage pruefen" schickt beide erzeugten Importdateien durch
 *   simplexml_load_string() und vergleicht ihre Felder mit denselben
 *   publish()-Zeilen. Eine kaputte oder falsch benannte Vorlage merkt der
 *   Anwender sonst erst in Loxone Config - und sucht den Fehler bei sich.
 */

require_once __DIR__ . '/rn_lib.php';

function rn_block($text)
{
    return '<div class="sm-log">' . rn_e($text) . '</div>';
}

/** Ein Geheimnis so zeigen, dass man es wiedererkennt, aber nicht liest. */
function rn_maske($s, $behalten = 3)
{
    $s = (string) $s;
    if ($s === '') {
        return 'LEER / NICHT GESETZT';
    }
    if (strlen($s) <= $behalten) {
        return str_repeat('*', strlen($s)) . ' (' . strlen($s) . ' Zeichen)';
    }
    return substr($s, 0, $behalten) . str_repeat('*', min(strlen($s) - $behalten, 12))
         . ' (' . strlen($s) . ' Zeichen)';
}

/**
 * Alle Themen, die der Programmcode wirklich veroeffentlicht.
 *
 * Gelesen werden die publish()-Zeilen - auskommentierte zaehlen nicht mit.
 * Das Muster wird gegen die echte Ausgabe gehalten und nicht gegen ein
 * erfundenes Beispiel: es trifft genau die Form, die rn_sende() und
 * rn_h_sende() erzeugen.
 */
function rn_test_gesendete_themen()
{
    $gefunden = array();
    foreach (array('abruf.php', 'history.php') as $datei) {
        $pfad = __DIR__ . '/' . $datei;
        if (!is_readable($pfad)) { continue; }
        foreach (file($pfad, FILE_IGNORE_NEW_LINES) as $nr => $zeile) {
            $t = ltrim($zeile);
            if (strpos($t, '//') === 0 || strpos($t, '*') === 0) { continue; }
            // rn_sende($mqtt, $name, 'Thema', ...) bzw. rn_h_sende(...)
            if (preg_match("/rn_h?_?sende\\s*\\(\\s*\\\$[A-Za-z_]+\\s*,\\s*\\\$[A-Za-z_]+\\s*,\\s*'([^']+)'/", $t, $m)) {
                $gefunden[$m[1]] = $datei . ':' . ($nr + 1);
            }
        }
    }
    return $gefunden;
}

/**
 * Die Zeilen der Selbstpruefung: array(Frage, in Ordnung, Antwort).
 *
 * Bis 2.0.6 waren es fuenf Zeilen. Die Punkte, die dazugekommen sind,
 * haben in dieser Reihe alle etwas gefunden: der Autostart des Gateways,
 * das Alter des letzten Abrufs, die Rechte der Konfigurationsdatei und
 * die Uebereinstimmung der Themen.
 */
function rn_test_selbstpruefung($cfg, $broker, $voll = true)
{
    $p = rn_paths();
    $z = array();

    $z[] = array(rn_t('PRUEF.ZUGANG'),
        $cfg['username'] !== '' && $cfg['password'] !== '',
        $cfg['username'] !== '' && $cfg['password'] !== ''
            ? rn_t('PRUEF.JA') : rn_t('PRUEF.NEIN_EINSTELLUNGEN'));

    $autos = rn_fahrzeuge($cfg);
    $mit = 0;
    foreach ($autos as $f) { if ($f['vin'] !== '') { $mit++; } }
    $z[] = array(rn_t('PRUEF.VIN'), $mit > 0,
        $mit > 0 ? sprintf(rn_t('PRUEF.N_FAHRZEUGE'), $mit) : rn_t('PRUEF.NEIN'));

    /* Ist die Konfiguration heil? Der Zustand kommt aus rn_konfig_lage() -
     * dem ZUERST festgestellten, nicht dem nach der Selbstheilung. Ein
     * geheilter Schaden ist kein Nicht-Schaden: die Zweitschrift kann
     * aelter sein als das, was verlorenging. */
    $lage = rn_konfig_lage();
    $z[] = array(rn_t('PRUEF.LAGE'),
        in_array($lage, array('ok', 'fehlt'), true) ? true
            : ($lage === 'ohne Token' ? null : false),
        rn_t('PRUEF.LAGE_' . strtoupper(str_replace(' ', '_', $lage))) !==
            'PRUEF.LAGE_' . strtoupper(str_replace(' ', '_', $lage))
            ? rn_t('PRUEF.LAGE_' . strtoupper(str_replace(' ', '_', $lage)))
            : $lage);

    /* Rechte: ohne Konfigurationsdatei gibt es nichts zu messen - das ist
     * ein Punkt, kein Kreuz. */
    $rechte = is_file($p['config']) ? substr(sprintf('%o', fileperms($p['config'])), -4) : '';
    $z[] = array(rn_t('PRUEF.RECHTE'), $rechte === '' ? null : ($rechte === '0600'),
        $rechte === '' ? rn_t('PRUEF.KEINE_CONFIG') : $rechte);

    $z[] = array(rn_t('PRUEF.GATEWAY'), (bool) $broker,
        $broker ? $broker['host'] . ':' . $broker['port'] : rn_t('PRUEF.KEIN_BROKER'));
    /* Ohne Broker ist der Autostart NICHT FESTSTELLBAR - bis 2.1.5 stand
     * dort ein rotes Kreuz neben der Antwort "unbekannt". */
    $z[] = array(rn_t('PRUEF.AUTOSTART'), $broker ? (bool) $broker['autostart'] : null,
        !$broker ? rn_t('PRUEF.UNBEKANNT')
                 : ($broker['autostart'] ? rn_t('PRUEF.JA') : rn_t('PRUEF.KEIN_AUTOSTART')));

    // Alter des letzten ERFOLGREICHEN Abrufs, je Fahrzeug.
    foreach ($autos as $f) {
        if ($f['vin'] === '') { continue; }
        $s = rn_session($f['nr']);
        $stand = ($s && isset($s[25]) && $s[25] !== '') ? $s[25] : '';
        if ($stand === '') {
            /* Noch kein Abruf: es gibt kein Alter zu beurteilen. Ein Kreuz
             * an dieser Stelle stuende auf jeder frisch eingerichteten
             * Anlage - eine Pruefzeile urteilt nicht ueber eine leere
             * Menge. */
            $z[] = array(sprintf(rn_t('PRUEF.ALTER'), $f['name']), null, rn_t('PRUEF.NIE'));
            continue;
        }
        $d = date_create_from_format('YmdHi', $stand);
        $min = $d ? (int) round((time() - date_timestamp_get($d)) / 60) : -1;
        $grenze = max(15, 3 * (int) $cfg['cron_ncs']);
        $z[] = array(sprintf(rn_t('PRUEF.ALTER'), $f['name']), $min >= 0 && $min <= $grenze,
            $min < 0 ? rn_t('PRUEF.UNLESBAR') : sprintf(rn_t('PRUEF.VOR_MINUTEN'), $min, $grenze));
    }

    // Steuerung: keine Beanstandung, nur eine Auskunft - "aus" ist die
    // Vorgabe und damit richtig, nicht falsch.
    $z[] = array(rn_t('PRUEF.STEUERUNG'), true,
        $cfg['steuerung_ein'] === 'Y' ? rn_t('PRUEF.STEUERUNG_EIN') : rn_t('PRUEF.STEUERUNG_AUS'));

    $z[] = array(rn_t('PRUEF.TOKEN'), strlen($cfg['aktionstoken']) >= 16,
        $cfg['aktionstoken'] === '' ? rn_t('PRUEF.NEIN')
            : sprintf(rn_t('PRUEF.N_ZEICHEN'), strlen($cfg['aktionstoken'])));

    /* Themen: Liste gegen Sendecode.
     *
     * Verglichen wird gegen die Anleitung fuer BEIDE Generationen, nicht nur
     * fuer die eingestellte. Grund: rn_test_gesendete_themen() liest den
     * Quelltext STATISCH und sieht deshalb immer beide Zweige - den fuer
     * Phase 1 (BatTemp) und den fuer Phase 2 (GPS, EnergieOnBoard). Wer nur
     * gegen die eingestellte Generation vergleicht, bekommt auf jeder
     * normalen Anlage mit einem Fahrzeug ein rotes Kreuz fuer etwas, das in
     * Ordnung ist. Genau so war es im ersten Anlauf dieser Pruefung. */
    $gesendet = rn_test_gesendete_themen();
    $dok = array();
    foreach (array('1', '2') as $ph) {
        foreach (array_keys(rn_themen($ph)) as $t) { $dok[$t] = true; }
    }
    $fehlt_dok  = array_diff(array_keys($gesendet), array_keys($dok));
    $fehlt_code = array_diff(array_keys($dok), array_keys($gesendet));
    $themen_ok  = !$fehlt_dok && !$fehlt_code;
    $z[] = array(rn_t('PRUEF.THEMEN'), $themen_ok,
        $themen_ok ? sprintf(rn_t('PRUEF.THEMEN_OK'), count($gesendet))
                   : sprintf(rn_t('PRUEF.THEMEN_ABWEICHUNG'),
                       count($fehlt_code), count($fehlt_dok)));

    /* Kongruenz von DREI Stellen: der Quelle $rn_reiter, der ausgeschriebenen
     * Leiste und den id der Bereiche.
     *
     * Bis zum ersten Prueflauf verglich diese Stelle nur Leiste gegen
     * Bereiche - und blieb gruen, als in einer Gegenprobe ein Name aus
     * $rn_reiter entfernt wurde. Der Reiter war damit unerreichbar (die
     * Positivliste kannte ihn nicht mehr, jedes Absenden sprang zurueck auf
     * Einstellungen), und weder diese Pruefung noch hausstandard_pruefen.py
     * hat es gesehen. Ein Kommentar, der eine Pruefung zusichert, ist keine. */
    $roh = @file_get_contents(__DIR__ . '/index.php');
    $kong = false; $anzahl = 0;
    if (is_string($roh)) {
        preg_match_all('/data-ziel="tab-([a-z]+)"/', $roh, $a);
        preg_match_all('/id="tab-([a-z]+)"/', $roh, $b);
        $quelle = array();
        if (preg_match('/\$rn_reiter = array\((.*?)\);/s', $roh, $m)) {
            preg_match_all("/'([a-z]+)'\s*=>/", $m[1], $q);
            $quelle = $q[1];
        }
        $kong = $quelle && ($quelle === $a[1]) && ($a[1] === $b[1]);
        $anzahl = count($a[1]);
    }
    $z[] = array(rn_t('PRUEF.REITER'), $kong,
        $kong ? sprintf(rn_t('PRUEF.REITER_OK'), $anzahl) : rn_t('PRUEF.REITER_ABWEICHUNG'));

    /* Doppelte Maskierung - der teuerste Einzelbefund der Pruefreihe
     * (40 Stellen in 13 Plugins, alle aus einem widerspruechlichen Satz in
     * den Hausregeln). Geprueft wird die Stelle selbst und nicht die Regel:
     * jeder Aufruf der Form rn_e(rn_t('X')) wird nachgesehen, ob der Wert zu
     * X Auszeichnung oder eine HTML-Entitaet traegt. Traegt er sie, steht
     * sie im Browser woertlich da. */
    /* Gezaehlt wird ueber ALLE Dateien der Oberflaeche, nicht nur ueber
     * index.php: bis 2.1.5 sah diese Zeile 158 von 161 Aufrufstellen und
     * meldete trotzdem ein abschliessendes "in Ordnung". Die Zahl der
     * angesehenen Stellen steht jetzt in der Antwort. */
    $doppelt = array();
    $stellen = 0;
    foreach (rn_test_oberflaechendateien() as $datei) {
        $roh2 = @file_get_contents($datei);
        if (!is_string($roh2)) { continue; }
        preg_match_all("/rn_e\(\s*(?:sprintf\()?rn_t\('([A-Z0-9_.]+)'\)/", $roh2, $m);
        $stellen += count($m[1]);
        foreach (array_unique($m[1]) as $k) {
            $w = rn_t($k);
            if ($w !== $k && (strpos($w, '&') !== false || strpos($w, '<') !== false)) {
                $doppelt[] = $k;
            }
        }
    }
    $doppelt = array_values(array_unique($doppelt));
    $z[] = array(rn_t('PRUEF.MASKIERUNG'), $stellen === 0 ? null : !$doppelt,
        $stellen === 0 ? rn_t('PRUEF.UNBEKANNT')
            : ($doppelt
                ? sprintf(rn_t('PRUEF.MASKIERUNG_ABWEICHUNG'), count($doppelt),
                          implode(', ', array_slice($doppelt, 0, 4)))
                : sprintf(rn_t('PRUEF.MASKIERUNG_OK_N'), $stellen)));

    /* Tragen ALLE Formulare das Merkmal gegen fremde Absender?
     *
     * Gezaehlt wird ueber ALLE Dateien der Oberflaeche, nicht nur ueber
     * index.php - sobald eine Oberflaeche auf mehrere Dateien verteilt ist,
     * misst eine Zeile, die nur eine liest, die falsche Grundmenge. Die
     * Zahl der angesehenen Stellen steht in der Antwort. */
    $formulare = 0; $mit_merkmal = 0;
    foreach (rn_test_oberflaechendateien() as $datei) {
        $t = (string) @file_get_contents($datei);
        $formulare  += preg_match_all('/<form\b/i', $t);
        $mit_merkmal += preg_match_all('/name="formtoken"\s+value="[^"]+"/', $t);
    }
    $z[] = array(rn_t('PRUEF.FORMULARE'),
        $formulare === 0 ? null : ($mit_merkmal >= $formulare),
        $formulare === 0 ? rn_t('PRUEF.UNBEKANNT')
            : sprintf(rn_t('PRUEF.N_VON_N'), $mit_merkmal, $formulare));

    /* Setzt der SERVER das Merkmal des offenen Reiters?
     *
     * Steht es nur im JavaScript, ist die Seite ohne Skript leer - die
     * Flaechen stehen auf display:none. Gezaehlt wird im Quelltext, weil
     * die gerenderte Seite hier nicht vorliegt. */
    $roh_alle = '';
    foreach (rn_test_oberflaechendateien() as $datei) {
        $roh_alle .= (string) @file_get_contents($datei);
    }
    $serverseitig = preg_match_all("/\?\s*' sm-active'\s*:/", $roh_alle);
    $z[] = array(rn_t('PRUEF.SMACTIVE'), $serverseitig >= 2,
        sprintf(rn_t('PRUEF.N_STELLEN'), $serverseitig));

    /* Ist die Konfiguration vollstaendig? Zwei Zahlen, und im Fehlerfall
     * die Namen. */
    $roh_cfg = is_readable($p['config'])
        ? rn_config_einlesen($p['config']) : array();
    if (!is_array($roh_cfg)) { $roh_cfg = array(); }
    $soll = array_keys(rn_vorgaben());
    $fehlend = array();
    foreach ($soll as $k) {
        if (!array_key_exists($k, $roh_cfg)) { $fehlend[] = $k; }
    }
    $z[] = array(rn_t('PRUEF.VOLLSTAENDIG'),
        !is_readable($p['config']) ? null : (count($fehlend) === 0),
        !is_readable($p['config']) ? rn_t('PRUEF.KEINE_CONFIG')
            : ($fehlend
                ? sprintf(rn_t('PRUEF.FEHLENDE_SCHLUESSEL'),
                          count($soll) - count($fehlend), count($soll),
                          implode(', ', array_slice($fehlend, 0, 6)))
                : sprintf(rn_t('PRUEF.N_VON_N'), count($soll), count($soll))));

    /* Sind die beiden Cron-Eintraege da - und sind es DATEIEN?
     * Ein Verzeichnis an dieser Stelle ist der Befund, nicht der Erfolg. */
    $cronfehlt = array();
    $croninfo  = array();
    foreach (array('cron.03min', 'cron.10min') as $ordner) {
        $pfad = $p['home'] . '/system/cron/' . $ordner . '/' . $p['plugin'];
        if (is_file($pfad))      { $croninfo[] = $ordner; }
        elseif (is_dir($pfad))   { $cronfehlt[] = $ordner . ' (' . rn_t('PRUEF.IST_ORDNER') . ')'; }
        else                     { $cronfehlt[] = $ordner; }
    }
    $z[] = array(rn_t('PRUEF.CRON'),
        !is_dir($p['home'] . '/system/cron') ? null : (count($cronfehlt) === 0),
        !is_dir($p['home'] . '/system/cron') ? rn_t('PRUEF.UNBEKANNT')
            : ($cronfehlt ? sprintf(rn_t('PRUEF.CRON_FEHLT'), implode(', ', $cronfehlt))
                          : sprintf(rn_t('PRUEF.N_VON_N'), count($croninfo), 2)));

    /* Antwortet der eigene Endpunkt?
     *
     * Nur diese Zeile findet den Fall, dass html/ und htmlauth/ installiert
     * in getrennten Baeumen liegen und der Endpunkt mit HTTP 500 antwortet -
     * der schwerste Fehler der Vorgeschichte dieser Linie, und bis 2.1.5
     * hatte er keine Pruefzeile. Gefragt wird ?selftest=1: der ruehrt das
     * Fahrzeug nicht an.
     *
     * Drei Ausgaenge: die erwartete Kennung ist ein Haken, eine andere
     * HTTP-Antwort ein Kreuz mit Code, gar keine Verbindung ein PUNKT. Ein
     * einlaeufiger Webserver kann sich waehrend des Seitenaufbaus nicht
     * selbst aufrufen - im Pruefaufbau faellt genau dieser Fall an, und er
     * sagt nichts ueber das Plugin. */
    /* Nur, wenn der Reiter Test wirklich der offene ist: alle Reiter werden
     * mitgerendert, und eine Netzfrage bei jedem Seitenaufruf ist eine
     * Netzfrage zu viel. */
    if ($voll) {
        list($ep_ok, $ep_text) = rn_test_endpunkt($cfg);
    } else {
        $ep_ok = null;
        $ep_text = rn_t('PRUEF.EP_NUR_IM_REITER');
    }
    $z[] = array(rn_t('PRUEF.ENDPUNKT'), $ep_ok, $ep_text);

    // Einmal lesen, dreimal auswerten - nicht dreimal lesen.
    $prot = rn_log_tail();
    /* Ein LEERES Protokoll ist kein Fehler: log/plugins liegt auf einer
     * Ramdisk und ist nach jedem Neustart leer, und ein frisch
     * eingerichtetes Plugin hat noch nichts zu melden gehabt. Bis 2.1.5
     * stand hier ein rotes Kreuz - ein Kreuz, das nichts bedeutet. */
    $z[] = array(rn_t('PRUEF.PROTOKOLL'), $prot ? true : null,
        $prot ? sprintf(rn_t('PRUEF.N_ZEILEN'), count($prot)) : rn_t('PRUEF.LEER'));

    return $z;
}

/**
 * Alle Dateien, aus denen die Oberflaeche besteht.
 *
 * Wer die eigene Oberflaeche auszaehlt - Formulare, Reiter, Knopfklassen,
 * Sprachschluessel -, sammelt zuerst ALLE Dateien ein und zaehlt dann. Eine
 * Zahl, die mit sich selbst uebereinstimmt, kann trotzdem falsch sein, wenn
 * die Grundmenge zu klein war.
 */
function rn_test_oberflaechendateien()
{
    $liste = array();
    foreach (array(__DIR__ . '/index.php', __FILE__, __DIR__ . '/history.php') as $d) {
        if (is_readable($d)) { $liste[] = $d; }
    }
    return $liste;
}

/**
 * Den eigenen Endpunkt ueber 127.0.0.1 wirklich aufrufen.
 *
 * Rueckgabe: array(true|false|null, Text). null heisst "nicht feststellbar"
 * und ist weder Haken noch Kreuz.
 */
function rn_test_endpunkt($cfg)
{
    if ((string) $cfg['aktionstoken'] === '') {
        return array(null, rn_t('PRUEF.EP_KEIN_TOKEN'));
    }
    $p = rn_paths(false);
    $port = isset($_SERVER['SERVER_PORT']) ? (int) $_SERVER['SERVER_PORT'] : 80;
    $url  = 'http://127.0.0.1' . ($port && $port !== 80 ? ':' . $port : '')
          . '/plugins/' . $p['plugin'] . '/index.php?selftest=1&token='
          . rawurlencode($cfg['aktionstoken']);

    $rumpf = false;
    $code  = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        $rumpf = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(array('http' => array(
            'timeout' => 3, 'ignore_errors' => true,
            'follow_location' => 0, 'max_redirects' => 1)));
        $vorher = set_error_handler(function () { return true; });
        $rumpf = file_get_contents($url, false, $ctx);
        set_error_handler($vorher);
        if (isset($http_response_header[0])
            && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
    } else {
        return array(null, rn_t('PRUEF.EP_NICHT_MESSBAR'));
    }

    if ($rumpf === false || $rumpf === null) {
        return array(null, rn_t('PRUEF.EP_KEINE_ANTWORT'));
    }
    if ($code === 200 && strpos((string) $rumpf, 'SELFTEST;OK=1;TOKEN=OK') === 0) {
        return array(true, rn_t('PRUEF.EP_OK'));
    }
    return array(false, sprintf(rn_t('PRUEF.EP_FALSCH'), $code,
        substr(trim(preg_replace('/\s+/', ' ', (string) $rumpf)), 0, 60)));
}

/**
 * Ein Text des Reiters Test.
 *
 * Bis 2.1.5 standen die Ausgaben der fuenf Pruefknoepfe fest auf Deutsch im
 * PHP: 55 Ausgabezeilen gegen neun rn_t()-Aufrufe, ueber dreissig deutsche
 * Literale. Wer LoxBerry auf Englisch stellt, bekam fuenf vollstaendig
 * deutsche Ausgaben. Sie stehen jetzt im Abschnitt [PRUEFTEXT] beider
 * Sprachdateien.
 */
function rn_pt($schluessel)
{
    return rn_t('PRUEFTEXT.' . $schluessel);
}

function rn_test_ausfuehren($was, $cfg)
{
    $p = rn_paths();

    switch ($was) {

        case 'umgebung':
            $z = array();
            $z[] = sprintf('%-18s: %s', rn_pt('PHPFASSUNG'), PHP_VERSION);
            foreach (array('curl', 'json', 'openssl') as $modul) {
                $z[] = sprintf('%-18s: %s', rn_pt('ERWEITERUNG') . ' ' . $modul,
                    extension_loaded($modul) ? rn_pt('VORHANDEN') : rn_pt('FEHLT'));
            }
            $z[] = sprintf('%-18s: %s', 'exec()',
                function_exists('exec') ? rn_pt('VERFUEGBAR') : rn_pt('EXEC_GESPERRT'));
            $z[] = '';
            $dateien = array('config' => 'config.php', 'anmeldung' => 'anmeldung',
                             'log' => 'renault.log', 'sicherung' => rn_pt('ZWEITSCHRIFT'));
            foreach ($dateien as $k => $name) {
                $d = $p[$k];
                $z[] = sprintf('%-18s: %s', $name,
                    is_readable($d)
                        ? sprintf(rn_pt('DA_KB_RECHTE'),
                                  number_format(filesize($d) / 1024, 1, ',', '.'),
                                  substr(sprintf('%o', fileperms($d)), -4))
                        : rn_pt('NICHT_VORHANDEN'));
            }
            foreach (rn_fahrzeuge($cfg) as $f) {
                foreach (array('session' => rn_pt('ZWISCHENSPEICHER'),
                               'csv' => rn_pt('AUFZEICHNUNG')) as $k => $name) {
                    $z[] = sprintf('%-18s: %s', $name . ' ' . $f['nr'],
                        is_readable($f[$k])
                            ? sprintf(rn_pt('DA_KB'),
                                      number_format(filesize($f[$k]) / 1024, 1, ',', '.'))
                            : rn_pt('NICHT_VORHANDEN'));
                }
            }
            $z[] = '';
            foreach (array('konfdir' => rn_pt('KONFIGORDNER'),
                           'datadir' => rn_pt('DATENORDNER'),
                           'logdir'  => rn_pt('PROTOKOLLORDNER')) as $k => $name) {
                $z[] = sprintf('%-20s: %s  (%s)', $name,
                    is_writable($p[$k]) ? rn_pt('BESCHREIBBAR') : rn_pt('NICHT_BESCHREIBBAR'),
                    $p[$k]);
            }
            $broker = rn_mqtt_broker();
            $z[] = sprintf('%-20s: %s', rn_pt('MQTTBROKER'), ($broker
                ? $broker['host'] . ':' . $broker['port']
                : rn_pt('KEIN_BROKER_GEFUNDEN')));
            return array(rn_t('TEXT.K_UMGEBUNG'), rn_block(implode("\n", $z)));

        case 'konfig':
            $z = array();
            $z[] = sprintf('%-24s: %s', rn_pt('BENUTZER'),
                $cfg['username'] !== '' ? $cfg['username'] : rn_pt('LEER'));
            $z[] = sprintf('%-24s: %s', rn_pt('PASSWORT'), rn_maske($cfg['password'], 2));
            $z[] = sprintf('%-24s: %s', rn_pt('LAND'), $cfg['country']);
            $z[] = sprintf('%-24s: %s', rn_pt('AKTIONSTOKEN'), rn_maske($cfg['aktionstoken'], 4));
            $z[] = sprintf('%-24s: %s', rn_pt('STEUERUNG'),
                $cfg['steuerung_ein'] === 'Y' ? rn_pt('EIN') : rn_pt('AUS_VORGABE'));
            $z[] = sprintf('%-24s: %s / %s min', rn_pt('TAKT'), $cfg['cron_ncs'], $cfg['cron_acs']);
            $z[] = sprintf('%-24s: %s ' . rn_pt('GRAD'), rn_pt('ZIELTEMPERATUR'), $cfg['ac_temp']);
            $z[] = sprintf('%-24s: %s %%', rn_pt('MELDESCHWELLE'), $cfg['bl_schwelle']);
            $z[] = sprintf('%-24s: %s / %s', rn_pt('LADEZIEL'),
                $cfg['soc_min'] !== '' ? $cfg['soc_min'] . ' %' : rn_pt('NICHT_GESETZT'),
                $cfg['soc_target'] !== '' ? $cfg['soc_target'] . ' %' : rn_pt('NICHT_GESETZT'));
            $z[] = sprintf('%-24s: %s', rn_pt('WETTERSCHLUESSEL'), rn_maske($cfg['weather_api_key'], 4));
            $z[] = sprintf('%-24s: %s', rn_pt('ABRPTOKEN'), rn_maske($cfg['abrp_token'], 4));
            $z[] = '';
            foreach (rn_fahrzeuge($cfg) as $f) {
                $z[] = sprintf(rn_pt('FAHRZEUG_N'), $f['nr'], $f['name']);
                $z[] = sprintf('   %-21s: %s', rn_pt('FAHRGESTELLNUMMER'),
                    $f['vin'] !== '' ? rn_maske($f['vin'], 6) : rn_pt('LEER'));
                $z[] = sprintf('   %-21s: ' . rn_pt('PHASE_N'), rn_pt('GENERATION'), $f['zoeph']);
                $z[] = sprintf('   %-21s: Renault/%s/#', rn_pt('ABO'), $f['name']);
            }
            $hinweis = '';
            if ($cfg['username'] === '' || $cfg['password'] === '') {
                $hinweis = '<p class="sm-hilfe">' . rn_e(rn_t('PRUEF.H_UNVOLLSTAENDIG')) . '</p>';
            }
            return array(rn_t('TEXT.K_KONFIG'), rn_block(implode("\n", $z)) . $hinweis);

        case 'zwischen':
            $z = array();
            $a = rn_anmeldung_lesen();
            $z[] = rn_pt('ANMELDUNG');
            $z[] = sprintf('   %-21s: %s', rn_pt('DATUM'),
                $a[0] !== '0000' ? $a[0] : rn_pt('KEINE'));
            $z[] = sprintf('   %-21s: %s', rn_pt('SITZUNGSTOKEN'), rn_maske($a[1], 6));
            $z[] = sprintf('   %-21s: %s', rn_pt('KAMEREONKONTO'),
                $a[2] !== '' ? $a[2] : rn_pt('UNBEKANNT'));
            $z[] = '';
            foreach (rn_fahrzeuge($cfg) as $f) {
                $s = rn_session($f['nr']);
                $z[] = sprintf(rn_pt('FAHRZEUG_N'), $f['nr'], $f['name']);
                if (!$s) {
                    $z[] = '   ' . rn_pt('KEIN_ABRUF');
                    continue;
                }
                foreach (rn_session_felder() as $i => $lbl) {
                    $z[] = sprintf('   %-30s: %s', rn_t($lbl),
                        isset($s[$i]) && $s[$i] !== '' ? $s[$i] : '-');
                }
                $z[] = sprintf('   %-30s: %d', rn_pt('FELDER_GESAMT'), count($s));
            }
            return array(rn_t('TEXT.K_ZWISCHEN'), rn_block(implode("\n", $z)));

        case 'themen':
            $gesendet = rn_test_gesendete_themen();
            $dok = array();
            foreach (rn_fahrzeuge($cfg) as $f) {
                foreach (rn_themen($f['zoeph']) as $t => $schl) { $dok[$t] = $schl; }
            }
            $nur_code = array_diff(array_keys($gesendet), array_keys($dok));
            $nur_liste = array_diff(array_keys($dok), array_keys($gesendet));
            $z = array();
            $z[] = sprintf(rn_pt('THEMEN_CODE'), count($gesendet));
            $z[] = sprintf(rn_pt('THEMEN_LISTE'), count($dok));
            $z[] = '';
            if (!$nur_code && !$nur_liste) {
                $z[] = rn_pt('THEMEN_GLEICH');
            } else {
                if ($nur_liste) {
                    $z[] = rn_pt('THEMEN_NUR_LISTE');
                    $z[] = '  ' . rn_pt('THEMEN_NUR_LISTE_ZUSATZ');
                    foreach ($nur_liste as $t) { $z[] = '    ' . $t; }
                    $z[] = '';
                }
                if ($nur_code) {
                    $z[] = rn_pt('THEMEN_NUR_CODE');
                    foreach ($nur_code as $t) { $z[] = '    ' . $t . '   (' . $gesendet[$t] . ')'; }
                    $z[] = '';
                }
            }
            $z[] = rn_pt('THEMEN_FUNDSTELLEN');
            foreach ($gesendet as $t => $stelle) {
                $z[] = sprintf('    %-34s %s', $t, $stelle);
            }
            return array(rn_t('TEXT.K_THEMEN'), rn_block(implode("\n", $z)));

        case 'vorlage':
            $z = array();
            $gesendet = rn_test_gesendete_themen();
            foreach (array('rn_vorlage', 'rn_vorlage_vo') as $fn) {
                list($name, $inhalt) = $fn();
                $vorher = libxml_use_internal_errors(true);
                $xml = simplexml_load_string($inhalt);
                $fehler = libxml_get_errors();
                libxml_clear_errors();
                libxml_use_internal_errors($vorher);
                $z[] = $name . ': '
                     . ($xml === false ? rn_pt('NICHT_WOHLGEFORMT') : rn_pt('WOHLGEFORMT'))
                     . ' (' . strlen($inhalt) . ' Bytes, '
                     . (substr_count($inhalt, "\r\n") > 0 ? 'CRLF' : 'LF') . ')';
                foreach ($fehler as $f) { $z[] = '    ' . trim($f->message); }
                if ($xml !== false) {
                    $kinder = $xml->children();
                    $z[] = sprintf('    ' . rn_pt('N_ELEMENTE'), count($kinder) - 1);
                }
            }
            $z[] = '';
            $z[] = rn_pt('VORLAGE_ABGLEICH');
            $fehlt = array();
            foreach (rn_fahrzeuge($cfg) as $f) {
                foreach (rn_vorlage_felder($f['zoeph']) as $w) {
                    if (!isset($gesendet[$w[0]])) { $fehlt[$w[0]] = true; }
                }
            }
            if ($fehlt) {
                $z[] = '  ' . rn_pt('VORLAGE_FEHLT');
                foreach (array_keys($fehlt) as $t) { $z[] = '    ' . $t; }
            } else {
                $z[] = '  ' . rn_pt('VORLAGE_OK');
            }
            return array(rn_t('TEXT.K_VORLAGE_PRUEFEN'), rn_block(implode("\n", $z)));
    }

    return array(rn_t('PRUEF.UNBEKANNT_TITEL'),
        '<p class="sm-hilfe">' . rn_e(rn_t('PRUEF.UNBEKANNT_TEXT')) . '</p>');
}
