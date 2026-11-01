<?php
/**
 * Renault - gemeinsame Funktionen der Oberflaeche
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins, data/plugins UND config/system/general.json traegt. Das
 * trifft die uebliche Installation genauso wie eine an einem anderen Ort;
 * aus einem entpackten Archiv heraus findet es nichts und gibt einen
 * Leerstring zurueck, den der Aufrufer abfangen muss.
 *
 * general.json ist die entscheidende Bedingung. Bis 2.1.10 genuegten
 * config/plugins und webfrontend - genau diese Ordner hinterlaesst ein
 * Pruefstand auf einem Arbeitsrechner, und am 05.09.2026 hat eine solche
 * Suche dort die Laufwerkswurzel als "LoxBerry" erkannt und Daten geloescht
 * (Regeln/06). In WSL gemessen (Pruefung-Renault-NG-2.1.11, Fall W1): in
 * einem fremden Baum ohne general.json nahm abruf.php den Baum als Wurzel
 * und legte dort Ordner und Protokoll an.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
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

/* Die Wurzel in der Reihenfolge der Hausregel: erst die Umgebung, dann die
 * Suche - und danach nichts mehr. Rueckgabe '' heisst "keine Wurzel".
 *
 * Ein gesetztes LBHOMEDIR gilt mit config/plugins UND data/plugins darunter;
 * general.json wird hier nicht verlangt, damit die Attrappen der
 * Pruefwerkzeuge (Werkzeuge/lb) weiter tragen. Bis 2.1.10 genuegte ein
 * vorhandenes Verzeichnis. */
/* Gemeinsame Sprachausgabe (Abschrift von Werkzeuge/gemeinsam/sprachausgabe.php, Nr. 36 b, seit
 * 2.1.16; seit 2.1.20 Stamm 1.2.1 mit weiteren Ausgabezielen, rn_mit_zielen(), Welle Mehrfach-Ziele).
 * Liegt neben dieser Datei; sie schuetzt sich selbst gegen doppeltes Laden. */
require_once __DIR__ . '/sprachausgabe.php';

function rn_lbhome()
{
    $h = rtrim((string) getenv('LBHOMEDIR'), '/');
    if ($h !== '' && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return $h;
    }
    return lb_wurzel_ermitteln();
}

/**
 * Die Pfade des Plugins.
 *
 * $anlegen = false legt KEINE Verzeichnisse an. Der unangemeldete Endpunkt
 * ruft so auf: bis 2.1.5 entstanden bei jedem tokenlosen Aufruf sechs
 * Verzeichnisse im LoxBerry-Baum (config/plugins, config/plugins/<ordner>,
 * data/plugins, data/plugins/<ordner>, log/plugins, log/plugins/<ordner>),
 * gemessen an einem frischen Baum. Der Hausstandard sagt: was ein Endpunkt
 * anlegt, legt er NACH der Tokenpruefung an - und der unangemeldete legt gar
 * nichts an.
 *
 * Das Ergebnis wird nur dann gemerkt, wenn wirklich angelegt werden durfte:
 * sonst merkte sich ein Endpunktaufruf die Pfade, und ein spaeterer Aufruf
 * mit Token faende die Ordner nicht vor.
 */
function rn_paths($anlegen = true)
{
    static $p = null;
    static $angelegt = false;
    if ($p !== null && ($angelegt || !$anlegen)) {
        return $p;
    }
    $home = rn_lbhome();
    $eigen = dirname(__FILE__);

    /* ==================================================================
     * WO DIE NUTZDATEN LIEGEN - und warum sie umgezogen sind
     * ==================================================================
     *
     * Bis 1.4 lagen Konfiguration, Sitzung, Ladehistorie und Protokoll
     * NEBEN dem Programm, in webfrontend/htmlauth. Genau diesen Ordner
     * loescht LoxBerry bei jedem Plugin-Update und legt ihn neu an - er
     * gehoert zum Programm, nicht zu den Daten.
     *
     * Seit 1.6.0 liegen die Daten dort, wo LoxBerry sie ohnehin stehen
     * laesst:
     *
     *     config/plugins/<ordner>/   Konfiguration (0600, enthaelt das
     *                                Renault-Passwort)
     *     data/plugins/<ordner>/     Anmeldung, Sitzungen, Ladehistorie
     *     log/plugins/<ordner>/      Protokoll
     *
     * 'eigen' bleibt als Ablageort des PROGRAMMS erhalten - api-keys.php
     * und die Bibliotheken liegen weiterhin dort, und die duerfen beim
     * Update auch ersetzt werden.
     * ================================================================== */
    // Der Ordnername wird ERMITTELT, nicht eingetragen: haengt LoxBerry bei
    // der Installation einen Zaehler an (renault_ng_01, weil der Name schon
    // belegt war), zeigten sonst alle Pfade auf die Erstinstallation.
    //
    // LBPPLUGINDIR ist die Auskunft von LoxBerry selbst und hat Vorrang. Ob
    // die Pfade der ANLAGE gelten, entscheidet seit 2.1.11 der Archivmodus
    // unten: bis 2.1.10 wurde aus einem ausgepackten Archiv heraus der Name
    // 'htmlauth' zum Ordnernamen, und abruf.php legte config/plugins/htmlauth,
    // data/plugins/htmlauth und log/plugins/htmlauth in der Anlage an (in WSL
    // gemessen, Pruefung-Renault-NG-2.1.11, Fall W2).
    $lbp = (string) getenv('LBPPLUGINDIR');
    $lbp_gilt = ($lbp !== '' && $lbp !== '.');
    $ordner = $lbp_gilt ? $lbp : basename(__DIR__);
    if ($ordner === '' || $ordner === '.') { $ordner = 'renault_ng'; }

    /* Archivmodus. Die Pfade DER ANLAGE gelten nur, wenn diese Bibliothek
     * dort installiert liegt (<Wurzel>/webfrontend/htmlauth/plugins/<ordner>,
     * physisch verglichen) oder der Aufrufer Wurzel UND Ordner ausdruecklich
     * nennt ($LBHOMEDIR und $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge
     * mit ihrer Attrappe, und so ruft uninstall/uninstall den Abraeumer).
     * Sonst ist das ein ausgepacktes Archiv oder ein Pruefordner: alles
     * bleibt in dessen eigenem Ordner, und abruf.php/history.php steigen aus
     * (rn_ohne_anlage()).
     *
     * Bis 2.1.10 nahm ein Archiv unterhalb einer echten Wurzel diese Wurzel -
     * mit $LBHOMEDIR allein, wie es am Geraet in /etc/environment steht, und
     * ohne Umgebung ueber die Suche ebenso (Faelle W2, W3). Ohne jede Wurzel
     * wurden die Pfade mit leerem home zusammengesetzt und lauteten
     * /config/plugins/..., /data/plugins/..., /log/plugins/... - ab der
     * Laufwerkswurzel, samt mkdir (Fall W8). Bauart wie tb_paths() der
     * Linie Spotpreis-Tibber 0.9.19. */
    $gefunden = $home;
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/htmlauth/plugins/' . basename(__DIR__));
        $ist  = @realpath(__DIR__);
        $installiert   = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) { $home = ''; }
    }
    if ($home === '') {
        $basis   = dirname(dirname(__DIR__));
        $konf    = $basis . '/config';
        $daten   = $basis . '/data';
        $prot    = $basis . '/log';
        $sich    = $basis . '/config.backup.config.php';
        $general = '';
    } else {
        $konf    = $home . '/config/plugins/' . $ordner;
        $daten   = $home . '/data/plugins/'   . $ordner;
        $prot    = $home . '/log/plugins/'    . $ordner;
        $sich    = $home . '/config/plugins/' . $ordner . '.backup.config.php';
        $general = $home . '/config/system/general.json';
    }
    if ($anlegen) {
        foreach (array($konf, $daten, $prot) as $d) {
            if (!is_dir($d)) { @mkdir($d, 0775, true); }
        }
        $angelegt = true;
    }

    $p = array(
        'home'    => $home,
        'plugin'  => $ordner,
        'eigen'   => $eigen,
        'konfdir' => $konf,
        'datadir' => $daten,
        'logdir'  => $prot,
        'config'  => $konf  . '/config.php',
        /* Die Zweitschrift liegt NEBEN dem Konfigordner, nicht darin.
         *
         * Bis 2.0.6 stand hier config/plugins/<ordner>/config.php.backup -
         * also im selben Ordner wie die Konfiguration, obwohl der Kommentar
         * darueber "ausserhalb des Plugin-Ordners" behauptete. Bei einer
         * Deinstallation raeumt LoxBerry config/plugins/<ordner>/ mitsamt
         * Inhalt ab; die Sicherung ging dabei mit und sah trotzdem aus wie
         * ein Schutz. uninstall/uninstall beschreibt die Lage seit jeher
         * richtig - der Kommentar in dieser Datei war der falsche. */
        'sicherung' => $sich,
        /* Die Anmeldung (Gigya-Token und Kamereon-Konto) gilt fuer das
         * KONTO, nicht fuer ein einzelnes Fahrzeug. Sie steht deshalb seit
         * 2.1.0 in einer eigenen Datei - sonst meldete sich jedes Fahrzeug
         * einzeln an, und bei zwei Fahrzeugen waeren es zwei Anmeldungen je
         * Tag statt einer. */
        'anmeldung' => $daten . '/anmeldung',
        'session' => $daten . '/session',
        'log'     => $prot  . '/renault.log',
        'csv'     => $daten . '/database.csv',
        'general' => $general,
        // Die gefundene Wurzel, wenn diese Datei NICHT darin installiert
        // liegt (Archivmodus) - fuer die Meldung in rn_ohne_anlage().
        'archiv'  => ($home === '') ? $gefunden : '',
        // Die alten Orte - nur noch, um einmalig umzuziehen.
        'alt_config'  => $eigen . '/config.php',
        'alt_session' => $eigen . '/session',
        'alt_log'     => $eigen . '/renault.log',
        'alt_csv'     => $eigen . '/database.csv',
    );
    return $p;
}

/**
 * Fuer abruf.php und history.php: gibt es eine Anlage, in die sie schreiben
 * duerfen? Rueckgabe '' = ja; sonst der Text der Meldung.
 *
 * Aufgerufen wird sie VOR logger.php - dessen erste Zeile legt ueber
 * rn_paths() die Ordner an - und vor rn_umzug(). Ohne Anlage wird nichts
 * geholt, nichts gesendet und nichts geschrieben.
 */
function rn_ohne_anlage()
{
    $p = rn_paths(false);
    if ($p['home'] !== '') {
        return '';
    }
    if ($p['archiv'] !== '') {
        return 'Diese Datei liegt nicht in der Installation unter ' . $p['archiv']
             . ' (ausgepacktes Archiv oder Pruefordner). Damit nichts in die Anlage kommt, '
             . 'wurde nichts geholt, nichts gesendet und nichts geschrieben. Abhilfe: das '
             . 'Programm aus der Installation aufrufen oder LBHOMEDIR und LBPPLUGINDIR '
             . 'ausdruecklich setzen.';
    }
    return 'Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: LBHOMEDIR ist nicht gesetzt, '
         . 'und oberhalb von ' . __DIR__ . ' traegt kein Verzeichnis config/plugins, '
         . 'data/plugins und config/system/general.json. Es wurde nichts geholt, nichts '
         . 'gesendet und nichts geschrieben.';
}

/**
 * Einmaliger Umzug der Nutzdaten aus dem Programmordner.
 *
 * Aufgerufen wird sie von den drei Einstiegspunkten, die SCHREIBEN duerfen:
 * der Oberflaeche, abruf.php und history.php. Der unangemeldete Endpunkt
 * ruft sie NICHT - er legt nichts an und zieht nichts um. Nach einem Update
 * von 1.4 oder aelter holt der Drei-Minuten-Cron den Umzug also nach, nicht
 * der erste Loxone-Aufruf; bis dahin antwortet der Endpunkt mit 403, weil
 * am neuen Ort noch kein Token steht.
 *
 * Bis 2.0.6 rief nur abruf.php und history.php diese Funktion. Die
 * Oberflaeche legte aber in ihrer ersten Handlung eine frische config.php
 * an, um ein Aktionstoken zu erzeugen. Wer nach einem Update von 1.4 oder
 * aelter zuerst die Oberflaeche oeffnete, hatte damit am neuen Ort eine
 * leere Konfiguration stehen - und rn_umzug() zieht nur um, solange dort
 * NICHTS steht. Benutzer, Passwort und Fahrgestellnummer waren dann
 * dauerhaft fort, ohne eine Meldung.
 *
 * Kopiert wird nur, wenn am neuen Ort noch nichts steht - eine bereits
 * umgezogene Datei wird nie ueberschrieben.
 */
function rn_umzug()
{
    $p = rn_paths();
    $paare = array(
        array($p['alt_config'],  $p['config']),
        array($p['alt_session'], $p['session']),
        array($p['alt_csv'],     $p['csv']),
        array($p['alt_log'],     $p['log']),
    );
    $bewegt = 0;
    foreach ($paare as $paar) {
        list($alt, $neu) = $paar;
        if (is_file($alt) && !is_file($neu)) {
            if (rn_datei_uebernehmen($alt, $neu)) {
                @unlink($alt);
                $bewegt++;
            }
        }
    }
    // Konfiguration notfalls aus der Zweitschrift. Beruecksichtigt wird
    // auch der alte Ort der Zweitschrift (im Konfigordner, bis 2.0.6).
    // Genommen wird sie nur, wenn sie das Merkwort traegt - eine
    // Zweitschrift ohne Aktionstoken ist keine Rettung.
    if (!is_file($p['config'])) {
        foreach (array($p['sicherung'], $p['konfdir'] . '/config.php.backup') as $sich) {
            if (!is_readable($sich)) { continue; }
            $w = rn_config_einlesen($sich);
            if (!is_array($w) || !array_key_exists('aktionstoken', $w)
                || (string) $w['aktionstoken'] === '') {
                continue;
            }
            if (rn_datei_uebernehmen($sich, $p['config'])) {
                $bewegt++;
                rn_lage_merken('aus der Zweitschrift');
            }
            break;
        }
    }
    if ($bewegt > 0) {
        @chmod($p['config'], 0600);
    }
    return $bewegt;
}

/**
 * Eine Datei unteilbar uebernehmen: Nebendatei mit Prozessnummer, Rechte vor
 * dem Inhalt, dann rename().
 *
 * @copy() schreibt am Ziel vorwaerts. Bricht es ab (volle Platte, Stromausfall),
 * steht dort eine halb geschriebene config.php - und die war bis 2.1.5 ein
 * Parsefehler, der Oberflaeche, Cron und Endpunkt zugleich stilllegte. Nach
 * rename() gibt es nur zwei Zustaende: alte Datei oder neue.
 */
function rn_datei_uebernehmen($quelle, $ziel)
{
    $roh = @file_get_contents($quelle);
    if ($roh === false) { return false; }
    return rn_datei_schreiben($ziel, $roh, 0600);
}

/**
 * Eine Datei unteilbar schreiben: Nebendatei mit Prozessnummer, Rechte vor
 * dem Inhalt, Inhalt ganz geschrieben und zurueckgelesen, dann rename().
 *
 * Geschrieben ist erst, was GANZ geschrieben ist. Bis 2.1.12 galt in
 * rn_config_write() "fwrite() !== false" als Erfolg (Befund C1). Bei voller
 * Karte liefert fwrite() aber eine kleinere Zahl, nicht false: in WSL
 * gemessen (ulimit -f 1) kam true zurueck, und config.php UND Zweitschrift
 * endeten nach 1024 Byte ohne Aktionstoken. Jetzt muessen volle Laenge,
 * fflush() und fclose() gelingen, und die Nebendatei muss beim Zuruecklesen
 * byteweise dem Inhalt gleichen. Bauform eb_json_schreiben() der Linie
 * Einspeisebremse 0.9.28.
 *
 * $rechte null laesst die Rechte der umask. $pruefen (optional) bekommt den
 * Pfad der Nebendatei und kann das Umbenennen mit false verhindern.
 */
function rn_datei_schreiben($ziel, $inhalt, $rechte = 0600, $pruefen = null)
{
    $inhalt = (string) $inhalt;
    $tmp = $ziel . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    if ($rechte !== null) { @chmod($tmp, $rechte); }          // erst schuetzen,
    $n = @ftruncate($fh, 0) ? @fwrite($fh, $inhalt) : false;  // dann fuellen
    $ok = ($n === strlen($inhalt)) && @fflush($fh);
    $ok = @fclose($fh) && $ok;
    if ($ok) {
        clearstatcache(true, $tmp);
        $ok = (@file_get_contents($tmp) === $inhalt);
    }
    if ($ok && $pruefen !== null) {
        $ok = (bool) call_user_func($pruefen, $tmp);
    }
    if (!$ok || !@rename($tmp, $ziel)) { @unlink($tmp); return false; }
    return true;
}

/**
 * Eine Adresse ueber die Stromfunktionen abrufen und den HTTP-Code aus den
 * Kopfzeilen lesen. Rueckgabe: array(Inhalt oder false, Code; 0 = kein Code).
 *
 * Bauform eb_http_abruf() (Einspeisebremse 0.9.28): fopen() und
 * stream_get_meta_data('wrapper_data') statt der alten Kopfzeilen-Variable
 * von PHP. PHP 8.5 meldet jene schon beim Uebersetzen als ueberholt
 * (Befund C7, rn_test.php bis 2.1.12), und PHP 9 soll sie abschaffen - dann
 * hiesse jeder Code 0. Zeitschranke und ignore_errors wirken ueber denselben
 * Kontext wie bei file_get_contents().
 */
function rn_http_abruf($url, $ctx)
{
    $fp = @fopen($url, 'r', false, $ctx);
    if ($fp === false) { return array(false, 0); }
    $meta = @stream_get_meta_data($fp);
    $t = @stream_get_contents($fp);
    @fclose($fp);
    $code = 0;
    $kopf = (is_array($meta) && isset($meta['wrapper_data']) && is_array($meta['wrapper_data']))
        ? $meta['wrapper_data'] : array();
    foreach ($kopf as $z) {
        if (is_string($z) && preg_match('#^HTTP/\S+\s+([0-9]{3})#', $z, $m)) { $code = (int) $m[1]; }
    }
    return array($t, $code);
}

function rn_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * Die Fassungsnummer - aus EINER Quelle, der plugin.cfg.
 *
 * Keine Konstante im PHP: die waere eine zweite Stelle, die beim Anheben
 * der Nummer vergessen wird. Gelesen wird die installierte plugin.cfg,
 * ersatzweise die im entpackten Archiv.
 *
 * parse_ini_file() scheitert an dieser Datei: sie kommentiert mit '#', und
 * PHPs INI-Zerleger kennt nur ';' - er gibt dann false zurueck. Deshalb
 * werden die Kommentarzeilen vorher entfernt.
 */
function rn_fassung()
{
    /* NEU: zuerst LoxBerry selbst fragen.
     *
     * Am Geraet gemessen (07.09.2026): plugininstall.pl liest die
     * plugin.cfg aus dem Auspackordner und loescht sie danach -
     * installiert wird sie NIRGENDWOHIN. Eine Fassungsfunktion, die nur
     * Dateien kennt, gibt auf jeder Installation eine leere Zeichenkette
     * zurueck; im Arbeitsordner faellt das nie auf, weil dort der
     * Archivfall der Kandidatenliste immer trifft.
     *
     * LBSystem::pluginversion() (loxberry_system.php:403) liest die
     * plugindatabase.json und ist die Auskunft von LoxBerry selbst.
     * Die Dateikandidaten darunter bleiben stehen - sie tragen den
     * Auspackordner, und der ist der Pruefstand.
     *
     * Gefragt wird ueber den ORDNERNAMEN (seit 2.1.9). Ohne Argument leitet
     * LoxBerry den Plugin-Namen aus dem Pfad des ersten eingebundenen
     * Skripts ab; am Geraet gemessen (17.09.2026): aus einem Plugin-Skript
     * heraus liefert das 2.1.8, aus jedem anderen Einstieg (php -r, ein
     * vorgeschaltetes Skript) NULL. Mit dem Ordnernamen liefert es in beiden
     * Faellen 2.1.8 - und auch bei einer Zweitinstallation (renault_ng_01). */
    if (class_exists('LBSystem', false)
        && method_exists('LBSystem', 'pluginversion')) {
        $aus = @LBSystem::pluginversion(rn_paths(false)['plugin']);
        if ($aus !== null && trim((string) $aus) !== '') {
            return trim((string) $aus);
        }
    }
    static $f = null;
    if ($f !== null) { return $f; }
    $f = '';
    $p = rn_paths(false);
    /* Der Kandidat der Anlage nur MIT Anlage: bis 2.1.10 stand er auch ohne
     * Wurzel in der Liste und lautete dann /data/system/plugins/<x>/plugin.cfg
     * - ab der Laufwerkswurzel (Pruefung-Renault-NG-2.1.11, Fall W7). */
    $kandidaten = array();
    if ($p['home'] !== '') {
        $kandidaten[] = $p['home'] . '/data/system/plugins/' . $p['plugin'] . '/plugin.cfg';
    }
    $kandidaten[] = dirname(dirname(dirname(__FILE__))) . '/plugin.cfg';
    foreach ($kandidaten as $datei) {
        $roh = @file_get_contents($datei);
        if ($roh === false) { continue; }
        $d = @parse_ini_string(preg_replace('/^[ \t]*#.*$/m', '', $roh),
                               true, INI_SCANNER_RAW);
        if (is_array($d) && isset($d['PLUGIN']['VERSION'])) {
            $f = trim((string) $d['PLUGIN']['VERSION'], " \t\"");
            break;
        }
    }
    return $f;
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

/** Hoechstzahl der Fahrzeuge, die ein Konto hier fuehren kann. */
define('RN_MAX_FAHRZEUGE', 4);

/**
 * Alle Schluessel, die config.php fuehrt, mit ihren Vorgaben.
 *
 * NEUE FUNKTIONEN STEHEN AB WERK AUS. Das gilt seit 2.1.0 ausdruecklich
 * fuer die schaltenden Befehle (steuerung_ein) und fuer alles, was von
 * sich aus etwas ausloest: Mail, Fremdbefehl, Ladeplan-Umschaltung,
 * Ladeziel. Ein Vorgabewert, der beim ersten Cron-Lauf ungefragt schaltet,
 * ist ein Fehler.
 */
function rn_vorgaben()
{
    $v = array(
        // ---- Fahrzeug 1 (die Namen bleiben, damit Bestandsanlagen
        //      weder Konfiguration noch MQTT-Themen verlieren) ----
        'zoename'         => 'Renault',
        'vin'             => '',
        'zoeph'           => '2',
        // ---- Konto ----
        'username'        => '',
        'password'        => '',
        'country'         => 'DE',
        // ---- Aufzeichnung ----
        'save_in_db'      => 'N',
        // ---- Abruftakt (bis 2.0.6 nur von Hand in der Datei zu aendern) ----
        'cron_ncs'        => '5',
        'cron_acs'        => '2',
        // ---- Schalten ----
        'steuerung_ein'   => 'N',
        'ac_temp'         => '21',
        // ---- Meldungen bei erreichtem Akkustand / beendeter Ladung ----
        'bl_schwelle'     => '80',
        'mail_bl'         => 'N',
        'exec_bl'         => '',
        'cmon_bl'         => 'N',
        'mail_csf'        => 'N',
        'exec_csf'        => '',
        // ---- Ladeziel (nur neuere Plattformen, siehe Reiter Test) ----
        'soc_min'         => '',
        'soc_target'      => '',
        // ---- Fremddienste ----
        'weather_api_key' => '',
        'abrp_token'      => '',
        'abrp_model'      => '',
        // ---- Endpunkt ----
        'aktionstoken'    => '',
        // ---- Sprachausgabe (Nr. 36 b, seit 2.1.16): Anlaesse je einzeln abwaehlbar. Gesprochen
        //      wird erst mit einer Ausgabeart (tts_mode, ab Werk 'aus'). ----
        'ansage_laden_fertig'  => 'Y',
        'ansage_laden_abbruch' => 'Y',
        'ansage_akku'          => 'Y',
        'ansage_ausfall'       => 'Y',
    );
    /* Der Block tts des Moduls, flach wie jeder Schluessel dieser Datei (tts_mode, tts_ip, ...). */
    foreach (ansage_vorgaben('aus') as $rn_tk => $rn_tw) {
        $v['tts_' . $rn_tk] = (string) $rn_tw;
    }
    /* Welle Mehrfach-Ziele (2.1.20, Sprachmodul 1.2.1): die weiteren Ausgabeziele als JSON-Liste, leer = keine (ab Werk
     * aus). rn_tts_aus() liest sie in den Block, rn_tts_flach() schreibt sie zurueck. */
    $v['tts_ziele'] = '';
    /* Fahrzeug 2 bis 4. Bewusst durchnummerierte Einzelschluessel und kein
     * verschachteltes Feld: config.php wird mit var_export() je Schluessel
     * geschrieben, und rn_config_read() uebernimmt ausdruecklich nur
     * Skalare. Ein Feld haette beides umgebaut - fuer nichts. */
    for ($i = 2; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $v['zoename' . $i] = '';
        $v['vin' . $i]     = '';
        $v['zoeph' . $i]   = '2';
    }
    /* Gruppe 40 (2.1.20): Gateway V2, Befehlsbruecke, Endpunkt-Haken, Heimzone,
     * Vorklimatisierung, SignalBot, Ankunft und Ladekosten - alles, was etwas
     * ausloest, ab Werk aus (rn_vorgaben_2120()). */
    return array_merge($v, rn_vorgaben_2120());
}

/**
 * Die eingerichteten Fahrzeuge, in der Reihenfolge ihrer Nummer.
 *
 * Fahrzeug 1 ist immer dabei, auch ohne Fahrgestellnummer - sonst haette
 * eine frische Installation gar kein Fahrzeug und die Oberflaeche nichts
 * anzuzeigen. Fahrzeug 2 bis 4 zaehlen nur mit, wenn Nummer oder Name
 * eingetragen sind.
 *
 * Jedes Fahrzeug bekommt eigene Dateien fuer Zwischenspeicher und
 * Aufzeichnung. Fahrzeug 1 behaelt die bisherigen Namen (session,
 * database.csv) - damit uebersteht eine Bestandsanlage das Update, ohne
 * ihre Aufzeichnung zu verlieren.
 */
function rn_fahrzeuge($cfg = null)
{
    if ($cfg === null) { $cfg = rn_config_read(); }
    $p = rn_paths();
    $liste = array();
    for ($i = 1; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $nr    = ($i === 1) ? '' : (string) $i;
        $vin   = trim((string) $cfg['vin' . $nr]);
        $name  = trim((string) $cfg['zoename' . $nr]);
        if ($i > 1 && $vin === '' && $name === '') { continue; }
        if ($name === '') { $name = 'Renault' . $nr; }
        $liste[] = array(
            'nr'      => $i,
            'name'    => $name,
            'vin'     => $vin,
            'zoeph'   => (string) $cfg['zoeph' . $nr],
            'session' => $p['datadir'] . '/session' . $nr,
            'csv'     => $p['datadir'] . '/database' . $nr . '.csv',
        );
    }
    return $liste;
}

/** Ein einzelnes Fahrzeug nach seiner Nummer, oder null. */
function rn_fahrzeug($nr, $cfg = null)
{
    foreach (rn_fahrzeuge($cfg) as $f) {
        if ((int) $f['nr'] === (int) $nr) { return $f; }
    }
    return null;
}

/**
 * Zufallstoken fuer den Aktionsendpunkt.
 *
 * Der Endpunkt liegt im unangemeldeten Bereich, damit Loxone ihn ohne
 * Zugangsdaten erreicht. Ohne Token koennte jedes Geraet im Netz die
 * Vorklimatisierung starten.
 */
function rn_token_erzeugen($laenge = 24)
{
    $zeichen = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) {
        $t .= $zeichen[random_int(0, strlen($zeichen) - 1)];
    }
    return $t;
}

/* ---------------- Formularschutz ----------------
 *
 * htmlauth schuetzt gegen den unangemeldeten Aufruf - nicht dagegen, dass der
 * Browser eines angemeldeten Bedieners ein Formular abschickt, das auf einer
 * fremden Seite steht. Hier waere der Schaden gross: zu den Feldern gehoeren
 * exec_bl und exec_csf, zwei Befehlszeilen, die der Abruf spaeter ausfuehrt.
 *
 * Das Merkmal wird aus dem Aktionstoken ABGELEITET und nicht gespeichert.
 * Sonst haette die Konfiguration einen Schluessel mehr, den ein
 * Speichern-Handler vergessen kann - genau dieser Fehler hat in dieser Reihe
 * schon dreimal das Aktionstoken gekostet, und mit ihm alle Adressen in
 * Loxone. Eine fremde Seite kann den Wert nicht lesen (gleiche-Herkunft-Regel)
 * und ihn deshalb nicht mitschicken.
 */
function rn_formtoken($cfg)
{
    return hash_hmac('sha256', 'formular-v1', (string) $cfg['aktionstoken']);
}

/** Traegt dieses POST das Merkmal? hash_equals, nicht ===. */
function rn_formtoken_ok($cfg)
{
    $ist = isset($_POST['formtoken']) ? (string) $_POST['formtoken'] : '';
    if ($ist === '' || (string) $cfg['aktionstoken'] === '') {
        return false;
    }
    return hash_equals(rn_formtoken($cfg), $ist);
}

/* ---------------- Die beiden Befehlszeilen des Betreibers ----------------
 *
 * exec_bl und exec_csf sind bewusste Eingaben in den Einstellungen, kein
 * Fremdwert - sie duerfen ein Programm mit Argumenten benennen. "Bewusst" ist
 * aber nicht dasselbe wie "geprueft": ein Semikolon in dem Feld sind zwei
 * Befehle, und der zweite steht dann in einer Konfigurationsdatei, in die
 * niemand mehr sieht.
 *
 * Geprueft wird deshalb an der Stelle des Aufrufs, nicht nur beim Speichern:
 * eine Datei kann sich zwischen Speichern und Aufruf geaendert haben, und die
 * Konfiguration von 1.4 wurde nie geprueft.
 *
 *   - Sonderzeichen der Schale ( ; & | ` $ ( ) < > Zeilenumbruch ) -> abgewiesen
 *   - das erste Wort muss eine vorhandene, ausfuehrbare Datei sein
 *   - jedes Wort wird EINZELN maskiert; die Meldung kommt als letztes Argument
 *
 * Abgewiesen heisst: es wird nichts ausgefuehrt und eine Zeile ins Log
 * geschrieben. Ein still uebergangener Haken ist schlimmer als ein Fehler.
 *
 * Rueckgabe: true, wenn ausgefuehrt wurde.
 */
function rn_hook_ausfuehren($roh, $text, $welche)
{
    /* renault_log() steht in logger.php, nicht hier. Aufgerufen wird diese
     * Funktion heute nur aus abruf.php, das logger.php einliest - aber eine
     * Bibliotheksfunktion, die beim naechsten Aufrufer fatal abbricht, ist
     * eine Falle. Fehlt der Logger, wird die Ablehnung ins PHP-Log gesagt;
     * geschwiegen wird nicht. */
    $melde = function ($text) use ($welche) {
        if (function_exists('renault_log')) {
            renault_log('WARN', $text);
        } else {
            error_log('renault ' . $welche . ': ' . $text);
        }
    };
    $roh = trim((string) $roh);
    if ($roh === '') {
        return false;
    }
    if (preg_match('/[;&|`$()<>\r\n]/', $roh)) {
        $melde('Der Befehl in ' . $welche . ' enthaelt Sonderzeichen der Schale und '
            . 'wurde NICHT ausgefuehrt. Erlaubt ist ein Programm mit einfachen '
            . 'Argumenten.');
        return false;
    }
    $teile = preg_split('/\s+/', $roh);
    $programm = $teile[0];
    if (!is_file($programm) || !is_executable($programm)) {
        $melde('Der Befehl in ' . $welche . ' zeigt nicht auf eine ausfuehrbare '
            . 'Datei (' . $programm . ') und wurde NICHT ausgefuehrt. Bitte den '
            . 'vollen Pfad eintragen.');
        return false;
    }
    $zeile = '';
    foreach ($teile as $t) {
        $zeile .= escapeshellarg($t) . ' ';
    }
    // Die Meldung kommt aus Werten der Renault-Schnittstelle - sie war schon
    // vorher maskiert und bleibt es.
    shell_exec($zeile . escapeshellarg((string) $text));
    return true;
}

/** Die Adresse, die in Loxone einzutragen ist. */
function rn_aktionsadresse($cfg, $aktion, $fahrzeug = 1)
{
    $a = '/plugins/' . rn_paths()['plugin'] . '/index.php?token='
       . rawurlencode($cfg['aktionstoken']) . '&aktion=' . rawurlencode($aktion);
    if ((int) $fahrzeug > 1) { $a .= '&fahrzeug=' . (int) $fahrzeug; }
    return $a;
}

/**
 * Der Rechnername fuer die Adressen in Loxone: aus HTTP_HOST, also der
 * Adresse, unter der die Seite geoeffnet wurde - sonst der Rechnername.
 * Dieselbe Stelle fuer die Vorlage und den Reiter "Einbindung in Loxone"
 * (Befund U10). Ein Vorschlag, den der Anwender prueft.
 */
function rn_rechnername()
{
    return isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])
            && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
}

/**
 * Einmalmeldung nach dem POST (Befund U1, Regeln/04 "Jeder POST-Handler
 * endet mit einer Umleitung"): eine Datei im Datenordner, 0600, beim
 * folgenden GET gelesen UND geloescht; aelter als 120 s wird verworfen.
 * Bauform eb_einmal_schreiben()/eb_einmal_lesen() (Einspeisebremse 0.9.28).
 *
 * Sie traegt nur Meldungstexte. Kennwort, Aktionstoken und die beiden
 * Schluessel werden trotzdem vor dem Ablegen durch *** ersetzt, falls ein
 * Text sie doch enthielte (Regeln/04, Nachtrag Raumklima 17.09.).
 */
function rn_einmal_schreiben($meldung, array $fehler, array $misslungen, $test_titel,
                             $test_text, $cfg, $eingaben = null)
{
    $weg = array();
    foreach (array('password', 'aktionstoken', 'weather_api_key', 'abrp_token') as $k) {
        $g = (is_array($cfg) && isset($cfg[$k])) ? (string) $cfg[$k] : '';
        if (strlen($g) >= 6) { $weg[$g] = '***'; }
    }
    $rein = function ($t) use ($weg) { return $weg ? strtr((string) $t, $weg) : (string) $t; };
    $d = array(
        'zeit'       => time(),
        'meldung'    => $rein($meldung),
        'fehler'     => array_values(array_map($rein, $fehler)),
        'misslungen' => array_values(array_map($rein, $misslungen)),
        'test_titel' => $rein($test_titel),
        'test_text'  => $rein($test_text),
    );
    /* X-2 (Welle-4-Bau): die Eingaben des beanstandeten Formulars. Geheimnisse
     * stehen nie darin (index.php, rn_eingabe_felder()); gespeicherte
     * Geheimnisse werden trotzdem ersetzt, falls jemand eines in ein
     * Textfeld getippt hat. */
    if (is_array($eingaben) && isset($eingaben['werte']) && is_array($eingaben['werte'])) {
        foreach ($eingaben['werte'] as $k => $w) {
            $eingaben['werte'][$k] = $rein($w);
        }
        $d['eingaben'] = $eingaben;
    }
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) { return false; }
    return rn_datei_schreiben(rn_paths()['datadir'] . '/einmalmeldung.json', $json, 0600);
}

/** Die Einmalmeldung lesen und loeschen; null, wenn keine (gueltige) da ist. */
function rn_einmal_lesen()
{
    $f = rn_paths()['datadir'] . '/einmalmeldung.json';
    if (!is_file($f)) { return null; }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    $text = function ($k) use ($d) {
        return (isset($d[$k]) && is_string($d[$k])) ? $d[$k] : '';
    };
    $liste = function ($k) use ($d) {
        $aus = array();
        if (isset($d[$k]) && is_array($d[$k])) {
            foreach ($d[$k] as $v) { if (is_string($v)) { $aus[] = $v; } }
        }
        return $aus;
    };
    return array('meldung' => $text('meldung'), 'fehler' => $liste('fehler'),
                 'misslungen' => $liste('misslungen'), 'test_titel' => $text('test_titel'),
                 'test_text' => $text('test_text'),
                 // X-2: geprueft wird beim Setzen (index.php, rn_eingaben()).
                 'eingaben' => (isset($d['eingaben']) && is_array($d['eingaben'])) ? $d['eingaben'] : null);
}

/** Die Adresse des Selbsttests - prueft das Token, ohne etwas zu schalten. */
function rn_selbsttestadresse($cfg)
{
    return '/plugins/' . rn_paths()['plugin'] . '/index.php?selftest=1&token='
         . rawurlencode($cfg['aktionstoken']);
}

/**
 * config.php in einem eigenen Gueltigkeitsbereich einlesen.
 *
 * Eigene Funktion, damit include nicht den globalen Namensraum vollschreibt.
 * Die Ausgabe wird verworfen: die alte Schreibroutine hat hinter das
 * schliessende ?> noch ein Leerzeichen und einen Zeilenumbruch gehaengt.
 * Ohne Puffer landet das in der Seite - und zwar vor den HTTP-Kopfzeilen.
 */
