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
 *
 * Gleichwert-Unterdrueckung (X-7, Welle-4-Bau, Entscheidung Nr. 19):
 * derselbe Sollwert fuer dasselbe Fahrzeug innerhalb von 60 s geht nicht noch
 * einmal an Renault - HTTP 200, <AKTION>;OK=1;UNVERAENDERT=1;SEIT_S=n, nichts
 * gesendet. Gilt fuer acnow/acoff (samt Zieltemperatur), chargenow/chargestop
 * und cmon/cmoff; nicht fuer abruf. Ein anderer Wert geht sofort hinaus (kein
 * 429). Laesst sich der Merker nicht fuehren: HTTP 503, GRUND=BREMSE_MERKER.
 *
 * Seit 2.1.20 (Gruppe 40, 2.3):
 * - endpunkt_ein = N (ab Werk bei Neuinstallation) -> jeder Aufruf ausser
 *   ?selftest bekommt nach der Tokenpruefung HTTP 403 RENAULT;OK=0;ERR=ENDPUNKT_AUS;
 *   ?selftest meldet zusaetzlich ;ENDPUNKT=AUS|EIN.
 * - Ein schaltender Befehl geht ueber rn_befehl_annehmen() OHNE Sperre (X-7 dort
 *   fuer alle Wege); der Abruf danach nur bei freier Abrufsperre
 *   (<logdir>/abruf.lock, dieselbe wie cron.03min/cron.10min), sonst die Zeile
 *   ABRUF=SKIP. Die Antwortzeilen bleiben die von 2.1.19.
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
/* Welche Lage gilt, entscheidet seit 2.1.11 der eigene Ablageort, nicht die
 * Reihenfolge einer Liste. Bis 2.1.10 stand hier eine Liste mit drei
 * Kandidaten, und der erste - drei Ebenen hinauf, dann htmlauth/plugins/ -
 * wurde auch aus einem ausgepackten Archiv heraus VOR der eigenen Bibliothek
 * geprueft. Drei Ebenen ueber einem Archiv liegt ein beliebiger fremder
 * Ordner, im Grenzfall die Laufwerkswurzel; in WSL gemessen
 * (Pruefung-Renault-NG-2.1.11, Fall W9) wurde ein Koeder dort geladen.
 * Installiert liegt diese Datei unter <home>/webfrontend/html/plugins/<ordner>,
 * im Archiv unter <archiv>/webfrontend/html. */
if (basename(dirname(__DIR__)) === 'plugins') {
    $rn_kandidaten = array(
        dirname(dirname(dirname(__DIR__))) . '/htmlauth/plugins/' . basename(__DIR__) . '/rn_lib.php',
    );
} else {
    $rn_kandidaten = array(dirname(__DIR__) . '/htmlauth/rn_lib.php');
}
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
/* bis 2.1.22 hiess dieses Feld $cfg: das LoxBerry-SDK ueberschreibt ein globales $cfg beim ersten Lesen von general.json (Ausfall MGiSmart 01.11.2026). */
$rn_lauf_cfg  = rn_config_read(false);
$soll = (string) $rn_lauf_cfg['aktionstoken'];
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
/* 2.1.23 (Fremdpruefung A1): !empty statt isset - bis 2.1.22 griff der Zweig auch bei ?selftest=0 und ?selftest=,
 * und ?selftest=0&aktion=abruf&token=... antwortete SELFTEST, statt abzurufen. */
if (!empty($_GET['selftest'])) {
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
       . ($rn_lauf_cfg['steuerung_ein'] === 'Y' ? 'EIN' : 'AUS')
       . ';FAHRZEUGE=' . count(rn_fahrzeuge($rn_lauf_cfg))
       . ';ENDPUNKT=' . ((string) $rn_lauf_cfg['endpunkt_ein'] === 'Y' ? 'EIN' : 'AUS'), 'INFO', 'Selbsttest beantwortet');
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

/* ------------------------- endpunkt_ein -------------------------
 *
 * Gruppe 40 (2.1.20, wie MG E-E1 A und SignalBot E-E8): der Endpunkt ist bei
 * Renault ganz ein Befehlsweg - es gibt keinen Lesezweig ohne Befehl
 * (Regeln/07 :303). Ab Werk (Neuinstallation) ist er aus; ein Update setzt den
 * Bestand auf "an" (postupgrade.sh, bin/rn_bestand.php), damit eine laufende
 * Loxone-Vorlage nicht stumm wird. Aus heisst: jeder Aufruf ausser ?selftest
 * bekommt nach der Tokenpruefung 403. Neue Anlagen nehmen Gateway V2 und die
 * Befehlsbruecke. */
