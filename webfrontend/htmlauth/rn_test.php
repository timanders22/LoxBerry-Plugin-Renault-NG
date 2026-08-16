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
function rn_test_selbstpruefung($cfg, $broker)
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

    $rechte = is_file($p['config']) ? substr(sprintf('%o', fileperms($p['config'])), -4) : '';
    $z[] = array(rn_t('PRUEF.RECHTE'), $rechte === '0600',
        $rechte === '' ? rn_t('PRUEF.KEINE_CONFIG') : $rechte);

    $z[] = array(rn_t('PRUEF.GATEWAY'), (bool) $broker,
        $broker ? $broker['host'] . ':' . $broker['port'] : rn_t('PRUEF.KEIN_BROKER'));
    $z[] = array(rn_t('PRUEF.AUTOSTART'), $broker ? $broker['autostart'] : false,
        !$broker ? rn_t('PRUEF.UNBEKANNT')
                 : ($broker['autostart'] ? rn_t('PRUEF.JA') : rn_t('PRUEF.KEIN_AUTOSTART')));

    // Alter des letzten ERFOLGREICHEN Abrufs, je Fahrzeug.
    foreach ($autos as $f) {
        if ($f['vin'] === '') { continue; }
        $s = rn_session($f['nr']);
        $stand = ($s && isset($s[25]) && $s[25] !== '') ? $s[25] : '';
        if ($stand === '') {
            $z[] = array(sprintf(rn_t('PRUEF.ALTER'), $f['name']), false, rn_t('PRUEF.NIE'));
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
    $doppelt = array();
    if (is_string($roh = @file_get_contents(__DIR__ . '/index.php'))) {
        preg_match_all("/rn_e\(\s*(?:sprintf\()?rn_t\('([A-Z0-9_.]+)'\)/", $roh, $m);
        foreach (array_unique($m[1]) as $k) {
            $w = rn_t($k);
            if ($w !== $k && (strpos($w, '&') !== false || strpos($w, '<') !== false)) {
                $doppelt[] = $k;
            }
        }
    }
    $z[] = array(rn_t('PRUEF.MASKIERUNG'), !$doppelt,
        $doppelt ? sprintf(rn_t('PRUEF.MASKIERUNG_ABWEICHUNG'), count($doppelt),
                           implode(', ', array_slice($doppelt, 0, 4)))
                 : rn_t('PRUEF.MASKIERUNG_OK'));

    // Einmal lesen, dreimal auswerten - nicht dreimal lesen.
    $prot = rn_log_tail();
    $z[] = array(rn_t('PRUEF.PROTOKOLL'), (bool) $prot,
        $prot ? sprintf(rn_t('PRUEF.N_ZEILEN'), count($prot)) : rn_t('PRUEF.LEER'));

    return $z;
}

function rn_test_ausfuehren($was, $cfg)
{
    $p = rn_paths();

    switch ($was) {

        case 'umgebung':
            $z = array();
            $z[] = 'PHP-Fassung       : ' . PHP_VERSION;
            foreach (array('curl', 'json', 'openssl') as $modul) {
                $z[] = sprintf('%-18s: %s', 'Erweiterung ' . $modul,
                    extension_loaded($modul) ? 'vorhanden' : 'FEHLT');
            }
            $z[] = sprintf('%-18s: %s', 'exec()',
                function_exists('exec') ? 'verfuegbar' : 'gesperrt (config.php wird ungeprueft geschrieben)');
            $z[] = '';
            $dateien = array('config' => 'config.php', 'anmeldung' => 'anmeldung',
                             'log' => 'renault.log', 'sicherung' => 'Zweitschrift');
            foreach ($dateien as $k => $name) {
                $d = $p[$k];
                $z[] = sprintf('%-18s: %s', $name,
                    is_readable($d)
                        ? 'vorhanden (' . number_format(filesize($d) / 1024, 1, ',', '.') . ' kB, '
                          . substr(sprintf('%o', fileperms($d)), -4) . ')'
                        : 'nicht vorhanden');
            }
            foreach (rn_fahrzeuge($cfg) as $f) {
                foreach (array('session' => 'Zwischenspeicher', 'csv' => 'Aufzeichnung') as $k => $name) {
                    $z[] = sprintf('%-18s: %s', $name . ' ' . $f['nr'],
                        is_readable($f[$k])
                            ? 'vorhanden (' . number_format(filesize($f[$k]) / 1024, 1, ',', '.') . ' kB)'
                            : 'nicht vorhanden');
                }
            }
            $z[] = '';
            foreach (array('konfdir' => 'Konfigurationsordner',
                           'datadir' => 'Datenordner',
                           'logdir'  => 'Protokollordner') as $k => $name) {
                $z[] = sprintf('%-20s: %s  (%s)', $name,
                    is_writable($p[$k]) ? 'beschreibbar' : 'NICHT beschreibbar', $p[$k]);
            }
            $broker = rn_mqtt_broker();
            $z[] = 'MQTT-Broker         : ' . ($broker
                ? $broker['host'] . ':' . $broker['port']
                : 'nicht in general.json gefunden - MQTT-Gateway eingerichtet?');
            return array(rn_t('TEXT.K_UMGEBUNG'), rn_block(implode("\n", $z)));

        case 'konfig':
            $z = array();
            $z[] = sprintf('%-24s: %s', 'Benutzer', $cfg['username'] !== '' ? $cfg['username'] : 'LEER');
            $z[] = sprintf('%-24s: %s', 'Passwort', rn_maske($cfg['password'], 2));
            $z[] = sprintf('%-24s: %s', 'Land', $cfg['country']);
            $z[] = sprintf('%-24s: %s', 'Aktionstoken', rn_maske($cfg['aktionstoken'], 4));
            $z[] = sprintf('%-24s: %s', 'Steuerung', $cfg['steuerung_ein'] === 'Y' ? 'EIN' : 'aus (Vorgabe)');
            $z[] = sprintf('%-24s: %s / %s min', 'Takt normal / laden', $cfg['cron_ncs'], $cfg['cron_acs']);
            $z[] = sprintf('%-24s: %s Grad', 'Klima-Zieltemperatur', $cfg['ac_temp']);
            $z[] = sprintf('%-24s: %s %%', 'Meldeschwelle', $cfg['bl_schwelle']);
            $z[] = sprintf('%-24s: %s / %s', 'Ladeziel min / soll',
                $cfg['soc_min'] !== '' ? $cfg['soc_min'] . ' %' : 'nicht gesetzt',
                $cfg['soc_target'] !== '' ? $cfg['soc_target'] . ' %' : 'nicht gesetzt');
            $z[] = sprintf('%-24s: %s', 'Wetterschluessel', rn_maske($cfg['weather_api_key'], 4));
            $z[] = sprintf('%-24s: %s', 'ABRP-Token', rn_maske($cfg['abrp_token'], 4));
            $z[] = '';
            foreach (rn_fahrzeuge($cfg) as $f) {
                $z[] = sprintf('Fahrzeug %d: %s', $f['nr'], $f['name']);
                $z[] = sprintf('   %-21s: %s', 'Fahrgestellnummer',
                    $f['vin'] !== '' ? rn_maske($f['vin'], 6) : 'LEER');
                $z[] = sprintf('   %-21s: Phase %s', 'Generation', $f['zoeph']);
                $z[] = sprintf('   %-21s: Renault/%s/#', 'Einzutragendes Abo', $f['name']);
            }
            $hinweis = '';
            if ($cfg['username'] === '' || $cfg['password'] === '') {
                $hinweis = '<p class="sm-hilfe">' . rn_e(rn_t('PRUEF.H_UNVOLLSTAENDIG')) . '</p>';
            }
            return array(rn_t('TEXT.K_KONFIG'), rn_block(implode("\n", $z)) . $hinweis);

        case 'zwischen':
            $z = array();
            $a = rn_anmeldung_lesen();
            $z[] = 'Anmeldung';
            $z[] = sprintf('   %-21s: %s', 'Datum', $a[0] !== '0000' ? $a[0] : 'keine');
            $z[] = sprintf('   %-21s: %s', 'Sitzungstoken', rn_maske($a[1], 6));
            $z[] = sprintf('   %-21s: %s', 'Kamereon-Konto', $a[2] !== '' ? $a[2] : 'unbekannt');
            $z[] = '';
            foreach (rn_fahrzeuge($cfg) as $f) {
                $s = rn_session($f['nr']);
                $z[] = 'Fahrzeug ' . $f['nr'] . ': ' . $f['name'];
                if (!$s) {
                    $z[] = '   (noch kein Abruf)';
                    continue;
                }
                foreach (rn_session_felder() as $i => $lbl) {
                    $z[] = sprintf('   %-30s: %s', rn_t($lbl),
                        isset($s[$i]) && $s[$i] !== '' ? $s[$i] : '-');
                }
                $z[] = sprintf('   %-30s: %d', 'Felder insgesamt', count($s));
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
            $z[] = sprintf('Im Programmcode veroeffentlicht : %d Themen', count($gesendet));
            $z[] = sprintf('In der Anleitung aufgefuehrt    : %d Themen', count($dok));
            $z[] = '';
            if (!$nur_code && !$nur_liste) {
                $z[] = 'Beide Listen sind deckungsgleich.';
            } else {
                if ($nur_liste) {
                    $z[] = 'IN DER ANLEITUNG, ABER NIE GESENDET';
                    $z[] = '  (wer diese Eingaenge anlegt, bekommt nie einen Wert)';
                    foreach ($nur_liste as $t) { $z[] = '    ' . $t; }
                    $z[] = '';
                }
                if ($nur_code) {
                    $z[] = 'GESENDET, ABER NICHT IN DER ANLEITUNG';
                    foreach ($nur_code as $t) { $z[] = '    ' . $t . '   (' . $gesendet[$t] . ')'; }
                    $z[] = '';
                }
            }
            $z[] = 'Fundstellen im Programmcode:';
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
                $z[] = $name . ': ' . ($xml === false ? 'NICHT WOHLGEFORMT' : 'wohlgeformt')
                     . ' (' . strlen($inhalt) . ' Bytes, '
                     . (substr_count($inhalt, "\r\n") > 0 ? 'CRLF' : 'LF') . ')';
                foreach ($fehler as $f) { $z[] = '    ' . trim($f->message); }
                if ($xml !== false) {
                    $kinder = $xml->children();
                    $z[] = sprintf('    %d Elemente', count($kinder) - 1);
                }
            }
            $z[] = '';
            $z[] = 'Abgleich der Eingangsvorlage mit dem Sendecode:';
            $fehlt = array();
            foreach (rn_fahrzeuge($cfg) as $f) {
                foreach (rn_vorlage_felder($f['zoeph']) as $w) {
                    if (!isset($gesendet[$w[0]])) { $fehlt[$w[0]] = true; }
                }
            }
            if ($fehlt) {
                $z[] = '  ACHTUNG - die Vorlage legt Eingaenge an, die nie einen Wert bekommen:';
                foreach (array_keys($fehlt) as $t) { $z[] = '    ' . $t; }
            } else {
                $z[] = '  Jedes Feld der Vorlage wird vom Programmcode auch gesendet.';
            }
            return array(rn_t('TEXT.K_VORLAGE_PRUEFEN'), rn_block(implode("\n", $z)));
    }

    return array(rn_t('PRUEF.UNBEKANNT_TITEL'),
        '<p class="sm-hilfe">' . rn_e(rn_t('PRUEF.UNBEKANNT_TEXT')) . '</p>');
}