function rn_config_einlesen($rn_datei, &$heil = null)
{
    $heil = false;
    $roh = @file_get_contents($rn_datei);
    if ($roh === false) {
        return null;
    }
    return rn_config_zerlegen($roh, $heil);
}

/**
 * Den Quelltext der config.php zerlegen, OHNE ihn auszufuehren.
 *
 * Bis 2.1.5 wurde die Datei EINGEBUNDEN und damit ausgefuehrt. Eine halb
 * geschriebene Datei ist so ein E_COMPILE_ERROR - und der ist von
 * catch (Throwable) NICHT zu fangen. (Die Anweisung steht hier absichtlich
 * nicht im Wortlaut: eine Suche danach soll den Kommentar nicht finden.) Gemessen an einer abgeschnittenen Datei, unter 7.4.33 und
 * 8.4.24 gleich: "Parse error … on line 3", Rueckgabewert 255, null Byte
 * Ausgabe. Mit ini_set('display_errors','0') im Endpunkt heisst das HTTP 500
 * mit leerem Rumpf - fuer Oberflaeche, Cron und Endpunkt gleichzeitig, und
 * die heile Zweitschrift daneben wird nie gelesen.
 *
 * Der Zerleger kennt genau die Formen, die vorkommen koennen:
 *   $name = '…';   var_export() maskiert darin nur \ und '
 *   $name = "…";   aus einer von Hand geschriebenen Datei
 *   $name = 42;    $name = true;   $name = null;
 * Ein Wert darf einen Zeilenumbruch enthalten (bis 2.1.5 liess sich einer
 * ueber eine Sicherungsdatei einschleusen), deshalb wird ueber den ganzen
 * Text gelaufen und nicht Zeile fuer Zeile.
 *
 * Was nicht auf diese Formen passt, wird uebergangen - der Zerleger bricht
 * nicht ab, sondern liefert, was er sicher lesen konnte. Ob das genug ist,
 * entscheidet rn_config_read() am Inhalt, nicht an der Form.
 */
function rn_config_zerlegen($roh, &$heil = null)
{
    $heil = true;
    $werte = array();
    $n = strlen($roh);
    $i = 0;
    while ($i < $n) {
        $d = strpos($roh, '$', $i);
        if ($d === false) { break; }
        // Nur ein $ am Zeilenanfang (hoechstens Leerraum davor) zaehlt.
        $zeilenanfang = ($d === 0);
        for ($j = $d - 1; $j >= 0; $j--) {
            if ($roh[$j] === "\n") { $zeilenanfang = true; break; }
            if ($roh[$j] !== ' ' && $roh[$j] !== "\t" && $roh[$j] !== "\r") { break; }
        }
        if (!$zeilenanfang) { $i = $d + 1; continue; }
        if (!preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*/',
                        substr($roh, $d, 200), $m)) {
            $i = $d + 1;
            continue;
        }
        $name = $m[1];
        $k = $d + strlen($m[0]);          // erstes Zeichen des Wertes
        if ($k >= $n) { break; }
        $z = $roh[$k];
        if ($z === "'" || $z === '"') {
            $wert = '';
            $k++;
            $offen = true;
            while ($k < $n) {
                $c = $roh[$k];
                if ($c === '\\' && $k + 1 < $n) {
                    $f = $roh[$k + 1];
                    if ($z === "'") {
                        // In einfachen Anfuehrungszeichen sind nur \\ und \'
                        // Fluchtzeichen; alles andere bleibt woertlich.
                        $wert .= ($f === '\\' || $f === "'") ? $f : '\\' . $f;
                    } else {
                        switch ($f) {
                            case 'n': $wert .= "\n"; break;
                            case 'r': $wert .= "\r"; break;
                            case 't': $wert .= "\t"; break;
                            case '"': $wert .= '"';  break;
                            case '\\': $wert .= '\\'; break;
                            case '$': $wert .= '$';  break;
                            default: $wert .= '\\' . $f;
                        }
                    }
                    $k += 2;
                    continue;
                }
                if ($c === $z) { $offen = false; $k++; break; }
                $wert .= $c;
                $k++;
            }
            if ($offen) {
                // Zeichenkette ohne Ende - die Datei ist abgeschnitten.
                $heil = false;
                break;
            }
            $werte[$name] = $wert;
        } else {
            $e = strpos($roh, ';', $k);
            if ($e === false) { $heil = false; break; }
            $t = trim(substr($roh, $k, $e - $k));
            if ($t === 'true')       { $werte[$name] = '1'; }
            elseif ($t === 'false')  { $werte[$name] = ''; }
            elseif ($t === 'null')   { $werte[$name] = ''; }
            elseif (preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $t)) { $werte[$name] = $t; }
            // Alles andere (Ausdruecke, Felder) wird uebergangen.
            $k = $e;
        }
        $e = strpos($roh, ';', $k);
        $i = ($e === false) ? $k + 1 : $e + 1;
    }
    return $werte;
}

/**
 * config.php einlesen, ohne sie in den globalen Namensraum zu kippen.
 *
 * DAS IST DER EINZIGE ZULAESSIGE WEG ZUR KONFIGURATION - auch fuer den
 * Cron. Bis 2.0.6 haben abruf.php und history.php die Datei stattdessen
 * mit "require" eingelesen und die Werte als globale Variablen benutzt.
 * Das hatte zwei Fehler zur Folge, die beide erst am Geraet auffielen:
 *
 *   1. Fehlt die Datei (Erstinstallation, bevor jemand die Oberflaeche
 *      geoeffnet hat), bricht "require" fatal ab - alle drei Minuten,
 *      ohne eine Zeile im Protokoll, weil logger.php erst danach kam.
 *   2. Fehlt ein SCHLUESSEL (jede Konfiguration, die nicht von
 *      rn_config_write() stammt - etwa die aus einer 1.4-Installation),
 *      ist die Variable undefiniert. Bei $cron_ncs ergab das
 *      date_interval_create_from_date_string(' minutes') === false und
 *      damit unter PHP 8 einen TypeError in date_add().
 *
 * rn_vorgaben() faengt beides ab: fehlende Datei und fehlende Schluessel.
 */
function rn_config_read($erzeugen = true)
{
    $cfg   = rn_vorgaben();
    $p     = rn_paths($erzeugen);
    $datei = $p['config'];

    if (!is_readable($datei)) {
        rn_lage_merken('fehlt');
        return $cfg;
    }

    $heil = false;
    $werte = rn_config_einlesen($datei, $heil);
    if ($werte === null) {
        rn_lage_merken('unlesbar');
        return $cfg;
    }

    /* Die Lage wird am INHALT entschieden, nicht an der Form.
     *
     * Bis 2.1.5 hiess "Datei vorhanden" gleich "Datei in Ordnung", und die
     * Selbstheilung haing allein an !is_file(). Gemessen an einer 0 Byte
     * grossen und an einer Datei mit dem Inhalt "das ist kein PHP", unter
     * beiden PHP-Fassungen gleich: die Oberflaeche las Werkseinstellungen,
     * erzeugte ein neues Aktionstoken, schrieb es - und ueberschrieb dabei
     * die heile Zweitschrift mit denselben Werkswerten. Verloren waren
     * Aktionstoken (jede Loxone-Adresse antwortet danach mit 403, was ein
     * Virtueller Ausgang nicht auswertet), Kennwort, Fahrgestellnummern und
     * alle Einstellungen. Ohne eine Zeile im Protokoll.
     *
     * Das Merkwort ist der Aktionstoken: er steht in JEDER Konfiguration,
     * die dieses Plugin je geschrieben hat, denn die Oberflaeche erzeugt ihn
     * beim ersten Aufruf. Fehlt er UND fehlt jeder andere bekannte
     * Schluessel, ist die Datei beschaedigt und nicht etwa neu. */
    $bekannt = 0;
    foreach ($cfg as $k => $v) {
        if (array_key_exists($k, $werte)) { $bekannt++; }
    }
    $hat_merkwort = array_key_exists('aktionstoken', $werte)
                    && (string) $werte['aktionstoken'] !== '';

    /* Drei Schadensbilder, jedes einzeln gemessen:
     *
     *   leer / kein PHP      keine einzige bekannte Einstellung
     *   abgeschnitten        eine Zeichenkette ohne Ende ($heil === false);
     *                        das ist die halb geschriebene Datei, die bis
     *                        2.1.5 einen Parsefehler ausloeste
     *   Token verloren       die Datei traegt kein Aktionstoken, die
     *                        Zweitschrift aber schon
     *
     * Der dritte Fall ist noetig, weil eine abgeschnittene Datei nicht
     * immer mitten in einer Zeichenkette endet - sie kann auch sauber nach
     * einer Zuweisung aufhoeren und dabei den Token verloren haben. Er darf
     * NICHT allein am fehlenden Token haengen: eine Konfiguration aus 1.4
     * kennt den Schluessel gar nicht, und die ist in Ordnung. Deshalb die
     * zweite Bedingung - die Zweitschrift hat eines, die Konfiguration
     * nicht, also ist es dort verlorengegangen. */
    $schaden = rn_konfig_urteil($werte, $heil, 'rn_zweitschrift_hat_token');

    if ($schaden !== '') {
        rn_lage_merken($schaden);
        if ($erzeugen && rn_konfig_heilen()) {
            /* Einmal wiederherstellen, einmal melden - und danach neu
             * lesen. Der zuerst festgestellte Zustand bleibt gemerkt: ein
             * geheilter Schaden ist kein Nicht-Schaden, die Zweitschrift
             * kann aelter sein als das, was verlorenging. */
            return rn_config_read(false);
        }
        if ($bekannt === 0) {
            return $cfg;
        }
        /* Nicht geheilt (unangemeldeter Aufruf oder keine Zweitschrift):
         * dann wenigstens das lesen, was lesbar war. */
    }

    foreach ($cfg as $k => $v) {
        if (array_key_exists($k, $werte) && !is_array($werte[$k])) {
            $cfg[$k] = (string) $werte[$k];
        }
    }
    rn_lage_merken($hat_merkwort ? 'ok' : 'ohne Token');
    return $cfg;
}

/** Traegt die Zweitschrift ein Aktionstoken? */
function rn_zweitschrift_hat_token()
{
    $p = rn_paths(false);
    foreach (array($p['sicherung'], $p['konfdir'] . '/config.php.backup') as $sich) {
        if (!is_readable($sich)) { continue; }
        $w = rn_config_einlesen($sich);
        if (is_array($w) && array_key_exists('aktionstoken', $w)
            && (string) $w['aktionstoken'] !== '') {
            return true;
        }
    }
    return false;
}

/**
 * Das Urteil ueber einen zerlegten Konfigurationstext - an EINER Stelle fuer
 * die Bibliothek (rn_config_read()) und die Hakenskripte (preupgrade.sh,
 * postupgrade.sh ueber rn_konfig_datei_urteil(); Befund I4). Bis 2.1.12
 * urteilten die Haken selbst und verlangten ein schliessendes ?>: eine von
 * der Bibliothek als heil gelesene Konfiguration wurde beim Update durch
 * eine aeltere Zweitschrift ersetzt (in WSL gemessen, Fall U3).
 *
 * Rueckgabe '' = in Ordnung, sonst der Schaden: 'kaputt' (keine bekannte
 * Einstellung), 'abgeschnitten' (eine Zeichenkette ohne Ende), 'ohne Token'
 * (kein Aktionstoken, die Zweitschrift traegt aber eines). $zweit_hat_token
 * ist aufrufbar und wird nur gefragt, wenn es darauf ankommt.
 */
function rn_konfig_urteil($werte, $heil, $zweit_hat_token)
{
    if (!is_array($werte)) { return 'kaputt'; }
    $bekannt = 0;
    foreach (array_keys(rn_vorgaben()) as $k) {
        if (array_key_exists($k, $werte)) { $bekannt++; }
    }
    if ($bekannt === 0) { return 'kaputt'; }
    if (!$heil) { return 'abgeschnitten'; }
    $hat = array_key_exists('aktionstoken', $werte) && (string) $werte['aktionstoken'] !== '';
    if (!$hat && call_user_func($zweit_hat_token)) { return 'ohne Token'; }
    return '';
}

/**
 * Dasselbe Urteil fuer eine DATEI - der Weg der Hakenskripte. $zweit ist der
 * Pfad der Zweitschrift, gegen die "ohne Token" gemessen wird ('' = keine).
 * Rueckgabe wie rn_konfig_urteil(), dazu 'fehlt'. Schreibt nichts und legt
 * nichts an.
 */
function rn_konfig_datei_urteil($datei, $zweit = '')
{
    $roh = @file_get_contents($datei);
    if (!is_string($roh)) { return 'fehlt'; }
    $heil = false;
    $werte = rn_config_zerlegen($roh, $heil);
    return rn_konfig_urteil($werte, $heil, function () use ($zweit) {
        if ($zweit === '' || !is_readable($zweit)) { return false; }
        $w = rn_config_einlesen($zweit);
        return is_array($w) && array_key_exists('aktionstoken', $w)
            && (string) $w['aktionstoken'] !== '';
    });
}

/**
 * Der zuerst festgestellte Zustand der Konfiguration, fuer den Reiter Test.
 *
 * Er wird fuer die Dauer des Prozesses festgehalten und von einem spaeteren
 * "ok" NICHT ueberschrieben: sonst meldete die Pruefzeile "in Ordnung",
 * waehrend die Datei beim selben Seitenaufruf beschaedigt war - der erste
 * Aufruf heilt sie, der zweite sieht eine heile Datei.
 */
function rn_lage_merken($lage = null)
{
    static $erste = '';
    if ($lage !== null && $erste === '') {
        $erste = $lage;
    }
    return $erste;
}

/** Fuer die Oberflaeche: ok, fehlt, leer, kaputt, aus der Zweitschrift. */
function rn_konfig_lage()
{
    $l = rn_lage_merken();
    return $l === '' ? 'nicht gelesen' : $l;
}

/**
 * Die Konfiguration aus der Zweitschrift wiederherstellen - einmal.
 *
 * Die beschaedigte Datei wird als <name>.kaputt beiseitegelegt, damit sie
 * nachgesehen werden kann; die Zweitschrift wird nur genommen, wenn sie
 * selbst Inhalt hat UND das Merkwort traegt. Eine Zweitschrift ohne Token
 * ist keine Rettung, sondern die Werkseinstellung mit anderem Datum.
 */
function rn_konfig_heilen()
{
    static $gelaufen = false;
    if ($gelaufen) { return false; }
    $gelaufen = true;

    $p = rn_paths();
    foreach (array($p['sicherung'], $p['konfdir'] . '/config.php.backup') as $sich) {
        if (!is_readable($sich)) { continue; }
        $w = rn_config_einlesen($sich);
        if (!is_array($w) || !array_key_exists('aktionstoken', $w)
            || (string) $w['aktionstoken'] === '') {
            continue;
        }
        /* Bis 2.1.12 hiess es hier rename() nach .kaputt, dann @copy() und
         * erst danach chmod (Befund C6): copy() schreibt vorwaerts mit den
         * Rechten der umask - die Datei mit dem Klartext-Kennwort stand kurz
         * lesbar da -, und bei voller Karte blieb eine halbe config.php,
         * waehrend die beschaedigte schon beiseite lag. Jetzt wird die
         * beschaedigte als Abschrift (0600) beiseitegelegt, und sie bleibt an
         * ihrem Platz, bis die Zweitschrift vollstaendig, zurueckgelesen und
         * unteilbar dort steht (rn_datei_uebernehmen()). */
        $kaputt = $p['config'] . '.kaputt';
        $war_da = is_file($p['config']);
        $beiseite = $war_da && rn_datei_uebernehmen($p['config'], $kaputt);
        if (rn_datei_uebernehmen($sich, $p['config'])) {
            rn_lage_merken('aus der Zweitschrift');
            rn_melden('WARN', 'Die Konfiguration war unlesbar und wurde aus der '
                . 'Zweitschrift wiederhergestellt (' . basename($sich) . '). '
                . ($beiseite ? 'Die beschaedigte Datei liegt als ' . basename($kaputt) . ' daneben. '
                   : ($war_da ? 'Die beschaedigte Datei liess sich nicht beiseitelegen. ' : ''))
                . 'Bitte die Einstellungen nachsehen - die Zweitschrift kann aelter '
                . 'sein als das, was verlorenging.');
            return true;
        }
    }
    rn_melden('ERROR', 'Die Konfiguration ist unlesbar, und es gibt keine '
        . 'brauchbare Zweitschrift. Es wird mit den Werkseinstellungen '
        . 'gearbeitet; das Aktionstoken und die Zugangsdaten fehlen.');
    rn_lage_merken('kaputt ohne Zweitschrift');
    return false;
}

/**
 * Eine Zeile ins Protokoll - auch dort, wo logger.php nicht eingebunden ist.
 *
 * renault_log() steht in logger.php. Die Oberflaeche band sie bis 2.1.5 gar
 * nicht ein und schrieb deshalb keine einzige Zeile: weder zum erzeugten
 * Token noch zu einer abgelehnten Sicherung noch zu einem Werksrueckfall.
 * Wer spaeter fragt, wann sein Token verlorenging, fand nichts.
 */
function rn_melden($stufe, $text)
{
    if (function_exists('renault_log')) {
        renault_log($stufe, $text);
        return;
    }
    $logger = __DIR__ . '/logger.php';
    if (is_file($logger)) {
        require_once $logger;
        if (function_exists('renault_log')) {
            renault_log($stufe, $text);
            return;
        }
    }
    error_log('renault_ng [' . $stufe . '] ' . $text);
}

/**
 * Eine Zeile hoechstens einmal je Stunde - auch wenn andere dazwischen stehen.
 *
 * Die Bremse in renault_log() fasst nur UNMITTELBAR aufeinanderfolgende
 * gleiche Meldungen zusammen. Ein Abruf schreibt aber je Lauf mehrere
 * INFO-Zeilen; eine Fehlerzeile am Ende jedes Laufs ginge so alle drei
 * Minuten hinaus. Gesucht wird in den letzten 400 Zeilen des Protokolls
 * (Ramdisk - auch bei voller Karte beschreibbar). Rueckgabe: true, wenn die
 * Zeile geschrieben wurde.
 */
function rn_melden_stuendlich($stufe, $text)
{
    $marke = '[' . $stufe . '] ' . $text;
    foreach (rn_log_tail(400) as $zeile) {
        if (substr($zeile, 20) !== $marke) { continue; }
        $t = date_create_from_format('d.m.Y H:i:s', substr($zeile, 0, 19));
        if ($t !== false && time() - date_timestamp_get($t) < 3600) { return false; }
        break;
    }
    rn_melden($stufe, $text);
    return true;
}

/**
 * Den Zwischenspeicher eines Fahrzeugs schreiben - unteilbar und geprueft.
 *
 * Bis 2.1.12 stand am Ende von abruf.php ein @file_put_contents() ohne
 * Rueckgabepruefung (Befund C3). Liess sich die Datei nicht schreiben, blieb
 * der Merker "Meldung gesendet" (Feld 5) auf N, und die Meldung bei
 * erreichtem Akkustand ging bei JEDEM Abruf erneut hinaus - Mail, exec_bl
 * und bei cmon_bl ein Befehl ans Fahrzeug (in WSL gemessen: 1, 2, 3 Haken in
 * drei Laeufen). Rechte wie bisher 0644; die Datei traegt kein Geheimnis.
 */
function rn_session_schreiben($datei, array $felder)
{
    return rn_datei_schreiben($datei, implode('|', $felder), 0644);
}

/**
 * config.php schreiben - atomar, und die Rechte VOR dem Inhalt.
 *
 * "Schreiben, dann chmod" laesst die Datei fuer die Dauer des Schreibens
 * mit den Vorgaben der umask stehen. Bei einer Datei, in der das
 * Renault-Passwort im Klartext steht, ist das der Unterschied zwischen
 * "kurz lesbar" und "nie lesbar". Die Nebendatei traegt die Prozessnummer,
 * sonst zerlegen zwei gleichzeitige Schreiber einander.
 *
 * Fruehere Fassung (DatenWrite.php) hat die Eingaben roh in den Quelltext
 * geschrieben: ein Apostroph im Passwort zerlegte die Datei, und schlimmer,
 * der Wert landete unmaskiert als PHP-Code. Ausserdem wurden bei jedem
 * Speichern alle nicht im Formular stehenden Felder auf Vorgabewerte
 * zurueckgesetzt. Beides ist hier behoben: var_export() maskiert, und
 * geschrieben wird die vollstaendige Konfiguration.
 */
function rn_config_write($cfg)
{
    $voll = array_merge(rn_vorgaben(), $cfg);
    $z = "<?php\n";
    foreach ($voll as $k => $v) {
        $z .= '$' . $k . ' = ' . var_export((string) $v, true) . ";\n";
    }
    $z .= "?>\n";

    $datei = rn_paths()['config'];

    /* Erst pruefen, ob die neue Datei ueberhaupt gueltiges PHP ist - sonst
     * waere die Oberflaeche nach dem Speichern nicht mehr aufrufbar.
     *
     * Diese Pruefung darf aber NICHT daran scheitern, dass "php" im
     * Suchpfad des Webserverbenutzers fehlt oder exec() gesperrt ist. Bis
     * 2.0.6 war genau das der Fall: "php nicht gefunden" ergab denselben
     * Rueckgabewert wie ein Syntaxfehler, das Speichern schlug fehl, und
     * die Oberflaeche schickte den Benutzer mit "Rechte im Plugin-Ordner
     * pruefen" in die falsche Richtung. Jetzt wird nur noch abgewiesen,
     * wenn die Ausgabe auch wirklich nach einem Syntaxfehler aussieht. */
    $php_l = function ($tmp) {
        if (!function_exists('exec')) { return true; }
        $aus = array(); $code = 0;
        @exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $aus, $code);
        $text = implode(' ', $aus);
        return !($code !== 0 && stripos($text, 'error') !== false
                 && stripos($text, 'not found') === false
                 && stripos($text, 'nicht gefunden') === false);
    };

    /* Atomar, Rechte VOR dem Inhalt, und geschrieben ist erst, was GANZ
     * geschrieben und zurueckgelesen ist (rn_datei_schreiben(), Befund C1).
     * Bis 2.1.12 galt fwrite() !== false als Erfolg. */
    if (!rn_datei_schreiben($datei, $z, 0600, $php_l)) {
        return false;
    }
    @chmod($datei, 0600);

    /* Die Zweitschrift entsteht aus der ZURUECKGELESENEN Datei, nicht aus
     * $z: sie soll abbilden, was wirklich dasteht. Weicht das Gelesene ab,
     * steht die Konfiguration nicht vollstaendig - dann false, und die
     * Zweitschrift bleibt, wie sie ist. */
    clearstatcache(true, $datei);
    $zurueck = @file_get_contents($datei);
    if ($zurueck !== $z) {
        rn_melden('ERROR', 'Die Konfiguration liess sich nach dem Schreiben nicht '
            . 'unveraendert zuruecklesen - die Zweitschrift bleibt unberuehrt.');
        return false;
    }

    /* Zweitschrift NEBEN dem Konfigordner - sie uebersteht damit auch eine
     * Deinstallation, die den Ordner selbst abraeumt. Mit denselben Rechten
     * wie das Original: sie enthaelt dasselbe Passwort.
     *
     * Sie wird NUR mitgezogen, wenn der zu schreibende Stand das Merkwort
     * traegt. Bis 2.1.5 geschah es bedingungslos - und damit ueberschrieb
     * ein Werksrueckfall (unlesbare config.php, neues Token) die heile
     * Zweitschrift mit genau den Werkswerten, vor denen sie schuetzen
     * sollte. Gemessen an einer 0 Byte grossen Datei: vorher 609 Byte mit
     * Token, nachher 563 Byte mit dem frisch gewuerfelten. */
    if ((string) $voll['aktionstoken'] === '') {
        rn_melden('WARN', 'Die Konfiguration wurde OHNE Aktionstoken geschrieben; '
            . 'die Zweitschrift bleibt deshalb unberuehrt. Im Reiter '
            . '"Einbindung in Loxone" ein Token erzeugen.');
        return true;
    }
    /* Auf demselben Weg wie die Konfiguration. Bis 2.1.12 wurden ftruncate()
     * und fwrite() der Zweitschrift gar nicht geprueft; eine gekuerzte ersetzte
     * per rename() die heile. Scheitert es jetzt, bleibt die bisherige
     * Zweitschrift unberuehrt, und das Protokoll sagt es. */
    $sich = rn_paths()['sicherung'];
    if (!rn_datei_schreiben($sich, $zurueck, 0600)) {
        rn_melden('WARN', 'Die Konfiguration ist geschrieben, die Zweitschrift ' . $sich
            . ' liess sich aber nicht erneuern - die bisherige bleibt unberuehrt. '
            . 'Freien Platz und Rechte im Konfigordner pruefen.');
        return true;
    }
    @chmod($sich, 0600);
    return true;
}

/* ==================================================================
 * Zustand
 * ================================================================== */

/**
 * Die gemeinsame Anmeldung: Datum | JWT | Kamereon-Konto | personId.
 *
 * Sie gilt fuer das Konto und wird von allen Fahrzeugen geteilt.
 */
function rn_anmeldung_lesen()
{
    $datei = rn_paths()['anmeldung'];
    /* Seit 2.1.20 (V1) fuenf Felder: das fuenfte ist die Zeit der letzten
     * Kontoleerung nach einer Abweisung; tolerant gelesen, fehlend = 0. */
    $leer  = array('0000', '', '', '', '0');
    if (!is_readable($datei)) {
        /* Uebergang von 2.0.6: dort standen die drei Werte in den Feldern
         * 0 bis 2 der Sitzungsdatei. Wer aktualisiert, soll sich nicht
         * neu anmelden muessen. */
        $alt = rn_session(1);
        if (is_array($alt) && isset($alt[2]) && $alt[2] !== '') {
            return array($alt[0], $alt[1], $alt[2], '', '0');
        }
        return $leer;
    }
    $roh = (string) @file_get_contents($datei);
    if ($roh === '') { return $leer; }
    $f = array_pad(array_slice(explode('|', $roh), 0, 5), 5, '');
    if (preg_match('/^[0-9]{1,12}$/', (string) $f[4]) !== 1) { $f[4] = '0'; }
    return $f;
}

/**
 * Die Anmeldung schreiben - geprueft und unteilbar (V7-f, T2-5): bis 2.1.19
 * wurden ftruncate()/fwrite() nicht geprueft, und bei voller Karte ersetzte
 * eine gekuerzte Datei die heile. Jetzt ueber rn_datei_schreiben(): scheitert
 * es, bleibt die alte Datei. 0600 - sie enthaelt ein gueltiges JWT.
 */
function rn_anmeldung_schreiben($a)
{
    $datei = rn_paths()['anmeldung'];
    $f = array_slice(array_pad(array_values((array) $a), 5, ''), 0, 5);
    if (preg_match('/^[0-9]{1,12}$/', (string) $f[4]) !== 1) { $f[4] = '0'; }
    if (!rn_datei_schreiben($datei, implode('|', $f), 0600)) {
        return false;
    }
    @chmod($datei, 0600);
    return true;
}

/** Die zwischengespeicherten Abrufdaten eines Fahrzeugs. */
function rn_session($fahrzeug = 1)
{
    $p = rn_paths();
    $nr = ((int) $fahrzeug > 1) ? (string) (int) $fahrzeug : '';
    $datei = $p['datadir'] . '/session' . $nr;
    if (!is_readable($datei)) {
        return null;
    }
    $roh = (string) @file_get_contents($datei);
    return $roh === '' ? null : explode('|', $roh);
}

/** Bedeutung der Felder in der session-Datei (Sprachschluessel). */
function rn_session_felder()
{
    return array(
        4  => 'FELD.LETZTER_ABRUF',
        7  => 'FELD.KILOMETERSTAND',
        8  => 'FELD.DATUM_STATUS',
        9  => 'FELD.UHRZEIT_STATUS',
        10 => 'FELD.LADESTATUS',
        11 => 'FELD.KABELSTATUS',
        12 => 'FELD.BATTERIESTAND',
        14 => 'FELD.REICHWEITE',
        24 => 'FELD.LADEMODUS',
        25 => 'FELD.LETZTER_ERFOLG',
        26 => 'FELD.MODELLCODE',
        27 => 'FELD.INNENTEMPERATUR',
        28 => 'FELD.AUSSENTEMPERATUR',
        // Gruppe 40 (2.1.20)
        29 => 'FELD.GPS_UNIX',
        30 => 'FELD.FAHRZEUGZEIT',
        31 => 'FELD.HVAC',
    );
}

/**
 * Die letzten Zeilen des Protokolls, rueckwaerts gelesen.
 *
 * Nicht die ganze Datei einlesen und nicht exec("tail") - beides ist
 * langsamer. Gemessen an 12.000 Zeilen: file()+array_reverse 0,37 ms mit
 * 2 MB zusaetzlichem Speicher, exec("tail") 2,17 ms, rueckwaerts mit
 * fseek 0,05 ms ohne zusaetzlichen Speicher.
 */
function rn_log_tail($max = 400, $block = 8192)
{
    $datei = rn_paths()['log'];
    /* Erst nachsehen, dann oeffnen. Das @ vor fopen unterdrueckt zwar die
     * Ausgabe, ruft aber trotzdem einen gesetzten Fehler-Aufnehmer - und
     * rendern.py haengt sich genau so ein. Solange es noch kein Protokoll
     * gibt, standen deshalb drei Meldungen je Seitenaufruf im Prueflauf,
     * fuer nichts. */
    if (!is_readable($datei)) {
        return array();
    }
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $max) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $max);
}

/* ==================================================================
 * MQTT
 * ================================================================== */

/**
 * Zugangsdaten des MQTT-Gateways.
 *
 * Das Gateway ist seit LoxBerry 3 Bestandteil des Systems, kein Plugin.
 * general.json.default setzt ab Werk Brokerhost localhost, Brokerport 1883,
 * Uselocalbroker 1 und Gatewayautostart 1.
 */
function rn_mqtt_broker()
{
    $datei = rn_paths()['general'];
    if (!is_readable($datei)) {
        return null;
    }
    $alles = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($alles)) {
        return null;
    }
    $mqtt = isset($alles['Mqtt']) ? $alles['Mqtt']
          : (isset($alles['mqtt']) ? $alles['mqtt'] : null);
    if (!is_array($mqtt)) {
        return null;
    }
    $hole = function ($gross, $klein, $vorgabe) use ($mqtt) {
        if (isset($mqtt[$gross])) { return $mqtt[$gross]; }
        if (isset($mqtt[$klein])) { return $mqtt[$klein]; }
        return $vorgabe;
    };
    $host = trim((string) $hole('Brokerhost', 'brokerhost', ''));
    if ($host === '') {
        return null;
    }
    return array(
        'host'      => $host,
        'port'      => (int) $hole('Brokerport', 'brokerport', 1883),
        'lokal'     => (int) $hole('Uselocalbroker', 'uselocalbroker', 1) ? true : false,
        'autostart' => (int) $hole('Gatewayautostart', 'gatewayautostart', 1) ? true : false,
        'benutzer'  => trim((string) $hole('Brokeruser', 'brokeruser', '')),
    );
}

/**
 * Alle Themen, die das Plugin veroeffentlicht - mit ihrem Sprachschluessel.
 *
 * =====================================================================
 * DIESE LISTE IST DIE ANLEITUNG. SIE MUSS MIT DEM SENDECODE UEBEREIN-
 * STIMMEN, SONST BAUT DER ANWENDER STUMME EINGAENGE.
 * =====================================================================
 *
 * Bis 2.0.6 stand hier BatteryLevel, RangeHvacOff, PlugStatus,
 * ChargingRemaining und ChargingPower. Gesendet hat abruf.php aber
 * BattSOC, Range, CableStatus, ChargingTime und ChargingEffekt. Die
 * falschen Namen standen an vier Stellen: in dieser Liste, in der
 * Themen-Tabelle des Reiters MQTT, in der Baustein-Liste und - am
 * teuersten - in der erzeugten Importdatei fuer Loxone Config. Wer sie
 * einlas, bekam fuer Batteriestand, Reichweite und Kabelzustand
 * Eingaenge, die dauerhaft auf 0 standen. Ohne jede Fehlermeldung.
 *
 * Angeglichen wurde die LISTE an den Sendecode, nicht umgekehrt:
 * Umbenennen im Sendecode haette jede bestehende Anlage gebrochen.
 * uninstall/uninstall nannte die richtigen Namen seit jeher.
 *
 * Gegengeprueft wird das seit 2.1.0 im Reiter Test ("Themen abgleichen"):
 * die Pruefung liest die publish()-Zeilen aus abruf.php und history.php
 * und haelt sie gegen diese Liste. Eine Liste, die niemand nachmisst,
 * laeuft wieder auseinander.
 */
function rn_themen($zoeph)
{
    $t = array(
        'BattSOC'           => 'THEMA.BATTSOC',
        'Range'             => 'THEMA.RANGE',
        'ChargingStatus'    => 'THEMA.CHARGINGSTATUS',
        'CableStatus'       => 'THEMA.CABLESTATUS',
        'ChargingTime'      => 'THEMA.CHARGINGTIME',
        'ChargingEffekt'    => 'THEMA.CHARGINGEFFEKT',
        'ChargeMode'        => 'THEMA.CHARGEMODE',
        'Mileage'           => 'THEMA.MILEAGE',
        'Name'              => 'THEMA.NAME',
        'HvAcStatus'        => 'THEMA.HVACSTATUS',
        'HvAcStatusBin'     => 'THEMA.HVACSTATUSBIN',
        'InTemp'            => 'THEMA.INTEMP',
        'OutTemp'           => 'THEMA.OUTTEMP',
        'phpCall'           => 'THEMA.PHPCALL',
        'LastDataRetrieval' => 'THEMA.LASTDATA',
        'ok'                => 'THEMA.OK',
    );
    if ((string) $zoeph === '1') {
        $t['BatTemp']       = 'THEMA.BATTEMP';
        $t['RenaultPHMode'] = 'THEMA.PHMODE1';
    } else {
        $t['GPS-Latitude']   = 'THEMA.GPSLAT';
        $t['GPS-Longitude']  = 'THEMA.GPSLON';
        $t['GPSTime']        = 'THEMA.GPSTIME';
        $t['EnergieOnBoard'] = 'THEMA.ENERGIE';
        $t['RenaultPHMode']  = 'THEMA.PHMODE2';
    }
    /* Aus dem Zehn-Minuten-Cron (history.php). Bis 2.0.6 fehlten sie in
     * dieser Liste vollstaendig, obwohl sie gesendet wurden.
     *
     * Die Klammern in den Namen sind haesslich und in einem Loxone-Eingang
     * unhandlich. Sie bleiben trotzdem: sie stehen so im Sendecode, und ein
     * bestehender virtueller Eingang heisst
     * Renault_<Name>_chargeDuration(min). Umbenennen waere derselbe Griff,
     * der oben schon einmal fuenf stumme Eingaenge erzeugt hat - nur in die
     * andere Richtung. Wenn sie fallen sollen, dann angekuendigt und in
     * beiden Richtungen, wie 1.4.1 es mit CargingStatus gemacht hat. */
    $t['chargeStartBatteryLevel(Prozent)'] = 'THEMA.CHG_START_SOC';
    $t['chargeEndBatteryLevel(Prozent)']   = 'THEMA.CHG_END_SOC';
    $t['chargeDuration(min)']              = 'THEMA.CHG_DAUER';
    $t['chargePowerAverage(kW)']           = 'THEMA.CHG_LEISTUNG';
    $t['chargeEnergyRecovered(kWh)']       = 'THEMA.CHG_ENERGIE';
    $t['chargeEndStatus']                  = 'THEMA.CHG_STATUS';
    $t['chargeStartInstantaneousPower']    = 'THEMA.CHG_STARTLEISTUNG';
    /* Das Lebenszeichen. Es geht bei JEDEM Cron-Durchgang hinaus, auch wenn
     * die Abrufbremse den Datenabruf ueberspringt - bis 2.1.5 wurde in einem
     * uebersprungenen Lauf gar nichts veroeffentlicht, auch kein "ok". Bei
     * der Werkseinstellung (Takt 5 Minuten, Cron alle 3) ist das jeder
     * zweite Lauf; ein stillstehender Cron war am Broker von einem regulaeren
     * Sprungzweig nicht zu unterscheiden. */
    $t['status/ts']      = 'THEMA.STATUS_TS';
    $t['status/zaehler'] = 'THEMA.STATUS_ZAEHLER';
    /* Gruppe 40 (2.1.20): Heimzone (3.1), Vorklimatisierung (3.2) und
     * Ladekosten der letzten Ladung (E-E2). */
    $t['Zuhause']       = 'THEMA.ZUHAUSE';
    $t['Entfernung']    = 'THEMA.ENTFERNUNG';
    $t['Vorklima']      = 'THEMA.VORKLIMA';
    $t['LadungKosten']  = 'THEMA.LADUNG_KOSTEN';
    $t['LadungEnergie'] = 'THEMA.LADUNG_ENERGIE';
    $t['LadungPreis']   = 'THEMA.LADUNG_PREIS';
    return $t;
}

/**
 * Die Beschriftung einer Spalte der Aufzeichnung.
 *
 * Die Kopfzeile in database.csv ist englisch und bleibt es - an ihr haengen
 * Auswertungen, die jemand ausserhalb dieses Plugins gebaut hat. Fuer die
 * Anzeige wird sie uebersetzt; ein unbekannter Name bleibt stehen, statt
 * durch eine leere Zelle ersetzt zu werden.
 */
