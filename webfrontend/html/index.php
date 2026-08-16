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
 *   /plugins/renault_ng/index.php?token=<TOKEN>&aktion=acnow[&fahrzeug=N]
 *   /plugins/renault_ng/index.php?selftest=1&token=<TOKEN>
 *
 * Antwort: Klartext, eine Zeile je Vorgang. HTTP 200 bei Erfolg, sonst
 * 400/403/500/503.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

/* ==================================================================
 * DIE BIBLIOTHEK FINDEN - in BEIDEN Ablagen
 * ==================================================================
 *
 * Bis 2.0.6 stand hier schlicht
 *
 *     require_once __DIR__ . '/../htmlauth/rn_lib.php';
 *
 * Das stimmt im entpackten Archiv, wo html/ und htmlauth/ nebeneinander
 * liegen. Auf einem installierten LoxBerry liegen sie in GETRENNTEN
 * Baeumen:
 *
 *     <home>/webfrontend/html/plugins/<ordner>/       <- diese Datei
 *     <home>/webfrontend/htmlauth/plugins/<ordner>/   <- die Bibliothek
 *
 * __DIR__ . '/../htmlauth' ergab dort webfrontend/html/plugins/htmlauth -
 * ein Verzeichnis, das es nicht gibt. require_once brach fatal ab, und
 * weil zwei Zeilen darueber display_errors abgeschaltet wird, kam beim
 * Miniserver ein leerer HTTP 500 an: keine Meldung, kein Protokolleintrag,
 * nichts. Saemtliche Loxone-Befehle dieses Plugins haben deshalb auf einer
 * echten Anlage nie gewirkt - gemessen am 17.08.2026 mit Gegenprobe im
 * entpackten Archiv (dort antwortete derselbe Aufruf regulaer).
 *
 * Dieselbe Klasse hatte das Heimkino-Plugin bis 1.2.10 und Intercom bis
 * 2.1.12. Die Kandidatenliste unten ist von dort uebernommen; sie trifft
 * beide Ablagen. Und der Selbsttest weiter unten haette es am ersten Tag
 * gezeigt.
 */
$rn_kandidaten = array(
    dirname(dirname(dirname(__DIR__))) . '/htmlauth/plugins/' . basename(__DIR__) . '/rn_lib.php',
    dirname(dirname(__DIR__)) . '/htmlauth/plugins/' . basename(__DIR__) . '/rn_lib.php',
    dirname(__DIR__) . '/htmlauth/rn_lib.php',
);
$rn_htmlauth = '';
foreach ($rn_kandidaten as $rn_k) {
    if (is_file($rn_k)) {
        require_once $rn_k;
        $rn_htmlauth = dirname($rn_k);
        break;
    }
}
if ($rn_htmlauth === '') {
    http_response_code(500);
    echo "rn_lib.php nicht gefunden. Erwartet unter htmlauth/plugins/"
       . basename(__DIR__) . "/ - gesucht wurde in:\n";
    foreach ($rn_kandidaten as $rn_k) { echo '  ' . $rn_k . "\n"; }
    exit;
}

function rn_ende($code, $text)
{
    http_response_code($code);
    echo $text . "\n";
    exit;
}

$cfg  = rn_config_read();
$soll = (string) $cfg['aktionstoken'];
$ist  = isset($_GET['token']) ? (string) $_GET['token'] : '';

/* ==================================================================
 * Selbsttest - prueft NUR das Token
 * ==================================================================
 *
 * Ob das in Loxone eingetragene Token noch stimmt, liess sich bis 2.0.6
 * nur herausfinden, indem man wirklich schaltete - also die
 * Vorklimatisierung startete. Das ist der falsche Preis fuer eine
 * Auskunft, und es ist der Grund, warum der Defekt oben ein Jahr lang
 * unentdeckt blieb.
 *
 * Der Selbsttest ruehrt das Fahrzeug nicht an: keine Verbindung, kein
 * Schreibzugriff. Und er ist keine Abkuerzung an der Sicherheit vorbei -
 * ein falsches Token bekommt dieselbe Abweisung wie sonst auch.
 */
if (isset($_GET['selftest'])) {
    if ($soll === '') {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET\n";
        exit;
    }
    if (!hash_equals($soll, $ist)) {
        http_response_code(403);
        echo "SELFTEST;OK=0;ERR=TOKEN\n";
        exit;
    }
    echo "SELFTEST;OK=1;TOKEN=OK;STEUERUNG="
       . ($cfg['steuerung_ein'] === 'Y' ? 'EIN' : 'AUS')
       . ';FAHRZEUGE=' . count(rn_fahrzeuge($cfg)) . "\n";
    exit;
}

/* ---------------------------- Token ---------------------------- */
if ($soll === '') {
    rn_ende(403, 'Kein Aktionstoken eingerichtet. Die Bedienoberflaeche des '
               . 'Plugins einmal aufrufen - dann wird eines erzeugt.');
}
// hash_equals vergleicht in gleichbleibender Zeit; ein einfaches ==
// liesse sich ueber die Antwortzeit Zeichen fuer Zeichen erraten.
if (!hash_equals($soll, $ist)) {
    rn_ende(403, 'Token falsch.');
}

