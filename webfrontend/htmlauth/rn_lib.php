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
 * 2.1.16). Liegt neben dieser Datei; sie schuetzt sich selbst gegen doppeltes Laden. */
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
    /* Fahrzeug 2 bis 4. Bewusst durchnummerierte Einzelschluessel und kein
     * verschachteltes Feld: config.php wird mit var_export() je Schluessel
     * geschrieben, und rn_config_read() uebernimmt ausdruecklich nur
     * Skalare. Ein Feld haette beides umgebaut - fuer nichts. */
    for ($i = 2; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $v['zoename' . $i] = '';
        $v['vin' . $i]     = '';
        $v['zoeph' . $i]   = '2';
    }
    return $v;
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
    $leer  = array('0000', '', '', '');
    if (!is_readable($datei)) {
        /* Uebergang von 2.0.6: dort standen die drei Werte in den Feldern
         * 0 bis 2 der Sitzungsdatei. Wer aktualisiert, soll sich nicht
         * neu anmelden muessen. */
        $alt = rn_session(1);
        if (is_array($alt) && isset($alt[2]) && $alt[2] !== '') {
            return array($alt[0], $alt[1], $alt[2], '');
        }
        return $leer;
    }
    $roh = (string) @file_get_contents($datei);
    if ($roh === '') { return $leer; }
    $f = explode('|', $roh);
    return array_pad(array_slice($f, 0, 4), 4, '');
}

function rn_anmeldung_schreiben($a)
{
    $datei = rn_paths()['anmeldung'];
    $tmp   = $datei . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    @chmod($tmp, 0600);                       // enthaelt ein gueltiges JWT
    ftruncate($fh, 0);
    fwrite($fh, implode('|', array_slice(array_pad($a, 4, ''), 0, 4)));
    fclose($fh);
    if (!@rename($tmp, $datei)) { @unlink($tmp); return false; }
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
    return rn_thema_retained($thema) ? '-' : null;
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
            $rn_titel = $mehr ? $rn_f['name'] . ': ' . $rn_klar : $rn_klar;
            /* Der Comment ist der ANZEIGENAME in Loxone Config (Regeln/07);
             * bis 2.1.6 stand dort "", und Config zeigte den Titel. Der
             * Vorsatz nennt das Fahrzeug, weil die Bausteinsuche des
             * Miniservers den Geraeteknoten nicht kennt. Uebersetzt wird
             * nichts Neues - die Beschriftung ist derselbe Text. */
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
    $home = getenv('LBHOMEDIR');
    if (!$home && defined('LBHOMEDIR')) {
        $home = LBHOMEDIR;
    }
    if (!$home || !is_dir($home)) {
        return 0;
    }
    $d = @json_decode((string) @file_get_contents(
        $home . '/config/system/general.json'), true);
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
    list(, $abo_ok) = rn_abo_datei();
    if ($abo_ok) {
        return rn_t('TEXT.ABO_MITGELIEFERT');
    }
    $f = rn_gateway_fassung();
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

/** Erlaubte Ausgabearten: alle des Moduls ausser 'audioserver' (die Linie gibt keinen Text an Loxone). */
function rn_ansage_modi()
{
    return array('aus', 'musicserver', 'ms4h', 'custom', 'alexang', 'cc4lox');
}

/** Optionen fuer Formular-Baustein und Formular-Lesen. */
function rn_ansage_opt()
{
    return array('modi' => rn_ansage_modi());
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
    return $t;
}

/** Der Block tts flach fuer config.php. */
function rn_tts_flach(array $tts)
{
    $aus = array();
    foreach (array_keys(ansage_vorgaben('aus')) as $k) {
        $aus['tts_' . $k] = isset($tts[$k]) && is_scalar($tts[$k]) ? (string) $tts[$k] : '';
    }
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
        /* Zu dieser Kennung hat das Modul (1.1.1) keinen Satz in [ANSAGE]; linieneigen wie Intercom
         * 2.2.18, bis der Modulschluessel mit einer ergaenzenden Fassung kommt (Entwurf, Stufe 2). */
        'schluessel' => array('K_TTS_EINTRAG' => 'TEXT.SICH_TTS_EINTRAG'),
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

    /* Die Felder, aus denen das Formular Anfuehrungszeichen entfernt,
     * duerfen auch aus der Sicherung keine tragen. Das Kennwort ist
     * ausgenommen - es wird im Formular ebenfalls nicht beschnitten. */
    if ($k !== 'password' && preg_match('/["\']/', $w)) {
        return false;
    }

    if (preg_match('/^zoename[2-4]?$/', $k)) {
        return strpos($w, '/') === false && strpos($w, '#') === false
            && strpos($w, '+') === false;
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