function rn_csv_spalte($roh)
{
    $roh = trim((string) $roh);
    $tafel = array(
        'Date'                     => 'CSV.DATUM',
        'Time'                     => 'CSV.ZEIT',
        'Mileage'                  => 'CSV.KM',
        'Battery level'            => 'CSV.SOC',
        'Battery capacity'         => 'CSV.KAPAZITAET',
        'Range'                    => 'CSV.REICHWEITE',
        'Cable status'             => 'CSV.KABEL',
        'Charging status'          => 'CSV.LADESTATUS',
        'Charging speed'           => 'CSV.LADELEISTUNG',
        'Remaining charging time'  => 'CSV.RESTZEIT',
        'GPS Latitude'             => 'CSV.GPSBREITE',
        'GPS Longitude'            => 'CSV.GPSLAENGE',
        'GPS date'                 => 'CSV.GPSDATUM',
        'GPS time'                 => 'CSV.GPSZEIT',
        'Outside temperature'      => 'CSV.AUSSEN',
        'Weather condition'        => 'CSV.WETTER',
        'Charging schedule'        => 'CSV.LADEMODUS',
    );
    if (!isset($tafel[$roh])) {
        return $roh;
    }
    $t = rn_t($tafel[$roh]);
    return $t === $tafel[$roh] ? $roh : $t;
}

/**
 * Geht dieses Thema mit gesetztem Retain-Merker hinaus?
 *
 * Hausstandard seit 03.09.2026: Zustaende retained, Messwerte mit Zeitbezug
 * nicht, das Lebenszeichen nie. Bis 2.1.5 gingen ALLE 29 Themen retained
 * hinaus - eine Sendefunktion je Datei, beide mit publish(…, 0, 1), kein
 * Zweig ohne Retain.
 *
 * Die Unterscheidung steht hier an EINER Stelle; abruf.php und history.php
 * fragen beide danach, und die Themen-Tabelle im Reiter MQTT zeigt sie dem
 * Anwender in einer eigenen Spalte.
 *
 * Warum das Lebenszeichen nie retained ist: ein zurueckbehaltener
 * Zeitstempel zeigt einem neu verbindenden Teilnehmer einen alten Wert als
 * frisch - es meldet also immer "lebt", gerade dann nicht mehr, wenn es
 * darauf ankaeme.
 */
function rn_thema_retained($thema)
{
    /* Zustaende DES FAHRZEUGS: an/aus, Betriebsart, letzter Kilometerstand.
     * Nach einem Neustart des Miniservers oder des Gateways soll der Stand
     * sofort dastehen.
     *
     * "ok" steht seit 2.1.11 NICHT mehr hier (Entscheidung des Hausherrn vom
     * 18./19.09.2026, Regeln/07 Abschnitt 3): es sagt, ob der LETZTE ABRUF
     * DIESES PLUGINS gelang - eine Aussage des Dienstes ueber sich selbst.
     * Zurueckbehalten bliebe eine 1 stehen, wenn der Cron nicht mehr laeuft,
     * und nach einem Neustart von Broker oder Gateway laese Loxone "in
     * Ordnung" von einem toten Plugin. Bis 2.1.10 ging es retained hinaus,
     * und bin/rn_selbsttest.php verlangte das sogar. Den Altwert raeumt
     * rn_mqtt_altlast() einmal ab. */
    $zustaende = array(
        'ChargingStatus', 'CableStatus', 'ChargeMode', 'HvAcStatus',
        'HvAcStatusBin', 'RenaultPHMode', 'Name', 'Mileage',
        'chargeEndStatus',
        // Gruppe 40: zu Hause ja/nein und die letzte Ladung sind Zustaende.
        'Zuhause', 'LadungKosten', 'LadungEnergie', 'LadungPreis',
    );
    return in_array((string) $thema, $zustaende, true);
}

/**
 * Der Retain-Merker fuer EINE Sendung: 1 oder 0.
 *
 * rn_thema_retained() sagt, was ein Thema IST; diese Funktion sagt, wie ein
 * bestimmter Wert hinausgeht. Der Unterschied ist der leere Wert: eine leere
 * Nutzlast mit gesetztem Retain LOESCHT das zurueckbehaltene Thema im Broker
 * (mqttgateway.pl, sub udpin; am Broker dieser Anlage gemessen 14.09.2026).
 * Bis 2.1.8 ging z. B. CableStatus retained und leer hinaus, sobald die
 * Schnittstelle plugStatus nicht lieferte - der letzte gute Stand war danach
 * aus dem Broker verschwunden. Ein leerer Wert geht deshalb nie retained.
 *
 * Beide Sendefunktionen (abruf.php, history.php) fragen HIER, damit die
 * Ausnahme nicht an einer der beiden Stellen fehlen kann.
 */
function rn_retain_merker($thema, $wert)
{
    if ((string) $wert === '') { return 0; }
    return rn_thema_retained($thema) ? 1 : 0;
}

/**
 * Themen, die frueher zurueckbehalten hinausgingen und es heute nicht mehr
 * tun - je Sendeweg ('abruf' = abruf.php, 'history' = history.php).
 *
 * Bis 2.1.5 gingen ALLE Themen retained hinaus (publish(..., 0, 1) in beiden
 * Dateien, gelesen in den Archiven 2.1.4 und 2.1.5); seit 2.1.6 sind die
 * Messwerte und Zeitangaben fluechtig, "ok" seit 2.1.11. Ein spaeteres
 * fluechtiges publish ersetzt einen zurueckbehaltenen Wert NICHT: auf einer
 * Anlage, die von 2.1.5 oder frueher kommt, stehen die Altwerte bis heute
 * im Broker, und nach jedem Neustart von Broker oder Gateway bekommt Loxone
 * sie wieder als frisch. Bis 2.1.10 raeumte sie niemand ab (in WSL gemessen,
 * Pruefung-Renault-NG-2.1.11, Fall R4).
 *
 * Keines davon darf heute retained sein; bin/rn_selbsttest.php prueft das.
 */
function rn_mqtt_frueher_behalten($gruppe)
{
    if ($gruppe === 'history') {
        return array(
            'chargeStartBatteryLevel(Prozent)', 'chargeEndBatteryLevel(Prozent)',
            'chargeDuration(min)', 'chargePowerAverage(kW)',
            'chargeEnergyRecovered(kWh)', 'chargeStartInstantaneousPower',
        );
    }
    return array(
        'ok', 'BattSOC', 'Range', 'ChargingTime', 'ChargingEffekt', 'InTemp',
        'OutTemp', 'BatTemp', 'GPS-Latitude', 'GPS-Longitude', 'GPSTime',
        'EnergieOnBoard', 'phpCall', 'LastDataRetrieval',
    );
}

/**
 * Die Zugangsdaten des Brokers: array(host, port, user, pass) oder null.
 *
 * Dieselben, mit denen abruf.php und history.php senden
 * (mqtt_connectiondetails() aus loxberry_io.php); ohne diese Funktion aus
 * der general.json der Anlage (Regeln/07, Abschnitt 2). Das Kennwort steht
 * nur im CONNECT-Paket, nie in einem Protokoll.
 */
function rn_mqtt_zugang()
{
    if (function_exists('mqtt_connectiondetails')) {
        $d = mqtt_connectiondetails();
        if (is_array($d) && !empty($d['brokerhost'])) {
            return array(
                'host' => (string) $d['brokerhost'],
                'port' => isset($d['brokerport']) ? (int) $d['brokerport'] : 1883,
                'user' => isset($d['brokeruser']) ? (string) $d['brokeruser'] : '',
                'pass' => isset($d['brokerpass']) ? (string) $d['brokerpass'] : '',
            );
        }
    }
    $datei = rn_paths(false)['general'];
    if ($datei === '' || !is_readable($datei)) {
        return null;
    }
    $alles = json_decode((string) @file_get_contents($datei), true);
    $m = null;
    if (is_array($alles) && isset($alles['Mqtt']) && is_array($alles['Mqtt'])) { $m = $alles['Mqtt']; }
    elseif (is_array($alles) && isset($alles['mqtt']) && is_array($alles['mqtt'])) { $m = $alles['mqtt']; }
    if (!$m) {
        return null;
    }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) { return (string) $m[$gross]; }
        return isset($m[$klein]) ? (string) $m[$klein] : '';
    };
    $host = trim($hol('Brokerhost', 'brokerhost'));
    if ($host === '') {
        return null;
    }
    return array('host' => $host, 'port' => (int) $hol('Brokerport', 'brokerport'),
                 'user' => $hol('Brokeruser', 'brokeruser'),
                 'pass' => $hol('Brokerpass', 'brokerpass'));
}

/**
 * Eine Sitzung beim Broker: erst $leeren mit leerer retain-Nutzlast
 * loeschen, dann nachlesen, welche der Themen $fragen noch zurueckbehalten
 * dastehen - in EINER Verbindung, ein SUBSCRIBE mit allen Filtern. Der Broker
 * arbeitet die Pakete eines Teilnehmers der Reihe nach ab; was er nach dem
 * Loeschen noch schickt, steht wirklich noch da.
 *
 * Rueckgabe array('lage' => 'ok'|'gesendet'|'unbekannt', 'belegt' => array(thema => true)).
 * 'ok': der Broker hat das Abonnement bestaetigt (oder einen Wert geschickt);
 * was dann nicht unter 'belegt' steht, ist leer. 'gesendet': nur geloescht,
 * nicht gefragt. 'unbekannt': keine Verbindung, Anmeldung abgewiesen, keine
 * Antwort.
 *
 * MQTT 3.1.1 von Hand (CONNECT, PUBLISH, SUBSCRIBE mit QoS 0, DISCONNECT),
 * ohne fremde Bibliothek: phpMQTT kann nicht sagen, was zurueckbehalten ist.
 * Bauart wie tb_mqtt_behalten_liste() der Linie Spotpreis-Tibber 0.9.19.
 */
function rn_mqtt_sitzung($zugang, array $leeren, array $fragen)
{
    $aus = array('lage' => 'unbekannt', 'belegt' => array());
    if (!is_array($zugang)) {
        return $aus;
    }
    $soll = array();
    foreach ($fragen as $t) {
        if ((string) $t !== '') { $soll[(string) $t] = true; }
    }
    $host = trim((string) $zugang['host']);
    if ($host === '' || $host === 'localhost') { $host = '127.0.0.1'; }
    $port = (int) $zugang['port'];
    if ($port <= 0 || $port > 65535) { $port = 1883; }
    $benutzer = (string) $zugang['user'];
    $kennwort = (string) $zugang['pass'];

    $s = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, 2);
    if (!$s) {
        return $aus;
    }
    stream_set_timeout($s, 1);

    $zk = function ($t) { return pack('n', strlen($t)) . $t; };
    $laenge = function ($n) {
        $o = '';
        do {
            $b = $n % 128;
            $n = intdiv($n, 128);
            if ($n > 0) { $b |= 128; }
            $o .= chr($b);
        } while ($n > 0);
        return $o;
    };
    /* Genau $n Bytes lesen oder null - bei Zeitablauf und Verbindungsende. */
    $lies = function ($n) use ($s) {
        $d = '';
        while (strlen($d) < $n) {
            $t = @fread($s, $n - strlen($d));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($s);
                if (!empty($meta['timed_out']) || !empty($meta['eof']) || feof($s)) { return null; }
                continue;
            }
            $d .= $t;
        }
        return $d;
    };
    /* Ein Paket: array(kopfbyte, rumpf) oder null. */
    $paket = function () use ($lies) {
        $k = $lies(1);
        if ($k === null) { return null; }
        $n = 0; $mult = 1;
        for ($i = 0; $i < 4; $i++) {
            $b = $lies(1);
            if ($b === null) { return null; }
            $n += (ord($b) & 127) * $mult;
            $mult *= 128;
            if (!(ord($b) & 128)) { break; }
        }
        $r = ($n > 0) ? $lies($n) : '';
        return ($r === null) ? null : array(ord($k), $r);
    };

    $flags = 0x02;                                  // saubere Sitzung
    $nutz = $zk('rnrueck' . getmypid());
    if ($benutzer !== '') {
        $flags |= 0x80;
        // Ein Kennwort ohne Benutzer laesst MQTT 3.1.1 nicht zu.
        if ($kennwort !== '') { $flags |= 0x40; }
    }
    $kopf = $zk('MQTT') . chr(4) . chr($flags) . pack('n', 10);
    if ($benutzer !== '') {
        $nutz .= $zk($benutzer);
        if ($kennwort !== '') { $nutz .= $zk($kennwort); }
    }
    if (@fwrite($s, chr(0x10) . $laenge(strlen($kopf . $nutz)) . $kopf . $nutz) !== false) {
        $ack = $paket();
        if ($ack !== null && ($ack[0] >> 4) === 2 && strlen($ack[1]) >= 2 && ord($ack[1][1]) === 0) {
            $geschrieben = true;
            foreach ($leeren as $t) {
                // PUBLISH, QoS 0, retain gesetzt, ohne Nutzlast: die Loeschung.
                $r = $zk((string) $t);
                if (@fwrite($s, chr(0x31) . $laenge(strlen($r)) . $r) === false) {
                    $geschrieben = false;
                }
            }
            if (!$soll) {
                $aus['lage'] = $geschrieben ? 'gesendet' : 'unbekannt';
            } else {
                $sub = pack('n', 1);
                foreach (array_keys($soll) as $t) { $sub .= $zk($t) . chr(0); }
                @fwrite($s, chr(0x82) . $laenge(strlen($sub)) . $sub);
                $bestaetigt = false;
                $ende = microtime(true) + 3.0;
                while (microtime(true) < $ende) {
                    $pk = $paket();
                    if ($pk === null) { break; }       // Zeitablauf: nichts mehr gekommen
                    $art = $pk[0] >> 4;
                    if ($art === 9) {
                        /* Je Filter ein Rueckgabebyte hinter der Paketkennung, in der
                           Reihenfolge des SUBSCRIBE; ab 0x80 heisst abgelehnt (etwa
                           durch eine ACL). Danach schickt der Broker nichts - ungeprueft
                           hiesse das "nichts belegt", und der Merker laege auf einer
                           Antwort, die keine war (in WSL gemessen, Pruefung-Renault-NG-2.1.12,
                           Faelle S3, S4, S7, S9, S11). Bauart bw_mqtt_behalten_liste(),
                           Beschattungswaechter 0.9.21. */
                        $rc = (string) substr($pk[1], 2);
                        if (strlen($rc) !== count($soll)) { break; }
                        $abgelehnt = false;
                        for ($i = 0; $i < strlen($rc); $i++) {
                            if (ord($rc[$i]) >= 0x80) { $abgelehnt = true; }
                        }
                        if ($abgelehnt) { break; }
                        $bestaetigt = true;
                        // Zurueckbehaltenes kommt unmittelbar nach dem SUBACK.
                        $ende = min($ende, microtime(true) + 1.0);
                    } elseif ($art === 3 && strlen($pk[1]) >= 2) {
                        $tl = unpack('n', substr($pk[1], 0, 2));
                        $t = substr($pk[1], 2, $tl[1]);
                        $versatz = 2 + $tl[1] + ((($pk[0] >> 1) & 3) > 0 ? 2 : 0);
                        $wert = (string) substr($pk[1], $versatz);
                        if (isset($soll[$t]) && ($pk[0] & 1) && $wert !== '') {
                            $aus['belegt'][$t] = true;
                            if (count($aus['belegt']) === count($soll)) { break; }
                        }
                    }
                }
                if ($bestaetigt || $aus['belegt']) { $aus['lage'] = 'ok'; }
            }
        }
        @fwrite($s, chr(0xE0) . chr(0));
    }
    fclose($s);
    return $aus;
}

/**
 * Welche Altwerte muessen fuer dieses Fahrzeug in diesem Lauf noch
 * abgeraeumt werden? Rueckgabe array('lage' => 'erledigt'|'belegt'|
 * 'unbekannt', 'themen' => array(<thema ohne Renault/<name>/>, ...)).
 *
 * Je Lauf, bis der Merker liegt:
 *   1. den Broker nach allen Themen aus rn_mqtt_frueher_behalten() fragen;
 *   2. keines belegt -> Merker schreiben, nichts abraeumen ('erledigt');
 *      einige belegt -> genau diese vormerken ('belegt'); nicht zu fragen ->
 *      alle vormerken ('unbekannt');
 *   3. im vollen Lauf geht vor jedem gueltigen Wert eines vorgemerkten
 *      Themas die leere retain-Nutzlast hinaus (rn_mqtt_senden()). Was
 *      danach noch vorgemerkt ist - das Thema bekam in diesem Lauf keinen
 *      oder einen leeren Wert -, loescht rn_mqtt_altlast_abschluss() am Ende
 *      des Laufs direkt und liest beim Broker nach.
 * Bis 2.1.12 wurde nur vor einem nichtleeren Wert geloescht (Befund M1):
 * BatTemp sendet ein Fahrzeug der Phase 2 nie, ChargingTime kommt nur beim
 * Laden - diese Altwerte blieben unbegrenzt retained, und JEDER Lauf oeffnete
 * eine zweite Broker-Sitzung und schrieb "stehen noch" ins Protokoll (in WSL
 * gemessen, Fall G: nach drei Laeufen BatTemp=18 und ChargingTime=45).
 *
 * Der Merker entsteht nur, wenn der BROKER sagt, dass nichts mehr dasteht -
 * nie auf das blosse Senden hin (Regeln/07, "Ein Absender merkt nichts
 * davon"). Er traegt die Kennung "leer-bestaetigt Renault/<name> <gruppe>:
 * <Themenliste>"; ein anderer Inhalt - anderes Fahrzeug, andere Liste, ein
 * Merker einer anderen Fassung - gilt nicht. purge_installation raeumt ihn
 * bei jedem Upgrade mit ab; dann wird genau einmal nachgefragt.
 */
function rn_mqtt_altlast($gruppe, $name)
{
    $liste = rn_mqtt_frueher_behalten($gruppe);
    list($merker, $kennung) = rn_mqtt_altlast_merker($gruppe, $name);
    if (is_file($merker) && trim((string) @file_get_contents($merker)) === $kennung) {
        return array('lage' => 'erledigt', 'themen' => array());
    }
    $praefix = 'Renault/' . $name . '/';
    $voll = array();
    foreach ($liste as $t) { $voll[] = $praefix . $t; }
    $f = rn_mqtt_sitzung(rn_mqtt_zugang(), array(), $voll);
    if ($f['lage'] === 'ok' && !$f['belegt']) {
        rn_mqtt_altlast_bestaetigt($gruppe, $name);
        return array('lage' => 'erledigt', 'themen' => array());
    }
    if ($f['lage'] === 'ok') {
        $t = array();
        foreach (array_keys($f['belegt']) as $v) { $t[] = substr($v, strlen($praefix)); }
        rn_melden('INFO', 'MQTT: unter ' . $praefix . ' stehen noch ' . count($t) . ' frueher '
            . 'zurueckbehaltene Werte im Broker (' . implode(', ', $t) . ') - sie werden in diesem '
            . 'Lauf geloescht (vor einem gueltigen Wert, sonst am Ende des Laufs) und beim Broker '
            . 'nachgelesen.');
        return array('lage' => 'belegt', 'themen' => $t);
    }
    rn_melden_stuendlich('WARN', 'MQTT: der Broker liess sich nicht befragen, ob unter ' . $praefix
        . ' noch frueher zurueckbehaltene Werte stehen. Sie werden deshalb in jedem vollen Lauf '
        . 'geloescht, bis der Broker antwortet.');
    return array('lage' => 'unbekannt', 'themen' => $liste);
}

/** Pfad und Kennung des Merkers eines Fahrzeugs: array(pfad, kennung). */
function rn_mqtt_altlast_merker($gruppe, $name)
{
    $p = rn_paths();
    return array(
        $p['datadir'] . '/.mqtt_altlast_' . $gruppe . '_' . substr(md5((string) $name), 0, 12),
        'leer-bestaetigt Renault/' . $name . ' ' . $gruppe . ': '
            . implode(' ', rn_mqtt_frueher_behalten($gruppe)),
    );
}

/** Den Merker schreiben - nur aufzurufen, wenn der Broker bestaetigt hat. */
function rn_mqtt_altlast_bestaetigt($gruppe, $name)
{
    list($merker, $kennung) = rn_mqtt_altlast_merker($gruppe, $name);
    $praefix = 'Renault/' . $name . '/';
    if (!rn_datei_schreiben($merker, $kennung . "\n", 0644)) {
        rn_melden('WARN', 'MQTT: der Merker ' . $merker . ' liess sich nicht schreiben - '
            . 'der Broker wird im naechsten Lauf wieder gefragt.');
        return false;
    }
    rn_melden('INFO', 'MQTT: unter ' . $praefix . ' steht keines der '
        . count(rn_mqtt_frueher_behalten($gruppe)) . ' frueher zurueckbehaltenen Themen ('
        . $gruppe . ') mehr im Broker - vom Broker bestaetigt.');
    return true;
}

/**
 * Am Ende eines VOLLEN Laufs (Befund M1): was noch vorgemerkt ist - das
 * Thema bekam keinen oder einen leeren Wert -, direkt loeschen und beim
 * Broker nachlesen, in EINER Sitzung. Bestaetigt der Broker, dass keines der
 * frueher zurueckbehaltenen Themen mehr dasteht, entsteht der Merker, und
 * kein weiterer Lauf oeffnet dafuer eine Sitzung. $alt ist die Rueckgabe von
 * rn_mqtt_altlast() aus diesem Lauf.
 */
function rn_mqtt_altlast_abschluss($gruppe, $name, $alt)
{
    $rest = rn_altlast_faellig($name, '', 'rest');
    if (!is_array($alt) || !isset($alt['lage']) || $alt['lage'] === 'erledigt') { return; }
    $praefix = 'Renault/' . $name . '/';
    $leeren = array();
    foreach ($rest as $t) { $leeren[] = $praefix . $t; }
    $fragen = array();
    foreach (rn_mqtt_frueher_behalten($gruppe) as $t) { $fragen[] = $praefix . $t; }
    $f = rn_mqtt_sitzung(rn_mqtt_zugang(), $leeren, $fragen);
    if ($f['lage'] === 'ok' && !$f['belegt']) {
        rn_mqtt_altlast_bestaetigt($gruppe, $name);
        return;
    }
    if ($f['lage'] === 'ok') {
        $t = array();
        foreach (array_keys($f['belegt']) as $v) { $t[] = substr($v, strlen($praefix)); }
        rn_melden_stuendlich('WARN', 'MQTT: unter ' . $praefix . ' stehen nach dem Loeschen noch '
            . count($t) . ' frueher zurueckbehaltene Werte im Broker (' . implode(', ', $t)
            . ') - der naechste volle Lauf versucht es erneut.');
        return;
    }
    rn_melden_stuendlich('WARN', 'MQTT: nach dem Loeschen der Altwerte unter ' . $praefix
        . ' liess sich der Broker nicht befragen - der naechste volle Lauf versucht es erneut.');
}

/**
 * Vormerken (drittes Argument ein Feld) oder abfragen, ob vor dem naechsten
 * gueltigen Wert dieses Themas der Altwert zu loeschen ist. Die Abfrage
 * verbraucht die Vormerkung - geloescht wird einmal je Lauf. Mit 'rest' als
 * drittem Argument: alle noch offenen Vormerkungen dieses Fahrzeugs (Themen
 * ohne Praefix) - und sie sind damit verbraucht.
 */
function rn_altlast_faellig($name, $thema, $vormerken = null)
{
    static $offen = array();
    if ($vormerken === 'rest') {
        $rest = array();
        foreach (array_keys($offen) as $k) {
            if (strpos($k, $name . '/') === 0) {
                $rest[] = substr($k, strlen($name) + 1);
                unset($offen[$k]);
            }
        }
        return $rest;
    }
    if (is_array($vormerken)) {
        foreach ($vormerken as $t) { $offen[$name . '/' . $t] = true; }
        return false;
    }
    $k = $name . '/' . $thema;
    if (isset($offen[$k])) {
        unset($offen[$k]);
        return true;
    }
    return false;
}

/**
 * Was fuer einen Wert hinausgeht: die Nutzlast, oder null = nicht senden.
 *
 * Regeln/07 Z. 766 (Befund M2): ein LEERER Messwert wird nicht gesendet -
 * das Gateway reicht eine leere Nachricht an den Miniserver weiter, und ein
 * Analogeingang liest daraus 0 (bis 2.1.12 in WSL gemessen: ChargingTime,
 * ChargingEffekt, InTemp, OutTemp und EnergieOnBoard gingen in einem Lauf
 * leer hinaus). Ein leerer ZUSTAND geht als "-" retained: fluechtig und
 * leer bekam Loxone 0, waehrend der Broker den alten Stand behielt - zwei
 * Wahrheiten. Was ein Zustand ist, sagt rn_thema_retained().
 */
function rn_mqtt_nutzlast($thema, $wert)
{
    $w = (string) $wert;
    if ($w !== '') { return $w; }
    /* Seit 2.1.20 (Hausform -1-Regel): ein Zahlthema ohne Wert geht mit
     * seinem Fehlwert hinaus (rn_fehlwert(): -1, Temperaturen -99, GPS -999),
     * ein Zustand weiter als "-"; ChargingStatus ohne Wert nicht (Begruendung
     * dort). Bis 2.1.19 ging ein leeres Zahlthema gar nicht hinaus, und Loxone
     * hielt den alten Wert fuer frisch. */
    return rn_fehlwert($thema);
}

/**
 * Kennt das Fahrzeug einen Nebenendpunkt NACHWEISLICH nicht? Nur dann darf
 * ein Zustand "n/a" heissen (Befund M4); jeder andere Fehlschlag laesst den
 * letzten gemessenen Stand im Broker stehen.
 */
function rn_endpunkt_unbekannt($code)
{
    return in_array((int) $code, array(404, 405, 501), true);
}

/**
 * EIN Thema senden - die gemeinsame Sendestelle von abruf.php und
 * history.php.
 *
 * Die Nutzlast kommt aus rn_mqtt_nutzlast(): ein leerer Messwert geht gar
 * nicht hinaus, ein leerer Zustand als "-" (Befund M2). Ist fuer das Thema
 * ein Altwert vorgemerkt (rn_mqtt_altlast()), geht unmittelbar davor die
 * leere retain-Nutzlast hinaus, die ihn loescht; was ohne Wert bleibt,
 * loescht rn_mqtt_altlast_abschluss() am Ende des Laufs.
 */
function rn_mqtt_senden($mqtt, $name, $thema, $wert)
{
    if ($mqtt === null) { return; }
    $nutz = rn_mqtt_nutzlast($thema, $wert);
    if ($nutz === null) { return; }
    $voll = 'Renault/' . $name . '/' . $thema;
    if (rn_altlast_faellig($name, $thema)) {
        $mqtt->publish($voll, '', 0, 1);
    }
    $mqtt->publish($voll, $nutz, 0, rn_retain_merker($thema, $nutz));
}

/**
 * Alle Themen, die dieses Plugin je gesendet hat - fuer die Deinstallation.
 * Beide Generationen, dazu die frueher zurueckbehaltenen; ein Thema, das
 * heute fluechtig geht, wird dort nur geloescht, wenn der Broker es noch
 * zurueckbehaelt.
 */
function rn_mqtt_alle_themen()
{
    $t = array();
    foreach (array_merge(array_keys(rn_themen('1')), array_keys(rn_themen('2')),
                         rn_mqtt_frueher_behalten('abruf'),
                         rn_mqtt_frueher_behalten('history')) as $k) {
        $t[$k] = true;
    }
    return array_keys($t);
}

/**
 * Die Namen der eingerichteten Fahrzeuge - aus der Konfiguration, ersatzweise
 * aus der Zweitschrift, OHNE Selbstheilung und ohne etwas zu schreiben (die
 * Deinstallation darf nichts anlegen). Dieselbe Regel wie rn_fahrzeuge().
 */
function rn_mqtt_fahrzeugnamen()
{
    $cfg = rn_config_read(false);
    if (!in_array(rn_konfig_lage(), array('ok', 'ohne Token'), true)) {
        $w = rn_config_einlesen(rn_paths(false)['sicherung']);
        if (is_array($w)) {
            foreach ($cfg as $k => $v) {
                if (array_key_exists($k, $w) && !is_array($w[$k])) { $cfg[$k] = (string) $w[$k]; }
            }
        }
    }
    $namen = array();
    for ($i = 1; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $nr   = ($i === 1) ? '' : (string) $i;
        $vin  = trim((string) $cfg['vin' . $nr]);
        $name = trim((string) $cfg['zoename' . $nr]);
        if ($i > 1 && $vin === '' && $name === '') { continue; }
        if ($name === '') { $name = 'Renault' . $nr; }
        $namen[$name] = true;
    }
    return array_keys($namen);
}

/**
 * Aus der Deinstallation (abruf.php --mqtt-leeren, gerufen von
 * uninstall/uninstall): die zurueckbehaltenen Themen der eingerichteten
 * Fahrzeuge leeren und beim Broker nachlesen.
 *
 * VOR der ersten Runde und in jeder wird der Broker gefragt; geloescht wird
 * nur, was dort noch steht, hoechstens $runden Runden. Bis 2.1.10 raeumte die
 * Deinstallation nichts ab: die Zustaende blieben im Broker, und nach jedem
 * Neustart von Broker oder Gateway bekam der Miniserver den Ladestand vom
 * Tag der Deinstallation (in WSL gemessen, Pruefung-Renault-NG-2.1.11,
 * Fall U1).
 *
 * Schreibt weder Protokoll noch Datei. Ausgabe im Format der Hakenskripte.
 * Rueckgabe 0 geleert (vom Broker bestaetigt), 1 es steht noch etwas oder
 * der Broker antwortete nicht, 2 nicht moeglich (kein Broker eingetragen).
 */
function rn_mqtt_leeren($runden = 3)
{
    $z = rn_mqtt_zugang();
    if (!$z) {
        echo "<INFO> MQTT: in der general.json steht kein Broker - zurueckbehaltene Themen "
           . "unter Renault/ wurden nicht geleert.\n";
        return 2;
    }
    $namen = rn_mqtt_fahrzeugnamen();
    $alle = array();
    foreach ($namen as $n) {
        foreach (rn_mqtt_alle_themen() as $t) { $alle[] = 'Renault/' . $n . '/' . $t; }
    }
    $wo = 'Renault/' . implode('/, Renault/', $namen) . '/';
    $f = rn_mqtt_sitzung($z, array(), $alle);
    if ($f['lage'] !== 'ok') {
        echo "<WARNING> MQTT: der Broker war nicht zu erreichen, hat die Anmeldung abgewiesen "
           . "oder das Abonnement abgelehnt - zurueckbehaltene Themen unter " . $wo . " wurden nicht geleert. "
           . "Von Hand: LoxBerry -> System -> MQTT Gateway, oder mosquitto_pub -r -n -t <thema>.\n";
        return 1;
    }
    $offen = array_keys($f['belegt']);
    if (!$offen) {
        echo "<OK> MQTT: der Broker bestaetigt: unter " . $wo . " steht keines der "
           . count($alle) . " Themen zurueckbehalten - nichts zu leeren.\n";
        return 0;
    }
    $zu = count($offen);
    for ($r = 1; $r <= max(1, (int) $runden) && $offen; $r++) {
        if ($r > 1) { usleep(500000); }
        $f = rn_mqtt_sitzung($z, $offen, $offen);
        if ($f['lage'] !== 'ok') { break; }
        $offen = array_keys($f['belegt']);
    }
    echo "<INFO> MQTT: " . $zu . " Themen unter " . $wo . " standen zurueckbehalten im Broker "
       . "und wurden mit leerer Nutzlast geloescht.\n";
    if ($f['lage'] === 'ok' && !$offen) {
        echo "<OK> MQTT: der Broker bestaetigt: keines davon steht mehr zurueckbehalten.\n";
        return 0;
    }
    if ($f['lage'] === 'ok') {
        echo "<WARNING> MQTT: " . count($offen) . " Themen stehen noch zurueckbehalten im Broker ("
           . implode(', ', array_slice($offen, 0, 5)) . (count($offen) > 5 ? ', ...' : '')
           . "). Von Hand: mosquitto_pub -r -n -t <thema>\n";
        return 1;
    }
    echo "<WARNING> MQTT: nach dem Loeschen liess sich der Broker nicht mehr befragen - nicht "
       . "nachgelesen.\n";
    return 1;
}

/**
 * Die zurueckbehaltenen Themen EINES Fahrzeugnamens leeren und beim Broker
 * nachlesen - fuer die Oberflaeche, wenn ein Fahrzeug umbenannt oder
 * entfernt wurde (Befund M3). Bis 2.1.12 blieben dessen Zustaende fuer immer
 * im Broker - das Gateway (Abo Renault/#) lieferte sie nach jedem Neustart
 * wieder an Loxone -, und die Deinstallation kannte den alten Namen nicht
 * mehr (in WSL gemessen, Fall U: Zoe -> Zoe2, danach 8 Themen unter
 * Renault/Zoe/).
 *
 * Ablauf wie rn_mqtt_leeren(): erst fragen, dann nur Belegtes loeschen und
 * nachlesen, hoechstens $runden Runden. Schreibt nichts und gibt nichts aus.
 * Rueckgabe array('lage' => 'ok'|'offen'|'unbekannt'|'kein_broker',
 * 'geloescht' => Zahl der belegten Themen, 'offen' => array(Themen)).
 */
function rn_mqtt_name_leeren($name, $runden = 3)
{
    $aus = array('lage' => 'kein_broker', 'geloescht' => 0, 'offen' => array());
    $z = rn_mqtt_zugang();
    if (!$z) { return $aus; }
    $alle = array();
    foreach (rn_mqtt_alle_themen() as $t) { $alle[] = 'Renault/' . $name . '/' . $t; }
    $f = rn_mqtt_sitzung($z, array(), $alle);
    if ($f['lage'] !== 'ok') { $aus['lage'] = 'unbekannt'; return $aus; }
    $offen = array_keys($f['belegt']);
    $aus['geloescht'] = count($offen);
    for ($r = 1; $r <= max(1, (int) $runden) && $offen; $r++) {
        if ($r > 1) { usleep(500000); }
        $f = rn_mqtt_sitzung($z, $offen, $offen);
        if ($f['lage'] !== 'ok') { break; }
        $offen = array_keys($f['belegt']);
    }
    $aus['offen'] = $offen;
    if ($f['lage'] !== 'ok') { $aus['lage'] = 'unbekannt'; return $aus; }
    $aus['lage'] = $offen ? 'offen' : 'ok';
    return $aus;
}

/**
 * Die zurueckbehaltenen Themen weggefallener Fahrzeugnamen abraeumen (Befund
 * M3) - fuer "Speichern" UND fuer das Zurueckspielen einer Sicherung
 * (Renault-a2). Bis 2.1.14 stand dieser Block nur im Speichern-Handler: eine
 * zurueckgespielte Sicherung mit anderen Fahrzeugnamen liess die Themen unter
 * dem alten Namen fuer immer im Broker, und das Gateway (Abo Renault/#)
 * lieferte sie nach jedem Neustart wieder an Loxone.
 *
 * $vorher, $nachher: die Fahrzeugnamen (rn_fahrzeuge()[..]['name']) vor und
 * nach dem Schreiben. Je weggefallenem Namen: leeren, beim Broker nachlesen.
 * Rueckgabe array('meldung' => Text zum Anhaengen an die Erfolgsmeldung
 * (beginnt mit <br>, schon maskiert), 'misslungen' => Zeilen fuer "Der
 * Vorgang ist nicht gelungen"). Protokollzeilen wie bisher.
 */
function rn_mqtt_altnamen_abraeumen(array $vorher, array $nachher)
{
    $aus = array('meldung' => '', 'misslungen' => array());
    foreach (array_values(array_diff($vorher, $nachher)) as $altname) {
        $wo = 'Renault/' . $altname . '/';
        $l = rn_mqtt_name_leeren($altname);
        if ($l['lage'] === 'ok') {
            $aus['meldung'] .= '<br>' . ($l['geloescht'] > 0
                ? sprintf(rn_t('TEXT.M_MQTT_ALTNAME_GELOESCHT'), $l['geloescht'], rn_e($wo))
                : sprintf(rn_t('TEXT.M_MQTT_ALTNAME_LEER'), rn_e($wo)));
            rn_melden('INFO', 'MQTT: Fahrzeug umbenannt oder entfernt - unter ' . $wo
                . ' ' . $l['geloescht'] . ' zurueckbehaltene Themen geloescht, vom Broker bestaetigt.');
        } elseif ($l['lage'] === 'offen') {
            $aus['misslungen'][] = sprintf(rn_t('TEXT.F_MQTT_ALTNAME_OFFEN'), rn_e($wo),
                count($l['offen']), rn_e(implode(', ', array_slice($l['offen'], 0, 5))));
            rn_melden('WARN', 'MQTT: unter ' . $wo . ' stehen nach dem Loeschen noch '
                . count($l['offen']) . ' zurueckbehaltene Themen.');
        } else {
            $aus['misslungen'][] = sprintf(rn_t('TEXT.F_MQTT_ALTNAME_BROKER'), rn_e($wo));
            rn_melden('WARN', 'MQTT: Fahrzeug umbenannt oder entfernt - der Broker war nicht '
                . 'zu befragen, die Themen unter ' . $wo . ' sind NICHT geloescht.');
        }
    }
    return $aus;
}