/* ---------------------------- Aktion ---------------------------- */
$aktion = isset($_GET['aktion']) ? (string) $_GET['aktion'] : '';
$befehle = rn_befehle();
if (!array_key_exists($aktion, $befehle)) {
    rn_ende(400, 'Unbekannte Aktion. Erlaubt: ' . implode(', ', array_keys($befehle)));
}

/* Schaltende Befehle verlangen, dass die Steuerung eingeschaltet ist.
 * Ab Werk steht sie aus - ein frisch installiertes Plugin soll nicht aus
 * dem Stand heraus die Vorklimatisierung starten koennen. Die Abweisung
 * sagt ausdruecklich, wo der Schalter sitzt; eine Abweisung, die den
 * Grund verschweigt, kostet eine Stunde Suchen. */
if (rn_befehl_schaltet($aktion) && $cfg['steuerung_ein'] !== 'Y') {
    rn_ende(403, 'Die Steuerung ist ausgeschaltet (Vorgabe). Im Plugin unter '
               . 'Einstellungen, Abschnitt Schalten, einschalten. '
               . 'Lesende Aufrufe (aktion=abruf) sind davon nicht betroffen.');
}

/* --------------------------- Fahrzeug --------------------------- */
$fahrzeug = isset($_GET['fahrzeug']) ? (int) $_GET['fahrzeug'] : 1;
if ($fahrzeug < 1 || $fahrzeug > RN_MAX_FAHRZEUGE) {
    rn_ende(400, 'Ungueltige Fahrzeugnummer. Erlaubt: 1 bis ' . RN_MAX_FAHRZEUGE . '.');
}
$ziel = rn_fahrzeug($fahrzeug, $cfg);
if (!$ziel) {
    rn_ende(400, 'Fahrzeug ' . $fahrzeug . ' ist nicht eingerichtet.');
}

if ($cfg['username'] === '' || $cfg['password'] === '' || $ziel['vin'] === '') {
    rn_ende(503, 'Zugangsdaten oder Fahrgestellnummer unvollstaendig - bitte im '
               . 'Plugin die Einstellungen ausfuellen.');
}

/* ==================================================================
 * abruf.php aufrufen
 * ==================================================================
 *
 * abruf.php bindet seine Nachbardateien (api-keys.php, loxberry_web.php,
 * phpMQTT) ueber relative Pfade ein. Deshalb muss das Arbeitsverzeichnis
 * stimmen.
 *
 * Der Auftrag wird als ARRAY uebergeben und nicht mehr ueber $_GET.
 * Bis 2.0.6 setzte diese Datei $_GET['cron'] = '1' - allein, um
 * Klartext statt HTML zu bekommen. abruf.php las daraus aber die
 * Betriebsart "Cron" und lief damit in die Intervallsperre, und die stand
 * VOR den Befehlsbloecken. Ein Befehl kam nur in etwa einer von sechs
 * Minuten durch; in den uebrigen antwortete das Plugin mit HTTP 200 und
 * dem Text "INTERVAL NOT REACHED" - einer Erfolgsmeldung fuer eine nicht
 * ausgefuehrte Handlung. Ausgabeformat und Betriebsart haengen jetzt
 * nicht mehr am selben Merker.
 */
if (!@chdir($rn_htmlauth)) {
    rn_ende(500, 'Der Ordner htmlauth ist nicht erreichbar: ' . $rn_htmlauth);
}

$_GET = array();                 // nichts Fremdes an abruf.php durchreichen
$rn_auftrag = array('aktion' => $aktion, 'fahrzeug' => $fahrzeug);

/* Ein Absturz in abruf.php darf nicht wieder als leerer HTTP 500 beim
 * Miniserver ankommen - das war der Fehler aus Punkt 1 der README, und er
 * blieb genau deshalb ein Jahr lang unentdeckt. Fehlt etwa die
 * curl-Erweiterung, soll dort eine lesbare Zeile stehen und nicht nichts.
 * Throwable faengt seit PHP 7 auch Error, nicht nur Exception. */
ob_start();
try {
    require $rn_htmlauth . '/abruf.php';
    $ausgabe = trim((string) ob_get_clean());
} catch (Throwable $rn_t) {
    $ausgabe = trim((string) ob_get_clean());
    if (function_exists('renault_log')) {
        renault_log('ERROR', 'Endpunkt: abruf.php ist abgestuerzt: ' . $rn_t->getMessage()
            . ' (' . basename($rn_t->getFile()) . ':' . $rn_t->getLine() . ')');
    }
    rn_ende(500, 'ABRUF;OK=0;ERR=' . $rn_t->getMessage()
                 . ' (' . basename($rn_t->getFile()) . ':' . $rn_t->getLine() . ')'
                 . ($ausgabe !== '' ? "\n" . $ausgabe : ''));
}

rn_ende(200, $ausgabe !== '' ? $ausgabe : 'OK');
