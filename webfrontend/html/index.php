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

/* ==================================================================
 * PROTOKOLL - auf JEDEM Weg, auch auf jedem Abweisungsweg
 * ==================================================================
 *
 * Bis 2.1.5 schrieb diese Datei genau eine Zeile, und die stand im
 * catch-Zweig. Gemessen: nach vier Abweisungen (403/400/503) war
 * log/plugins/<ordner>/ leer. Damit ist "das Geraet ruft nicht an" nicht
 * von "es ruft an und wird abgewiesen" zu unterscheiden - ein falsch
 * abgeschriebenes Token in Loxone ist am Geraet nicht auffindbar, und ein
 * Durchprobieren von Token bleibt unsichtbar.
 *
 * Vor der Tokenpruefung geht die Zeile in das Fehlerprotokoll des
 * Webservers, NICHT in das des Plugins: das Plugin-Protokoll zu schreiben
 * hiesse, ein Verzeichnis anzulegen, und der unangemeldete Endpunkt legt
 * nichts an (siehe rn_config_read(false) unten). Nach der Tokenpruefung hat
 * sich der Aufrufer ausgewiesen; ab da schreibt das Plugin selbst.
 *
 * Das Token steht NIE in einer dieser Zeilen - auch nicht gekuerzt. Ein
 * Protokoll, das Geheimnisse mitschreibt, verlagert das Problem in eine
 * Datei, die laenger lebt.
 */
$rn_ausgewiesen = false;

function rn_ep_log($stufe, $text)
{
    global $rn_ausgewiesen;
    $anrufer = isset($_SERVER['REMOTE_ADDR'])
        ? preg_replace('/[^0-9a-fA-F.:]/', '', (string) $_SERVER['REMOTE_ADDR'])
        : '-';
    $zeile = 'Endpunkt (' . ($anrufer === '' ? '-' : $anrufer) . '): ' . $text;
    if ($rn_ausgewiesen && function_exists('rn_melden')) {
        rn_melden($stufe, $zeile);
        return;
    }
    error_log('renault_ng [' . $stufe . '] ' . $zeile);
}

function rn_ende($code, $text, $stufe = 'WARN', $grund = '')
{
    rn_ep_log($stufe, ($grund !== '' ? $grund : $text) . ' [HTTP ' . $code . ']');
    http_response_code($code);
    echo $text . "\n";
    exit;
}

/* ==================================================================
 * Ein fataler Fehler darf nicht als LEERER HTTP 500 ankommen
 * ==================================================================
 *
 * Der try/catch weiter unten faengt Throwable - also auch Error. Was er
 * NICHT faengt, ist ein E_COMPILE_ERROR: ein fehlgeschlagenes require oder
 * ein Parsefehler in abruf.php, logger.php, rn_lib.php oder einer der drei
 * Kernbibliotheken. Gemessen, in beide Richtungen: fehlt phpMQTT, endet der
 * Lauf mit Rueckgabewert 255 und NULL Byte Ausgabe; fehlt nur curl, kommt
 * "ABRUF;OK=0;ERR=Call to undefined function curl_init()" - der catch greift
 * also, nur nicht fuer diese Klasse.
 *
 * Genau das Bild (leerer HTTP 500 beim Miniserver) erklaert der Kommentar
 * weiter unten fuer behoben. Der Abschluss-Aufnehmer schliesst die Luecke.
 */