/**
 * Die Befehle, die Loxone an das Plugin senden kann.
 *
 * Je Befehl: Sprachschluessel und ob er am Fahrzeug etwas VERAENDERT.
 * Nur die veraendernden verlangen, dass die Steuerung in den Einstellungen
 * eingeschaltet ist - "abruf" holt nur Daten und bleibt immer erlaubt.
 */
function rn_befehle()
{
    return array(
        'acnow'      => array('BEFEHL.ACNOW',      true),
        'acoff'      => array('BEFEHL.ACOFF',      true),
        'chargenow'  => array('BEFEHL.CHARGENOW',  true),
        'chargestop' => array('BEFEHL.CHARGESTOP', true),
        'cmon'       => array('BEFEHL.CMON',       true),
        'cmoff'      => array('BEFEHL.CMOFF',      true),
        'abruf'      => array('BEFEHL.ABRUF',      false),
    );
}

/** Veraendert dieser Befehl etwas am Fahrzeug? */
function rn_befehl_schaltet($aktion)
{
    $b = rn_befehle();
    return isset($b[$aktion]) ? (bool) $b[$aktion][1] : true;
}

/**
 * Fester deutscher Titel eines Befehls in der Loxone-Vorlage (rn_vorlage_vo()).
 *
 * Welle Bild 17 (2.1.19, Entwurf Welle 17 rn, E13; Sprachregel 13.10.2026, Regeln/07): Titel bleiben fest
 * deutsch, nur der Kommentar wird uebersetzt. Bis 2.1.18 kam der Titel aus BEFEHL.* der Sprachdatei, und ein
 * englischer LoxBerry legte "Start preconditioning" an - Baustein-Liste, Bild und Musterprojekt passten dann
 * nicht mehr. Die Werte sind die bisherigen deutschen BEFEHL-Texte (im Bauskript gegen die Sprachdatei
 * geprueft): fuer eine deutsche Oberflaeche bleibt die Vorlage bytegleich, bestehende Projekte behalten ihre
 * Ausgaenge. Unbekannte Aktion: die Aktion selbst.
 */
function rn_befehl_titel($aktion)
{
    $t = array(
        'acnow' => 'Vorklimatisierung starten',
        'acoff' => 'Vorklimatisierung beenden',
        'chargenow' => 'Sofort laden starten',
        'chargestop' => 'Laden anhalten',
        'cmon' => 'Ladeplan aktivieren',
        'cmoff' => 'Ladeplan abschalten (immer laden)',
        'abruf' => 'Daten sofort neu abrufen',
    );
    return isset($t[$aktion]) ? $t[$aktion] : (string) $aktion;
}

/* ---------------- Gleichwert-Unterdrueckung fuer Sollwerte (X-7) ----------------
 *
 * Welle-4-Bau, Entscheidung Nr. 19 des Hausherrn (01.10.2026): derselbe
 * Sollwert fuer dasselbe Fahrzeug innerhalb von 60 s geht nicht noch einmal
 * an Renault - der Endpunkt antwortet HTTP 200 mit UNVERAENDERT=1. Kein
 * zusaetzliches 429: ein anderer Wert geht sofort hinaus.
 *
 * Warum: Loxone sendet einen Ausgang bei jeder Aenderung, ein flatternder
 * Baustein denselben Befehl mehrmals. Jeder Befehl weckt das Fahrzeug ueber
 * die Renault-Cloud; bis 2.1.14 ging jeder hinaus.
 *
 * Gebremst werden nur Sollwerte: Klima an (samt Zieltemperatur) und aus,
 * Laden an und aus, Ladeplan an und aus. Nicht: der Datenabruf.
 *
 * Bauform BYD 0.9.22 / AudiConnect 0.9.23: Merker unter flock, geoeffnet mit
 * "e" (close-on-exec), faellt geschlossen aus (503). Vorgemerkt wird VOR dem
 * Senden; ist der Befehl danach nicht ausgefuehrt, verwirft der Endpunkt den
 * eigenen Eintrag wieder (rn_gleichwert_vergessen()).
 */
define('RN_GLEICHWERT_S', 60);

/** Pfad des Merkers (Datenordner; nur nach der Tokenpruefung benutzt). */
function rn_gleichwert_datei()
{
    return rn_paths()['datadir'] . '/gleichwert.json';
}

/**
 * Gruppe und Sollwert eines Befehls. null = nicht gebremst (Abruf,
 * Unbekanntes). Die Zieltemperatur der Vorklimatisierung kommt aus den
 * Einstellungen und gehoert zum Sollwert: 21 und 22 sind zwei Befehle.
 */
function rn_gleichwert_gruppe($aktion, $cfg)
{
    switch ((string) $aktion) {
        case 'acnow':
            $t = (is_array($cfg) && isset($cfg['ac_temp'])) ? trim((string) $cfg['ac_temp']) : '';
            return array('klima', 'an|' . $t);
        case 'acoff':
            return array('klima', 'aus');
        case 'chargenow':
            return array('laden', 'an');
        case 'chargestop':
            return array('laden', 'aus');
        case 'cmon':
            return array('ladeplan', 'an');
        case 'cmoff':
            return array('ladeplan', 'aus');
    }
    return null;
}

/** Schluessel des Merkers: Fahrgestellnummer (sonst Nummer) und Gruppe. */
function rn_gleichwert_schluessel($ziel, $nr, $gruppe)
{
    $fz = (is_array($ziel) && isset($ziel['vin']) && trim((string) $ziel['vin']) !== '')
        ? strtoupper(trim((string) $ziel['vin'])) : 'nr' . (int) $nr;
    return $fz . '|' . $gruppe;
}

/**
 * Das Urteil - rein, ohne Datei.
 * Rueckgabe: array('' | 'UNVERAENDERT', Sekunden seit dem gemerkten Befehl).
 */
function rn_gleichwert_urteil(array $m, $schluessel, $wert, $jetzt)
{
    if (!isset($m[$schluessel]) || !is_array($m[$schluessel])
        || !isset($m[$schluessel]['w'], $m[$schluessel]['t'])
        || !is_scalar($m[$schluessel]['w']) || !is_scalar($m[$schluessel]['t'])) {
        return array('', 0);
    }
    $seit = (int) $jetzt - (int) $m[$schluessel]['t'];
    // Uhr zurueckgesprungen: der Merker sagt nichts mehr.
    if ($seit < 0) {
        return array('', 0);
    }
    if ((string) $m[$schluessel]['w'] === (string) $wert && $seit < RN_GLEICHWERT_S) {
        return array('UNVERAENDERT', $seit);
    }
    return array('', $seit);
}

/**
 * Vor dem Senden. Rueckgabe: array(Urteil, Sekunden, Marke).
 *   'UNVERAENDERT' - derselbe Wert ging vor weniger als 60 s hinaus;
 *   'MERKER'       - der Merker laesst sich nicht oeffnen, sperren oder
 *                    schreiben: geschlossen ausfallen;
 *   ''             - senden; der Befehl ist dann unter der Marke vorgemerkt.
 */
function rn_gleichwert_pruefen($schluessel, $wert)
{
    $f = rn_gleichwert_datei();
    if (!is_dir(dirname($f))) {
        @mkdir(dirname($f), 0775, true);
    }
    $fh = @fopen($f, 'c+e');
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if (is_resource($fh)) {
            fclose($fh);
        }
        return array('MERKER', 0, '');
    }
    $m = json_decode((string) stream_get_contents($fh), true);
    if (!is_array($m)) {
        $m = array();     // unlesbar gilt als leer: im Zweifel senden
    }
    $jetzt = time();
    list($urteil, $seit) = rn_gleichwert_urteil($m, $schluessel, $wert, $jetzt);
    if ($urteil !== '') {
        flock($fh, LOCK_UN);
        fclose($fh);
        return array($urteil, $seit, '');
    }
    // Abgelaufene Eintraege fallen heraus - der Merker bleibt klein.
    foreach ($m as $k => $e) {
        if (!is_array($e) || !isset($e['t']) || !is_scalar($e['t'])
            || $jetzt - (int) $e['t'] >= RN_GLEICHWERT_S || (int) $e['t'] > $jetzt) {
            unset($m[$k]);
        }
    }
    $marke = bin2hex(random_bytes(6));
    $m[$schluessel] = array('w' => (string) $wert, 't' => $jetzt, 'm' => $marke);
    $js = json_encode($m);
    $ok = $js !== false && ftruncate($fh, 0) && rewind($fh)
          && fwrite($fh, $js) === strlen($js) && fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if (!$ok) {
        // Nicht vermerkt heisst: der naechste gleiche Befehl wuerde nicht
        // erkannt. Geschlossen ausfallen.
        return array('MERKER', 0, '');
    }
    return array('', 0, $marke);
}

/**
 * Den eigenen Eintrag verwerfen - der Befehl ist nicht ausgefuehrt worden.
 * $marke '' verwirft jeden Eintrag des Schluessels, sonst nur den eigenen.
 * Rueckgabe: true, wenn danach kein passender Eintrag mehr steht.
 */
