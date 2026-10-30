#!/usr/bin/env php
<?php
/**
 * Renault NG - Selbsttest des Rechenkerns
 *
 * Aufruf:  php bin/rn_selbsttest.php --selbsttest
 *
 * Er prueft die Funktionen, die ohne Fahrzeug, ohne Netz und ohne
 * eingerichtete Anlage eine feststehende Antwort haben - und NUR die. Was
 * eine Anlage braucht (Zugangsdaten, Broker, Cron), beantwortet der Reiter
 * "Test" in der Oberflaeche; das ist eine andere Frage und gehoert nicht in
 * dieselbe Zahl.
 *
 * Warum es diese Datei gibt: bis 2.1.5 wertete unter bin/ keine Datei
 * --selbsttest aus. Das Freigabetor des Hauses meldete diese Pruefung
 * deshalb als "nicht feststellbar" - kein Haken und kein Kreuz, sondern ein
 * Strich. Ein Kreuz waere falsch gewesen, ein Haken erst recht.
 *
 * WICHTIG: dieser Lauf schreibt NICHTS. Er ruft insbesondere kein
 * rn_paths() mit Anlegen auf - sonst entstuenden beim Pruefen des Archivs
 * config/, data/ und log/ irgendwo im Dateisystem. Die geprueften
 * Funktionen kommen alle ohne Pfade aus.
 */

/* ---- Nur bekannte Schalter; alles andere beendet den Lauf ---------- */
foreach ($argv as $i => $a) {
    if ($i === 0 || strncmp((string) $a, '--', 2) !== 0) { continue; }
    if (!in_array($a, array('--selbsttest'), true)) {
        fwrite(STDERR, 'Unbekannter Schalter: ' . $a . "\n");
        exit(2);
    }
}

/* ---- Die Bibliothek ueber eine Kandidatenliste finden --------------
 *
 * Installiert liegen bin/ und webfrontend/htmlauth/ in getrennten Baeumen,
 * im Archiv nebeneinander. Eine feste Zahl von ".." trifft immer nur eine
 * der beiden Lagen. */
/* Seit 2.1.11 entscheidet der eigene Ablageort, welche der beiden Lagen gilt
 * - nicht die Reihenfolge einer Liste. Bis 2.1.10 wurde der Kandidat "drei
 * Ebenen hinauf" auch aus einem ausgepackten Archiv heraus VOR der eigenen
 * Bibliothek geprueft; in WSL gemessen (Pruefung-Renault-NG-2.1.11, Fall
 * W10) wurde ein Koeder dort geladen. Mit LBHOMEDIR wird wie bisher zuerst
 * die Installation gefragt. */
$rn_kandidaten = array();
$rn_home = getenv('LBHOMEDIR');
if ($rn_home) {
    $rn_ordner = getenv('LBPPLUGINDIR');
    if (!$rn_ordner) { $rn_ordner = basename(__DIR__); }
    $rn_kandidaten[] = $rn_home . '/webfrontend/htmlauth/plugins/' . $rn_ordner . '/rn_lib.php';
}
if (basename(dirname(__DIR__)) === 'plugins') {
    $rn_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                     . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/rn_lib.php';
} else {
    $rn_kandidaten[] = dirname(__DIR__) . '/webfrontend/htmlauth/rn_lib.php';
}

$rn_lib = '';
foreach ($rn_kandidaten as $k) {
    if (is_file($k)) { $rn_lib = $k; break; }
}
if ($rn_lib === '') {
    fwrite(STDERR, "rn_lib.php nicht gefunden. Gesucht wurde in:\n");
    foreach ($rn_kandidaten as $k) { fwrite(STDERR, '  ' . $k . "\n"); }
    exit(1);
}
require_once $rn_lib;
echo 'Bibliothek: ' . $rn_lib . "\n";

/* ---- Die Faelle ---------------------------------------------------- */
$faelle = 0;
$fehl   = 0;

function pruefe($was, $ist, $soll)
{
    global $faelle, $fehl;
    $faelle++;
    if ($ist === $soll) { return; }
    $fehl++;
    printf("  FEHLSCHLAG %-46s ist %s, soll %s\n", $was,
        var_export($ist, true), var_export($soll, true));
}

