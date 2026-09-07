<?php
/**
 * Renault - gemeinsame Funktionen der Oberflaeche
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
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
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        $home = lb_wurzel_ermitteln();
    }
    $home = $home ? $home : lb_wurzel_ermitteln();
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
    $ordner = getenv('LBPPLUGINDIR');
    if (!$ordner) { $ordner = basename(__DIR__); }
    if ($ordner === '' || $ordner === '.') { $ordner = 'renault_ng'; }
    $konf   = $home . '/config/plugins/' . $ordner;
    $daten  = $home . '/data/plugins/'   . $ordner;
    $prot   = $home . '/log/plugins/'    . $ordner;
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
        'sicherung' => $home . '/config/plugins/' . $ordner . '.backup.config.php',
        /* Die Anmeldung (Gigya-Token und Kamereon-Konto) gilt fuer das
         * KONTO, nicht fuer ein einzelnes Fahrzeug. Sie steht deshalb seit
         * 2.1.0 in einer eigenen Datei - sonst meldete sich jedes Fahrzeug
         * einzeln an, und bei zwei Fahrzeugen waeren es zwei Anmeldungen je
         * Tag statt einer. */
        'anmeldung' => $daten . '/anmeldung',
        'session' => $daten . '/session',
        'log'     => $prot  . '/renault.log',
        'csv'     => $daten . '/database.csv',
        'general' => $home . '/config/system/general.json',
        // Die alten Orte - nur noch, um einmalig umzuziehen.
        'alt_config'  => $eigen . '/config.php',
        'alt_session' => $eigen . '/session',
        'alt_log'     => $eigen . '/renault.log',
        'alt_csv'     => $eigen . '/database.csv',
    );
    return $p;
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
    $tmp = $ziel . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    @chmod($tmp, 0600);
    $ok = ftruncate($fh, 0) && fwrite($fh, $roh) === strlen($roh);
    fflush($fh);
    fclose($fh);
    if (!$ok) { @unlink($tmp); return false; }
    if (!@rename($tmp, $ziel)) { @unlink($tmp); return false; }
    return true;
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
     * Auspackordner, und der ist der Pruefstand. */
    if (class_exists('LBSystem', false)
        && method_exists('LBSystem', 'pluginversion')) {
        $aus = @LBSystem::pluginversion();
        if ($aus !== null && trim((string) $aus) !== '') {
            return trim((string) $aus);
        }
    }
    static $f = null;
    if ($f !== null) { return $f; }
    $f = '';
    $p = rn_paths(false);
    $kandidaten = array(
        $p['home'] . '/data/system/plugins/' . $p['plugin'] . '/plugin.cfg',
        dirname(dirname(dirname(__FILE__))) . '/plugin.cfg',
    );
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
    );
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
    $schaden = '';
    if ($bekannt === 0)                                    { $schaden = 'kaputt'; }
    elseif (!$heil)                                        { $schaden = 'abgeschnitten'; }
    elseif (!$hat_merkwort && rn_zweitschrift_hat_token()) { $schaden = 'ohne Token'; }

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
        $kaputt = $p['config'] . '.kaputt';
        if (is_file($p['config'])) { @rename($p['config'], $kaputt); }
        if (@copy($sich, $p['config'])) {
            @chmod($p['config'], 0600);
            rn_lage_merken('aus der Zweitschrift');
            rn_melden('WARN', 'Die Konfiguration war unlesbar und wurde aus der '
                . 'Zweitschrift wiederhergestellt (' . basename($sich) . '). Die '
                . 'beschaedigte Datei liegt als ' . basename($kaputt) . ' daneben. '
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
    $tmp   = $datei . '.tmp.' . getmypid();
    $fh = @fopen($tmp, 'c');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0600);                       // erst schuetzen,
    $ok = ftruncate($fh, 0) && fwrite($fh, $z) !== false;   // dann fuellen
    fflush($fh);
    fclose($fh);
    if (!$ok) { @unlink($tmp); return false; }

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
    if (function_exists('exec')) {
        $aus = array(); $code = 0;
        @exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $aus, $code);
        $text = implode(' ', $aus);
        if ($code !== 0 && stripos($text, 'error') !== false
            && stripos($text, 'not found') === false
            && stripos($text, 'nicht gefunden') === false) {
            @unlink($tmp);
            return false;
        }
    }

    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
    @chmod($datei, 0600);

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
    $sich = rn_paths()['sicherung'];
    $stmp = $sich . '.tmp.' . getmypid();
    $sh = @fopen($stmp, 'c');
    if ($sh !== false) {
        @chmod($stmp, 0600);
        ftruncate($sh, 0);
        fwrite($sh, $z);
        fclose($sh);
        if (!@rename($stmp, $sich)) { @unlink($stmp); }
        else { @chmod($sich, 0600); }
    }
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
    /* Zustaende: an/aus, Betriebsart, Fehlerflag, letzter Kilometerstand.
     * Nach einem Neustart des Miniservers oder des Gateways soll der Stand
     * sofort dastehen. */
    $zustaende = array(
        'ChargingStatus', 'CableStatus', 'ChargeMode', 'HvAcStatus',
        'HvAcStatusBin', 'RenaultPHMode', 'Name', 'ok', 'Mileage',
        'chargeEndStatus',
    );
    return in_array((string) $thema, $zustaende, true);
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
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            $home = lb_wurzel_ermitteln();
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
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