function rn_gleichwert_vergessen($schluessel, $marke = '')
{
    $f = rn_gleichwert_datei();
    clearstatcache(true, $f);
    if (!is_file($f)) {
        return true;
    }
    $fh = @fopen($f, 'c+e');
    if ($fh === false || !@flock($fh, LOCK_EX)) {
        if (is_resource($fh)) {
            fclose($fh);
        }
        return false;
    }
    $m = json_decode((string) stream_get_contents($fh), true);
    $ok = true;
    if (is_array($m) && isset($m[$schluessel]) && ($marke === ''
            || (is_array($m[$schluessel]) && isset($m[$schluessel]['m'])
                && (string) $m[$schluessel]['m'] === (string) $marke))) {
        unset($m[$schluessel]);
        $js = json_encode($m);
        $ok = $js !== false && ftruncate($fh, 0) && rewind($fh)
              && fwrite($fh, $js) === strlen($js) && fflush($fh);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function rn_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel: Abschnittsname, Punkt, Name innerhalb des
 * Abschnitts. Ein Beispiel steht hier absichtlich nicht - eine Zeichenfolge
 * dieser Gestalt im Kommentar liest jeder Schluesselpruefer als benutzten
 * Schluessel und meldet ihn als fehlend.
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function rn_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/. Wurzel und Ordner kommen
        // aus rn_paths(): ohne Anlage (Archiv, Pruefordner) gibt es keinen
        // Pfad der Anlage. Bis 2.1.10 wurde dann
        // '/templates/plugins/htmlauth/lang' ab der Laufwerkswurzel
        // abgefragt (Pruefung-Renault-NG-2.1.11, Fall W6).
        $rn_p = rn_paths(false);
        $pfad = ($rn_p['home'] !== '')
            ? $rn_p['home'] . '/templates/plugins/' . $rn_p['plugin'] . '/lang' : '';
        if ($pfad === '' || !is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . rn_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

/* ==================================================================
 * Loxone-Vorlagen
 * ================================================================== */

/** Einheit und Grenzen der Zahlenthemen je Fahrzeug.
 *
 *  Bis 2.1.8 die Felder der Eingangs-Importdatei; seit 2.1.9 liefert die
 *  Tabelle die Spalte Einheit der Namenstabelle und den Abgleich im
 *  Reiter Test.
 *
 *  Aufbau: Thema, Sprachschluessel, Signed, MinVal, MaxVal, Einheit.
 *  Grenzen bewusst realistisch: Loxone zieht daraus die Reglergrenzen und
 *  die Plausibilitaetspruefung. phpCall stand bis 2.0.6 auf 2147483647,
 *  obwohl der Wert eine Uhrzeit als HHMM ist - also hoechstens 2359.
 */
function rn_vorlage_felder($zoeph)
{
    $f = array(
        array('BattSOC',        'THEMA.BATTSOC',        'false', '0',  '100',     '<v.0> %'),
        array('Range',          'THEMA.RANGE',          'false', '0',  '2000',    '<v.0> km'),
        array('ChargingStatus', 'THEMA.CHARGINGSTATUS', 'true',  '-2', '2',       '<v.0>'),
        array('CableStatus',    'THEMA.CABLESTATUS',    'true',  '-2', '2',       '<v.0>'),
        array('ChargingTime',   'THEMA.CHARGINGTIME',   'false', '0',  '2000',    '<v.0> min'),
        array('ChargingEffekt', 'THEMA.CHARGINGEFFEKT', 'false', '0',  '350',     '<v.1> kW'),
        array('Mileage',        'THEMA.MILEAGE',        'false', '0',  '1000000', '<v.0> km'),
        array('phpCall',        'THEMA.PHPCALL',        'false', '0',  '2359',    '<v.0>'),
        array('ok',             'THEMA.OK',             'false', '0',  '1',       '<v.0>'),
    );
    if ((string) $zoeph === '1') {
        $f[] = array('BatTemp', 'THEMA.BATTEMP', 'true', '-40', '80', '<v.1> °C');
    } else {
        $f[] = array('EnergieOnBoard', 'THEMA.ENERGIE', 'false', '0', '150', '<v.1> kWh');
    }
    $f[] = array('OutTemp', 'THEMA.OUTTEMP', 'true', '-40', '60', '<v.1> °C');
    $f[] = array('InTemp',  'THEMA.INTEMP',  'true', '-40', '80', '<v.1> °C');
    return $f;
}

/* Die Importdatei fuer die EINGAENGE (VirtualInHttp mit http://localhost
 * und Abfragezyklus 604800 s) ist mit 2.1.9 entfallen. Sie war ein
 * Kunstgriff, damit Loxone richtig benannte Eingaenge anlegt; die Werte
 * kamen trotzdem vom MQTT-Gateway. Hausregel (Regeln/07, "Gateway-Eingaenge
 * in Loxone Config"): Werte ueber das Gateway bekommen keine Importvorlage,
 * sondern die vollstaendige Namenstabelle im Reiter "Einbindung in Loxone".
 * Die Vorlage nannte nur die Zahlenthemen; die Tabelle nennt alle.
 * Bereits importierte Eingaenge bleiben in Loxone und bekommen weiter Werte,
 * denn das Gateway adressiert nach dem Namen. */

/** VQ-Vorlage (Steuerbefehle) nach dem Heimkino/Robonect-Muster:
 *  templateType 3, Aktionstoken eingesetzt. Befehle = rn_befehle(),
 *  je Fahrzeug einmal. */
function rn_vorlage_vo()
{
    $host  = rn_rechnername();
    $cfg   = rn_config_read();
    $autos = rn_fahrzeuge($cfg);
    $mehr  = count($autos) > 1;
    $crlf  = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="Renault steuern (LoxBerry-Plugin)" Comment="Steuerbefehle über das Plugin ' . htmlspecialchars(rn_paths()['plugin'], ENT_QUOTES | ENT_XML1, 'UTF-8') . ' - enthält das Aktionstoken. Loxone Config legt beim Import neu an und überschreibt nichts." Address="http://' . htmlspecialchars($host, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($autos as $rn_f) {
        foreach (rn_befehle() as $rn_a => $rn_angabe) {
            // html_entity_decode bleibt stehen, obwohl die Sprachdateien seit
            // 2.1.0 echte Zeichen tragen: schreibt jemand doch wieder eine
            // Entitaet hinein, stuende sie sonst doppelt maskiert im XML.
            $rn_klar  = html_entity_decode(rn_t($rn_angabe[0]), ENT_QUOTES, 'UTF-8');
            /* Welle Bild 17 (2.1.19): der Titel ist fest deutsch (rn_befehl_titel()), nur der
             * Kommentar unten bleibt uebersetzt. Bis 2.1.18 stand hier $rn_klar. */
            $rn_fest  = rn_befehl_titel($rn_a);
            $rn_titel = $mehr ? $rn_f['name'] . ': ' . $rn_fest : $rn_fest;
            /* Der Comment ist der ANZEIGENAME in Loxone Config (Regeln/07);
             * bis 2.1.6 stand dort "", und Config zeigte den Titel. Der
             * Vorsatz nennt das Fahrzeug, weil die Bausteinsuche des
             * Miniservers den Geraeteknoten nicht kennt. Uebersetzt wird
             * nichts Neues - die Beschriftung ist derselbe Text (seit 2.1.19
             * uebersetzt; der Titel ist fest deutsch, rn_befehl_titel()). */
            $rn_anzeige = $mehr
                ? 'Renault ' . $rn_f['name'] . ': ' . $rn_klar
                : 'Renault: ' . $rn_klar;
            $o .= "\t" . '<VirtualOutCmd Title="' . htmlspecialchars($rn_titel, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" Comment="' . htmlspecialchars($rn_anzeige, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" CmdOnMethod="GET" CmdOffMethod="GET" ';
            $o .= 'CmdOn="' . htmlspecialchars(rn_aktionsadresse($cfg, $rn_a, $rn_f['nr']), ENT_QUOTES | ENT_XML1, 'UTF-8') . '" ';
            $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
            $o .= 'Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
        }
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('VQ_renault_steuern.xml', $o);
}


/**
 * Die Fassung des LoxBerry-MQTT-Gateways - 0 heisst "nicht feststellbar".
 *
 * Sie steht als Mqtt.Gatewayversion in config/system/general.json (ab Werk
 * 1) und entscheidet, was der Anwender eintragen muss: unter V1 jedes Thema
 * von Hand auf der Abo-Seite, ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions.
 *
 * Die Datei wird hier eigens gelesen, obwohl andere Stellen sie auch lesen.
 * Das ist Absicht: dieser Baustein passt damit in jedes Plugin, unabhaengig
 * davon, wie es seinen MQTT-Zustand ermittelt - und er geht nicht kaputt,
 * wenn jemand jene Funktion umbaut.
 */
function rn_gateway_fassung()
{
    /* 2.1.22 (Fremdpruefung 2C): zuerst die Wurzel aus rn_paths(false) - dieselbe, aus der
     * rn_v2_haken_lage() subscriptions.json liest; sie gilt auch ohne LBHOMEDIR in der Umgebung.
     * Erst ohne sie (Archivmodus) wie bis 2.1.21 LBHOMEDIR. */
    $gj = (string) rn_paths(false)['general'];
    if ($gj === '') {
        $home = getenv('LBHOMEDIR');
        if (!$home && defined('LBHOMEDIR')) {
            $home = LBHOMEDIR;
        }
        if (!$home || !is_dir($home)) {
            return 0;
        }
        $gj = $home . '/config/system/general.json';
    }
    $d = @json_decode((string) @file_get_contents($gj), true);
    if (!is_array($d)) {
        return 0;
    }
    foreach (array('Mqtt', 'mqtt') as $ab) {
        if (!isset($d[$ab]) || !is_array($d[$ab])) {
            continue;
        }
        foreach (array('Gatewayversion', 'gatewayversion') as $sl) {
            if (isset($d[$ab][$sl]) && (string) $d[$ab][$sl] !== '') {
                return (int) $d[$ab][$sl];
            }
        }
    }
    return 0;
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis hierher stand an der Ausgabestelle unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1; ab V2 schickte
 * der Satz jeden Anwender zu einem Eingabeplatz, den es nicht mehr gibt.
 *
 * Drei Ausgaenge: ist die Fassung nicht feststellbar, werden BEIDE Faelle
 * genannt statt einer behauptet.
 */
function rn_abo_text()
{
    /* Seit 2.1.9 bringt das Plugin sein Abonnement selbst mit:
     * config/mqtt_subscriptions.cfg im Archiv landet bei jeder Installation
     * und jedem Update unter config/plugins/<ordner>/. Das Gateway liest
     * die Datei von dort (mqttgateway.pl: watch() je Plugin, erneutes
     * Einlesen bei jeder Aenderung an plugins_state.json - am Geraet
     * nachgelesen 17.09.2026). Liegt sie da, ist nichts einzutragen. */
    /* Seit 2.1.20 (Gruppe 40, Regeln/07 :38): Hausform ist das MQTT-Gateway V2
     * mit Hakenliste. Fassung 2 -> "Datenpunkte im MQTT-Gateway V2 anhaken"; das
     * Abo Renault/# (mitgeliefert) gilt nur noch als Rueckfall Gateway V1;
     * unbekannt -> beide Saetze. */
    $f = rn_gateway_fassung();
    if ($f >= 2) {
        return rn_t('TEXT.ABO_V2') . ' <span class="sm-mono">'
             . sprintf(rn_t('TEXT.ABO_GEMESSEN'), $f) . '</span>';
    }
    list(, $abo_ok) = rn_abo_datei();
    if ($abo_ok && $f === 1) {
        return rn_t('TEXT.ABO_MITGELIEFERT');
    }
    if ($abo_ok) {
        return rn_t('TEXT.ABO_V2') . ' ' . rn_t('TEXT.ABO_MITGELIEFERT');
    }
    if ($f <= 0) {
        return rn_t('TEXT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(rn_t('TEXT.ABO_GEMESSEN'), $f) . '</span>';
    return rn_t($f >= 2 ? 'TEXT.ABO_V2' : 'TEXT.H_ABO_PFLICHT') . $gemessen;
}


/** Das Thema der mitgelieferten Abo-Datei - an EINER Stelle, damit Datei,
 *  Oberflaeche und Pruefung nicht auseinanderlaufen. */
define('RN_ABO_THEMA', 'Renault/#');

/**
 * Die mitgelieferte Abo-Datei des Gateways: array(Pfad, traegt Renault/#).
 *
 * Gelesen wird die INSTALLIERTE Datei unter config/plugins/<ordner>/, nicht
 * die im Archiv - nur jene sieht das Gateway. Jede nichtleere Zeile ist dort
 * ein Thema; Kommentare kennt das Gateway nicht.
 */
function rn_abo_datei()
{
    $pfad = rn_paths(false)['konfdir'] . '/mqtt_subscriptions.cfg';
    $roh = is_readable($pfad) ? (string) @file_get_contents($pfad) : '';
    $zeilen = array_map('trim', preg_split('/\r?\n/', $roh));
    return array($pfad, in_array(RN_ABO_THEMA, $zeilen, true));
}

/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Der wichtigste Punkt: eine halb gueltige Datei ueberschreibt GAR NICHTS.
 * Wer eine Sicherung zurueckspielt, will entweder den ganzen Stand oder
 * gar keinen - eine zur Haelfte uebernommene Konfiguration ist schlimmer
 * als die alte, und man sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte,
 * Hinweise[] - seit 2.1.13, etwa "das geltende Token bleibt").
 */
function rn_sicherung_lesen($roh, &$namen = null)
{
    $mangel = array();
    $namen = array();     // X-3: die Namen der beanstandeten Einstellungen, nie Werte
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(rn_t('TEXT.SICH_KEIN_JSON')), 0);
    }

    /* Grundlage ist der AKTUELLE Stand, nicht die Werkseinstellung.
     *
     * Bis 2.1.5 stand hier $neu = rn_vorgaben(). Gemessen an einer
     * eingerichteten Anlage mit der Datei {"username":"neu@example.org"}:
     * "UEBERNOMMEN, 1 Werte" - und danach waren Aktionstoken, Kennwort,
     * Fahrgestellnummer, Fahrzeugname, die Freigabe zum Schalten und der
     * Abruftakt auf Werk. Ein in der Sicherung fehlender Schluessel behaelt
     * jetzt seinen jetzigen Wert; dass welche fehlen, wird gesagt. */
    $neu = rn_config_read();
    $bekannt = array_keys(rn_vorgaben());
    $anzahl = 0;
    $gesehen = array();
    $hinweise = array();

    foreach ($daten as $k => $w) {
        $k = (string) $k;
        /* Der lesbare Kopf wird UEBERGANGEN, nicht beanstandet. */
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        /* Nr. 36 b (seit 2.1.16): eine Sicherung dieses Plugins traegt nie ein Sprechtoken - traegt
         * die Datei eines (auch als Liste, Zahl oder null), stammt sie nicht aus "Einstellungen
         * sichern" und wird abgewiesen; die geltenden Sprechtoken bleiben. Leer heisst "keines". */
        if (in_array($k, rn_ansage_tokenfelder(), true)) {
            if ($w !== '') {
                $mangel[] = sprintf(rn_t('TEXT.SICH_TTS_TOKEN'), htmlspecialchars($k, ENT_QUOTES, 'UTF-8'));
                $namen[] = $k;
            }
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(rn_t('TEXT.SICH_FREMD'),
                                 htmlspecialchars($k, ENT_QUOTES, 'UTF-8'));
            $namen[] = $k;
            continue;
        }
        /* Jeder WERT wird geprueft, nicht nur der Schluessel.
         *
         * Bis 2.1.5 stand hier schlicht $neu[$k] = $w. Gemessen gingen damit
         * durch: country=NICHTEINLAND, zoeph=9, steuerung_ein=JA,
         * vin=KEINEVIN, ac_temp=999, cron_ncs=0, ein Fahrzeugname mit / # +
         * und ein Zeilenumbruch im Namen, dazu ein Feld statt eines Skalars
         * (aus dem im Quelltext die Zeichenkette 'Array' wurde). Dieselben
         * Werte weist der Speicher-Handler alle zurueck - die Sicherung war
         * der Weg um die eigene Formpruefung herum. */
        if (!rn_wert_taugt($w)) {
            $mangel[] = sprintf(rn_t('TEXT.SICH_WERT'),
                                 htmlspecialchars($k, ENT_QUOTES, 'UTF-8'));
            $namen[] = $k;
            continue;
        }
        $w = (string) $w;
        /* Ein leeres Aktionstoken heisst "kein Token gesichert" (Befund U4,
         * Regeln/05): das geltende Token bleibt, und die Meldung sagt es. Bis
         * 2.1.12 wurde es als leer geschrieben, und rn_config_read() "heilte"
         * die Datei im selben Aufruf aus der Zweitschrift - die Oberflaeche
         * meldete Erfolg fuer einen Stand, der schon wieder fort war.
         * Gewaehlt ist Behalten statt Abweisen: eine sonst gueltige Sicherung
         * (etwa aus einer Fassung, die das Token nicht sicherte) bleibt so
         * zurueckspielbar, und die Adressen in Loxone gelten weiter. */
        if ($k === 'aktionstoken' && $w === '') {
            $gesehen[$k] = true;
            $hinweise[] = rn_t('TEXT.SICH_TOKEN_BEHALTEN');
            continue;
        }
        if (!rn_wert_pruefen($k, $w)) {
            $mangel[] = sprintf(rn_t('TEXT.SICH_WERT'),
                                 htmlspecialchars($k, ENT_QUOTES, 'UTF-8'));
            $namen[] = $k;
            continue;
        }
        $neu[$k] = $w;
        $gesehen[$k] = true;
        $anzahl++;
    }

    /* "Fehlt" heisst: der Schluessel steht NICHT in der Datei (Befund U3).
     * Bis 2.1.12 zaehlte auch ein vorhandener, aber abgewiesener Schluessel
     * als fehlend, und die Meldung schob die Schuld auf die Datei; der
     * abgewiesene Wert steht schon als eigene Beanstandung da. */
    $in_datei = array();
    foreach (array_keys($daten) as $k) { $in_datei[(string) $k] = true; }
    $fehlend = array();
    foreach ($bekannt as $k) {
        /* Nr. 36 b: eine Sicherung von 2.1.15 oder frueher kennt die Sprachausgabe nicht; ihre
         * Schluessel behalten den Wert dieser Anlage ($neu geht vom Bestand aus). */
        if (in_array($k, rn_ansage_neue_schluessel(), true)) { continue; }
        /* Gruppe 40: eine Sicherung von 2.1.19 oder frueher kennt die Schluessel
         * von 2.1.20 nicht - sie behalten den geltenden Wert (endpunkt_ein bleibt,
         * was er ist). */
        if (in_array($k, rn_neue_schluessel_2120(), true)) { continue; }
        /* Welle Mehrfach-Ziele (2.1.20, Spaetliste): eine Sicherung bis 2.1.19 kennt tts_ziele nicht - die geltenden
         * weiteren Ziele bleiben ($neu geht vom Bestand aus), und der Hinweis nennt sie. Eine Sicherung MIT leerer
         * Liste heisst "keine weiteren Ziele" und wird so uebernommen. */
        if ($k === 'tts_ziele') {
            if (!isset($in_datei[$k]) && trim((string) $neu[$k]) !== '') {
                $hinweise[] = sprintf(rn_t('TEXT.SICH_SPAET'), 'tts.ziele');
            }
            continue;
        }
        if (!isset($in_datei[$k])) { $fehlend[] = $k; }
    }
    if (!isset($in_datei['tts_mode'])) {
        $hinweise[] = rn_t('TEXT.SICH_OHNE_ANSAGE');
    }
    /* Nr. 36 b: der Block der Sprachausgabe wie im Formular geprueft (Ausgabeart, Heimnetz). */
    if (!$mangel) {
        $rn_tg = '';
        if (ansage_wert_pruefen(rn_tts_aus($neu), $rn_tg, rn_ansage_modi()) === null) {
            $mangel[] = sprintf(rn_t('TEXT.SICH_TTS'), htmlspecialchars(
                ansage_kennung_text($rn_tg, rn_ansage_k()), ENT_QUOTES, 'UTF-8'));
            $namen[] = 'tts_mode';
        }
    }
    if ($fehlend) {
        foreach ($fehlend as $k) { $namen[] = $k; }
        $mangel[] = sprintf(rn_t('TEXT.SICH_FEHLT'), count($fehlend), count($bekannt),
            htmlspecialchars(implode(', ', array_slice($fehlend, 0, 8))
                . (count($fehlend) > 8 ? ' …' : ''), ENT_QUOTES, 'UTF-8'));
    }
    if ($anzahl === 0) {
        $mangel[] = rn_t('TEXT.SICH_LEER');
    }
    /* Alle Beanstandungen werden gesammelt; eine halb gueltige Datei aendert
     * GAR NICHTS. */
    return array($mangel ? null : $neu, $mangel, $anzahl, $hinweise);
}

/* ==================================================================
 * Sprachausgabe (Nr. 36 b, Stufe 2, seit 2.1.16)
 *
 * abruf.php erkennt nach jedem Abruf eines Fahrzeugs die Anlaesse
 * (rn_ansage_lauf()) und spricht ueber die gemeinsame Sprachausgabe
 * (sprachausgabe.php). Ab Werk ist die Ausgabe aus.
 * ================================================================== */

/** Erlaubte Ausgabearten: alle des Moduls ausser 'audioserver' (die Linie gibt keinen Text an Loxone).
 *  Seit 2.1.20 (2.5, T3-5) aus ansage_modi() abgeleitet statt abgeschrieben - bis 2.1.19 fehlten
 *  sonos4lox (und mit Modul 1.1.4 sonox), obwohl der Kommentar "alle des Moduls" sagte. sonox nur
 *  ab Modulfassung 1.1.4. */
function rn_ansage_modi()
{
    $aus = array();
    foreach (ansage_modi() as $m) {
        if ($m === 'audioserver') { continue; }
        if ($m === 'sonox' && (!defined('ANSAGE_FASSUNG') || version_compare(ANSAGE_FASSUNG, '1.1.4', '<'))) {
            continue;
        }
        $aus[] = $m;
    }
    return $aus;
}

/** Optionen fuer Formular-Baustein und Formular-Lesen. */
function rn_ansage_opt()
{
    return array('modi' => rn_ansage_modi());
}

/** Welle Mehrfach-Ziele (Sprachmodul 1.2.1): bietet diese Linie die Tabelle der weiteren Ausgabeziele an? Nur, wenn die
 *  geladene Abschrift sie kann - laden zwei Abschriften im selben Prozess, gilt die zuerst geladene. */
function rn_mit_zielen() {
    return version_compare(ANSAGE_FASSUNG, '1.2.0', '>=') && function_exists('ansage_ziele_html')
        && function_exists('ansage_ziele_pruefen') && function_exists('ansage_testknoepfe_html');
}

/** Welle Mehrfach-Ziele (Sprachmodul 1.2.1), X-2 mit flachen Schluesseln (name.N): die Felder der Tabelle heissen im
 *  Formular name[N] (N = 2..6, ansage_ziel_feldnamen()). rn_ziele_x2_name() macht daraus name.N (Beanstandung und
 *  Markierung), rn_ziele_x2_post() aus den gesammelten Eingaben wieder das POST der Tabelle (ziele_post). */
function rn_ziele_x2_name($n) {
    return is_string($n) ? (string) preg_replace('/^(tts_ziel_[a-z]+)\[([2-9])\]\z/', '$1.$2', $n) : $n;
}
function rn_ziele_x2_post(array $werte) {
    $p = array();
    foreach ($werte as $k => $v) {
        if (is_string($v) && preg_match('/^(tts_ziel_[a-z]+)\.([2-9])\z/', (string) $k, $m)) { $p[$m[1]][(int) $m[2]] = $v; }
    }
    return isset($p['tts_ziel_art']) ? $p : null;
}

/** Welle Mehrfach-Ziele (2.1.20): die Felder der Tabelle in der Einmalmeldung (X-2), flach name.N fuer N = 2..6 -
 *  nur, wenn die geladene Abschrift die Tabelle kann. */
function rn_ziele_x2_felder()
{
    $f = array();
    if (rn_mit_zielen()) {
        for ($i = 2; $i <= 6; $i++) {
            foreach (array('tts_ziel_art', 'tts_ziel_wo', 'tts_ziel_laut', 'tts_ziel_sprache', 'tts_ziel_ein',
                           'tts_ziel_loeschen') as $n) {
                $f[] = $n . '.' . $i;
            }
        }
    }
    return $f;
}

/** Welle Mehrfach-Ziele (2.1.20): eine Zeile ins Protokoll der Linie - die Stuecke rufen rn_log($text); renault_log()
 *  nimmt Stufe UND Text (wie gardena_log(), Los D). */
function rn_log($text)
{
    renault_log('INFO', $text);
}

/** Die Anlaesse: Name => array(Konfigschluessel, Beschriftung). */
function rn_ansage_anlaesse()
{
    return array(
        'laden_fertig'  => array('ansage_laden_fertig', 'TEXT.L_ANSAGE_LADEN_FERTIG'),
        'laden_abbruch' => array('ansage_laden_abbruch', 'TEXT.L_ANSAGE_LADEN_ABBRUCH'),
        'akku'          => array('ansage_akku', 'TEXT.L_ANSAGE_AKKU'),
        'ausfall'       => array('ansage_ausfall', 'TEXT.L_ANSAGE_AUSFALL'),
    );
}

/** Die Konfigschluessel der Sprechtoken - nie in Seite, Sicherung, Protokoll, X-2. */
function rn_ansage_tokenfelder()
{
    $aus = array();
    foreach (ansage_geheim() as $g) {
        $aus[] = 'tts_' . $g;
    }
    return $aus;
}

/** Die Schluessel, die mit 2.1.16 dazukamen - eine aeltere Sicherung kennt sie nicht. */
function rn_ansage_neue_schluessel()
{
    $s = array();
    foreach (rn_ansage_anlaesse() as $a) {
        $s[] = $a[0];
    }
    foreach (array_keys(ansage_vorgaben('aus')) as $k) {
        $s[] = 'tts_' . $k;
    }
    return $s;
}

/** Der Block tts aus der flachen Konfiguration; Zahlenfelder als Zahl (config.php kennt nur Zeichenketten). */
function rn_tts_aus($cfg)
{
    $t = array();
    foreach (ansage_vorgaben('aus') as $k => $w) {
        if (!is_array($cfg) || !array_key_exists('tts_' . $k, $cfg)) {
            continue;
        }
        $v = $cfg['tts_' . $k];
        if (is_int($w) && is_string($v) && preg_match('/^-?[0-9]{1,6}$/', $v)) {
            $v = (int) $v;
        }
        $t[$k] = $v;
    }
    /* Welle Mehrfach-Ziele (2.1.20): die weiteren Ziele stehen als JSON-Liste in tts_ziele (leer = keine). Eine
     * Liste, die kein JSON ist, bleibt draussen (das Zurueckspielen und das Speichern weisen sie ab). */
    $zr = (is_array($cfg) && isset($cfg['tts_ziele'])) ? trim((string) $cfg['tts_ziele']) : '';
    $zl = ($zr !== '') ? json_decode($zr, true) : null;
    if (is_array($zl) && $zl !== array()) {
        $t['ziele'] = $zl;
    }
    return $t;
}

/** Der Block tts flach fuer config.php. Seit 2.1.20 (Welle Mehrfach-Ziele) die Liste der weiteren Ziele als JSON in
 *  tts_ziele - leer, wenn der Block keine traegt (eine geleerte Tabelle loescht die gespeicherte Liste). */
function rn_tts_flach(array $tts)
{
    $aus = array();
    foreach (array_keys(ansage_vorgaben('aus')) as $k) {
        $aus['tts_' . $k] = isset($tts[$k]) && is_scalar($tts[$k]) ? (string) $tts[$k] : '';
    }
    $aus['tts_ziele'] = (isset($tts['ziele']) && is_array($tts['ziele']) && $tts['ziele'] !== array())
        ? (string) json_encode(array_values($tts['ziele']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    return $aus;
}

/**
 * Der Block tts, geprueft und vervollstaendigt (ab Werk 'aus'). Ein
 * unzulaessiger Block (von Hand geschrieben) gilt als "aus"; das eigene
 * Zurueckspielen nennt ihn (X-3).
 */
function rn_tts($cfg = null)
{
    if ($cfg === null) {
        $cfg = rn_config_read(false);
    }
    $t = rn_tts_aus($cfg);
    $g = '';
    if (ansage_wert_pruefen($t, $g, rn_ansage_modi()) === null) {
        $t = array();
    }
    list($t) = ansage_vervollstaendigen($t, 'aus');
    return $t;
}

/** Der Kontext des Moduls: Webport, Kopfzeile, Ordner fuer <art>_letzte.json, Texte. */
function rn_ansage_k()
{
    $p = rn_paths(false);
    return array(
        'port'   => ansage_webport((string) $p['general']),
        'kopf'   => array('User-Agent: LoxBerry Renault NG'),
        'ordner' => @is_dir($p['datadir']) ? $p['datadir'] : '',
        't'      => function ($s) { return rn_t($s); },
        /* K_TTS_EINTRAG: den Satz bringt das Modul seit 1.1.2 selbst mit; die Umlenkung auf
         * TEXT.SICH_TTS_EINTRAG ist seit 2.1.18 gestrichen (X-10). Ab Werk aus - kein 'werk'. */
    );
}

/** Der Satz zu einem Anlass, aus der Sprachdatei. Ladestand und Ladeziel nur, wenn sie bekannt sind. */
function rn_ansage_satz($anlass, $name, $soc = null, $grenze = null)
{
    if ($anlass === 'ausfall') {
        return sprintf(rn_t('TEXT.ANSAGE_S_AUSFALL'), $name);
    }
    if ($anlass === 'akku') {
        return sprintf(rn_t('TEXT.ANSAGE_S_AKKU'), $name, (int) $soc);
    }
    if ($anlass === 'laden_abbruch') {
        if ($soc === null || $grenze === null) {
            return sprintf(rn_t('TEXT.ANSAGE_S_LADEN_STOERUNG'), $name);
        }
        return sprintf(rn_t('TEXT.ANSAGE_S_LADEN_ABBRUCH'), $name, (int) $soc, (int) $grenze);
    }
    $s = sprintf(rn_t('TEXT.ANSAGE_S_LADEN_FERTIG'), $name);
    return $soc === null ? $s : $s . ' ' . sprintf(rn_t('TEXT.ANSAGE_S_LADESTAND'), (int) $soc);
}

/** Eine kleine JSON-Datei im Datenordner lesen (nur ein Feld zaehlt). */
function rn_ansage_json($datei)
{
    $d = is_file($datei) ? json_decode((string) @file_get_contents($datei), true) : null;
    return is_array($d) ? $d : array();
}

/**
 * Nach dem Abruf EINES Fahrzeugs: die Anlaesse erkennen und ansagen.
 *
 * $s ist der Zwischenspeicher des Laufs (Feld 10 Ladestatus, 12 Batteriestand),
 * $erfolg sagt, ob der Abruf gelang. Angesagt wird nur ein WECHSEL gegenueber
 * dem zuletzt gesehenen Stand (ansage_stand.json):
 *   laden_fertig   Ladestatus 1 -> nicht 1, Batteriestand nicht unter dem Ladeziel
 *   laden_abbruch  ... Ladestatus negativ (Stoerung) oder mehr als 1 Punkt unter soc_target
 *   akku           waehrend der Ladung erreicht der Batteriestand bl_schwelle
 *   ausfall        der dritte gescheiterte Abruf in Folge, nachdem einer gelungen war
 * Fehlt der Stand oder ist der letzte gelungene Abruf aelter als 6 h, setzt
 * der Lauf nur den Ausgangsstand. Rueckgabe: je Anlass array('anlass',
 * 'stufe', 'kurz') fuers Protokoll - nie Text, nie Token.
 */
function rn_ansage_lauf($cfg, $f, $erfolg, array $s, $jetzt = null)
{
    $jetzt = ($jetzt === null) ? time() : (int) $jetzt;
    $p = rn_paths(false);
    if ($p['datadir'] === '' || !@is_dir($p['datadir'])) {
        return array();
    }
    $datei = $p['datadir'] . '/ansage_stand.json';
    $stand = rn_ansage_json($datei);
    $nr = (string) (int) $f['nr'];
    $alt = (isset($stand[$nr]) && is_array($stand[$nr])) ? $stand[$nr] : null;
    $frisch = $alt !== null && isset($alt['ts_ok']) && is_int($alt['ts_ok'])
              && $jetzt - $alt['ts_ok'] >= 0 && $jetzt - $alt['ts_ok'] <= 6 * 3600;
    $anlaesse = array();
    if ($erfolg) {
        $st = (isset($s[10]) && is_numeric($s[10])) ? (float) $s[10] : null;
        $laedt = ($st === null) ? null : (abs($st - 1.0) < 0.001 ? 1 : 0);
        $soc = (isset($s[12]) && is_numeric($s[12])) ? (int) round((float) $s[12]) : null;
        if ($soc !== null && ($soc < 0 || $soc > 100)) {
            $soc = null;
        }
        $schwelle = (int) $cfg['bl_schwelle'];
        $akku = ($laedt === 1 && $soc !== null && $soc >= $schwelle) ? 1 : 0;
        $neu = array('ts_ok' => $jetzt,
                     'laedt' => ($laedt !== null) ? $laedt : ($alt !== null && isset($alt['laedt']) ? $alt['laedt'] : null),
                     'akku' => $akku, 'fehler' => 0, 'ok' => 1);
        if ($frisch) {
            if (isset($alt['laedt']) && $alt['laedt'] === 1 && $neu['laedt'] === 0) {
                $ziel = trim((string) $cfg['soc_target']);
                $unter = $ziel !== '' && $soc !== null && $soc < (int) $ziel - 1;
                if ($unter || ($st !== null && $st < 0)) {
                    $anlaesse[] = array('laden_abbruch', $unter ? $soc : null, $unter ? (int) $ziel : null);
                } else {
                    $anlaesse[] = array('laden_fertig', $soc, null);
                }
            }
            if ($akku === 1 && empty($alt['akku'])) {
                $anlaesse[] = array('akku', $soc, null);
            }
        }
    } else {
        $neu = ($alt !== null) ? $alt : array('ts_ok' => 0, 'laedt' => null, 'akku' => 0, 'fehler' => 0, 'ok' => 0);
        $vorher = isset($neu['fehler']) ? (int) $neu['fehler'] : 0;
        $neu['fehler'] = $vorher + 1;
        if (!empty($neu['ok']) && $vorher < 3 && $neu['fehler'] >= 3) {
            $anlaesse[] = array('ausfall', null, null);
        }
    }
    /* Der Stand steht VOR der Ansage fest: scheitert das Schreiben, wird nicht
     * gesprochen - sonst spraeche jeder folgende Lauf denselben Wechsel. */
    $stand[$nr] = $neu;
    $js = json_encode($stand);
    if (!is_string($js) || !rn_datei_schreiben($datei, $js, 0600)) {
        return $anlaesse ? array(array('anlass' => 'stand', 'stufe' => 'ERROR',
            'kurz' => 'ansage_stand.json nicht schreibbar - nichts angesagt')) : array();
    }
    $aus = array();
    if (!$anlaesse) {
        return $aus;
    }
    $tts = rn_tts($cfg);
    $alle = rn_ansage_anlaesse();
    foreach ($anlaesse as $a) {
        list($anlass, $soc, $grenze) = $a;
        if (!isset($cfg[$alle[$anlass][0]]) || (string) $cfg[$alle[$anlass][0]] !== 'Y'
            || $tts['mode'] === 'aus') {
            continue;
        }
        $sp_datei = $p['datadir'] . '/ansage_sperre.json';
        $sp = rn_ansage_json($sp_datei);
        $schl = $anlass . ':' . $nr;
        if (isset($sp[$schl]) && is_int($sp[$schl]) && $jetzt - $sp[$schl] >= 0 && $jetzt - $sp[$schl] < 3600) {
            $aus[] = array('anlass' => $anlass, 'stufe' => 'INFO', 'kurz' => 'unterdrueckt - hoechstens eine je 3600 s');
            continue;
        }
        foreach ($sp as $sk => $sv) {
            if (!is_int($sv) || $jetzt - $sv < 0 || $jetzt - $sv >= 3600) { unset($sp[$sk]); }
        }
        $sp[$schl] = $jetzt;
        rn_datei_schreiben($sp_datei, (string) json_encode($sp), 0600);
        $r = ansage_sprechen(rn_ansage_satz($anlass, (string) $f['name'], $soc, $grenze), $tts, rn_ansage_k());
        $aus[] = array('anlass' => $anlass, 'stufe' => $r['stand'] === 0 ? 'WARN' : 'INFO', 'kurz' => ansage_kurz($r));
    }
    return $aus;
}

/* ---------------- Beiseitegelegte Konfigurationen (Renault-b1) ----------------
 *
 * Bis 2.1.14 begann das Plugin nach einem Update mit unbrauchbarer config.php
 * und ohne brauchbare Zweitschrift mit Werkseinstellungen; die halb lesbare
 * Datei lag als <ordner>.config.php.kaputt.<zeit> neben dem Konfigordner
 * (preupgrade.sh, postupgrade.sh, Befund I4) oder als config.php.kaputt darin
 * (rn_konfig_heilen()), und die Oberflaeche sagte nichts davon. Was darin noch
 * lesbar ist, bietet der Reiter Einstellungen jetzt zum Neueintragen an.
 */

/** Die beiseitegelegten Dateien, die neueste zuerst. Liest, schreibt nichts. */
function rn_kaputt_dateien()
{
    $p = rn_paths(false);
    $kand = glob($p['konfdir'] . '.config.php.kaputt.*');
    $kand = is_array($kand) ? $kand : array();
    $kand[] = $p['konfdir'] . '/config.php.kaputt';
    $aus = array();
    foreach ($kand as $f) {
        if (is_string($f) && is_file($f) && is_readable($f)) { $aus[$f] = (int) @filemtime($f); }
    }
    uksort($aus, function ($a, $b) use ($aus) {
        return ($aus[$a] !== $aus[$b]) ? ($aus[$b] - $aus[$a]) : strcmp($b, $a);
    });
    return array_keys($aus);
}

/** Die Geheimnisse der Konfiguration: nie ins Formular, nur genannt. Das
 *  Aktionstoken zaehlt nicht mit - nach dem Neuanfang traegt die Anlage immer
 *  ein anderes, der Kasten stuende sonst fuer immer da. */
function rn_kaputt_geheim()
{
    return array('password', 'weather_api_key', 'abrp_token');
}

/**
 * Das Angebot aus der NEUESTEN beiseitegelegten Datei, oder null.
 * Rueckgabe array('datei' => Pfad, 'zeit' => Zeitstempel, 'felder' =>
 * array(Schluessel => Wert) - nicht geheim, zulaessig, vom jetzigen Stand
 * abweichend -, 'geheim' => Namen der Geheimnisse, die dort mit einem
 * anderen Wert stehen). null, wenn es nichts anzubieten gibt.
 */
function rn_kaputt_angebot($cfg)
{
    $dateien = rn_kaputt_dateien();
    if (!$dateien) { return null; }
    $f = $dateien[0];
    $heil = false;
    $w = rn_config_einlesen($f, $heil);
    if (!is_array($w)) { return null; }
    $felder = array();
    $geheim = array();
    foreach (rn_vorgaben() as $k => $v0) {
        if ($k === 'aktionstoken') { continue; }
        if (!array_key_exists($k, $w) || !rn_wert_taugt($w[$k])) { continue; }
        $v = (string) $w[$k];
        if ($v === '' || !rn_wert_pruefen($k, $v)) { continue; }
        $jetzt = (is_array($cfg) && isset($cfg[$k])) ? (string) $cfg[$k] : '';
        if ($v === $jetzt) { continue; }
        if (in_array($k, rn_kaputt_geheim(), true)) { $geheim[] = $k; }
        else { $felder[$k] = $v; }
    }
    if (!$felder && !$geheim) { return null; }
    return array('datei' => $f, 'zeit' => (int) @filemtime($f), 'felder' => $felder,
                 'geheim' => $geheim);
}

/** Ein Wert aus der neuesten beiseitegelegten Datei ('' = nicht lesbar). */
function rn_kaputt_wert($k)
{
    $dateien = rn_kaputt_dateien();
    if (!$dateien) { return ''; }
    $w = rn_config_einlesen($dateien[0]);
    if (!is_array($w) || !array_key_exists($k, $w) || !rn_wert_taugt($w[$k])) { return ''; }
    $v = (string) $w[$k];
    return rn_wert_pruefen($k, $v) ? $v : '';
}

/**
 * Das Feld, das "Einstellungen sichern" ausgibt: lesbarer Kopf und die VOLLE
 * Konfiguration samt Aktionstoken (Begruendung in index.php). An EINER Stelle
 * fuer den Knopf und fuer rn_rueckspiel_altwerte() (X-3) - sonst pruefte die
 * Warnung eine andere Datei als die gelieferte.
 */
function rn_sicherung_inhalt()
{
    $kopf = array(
        '_hinweis' => 'Sicherung der Einstellungen des LoxBerry-Plugins Renault NG. '
                    . 'Diese Datei enthaelt Ihr Renault-Kennwort und das Aktionstoken '
                    . 'im Klartext - behandeln Sie sie wie ein Passwort.',
        '_plugin'  => 'renault_ng',
        '_fassung' => rn_fassung(),
        '_stand'   => date('Y-m-d H:i:s'),
    );
    /* Nr. 36 b: die Sprechtoken der Sprachausgabe gehen nie in eine Sicherung (Entwurf 5) -
     * anders als Kennwort und Aktionstoken, die diese Linie bewusst mitsichert. */
    $rn_c = rn_config_read();
    foreach (rn_ansage_tokenfelder() as $rn_tf) {
        unset($rn_c[$rn_tf]);
    }
    return array_merge($kopf, $rn_c);
}

/**
 * X-3 (Welle-4-Bau): Welche Einstellungen wuerden beim Zurueckspielen der
 * EIGENEN Sicherung abgewiesen? Gebaut wird genau die Datei, die
 * "Einstellungen sichern" liefert, und durch dieselbe Pruefung geschickt wie
 * beim Zurueckspielen (rn_sicherung_lesen). Rueckgabe: Namen (nie Werte),
 * leer heisst "wuerde angenommen".
 *
 * Die Faelle, die hier anschlagen: ein Wert in config.php, den das Formular
 * nie gespeichert haette (von Hand geaendert, aus einer anderen Fassung, eine
 * Grenze, die sich geaendert hat). rn_config_read() setzt solche Werte beim
 * Lesen nicht zurueck - sie stehen in der Sicherung, und das Zurueckspielen
 * weist die ganze Datei ab.
 */
function rn_rueckspiel_altwerte()
{
    $js = json_encode(rn_sicherung_inhalt(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) {
        return array();     // Sichern meldet dann selbst SICH_SCHREIBFEHLER
    }
    $namen = array();
    list($neu) = rn_sicherung_lesen($js, $namen);
    $namen = array_values(array_unique($namen));
    sort($namen);
    if ($neu === null && !$namen) {
        $namen[] = rn_t('TEXT.SICH_GANZE_DATEI');
    }
    return $namen;
}

/**
 * Taugt der Wert ueberhaupt fuer eine Zeile dieser Konfiguration?
 *
 * config.php ist PHP-Quelltext, in den var_export() schreibt. Ein Feld, ein
 * Objekt oder ein Steuerzeichen hat dort nichts zu suchen; ein Zeilenumbruch
 * im Fahrzeugnamen wandert in den MQTT-Themenpfad und in die Loxone-Vorlage.
 */
function rn_wert_taugt($v)
{
    if (is_array($v) || is_object($v) || is_bool($v) || is_null($v)) {
        return false;
    }
    $s = (string) $v;
    if (strlen($s) > 4096) {
        return false;
    }
    return preg_match('/[\x00-\x1F\x7F]/', $s) !== 1;
}

/**
 * Ist der Wert fuer DIESEN Schluessel zulaessig?
 *
 * Dieselbe Positivliste, die der Speicher-Handler in index.php fuehrt -
 * Formular und Sicherung beantworten die Frage sonst verschieden. Wer hier
 * etwas aendert, aendert es dort mit; die Pruefzeile im Reiter Test haelt
 * beide gegeneinander.
 */
function rn_wert_pruefen($k, $w)
{
    $w = (string) $w;

    /* Gruppe 40: die Schluessel von 2.1.20 (rn_wert_pruefen_2120()). */
    $rn_neu = rn_wert_pruefen_2120($k, $w);
    if ($rn_neu !== null) {
        return $rn_neu;
    }

    /* Welle Mehrfach-Ziele (2.1.20): tts_ziele traegt die Liste der weiteren Ziele als JSON (leer = keine) - mit
     * Anfuehrungszeichen. Geprueft vom Modul mit der Modusliste dieser Linie (ansage_wert_pruefen, Schluessel ziele).
     * Ohne diesen Zweig wies die Zeichenpruefung darunter jede Liste ab - beim Speichern (Sicherheitsnetz im Handler)
     * und beim Zurueckspielen. Eine zuerst geladene aeltere Abschrift kennt die Liste nicht. */
    if ($k === 'tts_ziele') {
        if ($w === '') { return true; }
        $zl = json_decode($w, true);
        $tg = '';
        return is_array($zl) && rn_mit_zielen() && ansage_wert_pruefen(array('ziele' => $zl), $tg, rn_ansage_modi()) !== null;
    }
    /* Die Felder, aus denen das Formular Anfuehrungszeichen entfernt,
     * duerfen auch aus der Sicherung keine tragen. Das Kennwort ist
     * ausgenommen - es wird im Formular ebenfalls nicht beschnitten. */
    if ($k !== 'password' && preg_match('/["\']/', $w)) {
        return false;
    }

    if (preg_match('/^zoename[2-4]?$/', $k)) {
        /* "status" und "befehl" (ohne Ruecksicht auf Gross/Klein) fielen mit
         * Renault/status/befehle bzw. Renault/befehl/# zusammen (eigener Fund F7). */
        return strpos($w, '/') === false && strpos($w, '#') === false
            && strpos($w, '+') === false && !rn_name_reserviert($w);
    }
    if (preg_match('/^vin[2-4]?$/', $k)) {
        return $w === '' || preg_match('/^[A-HJ-NPR-Z0-9]{17}$/i', $w) === 1;
    }
    if (preg_match('/^zoeph[2-4]?$/', $k)) {
        return in_array($w, array('1', '2'), true);
    }
    switch ($k) {
        case 'country':
            return preg_match('/^[A-Z]{2}$/', $w) === 1;
        case 'ansage_laden_fertig':
        case 'ansage_laden_abbruch':
        case 'ansage_akku':
        case 'ansage_ausfall':
        case 'save_in_db':
        case 'steuerung_ein':
        case 'mail_bl':
        case 'cmon_bl':
        case 'mail_csf':
            return in_array($w, array('Y', 'N'), true);
        case 'cron_ncs':
        case 'cron_acs':
            return preg_match('/^[0-9]+$/', $w) === 1 && (int) $w >= 1 && (int) $w <= 60;
        case 'ac_temp':
            return preg_match('/^[0-9]+$/', $w) === 1 && (int) $w >= 16 && (int) $w <= 30;
        case 'bl_schwelle':
            return preg_match('/^[0-9]+$/', $w) === 1 && (int) $w >= 1 && (int) $w <= 99;
        case 'soc_min':
        case 'soc_target':
            return $w === '' || (preg_match('/^[0-9]+$/', $w) === 1
                && (int) $w >= 20 && (int) $w <= 100);
        case 'aktionstoken':
            /* Weit gefasst: zugelassen ist, was ohne Kodierung in eine
             * Adresse passt. Ein zu enges Muster verwirft ein von Hand
             * gesetztes oder aus einer aelteren Fassung uebernommenes
             * Token - und der Schaden ist derselbe wie bei einem verlorenen.
             * Die Laenge 0 ist zulaessig: "kein Token gesichert" ist kein
             * unzulaessiger Wert. */
            return preg_match('/^[A-Za-z0-9_.\-]{0,64}$/', $w) === 1;
        case 'exec_bl':
        case 'exec_csf':
            /* Dieselben Sonderzeichen, die rn_hook_ausfuehren() vor dem
             * Ausfuehren abweist - hier schon beim Hereinkommen. */
            return preg_match('/[;&|`$()<>\r\n]/', $w) !== 1;
    }
    return true;
}

/* ==================================================================
 * Gruppe 40 (2.1.20) - Bauvorschrift wz_bild/GRUPPE40_BAUVORSCHRIFT.md
 * und Musterprojekt/ENTSCHEIDUNGEN_GRUPPE40_RENAULT_NG.md (E-E1 B,
 * E-E2 und E-E3 abweichend: Ankunftsabfrage, Ladekosten und Aufraeumen
 * der Aufzeichnung schon in 2.1.20; E-E4 deinstallieren).
 *
 * Grundsatz wie bei MGiSmart 1.1.28: jede neue Entscheidung steht in einer
 * REINEN Funktion (ohne Schale, ohne Datei- und Netzzugriff) und laesst sich
 * unter PHP 7.4, 8.4 und 8.5 pruefen; die Huellen darum lesen, schreiben,
 * senden und rufen Programme.
 * ================================================================== */

if (!defined('RN_KONTO_NEU_S'))        { define('RN_KONTO_NEU_S', 3600); }
if (!defined('RN_HAUS_FRIST'))         { define('RN_HAUS_FRIST', 120); }
if (!defined('RN_HAUS_LESEFENSTER'))   { define('RN_HAUS_LESEFENSTER', 65); }
if (!defined('RN_HEIM_FRISCH'))        { define('RN_HEIM_FRISCH', 3600); }
if (!defined('RN_VORKLIMA_VERSUCHE'))  { define('RN_VORKLIMA_VERSUCHE', 3); }
if (!defined('RN_VORKLIMA_ABSTAND'))   { define('RN_VORKLIMA_ABSTAND', 120); }
if (!defined('RN_VORKLIMA_FRISCH'))    { define('RN_VORKLIMA_FRISCH', 3600); }
if (!defined('RN_AUSSEN_LOXONE_FRISCH')) { define('RN_AUSSEN_LOXONE_FRISCH', 1800); }
if (!defined('RN_ANKUNFT_ABSTAND'))    { define('RN_ANKUNFT_ABSTAND', 1800); }
if (!defined('RN_ANKUNFT_FZALTER'))    { define('RN_ANKUNFT_FZALTER', 300); }
if (!defined('RN_LADUNG_ABDECKUNG'))   { define('RN_LADUNG_ABDECKUNG', 0.9); }
if (!defined('RN_SIGNAL_MAX'))         { define('RN_SIGNAL_MAX', 4000); }
if (!defined('RN_BASIS')) {
    define('RN_BASIS', 'https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1');
}

/* ---------------- 1.0: die Nahtstelle zu Renault ----------------
 *
 * Bis 2.1.19 standen rn_get()/rn_post() und die beiden Gigya-Aufrufe in
 * abruf.php, jeder mit eigenem curl. Seit 2.1.20 gehen ALLE Aufrufe an Gigya
 * und Kamereon ueber diese eine Funktion. Eine Probe definiert sie VOR dem
 * Laden der Bibliothek und antwortet aus Tabellen; am Geraet gibt es keine
 * zweite Definition, und hier aendert sich nichts. Kein Umgebungsschalter,
 * keine Testadresse im Code (RN_BASIS bleibt fest).
 *
 * $rumpf: null (GET), eine Zeichenkette (JSON) oder ein Feld (Formularfelder,
 * wie bei Gigya bisher). $zeit: array(verbinden, gesamt) in Sekunden.
 * Rueckgabe array(HTTP-Code oder 0, Antwort oder false, curl-Fehlertext, Dauer s).
 */
if (!function_exists('rn_http_senden')) {
    function rn_http_senden($methode, $url, array $kopf, $rumpf, $zeit)
    {
        if (!function_exists('curl_init')) {
            return array(0, false, 'curl fehlt', 0.0);
        }
        $zeit = is_array($zeit) ? $zeit : array(10, (int) $zeit);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(1, (int) $zeit[0]));
        curl_setopt($ch, CURLOPT_TIMEOUT, max(1, (int) $zeit[1]));
        if ($kopf) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $kopf);
        }
        if (strtoupper((string) $methode) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rumpf === null ? '' : $rumpf);
        }
        $antwort = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $fehler = ($antwort === false) ? (string) curl_error($ch) : '';
        $dauer = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        if (PHP_VERSION_ID < 80000) { curl_close($ch); }
        return array($code, $antwort === false ? false : (string) $antwort, $fehler, $dauer);
    }
}

/** Zeitschranke der Renault-Aufrufe: array(verbinden, gesamt). Ab Werk 10/30 s; die Bruecke setzt 10/15 s (2.2). */
function rn_http_zeitschranke($neu = null)
{
    static $z = array(10, 30);
    if (is_array($neu) && count($neu) === 2) {
        $z = array(max(1, (int) $neu[0]), max(1, (int) $neu[1]));
    }
    return $z;
}

/** Die Schluessel aus api-keys.php: array(gigya, kamereon). Einmal je Prozess gelesen. */
function rn_api_schluessel()
{
    static $s = null;
    if ($s !== null) { return $s; }
    $lade = function ($datei) {
        $gigya_api = '';
        $kamereon_api = '';
        if (is_file($datei)) { include $datei; }
        return array((string) $gigya_api, (string) $kamereon_api);
    };
    $s = $lade(__DIR__ . '/api-keys.php');
    return $s;
}

/** Eine Schnittstellenantwort ins Protokoll (Form von renault_log_api(), ohne curl-Griff). */
function rn_antwort_protokoll($name, array $erg)
{
    if ($name === '') { return; }
    if (function_exists('renault_log_api')) {
        renault_log_api($name, $erg);
        return;
    }
    $code = (int) $erg[0];
    if ($erg[1] === false) {
        rn_melden('ERROR', $name . ': cURL-Fehler: ' . (isset($erg[2]) ? $erg[2] : ''));
    } elseif ($code >= 400) {
        rn_melden('ERROR', $name . ': HTTP ' . $code . ' - ' . substr(preg_replace('/\s+/', ' ', (string) $erg[1]), 0, 400));
    } else {
        rn_melden('INFO', $name . ': HTTP ' . $code);
    }
}

/** Ein GET an die Kamereon-Schnittstelle. Gibt array(httpcode, daten, roh). (bis 2.1.19 in abruf.php) */
function rn_get($url, $kamereon_api, $jwt, $name = '', $zeit = null)
{
    $erg = rn_http_senden('GET', $url, array(
        'apikey: ' . $kamereon_api,
        'x-gigya-id_token: ' . $jwt,
    ), null, $zeit === null ? rn_http_zeitschranke() : $zeit);
    $erg += array(0, false, '', 0.0);
    if ($name !== '') { rn_antwort_protokoll($name, $erg); }
    if ($erg[1] === false) { return array(0, null, ''); }
    return array((int) $erg[0], json_decode($erg[1], true), $erg[1]);
}

/** Ein POST an die Kamereon-Schnittstelle. Gibt array(httpcode, daten, roh). (bis 2.1.19 in abruf.php) */
function rn_post($url, $kamereon_api, $jwt, $json, $name = '', $zeit = null)
{
    $erg = rn_http_senden('POST', $url, array(
        'Content-type: application/vnd.api+json',
        'apikey: ' . $kamereon_api,
        'x-gigya-id_token: ' . $jwt,
    ), $json, $zeit === null ? rn_http_zeitschranke() : $zeit);
    $erg += array(0, false, '', 0.0);
    if ($name !== '') { rn_antwort_protokoll($name, $erg); }
    if ($erg[1] === false) { return array(0, null, ''); }
    return array((int) $erg[0], json_decode($erg[1], true), $erg[1]);
}

/** Der Pfad zum Fahrzeug in der kca-Welt. */
function rn_kca($konto, $vin, $rest, $land, $v = 'v1')
{
    return RN_BASIS . '/accounts/' . rawurlencode($konto) . '/kamereon/kca/car-adapter/'
         . $v . '/cars/' . rawurlencode($vin) . '/' . $rest . '?country=' . rawurlencode($land);
}

/** Der Pfad zum Fahrzeug in der kcm-Welt (neuere Plattformen). */
function rn_kcm($konto, $vin, $rest, $land)
{
    return RN_BASIS . '/accounts/' . rawurlencode($konto) . '/kamereon/kcm/v1/vehicles/'
         . rawurlencode($vin) . '/' . $rest . '?country=' . rawurlencode($land);
}

/* ---------------- V1: Kontowechsel und Abweisung ---------------- */

/** Haben sich die Zugangsdaten geaendert? Benutzer, Kennwort, Land - als Zeichenkette, ohne trim. Rein. */
function rn_konto_geaendert(array $alt, array $neu)
{
    foreach (array('username', 'password', 'country') as $k) {
        $a = isset($alt[$k]) ? (string) $alt[$k] : '';
        $b = isset($neu[$k]) ? (string) $neu[$k] : '';
        if ($a !== $b) { return true; }
    }
    return false;
}

/**
 * Die Anmeldung nach einer Abweisung (401/403) - rein.
 * Immer: Datum '0000', JWT ''. Das Konto (Feld 2) wird nur geleert, wenn die
 * letzte Kontoleerung mindestens RN_KONTO_NEU_S zurueckliegt (oder die Uhr
 * zurueckging); sonst bleibt es - kein persons-Abruf je 3-min-Lauf.
 * Rueckgabe array(neue Anmeldung (5 Felder), neue Leerungszeit).
 */
function rn_anmeldung_nach_abweisung(array $anm, $jetzt, $letzte_leerung)
{
    $anm = array_slice(array_pad(array_values($anm), 5, ''), 0, 5);
    $anm[0] = '0000';
    $anm[1] = '';
    $jetzt = (int) $jetzt;
    $letzte = (int) $letzte_leerung;
    $leerung = $letzte;
    if ($jetzt < $letzte || $jetzt - $letzte >= RN_KONTO_NEU_S) {
        $anm[2] = '';
        $leerung = $jetzt;
    }
    $anm[4] = (string) $leerung;
    return array($anm, $leerung);
}

/**
 * V2: welche session<n> verwirft das Speichern? Nummer n, wenn sich vin<n> oder
 * zoeph<n> geaendert hat (auch: Fahrzeug entfernt); alle eingerichteten, wenn
 * sich die Zugangsdaten geaendert haben. Rein. Rueckgabe sortierte Nummern.
 */
function rn_session_verwerfen(array $alt, array $neu)
{
    $aus = array();
    $konto = rn_konto_geaendert($alt, $neu);
    for ($i = 1; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $nr = ($i === 1) ? '' : (string) $i;
        $war = (isset($alt['vin' . $nr]) && trim((string) $alt['vin' . $nr]) !== '')
            || (isset($alt['zoename' . $nr]) && trim((string) $alt['zoename' . $nr]) !== '');
        $ist = (isset($neu['vin' . $nr]) && trim((string) $neu['vin' . $nr]) !== '')
            || (isset($neu['zoename' . $nr]) && trim((string) $neu['zoename' . $nr]) !== '');
        if ($i === 1) { $war = true; }
        $wert = function ($c, $k) { return isset($c[$k]) ? (string) $c[$k] : ''; };
        $geaendert = $wert($alt, 'vin' . $nr) !== $wert($neu, 'vin' . $nr)
            || $wert($alt, 'zoeph' . $nr) !== $wert($neu, 'zoeph' . $nr);
        if (($konto && ($war || $ist)) || ($war && $geaendert) || ($war && !$ist)) {
            $aus[] = $i;
        }
    }
    return $aus;
}

/* ---------------- 2.2: Anmeldung und Befehl als Bibliothek ---------------- */

/**
 * Anmeldung bei Gigya/Kamereon - der Block, der bis 2.1.19 in abruf.php stand
 * (:247-366), samt V1, ohne echo und ohne return aus der Datei. Die Aufrufer
 * schreiben ihre Zeilen selbst.
 * Rueckgabe array(ok, konto, jwt, grund, zeile, http, anmeldung):
 *   grund '' | LOGIN | NO_ACCOUNT; zeile 'LOGIN FAILED' | 'NO ACCOUNT' (wie bisher).
 */
function rn_anmelden(array $cfg, $zeit = null)
{
    list($gigya_api, $kamereon_api) = rn_api_schluessel();
    $zeit = ($zeit === null) ? rn_http_zeitschranke() : $zeit;
    $anm = rn_anmeldung_lesen();          // 0 Datum, 1 JWT, 2 Konto, 3 personId, 4 Kontoleerung
    $heute = date('md');
    $fehl = function ($zeile, $grund, $anm) {
        return array(false, '', '', $grund, $zeile, 502, $anm);
    };
    if ($anm[1] === '' || $anm[0] !== $heute) {
        $erg = rn_http_senden('POST', 'https://accounts.eu1.gigya.com/accounts.login', array(), array(
            'ApiKey'            => $gigya_api,
            'loginId'           => $cfg['username'],
            'password'          => $cfg['password'],
            'include'           => 'data',
            'sessionExpiration' => 60,
        ), $zeit);
        $erg += array(0, false, '', 0.0);
        rn_antwort_protokoll('Gigya Login (accounts.login)', $erg);
        $r = $erg[1];
        if ($r === false) {
            rn_melden('ERROR', 'Gigya Login: keine Antwort (Zeitueberschreitung oder Netzfehler).');
            return $fehl('LOGIN FAILED', 'LOGIN', $anm);
        }
        $d = json_decode($r, true);
        if (isset($d['errorCode']) && $d['errorCode'] != 0) {
            rn_melden('ERROR', 'Gigya Login fehlgeschlagen: errorCode=' . $d['errorCode']
                . ' (' . (isset($d['errorDetails']) ? $d['errorDetails'] : '') . ') - '
                . 'Benutzername und Passwort pruefen.');
        }
        $person = isset($d['data']['personId']) ? (string) $d['data']['personId'] : '';
        $oauth  = isset($d['sessionInfo']['cookieValue']) ? $d['sessionInfo']['cookieValue'] : '';
        if ($oauth === '') {
            rn_melden('ERROR', 'Gigya Login: kein Sitzungswert (cookieValue) erhalten - Anmeldung abgebrochen.');
            return $fehl('LOGIN FAILED', 'LOGIN', $anm);
        }
        $erg = rn_http_senden('POST', 'https://accounts.eu1.gigya.com/accounts.getJWT', array(), array(
            'login_token' => $oauth,
            'ApiKey'      => $gigya_api,
            'fields'      => 'data.personId,data.gigyaDataCenter',
            'expiration'  => 87000,
        ), $zeit);
        $erg += array(0, false, '', 0.0);
        rn_antwort_protokoll('Gigya JWT (accounts.getJWT)', $erg);
        $r = $erg[1];
        $d = ($r === false) ? null : json_decode($r, true);
        if (empty($d['id_token'])) {
            // Einen fehlgeschlagenen Login NICHT bis Mitternacht zwischenspeichern.
            rn_melden('ERROR', 'Gigya JWT: kein id_token erhalten. Antwort: '
                . substr(preg_replace('/\s+/', ' ', (string) $r), 0, 300));
            rn_anmeldung_schreiben(array('0000', '', $anm[2], $person, $anm[4]));
            return $fehl('LOGIN FAILED', 'LOGIN', $anm);
        }
        rn_melden('INFO', 'Gigya JWT Token erfolgreich erhalten.');
        /* V1 (Gruppe 40): das Konto bleibt nur, wenn die Person dieselbe ist.
         * Eine andere Person heisst ein anderes Konto - bis 2.1.19 blieb die
         * alte Konto-Kennung stehen (Pruefbericht T2-2). */
        $konto = ($person !== '' && $person === (string) $anm[3]) ? $anm[2] : '';
        if ($konto === '' && $anm[2] !== '') {
            rn_melden('INFO', 'Die Anmeldung gehoert zu einer anderen Person als die gemerkte - '
                . 'die Konto-Kennung wird neu ermittelt.');
        }
        $anm = array($heute, $d['id_token'], $konto, $person, $anm[4]);
        rn_anmeldung_schreiben($anm);
    }

    /* ---- Kamereon-Konto, falls noch keines bekannt ist ---- */
    if ($anm[2] === '') {
        if ($anm[3] === '') {
            rn_melden('ERROR', 'Kein Kamereon-Konto und keine personId bekannt - '
                . 'die Anmeldung wird beim naechsten Lauf wiederholt.');
            rn_anmeldung_schreiben(array('0000', '', '', '', $anm[4]));
            return $fehl('NO ACCOUNT', 'NO_ACCOUNT', $anm);
        }
        list($code, $d) = rn_get(RN_BASIS . '/persons/' . rawurlencode($anm[3])
            . '?country=' . rawurlencode($cfg['country']),
            $kamereon_api, $anm[1], 'Kamereon Konto (persons)', $zeit);
        $konten = isset($d['accounts']) ? $d['accounts'] : null;
        if (is_array($konten)) {
            foreach ($konten as $k) {
                rn_melden('INFO', 'Renault-Konto gefunden: ' . (isset($k['accountId']) ? $k['accountId'] : '?')
                    . ' (Typ: ' . (isset($k['accountType']) ? $k['accountType'] : '?')
                    . ', Status: ' . (isset($k['accountStatus']) ? $k['accountStatus'] : '?') . ')');
            }
            // Das MYRENAULT-Konto bevorzugen (siehe 2.1.19).
            foreach ($konten as $k) {
                if (isset($k['accountType']) && $k['accountType'] === 'MYRENAULT') {
                    $anm[2] = (string) $k['accountId']; break;
                }
            }
            if ($anm[2] === '' && isset($konten[0]['accountId'])) {
                $anm[2] = (string) $konten[0]['accountId'];
            }
        }
        if ($anm[2] === '') {
            rn_melden('ERROR', 'Keine Konto-Kennung erhalten (HTTP ' . $code . ').');
            return $fehl('NO ACCOUNT', 'NO_ACCOUNT', $anm);
        }
        rn_melden('INFO', 'Verwende Konto-Kennung: ' . $anm[2]);
        rn_anmeldung_schreiben($anm);
    }
    return array(true, $anm[2], $anm[1], '', '', 200, $anm);
}

/**
 * Einen Schaltbefehl an Renault senden - der switch, der bis 2.1.19 in
 * abruf.php stand (:414-477), samt den Rueckfallformen (acoff stop,
 * chargestop pause-resume). Rueckgabe der HTTP-Code (0 keine Antwort, -1
 * unbekannte Aktion).
 */
function rn_befehl_senden($aktion, array $ziel, array $cfg, $konto, $jwt, $zeit = null)
{
    list(, $kamereon_api) = rn_api_schluessel();
    $vin  = (string) $ziel['vin'];
    $land = (string) $cfg['country'];
    $temp = (int) $cfg['ac_temp'];
    switch ((string) $aktion) {
        case 'acnow':
            list($code) = rn_post(rn_kca($konto, $vin, 'actions/hvac-start', $land), $kamereon_api, $jwt,
                '{"data":{"type":"HvacStart","attributes":{"action":"start","targetTemperature":"' . $temp . '"}}}',
                'Vorklimatisierung starten (hvac-start)', $zeit);
            return $code;
        case 'acoff':
            /* "cancel" fuer die meisten Fahrzeuge; einzelne (A290, R5, Spring)
             * kennen "stop" - bei einem Fehlercode wird die zweite Form versucht. */
            list($code) = rn_post(rn_kca($konto, $vin, 'actions/hvac-start', $land), $kamereon_api, $jwt,
                '{"data":{"type":"HvacStart","attributes":{"action":"cancel"}}}',
                'Vorklimatisierung beenden (hvac-start cancel)', $zeit);
            if ($code >= 400) {
                list($code) = rn_post(rn_kca($konto, $vin, 'actions/hvac-start', $land), $kamereon_api, $jwt,
                    '{"data":{"type":"HvacStart","attributes":{"action":"stop"}}}',
                    'Vorklimatisierung beenden (hvac-start stop)', $zeit);
            }
            return $code;
        case 'chargenow':
            list($code) = rn_post(rn_kca($konto, $vin, 'actions/charging-start', $land), $kamereon_api, $jwt,
                '{"data":{"type":"ChargingStart","attributes":{"action":"start"}}}',
                'Sofort laden (charging-start)', $zeit);
            return $code;
        case 'chargestop':
            /* Erst der Weg von Zoe PH1 und Twingo; Zoe PH2 und Dacia Spring halten
             * das Laden ueber kcm/charge/pause-resume an. */
            list($code) = rn_post(rn_kca($konto, $vin, 'actions/charging-start', $land), $kamereon_api, $jwt,
                '{"data":{"type":"ChargingStart","attributes":{"action":"stop"}}}',
                'Laden anhalten (charging-start stop)', $zeit);
            if ($code >= 400) {
                list($code) = rn_post(rn_kcm($konto, $vin, 'charge/pause-resume', $land), $kamereon_api, $jwt,
                    '{"data":{"type":"ChargePauseResume","attributes":{"action":"pause"}}}',
                    'Laden anhalten (charge/pause-resume)', $zeit);
            }
            return $code;
        case 'cmon':
            list($code) = rn_post(rn_kca($konto, $vin, 'actions/charge-mode', $land), $kamereon_api, $jwt,
                '{"data":{"type":"ChargeMode","attributes":{"action":"schedule_mode"}}}',
                'Ladeplan aktivieren (charge-mode)', $zeit);
            return $code;
        case 'cmoff':
            list($code) = rn_post(rn_kca($konto, $vin, 'actions/charge-mode', $land), $kamereon_api, $jwt,
                '{"data":{"type":"ChargeMode","attributes":{"action":"always_charging"}}}',
                'Ladeplan abschalten (charge-mode)', $zeit);
            return $code;
    }
    return -1;
}

/**
 * Einen Befehl annehmen - DIESELBE Funktion fuer den Endpunkt ('http'), die
 * Befehlsbruecke ('mqtt') und die Vorklimatisierung ('automatik'). Damit steht
 * X-7 an EINER Stelle fuer alle Wege (eigener Fund F11: bis 2.1.19 nur im
 * Endpunkt). Reihenfolge der Pruefungen wie der Endpunkt bisher:
 * Aktion -> Steuerung -> Fahrzeug -> Zugangsdaten/VIN -> ac_temp -> X-7 ->
 * Anmeldung -> Senden -> bei Fehlschlag X-7 vergessen.
 * Rueckgabe array(ok, code, http, zeile): ok 1 = Renault hat angenommen
 * (HTTP < 400) oder X-7 UNVERAENDERT; zeile ist die Antwortzeile von 2.1.19.
 */
function rn_befehl_annehmen($aktion, $nr, $quelle, $cfg = null)
{
    $quelle = in_array($quelle, array('http', 'mqtt', 'automatik'), true) ? $quelle : 'http';
    if (!is_array($cfg)) { $cfg = rn_config_read(false); }
    $aktion = (string) $aktion;
    $nr = (int) $nr;
    $wo = ($quelle === 'http') ? '' : ' (' . ($quelle === 'mqtt' ? 'ueber MQTT' : 'Vorklimatisierung') . ')';
    $befehle = rn_befehle();
    if ($aktion === 'abruf' || !isset($befehle[$aktion])) {
        return array(0, 'UNBEKANNT', 400, 'UNKNOWN ACTION');
    }
    if (rn_befehl_schaltet($aktion) && (string) $cfg['steuerung_ein'] !== 'Y') {
        rn_melden('WARN', 'Befehl ' . $aktion . $wo . ' abgewiesen: die Steuerung ist in den '
            . 'Einstellungen ausgeschaltet (Vorgabe).');
        return array(0, 'STEUERUNG_AUS', 403, 'STEUERUNG AUS');
    }
    $ziel = rn_fahrzeug($nr, $cfg);
    if (!$ziel || ($nr > 1 && trim((string) $cfg['vin' . $nr]) === '' && trim((string) $cfg['zoename' . $nr]) === '')) {
        rn_melden('ERROR', 'Befehl ' . $aktion . $wo . ': Fahrzeug ' . $nr . ' ist nicht eingerichtet.');
        return array(0, 'FAHRZEUG_FEHLT', 400, 'UNKNOWN VEHICLE');
    }
    if ((string) $cfg['username'] === '' || (string) $cfg['password'] === '' || (string) $ziel['vin'] === '') {
        rn_melden('ERROR', 'Befehl ' . $aktion . $wo . ' abgewiesen: Zugangsdaten oder Fahrgestellnummer '
            . 'unvollstaendig. Es wird nichts an Renault gesendet.');
        return array(0, 'NICHT_EINGERICHTET', 503, 'NO CREDENTIALS');
    }
    if (preg_match('/^[0-9]+$/', (string) $cfg['ac_temp']) !== 1
        || (int) $cfg['ac_temp'] < 16 || (int) $cfg['ac_temp'] > 30) {
        rn_melden('ERROR', 'Die Zieltemperatur der Vorklimatisierung ist unzulaessig (ac_temp = "'
            . $cfg['ac_temp'] . '", erlaubt sind 16 bis 30 Grad). Es wurde NICHTS an das Fahrzeug gesendet.');
        return array(0, 'AC_TEMP', 409, 'AC TEMP UNZULAESSIG');
    }
    $gw = rn_gleichwert_gruppe($aktion, $cfg);
    $schl = '';
    $marke = '';
    if ($gw !== null) {
        $schl = rn_gleichwert_schluessel($ziel, $nr, $gw[0]);
        list($urteil, $seit, $marke) = rn_gleichwert_pruefen($schl, $gw[1]);
        if ($urteil === 'MERKER') {
            rn_melden('ERROR', 'Befehl ' . $aktion . $wo . ' fuer Fahrzeug ' . $nr . ' abgewiesen - der Merker '
                . 'der Gleichwert-Unterdrueckung laesst sich nicht fuehren (' . rn_gleichwert_datei() . ')');
            return array(0, 'BREMSE_MERKER', 503, strtoupper($aktion) . ';OK=0;GRUND=BREMSE_MERKER');
        }
        if ($urteil === 'UNVERAENDERT') {
            rn_melden('INFO', 'Befehl ' . $aktion . $wo . ' fuer Fahrzeug ' . $nr . ': derselbe Befehl ging vor '
                . (int) $seit . ' s hinaus - nichts gesendet (gleiche Sollwerte innerhalb von '
                . RN_GLEICHWERT_S . ' s gehen nur einmal an Renault)');
            return array(1, 'UNVERAENDERT', 200, strtoupper($aktion) . ';OK=1;UNVERAENDERT=1;SEIT_S=' . (int) $seit);
        }
    }
    $anm = rn_anmelden($cfg);
    if (!$anm[0]) {
        if ($marke !== '') { rn_gleichwert_vergessen($schl, $marke); }
        return array(0, 'ANMELDUNG', 502, $anm[4]);
    }
    $code = rn_befehl_senden($aktion, $ziel, $cfg, $anm[1], $anm[2]);
    $ok = ($code > 0 && $code < 400);
    rn_melden($ok ? 'INFO' : 'ERROR', 'Befehl ' . $aktion . $wo . ' an ' . $ziel['name'] . ': HTTP ' . $code
        . ($ok ? '' : ' - der Befehl wurde NICHT ausgefuehrt.'));
    if (!$ok && $marke !== '') { rn_gleichwert_vergessen($schl, $marke); }
    return array($ok ? 1 : 0, $ok ? 'OK' : 'RENAULT_' . (int) $code, $ok ? 200 : 502,
                 strtoupper($aktion) . ';OK=' . ($ok ? '1' : '0') . ';HTTP=' . (int) $code);
}

/** Quittung fuer die Bruecke: array(true, '') bei ok 1, sonst array(false, Code). Rein. */
function rn_befehl_quittung($erg)
{
    if (!is_array($erg) || !isset($erg[0])) {
        return array(false, 'UNBEKANNT');
    }
    if ((int) $erg[0] === 1) {
        return array(true, '');
    }
    return array(false, isset($erg[1]) && (string) $erg[1] !== '' ? (string) $erg[1] : 'FEHLER');
}

/* ---------------- 2.3: die Abrufsperre ---------------- */

/** Die Sperre, die cron.03min/cron.10min und der Endpunkt teilen (eigener Fund F9). */
function rn_abruf_sperre()
{
    return rn_paths()['logdir'] . '/abruf.lock';
}

/**
 * Ist die Abrufsperre frei? Geoeffnet mit 'ce' (close-on-exec: exec_bl und
 * exec_csf erben den Griff nicht). Rueckgabe der Griff (gehalten) oder null.
 */
function rn_abruf_sperre_nehmen()
{
    $d = dirname(rn_abruf_sperre());
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $fh = @fopen(rn_abruf_sperre(), 'ce');
    if ($fh === false) { return null; }
    if (!@flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return null;
    }
    return $fh;
}

/**
 * Einen abgekoppelten Abruf nur dieses Fahrzeugs anstossen (Bruecke
 * "auffrischen", Vorklimatisierung Versuch 2, Ankunft). Nur bei freier
 * Abrufsperre; die Schale schliesst jede Deskriptor-Nummer > 2, flock -o gibt
 * den Griff nicht an php weiter. Rueckgabe gestartet | belegt | kein_start.
 */
function rn_abruf_anstossen($nr)
{
    $fh = rn_abruf_sperre_nehmen();
    if ($fh === null) {
        rn_melden('INFO', 'ABRUF=SKIP - der laufende Abruf holt die Werte (Fahrzeug ' . (int) $nr . ').');
        return 'belegt';
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY)) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'cd ' . escapeshellarg(__DIR__) . ' && exec flock -n -o ' . escapeshellarg(rn_abruf_sperre()) . ' '
            . escapeshellarg($php) . ' abruf.php abruf ' . (int) $nr;
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/* ---------------- 2.1: MQTT-Gateway V2, -1-Regel, reservierte Namen ---------------- */

/** Der Name, den das MQTT-Gateway vergibt: '/', '%' und Leerzeichen werden '_' (Regeln/07 :69). Rein. */
function rn_gateway_name($thema)
{
    return str_replace(array('/', '%', ' '), '_', (string) $thema);
}

/** Zahlthemen je Fahrzeug, unabhaengig von der Generation (Bauvorschrift 2.1). */
function rn_v2_zahlthemen()
{
    return array('BattSOC', 'Range', 'ChargingStatus', 'CableStatus', 'ChargingTime', 'ChargingEffekt',
        'Mileage', 'HvAcStatusBin', 'InTemp', 'OutTemp', 'RenaultPHMode', 'phpCall', 'LastDataRetrieval',
        'chargeStartBatteryLevel(Prozent)', 'chargeEndBatteryLevel(Prozent)', 'chargeDuration(min)',
        'chargePowerAverage(kW)', 'chargeEnergyRecovered(kWh)', 'chargeStartInstantaneousPower',
        'Zuhause', 'Entfernung', 'Vorklima', 'LadungKosten', 'LadungEnergie', 'LadungPreis');
}

/** Textthemen, die das Musterprojekt ueber Virtuelle Texteingaenge liest (baustein_listen.txt :3255/:3291/:3294/:3305). */
function rn_v2_textthemen()
{
    return array('ChargeMode', 'GPSTime', 'Name', 'chargeEndStatus');
}

/**
 * Namenstabelle und V2-Hakenliste - EINE Quelle fuer Reiter MQTT/Einbindung,
 * Selbsttest, Healthcheck und Messskript. Je eingerichtetes Fahrzeug die
 * Zahlthemen (Generation 1 BatTemp, Generation 2 GPS und EnergieOnBoard), die
 * vier Textthemen, dazu ok und status/zaehler (Noncached); global
 * Renault/status/befehle (Noncached). HvAcStatus (Text) und status/ts stehen
 * nicht darin (MG-Abweichung 10).
 * Rueckgabe Liste von array(thema, name, noncached, art zahl|text, bez).
 */
function rn_v2_haken($cfg = null)
{
    if ($cfg === null) { $cfg = rn_config_read(false); }
    $themen = array();
    $aus = array();
    foreach (rn_fahrzeuge($cfg) as $f) {
        $alle = rn_themen($f['zoeph']);
        $zahl = rn_v2_zahlthemen();
        if ((string) $f['zoeph'] === '1') {
            $zahl[] = 'BatTemp';
        } else {
            $zahl[] = 'GPS-Latitude';
            $zahl[] = 'GPS-Longitude';
            $zahl[] = 'EnergieOnBoard';
        }
        foreach ($alle as $t => $bez) {
            if (in_array($t, $zahl, true)) {
                $art = 'zahl';
            } elseif (in_array($t, rn_v2_textthemen(), true)) {
                $art = 'text';
            } elseif ($t === 'ok' || $t === 'status/zaehler') {
                $art = 'zahl';
            } else {
                continue;               // HvAcStatus, status/ts
            }
            $voll = 'Renault/' . $f['name'] . '/' . $t;
            if (isset($themen[$voll])) { continue; }
            $themen[$voll] = true;
            $aus[] = array('thema' => $voll, 'name' => rn_gateway_name($voll),
                           'noncached' => ($t === 'ok' || $t === 'status/zaehler'), 'art' => $art, 'bez' => $bez);
        }
    }
    $aus[] = array('thema' => 'Renault/status/befehle', 'name' => rn_gateway_name('Renault/status/befehle'),
                   'noncached' => true, 'art' => 'zahl', 'bez' => 'THEMA.STATUS_BEFEHLE');
    return $aus;
}

/** Die Liste der angehakten Datenpunkte aus subscriptions.json (Rein): Id => noncached. null = unlesbar. */
function rn_v2_abos($daten)
{
    if (!is_array($daten)) { return null; }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($daten[$s]) && is_array($daten[$s])) { $liste = $daten[$s]; break; }
    }
    $abo = array();
    foreach ($liste as $e) {
        if (!is_array($e)) { continue; }
        $id = '';
        foreach (array('Id', 'id', 'Topic', 'topic') as $k) {
            if (isset($e[$k]) && is_string($e[$k])) { $id = $e[$k]; break; }
        }
        if ($id === '') { continue; }
        $nc = false;
        foreach (array('Noncached', 'noncached') as $k) {
            if (isset($e[$k])) {
                $nc = ($e[$k] === true || in_array(strtolower(trim((string) $e[$k])), array('1', 'true', 'yes', 'on'), true));
            }
        }
        $abo[$id] = $nc;
    }
    return $abo;
}

/** Soll gegen Ist (Rein): array(1|0, fehlt[], noncached_falsch[], da, soll). */
function rn_v2_haken_abgleich(array $soll, array $abo)
{
    $fehlt = array();
    $nc = array();
    $da = 0;
    foreach ($soll as $e) {
        if (!array_key_exists($e['thema'], $abo)) {
            $fehlt[] = $e['name'];
            continue;
        }
        $da++;
        if ((bool) $abo[$e['thema']] !== (bool) $e['noncached']) {
            $nc[] = $e['name'] . ($e['noncached'] ? ' (Noncached fehlt)' : ' (Noncached zu viel)');
        }
    }
    return array(($fehlt || $nc) ? 0 : 1, $fehlt, $nc, $da, count($soll));
}

/**
 * Die Zeile "Gateway V2: sind alle Datenpunkte angehakt?" - rein bis auf die
 * Argumente. $fassung aus rn_gateway_fassung(), $abo aus rn_v2_abos() (null =
 * unlesbar), $eingerichtet = Zugangsdaten da.
 * Rueckgabe array(lage, Text): 1 gruen, 0 rot, 'grau' (noch nicht
 * eingerichtet), 'info' (trifft nicht zu - Gateway V1), null (nicht feststellbar).
 */
function rn_v2_lage_urteil(array $soll, $fassung, $abo, $eingerichtet)
{
    if ((int) $fassung === 1) {
        return array('info', rn_t('PRUEFTEXT.V2_V1'));
    }
    if (!$eingerichtet && ((int) $fassung <= 0 || $abo === null)) {
        /* Ohne Zugangsdaten kommt kein Wert - ohne Wert gibt es nichts anzuhaken. Grau, nicht rot. */
        return array('grau', rn_t('PRUEFTEXT.V2_NICHT_EINGERICHTET'));
    }
    if ((int) $fassung <= 0 || $abo === null) {
        return array(null, rn_t('PRUEFTEXT.V2_UNKLAR'));
    }
    list($ok, $fehlt, $nc, $da, $zahl) = rn_v2_haken_abgleich($soll, $abo);
    $txt = sprintf(rn_t('PRUEFTEXT.V2_ZAHL'), $da, $zahl);
    if ($fehlt) {
        $txt .= ' ' . sprintf(rn_t('PRUEFTEXT.V2_FEHLT'), implode(', ', array_slice($fehlt, 0, 12))
            . (count($fehlt) > 12 ? ' …' : ''));
    }
    if ($nc) {
        $txt .= ' ' . sprintf(rn_t('PRUEFTEXT.V2_NC'), implode(', ', $nc));
    }
    if (!$eingerichtet && !$ok) {
        /* Ohne Zugangsdaten kommt kein Wert - ohne Wert gibt es nichts
         * anzuhaken. Grau, nicht rot: sonst waere jede Neuinstallation rot. */
        return array('grau', rn_t('PRUEFTEXT.V2_NICHT_EINGERICHTET') . ' ' . $txt);
    }
    return array($ok ? 1 : 0, $txt);
}

/** Dieselbe Zeile mit den Dateien der Anlage (liest nur). */
function rn_v2_haken_lage($cfg = null)
{
    if ($cfg === null) { $cfg = rn_config_read(false); }
    $p = rn_paths(false);
    $abo = null;
    $sj = ($p['home'] !== '') ? $p['home'] . '/config/system/subscriptions.json' : '';
    if ($sj !== '' && is_file($sj)) {
        $abo = rn_v2_abos(json_decode((string) @file_get_contents($sj), true));
    }
    return rn_v2_lage_urteil(rn_v2_haken($cfg), rn_gateway_fassung(), $abo,
        (string) $cfg['username'] !== '' && (string) $cfg['password'] !== '');
}

/**
 * Der Fehlwert eines Themas ohne Wert - EINE Tabelle (Regeln/07 :388, Hausform
 * "-1-Regel"). Zahlthemen -1; Temperaturen -99; GPS -999; Zustaende (retained)
 * "-" wie bisher. ChargingStatus ohne Wert geht NICHT hinaus: bei Renault
 * heisst -1 "Ladefehler", und das Musterprojekt wertet "unter 0" als Stoerung
 * (baustein_listen.txt :3216ff. #6/#29) - ein fehlender Wert loeste sonst einen
 * Fehlalarm aus (Regeln/07 :283, Abweichung mit Grund). Textthemen ohne Wert
 * gehen wie bisher nicht hinaus. Rueckgabe Zeichenkette oder null. Rein.
 */
function rn_fehlwert($thema)
{
    $t = (string) $thema;
    if ($t === 'ChargingStatus') { return null; }
    if (in_array($t, array('InTemp', 'OutTemp', 'BatTemp'), true)) { return '-99'; }
    if ($t === 'GPS-Latitude' || $t === 'GPS-Longitude') { return '-999'; }
    if (rn_thema_retained($t)) { return '-'; }
    if (in_array($t, rn_v2_zahlthemen(), true) || $t === 'EnergieOnBoard') { return '-1'; }
    return null;
}

/** Reservierte Fahrzeugnamen (eigener Fund F7): fielen mit Renault/status/befehle bzw. Renault/befehl/# zusammen. Rein. */
function rn_name_reserviert($name)
{
    return in_array(strtolower(trim((string) $name)), array('status', 'befehl'), true);
}

/* ---------------- 2.2: Befehlsbruecke 1.0.2 ---------------- */

/** Die Bruecke als Bibliothek laden (BB_NUR_LADEN). Rueckgabe true, wenn bb_waechter() danach da ist. */
function rn_bruecke_laden()
{
    if (function_exists('bb_waechter')) { return true; }
    $p = rn_paths(false);
    $kand = array();
    if ($p['home'] !== '') {
        $kand[] = $p['home'] . '/bin/plugins/' . $p['plugin'] . '/befehlsbruecke.php';
    }
    $kand[] = dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php';
    foreach ($kand as $k) {
        if (is_file($k)) {
            if (!defined('BB_NUR_LADEN')) { define('BB_NUR_LADEN', true); }
            require_once $k;
            return function_exists('bb_waechter');
        }
    }
    return false;
}

/** Die Befehlsliste der Bruecke (bb_liste()). array(Liste oder null, Fehlertext). */
function rn_bruecke_liste()
{
    if (!rn_bruecke_laden()) { return array(null, rn_t('BEFEHL.T_NICHT_DA')); }
    list($z, $fehler) = bb_zuordnung_laden();
    if ($z === null) { return array(null, $fehler); }
    $GLOBALS['bb_zuordnung'] = $z;
    list($fehl) = bb_zuordnung_pruefen($z);
    if ($fehl) { return array(null, $fehl[0]); }
    return array(bb_liste($z, bb_einstellungen($z)), '');
}

/** Nach dem Speichern im Reiter MQTT: start (Haken an) bzw. stop. array(rc|null, start|stop|'', Ausgabe). */
function rn_bruecke_schalten($cfg = null)
{
    if (!is_array($cfg)) { $cfg = rn_config_read(false); }
    if (!rn_bruecke_laden()) { return array(1, '', rn_t('BEFEHL.T_NICHT_DA')); }
    $p = bb_pfade();
    if (!$p) { return array(1, '', 'keine LoxBerry-Installation erkannt'); }
    if ((string) $cfg['befehle_mqtt_ein'] === 'Y') {
        list($rc, $aus) = bb_sh($p, 'start');
        return array($rc, 'start', $aus);
    }
    if (!bb_dienste()) { return array(null, '', ''); }
    list($rc, $aus) = bb_sh($p, 'stop');
    return array($rc, 'stop', $aus);
}

/** Lage der Bruecke (Reiter Test, Reiter MQTT, Healthcheck): array(true|false|null, Text); null = aus (Haken). */
function rn_bruecke_lage()
{
    if (!rn_bruecke_laden()) { return array(false, rn_t('BEFEHL.T_NICHT_DA')); }
    list($soll, $grund) = bb_soll_ermitteln();
    if ($soll === 1) { return array(null, rn_t('BEFEHL.T_AUS')); }
    if ($soll !== 0) { return array(false, sprintf(rn_t('BEFEHL.T_DEFEKT'), $grund)); }
    $zu = bb_zustand_lesen();
    $pids = bb_dienste();
    $alter = (is_array($zu) && isset($zu['ts'])) ? time() - (int) $zu['ts'] : null;
    if ($pids && $alter !== null && $alter <= BB_WACHE_GRENZE && !empty($zu['abo'])) {
        $zl = (isset($zu['zaehler']) && is_array($zu['zaehler'])) ? $zu['zaehler'] : array();
        $txt = sprintf(rn_t('BEFEHL.T_LAEUFT'), count($pids),
            isset($zl['angenommen']) ? (int) $zl['angenommen'] : 0, isset($zl['abgelehnt']) ? (int) $zl['abgelehnt'] : 0);
        $lz = isset($zu['letzter']) && is_array($zu['letzter']) ? $zu['letzter'] : null;
        if ($lz !== null && isset($lz['name'])) {
            $txt .= ' ' . sprintf(rn_t('BEFEHL.T_LETZTER'), (string) $lz['name'],
                isset($lz['ergebnis']) ? (string) $lz['ergebnis'] : '-',
                isset($lz['zeit']) ? max(0, time() - (int) $lz['zeit']) : 0);
        } else {
            $txt .= ' ' . rn_t('BEFEHL.T_KEINER');
        }
        return array(true, $txt);
    }
    $wie = !$pids ? 'kein Dienst' : ($alter === null ? 'kein Zustand' : ($alter > BB_WACHE_GRENZE
        ? 'Zustand ' . $alter . ' s alt' : 'ohne Abo'));
    return array(false, sprintf(rn_t('BEFEHL.T_STEHT'), $wie));
}

/** befehlsbruecke.php --pruefen: array(rc, Zeilen). */
function rn_bruecke_pruefen()
{
    if (!rn_bruecke_laden()) { return array(1, array(rn_t('BEFEHL.T_NICHT_DA'))); }
    $p = bb_pfade();
    $skript = $p ? $p['bin'] . '/befehlsbruecke.php' : dirname(dirname(__DIR__)) . '/bin/befehlsbruecke.php';
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $aus = array();
    $rc = 1;
    @exec(escapeshellarg($php) . ' ' . escapeshellarg($skript) . ' --pruefen 2>&1', $aus, $rc);
    return array((int) $rc, $aus);
}

/** Der Rechnername fuer UDP-Adressen: nie die Rueckschleife (Regeln/07). */
function rn_rechnername_udp()
{
    $h = rn_rechnername();
    if (substr_count($h, ':') === 1) { $h = substr($h, 0, strpos($h, ':')); }
    if (in_array(strtolower($h), array('', '127.0.0.1', 'localhost', '::1', '[::1]'), true)) {
        $g = gethostname();
        $h = ($g !== false && $g !== '') ? $g : 'loxberry';
    }
    return $h;
}

/** Die Vorlage "Renault MQTT-Befehle" aus bb_vorlage() (CRLF, /dev/udp/<rechner>/<Udpinport>). array(Name, Inhalt|''). */
function rn_vorlage_befehle()
{
    list($l) = rn_bruecke_liste();
    if ($l === null) { return array('VQ_renault_mqtt.xml', ''); }
    $bp = bb_pfade();
    $port = $bp ? bb_broker($bp)['udpin'] : 11884;
    return array('VQ_renault_mqtt.xml', bb_vorlage($l, rn_rechnername_udp(), $port));
}

/** Vorlagen: alle CRLF und wohlgeformt (HTTP-Vorlage und Bruecke). array(1|0, Text). P-H5. */
function rn_vorlagen_pruefen()
{
    $teile = array(rn_vorlage_vo());
    $b = rn_vorlage_befehle();
    if ($b[1] !== '') { $teile[] = $b; }
    $schlecht = array();
    foreach ($teile as $t) {
        $ohne = str_replace("\r\n", '', $t[1]);
        $xml_ok = !function_exists('simplexml_load_string') || @simplexml_load_string($t[1]) !== false;
        if (strpos($ohne, "\n") !== false || strpos($t[1], "\r\n") === false || !$xml_ok) {
            $schlecht[] = $t[0];
        }
    }
    return $schlecht ? array(0, implode(', ', $schlecht))
                     : array(1, sprintf(rn_t('PRUEFTEXT.VORLAGEN_CRLF'), count($teile)));
}

/* ---------------- E-E4 der G39: Aussentemperatur aus Loxone ---------------- */

function rn_aussentemp_datei()
{
    return rn_paths()['logdir'] . '/aussentemp.json';
}

/** Den Wert aus Renault/befehl/aussentemp merken (nur die Bruecke). Rueckgabe wie rn_befehl_annehmen(). */
function rn_aussentemp_merken($wert)
{
    if (!is_numeric($wert) || (float) $wert < -40 || (float) $wert > 60) {
        return array(0, 'WERT_AUSSER_BEREICH', 400, '');
    }
    $d = dirname(rn_aussentemp_datei());
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $ok = rn_datei_schreiben(rn_aussentemp_datei(),
        (string) json_encode(array('wert' => round((float) $wert, 1), 't' => time())), 0600);
    return $ok ? array(1, 'OK', 200, '') : array(0, 'SPEICHERN', 500, '');
}

/** Die Aussentemperatur aus Loxone: array(wert, alter_s) oder array(null, -1). */
function rn_aussentemp_lesen($jetzt = null)
{
    $d = rn_json_lesen(rn_aussentemp_datei());
    if (!isset($d['wert'], $d['t']) || !is_numeric($d['wert'])) {
        return array(null, -1);
    }
    $a = ($jetzt === null ? time() : (int) $jetzt) - (int) $d['t'];
    return $a < 0 ? array(null, -1) : array((float) $d['wert'], $a);
}

/* ---------------- 2.3: endpunkt_ein, neue Schluessel ---------------- */

/**
 * Die Schluessel, die mit 2.1.20 dazukamen. Eine aeltere Sicherung kennt sie
 * nicht: beim Zurueckspielen behalten sie den Wert dieser Anlage (wie
 * rn_ansage_neue_schluessel()), und "fehlt" zaehlt sie nicht.
 */
function rn_neue_schluessel_2120()
{
    $s = array('endpunkt_ein', 'befehle_mqtt_ein', 'heim_ein', 'heim_breite', 'heim_laenge', 'heim_radius',
        'haus_abfahrt_ein', 'vorklima_anwesend_ein', 'vorklima_ein', 'vorklima_vorlauf', 'vorklima_fahrzeuge',
        'vorklima_wetter_ein', 'vorklima_kalt_unter', 'vorklima_heiss_ueber', 'signal_bl', 'signal_csf',
        'signal_praefix', 'ankunft_ein', 'haus_strompreis_ein');
    for ($i = 1; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $nr = ($i === 1) ? '' : (string) $i;
        $s[] = 'ankunft_quelle' . $nr;
        $s[] = 'akku_kwh' . $nr;
    }
    return $s;
}

/** Die Vorgaben der neuen Schluessel (alles, was etwas ausloest, ab Werk aus). */
function rn_vorgaben_2120()
{
    $v = array(
        'endpunkt_ein'          => 'N',   // Neuinstallation aus; postupgrade.sh setzt Bestand auf Y
        'befehle_mqtt_ein'      => 'N',
        'heim_ein'              => 'N',
        'heim_breite'           => '',
        'heim_laenge'           => '',
        'heim_radius'           => '150',
        'haus_abfahrt_ein'      => 'N',
        'vorklima_anwesend_ein' => 'N',
        'vorklima_ein'          => 'N',
        'vorklima_vorlauf'      => '20',
        'vorklima_fahrzeuge'    => '1',
        'vorklima_wetter_ein'   => 'N',
        'vorklima_kalt_unter'   => '5',
        'vorklima_heiss_ueber'  => '25',
        'signal_bl'             => 'N',
        'signal_csf'            => 'N',
        'signal_praefix'        => 'signalbot',
        'ankunft_ein'           => 'N',
        'haus_strompreis_ein'   => 'N',
    );
    for ($i = 1; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $nr = ($i === 1) ? '' : (string) $i;
        $v['ankunft_quelle' . $nr] = '';
        $v['akku_kwh' . $nr] = '';
    }
    return $v;
}

/** Wertpruefung der neuen Schluessel: true/false, null = kein neuer Schluessel. Rein. */
function rn_wert_pruefen_2120($k, $w)
{
    $w = (string) $w;
    if (preg_match('/^ankunft_quelle[2-4]?$/', $k)) {
        return $w === '' || rn_ankunft_thema($w) !== '';
    }
    if (preg_match('/^akku_kwh[2-4]?$/', $k)) {
        return $w === '' || (preg_match('/^[0-9]{1,3}(\.[0-9])?$/', $w) === 1 && (float) $w >= 5 && (float) $w <= 250);
    }
    switch ($k) {
        case 'endpunkt_ein': case 'befehle_mqtt_ein': case 'heim_ein': case 'haus_abfahrt_ein':
        case 'vorklima_anwesend_ein': case 'vorklima_ein': case 'vorklima_wetter_ein': case 'signal_bl':
        case 'signal_csf': case 'ankunft_ein': case 'haus_strompreis_ein':
            return in_array($w, array('Y', 'N'), true);
        case 'heim_breite':
            return $w === '' || (preg_match('/^-?[0-9]{1,2}(\.[0-9]{1,8})?$/', $w) === 1 && abs((float) $w) <= 90);
        case 'heim_laenge':
            return $w === '' || (preg_match('/^-?[0-9]{1,3}(\.[0-9]{1,8})?$/', $w) === 1 && abs((float) $w) <= 180);
        case 'heim_radius':
            return preg_match('/^[0-9]{1,5}$/', $w) === 1 && (int) $w >= 20 && (int) $w <= 20000;
        case 'vorklima_vorlauf':
            return preg_match('/^[0-9]{1,2}$/', $w) === 1 && (int) $w >= 5 && (int) $w <= 60;
        case 'vorklima_fahrzeuge':
            if (preg_match('/^[1-4](,[1-4]){0,3}$/', $w) !== 1) { return false; }
            $l = explode(',', $w);
            return count($l) === count(array_unique($l));
        case 'vorklima_kalt_unter':
        case 'vorklima_heiss_ueber':
            return preg_match('/^-?[0-9]{1,2}$/', $w) === 1 && (int) $w >= -30 && (int) $w <= 45;
        case 'signal_praefix':
            return preg_match('/^[A-Za-z0-9_-]{1,40}$/', $w) === 1;
    }
    return null;
}

/** Die Fahrzeugnummern der Vorklimatisierung aus vorklima_fahrzeuge. Rein. */
function rn_vorklima_nummern($cfg)
{
    $w = is_array($cfg) && isset($cfg['vorklima_fahrzeuge']) ? (string) $cfg['vorklima_fahrzeuge'] : '';
    if (preg_match('/^[1-4](,[1-4]){0,3}$/', $w) !== 1) { return array(); }
    $l = array_values(array_unique(array_map('intval', explode(',', $w))));
    sort($l);
    return $l;
}

/* ---------------- 2.4: Haus-Themen (Nr. 15), abgekoppelter Leser ---------------- */

/** Ist ein Haken gesetzt? config.php fuehrt Y/N als Zeichenkette. Rein. */
function rn_ja($cfg, $k)
{
    return is_array($cfg) && isset($cfg[$k]) && (string) $cfg[$k] === 'Y';
}

/** Eine kleine JSON-Datei lesen (Feld oder leeres Feld). */
function rn_json_lesen($datei)
{
    $d = is_file($datei) ? json_decode((string) @file_get_contents($datei), true) : null;
    return is_array($d) ? $d : array();
}

/** Eine JSON-Datei unteilbar schreiben (0600). */
function rn_json_schreiben($datei, array $daten)
{
    $d = dirname($datei);
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $js = json_encode($daten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return is_string($js) && rn_datei_schreiben($datei, $js, 0600);
}

/** Ein Programm der mosquitto-clients: Pfad oder ''. */
function rn_programm($name)
{
    foreach (array('/usr/bin/', '/usr/local/bin/', '/bin/') as $d) {
        if (is_file($d . $name) && is_executable($d . $name)) { return $d . $name; }
    }
    return '';
}

/**
 * Die Optionsdatei fuer mosquitto_sub/_pub: data/plugins/<ordner>/mosquitto/
 * (Ordner 0700, Dateien 0600) mit -u/-P aus dem Broker-Zugang des Gateways.
 * Aufruf mit XDG_CONFIG_HOME - das Kennwort steht NIE in einer Befehlszeile
 * (Gedaechtnis "MQTT-Kennwort nie als Argument"). Abschrift
 * mg_broker_optionsdatei(). Rueckgabe der Ordner oder ''.
 */
function rn_broker_optionsdatei()
{
    $p = rn_paths(false);
    if ($p['home'] === '') { return ''; }
    $ordner = $p['datadir'] . '/mosquitto';
    if (!is_dir($ordner) && !@mkdir($ordner, 0700, true) && !is_dir($ordner)) {
        return '';
    }
    @chmod($ordner, 0700);
    $z = rn_mqtt_zugang();
    $zeilen = '';
    if (is_array($z) && (string) $z['user'] !== '') {
        $zeilen .= '-u ' . $z['user'] . "\n";
        if ((string) $z['pass'] !== '') { $zeilen .= '-P ' . $z['pass'] . "\n"; }
    }
    foreach (array('mosquitto_sub', 'mosquitto_pub') as $name) {
        $datei = $ordner . '/' . $name;
        if (is_file($datei) && (string) @file_get_contents($datei) === $zeilen) { continue; }
        if (!rn_datei_schreiben($datei, $zeilen, 0600)) {
            rn_melden_stuendlich('WARN', 'Die Optionsdatei ' . $datei . ' liess sich nicht schreiben - es gilt '
                . 'der vorherige Stand.');
        }
    }
    return $ordner;
}

/** Umgebung fuer die mosquitto-Kinder: Optionsdatei (XDG), nie ein Kennwort als Argument. */
function rn_proc_umgebung()
{
    $u = array('XDG_CONFIG_HOME' => rn_broker_optionsdatei(), 'LANG' => 'C',
               'PATH' => ((string) getenv('PATH') !== '') ? (string) getenv('PATH') : '/usr/local/bin:/usr/bin:/bin');
    if ((string) getenv('HOME') !== '') { $u['HOME'] = (string) getenv('HOME'); }
    return $u;
}

function rn_haus_quellen_datei()
{
    return rn_paths()['logdir'] . '/haus_quellen.json';
}

/** Der zuletzt gelesene Stand - bei jedem Aufruf frisch aus der Datei. */
function rn_haus_quellen()
{
    return rn_json_lesen(rn_haus_quellen_datei());
}

/** 'tag:<name>' -> haus/tag/<name>/da, 'person:<name>' -> haus/anwesenheit/<name>, sonst ''. Rein (E-E2, wie MG). */
function rn_ankunft_thema($quelle)
{
    if (!is_string($quelle) || !preg_match('/^(tag|person):([A-Za-z0-9_\-]{1,40})\z/', $quelle, $t)) {
        return '';
    }
    if ($t[1] === 'person' && in_array($t[2], array('jemand', 'zaehler'), true)) {
        return '';
    }
    return $t[1] === 'tag' ? 'haus/tag/' . $t[2] . '/da' : 'haus/anwesenheit/' . $t[2];
}

/** Die Ankunftsquellen je Fahrzeug: nr => Thema. Rein bis auf die Konfiguration als Argument. */
function rn_ankunft_quellen(array $cfg)
{
    $aus = array();
    if (!rn_ja($cfg, 'ankunft_ein')) { return $aus; }
    for ($i = 1; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $nr = ($i === 1) ? '' : (string) $i;
        $t = isset($cfg['ankunft_quelle' . $nr]) ? rn_ankunft_thema((string) $cfg['ankunft_quelle' . $nr]) : '';
        if ($t !== '') { $aus[$i] = $t; }
    }
    return $aus;
}

/** Welche Themen liest der Leser je Quelle? Nur die eingeschalteten. Rein. */
function rn_haus_lesethemen(array $cfg)
{
    $aus = array();
    if (rn_ja($cfg, 'haus_abfahrt_ein')) {
        $aus['abfahrt'] = array('haus/abfahrt/in_min', 'haus/abfahrt/zaehler');
    }
    $ank = rn_ankunft_quellen($cfg);
    if (rn_ja($cfg, 'vorklima_anwesend_ein') || $ank) {
        $l = array('haus/anwesenheit/jemand', 'haus/anwesenheit/zaehler');
        foreach ($ank as $t) {
            if (!in_array($t, $l, true)) { $l[] = $t; }
        }
        $aus['anwesenheit'] = $l;
    }
    if (rn_ja($cfg, 'haus_strompreis_ein')) {
        $aus['strompreis'] = array('haus/strompreis/aktuell', 'haus/strompreis/vorschau', 'haus/strompreis/ab',
                                   'haus/strompreis/raster', 'haus/strompreis/anbieter', 'haus/strompreis/zaehler');
    }
    return $aus;
}

/**
 * Das Ergebnis einer Lesesitzung einarbeiten (Rein; Abschrift mg_haus_merken()).
 * Je Quelle: werte, der zuletzt LIVE gesehene Zaehler und 'seit' = Zeitpunkt
 * seiner letzten Aenderung. Ein live gesehener, aber unveraenderter Zaehler
 * verlaengert nichts; ein nur retained gelesener zaehlt nicht.
 */
function rn_haus_merken(array $alt, array $erg, array $lesethemen, $jetzt)
{
    $neu = $alt;
    $erg += array('lage' => '', 'werte' => array(), 'live' => array());
    foreach ($lesethemen as $q => $themen) {
        $e = (isset($alt[$q]) && is_array($alt[$q])) ? $alt[$q] : array();
        $e += array('werte' => array(), 'zaehler' => null, 'seit' => 0, 'beginn' => (int) $jetzt);
        foreach ($themen as $t) {
            $da = array_key_exists($t, $erg['werte']);
            if (substr($t, -8) === '/zaehler') {
                if ($da && !empty($erg['live'][$t]) && (string) $erg['werte'][$t] !== (string) $e['zaehler']) {
                    $e['zaehler'] = (string) $erg['werte'][$t];
                    $e['seit'] = (int) $jetzt;
                }
            } elseif ($da) {
                $e['werte'][$t] = (string) $erg['werte'][$t];
            } elseif ($erg['lage'] === 'ok') {
                $e['werte'][$t] = '';
            }
        }
        $neu[$q] = $e;
    }
    return $neu;
}

/** Frisch: 0 <= jetzt - seit <= RN_HAUS_FRIST, seit > 0 und nicht in der Zukunft. Rein. */
function rn_haus_frisch(array $c, $q, $jetzt)
{
    $seit = isset($c[$q]['seit']) ? (int) $c[$q]['seit'] : 0;
    return $seit > 0 && $seit <= (int) $jetzt && (int) $jetzt - $seit <= RN_HAUS_FRIST;
}

/** Schweigt die Quelle laenger als 10 min? Rein. */
function rn_haus_lange_still(array $c, $q, $jetzt)
{
    $seit = isset($c[$q]['seit']) ? (int) $c[$q]['seit'] : 0;
    $beginn = isset($c[$q]['beginn']) ? (int) $c[$q]['beginn'] : (int) $jetzt;
    $ab = ($seit > 0 && $seit <= (int) $jetzt) ? $seit : $beginn;
    return (int) $jetzt - $ab > 600;
}

/** Auf welche Zaehler wartet der Leser? Jeden, ausser eine seit 10 min schweigende Quelle (nur in Minute % 10 = 0). Rein. */
function rn_haus_wartethemen(array $themen, array $c, $jetzt)
{
    $warten = array();
    $zehnter = ((int) floor((int) $jetzt / 60)) % 10 === 0;
    foreach (array_keys($themen) as $q) {
        if (isset($c[$q]) && rn_haus_lange_still($c, $q, $jetzt) && !$zehnter) {
            continue;
        }
        $warten[] = 'haus/' . $q . '/zaehler';
    }
    return $warten;
}

/** Zeilen von "mosquitto_sub -F '%r %t %x'" deuten (Rein): array(werte, live) fuer die gesuchten Themen. */
function rn_haus_zeilen_deuten(array $zeilen, array $soll)
{
    $werte = array();
    $live = array();
    foreach ($zeilen as $z) {
        if (!preg_match('/^([01]) (\S+) ([0-9a-fA-F]*)\z/', rtrim((string) $z, "\r\n"), $m)) {
            continue;
        }
        if (!in_array($m[2], $soll, true) || strlen($m[3]) % 2 !== 0) {
            continue;
        }
        $werte[$m[2]] = (string) hex2bin($m[3]);
        if ($m[1] === '0') {
            $live[$m[2]] = true;
        }
    }
    return array($werte, $live);
}

/**
 * Eine Lesesitzung am Broker: mosquitto_sub mit der Optionsdatei des Plugins,
 * Ausgabe '%r %t %x' (Retain-Bit, Thema, Nutzlast hex). Gelesen wird, bis
 * jedes Thema aus $warten einmal LIVE (Retain-Bit 0) kam oder $fenster
 * Sekunden um sind; ohne $warten 1,5 s. phpMQTT gibt das Retain-Bit nicht
 * heraus (MG-Abweichung 3). Der Leser liest nur.
 */
function rn_haus_sitzung(array $lesen, array $warten, $fenster)
{
    $aus = array('lage' => 'kein_broker', 'werte' => array(), 'live' => array());
    $prog = rn_programm('mosquitto_sub');
    $z = rn_mqtt_zugang();
    if (!$lesen || !function_exists('proc_open') || $prog === '' || !is_array($z)) {
        return $aus;
    }
    $host = (string) $z['host'];
    if ($host === 'localhost') { $host = '127.0.0.1'; }
    $kmd = array($prog, '-h', $host, '-p', (string) ((int) $z['port'] > 0 ? (int) $z['port'] : 1883), '-F', '%r %t %x');
    foreach ($lesen as $t) {
        $kmd[] = '-t';
        $kmd[] = (string) $t;
    }
    $rohr = array();
    $pr = @proc_open($kmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'),
                                 2 => array('pipe', 'w')), $rohr, null, rn_proc_umgebung());
    if (!is_resource($pr)) {
        return $aus;
    }
    stream_set_blocking($rohr[1], false);
    stream_set_blocking($rohr[2], false);
    $puffer = '';
    $zeilen = array();
    $bis = microtime(true) + ($warten ? max(1.0, min(66.0, (float) $fenster)) : 1.5);
    $rc = null;
    while (microtime(true) < $bis) {
        $r = array($rohr[1]);
        $w = null;
        $x = null;
        if (@stream_select($r, $w, $x, 0, 200000) > 0) {
            $teil = fread($rohr[1], 65536);
            if ($teil !== false && $teil !== '') {
                $puffer .= $teil;
                while (($pz = strpos($puffer, "\n")) !== false) {
                    $zeilen[] = substr($puffer, 0, $pz);
                    $puffer = substr($puffer, $pz + 1);
                }
            }
        }
        if ($warten) {
            list(, $live) = rn_haus_zeilen_deuten($zeilen, $lesen);
            $offen = false;
            foreach ($warten as $t) {
                if (empty($live[$t])) { $offen = true; break; }
            }
            if (!$offen) { break; }
        }
        $s = proc_get_status($pr);
        if (!$s['running']) {
            $rc = (int) $s['exitcode'];
            $puffer .= (string) stream_get_contents($rohr[1]);
            break;
        }
    }
    if ($puffer !== '') {
        foreach (explode("\n", $puffer) as $zz) {
            if ($zz !== '') { $zeilen[] = $zz; }
        }
    }
    $s = proc_get_status($pr);
    if ($s['running']) {
        @proc_terminate($pr, 15);
    }
    @fclose($rohr[1]);
    @fclose($rohr[2]);
    @proc_close($pr);
    if ($rc === null && !$s['running']) {
        $rc = (int) $s['exitcode'];
    }
    list($aus['werte'], $aus['live']) = rn_haus_zeilen_deuten($zeilen, $lesen);
    if ($rc !== null && $rc !== 0 && $rc !== 27 && !$aus['werte']) {
        $aus['lage'] = in_array($rc, array(4, 5), true) ? 'abgelehnt' : 'kein_broker';
    } else {
        $aus['lage'] = 'ok';
    }
    return $aus;
}

/**
 * Ankunftsflanke im Leser (Rein; E-E2, Abschrift mg_ankunft_flanke()). Der erste
 * Stand nach einem Start ist nie eine Flanke; 0 -> 1 zaehlt nur, wenn der vorige
 * UND der jetzige Stand frisch sind. nr steigt je Flanke um eins.
 */
function rn_ankunft_flanke($alt, $wert, $frisch)
{
    $wert = (string) $wert;
    if (!is_array($alt) || !array_key_exists('wert', $alt)) {
        return array('wert' => $wert, 'frisch' => (bool) $frisch, 'nr' => 0);
    }
    $nr = isset($alt['nr']) ? (int) $alt['nr'] : 0;
    if (!empty($alt['frisch']) && $frisch && (string) $alt['wert'] === '0' && $wert === '1') {
        $nr++;
    }
    return array('wert' => $wert, 'frisch' => (bool) $frisch, 'nr' => $nr);
}

/**
 * Was tut der Minutentakt mit einer Flanke? (Rein) array(aktion, neue_ausgefuehrt):
 * nichts | abfragen | gedrosselt | frisch. Jede Flanke genau einmal; hoechstens
 * eine Abfrage je RN_ANKUNFT_ABSTAND je Fahrzeug; nur wenn der letzte gelungene
 * Abruf aelter als RN_ANKUNFT_FZALTER Sekunden ist (unbekannt = alt).
 */
function rn_ankunft_entscheiden($flanke_nr, $ausgefuehrt, $letzte_abfrage, $jetzt, $alter_s)
{
    if ((int) $flanke_nr <= (int) $ausgefuehrt) {
        return array('nichts', (int) $ausgefuehrt);
    }
    $neu = (int) $flanke_nr;
    if ((int) $letzte_abfrage > 0 && (int) $jetzt >= (int) $letzte_abfrage
        && (int) $jetzt - (int) $letzte_abfrage < RN_ANKUNFT_ABSTAND) {
        return array('gedrosselt', $neu);
    }
    if (is_numeric($alter_s) && (float) $alter_s >= 0 && (float) $alter_s <= RN_ANKUNFT_FZALTER) {
        return array('frisch', $neu);
    }
    return array('abfragen', $neu);
}

/** Den Lesestand der Ankunftsquellen einarbeiten (Flanken je Fahrzeug). Rein bis auf die Konfiguration. */
function rn_ankunft_merken(array $c, array $cfg, $jetzt)
{
    $quellen = rn_ankunft_quellen($cfg);
    if (!$quellen) {
        unset($c['ankunft']);
        return $c;
    }
    $frisch = rn_haus_frisch($c, 'anwesenheit', $jetzt);
    $ank = (isset($c['ankunft']) && is_array($c['ankunft'])) ? $c['ankunft'] : array();
    $neu = array();
    foreach ($quellen as $nr => $t) {
        $w = isset($c['anwesenheit']['werte'][$t]) ? $c['anwesenheit']['werte'][$t] : '';
        $alt = (isset($ank[$nr]) && is_array($ank[$nr]) && isset($ank[$nr]['thema']) && $ank[$nr]['thema'] === $t)
            ? $ank[$nr] : null;
        $neu[(string) $nr] = rn_ankunft_flanke($alt, $w, $frisch) + array('thema' => $t);
    }
    $c['ankunft'] = $neu;
    return $c;
}

/**
 * Der Leser: liest die eingeschalteten Haus-Themen (bis $fenster s), arbeitet
 * sie und die Ankunftsflanken ein und legt <logdir>/haus_quellen.json ab (0600).
 * Er sendet nichts, schreibt nichts unter haus/ und loest keinen Befehl aus.
 */
function rn_haus_abnehmer(array $cfg, $jetzt = null, $fenster = RN_HAUS_LESEFENSTER)
{
    $themen = rn_haus_lesethemen($cfg);
    $datei = rn_haus_quellen_datei();
    if (!$themen) {
        if (is_file($datei)) { @unlink($datei); }
        return null;
    }
    if ($jetzt === null) { $jetzt = time(); }
    $c = rn_haus_quellen();
    $lesen = array();
    foreach ($themen as $liste) {
        foreach ($liste as $t) { $lesen[] = $t; }
    }
    $warten = rn_haus_wartethemen($themen, $c, $jetzt);
    $erg = rn_haus_sitzung($lesen, $warten, $fenster);
    $c = rn_haus_merken($c, $erg, $themen, time());
    foreach (array('abfahrt', 'anwesenheit', 'strompreis') as $q) {
        if (!isset($themen[$q])) { unset($c[$q]); }
    }
    $c = rn_ankunft_merken($c, $cfg, time());
    $c['sitzung'] = array('zeit' => time(), 'lage' => $erg['lage'], 'gewartet' => count($warten));
    rn_json_schreiben($datei, $c);
    return $erg;
}

function rn_haus_leser_sperre()
{
    return rn_paths()['logdir'] . '/haus_lesen.lock';
}

/**
 * Den abgekoppelten Leser starten (abruf.php --haus-lesen) - hoechstens einen,
 * der Takt wartet nie. Die Schale schliesst vor dem exec jede Deskriptor-Nummer
 * ueber 2 (sonst erbte der Leser takt.lock - Gedaechtnis "Sperre vererbt sich
 * an Kinder"). Rueckgabe: aus | laeuft | gestartet | kein_start.
 */
function rn_haus_abnehmer_starten(array $cfg, $skript)
{
    if (!rn_haus_lesethemen($cfg)) {
        $d = rn_haus_quellen_datei();
        if (is_file($d)) { @unlink($d); }
        return 'aus';
    }
    $fh = @fopen(rn_haus_leser_sperre(), 'ce');
    if ($fh === false) { return 'kein_start'; }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return 'laeuft';
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY)) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'cd ' . escapeshellarg(dirname($skript)) . ' && exec ' . escapeshellarg($php) . ' '
            . escapeshellarg(basename($skript)) . ' --haus-lesen';
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Der Leser selbst (abruf.php --haus-lesen): eigene Sperre (close-on-exec), sonst still beenden. */
function rn_haus_leser_lauf($fenster = RN_HAUS_LESEFENSTER)
{
    $fh = @fopen(rn_haus_leser_sperre(), 'ce');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
        return 'laeuft';
    }
    $erg = rn_haus_abnehmer(rn_config_read(false), null, $fenster);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg === null ? 'aus' : $erg['lage'];
}

