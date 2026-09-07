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
$rn_kandidaten = array();
$rn_home = getenv('LBHOMEDIR');
if ($rn_home) {
    $rn_ordner = getenv('LBPPLUGINDIR');
    if (!$rn_ordner) { $rn_ordner = basename(__DIR__); }
    $rn_kandidaten[] = $rn_home . '/webfrontend/htmlauth/plugins/' . $rn_ordner . '/rn_lib.php';
}
$rn_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                 . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/rn_lib.php';
$rn_kandidaten[] = dirname(__DIR__) . '/webfrontend/htmlauth/rn_lib.php';

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
 * Zustaende retained, Messwerte mit Zeitbezug nicht, das Lebenszeichen
 * nie. Bis 2.1.5 ging alles retained hinaus. */
pruefe('Retain: ChargingStatus (Zustand)',   rn_thema_retained('ChargingStatus'), true);
pruefe('Retain: ok (Zustand)',               rn_thema_retained('ok'), true);
pruefe('Retain: chargeEndStatus (Zustand)',  rn_thema_retained('chargeEndStatus'), true);
pruefe('Retain: BattSOC (Messwert)',         rn_thema_retained('BattSOC'), false);
pruefe('Retain: OutTemp (Messwert)',         rn_thema_retained('OutTemp'), false);
pruefe('Retain: phpCall (Lebenszeichen)',    rn_thema_retained('phpCall'), false);
pruefe('Retain: status/ts (Lebenszeichen)',  rn_thema_retained('status/ts'), false);
pruefe('Retain: status/zaehler',             rn_thema_retained('status/zaehler'), false);

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
pruefe('Vorgabe: 32 Schluessel',     count($v), 32);

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