register_shutdown_function(function () {
    $f = error_get_last();
    if ($f === null || !in_array($f['type'],
        array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'ABRUF;OK=0;ERR=FATAL ' . basename((string) $f['file']) . ':' . (int) $f['line']
       . ' ' . (string) $f['message'] . "\n";
});

/* rn_config_read(false) legt NICHTS an.
 *
 * Bis 2.1.5 entstanden aus einem einzigen tokenlosen Aufruf sechs
 * Verzeichnisse im LoxBerry-Baum - gemessen an einem frischen Baum:
 * config/plugins, config/plugins/<ordner>, data/plugins,
 * data/plugins/<ordner>, log/plugins, log/plugins/<ordner>, mit 0775. Der
 * Hausstandard sagt: der unangemeldete Endpunkt legt nichts an, und was ein
 * Endpunkt anlegt, legt er nach der Tokenpruefung an. */
$cfg  = rn_config_read(false);
$soll = (string) $cfg['aktionstoken'];
/* is_string() vor der Umwandlung: ?token[]=x ist ein Feld, und (string) auf
 * ein Feld ergibt "Array" - unter PHP 8 mit einer Warnung, die vor
 * http_response_code() hinausginge. */
$ist  = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';

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
        rn_ende(403, 'SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET', 'WARN',
                'Selbsttest abgewiesen - kein Aktionstoken eingerichtet');
    }
    if (!hash_equals($soll, $ist)) {
        rn_ende(403, 'SELFTEST;OK=0;ERR=TOKEN', 'WARN',
                'Selbsttest abgewiesen - falsches Token');
    }
    $rn_ausgewiesen = true;
    rn_ende(200, 'SELFTEST;OK=1;TOKEN=OK;STEUERUNG='
       . ($cfg['steuerung_ein'] === 'Y' ? 'EIN' : 'AUS')
       . ';FAHRZEUGE=' . count(rn_fahrzeuge($cfg)), 'INFO', 'Selbsttest beantwortet');
}

/* ---------------------------- Token ---------------------------- */
if ($soll === '') {
    rn_ende(403, 'Kein Aktionstoken eingerichtet. Die Bedienoberflaeche des '
               . 'Plugins einmal aufrufen - dann wird eines erzeugt.', 'ERROR',
               'abgewiesen - kein Aktionstoken eingerichtet');
}
// hash_equals vergleicht in gleichbleibender Zeit; ein einfaches ==
// liesse sich ueber die Antwortzeit Zeichen fuer Zeichen erraten.
if (!hash_equals($soll, $ist)) {
    // Die Laenge des abgewiesenen Wertes sagt genug; der Wert selbst
    // gehoert nicht ins Protokoll.
    rn_ende(403, 'Token falsch.', 'WARN',
            'abgewiesen - falsches Token (' . strlen($ist) . ' Zeichen)');
}
$rn_ausgewiesen = true;

/* ---------------------------- Aktion ---------------------------- */
$aktion = (isset($_GET['aktion']) && is_string($_GET['aktion'])) ? $_GET['aktion'] : '';
$befehle = rn_befehle();
if (!array_key_exists($aktion, $befehle)) {
    rn_ende(400, 'Unbekannte Aktion. Erlaubt: ' . implode(', ', array_keys($befehle)),
            'WARN', 'abgewiesen - unbekannte Aktion');
}

/* Schaltende Befehle verlangen, dass die Steuerung eingeschaltet ist.
 * Ab Werk steht sie aus - ein frisch installiertes Plugin soll nicht aus
 * dem Stand heraus die Vorklimatisierung starten koennen. Die Abweisung
 * sagt ausdruecklich, wo der Schalter sitzt; eine Abweisung, die den
 * Grund verschweigt, kostet eine Stunde Suchen. */
if (rn_befehl_schaltet($aktion) && $cfg['steuerung_ein'] !== 'Y') {
    rn_ende(403, 'Die Steuerung ist ausgeschaltet (Vorgabe). Im Plugin unter '
               . 'Einstellungen, Abschnitt Schalten, einschalten. '
               . 'Lesende Aufrufe (aktion=abruf) sind davon nicht betroffen.',
               'WARN', 'abgewiesen - Steuerung ausgeschaltet, Befehl ' . $aktion);
}

