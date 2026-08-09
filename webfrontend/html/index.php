<?php
/**
 * Renault - Aktionsendpunkt fuer den Miniserver
 *
 * Liegt bewusst im unangemeldeten Bereich, damit Loxone ihn ohne
 * Zugangsdaten aufrufen kann - aber jeder Aufruf braucht das Token aus den
 * Einstellungen. Ohne Token wird nichts ausgefuehrt: sonst koennte jedes
 * Geraet im Netz die Vorklimatisierung starten.
 *
 * Aufruf:
 *   /plugins/renault_ng/index.php?token=<TOKEN>&aktion=acnow
 *
 * Antwort: Klartext, eine Zeile. HTTP 200 bei Erfolg, sonst 400/403/503.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../htmlauth/rn_lib.php';

function rn_ende($code, $text)
{
    http_response_code($code);
    echo $text . "\n";
    exit;
}

$cfg = rn_config_read();

$soll = $cfg['aktionstoken'];
if ($soll === '') {
    rn_ende(403, 'Kein Aktionstoken eingerichtet. Die Bedienoberflaeche des '
               . 'Plugins einmal aufrufen - dann wird eines erzeugt.');
}

$ist = isset($_GET['token']) ? (string) $_GET['token'] : '';
// hash_equals vergleicht in gleichbleibender Zeit; ein einfaches ==
// liesse sich ueber die Antwortzeit Zeichen fuer Zeichen erraten.
if (!hash_equals($soll, $ist)) {
    rn_ende(403, 'Token falsch.');
}

$aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : '';
if (!array_key_exists($aktion, rn_befehle())) {
    rn_ende(400, 'Unbekannte Aktion. Erlaubt: '
               . implode(', ', array_keys(rn_befehle())));
}

if ($cfg['username'] === '' || $cfg['password'] === '' || $cfg['vin'] === '') {
    rn_ende(503, 'Zugangsdaten unvollstaendig - bitte im Plugin die '
               . 'Einstellungen ausfuellen.');
}

// abruf.php liest seine Nachbardateien (config.php, api-keys.php, session,
// database.csv) ueber relative Pfade. Deshalb muss das Arbeitsverzeichnis
// stimmen, sonst legt es die Dateien im Web-Wurzelverzeichnis an.
$htmlauth = realpath(__DIR__ . '/../htmlauth');
if ($htmlauth === false || !@chdir($htmlauth)) {
    rn_ende(500, 'Der Ordner htmlauth ist nicht erreichbar.');
}

// abruf.php wertet $_GET aus. "abruf" ist der reine Datenabruf ohne Befehl.
$_GET = array();
if ($aktion !== 'abruf') {
    $_GET[$aktion] = '1';
}
$_GET['cron'] = '1';   // sorgt fuer Klartext statt HTML

ob_start();
require $htmlauth . '/abruf.php';
$ausgabe = trim((string) ob_get_clean());

rn_ende(200, $ausgabe !== '' ? $ausgabe : 'OK');