/** Die Eingaenge, die die Importdatei je Fahrzeug anlegt.
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

/** Vorlage der Gateway-Eingaenge nach dem Heimkino-Kunstgriff (12.08.2026):
 *  VirtualInHttp mit Dummy-Adresse http://localhost und Abfragezyklus 604800 s,
 *  nur damit Loxone die richtig benannten Eingaenge anlegt - die Werte kommen
 *  vom MQTT-Gateway. Format wie Original-Export aus Loxone Config 17.1.
 *  Seit 2.1.0 fuer ALLE eingerichteten Fahrzeuge. */
function rn_vorlage()
{
    $cfg   = rn_config_read();
    $autos = rn_fahrzeuge($cfg);
    $crlf  = "\r\n";
    $themen = array();
    foreach ($autos as $rn_f) {
        $themen[] = 'Renault/' . $rn_f['name'] . '/#';
    }
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="Renault Fahrzeugdaten" Comment="Erzeugt vom LoxBerry-Plugin Renault ('
        . date('d.m.Y') . '). Werte kommen vom MQTT-Gateway - Abo '
        . htmlspecialchars(implode(' ', $themen), ENT_QUOTES | ENT_XML1, 'UTF-8')
        . ' noetig." Address="http://localhost" PollingTime="604800">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($autos as $rn_f) {
        foreach (rn_vorlage_felder($rn_f['zoeph']) as $w) {
            $o .= "\t" . '<VirtualInHttpCmd Title="' . htmlspecialchars('Renault_' . $rn_f['name'] . '_' . $w[0], ENT_QUOTES | ENT_XML1, 'UTF-8') . '" ';
            $o .= 'Comment="' . htmlspecialchars(html_entity_decode(rn_t($w[1]), ENT_QUOTES, 'UTF-8'), ENT_QUOTES | ENT_XML1, 'UTF-8') . '" Check=" " ';
            $o .= 'Signed="' . $w[2] . '" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="' . $w[3] . '" MaxVal="' . $w[4] . '" Unit="' . htmlspecialchars(html_entity_decode($w[5], ENT_QUOTES, 'UTF-8'), ENT_QUOTES | ENT_XML1, 'UTF-8') . '" HintText=""/>' . $crlf;
        }
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return array('VI_renault.xml', $o);
}

/** VQ-Vorlage (Steuerbefehle) nach dem Heimkino/Robonect-Muster:
 *  templateType 3, Aktionstoken eingesetzt. Befehle = rn_befehle(),
 *  je Fahrzeug einmal. */
function rn_vorlage_vo()
{
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
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
    $f = rn_gateway_fassung();
    if ($f <= 0) {
        return rn_t('TEXT.ABO_UNBEKANNT');
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(rn_t('TEXT.ABO_GEMESSEN'), $f) . '</span>';
    return rn_t($f >= 2 ? 'TEXT.ABO_V2' : 'TEXT.H_ABO_PFLICHT') . $gemessen;
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
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function rn_sicherung_lesen($roh)
{
    $mangel = array();
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

    foreach ($daten as $k => $w) {
        $k = (string) $k;
        /* Der lesbare Kopf wird UEBERGANGEN, nicht beanstandet. */
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(rn_t('TEXT.SICH_FREMD'),
                                 htmlspecialchars($k, ENT_QUOTES, 'UTF-8'));
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
            continue;
        }
        $w = (string) $w;
        if (!rn_wert_pruefen($k, $w)) {
            $mangel[] = sprintf(rn_t('TEXT.SICH_WERT'),
                                 htmlspecialchars($k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $gesehen[$k] = true;
        $anzahl++;
    }

    $fehlend = array_values(array_diff($bekannt, array_keys($gesehen)));
    if ($fehlend) {
        $mangel[] = sprintf(rn_t('TEXT.SICH_FEHLT'), count($fehlend), count($bekannt),
            htmlspecialchars(implode(', ', array_slice($fehlend, 0, 8))
                . (count($fehlend) > 8 ? ' …' : ''), ENT_QUOTES, 'UTF-8'));
    }
    if ($anzahl === 0) {
        $mangel[] = rn_t('TEXT.SICH_LEER');
    }
    /* Alle Beanstandungen werden gesammelt; eine halb gueltige Datei aendert
     * GAR NICHTS. */
    return array($mangel ? null : $neu, $mangel, $anzahl);
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