/** Ein Wert einer Quelle aus der Ablage ('' wenn keiner). */
function rn_haus_wert(array $c, $q, $thema)
{
    return isset($c[$q]['werte'][$thema]) ? (string) $c[$q]['werte'][$thema] : '';
}

/** Je eingeschaltete Haus-Quelle eine Zeile fuer den Reiter Test: array(bez, ok, text). */
function rn_haus_pruefzeilen($cfg = null)
{
    if ($cfg === null) { $cfg = rn_config_read(false); }
    $c = rn_haus_quellen();
    $jetzt = time();
    $gilt = array('abfahrt' => 'HAUS.RUECK_ABFAHRT', 'anwesenheit' => 'HAUS.RUECK_ANWESENHEIT',
                  'strompreis' => 'HAUS.RUECK_STROMPREIS');
    $z = array();
    foreach (array_keys(rn_haus_lesethemen($cfg)) as $q) {
        $seit = isset($c[$q]['seit']) ? (int) $c[$q]['seit'] : 0;
        if (rn_haus_frisch($c, $q, $jetzt)) {
            $z[] = array(rn_t('HAUS.Q_' . strtoupper($q)), true, sprintf(rn_t('HAUS.FRISCH'), $jetzt - $seit));
        } else {
            $z[] = array(rn_t('HAUS.Q_' . strtoupper($q)), false, sprintf(rn_t('HAUS.SCHWEIGT'),
                $seit > 0 ? (string) max(0, (int) round(($jetzt - $seit) / 60)) . ' min' : rn_t('HAUS.NIE'),
                rn_t($gilt[$q])));
        }
    }
    if ($z && rn_programm('mosquitto_sub') === '') {
        $z[] = array(rn_t('HAUS.LESER'), false, rn_t('HAUS.KEIN_MOSQUITTO'));
    }
    return $z;
}

