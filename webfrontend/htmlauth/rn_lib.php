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

function rn_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
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
    foreach (array($konf, $daten, $prot) as $d) {
        if (!is_dir($d)) { @mkdir($d, 0775, true); }
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
 * Wird von JEDEM Einstiegspunkt aufgerufen - auch von der Oberflaeche.
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
            if (@copy($alt, $neu)) {
                @unlink($alt);
                $bewegt++;
            }
        }
    }
    // Konfiguration notfalls aus der Zweitschrift. Beruecksichtigt wird
    // auch der alte Ort der Zweitschrift (im Konfigordner, bis 2.0.6).
    if (!is_file($p['config'])) {
        foreach (array($p['sicherung'], $p['konfdir'] . '/config.php.backup') as $sich) {
            if (is_file($sich)) {
                if (@copy($sich, $p['config'])) { $bewegt++; }
                break;
            }
        }
    }
    if ($bewegt > 0) {
        @chmod($p['config'], 0600);
    }
    return $bewegt;
}

function rn_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
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
function rn_config_einlesen($rn_datei)
{
    ob_start();
    include $rn_datei;
    ob_end_clean();
    $rn_werte = get_defined_vars();
    unset($rn_werte['rn_datei']);
    return $rn_werte;
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
function rn_config_read()
{
    $cfg = rn_vorgaben();
    $datei = rn_paths()['config'];
    if (is_readable($datei)) {
        $werte = rn_config_einlesen($datei);
        foreach ($cfg as $k => $v) {
            if (array_key_exists($k, $werte) && !is_array($werte[$k])) {
                $cfg[$k] = (string) $werte[$k];
            }
        }
    }
    return $cfg;
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

    // Zweitschrift NEBEN dem Konfigordner - sie uebersteht damit auch eine
    // Deinstallation, die den Ordner selbst abraeumt. Mit denselben Rechten
    // wie das Original: sie enthaelt dasselbe Passwort.
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
    return $t;
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

/** Beschriftung eines Befehls in der eingestellten Sprache. */
function rn_befehl_text($aktion)
{
    $b = rn_befehle();
    return isset($b[$aktion]) ? rn_t($b[$aktion][0]) : $aktion;
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
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
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
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
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
    $o .= '<VirtualOut HintText="" Title="Renault steuern (LoxBerry-Plugin)" Comment="Steuerbefehle ueber das Plugin ' . htmlspecialchars(rn_paths()['plugin'], ENT_QUOTES | ENT_XML1, 'UTF-8') . ' - enthaelt das Aktionstoken." Address="http://' . htmlspecialchars($host, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" CmdInit="" CloseAfterSend="true" CmdSep="">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($autos as $rn_f) {
        foreach (rn_befehle() as $rn_a => $rn_angabe) {
            // html_entity_decode bleibt stehen, obwohl die Sprachdateien seit
            // 2.1.0 echte Zeichen tragen: schreibt jemand doch wieder eine
            // Entitaet hinein, stuende sie sonst doppelt maskiert im XML.
            $rn_klar  = html_entity_decode(rn_t($rn_angabe[0]), ENT_QUOTES, 'UTF-8');
            $rn_titel = $mehr ? $rn_f['name'] . ': ' . $rn_klar : $rn_klar;
            $o .= "\t" . '<VirtualOutCmd Title="' . htmlspecialchars($rn_titel, ENT_QUOTES | ENT_XML1, 'UTF-8') . '" Comment="" CmdOnMethod="GET" CmdOffMethod="GET" ';
            $o .= 'CmdOn="' . htmlspecialchars(rn_aktionsadresse($cfg, $rn_a, $rn_f['nr']), ENT_QUOTES | ENT_XML1, 'UTF-8') . '" ';
            $o .= 'CmdOnHTTP="" CmdOnPost="" CmdOff="" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" ';
            $o .= 'Analog="false" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
        }
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('VQ_renault_steuern.xml', $o);
}