/* --------------------------- Fahrzeug ---------------------------
 *
 * Bis 2.1.5 stand hier (int) $_GET['fahrzeug'] ohne Formpruefung. Gemessen
 * unter 7.4.33 und 8.4.24, jeweils ohne Warnung: "2abc", "2.9", " 2" und
 * "2e0" wurden alle zu 2 - und ein FELD (?fahrzeug[]=2) zu 1. Damit hielte
 * "…&aktion=chargestop&fahrzeug[]=2" das Laden an Fahrzeug 1 an, und ein
 * Tippfehler in einer Loxone-Adresse traefe stillschweigend ein Fahrzeug,
 * statt abgewiesen zu werden. Eingaben werden abgewiesen und gemeldet, nie
 * still zurechtgebogen. */
$fahrzeug = 1;
if (isset($_GET['fahrzeug'])) {
    if (!is_string($_GET['fahrzeug'])
        || preg_match('/^[0-9]{1,2}$/', $_GET['fahrzeug']) !== 1) {
        rn_ende(400, 'Ungueltige Fahrzeugnummer. Erlaubt: 1 bis ' . RN_MAX_FAHRZEUGE
                   . ' (nur Ziffern).', 'WARN',
                   'abgewiesen - Fahrzeugnummer nicht wohlgeformt');
    }
    $fahrzeug = (int) $_GET['fahrzeug'];
}
if ($fahrzeug < 1 || $fahrzeug > RN_MAX_FAHRZEUGE) {
    rn_ende(400, 'Ungueltige Fahrzeugnummer. Erlaubt: 1 bis ' . RN_MAX_FAHRZEUGE . '.',
            'WARN', 'abgewiesen - Fahrzeugnummer ausserhalb 1 bis ' . RN_MAX_FAHRZEUGE);
}
$ziel = rn_fahrzeug($fahrzeug, $cfg);
if (!$ziel) {
    rn_ende(400, 'Fahrzeug ' . $fahrzeug . ' ist nicht eingerichtet.', 'WARN',
            'abgewiesen - Fahrzeug ' . $fahrzeug . ' nicht eingerichtet');
}

if ($cfg['username'] === '' || $cfg['password'] === '' || $ziel['vin'] === '') {
    rn_ende(503, 'Zugangsdaten oder Fahrgestellnummer unvollstaendig - bitte im '
               . 'Plugin die Einstellungen ausfuellen.', 'ERROR',
               'abgewiesen - Zugangsdaten oder Fahrgestellnummer unvollstaendig');
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
    rn_ende(500, 'Der Ordner htmlauth ist nicht erreichbar: ' . $rn_htmlauth,
            'ERROR', 'Ordner htmlauth nicht erreichbar');
}

$_GET = array();                 // nichts Fremdes an abruf.php durchreichen
$rn_auftrag = array('aktion' => $aktion, 'fahrzeug' => $fahrzeug);
$rn_http_status = 200;           // abruf.php setzt ihn bei jedem Fehlerausgang

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
                 . ($ausgabe !== '' ? "\n" . $ausgabe : ''), 'ERROR',
                 'abruf.php ist abgestuerzt');
}

/* Der Statuscode kommt aus abruf.php, nicht aus dem blossen Umstand, dass
 * der require zurueckgekehrt ist.
 *
 * Bis 2.1.5 stand hier rn_ende(200, …) - und damit antwortete der Endpunkt
 * auch auf LOGIN FAILED, NO CREDENTIALS, NO VIN, STEUERUNG AUS und OK=0 mit
 * HTTP 200. Das ist wortgleich die Klasse, die der Kommentar oben fuer
 * behoben erklaert: eine Erfolgsmeldung fuer eine nicht ausgefuehrte
 * Handlung. Loxone wertet nur den Code aus. */
$rn_code = (isset($rn_http_status) && (int) $rn_http_status >= 100
            && (int) $rn_http_status < 600) ? (int) $rn_http_status : 200;
rn_ende($rn_code, $ausgabe !== '' ? $ausgabe : 'OK',
        $rn_code === 200 ? 'INFO' : 'ERROR',
        'Aktion ' . $aktion . ' fuer Fahrzeug ' . $fahrzeug
        . ($rn_code === 200 ? ' ausgefuehrt' : ' NICHT ausgefuehrt'));