/* ---------------- 3.2: Vorklimatisierung aus haus/abfahrt ---------------- */

/** Kennung einer Abfahrt: Abfahrtszeit auf 5 min gerundet. Rein. */
function rn_vorklima_kennung($jetzt, $in_min)
{
    return (int) (round(((int) $jetzt + (int) $in_min * 60) / 300) * 300);
}

/**
 * Ein Schritt der Vorklimatisierung (Rein; Abschrift mg_vorklima_schritt()).
 * Merker {kennung, versuche, letzter, erfolg, aufgefrischt, aufgegeben, mild}.
 * Rueckgabe array(aktion, merker), aktion nichts | senden | abruf_und_senden |
 * aufgeben | erledigt. Ausloesen nur bei 1 <= in_min <= vorlauf; +-5 min ist
 * dieselbe Abfahrt; hoechstens RN_VORKLIMA_VERSUCHE Versuche, Abstand >=
 * RN_VORKLIMA_ABSTAND: 1. Senden, 2. Abruf (gesendet wird nicht), 3. Senden;
 * danach aufgeben - hoechstens ZWEI acnow je Abfahrt (MG-Abweichung 1).
 */
function rn_vorklima_schritt(array $m, $in_min, $jetzt, $vorlauf, $letztes_ok)
{
    $jetzt = (int) $jetzt;
    if ($letztes_ok === true && isset($m['kennung'])) {
        $m['erfolg'] = 1;
    }
    if (!is_numeric($in_min)) {
        return array('nichts', $m);
    }
    $in = (int) $in_min;
    if ($in < 1 || $in > max(1, (int) $vorlauf)) {
        return array('nichts', $m);
    }
    $k = rn_vorklima_kennung($jetzt, $in);
    if (!isset($m['kennung']) || abs($k - (int) $m['kennung']) > 300) {
        $m = array('kennung' => $k, 'versuche' => 0, 'letzter' => 0, 'erfolg' => 0,
                   'aufgefrischt' => 0, 'aufgegeben' => 0, 'mild' => 0);
    }
    $m += array('versuche' => 0, 'letzter' => 0, 'erfolg' => 0, 'aufgefrischt' => 0, 'aufgegeben' => 0, 'mild' => 0);
    if (!empty($m['erfolg']) || !empty($m['mild'])) {
        return array('erledigt', $m);
    }
    if (!empty($m['aufgegeben'])) {
        return array('nichts', $m);
    }
    if ((int) $m['versuche'] >= RN_VORKLIMA_VERSUCHE) {
        $m['aufgegeben'] = 1;
        return array('aufgeben', $m);
    }
    if ((int) $m['versuche'] >= 1) {
        if ($jetzt < (int) $m['letzter']) {   // Uhr zurueck: neu zaehlen, nicht senden
            $m['letzter'] = $jetzt;
            return array('nichts', $m);
        }
        if ($jetzt - (int) $m['letzter'] < RN_VORKLIMA_ABSTAND) {
            return array('nichts', $m);
        }
    }
    $m['versuche'] = (int) $m['versuche'] + 1;
    $m['letzter'] = $jetzt;
    if ((int) $m['versuche'] === 2 && empty($m['aufgefrischt'])) {
        $m['aufgefrischt'] = 1;
        return array('abruf_und_senden', $m);
    }
    return array('senden', $m);
}

/** Vorklima aus einem Merker (Rein): 0 nichts/aus, 1 gesendet, 2 mild, 3 Wiederholung, 4 Anwesenheit, -1 gescheitert. */
function rn_vorklima_wert($m, $jetzt)
{
    if (!is_array($m) || !isset($m['kennung'])) {
        return 0;
    }
    if ((int) $m['kennung'] + 1800 < (int) $jetzt) {
        return 0;   // die Abfahrt liegt eine halbe Stunde zurueck
    }
    if (!empty($m['aufgegeben'])) { return -1; }
    if (!empty($m['erfolg'])) { return 1; }
    if (!empty($m['mild'])) { return 2; }
    if (!empty($m['warten_anwesend'])) { return 4; }
    if (!empty($m['versuche'])) { return 3; }
    return 0;
}

/**
 * Die Wettergrenze (Rein; MG E-E4 B). Zuerst der Wert aus Loxone
 * (Renault/befehl/aussentemp, Alter <= RN_AUSSEN_LOXONE_FRISCH s), sonst OutTemp
 * des Fahrzeugs aus einem Lauf, der hoechstens RN_VORKLIMA_FRISCH s zurueckliegt
 * (-99 und weniger gilt nicht), sonst ohne_aussage - dann wird klimatisiert
 * (nie "nicht klimatisiert" ohne Aussage). Haken aus -> klimatisieren.
 * Rueckgabe array(urteil klimatisieren|mild|ohne_aussage, Wert|null, Quelle loxone|fahrzeug|'').
 */
function rn_vorklima_wetter_genau($cfg, $aussen_loxone, $alter_loxone, $aussen_fz, $alter_fz)
{
    if (!rn_ja($cfg, 'vorklima_wetter_ein')) {
        return array('klimatisieren', null, '');
    }
    $a = null;
    $q = '';
    if (is_numeric($aussen_loxone) && is_numeric($alter_loxone) && (float) $alter_loxone >= 0
        && (float) $alter_loxone <= RN_AUSSEN_LOXONE_FRISCH) {
        $a = (float) $aussen_loxone;
        $q = 'loxone';
    } elseif (is_numeric($aussen_fz) && (float) $aussen_fz > -99 && is_numeric($alter_fz)
        && (float) $alter_fz >= 0 && (float) $alter_fz <= RN_VORKLIMA_FRISCH) {
        $a = (float) $aussen_fz;
        $q = 'fahrzeug';
    }
    if ($a === null) {
        return array('ohne_aussage', null, '');
    }
    $kalt = isset($cfg['vorklima_kalt_unter']) && is_numeric($cfg['vorklima_kalt_unter']) ? (float) $cfg['vorklima_kalt_unter'] : 5.0;
    $heiss = isset($cfg['vorklima_heiss_ueber']) && is_numeric($cfg['vorklima_heiss_ueber']) ? (float) $cfg['vorklima_heiss_ueber'] : 25.0;
    return array(($a >= $kalt && $a <= $heiss) ? 'mild' : 'klimatisieren', $a, $q);
}

/** klimatisieren | mild | ohne_aussage. Rein. */
function rn_vorklima_wetter($cfg, $aussen_loxone, $alter_loxone, $aussen_fz, $alter_fz)
{
    $e = rn_vorklima_wetter_genau($cfg, $aussen_loxone, $alter_loxone, $aussen_fz, $alter_fz);
    return $e[0];
}

function rn_vorklima_datei()
{
    return rn_paths()['datadir'] . '/vorklima.json';
}

/** Das Feld Vorklima eines Fahrzeugs (aus dem Merker der Automatik). */
function rn_vorklima_feld($cfg, $nr, $jetzt = null)
{
    if (!rn_ja($cfg, 'vorklima_ein') || !in_array((int) $nr, rn_vorklima_nummern($cfg), true)) {
        return 0;
    }
    $merk = rn_json_lesen(rn_vorklima_datei());
    $m = isset($merk['vorklima'][(string) (int) $nr]) ? $merk['vorklima'][(string) (int) $nr] : null;
    return rn_vorklima_wert($m, $jetzt === null ? time() : $jetzt);
}

/* ---------------- E-E2: Ladekosten je Ladung (abweichend: schon in 2.1.20) ---------------- */

/**
 * Endpreis in ct/kWh zum Zeitpunkt $t (Rein; Abschrift mg_preis_zum_zeitpunkt()):
 * vorschau[floor((t - ab)/raster)]; ausserhalb gilt aktuell - aber nur bei
 * frischer Quelle. Sonst null.
 */
function rn_preis_zum_zeitpunkt($vorschau, $ab, $raster, $aktuell, $frisch, $t)
{
    $v = is_string($vorschau) ? trim($vorschau) : '';
    if ($v !== '' && strlen($v) <= 4096 && preg_match('/^-?\d{1,4}(\.\d{1,3})?(;-?\d{1,4}(\.\d{1,3})?)*\z/', $v)
        && is_numeric($ab) && is_numeric($raster)) {
        $ab = (int) $ab;
        $r = (int) $raster;
        if (in_array($r, array(900, 3600), true) && $ab > 0 && $ab % $r === 0 && (int) $t >= $ab) {
            $l = explode(';', $v);
            $i = (int) floor(((int) $t - $ab) / $r);
            if ($i < count($l)) {
                return (float) $l[$i];
            }
        }
    }
    if ($frisch && is_numeric($aktuell)) {
        return (float) $aktuell;
    }
    return null;
}

/**
 * Die Kosten einer Ladung (Rein). Renault liefert die Ladeleistung nicht im
 * Minutentakt, nur mit jedem Abruf (2-5 min). Deshalb:
 *   - Energie je Abschnitt zwischen zwei Abrufen aus der GEMELDETEN Energie im
 *     Akku (batteryAvailableEnergy, Generation 2), sonst aus der
 *     Akkustand-Differenz x eingetragene Kapazitaet (akku_kwh). Fehlt beides
 *     fuer einen Abschnitt: Kosten -1 ('energie_fehlt') - nie geschaetzt.
 *     Ein Abschnitt mit sinkendem Stand zaehlt nicht.
 *   - Der Abschnitt wird nach Dauer auf Minutenfenster ab der Fahrzeugzeit
 *     verteilt; jedes Fenster traegt den Preis einer Probe in ihm, sonst der
 *     seiner Mitte naechsten (hoechstens 60 s; $preise: t, preis|null - der Takt
 *     schreibt eine je Uhrminute, der Beginn wird aus der Vorschau nachgetragen).
 *   - Fehlt der Preis fuer mehr als 10 % der Energie: Kosten -1 ('preis_fehlt').
 * Die Energie ist die in den Akku geladene - ohne Ladeverluste (Hilfe/README).
 * $stand: Liste {t, soc, kwh}. Rueckgabe array(kwh, kosten_eur, preis_ct, abdeckung, grund).
 */
function rn_ladung_kosten(array $stand, array $preise, $kapazitaet)
{
    $s = array();
    foreach ($stand as $x) {
        if (is_array($x) && isset($x['t']) && is_numeric($x['t']) && !isset($s[(int) $x['t']])) {
            $s[(int) $x['t']] = $x;
        }
    }
    ksort($s);
    $p = array();
    foreach ($preise as $x) {
        if (is_array($x) && isset($x['t']) && is_numeric($x['t']) && !isset($p[(int) $x['t']])) {
            $p[(int) $x['t']] = (isset($x['preis']) && is_numeric($x['preis'])) ? (float) $x['preis'] : null;
        }
    }
    ksort($p);
    if (count($s) < 2) {
        return array(-1, -1, -1, 0, 'energie_fehlt');
    }
    $kap = is_numeric($kapazitaet) ? (float) $kapazitaet : 0.0;
    $kwh = 0.0;
    $mit = 0.0;
    $cent = 0.0;
    $vorher = null;
    foreach ($s as $t => $b) {
        if ($vorher === null) { $vorher = array($t, $b); continue; }
        list($ta, $a) = $vorher;
        $vorher = array($t, $b);
        $dt = $t - $ta;
        if ($dt <= 0) { continue; }
        $ka = isset($a['kwh']) && is_numeric($a['kwh']) && (float) $a['kwh'] >= 0 ? (float) $a['kwh'] : null;
        $kb = isset($b['kwh']) && is_numeric($b['kwh']) && (float) $b['kwh'] >= 0 ? (float) $b['kwh'] : null;
        $sa = isset($a['soc']) && is_numeric($a['soc']) && (float) $a['soc'] >= 0 ? (float) $a['soc'] : null;
        $sb = isset($b['soc']) && is_numeric($b['soc']) && (float) $b['soc'] >= 0 ? (float) $b['soc'] : null;
        if ($ka !== null && $kb !== null) {
            $e = $kb - $ka;
        } elseif ($sa !== null && $sb !== null && $kap > 0) {
            $e = ($sb - $sa) / 100.0 * $kap;
        } else {
            return array(-1, -1, -1, 0, 'energie_fehlt');
        }
        if ($e <= 0) { continue; }
        $kwh += $e;
        /* Der Abschnitt wird nach Dauer auf Minutenfenster verteilt (ein kurzes
         * letztes Fenster traegt nur seinen Anteil). Jedes Fenster nimmt eine
         * Probe in ihm, sonst die seiner Mitte naechste - hoechstens 60 s entfernt
         * (die Proben kommen einmal je Uhrminute, die Fenster beginnen bei der
         * Fahrzeugzeit). Fehlt sie oder ist sie null, zaehlt das Fenster als
         * ohne Preis. */
        $n = max(1, (int) ceil($dt / 60));
        for ($i = 0; $i < $n; $i++) {
            $s0 = $ta + $i * 60;
            $s1 = min($t, $s0 + 60);
            $je = $e * ($s1 - $s0) / $dt;
            $mitte = ($s0 + $s1) / 2;
            $gefunden = null;
            $innen = false;
            $abstand = 61;
            foreach ($p as $pt => $pw) {
                if ($pt > $mitte + 60) { break; }
                $drin = ($pt > $s0 && $pt <= $s1);
                $d = abs($pt - $mitte);
                if ($innen && !$drin) { continue; }
                if (($drin && !$innen) || ($d <= 60 && $d < $abstand)) {
                    $abstand = $d; $gefunden = $pw; $innen = $drin;
                }
            }
            if ($gefunden !== null) {
                $mit += $je;
                $cent += $gefunden * $je;
            }
        }
    }
    if ($kwh <= 0) {
        return array(-1, -1, -1, 0, 'energie_fehlt');
    }
    $abd = $mit / $kwh;
    if ($abd < RN_LADUNG_ABDECKUNG) {
        return array(round($kwh, 3), -1, -1, round($abd, 3), 'preis_fehlt');
    }
    return array(round($kwh, 3), round($cent / 100, 2), round($cent / $mit, 1), round($abd, 3), 'ok');
}

function rn_ladung_datei($nr, $art)
{
    return rn_paths()['datadir'] . '/ladung_' . $art . (int) $nr . '.json';
}

/**
 * Nach jedem gelungenen Abruf (abruf.php): die laufende Ladung fortschreiben.
 * Beginnt eine Ladung, entsteht ladung_laufend<n>.json (Zuhause zu Beginn, ob
 * der Strompreis-Haken an ist) und eine leere ladung_preise<n>.json; jeder
 * Abruf haengt den Stand {t, soc, kwh} an. Endet sie, rechnet
 * rn_ladung_abschluss(). Rueckgabe null oder der Abschluss (Feld fuer MQTT).
 */
function rn_ladung_fortschreiben(array $cfg, $nr, $laedt, $t, $soc, $kwh, $zuhause)
{
    if (!rn_ja($cfg, 'haus_strompreis_ein')) {
        return null;
    }
    $f = rn_ladung_datei($nr, 'laufend');
    $d = rn_json_lesen($f);
    $probe = array('t' => (int) $t, 'soc' => is_numeric($soc) ? (float) $soc : null,
                   'kwh' => is_numeric($kwh) ? (float) $kwh : null);
    if ($laedt) {
        if (!isset($d['beginn_t'])) {
            $d = array('beginn_t' => time(), 'zuhause' => (int) $zuhause, 'stand' => array());
            rn_json_schreiben(rn_ladung_datei($nr, 'preise'), array('beginn_t' => $d['beginn_t'],
                'proben' => rn_ladung_nachtrag(rn_haus_quellen(), (int) $t, time())));
        }
        $l = isset($d['stand']) && is_array($d['stand']) ? $d['stand'] : array();
        $letzte = $l ? end($l) : null;
        if (!is_array($letzte) || (int) $letzte['t'] !== (int) $t) { $l[] = $probe; }
        $d['stand'] = array_slice($l, -1000);
        rn_json_schreiben($f, $d);
        return null;
    }
    if (!isset($d['beginn_t'])) {
        return null;
    }
    $l = isset($d['stand']) && is_array($d['stand']) ? $d['stand'] : array();
    $letzte = $l ? end($l) : null;
    if (!is_array($letzte) || (int) $letzte['t'] !== (int) $t) { $l[] = $probe; }
    $d['stand'] = $l;
    return rn_ladung_abschluss($cfg, $nr, $d);
}

/**
 * Preisproben fuer die Minuten zwischen der Fahrzeugzeit der ersten Probe ($von)
 * und dem Abruf, der die Ladung erkennt ($bis): vergangene Minuten nur aus der
 * Preisvorschau (vorschau/ab/raster, der Tarif genau dieser Minuten), nie aus
 * 'aktuell' - das gilt nur fuer die laufende Minute, die mit einer Probe zum
 * Zeitpunkt $bis abgedeckt wird (wie der Takt, nur bei frischer Quelle; der
 * Takt dieser Minute lief vielleicht schon, bevor die Ladung erkannt war).
 * Ohne Vorschau: null je vergangene Minute (zaehlt als fehlender Preis).
 * Hoechstens 120 Minuten. Rein bis auf die uebergebene Ablage $c.
 */
function rn_ladung_nachtrag(array $c, $von, $bis)
{
    $aus = array();
    $von = (int) $von;
    $bis = (int) $bis;
    if ($von <= 0 || $bis <= $von) { return $aus; }
    $vorschau = rn_haus_wert($c, 'strompreis', 'haus/strompreis/vorschau');
    $ab = rn_haus_wert($c, 'strompreis', 'haus/strompreis/ab');
    $raster = rn_haus_wert($c, 'strompreis', 'haus/strompreis/raster');
    $minute = $bis - $bis % 60;
    for ($i = 0; $i < 120; $i++) {
        $pt = $von + $i * 60 + 30;
        if ($pt >= $minute) { break; }
        $aus[] = array('t' => $pt, 'preis' => rn_preis_zum_zeitpunkt($vorschau, $ab, $raster, '', false, $pt), 'nachtrag' => 1);
    }
    $aus[] = array('t' => $bis, 'preis' => rn_preis_zum_zeitpunkt($vorschau, $ab, $raster,
        rn_haus_wert($c, 'strompreis', 'haus/strompreis/aktuell'), rn_haus_frisch($c, 'strompreis', $bis), $bis), 'nachtrag' => 1);
    return $aus;
}