/* 1. Der Zerleger der Konfiguration.
 *
 * Er hat mit 2.1.6 das include abgeloest. Die Faelle sind die, an denen
 * die Korrektur haengt: Fluchtzeichen, Zeilenumbruch im Wert, und vor
 * allem die abgeschnittene Datei - bis 2.1.5 ein Parsefehler, der
 * Oberflaeche, Cron und Endpunkt zugleich stilllegte. */
$heil = null;
$w = rn_config_zerlegen("<?php\n\$a = 'schlicht';\n\$b = '';\n?>\n", $heil);
pruefe('Zerleger: schlichter Wert',        isset($w['a']) ? $w['a'] : null, 'schlicht');
pruefe('Zerleger: leerer Wert',            isset($w['b']) ? $w['b'] : null, '');
pruefe('Zerleger: Datei heil',             $heil, true);

$w = rn_config_zerlegen("<?php\n\$a = 'L\\'Auto';\n\$b = 'C:\\\\temp';\n?>\n", $heil);
pruefe('Zerleger: maskiertes Anfuehrungszeichen', isset($w['a']) ? $w['a'] : null, "L'Auto");
pruefe('Zerleger: maskierter Rueckstrich',        isset($w['b']) ? $w['b'] : null, 'C:\\temp');

$w = rn_config_zerlegen("<?php\n\$a = 'zwei\nZeilen';\n?>\n", $heil);
pruefe('Zerleger: Zeilenumbruch im Wert',  isset($w['a']) ? $w['a'] : null, "zwei\nZeilen");

$w = rn_config_zerlegen("<?php\n\$a = 'gut';\n\$b = 'abgeschn", $heil);
pruefe('Zerleger: abgeschnitten, was lesbar war', isset($w['a']) ? $w['a'] : null, 'gut');
pruefe('Zerleger: abgeschnitten erkannt',  $heil, false);
pruefe('Zerleger: abgeschnittener Wert faellt weg', isset($w['b']), false);

$w = rn_config_zerlegen("<?php\n// \$a = 'aus dem Kommentar';\n\$b = 'echt';\n?>\n", $heil);
pruefe('Zerleger: Kommentar wird uebergangen', isset($w['a']), false);
pruefe('Zerleger: Wert nach dem Kommentar',    isset($w['b']) ? $w['b'] : null, 'echt');

$w = rn_config_zerlegen("<?php\n\$a = 42;\n\$b = true;\n\$c = null;\n?>\n", $heil);
pruefe('Zerleger: Zahl',    isset($w['a']) ? $w['a'] : null, '42');
pruefe('Zerleger: true',    isset($w['b']) ? $w['b'] : null, '1');
pruefe('Zerleger: null',    isset($w['c']) ? $w['c'] : null, '');

/* 2. Die Wertpruefung der Sicherungsdatei.
 *
 * Sie muss dasselbe sagen wie das Formular. Zu jedem abgewiesenen Wert
 * gehoert ein zugelassener daneben - sonst misst man nur, dass die
 * Funktion ueberhaupt etwas ablehnt. */
pruefe('Wert: Land DE',                rn_wert_pruefen('country', 'DE'), true);
pruefe('Wert: Land NICHTEINLAND',      rn_wert_pruefen('country', 'NICHTEINLAND'), false);
pruefe('Wert: Phase 2',                rn_wert_pruefen('zoeph', '2'), true);
pruefe('Wert: Phase 9',                rn_wert_pruefen('zoeph', '9'), false);
pruefe('Wert: Steuerung Y',            rn_wert_pruefen('steuerung_ein', 'Y'), true);
pruefe('Wert: Steuerung JA',           rn_wert_pruefen('steuerung_ein', 'JA'), false);
pruefe('Wert: Takt 5',                 rn_wert_pruefen('cron_ncs', '5'), true);
pruefe('Wert: Takt 0',                 rn_wert_pruefen('cron_ncs', '0'), false);
pruefe('Wert: Zieltemperatur 21',      rn_wert_pruefen('ac_temp', '21'), true);
pruefe('Wert: Zieltemperatur 999',     rn_wert_pruefen('ac_temp', '999'), false);
pruefe('Wert: Ladeziel leer',          rn_wert_pruefen('soc_min', ''), true);
pruefe('Wert: Ladeziel 10',            rn_wert_pruefen('soc_min', '10'), false);
pruefe('Wert: Fahrgestellnummer leer', rn_wert_pruefen('vin', ''), true);
pruefe('Wert: Fahrgestellnummer 17',   rn_wert_pruefen('vin', 'VF1AG000000000001'), true);
pruefe('Wert: Fahrgestellnummer kurz', rn_wert_pruefen('vin', 'KEINEVIN'), false);
pruefe('Wert: Name schlicht',          rn_wert_pruefen('zoename', 'Zoe'), true);
pruefe('Wert: Name mit Schraegstrich', rn_wert_pruefen('zoename', 'a/b'), false);
pruefe('Wert: Name mit Raute',         rn_wert_pruefen('zoename', 'a#b'), false);
pruefe('Wert: Name mit Plus',          rn_wert_pruefen('zoename', 'a+b'), false);
pruefe('Wert: Befehl schlicht',        rn_wert_pruefen('exec_bl', '/bin/echo hallo'), true);
pruefe('Wert: Befehl mit Semikolon',   rn_wert_pruefen('exec_bl', '/bin/echo;rm -rf /'), false);
pruefe('Wert: Token leer zulaessig',   rn_wert_pruefen('aktionstoken', ''), true);
pruefe('Wert: Token schlicht',         rn_wert_pruefen('aktionstoken', 'abc-123_x.y'), true);
pruefe('Wert: Token mit Leerzeichen',  rn_wert_pruefen('aktionstoken', 'a b'), false);

