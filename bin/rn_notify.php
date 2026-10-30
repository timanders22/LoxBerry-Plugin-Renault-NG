<?php
/**
 * Renault NG - Meldung in den LoxBerry-Benachrichtigungsbereich legen (seit 2.1.20, Gruppe 40, 3.2)
 *
 * Aufruf:  php rn_notify.php <Schwere 1-7> <Text> [Pluginordner]
 *
 * Aufgerufen vom Minutentakt (abruf.php takt -> rn_vorklima_takt() -> rn_benachrichtigen()), wenn die
 * Vorklimatisierung fuer eine Abfahrt aufgegeben wird - hoechstens einmal je Abfahrt; den Merker fuehrt die
 * Bibliothek (vorklima.json).
 *
 * Warum ein eigenes Skript und nicht notify_ext() im Takt selbst: keine phplib des LoxBerry laedt
 * loxberry_log.php von allein (am Geraet gemessen, Gedaechtnis "notify_ext() nie erreicht"), und die
 * LoxBerry-Bibliothek setzt beim Einbinden globale Variablen und Funktionen, die im Takt nichts zu suchen
 * haben. Aufbau wortgleich aus MGiSmart 1.1.28 bin/mg_notify.php (dort aus BatterieBMS/AudiConnect); geaendert
 * sind nur Anzeigename, Rueckfallname und dieser Kopf.
 *
 * Rueckgabewert 0 = abgelegt, 1 = nicht moeglich.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen (config/plugins, data/plugins UND
 * config/system/general.json). DIESER BLOCK STEHT VOR SEINEM AUFRUF. */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

$home = getenv('LBHOMEDIR');
if (!$home || !is_dir($home . '/config/plugins') || !is_dir($home . '/data/plugins')) {
    $home = lb_wurzel_ermitteln();
}
if (!$home) {
    fwrite(STDERR, "Es wurde kein LoxBerry-Wurzelverzeichnis gefunden - es wurde keine Meldung abgesetzt.\n");
    exit(1);
}
$sdk = $home . '/libs/phplib/loxberry_log.php';
if (!file_exists($sdk)) {
    fwrite(STDERR, "LoxBerry-Bibliothek nicht gefunden: " . $sdk . "\n");
    exit(1);
}
require_once $home . '/libs/phplib/loxberry_system.php';
require_once $sdk;

$schwere = isset($argv[1]) && preg_match('/^[0-9]+$/', (string) $argv[1]) ? (int) $argv[1] : 4;
$text    = isset($argv[2]) ? (string) $argv[2] : '';
if (trim($text) === '') {
    fwrite(STDERR, "Kein Text angegeben.\n");
    exit(1);
}

$paket = isset($argv[3]) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $argv[3]) : '';
if ($paket === '') {
    $paket = (string) getenv('LBPPLUGINDIR');
}
if (!$paket) {
    $paket = 'renault_ng';
}

if (!function_exists('notify_ext')) {
    fwrite(STDERR, "notify_ext() steht in dieser LoxBerry-Fassung nicht bereit.\n");
    exit(1);
}

notify_ext(array(
    'PACKAGE'  => $paket,
    'NAME'     => 'Renault NG',
    'MESSAGE'  => $text,
    'SEVERITY' => $schwere,
));

exit(0);
