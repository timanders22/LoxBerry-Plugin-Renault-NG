<?php
/**
 * bin/rn_bestand.php - kleine Werkzeuge der Hakenskripte (seit 2.1.20, Gruppe 40). Am Geraet nie von Hand.
 *
 *   php rn_bestand.php --endpunkt-ein
 *       (postupgrade.sh, Bauvorschrift 2.3) Ergaenzt in config.php den Schluessel endpunkt_ein = Y, aber NUR,
 *       wenn er FEHLT (Bestand von 2.1.19 oder aelter: der HTTP-Endpunkt bleibt beim Update offen). Ein
 *       vorhandener Wert bleibt. Geschrieben wird ueber rn_config_write() samt Zweitschrift, 0600.
 *       LBHOMEDIR und LBPPLUGINDIR setzt das Hakenskript. Rueckgabe 0 gesetzt, 3 schon vorhanden (nichts
 *       geschrieben), 1 Fehler.
 *
 *   php rn_bestand.php --csv-aufraeumen <datei>
 *       (postupgrade.sh, E-E3 des Hausherrn 29.10.2026) Fasst aufeinanderfolgende gleiche Zeilen der
 *       Aufzeichnung zusammen (rn_csv_aufraeumen()): vorher liegt die Datei als <datei>.vor_2120 (0600)
 *       daneben; die Kopfzeile bleibt, keine andere Zeile aendert sich. Liegt die Sicherung schon da, geschieht
 *       nichts. Rueckgabe 0 (auch ohne Duplikate), 1 Fehler. Ausgabe: eine Zeile mit der Zaehlung.
 *
 * Unbekannter Schalter: Satz auf STDERR, Rueckgabe 2.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

$rn_was = isset($argv[1]) ? (string) $argv[1] : '';
if (!in_array($rn_was, array('--endpunkt-ein', '--csv-aufraeumen'), true)) {
    fwrite(STDERR, "rn_bestand.php: unbekannter Schalter. Erlaubt: --endpunkt-ein, --csv-aufraeumen <datei>\n");
    exit(2);
}

if (basename(dirname(__DIR__)) === 'plugins') {
    $rn_lib = dirname(dirname(dirname(__DIR__))) . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/rn_lib.php';
} else {
    $rn_lib = dirname(__DIR__) . '/webfrontend/htmlauth/rn_lib.php';
}
if (!is_file($rn_lib)) {
    echo "rn_lib.php nicht gefunden.\n";
    exit(1);
}
require_once $rn_lib;

if ($rn_was === '--csv-aufraeumen') {
    $rn_f = isset($argv[2]) ? (string) $argv[2] : '';
    $rn_name = basename($rn_f);
    if ($rn_f === '' || !is_file($rn_f) || is_link($rn_f) || !preg_match('/^database[2-4]?\.csv$/', $rn_name)) {
        echo $rn_name . ": keine Aufzeichnung dieses Plugins - nichts aufgeraeumt.\n";
        exit(1);
    }
    $rn_sich = $rn_f . '.vor_2120';
    if (file_exists($rn_sich)) {
        echo $rn_name . ": schon aufgeraeumt (" . basename($rn_sich) . " liegt daneben).\n";
        exit(0);
    }
    $rn_roh = @file_get_contents($rn_f);
    if (!is_string($rn_roh)) {
        echo $rn_name . ": nicht lesbar - nichts aufgeraeumt.\n";
        exit(1);
    }
    list($rn_neu, $rn_weg) = rn_csv_aufraeumen($rn_roh);
    $rn_zeilen = substr_count($rn_roh, "\n");
    /* Zuerst die Sicherung (unteilbar, 0600, zurueckgelesen) - ohne sie aendert sich nichts. */
    if (!rn_datei_schreiben($rn_sich, $rn_roh, 0600)) {
        echo $rn_name . ": die Sicherung " . basename($rn_sich) . " liess sich nicht schreiben - nichts aufgeraeumt.\n";
        exit(1);
    }
    if ($rn_weg === 0) {
        echo $rn_name . ": " . $rn_zeilen . " Zeilen, keine Duplikate (Sicherung " . basename($rn_sich) . ").\n";
        exit(0);
    }
    $rn_rechte = @fileperms($rn_f);
    if (!rn_datei_schreiben($rn_f, $rn_neu, $rn_rechte !== false ? ($rn_rechte & 0777) : 0644)) {
        echo $rn_name . ": liess sich nicht neu schreiben - unveraendert (Sicherung " . basename($rn_sich) . ").\n";
        exit(1);
    }
    echo $rn_name . ": " . $rn_weg . " aufeinanderfolgende gleiche Zeilen zusammengefasst (" . $rn_zeilen . " -> "
        . substr_count($rn_neu, "\n") . " Zeilen); die alte Datei liegt als " . basename($rn_sich) . " (0600) daneben.\n";
    exit(0);
}

/* --endpunkt-ein */
$rn_p = rn_paths(false);
if ($rn_p['home'] === '' || !is_file($rn_p['config'])) {
    echo "endpunkt_ein: keine Konfiguration gefunden - nichts gesetzt.\n";
    exit(1);
}
$rn_w = rn_config_einlesen($rn_p['config']);
if (!is_array($rn_w)) {
    echo "endpunkt_ein: config.php nicht lesbar - nichts gesetzt.\n";
    exit(1);
}
if (array_key_exists('endpunkt_ein', $rn_w)) {
    echo "endpunkt_ein steht schon (" . ((string) $rn_w['endpunkt_ein'] === 'Y' ? 'an' : 'aus') . ") - unveraendert.\n";
    exit(3);
}
$rn_cfg = rn_config_read(false);
$rn_cfg['endpunkt_ein'] = 'Y';
if (!rn_config_write($rn_cfg)) {
    echo "endpunkt_ein liess sich nicht setzen (config.php nicht schreibbar).\n";
    exit(1);
}
@chmod($rn_p['config'], 0600);
echo "endpunkt_ein = Y gesetzt (Bestand: der HTTP-Endpunkt bleibt offen; config.php und Zweitschrift, 0600).\n";
exit(0);