pruefe('Taugt: Zeichenkette',          rn_wert_taugt('Zoe'), true);
pruefe('Taugt: Feld',                  rn_wert_taugt(array('a')), false);
pruefe('Taugt: null',                  rn_wert_taugt(null), false);
pruefe('Taugt: Zeilenumbruch',         rn_wert_taugt("a\nb"), false);
pruefe('Taugt: Nullbyte',              rn_wert_taugt("a\x00b"), false);

/* 3. Retain je Thema.
 *
 * Zustaende des Fahrzeugs retained, Messwerte mit Zeitbezug nicht, das
 * Lebenszeichen nie - und eine Aussage des Plugins ueber sich selbst ("ok")
 * nie (Regeln/07 Abschnitt 3, 18./19.09.2026). Bis 2.1.5 ging alles
 * retained hinaus; bis 2.1.10 verlangte diese Zeile ok retained. */
pruefe('Retain: ChargingStatus (Zustand)',   rn_thema_retained('ChargingStatus'), true);
pruefe('Retain: ok (Aussage des Plugins)',   rn_thema_retained('ok'), false);
pruefe('Retain: chargeEndStatus (Zustand)',  rn_thema_retained('chargeEndStatus'), true);
pruefe('Retain: BattSOC (Messwert)',         rn_thema_retained('BattSOC'), false);
pruefe('Retain: OutTemp (Messwert)',         rn_thema_retained('OutTemp'), false);
pruefe('Retain: phpCall (Lebenszeichen)',    rn_thema_retained('phpCall'), false);
pruefe('Retain: status/ts (Lebenszeichen)',  rn_thema_retained('status/ts'), false);
pruefe('Retain: status/zaehler',             rn_thema_retained('status/zaehler'), false);

/* 3b. Der Merker einer einzelnen Sendung (seit 2.1.9). Eine leere Nutzlast
 * mit Retain loescht das Thema im Broker - leer geht deshalb nie etwas
 * retained hinaus. Seit 2.1.13 (Befund M2) geht ein leerer Messwert gar
 * nicht hinaus und ein leerer Zustand als "-" retained (rn_mqtt_nutzlast());
 * die zwei Zeilen darunter pruefen das statt des frueheren Merkers fuer
 * einen leeren Zustand. Die Null als Wert ist NICHT leer (ok=0, Kabel=0). */
pruefe('Merker: CableStatus mit Wert',       rn_retain_merker('CableStatus', '1'), 1);
pruefe('Nutzlast: CableStatus leer -> "-"',  rn_mqtt_nutzlast('CableStatus', ''), '-');
// Seit 2.1.20 (Hausform -1-Regel): ein Zahlthema ohne Wert geht mit seinem Fehlwert hinaus.
pruefe('Nutzlast: BattSOC leer -> -1',       rn_mqtt_nutzlast('BattSOC', ''), '-1');
pruefe('Merker: ok mit 1 nicht retained',    rn_retain_merker('ok', 1), 0);
pruefe('Merker: ok mit "0" nicht retained',  rn_retain_merker('ok', '0'), 0);
pruefe('Merker: Kabel mit 0 bleibt retained', rn_retain_merker('CableStatus', 0), 1);
pruefe('Merker: BattSOC mit Wert',           rn_retain_merker('BattSOC', '80'), 0);
pruefe('Merker: status/ts mit Wert',         rn_retain_merker('status/ts', 1789600000), 0);
pruefe('Merker: unbekanntes Thema',          rn_retain_merker('Erfunden', 'x'), 0);