if ((string) $rn_lauf_cfg['endpunkt_ein'] !== 'Y') {
    rn_ende(403, 'RENAULT;OK=0;ERR=ENDPUNKT_AUS', 'WARN',
            'abgewiesen - der Endpunkt ist ausgeschaltet (Reiter Einbindung in Loxone, Bisheriger Weg ueber HTTP)');
}

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
if (rn_befehl_schaltet($aktion) && $rn_lauf_cfg['steuerung_ein'] !== 'Y') {
    rn_ende(403, 'Die Steuerung ist ausgeschaltet (Vorgabe). Im Plugin unter '
               . 'Einstellungen, Abschnitt Schalten, einschalten. '
               . 'Lesende Aufrufe (aktion=abruf) sind davon nicht betroffen.',
               'WARN', 'abgewiesen - Steuerung ausgeschaltet, Befehl ' . $aktion);
}

/* --------------------------- Fahrzeug ---------------------------
 *
 * Bis 2.1.5 stand hier (int) $_GET['fahrzeug'] ohne Formpruefung. Eingaben
 * werden abgewiesen und gemeldet, nie still zurechtgebogen. */
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
$ziel = rn_fahrzeug($fahrzeug, $rn_lauf_cfg);
if (!$ziel) {
    rn_ende(400, 'Fahrzeug ' . $fahrzeug . ' ist nicht eingerichtet.', 'WARN',
            'abgewiesen - Fahrzeug ' . $fahrzeug . ' nicht eingerichtet');
}

if ($rn_lauf_cfg['username'] === '' || $rn_lauf_cfg['password'] === '' || $ziel['vin'] === '') {
    rn_ende(503, 'Zugangsdaten oder Fahrgestellnummer unvollstaendig - bitte im '
               . 'Plugin die Einstellungen ausfuellen.', 'ERROR',
               'abgewiesen - Zugangsdaten oder Fahrgestellnummer unvollstaendig');
}

/* abruf.php bindet seine Nachbardateien ueber relative Pfade ein - das
 * Arbeitsverzeichnis muss stimmen (siehe Kopf von 2.1.19). */
if (!@chdir($rn_htmlauth)) {
    rn_ende(500, 'Der Ordner htmlauth ist nicht erreichbar: ' . $rn_htmlauth,
            'ERROR', 'Ordner htmlauth nicht erreichbar');
}

/* ==================================================================
 * Der Befehl - OHNE Abrufsperre (Gruppe 40, 2.3, T3-1)
 * ==================================================================
 *
 * Seit 2.1.20 geht ein schaltender Befehl ueber rn_befehl_annehmen() (rn_lib.php)
 * - dieselbe Funktion wie Befehlsbruecke und Vorklimatisierung, X-7 dort an EINER
 * Stelle. Der Befehl wartet nicht auf den Cron: er sendet sofort. Danach der
 * Abruf - nur bei freier Abrufsperre, sonst ABRUF=SKIP (der Cron holt binnen 3
 * min). Bis 2.1.19 liefen Befehl und Abruf in einem require ohne Sperre, und ein
 * gleichzeitiger Cron-Lauf holte doppelt (Pruefbericht T3-1). Die Antwortzeilen
 * bleiben die von 2.1.19 (P-E-5).
 */
$_GET = array();                 // nichts Fremdes durchreichen
$rn_zeilen = array();
$rn_befehl = null;
if ($aktion !== 'abruf') {
    $rn_be = rn_befehl_annehmen($aktion, $fahrzeug, 'http', $rn_lauf_cfg);
    if ($rn_be[1] === 'UNVERAENDERT') {
        rn_ende(200, $rn_be[3], 'INFO', 'Aktion ' . $aktion . ' fuer Fahrzeug ' . $fahrzeug . ': derselbe Befehl ging '
            . 'vor kurzem hinaus - nichts gesendet (gleiche Sollwerte innerhalb von ' . RN_GLEICHWERT_S
            . ' s gehen nur einmal an Renault)');
    }
    if ($rn_be[1] === 'BREMSE_MERKER') {
        rn_ende(503, $rn_be[3] . "\n"
                   . 'Die Merkerdatei der Gleichwert-Unterdrueckung (' . basename(rn_gleichwert_datei())
                   . ') laesst sich nicht oeffnen oder schreiben - Sollwert-Befehle werden abgewiesen, '
                   . 'bis das behoben ist. Pruefen: Platz und Eigentuemer (loxberry) des Datenordners.',
                'ERROR', 'Aktion ' . $aktion . ' fuer Fahrzeug ' . $fahrzeug . ' abgewiesen - der Merker '
                   . 'der Gleichwert-Unterdrueckung laesst sich nicht fuehren (' . rn_gleichwert_datei() . ')');
    }
    if ($rn_be[1] !== 'OK' && strpos($rn_be[1], 'RENAULT_') !== 0) {
        rn_ende((int) $rn_be[2], $rn_be[3], 'ERROR', 'Aktion ' . $aktion . ' fuer Fahrzeug ' . $fahrzeug
            . ' NICHT ausgefuehrt (' . $rn_be[1] . ')');
    }
    $rn_befehl = ((int) $rn_be[0] === 1);
    $rn_zeilen[] = $rn_be[3];
}