/** Beim Ladeende: Kosten rechnen, Eintrag in ladungen.json, Mitschriften nach *_letzte.json umbenennen. */
function rn_ladung_abschluss(array $cfg, $nr, array $d)
{
    $nrs = ((int) $nr > 1) ? (string) (int) $nr : '';
    $aus = array('fz' => (int) $nr, 'beginn' => (int) $d['beginn_t'], 'ende' => time(),
                 'kwh' => -1, 'kosten_eur' => -1, 'preis_ct' => -1, 'abdeckung' => 0, 'kosten_grund' => 'aus');
    $preise = rn_json_lesen(rn_ladung_datei($nr, 'preise'));
    if ((int) $d['zuhause'] === -1) {
        $aus['kosten_grund'] = 'heimzone_fehlt';
    } elseif ((int) $d['zuhause'] !== 1) {
        $aus['kosten_grund'] = 'unterwegs';
    } else {
        list($k, $e, $pc, $ab, $g) = rn_ladung_kosten($d['stand'],
            isset($preise['proben']) && is_array($preise['proben']) ? $preise['proben'] : array(),
            isset($cfg['akku_kwh' . $nrs]) ? $cfg['akku_kwh' . $nrs] : '');
        $aus['kwh'] = $k;
        $aus['kosten_eur'] = $e;
        $aus['preis_ct'] = $pc;
        $aus['abdeckung'] = $ab;
        $aus['kosten_grund'] = $g;
    }
    $liste = rn_json_lesen(rn_paths()['datadir'] . '/ladungen.json');
    $l = isset($liste['liste']) && is_array($liste['liste']) ? $liste['liste'] : array();
    $l[] = $aus;
    rn_json_schreiben(rn_paths()['datadir'] . '/ladungen.json', array('liste' => array_slice($l, -200)));
    rn_json_schreiben(rn_ladung_datei($nr, 'laufend'), $d);
    foreach (array('laufend', 'preise') as $art) {
        $q = rn_ladung_datei($nr, $art);
        if (is_file($q)) { @rename($q, substr($q, 0, -5) . '_letzte.json'); }
    }
    return $aus;
}

/** Je Takt waehrend einer Ladung: den Preis dieser Minute mitschreiben (nur mit Haken und laufender Ladung). */
function rn_ladung_preis_probe(array $cfg, $nr, array $c, $jetzt)
{
    if (!rn_ja($cfg, 'haus_strompreis_ein') || !is_file(rn_ladung_datei($nr, 'laufend'))) {
        return false;
    }
    $f = rn_ladung_datei($nr, 'preise');
    $d = rn_json_lesen($f);
    $l = isset($d['proben']) && is_array($d['proben']) ? $d['proben'] : array();
    $t = (int) $jetzt;
    $letzte = $l ? end($l) : null;
    if (is_array($letzte) && (int) floor((int) $letzte['t'] / 60) === (int) floor($t / 60)) {
        return false;
    }
    $l[] = array('t' => $t, 'preis' => rn_preis_zum_zeitpunkt(
        rn_haus_wert($c, 'strompreis', 'haus/strompreis/vorschau'),
        rn_haus_wert($c, 'strompreis', 'haus/strompreis/ab'),
        rn_haus_wert($c, 'strompreis', 'haus/strompreis/raster'),
        rn_haus_wert($c, 'strompreis', 'haus/strompreis/aktuell'),
        rn_haus_frisch($c, 'strompreis', $t), $t));
    $d['proben'] = array_slice($l, -2000);
    return rn_json_schreiben($f, $d);
}

/** Die letzte eingetragene Ladung eines Fahrzeugs (je -1 ohne Aussage). */
function rn_ladung_letzte($nr)
{
    $aus = array('kosten_eur' => -1, 'kwh' => -1, 'preis_ct' => -1, 'kosten_grund' => '');
    $d = rn_json_lesen(rn_paths(false)['datadir'] . '/ladungen.json');
    $l = isset($d['liste']) && is_array($d['liste']) ? $d['liste'] : array();
    for ($i = count($l) - 1; $i >= 0; $i--) {
        if (is_array($l[$i]) && isset($l[$i]['fz']) && (int) $l[$i]['fz'] === (int) $nr) {
            foreach (array_keys($aus) as $k) {
                if (isset($l[$i][$k])) { $aus[$k] = $l[$i][$k]; }
            }
            break;
        }
    }
    return $aus;
}

/* ---------------- 3.1: Heimzone aus GPS (E-E1 B) ---------------- */

/** Entfernung zweier Punkte in Metern (Haversine; Abschrift mg_entfernung()). Rein. */
function rn_entfernung($b1, $l1, $b2, $l2)
{
    $r = 6371000.0;
    $p1 = deg2rad((float) $b1);
    $p2 = deg2rad((float) $b2);
    $dp = deg2rad((float) $b2 - (float) $b1);
    $dl = deg2rad((float) $l2 - (float) $l1);
    $a = sin($dp / 2) * sin($dp / 2) + cos($p1) * cos($p2) * sin($dl / 2) * sin($dl / 2);
    return 2 * $r * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
}

/**
 * Ist das Fahrzeug zu Hause? (Rein) Rueckgabe array(zuhause 1|0|-1, entfernung_km|-1).
 * -1/-1: Haken aus, Heimpunkt leer, Generation 1, Position leer oder 0/0, oder
 * die Position ist nicht frisch. Frisch nach E-E1 B (Hausherr 29.10.): die
 * GPS-Zeit liegt hoechstens RN_HEIM_FRISCH vor dem Zeitstempel der
 * Fahrzeugantwort - solange das Auto nichts Neues meldet, hat es sich nicht
 * bewegt. Eine GPS-Zeit NACH der Fahrzeugantwort gilt (gleiches Ereignis,
 * andere Uhr). Zu Hause: Entfernung <= max(20, Radius).
 */
function rn_zuhause($cfg, $breite, $laenge, $gps_unix, $fz_unix, $ph)
{
    $nichts = array(-1, -1);
    if (!rn_ja($cfg, 'heim_ein') || (string) $ph === '1') { return $nichts; }
    $hb = isset($cfg['heim_breite']) ? trim((string) $cfg['heim_breite']) : '';
    $hl = isset($cfg['heim_laenge']) ? trim((string) $cfg['heim_laenge']) : '';
    if ($hb === '' || $hl === '' || !is_numeric($hb) || !is_numeric($hl)) { return $nichts; }
    if (!is_numeric($breite) || !is_numeric($laenge)) { return $nichts; }
    if ((float) $breite == 0.0 && (float) $laenge == 0.0) { return $nichts; }
    if (!is_numeric($gps_unix) || !is_numeric($fz_unix) || (int) $gps_unix <= 0 || (int) $fz_unix <= 0) {
        return $nichts;
    }
    if ((int) $fz_unix - (int) $gps_unix > RN_HEIM_FRISCH) { return $nichts; }
    $m = rn_entfernung($hb, $hl, $breite, $laenge);
    $radius = max(20, isset($cfg['heim_radius']) ? (int) $cfg['heim_radius'] : 150);
    return array($m <= $radius ? 1 : 0, round($m / 1000, 2));
}

/* ---------------- 3.3: Meldungen ueber die SignalBot-Bruecke ---------------- */

/** Das Thema der SignalBot-Bruecke fuer diesen Absender, '' bei ungueltigem Ordner oder Praefix. Rein. */
function rn_signal_thema($praefix, $ordner)
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string) $praefix)) { return ''; }
    if (!preg_match('/^[a-z0-9_]{1,40}$/', (string) $ordner)) { return ''; }
    return $praefix . '/befehl/plugin/' . $ordner;
}

/** Die Nutzlast {"text", "dringend"} (sg_plugin_nachricht() in SignalBot 0.9.30), hoechstens RN_SIGNAL_MAX Byte. Rein. */
function rn_signal_nutzlast($text, $dringend)
{
    $t = (string) $text;
    if (function_exists('mb_check_encoding') && !mb_check_encoding($t, 'UTF-8')) {
        $t = function_exists('mb_convert_encoding') ? (string) mb_convert_encoding($t, 'UTF-8', 'ISO-8859-1') : '';
    }
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $d = $dringend ? 1 : 0;
    $js = json_encode(array('text' => $t, 'dringend' => $d), $flags);
    while ($js !== false && strlen($js) > RN_SIGNAL_MAX && $t !== '') {
        $t = function_exists('mb_substr') ? mb_substr($t, 0, max(0, (int) (mb_strlen($t, 'UTF-8') * 0.9) - 1), 'UTF-8')
                                          : substr($t, 0, (int) (strlen($t) * 0.9));
        $js = json_encode(array('text' => $t . '…', 'dringend' => $d), $flags);
    }
    return $js === false ? '' : $js;
}

/** Eine Meldung an SignalBot ueber die phpMQTT-Verbindung des Laufs, retain 0. Rueckgabe gesendet|kein_broker|ungueltig. */
function rn_signal_senden($mqtt, array $cfg, $text)
{
    $thema = rn_signal_thema((string) $cfg['signal_praefix'], rn_paths(false)['plugin']);
    if ($thema === '') {
        rn_melden('WARN', 'SignalBot-Meldung nicht gesendet - der Ordnername des Plugins oder das Praefix passt '
            . 'nicht auf [a-z0-9_]{1,40}.');
        return 'ungueltig';
    }
    if ($mqtt === null) {
        rn_melden('WARN', 'SignalBot-Meldung nicht gesendet - kein Broker.');
        return 'kein_broker';
    }
    $mqtt->publish($thema, rn_signal_nutzlast($text, 0), 0, 0);
    return 'gesendet';
}

/**
 * Testmeldung an SignalBot (Reiter Test): ein fester Satz ueber mosquitto_pub,
 * die Quittung bis 5 s mit mosquitto_sub - beide mit der Optionsdatei.
 * Rueckgabe array(angenommen|abgelehnt|keine_antwort|nicht_moeglich, Text).
 */
function rn_signal_testmeldung(array $cfg)
{
    $thema = rn_signal_thema((string) $cfg['signal_praefix'], rn_paths(false)['plugin']);
    $sub = rn_programm('mosquitto_sub');
    $pub = rn_programm('mosquitto_pub');
    $z = rn_mqtt_zugang();
    if ($thema === '' || $sub === '' || $pub === '' || !is_array($z) || !function_exists('proc_open')) {
        return array('nicht_moeglich', rn_t('SIGNAL.T_NICHT_MOEGLICH'));
    }
    $host = ((string) $z['host'] === 'localhost') ? '127.0.0.1' : (string) $z['host'];
    $port = (string) ((int) $z['port'] > 0 ? (int) $z['port'] : 1883);
    $env = rn_proc_umgebung();
    $rohr = array();
    $h = @proc_open(array($sub, '-h', $host, '-p', $port, '-C', '1', '-W', '5', '-t', $thema . '/quittung'),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')),
        $rohr, null, $env);
    if (!is_resource($h)) {
        return array('nicht_moeglich', rn_t('SIGNAL.T_NICHT_MOEGLICH'));
    }
    usleep(300000);
    $r2 = array();
    $p = @proc_open(array($pub, '-h', $host, '-p', $port, '-t', $thema, '-m',
        rn_signal_nutzlast(rn_t('SIGNAL.TESTSATZ'), 0)),
        array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
        $r2, null, $env);
    if (is_resource($p)) { proc_close($p); }
    $text = (string) stream_get_contents($rohr[1]);
    fclose($rohr[1]);
    proc_close($h);
    $text = trim($text);
    if ($text === '') {
        return array('keine_antwort', rn_t('SIGNAL.T_KEINE_ANTWORT'));
    }
    if (stripos($text, 'abgelehnt') === 0 || stripos($text, 'ABSENDER_NICHT_FREIGEGEBEN') !== false) {
        return array('abgelehnt', sprintf(rn_t('SIGNAL.T_ABGELEHNT'), substr($text, 0, 120)));
    }
    return array('angenommen', rn_t('SIGNAL.T_ANGENOMMEN'));
}

/**
 * V7-i (F4): die Protokollzeile zu den Meldewegen eines Anlasses (Rein).
 * $erg: 'mail' => true|false|null (null = nicht gewaehlt), 'signal' =>
 * gesendet|kein_broker|ungueltig|null, 'befehl' => true|false|null (exec).
 * Rueckgabe array(Stufe INFO|WARN, Text).
 */
function rn_meldewege_text(array $erg)
{
    $teile = array();
    $warn = false;
    if (array_key_exists('mail', $erg) && $erg['mail'] !== null) {
        if ($erg['mail'] === true) {
            $teile[] = 'Mail uebergeben';
        } else {
            $teile[] = 'Mail NICHT uebergeben - sendmail_path bzw. msmtp pruefen';
            $warn = true;
        }
    }
    if (array_key_exists('signal', $erg) && $erg['signal'] !== null) {
        if ($erg['signal'] === 'gesendet') {
            $teile[] = 'SignalBot gesendet';
        } else {
            $teile[] = 'SignalBot NICHT gesendet (' . $erg['signal'] . ')';
            $warn = true;
        }
    }
    if (array_key_exists('befehl', $erg) && $erg['befehl'] !== null) {
        $teile[] = $erg['befehl'] ? 'Befehl ausgefuehrt' : 'Befehl NICHT ausgefuehrt (siehe Zeile davor)';
        if (!$erg['befehl']) { $warn = true; }
    }
    return array($warn ? 'WARN' : 'INFO', $teile ? implode(', ', $teile) : 'kein Meldeweg gewaehlt');
}

/* ---------------- V3 und E-E3: Aufzeichnung ---------------- */

/** Spalten der Aufzeichnung, die eine neue Zeile ausloesen (Kopfzeile abruf.php). */
function rn_csv_vergleichsspalten()
{
    return array(0, 1, 2, 3, 5, 6, 7);   // Date, Time, Mileage, Battery level, Range, Cable, Charging status
}

/** Neue Zeile? Wahr, wenn die letzte fehlt oder sich Date/Time/Kilometer/Ladestand/Reichweite/Kabel/Ladestatus unterscheiden. Rein. */
function rn_csv_neu(array $letzte, array $neu)
{
    if (!$letzte) { return true; }
    foreach (rn_csv_vergleichsspalten() as $i) {
        $a = isset($letzte[$i]) ? trim((string) $letzte[$i]) : '';
        $b = isset($neu[$i]) ? trim((string) $neu[$i]) : '';
        if ($a !== $b) { return true; }
    }
    return false;
}

/** Die letzte nicht leere Datenzeile als Feld - liest hoechstens die letzten 4096 Byte. array() = keine. */
function rn_csv_letzte_zeile($datei)
{
    /* stat() merkt sich PHP je Prozess - nach einem Anhaengen im selben Lauf
     * saehe filesize() sonst die alte Groesse (unter 7.4 gemessen, Kernprobe P-V3-1). */
    clearstatcache(true, $datei);
    if (!is_file($datei)) { return array(); }
    $fh = @fopen($datei, 'rb');
    if ($fh === false) { return array(); }
    $gr = (int) @filesize($datei);
    $ab = max(0, $gr - 4096);
    fseek($fh, $ab);
    $t = (string) fread($fh, 4096);
    fclose($fh);
    $zeilen = preg_split('/\r?\n/', $t);
    for ($i = count($zeilen) - 1; $i >= 0; $i--) {
        $z = trim($zeilen[$i]);
        if ($z === '' || ($i === 0 && $ab > 0)) { continue; }
        if (strpos($z, 'Date;') === 0) { return array(); }
        return explode(';', $z);
    }
    return array();
}

/**
 * Die Zeilen der letzten 24 h (Rein): Date+Time (Europe/Berlin) hoechstens
 * 86 400 s vor $jetzt und nicht danach. Zeilen ohne gueltige Zeit werden
 * uebergangen. Rueckgabe Liste array(ts, soc, reichweite).
 */
function rn_csv_24h(array $zeilen, $jetzt)
{
    $tz = timezone_open('Europe/Berlin');
    $aus = array();
    foreach ($zeilen as $z) {
        $sp = is_array($z) ? $z : explode(';', (string) $z);
        if (count($sp) < 6) { continue; }
        $d = date_create_from_format('!d.m.Y H:i', trim((string) $sp[0]) . ' ' . trim((string) $sp[1]), $tz);
        if ($d === false) { continue; }
        $fehler = date_get_last_errors();
        if (is_array($fehler) && ($fehler['warning_count'] > 0 || $fehler['error_count'] > 0)) { continue; }
        $ts = (int) date_timestamp_get($d);
        if ($ts > (int) $jetzt || (int) $jetzt - $ts > 86400) { continue; }
        $aus[] = array($ts, (float) $sp[3], (float) $sp[5]);
    }
    usort($aus, function ($a, $b) { return $a[0] - $b[0]; });
    /* Gleiche Punkte hintereinander (Zeilen aus 2.1.19 mit demselben Stand) zaehlen als einer. */
    $eins = array();
    foreach ($aus as $pt) {
        $vor = $eins ? $eins[count($eins) - 1] : null;
        if ($vor !== null && $vor[0] === $pt[0] && $vor[1] === $pt[1] && $vor[2] === $pt[2]) { continue; }
        $eins[] = $pt;
    }
    return $eins;
}

/**
 * E-E3 (Hausherr 29.10., abweichend): aufeinanderfolgende gleiche Zeilen
 * zusammenfassen - gleicher Zeitstempel und gleiche Werte, also byte-gleiche
 * Zeilen. Die Kopfzeile bleibt; keine andere Zeile wird geaendert, die
 * Zeilenenden bleiben. Rein. Rueckgabe array(neuer Text, entfernte Zeilen).
 */
function rn_csv_aufraeumen($text)
{
    $text = (string) $text;
    if ($text === '') { return array('', 0); }
    $teile = explode("\n", $text);
    $ende = (substr($text, -1) === "\n");
    if ($ende) { array_pop($teile); }
    $aus = array();
    $weg = 0;
    $vorher = null;
    foreach ($teile as $i => $z) {
        $vergleich = rtrim($z, "\r");
        if ($i > 0 && $vorher !== null && $vergleich !== '' && $vergleich === $vorher
            && strpos($vergleich, 'Date;') !== 0) {
            $weg++;
            continue;
        }
        $aus[] = $z;
        $vorher = $vergleich;
    }
    return array(implode("\n", $aus) . ($ende ? "\n" : ''), $weg);
}

/* ---------------- V7-e: Ladehistorie ---------------- */

/** "keine Ladevorgaenge" ist eine INFO nur bei HTTP 2xx oder einem nachweislich unbekannten Endpunkt; sonst WARN. Rein. */
function rn_history_stufe($code, $hat_charges)
{
    $c = (int) $code;
    if ($hat_charges) { return 'INFO'; }
    if (($c >= 200 && $c < 300) || rn_endpunkt_unbekannt($c)) { return 'INFO'; }
    return 'WARN';
}

/* ---------------- 2.6: Healthcheck ---------------- */

/**
 * Das Urteil des Healthchecks (Rein; Regeln/03 :73-78, Regeln/04 :809).
 * $f: eingerichtet (bool), rechte ('0600' oder ''), zaehler_alter_s (int|-1),
 * erfolg_alter_s (Liste je Fahrzeug, -1 = noch nie), takt_min (cron_ncs),
 * v2 (array(lage, Text) aus rn_v2_lage_urteil()), bruecke_soll (bool),
 * bruecke_laeuft (bool|null). Status 3 Fehler, 4 Warnung, 5 in Ordnung,
 * 6 Hinweis. Rueckgabe array(status, Text). Nie auf einen einzelnen Abruf.
 */
function rn_gesund_urteil(array $f)
{
    $f += array('eingerichtet' => false, 'rechte' => '', 'zaehler_alter_s' => -1, 'erfolg_alter_s' => array(),
                'takt_min' => 5, 'v2' => array(null, ''), 'bruecke_soll' => false, 'bruecke_laeuft' => null);
    if (!$f['eingerichtet']) {
        return array(6, 'nicht eingerichtet - Zugangsdaten im Reiter Einstellungen eintragen');
    }
    $befunde = array();
    $status = 5;
    $setze = function ($s, $t) use (&$status, &$befunde) {
        if ($s < $status) { $status = $s; }
        $befunde[] = $t;
    };
    if ($f['rechte'] !== '' && $f['rechte'] !== '0600') {
        $setze(4, 'config.php hat die Rechte ' . $f['rechte'] . ' statt 0600');
    }
    if ((int) $f['zaehler_alter_s'] < 0 || (int) $f['zaehler_alter_s'] > 600) {
        $setze(3, 'Cron laeuft nicht (Lebenszeichen ' . ((int) $f['zaehler_alter_s'] < 0 ? 'fehlt'
            : 'seit ' . (int) round($f['zaehler_alter_s'] / 60) . ' min still') . ')');
    }
    $grenze = max(15, 3 * max(1, (int) $f['takt_min'])) * 60;
    foreach ($f['erfolg_alter_s'] as $name => $a) {
        if ((int) $a < 0) {
            $setze(4, $name . ': noch kein gelungener Abruf');
        } elseif ((int) $a > 3600) {
            $setze(3, $name . ': letzter gelungener Abruf vor ' . (int) round($a / 60) . ' min');
        } elseif ((int) $a > $grenze) {
            $setze(4, $name . ': letzter gelungener Abruf vor ' . (int) round($a / 60) . ' min');
        }
    }
    if (is_array($f['v2']) && $f['v2'][0] === 0) {
        $setze(4, 'Gateway V2: ' . $f['v2'][1]);
    }
    if ($f['bruecke_soll'] && $f['bruecke_laeuft'] === false) {
        $setze(4, 'Befehle ueber MQTT eingeschaltet, aber die Befehlsbruecke steht');
    }
    return array($status, $befunde ? implode('; ', $befunde) : 'in Ordnung');
}

/** Die Fakten des Healthchecks - nur lesend, kein Netz, kein Broker, keine Anmeldung. */
function rn_gesund_fakten(array $cfg)
{
    $p = rn_paths(false);
    $f = array();
    $f['eingerichtet'] = (string) $cfg['username'] !== '' && (string) $cfg['password'] !== '';
    $f['rechte'] = is_file($p['config']) ? substr(sprintf('%o', fileperms($p['config'])), -4) : '';
    $z = $p['datadir'] . '/zaehler';
    $f['zaehler_alter_s'] = is_file($z) ? max(0, time() - (int) filemtime($z)) : -1;
    $f['erfolg_alter_s'] = array();
    foreach (rn_fahrzeuge($cfg) as $fz) {
        if ($fz['vin'] === '') { continue; }
        $s = is_readable($fz['session']) ? explode('|', (string) @file_get_contents($fz['session'])) : array();
        $st = isset($s[25]) ? trim($s[25]) : '';
        $d = preg_match('/^[0-9]{12}$/', $st) ? date_create_from_format('YmdHi', $st) : false;
        $f['erfolg_alter_s'][$fz['name']] = $d ? max(0, time() - date_timestamp_get($d)) : -1;
    }
    $f['takt_min'] = (int) $cfg['cron_ncs'];
    $f['v2'] = rn_v2_haken_lage($cfg);
    $f['bruecke_soll'] = rn_ja($cfg, 'befehle_mqtt_ein');
    $f['bruecke_laeuft'] = null;
    if ($f['bruecke_soll'] && rn_bruecke_laden()) {
        $l = rn_bruecke_lage();
        $f['bruecke_laeuft'] = $l[0] === true;
    }
    return $f;
}

/* ---------------- 2.4: der Minutentakt (abruf.php takt) ---------------- */

/** Zwischenstueck zu notify_ext() (bin/rn_notify.php, Form von MG/BatterieBMS). array(abgelegt, Ausgabe). */
function rn_benachrichtigen($schwere, $text)
{
    $p = rn_paths(false);
    $skript = ($p['home'] !== '') ? $p['home'] . '/bin/plugins/' . $p['plugin'] . '/rn_notify.php'
                                  : dirname(dirname(__DIR__)) . '/bin/rn_notify.php';
    if (!is_file($skript) || !function_exists('proc_open')) {
        return array(false, 'rn_notify.php fehlt');
    }
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $cmd = array($php, $skript, (string) (int) $schwere, (string) $text, (string) $p['plugin']);
    $rohr = array();
    $pr = @proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'),
                                 2 => array('pipe', 'w')), $rohr);
    if (!is_resource($pr)) {
        return array(false, 'proc_open gescheitert');
    }
    $aus = (string) stream_get_contents($rohr[1]) . ' ' . (string) stream_get_contents($rohr[2]);
    fclose($rohr[1]);
    fclose($rohr[2]);
    return array(proc_close($pr) === 0, substr(trim(preg_replace('/\s+/', ' ', $aus)), 0, 200));
}

/** Die Sitzungszeile eines Fahrzeugs als Feld (32 Felder), ohne etwas anzulegen. */
function rn_session_feld($nr)
{
    $f = rn_fahrzeug($nr, rn_config_read(false));
    $roh = ($f && is_readable($f['session'])) ? (string) @file_get_contents($f['session']) : '';
    return array_pad($roh === '' ? array() : explode('|', $roh), 32, '');
}

/**
 * Der Takt der Vorklimatisierung fuer EIN Fahrzeug (3.2). Ruft nur, was der
 * Ablauf verlangt; Rueckgabe der neue Wert des Felds Vorklima.
 */
function rn_vorklima_takt(array $cfg, array $f, array $c, $jetzt, array &$merk)
{
    $nr = (string) (int) $f['nr'];
    $m = isset($merk['vorklima'][$nr]) && is_array($merk['vorklima'][$nr]) ? $merk['vorklima'][$nr] : array();
    $wert_vorher = rn_vorklima_wert($m, $jetzt);
    if (!rn_ja($cfg, 'vorklima_ein') || !rn_ja($cfg, 'steuerung_ein') || !rn_ja($cfg, 'haus_abfahrt_ein')
        || (string) $cfg['username'] === '' || (string) $cfg['password'] === '' || (string) $f['vin'] === '') {
        return 0;
    }
    if (!rn_haus_frisch($c, 'abfahrt', $jetzt)) {
        return rn_vorklima_wert($m, $jetzt);           // 2.4 Rueckfall: schweigt -> nichts, nie termin.php
    }
    $v = trim(rn_haus_wert($c, 'abfahrt', 'haus/abfahrt/in_min'));
    if (!preg_match('/^-?[0-9]{1,6}\z/', $v) || (int) $v < 0) {
        return rn_vorklima_wert($m, $jetzt);           // -1 / '-' : keine Abfahrt bekannt
    }
    $in = (int) $v;
    $vorlauf = (int) $cfg['vorklima_vorlauf'];
    if ($in < 1 || $in > max(1, $vorlauf)) {
        return rn_vorklima_wert($m, $jetzt);
    }
    $k = rn_vorklima_kennung($jetzt, $in);
    $neu = !isset($m['kennung']) || abs($k - (int) $m['kennung']) > 300;
    /* Versuch 2 war ein Abruf: zeigt der Stand danach HvAcStatus on, laeuft die
     * Klimatisierung schon - erledigt (Feld 31, Fahrzeugzeit Feld 30). */
    if (!$neu && (int) ($m['versuche'] ?? 0) === 2 && empty($m['erfolg'])) {
        $s = rn_session_feld($f['nr']);
        if ((string) $s[31] === 'on' && (int) $s[30] >= (int) $m['letzter'] - 60) {
            $m['erfolg'] = 1;
            rn_melden('INFO', 'Vorklimatisierung ' . $f['name'] . ': der Abruf zeigt die Klimatisierung schon an - erledigt.');
        }
    }
    /* Anwesenheit (Haken an, Quelle frisch, live 0): warten. Schweigt sie, gilt sie als nicht gesetzt. */
    if (rn_ja($cfg, 'vorklima_anwesend_ein') && rn_haus_frisch($c, 'anwesenheit', $jetzt)
        && trim(rn_haus_wert($c, 'anwesenheit', 'haus/anwesenheit/jemand')) === '0') {
        if ($neu) {
            $m = array('kennung' => $k, 'versuche' => 0, 'letzter' => 0, 'erfolg' => 0,
                       'aufgefrischt' => 0, 'aufgegeben' => 0, 'mild' => 0);
        }
        if (empty($m['warten_anwesend'])) {
            rn_melden('INFO', 'Vorklimatisierung ' . $f['name'] . ': niemand zu Hause (haus/anwesenheit/jemand = 0) - sie wartet.');
        }
        $m['warten_anwesend'] = 1;
        $merk['vorklima'][$nr] = $m;
        return rn_vorklima_wert($m, $jetzt);
    }
    if (!empty($m['warten_anwesend'])) { unset($m['warten_anwesend']); }
    /* Die Wettergrenze: einmal je Abfahrtskennung. */
    if ($neu || !isset($m['wetter'])) {
        if ($neu) {
            $m = array('kennung' => $k, 'versuche' => 0, 'letzter' => 0, 'erfolg' => 0,
                       'aufgefrischt' => 0, 'aufgegeben' => 0, 'mild' => 0);
        }
        list($lw, $la) = rn_aussentemp_lesen($jetzt);
        $s = rn_session_feld($f['nr']);
        $fz_alter = ((int) $s[30] > 0 && (string) $s[28] !== '') ? $jetzt - (int) $s[30] : -1;
        list($urteil, $aw, $aq) = rn_vorklima_wetter_genau($cfg, $lw, $la, $s[28] === '' ? null : $s[28], $fz_alter);
        $m['wetter'] = $urteil;
        if ($urteil === 'mild') {
            $m['mild'] = 1;
            rn_melden('INFO', 'Vorklimatisierung ' . $f['name'] . ': mildes Wetter (' . $aw . ' Grad, Quelle '
                . $aq . ') - keine Vorklimatisierung fuer diese Abfahrt.');
        }
    }
    list($aktion, $m) = rn_vorklima_schritt($m, $in, $jetzt, $vorlauf, null);
    if ($aktion === 'senden') {
        $erg = rn_befehl_annehmen('acnow', (int) $f['nr'], 'automatik', $cfg);
        if ((int) $erg[0] === 1) { $m['erfolg'] = 1; }
        rn_melden((int) $erg[0] === 1 ? 'INFO' : 'WARN', 'Vorklimatisierung ' . $f['name'] . ': Versuch '
            . (int) $m['versuche'] . ' (Abfahrt in ' . $in . ' min) -> ' . $erg[1]);
    } elseif ($aktion === 'abruf_und_senden') {
        $r = rn_abruf_anstossen((int) $f['nr']);
        rn_melden('INFO', 'Vorklimatisierung ' . $f['name'] . ': Versuch 2 - Abruf statt Senden (' . $r . ').');
    } elseif ($aktion === 'aufgeben') {
        $satz = sprintf(rn_t('VORKLIMA.GESCHEITERT'), $f['name']);
        rn_melden('WARN', 'Vorklimatisierung ' . $f['name'] . ' gescheitert - aufgegeben nach '
            . RN_VORKLIMA_VERSUCHE . ' Versuchen (hoechstens zwei acnow je Abfahrt).');
        rn_benachrichtigen(3, $satz);
        $m['signal_offen'] = (rn_ja($cfg, 'signal_bl') || rn_ja($cfg, 'signal_csf')) ? 1 : 0;
    }
    $merk['vorklima'][$nr] = $m;
    unset($wert_vorher);
    return rn_vorklima_wert($m, $jetzt);
}

/**
 * Der Minutentakt (abruf.php takt, aus cron.01min): KEIN Renault-Aufruf ausser
 * einer faelligen Vorklimatisierung, kein Abruf ausser angestossenen.
 * (1) bb_waechter(); (2) Leser starten; (3) Vorklimatisierung, Ankunft,
 * Preisproben je Fahrzeug; (4) Ende. Ist nichts eingeschaltet, endet der Lauf
 * nach dem Lesen der Konfiguration - kein Protokoll.
 * Rueckgabe Feld mit dem, was geschah (fuer Proben).
 */
function rn_takt_lauf($skript)
{
    $aus = array('waechter' => '', 'leser' => '', 'vorklima' => array(), 'ankunft' => array(), 'preis' => 0);
    $cfg = rn_config_read(false);
    $bruecke = rn_ja($cfg, 'befehle_mqtt_ein') || is_dir(rn_paths(false)['datadir'] . '/befehle');
    if ($bruecke && rn_bruecke_laden()) {
        bb_waechter();
        $aus['waechter'] = 'gerufen';
    }
    $themen = rn_haus_lesethemen($cfg);
    $vorklima = rn_ja($cfg, 'vorklima_ein');
    if (!$themen && !$vorklima) {
        if (is_file(rn_haus_quellen_datei())) { @unlink(rn_haus_quellen_datei()); }
        return $aus;
    }
    $aus['leser'] = rn_haus_abnehmer_starten($cfg, $skript);
    $jetzt = time();
    $c = rn_haus_quellen();
    $merk = rn_json_lesen(rn_vorklima_datei());
    $alt = isset($merk['wert']) && is_array($merk['wert']) ? $merk['wert'] : array();
    $senden = array();
    $nummern = rn_vorklima_nummern($cfg);
    foreach (rn_fahrzeuge($cfg) as $f) {
        $nr = (int) $f['nr'];
        if ($vorklima && in_array($nr, $nummern, true)) {
            $w = rn_vorklima_takt($cfg, $f, $c, $jetzt, $merk);
            $aus['vorklima'][$nr] = $w;
            if (!isset($alt[(string) $nr]) || (int) $alt[(string) $nr] !== $w) {
                $senden[$f['name']] = $w;
            }
            $merk['wert'][(string) $nr] = $w;
        }
        if (rn_ladung_preis_probe($cfg, $nr, $c, $jetzt)) { $aus['preis']++; }
    }
    /* Ankunft (E-E2): je Flanke genau einmal, gedrosselt. */
    if (rn_ankunft_quellen($cfg) && (string) $cfg['username'] !== '' && (string) $cfg['password'] !== '') {
        $ank = rn_json_lesen(rn_paths()['datadir'] . '/ankunft.json');
        foreach (rn_ankunft_quellen($cfg) as $nr => $t) {
            $fl = isset($c['ankunft'][(string) $nr]['nr']) ? (int) $c['ankunft'][(string) $nr]['nr'] : 0;
            $e = isset($ank[(string) $nr]) && is_array($ank[(string) $nr]) ? $ank[(string) $nr] : array('aus' => $fl, 'letzte' => 0);
            if (!isset($ank[(string) $nr])) { $ank[(string) $nr] = $e; continue; }   // erster Lauf: nichts nachholen
            $s = rn_session_feld($nr);
            $d = preg_match('/^[0-9]{12}$/', (string) $s[25]) ? date_create_from_format('YmdHi', (string) $s[25]) : false;
            $alter = $d ? $jetzt - date_timestamp_get($d) : -1;
            list($was, $neu) = rn_ankunft_entscheiden($fl, (int) $e['aus'], (int) $e['letzte'], $jetzt, $alter);
            $e['aus'] = $neu;
            if ($was === 'abfragen') {
                $e['letzte'] = $jetzt;
                $r = rn_abruf_anstossen($nr);
                rn_melden('INFO', 'Ankunft (' . $t . ' wechselte auf 1): Fahrzeug ' . $nr . ' wird abgefragt (' . $r . ').');
            } elseif ($was === 'gedrosselt') {
                rn_melden('INFO', 'Ankunft (' . $t . '): Fahrzeug ' . $nr . ' nicht abgefragt - hoechstens einmal je '
                    . (int) (RN_ANKUNFT_ABSTAND / 60) . ' min.');
            }
            $aus['ankunft'][$nr] = $was;
            $ank[(string) $nr] = $e;
        }
        rn_json_schreiben(rn_paths()['datadir'] . '/ankunft.json', $ank);
    }
    if ($vorklima) {
        rn_json_schreiben(rn_vorklima_datei(), $merk);
    }
    /* Vorklima bei jedem Wechsel senden - eine Sitzung je Takt hoechstens, nur mit Broker. */
    if ($senden && class_exists('Bluerhinos\\phpMQTT') && function_exists('mqtt_connectiondetails')) {
        $b = mqtt_connectiondetails();
        if (is_array($b) && !empty($b['brokerhost'])) {
            $mq = new Bluerhinos\phpMQTT($b['brokerhost'], $b['brokerport'], uniqid('rn_takt_'));
            if ($mq->connect(true, null, $b['brokeruser'], $b['brokerpass'])) {
                foreach ($senden as $name => $w) {
                    rn_mqtt_senden($mq, $name, 'Vorklima', $w);
                }
                /* SignalBot: "Vorklimatisierung gescheitert" (3.3), einmal je Kennung. */
                foreach (rn_fahrzeuge($cfg) as $f) {
                    $nr = (string) (int) $f['nr'];
                    if (!empty($merk['vorklima'][$nr]['signal_offen'])) {
                        rn_signal_senden($mq, $cfg, sprintf(rn_t('VORKLIMA.GESCHEITERT'), $f['name']));
                        $merk['vorklima'][$nr]['signal_offen'] = 0;
                        rn_json_schreiben(rn_vorklima_datei(), $merk);
                    }
                }
                $mq->close();
            }
        }
    }
    return $aus;
}