/* 3c. Die Themen, deren Altwert abgeraeumt wird (seit 2.1.11): ok und die
 * Messwerte, die bis 2.1.5 retained hinausgingen. Keines davon darf heute
 * retained sein - sonst raeumte der Abruf ab, was er gleich wieder
 * zurueckbehielte. */
$fa = rn_mqtt_frueher_behalten('abruf');
$fh = rn_mqtt_frueher_behalten('history');
pruefe('Altlast: ok gehoert dazu',           in_array('ok', $fa, true), true);
pruefe('Altlast: BattSOC gehoert dazu',      in_array('BattSOC', $fa, true), true);
pruefe('Altlast: chargeDuration(min) dazu',  in_array('chargeDuration(min)', $fh, true), true);
pruefe('Altlast: kein Zustand gehoert dazu', in_array('Mileage', array_merge($fa, $fh), true), false);
$zurueck = array();
foreach (array_merge($fa, $fh) as $t) {
    if (rn_thema_retained($t)) { $zurueck[] = $t; }
}
pruefe('Altlast: keines davon ist heute retained', implode(', ', $zurueck), '');

/* 4. Befehle: veraendert er etwas am Fahrzeug? */
pruefe('Befehl acnow schaltet',      rn_befehl_schaltet('acnow'), true);
pruefe('Befehl chargestop schaltet', rn_befehl_schaltet('chargestop'), true);
pruefe('Befehl abruf schaltet nicht', rn_befehl_schaltet('abruf'), false);
pruefe('Unbekannter Befehl gilt als schaltend', rn_befehl_schaltet('gibtsnicht'), true);

/* 5. Die Themenliste beider Generationen. */
$t1 = rn_themen('1');
$t2 = rn_themen('2');
pruefe('Phase 1 kennt BatTemp',        isset($t1['BatTemp']), true);
pruefe('Phase 1 kennt kein GPSTime',   isset($t1['GPSTime']), false);
pruefe('Phase 2 kennt GPSTime',        isset($t2['GPSTime']), true);
pruefe('Phase 2 kennt kein BatTemp',   isset($t2['BatTemp']), false);
pruefe('Beide kennen das Lebenszeichen',
    isset($t1['status/ts']) && isset($t2['status/ts']), true);

/* 6. Vorgaben: neue Funktionen stehen ab Werk AUS. */
$v = rn_vorgaben();
pruefe('Vorgabe: Steuerung aus',     $v['steuerung_ein'], 'N');
pruefe('Vorgabe: Aufzeichnung aus',  $v['save_in_db'], 'N');
pruefe('Vorgabe: Mail aus',          $v['mail_bl'], 'N');
pruefe('Vorgabe: Token leer',        $v['aktionstoken'], '');
pruefe('Vorgabe: Zieltemperatur 21', $v['ac_temp'], '21');
// Nr. 36 b (seit 2.1.16): + 4 Anlass-Schalter + die Felder des Blocks tts (ansage_vorgaben(), Modul 1.1.1: 15).
pruefe('Vorgabe: Sprachausgabe aus', $v['tts_mode'], 'aus');
// Gruppe 40 (2.1.20): + die neuen Schluessel (rn_vorgaben_2120(), 19 + je Fahrzeug zwei). Welle Mehrfach-Ziele
// (2.1.20): + tts_ziele (die Liste der weiteren Ausgabeziele als JSON, leer = keine).
pruefe('Vorgabe: 32 + 4 + tts + tts_ziele + 2.1.20 Schluessel', count($v),
    37 + count(ansage_vorgaben('aus')) + count(rn_neue_schluessel_2120()));
pruefe('Vorgabe: tts_ziele leer', $v['tts_ziele'], '');