/* ------------------- danach der Abruf, nur bei freier Sperre -------------------
 * 'ce' beim Oeffnen der Sperre (rn_abruf_sperre_nehmen()): exec_bl/exec_csf
 * erben den Griff nicht (Gedaechtnis "Sperre vererbt sich an Kinder"). */
$rn_sperre = rn_abruf_sperre_nehmen();
$rn_abruf_fehl = false;
$rn_code_abruf = 200;
if ($rn_sperre === null) {
    $rn_zeilen[] = 'ABRUF=SKIP';
} else {
    $rn_auftrag = array('aktion' => 'abruf', 'fahrzeug' => $fahrzeug);
    $rn_http_status = 200;           // abruf.php setzt ihn bei jedem Fehlerausgang
    /* Ein Absturz in abruf.php darf nicht als leerer HTTP 500 beim Miniserver
     * ankommen. Throwable faengt seit PHP 7 auch Error. */
    ob_start();
    try {
        require $rn_htmlauth . '/abruf.php';
        $rn_aus = trim((string) ob_get_clean());
    } catch (Throwable $rn_t) {
        $rn_aus = trim((string) ob_get_clean());
        flock($rn_sperre, LOCK_UN);
        fclose($rn_sperre);
        if (function_exists('renault_log')) {
            renault_log('ERROR', 'Endpunkt: abruf.php ist abgestuerzt: ' . $rn_t->getMessage()
                . ' (' . basename($rn_t->getFile()) . ':' . $rn_t->getLine() . ')');
        }
        rn_ende(500, implode("\n", $rn_zeilen) . ($rn_zeilen ? "\n" : '') . 'ABRUF;OK=0;ERR=' . $rn_t->getMessage()
                     . ' (' . basename($rn_t->getFile()) . ':' . $rn_t->getLine() . ')'
                     . ($rn_aus !== '' ? "\n" . $rn_aus : ''), 'ERROR',
                     'abruf.php ist abgestuerzt');
    }
    flock($rn_sperre, LOCK_UN);
    fclose($rn_sperre);
    if ($rn_aus !== '') { $rn_zeilen[] = $rn_aus; }
    $rn_code_abruf = (isset($rn_http_status) && (int) $rn_http_status >= 100
                      && (int) $rn_http_status < 600) ? (int) $rn_http_status : 200;
    $rn_abruf_fehl = !empty($rn_abruf_gescheitert);
}

/* Der Statuscode: bei einem Befehl entscheidet das Ergebnis des BEFEHLS
 * (Befund C2) - ein angenommener Befehl ist 200, auch wenn der Abruf danach
 * scheiterte; ein abgewiesener 502. Ohne Befehl (aktion=abruf) der Code des
 * Abrufs. */
if ($rn_befehl === true) {
    $rn_code = 200;
    $rn_was = $rn_abruf_fehl ? ': Befehl ausgefuehrt, Abruf danach gescheitert' : ' ausgefuehrt';
} elseif ($rn_befehl === false) {
    $rn_code = 502;
    $rn_was = ' NICHT ausgefuehrt';
} else {
    $rn_code = $rn_code_abruf;
    $rn_was = ($rn_code === 200) ? ' ausgefuehrt' : ' NICHT ausgefuehrt';
}
if ($rn_sperre === null) {
    $rn_was .= ' (Abruf uebersprungen - der laufende Abruf holt die Werte)';
}
$rn_ausgabe = implode("\n", $rn_zeilen);
rn_ende($rn_code, $rn_ausgabe !== '' ? $rn_ausgabe : 'OK',
        $rn_code !== 200 ? 'ERROR' : ($rn_abruf_fehl ? 'WARN' : 'INFO'),
        'Aktion ' . $aktion . ' fuer Fahrzeug ' . $fahrzeug . $rn_was);
