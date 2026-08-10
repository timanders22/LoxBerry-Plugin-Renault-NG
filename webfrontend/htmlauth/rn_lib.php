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
     * Deshalb gab es preupgrade.sh und postupgrade.sh, die die Dateien vor
     * dem Update nach /tmp/renault_api_upgrade kopierten und danach
     * zurueck. Das ist eine Kruecke, und eine bruechige dazu: /tmp ist auf
     * dem LoxBerry eine Ramdisk. Faellt zwischen den beiden Schritten der
     * Strom aus oder startet das System neu, sind Ladehistorie, Sitzung
     * und Protokoll fort - unwiederbringlich.
     *
     * Die Kruecke ist jetzt ueberfluessig, weil die Daten dort liegen, wo
     * LoxBerry sie ohnehin stehen laesst:
     *
     *     config/plugins/<ordner>/   Konfiguration (0600, enthaelt das
     *                                Renault-Passwort)
     *     data/plugins/<ordner>/     Sitzung und Ladehistorie
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
 * Wird von jedem Einstiegspunkt aufgerufen, kostet im Normalfall vier
 * is_file()-Aufrufe. Kopiert wird nur, wenn am neuen Ort noch nichts
 * steht - eine bereits umgezogene Datei wird nie ueberschrieben.
 *
 * Zusaetzlich wird die alte Dauersicherung config.php.backup beruecksichtigt,
 * die preupgrade.sh bis 1.4 angelegt hat.
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
    // Konfiguration notfalls aus der alten Dauersicherung.
    $sicherung = $p['konfdir'] . '/config.php.backup';
    if (!is_file($p['config']) && is_file($sicherung)) {
        @copy($sicherung, $p['config']);
        $bewegt++;
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

/** Alle Schluessel, die config.php fuehrt, mit ihren Vorgaben. */
function rn_vorgaben()
{
    return array(
        'zoename'         => 'Renault',
        'username'        => '',
        'password'        => '',
        'vin'             => '',
        'country'         => 'DE',
        'zoeph'           => '2',
        'save_in_db'      => 'N',
        'mail_bl'         => 'N',
        'exec_bl'         => '',
        'cmon_bl'         => 'N',
        'mail_csf'        => 'N',
        'exec_csf'        => '',
        'hide_cm'         => 'N',
        'map_provider'    => 'google',
        'weather_api_key' => '',
        'abrp_token'      => '',
        'abrp_model'      => '',
        'cron_ncs'        => '5',
        'cron_acs'        => '2',
        'aktionstoken'    => '',
    );
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
function rn_aktionsadresse($cfg, $aktion)
{
    return '/plugins/' . rn_paths()['plugin'] . '/index.php?token='
         . rawurlencode($cfg['aktionstoken']) . '&aktion=' . rawurlencode($aktion);
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

/** config.php einlesen, ohne sie in den globalen Namensraum zu kippen. */
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
 * config.php schreiben.
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
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, $z) === false) {
        return false;
    }
    // Erst pruefen, ob die neue Datei ueberhaupt gueltiges PHP ist -
    // sonst waere die Oberflaeche nach dem Speichern nicht mehr aufrufbar.
    $aus = array(); $code = 0;
    @exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $aus, $code);
    if ($code !== 0 && $aus) {
        @unlink($tmp);
        return false;
    }
    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
    @chmod($datei, 0600);

    // Dauerhafte Sicherung ausserhalb des Plugin-Ordners: uebersteht
    // Updates und Neuinstallation.
    $sich = rn_paths()['home'] . '/config/plugins/' . rn_paths()['plugin'];
    @mkdir($sich, 0775, true);
    @copy($datei, $sich . '/config.php.backup');
    return true;
}

/* ==================================================================
 * Zustand
 * ================================================================== */

/** Die zwischengespeicherten Abrufdaten. */
function rn_session()
{
    $datei = rn_paths()['session'];
    if (!is_readable($datei)) {
        return null;
    }
    $roh = (string) @file_get_contents($datei);
    return $roh === '' ? null : explode('|', $roh);
}

/** Bedeutung der Felder in der session-Datei. */
function rn_session_felder()
{
    return array(
        4  => 'Zeitpunkt des letzten Abrufs',
        7  => 'Kilometerstand',
        8  => 'Datum des Statusupdates',
        9  => 'Uhrzeit des Statusupdates',
        10 => 'Ladestatus',
        11 => 'Kabelstatus',
        12 => 'Batteriestand (%)',
        14 => 'Reichweite (km)',
        24 => 'Lademodus',
    );
}

function rn_log_tail($max = 200)
{
    $datei = rn_paths()['log'];
    if (!is_readable($datei)) {
        return array();
    }
    $zeilen = @file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($zeilen)) {
        return array();
    }
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

/** Alle Themen, die der Abruf veroeffentlicht, mit ihrer Bedeutung. */
function rn_themen($zoeph)
{
    $t = array(
        'BatteryLevel'      => 'Batteriestand in Prozent',
        'RangeHvacOff'      => 'Reichweite in km',
        'ChargingStatus'    => 'Ladestatus als Zahl',
        'PlugStatus'        => 'Kabel eingesteckt (1) oder nicht (0)',
        'ChargingRemaining' => 'Restdauer des Ladevorgangs in Minuten',
        'ChargingPower'     => 'Ladeleistung',
        'ChargeMode'        => 'Lademodus (always / schedule_mode)',
        'Mileage'           => 'Kilometerstand',
        'Name'              => 'Name des Fahrzeugs aus den Einstellungen',
        'HvAcStatus'        => 'Vorklimatisierung: on oder off',
        'HvAcStatusBin'     => 'Vorklimatisierung als 0/1 fuer Loxone',
        'phpCall'           => 'Zeitstempel des letzten Abrufs (Minuten)',
    );
    if ((string) $zoeph === '1') {
        $t['OutTemp'] = 'Aussentemperatur in Grad Celsius';
        $t['BatTemp'] = 'Batterietemperatur in Grad Celsius';
        $t['RenaultPHMode'] = 'Phase 1';
    } else {
        $t['GPS-Latitude']  = 'Breitengrad des Fahrzeugs';
        $t['GPS-Longitude'] = 'Laengengrad des Fahrzeugs';
        $t['EnergieOnBoard'] = 'verfuegbare Energie in kWh';
        $t['RenaultPHMode'] = 'Phase 2';
    }
    return $t;
}

/** Die Befehle, die Loxone an das Plugin senden kann. */
function rn_befehle()
{
    return array(
        'acnow'     => 'Vorklimatisierung starten (21 &deg;C)',
        'chargenow' => 'Sofort laden starten',
        'cmon'      => 'Ladeplan aktivieren',
        'cmoff'     => 'Ladeplan deaktivieren (sofort laden erlauben)',
        'abruf'     => 'Daten sofort neu abrufen',
    );
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