/* 7. Jeder Vorgabewert muss die eigene Wertpruefung bestehen.
 *
 * Sonst wiese die Sicherungsdatei eine Konfiguration ab, die das Plugin
 * selbst geschrieben hat - der Prueffall, der beim Bau der VolkswagenID-
 * Fassung eine richtig arbeitende Funktion rot gemeldet hat. */
$durchgefallen = array();
foreach ($v as $k => $wert) {
    if (!rn_wert_taugt($wert) || !rn_wert_pruefen($k, (string) $wert)) {
        $durchgefallen[] = $k;
    }
}
pruefe('Alle Vorgabewerte bestehen die eigene Pruefung',
    implode(', ', $durchgefallen), '');

/* 8. Die Beschriftungen der Aufzeichnung.
 *
 * Gemessen wird, was hier feststeht - nicht, ob die Sprachdatei gefunden
 * wurde: das haengt am Ablageort und ist keine Eigenschaft der Funktion.
 * Was in KEINEM Fall herauskommen darf, ist der nackte Sprachschluessel;
 * genau der stuende sonst als Spaltenueberschrift in der Tabelle. */
pruefe('CSV: unbekannte Spalte bleibt stehen',
    rn_csv_spalte('Etwas Unbekanntes'), 'Etwas Unbekanntes');
pruefe('CSV: leere Spalte bleibt leer', rn_csv_spalte(''), '');
$roh_namen = array('Date', 'Battery level', 'Range', 'Charging status',
                   'GPS Latitude', 'Outside temperature', 'Charging schedule');
$schluesselhaft = array();
foreach ($roh_namen as $sp) {
    $b = rn_csv_spalte($sp);
    if ($b === '' || strpos($b, 'CSV.') === 0) { $schluesselhaft[] = $sp; }
}
pruefe('CSV: keine Ueberschrift ist ein nackter Sprachschluessel',
    implode(', ', $schluesselhaft), '');

/* 9. Gruppe 40 (2.1.20): die neuen reinen Funktionen (Bauvorschrift 7.1 Schritt 8). */
$a0 = array('username' => 'nutzer@example.com', 'password' => 'p', 'country' => 'DE', 'ac_temp' => '21',
            'vin' => 'BEISPIEL000000001', 'zoeph' => '2', 'zoename' => 'Zoe',
            'vin2' => 'BEISPIEL000000002', 'zoeph2' => '2', 'zoename2' => 'Zwei',
            'vin3' => 'BEISPIEL000000003', 'zoeph3' => '1', 'zoename3' => 'Drei', 'vin4' => '', 'zoename4' => '');
$a1 = $a0; $a1['ac_temp'] = '22';
pruefe('V1: nur ac_temp -> Konto gleich',        rn_konto_geaendert($a0, $a1), false);
$a1 = $a0; $a1['username'] = 'andere@example.com';
pruefe('V1: Benutzer geaendert',                 rn_konto_geaendert($a0, $a1), true);
$a1 = $a0; $a1['country'] = 'AT';
pruefe('V1: Land geaendert',                     rn_konto_geaendert($a0, $a1), true);
list($an1, $le1) = rn_anmeldung_nach_abweisung(array('1028', 'JWT_A_0', 'KONTO_A', 'P1', '0'), 100000, 0);
pruefe('V1: erste Abweisung leert das Konto',    $an1[2], '');
list($an2) = rn_anmeldung_nach_abweisung(array('1028', 'JWT', 'KONTO_A', 'P1', (string) $le1), 100600, $le1);
pruefe('V1: nach 600 s bleibt das Konto',        $an2[2], 'KONTO_A');
list($an3) = rn_anmeldung_nach_abweisung(array('1028', 'JWT', 'KONTO_A', 'P1', (string) $le1), 103700, $le1);
pruefe('V1: nach 3700 s leer',                   $an3[2], '');
list($an4) = rn_anmeldung_nach_abweisung(array('1028', 'JWT', 'KONTO_A', 'P1', '200000'), 100000, 200000);
pruefe('V1: Uhr zurueck -> leer',                $an4[2], '');
pruefe('V1: JWT immer leer',                     $an2[1] . '|' . $an2[0], '|0000');
$a1 = $a0; $a1['ac_temp'] = '25';
pruefe('V2: ac_temp -> nichts verwerfen',        implode(',', rn_session_verwerfen($a0, $a1)), '');
$a1 = $a0; $a1['vin2'] = 'BEISPIEL000000009';
pruefe('V2: vin2 -> [2]',                        implode(',', rn_session_verwerfen($a0, $a1)), '2');
$a1 = $a0; $a1['zoeph'] = '1';
pruefe('V2: zoeph (Fahrzeug 1) -> [1]',          implode(',', rn_session_verwerfen($a0, $a1)), '1');
$a1 = $a0; $a1['password'] = 'neu';
pruefe('V2: Kennwort -> alle eingerichteten',    implode(',', rn_session_verwerfen($a0, $a1)), '1,2,3');
$a1 = $a0; $a1['vin3'] = ''; $a1['zoename3'] = '';
pruefe('V2: Fahrzeug 3 entfernt -> [3]',         implode(',', rn_session_verwerfen($a0, $a1)), '3');
pruefe('V3: gleiche Zeile -> keine neue',        rn_csv_neu(array('27.10.2026', '18:02', '100', '80', '', '200', '1', '0'),
                                                            array('27.10.2026', '18:02', '100', '80', '', '200', '1', '0')), false);
pruefe('V3: SOC +1 -> neue Zeile',               rn_csv_neu(array('27.10.2026', '18:02', '100', '80', '', '200', '1', '0'),
                                                            array('27.10.2026', '18:02', '100', '81', '', '200', '1', '0')), true);
pruefe('V3: ohne letzte Zeile -> neu',           rn_csv_neu(array(), array('a')), true);
list($rt, $rw) = rn_csv_aufraeumen("Date;Time\na;1\na;1\na;1\nb;2\na;1\n");
pruefe('E-E3: drei gleiche -> eine, Folge bleibt', $rt . '|' . $rw, "Date;Time\na;1\nb;2\na;1\n|2");
list($rt) = rn_csv_aufraeumen("Date;Time\r\nx;1\r\nx;1\r\n");
pruefe('E-E3: CRLF bleibt',                      $rt, "Date;Time\r\nx;1\r\n");
pruefe('H1: Fehlwert BattSOC',                   rn_mqtt_nutzlast('BattSOC', ''), '-1');
pruefe('H1: Fehlwert InTemp',                    rn_mqtt_nutzlast('InTemp', ''), '-99');
pruefe('H1: Fehlwert GPS-Latitude',              rn_mqtt_nutzlast('GPS-Latitude', ''), '-999');
pruefe('H1: ChargingStatus ohne Wert: nichts',   rn_mqtt_nutzlast('ChargingStatus', ''), null);
pruefe('H1: ChargeMode ohne Wert: -',            rn_mqtt_nutzlast('ChargeMode', ''), '-');
pruefe('H1: Gateway-Name',                       rn_gateway_name('Renault/Mein Zoe/BattSOC'), 'Renault_Mein_Zoe_BattSOC');
pruefe('F7: status reserviert',                  rn_name_reserviert('Status'), true);
pruefe('F7: Zoe frei',                           rn_name_reserviert('Zoe'), false);
pruefe('F7: Wertpruefung befehl',                rn_wert_pruefen('zoename2', 'Befehl'), false);
pruefe('B: Quittung ok',                         rn_befehl_quittung(array(1, 'OK', 200, '')), array(true, ''));
pruefe('B: Quittung abgelehnt',                  rn_befehl_quittung(array(0, 'STEUERUNG_AUS', 403, '')), array(false, 'STEUERUNG_AUS'));
pruefe('V7-e: 404 -> INFO',                      rn_history_stufe(404, false), 'INFO');
pruefe('V7-e: 502 -> WARN',                      rn_history_stufe(502, false), 'WARN');
list($ms, $mt) = rn_meldewege_text(array('mail' => false, 'signal' => null, 'befehl' => null));
pruefe('V7-i: mail false -> WARN',               $ms, 'WARN');
pruefe('X1: Entfernung 0,009 Grad Breite',       (int) round(rn_entfernung(48.0, 11.0, 48.009, 11.0)), 1001);
$hz = array('heim_ein' => 'Y', 'heim_breite' => '48.0000', 'heim_laenge' => '11.0000', 'heim_radius' => '150');
pruefe('X1: innen und frisch',                   rn_zuhause($hz, '48.0005', '11.0000', 1000, 1000 + 600, '2'), array(1, 0.06));
pruefe('X1: GPS 61 min vor Fahrzeug',            rn_zuhause($hz, '48.0005', '11.0000', 1000, 1000 + 3660, '2'), array(-1, -1));
pruefe('X1: Generation 1',                       rn_zuhause($hz, '48.0005', '11.0000', 1000, 1000, '1'), array(-1, -1));
$m = array();
list($va, $m) = rn_vorklima_schritt($m, 10, 1000000, 20, null);
pruefe('X2: Lauf 1 senden',                      $va, 'senden');
list($va, $m) = rn_vorklima_schritt($m, 9, 1000060, 20, null);
pruefe('X2: +60 s nichts',                       $va, 'nichts');
list($va, $m) = rn_vorklima_schritt($m, 8, 1000130, 20, null);
pruefe('X2: +130 s Abruf statt Senden',          $va, 'abruf_und_senden');
pruefe('X2: Wettergrenze mild',                  rn_vorklima_wetter(array('vorklima_wetter_ein' => 'Y', 'vorklima_kalt_unter' => '5',
                                                     'vorklima_heiss_ueber' => '25'), 12, 0, null, -1), 'mild');
pruefe('X2: Wettergrenze ohne Aussage',          rn_vorklima_wetter(array('vorklima_wetter_ein' => 'Y'), 12, 1801, null, -1), 'ohne_aussage');
pruefe('X4: Thema',                              rn_signal_thema('signalbot', 'renault_ng'), 'signalbot/befehl/plugin/renault_ng');
pruefe('X4: Ordner ungueltig',                   rn_signal_thema('signalbot', 'Renault NG'), '');
pruefe('A: Haus frisch 60 s',                    rn_haus_frisch(array('abfahrt' => array('seit' => 1000)), 'abfahrt', 1060), true);
pruefe('A: Haus 121 s schweigt',                 rn_haus_frisch(array('abfahrt' => array('seit' => 1000)), 'abfahrt', 1121), false);
pruefe('E-E2: Ankunft Thema tag',                rn_ankunft_thema('tag:zoe'), 'haus/tag/zoe/da');
$lk = rn_ladung_kosten(array(array('t' => 0, 'soc' => 50, 'kwh' => 20.0), array('t' => 600, 'soc' => 60, 'kwh' => 25.0)),
                       array(array('t' => 60, 'preis' => 30), array('t' => 120, 'preis' => 30), array('t' => 180, 'preis' => 30),
                             array('t' => 240, 'preis' => 30), array('t' => 300, 'preis' => 30), array('t' => 360, 'preis' => 30),
                             array('t' => 420, 'preis' => 30), array('t' => 480, 'preis' => 30), array('t' => 540, 'preis' => 30),
                             array('t' => 600, 'preis' => 30)), '');
pruefe('E-E2: 5 kWh zu 30 ct -> 1,50 EUR',       array($lk[0], $lk[1], $lk[2], $lk[4]), array(5.0, 1.5, 30.0, 'ok'));
$lk = rn_ladung_kosten(array(array('t' => 0, 'soc' => 50), array('t' => 600, 'soc' => 60)), array(), '');
pruefe('E-E2: ohne Energie und Kapazitaet -> -1', $lk[4], 'energie_fehlt');
$vg = rn_vorgaben();
pruefe('2.3: endpunkt_ein ab Werk aus',          $vg['endpunkt_ein'], 'N');
pruefe('2.2: Befehle ueber MQTT ab Werk aus',    $vg['befehle_mqtt_ein'], 'N');
pruefe('2.5: Ansage-Modi mit sonos4lox, ohne audioserver',
    in_array('sonos4lox', rn_ansage_modi(), true) && !in_array('audioserver', rn_ansage_modi(), true), true);

/* ---- Schlusszeile --------------------------------------------------
 *
 * Gezaehlt wird aus den wirklich gelaufenen Faellen, nicht aus einer Zahl
 * im Quelltext. */
echo "\n";
printf("Rechenkern Renault NG: %d Faelle geprueft, %d Fehlschlaege.\n", $faelle, $fehl);
if ($fehl === 0) {
    echo "Was hier NICHT gemessen ist: die Anmeldung bei Renault, die Feldnamen\n";
    echo "an einem echten Fahrzeug, die Wirkung der Schaltbefehle, retain am\n";
    echo "laufenden Gateway und die Installation auf echter Hardware.\n";
}
exit($fehl === 0 ? 0 : 1);
