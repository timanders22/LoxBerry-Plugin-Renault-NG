<?php
/**
 * Renault - Bedienoberflaeche
 *
 * Ausschliesslich Oberflaeche. Der Datenabruf steht in abruf.php und wird
 * vom Cron aufgerufen; die Befehle von Loxone nimmt der Endpunkt unter
 * webfrontend/html/index.php entgegen (mit Token, ohne Anmeldung).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

require_once 'loxberry_web.php';
require_once __DIR__ . '/rn_lib.php';
/* logger.php gehoert HIERHER.
 *
 * Bis 2.1.5 band die Oberflaeche es nicht ein und rief renault_log() kein
 * einziges Mal. Damit hinterliess weder ein neu erzeugtes Aktionstoken noch
 * eine abgelehnte Sicherung noch ein Werksrueckfall eine Spur - wer spaeter
 * fragte, wann sein Token verlorenging, fand nichts. */
require_once __DIR__ . '/logger.php';

/* rn_umzug() gehoert HIERHER, nicht nur in abruf.php.
 *
 * Bis 2.0.6 rief nur der Cron den Umzug. Die Oberflaeche legte aber als
 * erste Handlung eine frische config.php an, um ein Aktionstoken zu
 * erzeugen - und rn_umzug() zieht nur um, solange am neuen Ort NICHTS
 * steht. Wer nach einem Update von 1.4 zuerst die Oberflaeche oeffnete,
 * verlor Benutzer, Passwort und Fahrgestellnummer dauerhaft. */
rn_umzug();

$rn_p       = rn_paths();
$rn_meldung = '';
$rn_fehler  = array();
/* Was versucht und NICHT gelungen ist (Regeln/04: "Der Vorgang ist nicht
 * gelungen"), getrennt von "Nicht gespeichert" (Befund U9). */
$rn_misslungen = array();

/* ---------- X-2: Eingaben nach einer Beanstandung (Welle-4-Bau) ----------
 *
 * Regeln/04, "Nach einer Beanstandung stehen die eingetippten Werte wieder im
 * Formular". Seit der Umleitung nach jedem POST (Befund U1) zeigte der GET
 * nach einer Beanstandung die GESPEICHERTEN Werte; wer drei Felder richtig
 * und eines falsch eingab, tippte alle vier neu.
 *
 * Mit der Einmalmeldung reisen unter 'eingaben' die Felder des
 * Einstellungsformulars und die Namen der beanstandeten Felder. Nie mit
 * reisen Passwort, Wetter-Schluessel und ABRP-Token - sie stehen in keiner
 * Liste; ihre Felder werden nur markiert. Ein Wert, der keine Zeichenkette in
 * gueltigem UTF-8 ist oder laenger als 512 Byte, reist nicht mit; sein Feld
 * zeigt dann den gespeicherten Wert (und bleibt markiert). Bauform
 * AudiConnect 0.9.23 / Abfahrtsassistent 1.6.19. */
function rn_eingabe_felder($formular)
{
    if ($formular !== 'einst') { return array(); }
    $f = array('username', 'country', 'cron_ncs', 'cron_acs', 'steuerung_ein', 'ac_temp',
               'bl_schwelle', 'mail_bl', 'cmon_bl', 'mail_csf', 'exec_bl', 'exec_csf',
               'soc_min', 'soc_target', 'save_in_db', 'abrp_model');
    for ($i = 1; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $nr = ($i === 1) ? '' : (string) $i;
        foreach (array('zoename', 'vin', 'zoeph') as $n) { $f[] = $n . $nr; }
    }
    /* Nr. 36 b (seit 2.1.16): die Sprachausgabe und die Anlass-Schalter - nie die Sprechtoken
     * (ansage_x2_felder() nennt sie nicht; markiert werden koennen sie). */
    $f = array_merge($f, ansage_x2_felder(rn_ansage_opt()));
    foreach (rn_ansage_anlaesse() as $a) { $f[] = $a[0]; }
    return $f;
}
/* Geheimnisfelder: werden markiert, ihr Wert reist nie mit. */
function rn_eingabe_geheim()
{
    return array_merge(array('password', 'weather_api_key', 'abrp_token'), rn_ansage_tokenfelder());
}
function rn_eingabe_tauglich($w)
{
    return is_string($w) && strlen($w) <= 512 && preg_match('//u', $w) === 1;
}
/* Ein Feld beanstanden; ohne Argument die Liste. */
function rn_bean($feld = null)
{
    static $liste = array();
    if ($feld !== null && !in_array((string) $feld, $liste, true)) {
        $liste[] = (string) $feld;
    }
    return $liste;
}
/* Die Eingaben eines Formulars aus $_POST - nur die Felder der Liste. */
function rn_eingaben_sammeln($formular)
{
    $werte = array();
    foreach (rn_eingabe_felder($formular) as $f) {
        if (isset($_POST[$f]) && rn_eingabe_tauglich($_POST[$f])) {
            $werte[$f] = $_POST[$f];
        }
    }
    return array('formular' => $formular, 'werte' => $werte, 'falsch' => rn_bean());
}
/* Beim GET: die Eingaben aus der Einmalmeldung pruefen und ablegen. */
function rn_eingaben($setzen = null)
{
    static $e = null;
    if ($setzen !== null) {
        $e = null;
        if (is_array($setzen) && isset($setzen['formular'], $setzen['werte'], $setzen['falsch'])
            && is_string($setzen['formular']) && is_array($setzen['werte'])
            && is_array($setzen['falsch']) && rn_eingabe_felder($setzen['formular'])) {
            $erlaubt = rn_eingabe_felder($setzen['formular']);
            $werte = array();
            foreach ($setzen['werte'] as $f => $w) {
                if (in_array((string) $f, $erlaubt, true) && rn_eingabe_tauglich($w)) {
                    $werte[(string) $f] = $w;
                }
            }
            $falsch = array();
            foreach ($setzen['falsch'] as $n) {
                if (is_string($n) && (in_array($n, $erlaubt, true)
                                      || in_array($n, rn_eingabe_geheim(), true))) {
                    $falsch[] = $n;
                }
            }
            $e = array('formular' => $setzen['formular'], 'werte' => $werte, 'falsch' => $falsch);
        }
    }
    return $e;
}
/* Wert eines Felds: die Eingabe, sonst der gespeicherte Wert. */
function rn_ein_w($feld, $gespeichert)
{
    $e = rn_eingaben();
    if ($e !== null && in_array($feld, rn_eingabe_felder($e['formular']), true)
        && isset($e['werte'][$feld]) && is_string($e['werte'][$feld])) {
        return $e['werte'][$feld];
    }
    return (string) $gespeichert;
}
/* Markierung eines beanstandeten Felds (Attribute, schon maskiert). */
function rn_ein_m($feld)
{
    $e = rn_eingaben();
    return ($e !== null && in_array($feld, $e['falsch'], true))
        ? ' class="sm-beanstandet" aria-invalid="true"' : '';
}
/* Nr. 36 b: ein Haken nach einer Beanstandung - wie abgeschickt (fehlt er in den Eingaben, war er
 * aus); sonst der gespeicherte Stand. */
function rn_ein_h($feld, $gespeichert)
{
    $e = rn_eingaben();
    if ($e !== null && in_array($feld, rn_eingabe_felder($e['formular']), true)) {
        return isset($e['werte'][$feld]);
    }
    return (bool) $gespeichert;
}
/* Nr. 19: enthaelt eine Eingabe Anfuehrungs- oder Steuerzeichen? Bis 2.1.14
 * wurden sie still entfernt und der Rest gespeichert. */
function rn_zeichen_falsch($w)
{
    return preg_match('/[\x00-\x1F\x7F"\']/', (string) $w) === 1;
}
$rn_eingaben_post = null;   // X-2: was mit der Einmalmeldung zurueckreist

/* ---------------------------------------------------------------- *
 * DIE REITERLISTE STEHT GENAU EINMAL
 *
 * Bis 2.0.6 stand sie dreimal: als Positivliste im regulaeren Ausdruck,
 * als Feld fuer die Leiste und als id am Bereich. Wer einen Reiter
 * ergaenzte und eine der drei Stellen vergass, bekam keinen Fehler,
 * sondern einen Reiter, der sich anklicken laesst und nach jedem Absenden
 * auf Einstellungen zurueckspringt.
 *
 * Die Leiste weiter unten ist trotzdem AUSGESCHRIEBEN und keine Schleife.
 * Grund: hausstandard_pruefen.py sucht die Reiter im Quelltext und findet
 * eine erzeugte Leiste nicht - es meldete fuer 2.0.6 null Reiter und in
 * der Spalte "tab" ein Minus, also gar kein Ergebnis. Eine Korrektur, die
 * eine Pruefung blind macht, ist keine. Die Uebereinstimmung zwischen
 * dieser Liste und der Leiste prueft der Reiter Test nach.
 * ---------------------------------------------------------------- */
$rn_reiter = array(
    'settings' => 'REITER.EINSTELLUNGEN',
    'mqtt'     => null,                    // Eigenname, wird nicht uebersetzt
    'loxone'   => 'REITER.LOXONE',
    'test'     => 'REITER.TEST',
    'verlauf'  => 'REITER.VERLAUF',
    'log'      => 'REITER.LOG',
);
$rn_muster = '/^tab-(' . implode('|', array_map(function ($k) {
    return preg_quote($k, '/');
}, array_keys($rn_reiter))) . ')$/';

$rn_cfg = rn_config_read();

// Beim ersten Aufruf ein Token erzeugen, damit der Endpunkt fuer Loxone
// sofort benutzbar ist. Der Rueckgabewert wird geprueft - schlaegt das
// Schreiben fehl, soll das nicht stillschweigend untergehen.
if ($rn_cfg['aktionstoken'] === '') {
    /* Der Unterschied zwischen "noch nie gesetzt" und "verlorengegangen"
     * steht in rn_konfig_lage(): war die Konfiguration beschaedigt, hat
     * rn_config_read() sie vorher aus der Zweitschrift geheilt und den
     * Zustand gemerkt. Ein neues Token macht JEDE im Miniserver eingetragene
     * Adresse ungueltig, und ein Virtueller Ausgang wertet die 403 nicht
     * aus - der Verlust waere stumm. Deshalb steht er im Protokoll. */
    $rn_lage = rn_konfig_lage();
    $rn_cfg['aktionstoken'] = rn_token_erzeugen();
    if (!rn_config_write($rn_cfg)) {
        $rn_fehler[] = rn_t('TEXT.F_TOKEN_SCHREIBEN');
        renault_log('ERROR', 'Ein neues Aktionstoken liess sich nicht schreiben.');
    } else {
        renault_log($rn_lage === 'fehlt' ? 'INFO' : 'WARN',
            'Neues Aktionstoken erzeugt (Lage der Konfiguration: ' . $rn_lage . ').'
            . ($rn_lage === 'fehlt' ? '' : ' Die Adressen im Miniserver sind damit '
              . 'ungueltig geworden - sie stehen im Reiter "Einbindung in Loxone" '
              . 'zum Abschreiben bereit.'));
    }
}

/* Die Einmalmeldung des vorigen POST (Befund U1, PRG): nur beim GET gelesen,
 * dabei geloescht, aelter als 120 s verworfen (rn_einmal_lesen()). */
$rn_test_titel = '';
$rn_test_text  = '';
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') !== 'POST') {
    $rn_einmal = rn_einmal_lesen();
    if ($rn_einmal !== null) {
        if ($rn_einmal['meldung'] !== '') { $rn_meldung = $rn_einmal['meldung']; }
        $rn_fehler     = array_merge($rn_fehler, $rn_einmal['fehler']);
        $rn_misslungen = array_merge($rn_misslungen, $rn_einmal['misslungen']);
        $rn_test_titel = $rn_einmal['test_titel'];
        $rn_test_text  = $rn_einmal['test_text'];
        rn_eingaben($rn_einmal['eingaben']);     // X-2
    }
}

/* ---------------------------------------------------------------- *
 * DER WACHPOSTEN - vor jedem Handler, genau einmal
 *
 * Bei fehlendem Merkmal wird $_POST geleert bis auf activetab. Das ist mit
 * Absicht gruendlicher als ein "wenn" vor jedem der sieben Handler: der
 * achte Handler, den jemand spaeter ergaenzt, ist damit automatisch
 * mitgeschuetzt. Ein Schutz, den man beim Erweitern vergessen kann, ist
 * keiner. activetab bleibt stehen, damit die Seite den Reiter nicht
 * verliert, auf dem der Bediener gerade war.
 * ---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !rn_formtoken_ok($rn_cfg)) {
    $rn_behalten = isset($_POST['activetab']) ? (string) $_POST['activetab'] : '';
    $_POST = ($rn_behalten !== '') ? array('activetab' => $rn_behalten) : array();
    $rn_fehler[] = rn_t('TEXT.F_FORMTOKEN');
}
/* Das Formularmerkmal entsteht erst NACH allen Handlern, unmittelbar vor
 * lbheader() (Befund U2): bis 2.1.12 wurde es hier aus dem ALTEN Token
 * gebildet, und nach "Neues Token" oder einem Zurueckspielen wies der
 * Wachposten den naechsten Klick als fremdes Formular ab. */

/* Die Reiterwahl steht ABSICHTLICH nach dem Wachposten: sonst uebernaehme sie
 * das activetab eines abgewiesenen POST, und ein fremdes Formular koennte
 * wenigstens noch den Reiter umschalten. Gelesen wird erst, wenn geprueft
 * ist. */
$rn_tab = 'tab-settings';
if (isset($_POST['activetab']) && preg_match($rn_muster, (string) $_POST['activetab'])) {
    $rn_tab = (string) $_POST['activetab'];
} elseif (isset($_GET['form']) && !is_array($_GET['form'])
          && preg_match($rn_muster, 'tab-' . (string) $_GET['form'])) {
    $rn_tab = 'tab-' . (string) $_GET['form'];
}

/* ---------------------------------------------------------------- *
 * Formulare
 * ---------------------------------------------------------------- */
if (isset($_POST['token_neu'])) {
    $rn_cfg['aktionstoken'] = rn_token_erzeugen();
    if (rn_config_write($rn_cfg)) {
        $rn_meldung = rn_t('TEXT.M_TOKEN_NEU');
        renault_log('WARN', 'Aktionstoken auf Knopfdruck neu erzeugt. Alle bisher '
            . 'im Miniserver eingetragenen Adressen antworten ab jetzt mit 403 und '
            . 'muessen neu abgeschrieben werden.');
    } else {
        $rn_fehler[] = rn_t('TEXT.F_TOKEN_SCHREIBEN');
        renault_log('ERROR', 'Das neue Aktionstoken liess sich nicht schreiben.');
    }
    $rn_cfg = rn_config_read();
    $rn_tab = 'tab-loxone';
}

if (isset($_POST['speichern'])) {
    $neu = $rn_cfg;
    /* Die Fahrzeugnamen VOR dem Speichern - sie sind Teil der MQTT-Themen
     * (Befund M3, unten nach dem Schreiben). */
    $rn_namen_vorher = array();
    foreach (rn_fahrzeuge($rn_cfg) as $rn_f0) { $rn_namen_vorher[] = $rn_f0['name']; }

    /* Einfache Textfelder. Leere Felder loeschen nichts, wo der Verlust
     * wehtut - das gilt hier fuer das Passwort. */
    /* Nr. 16/19 (Welle-4-Bau): Anfuehrungs- und Steuerzeichen werden NICHT
     * mehr still entfernt. Bis 2.1.14 wurde "Zo'e" als "Zoe" gespeichert,
     * mit gruener Meldung - der Anwender sah den geaenderten Wert erst im
     * MQTT-Thema. Jetzt ist es eine Beanstandung: nichts gespeichert, Feld
     * markiert, die Eingabe kommt zurueck (X-2). Still bleiben nur Leerraum
     * am Rand und der grossgeschriebene Laendercode (Nr. 19, Nr. 21). */
    $rn_feldname = array(
        'username' => 'TEXT.L_BENUTZER', 'country' => 'TEXT.L_LAND', 'save_in_db' => 'TEXT.L_SAVE_IN_DB',
        'steuerung_ein' => 'TEXT.L_STEUERUNG', 'cron_ncs' => 'TEXT.L_CRON_NCS',
        'cron_acs' => 'TEXT.L_CRON_ACS', 'ac_temp' => 'TEXT.L_AC_TEMP',
        'bl_schwelle' => 'TEXT.L_BL_SCHWELLE', 'mail_bl' => 'TEXT.L_MAIL_BL',
        'exec_bl' => 'TEXT.L_EXEC_BL', 'cmon_bl' => 'TEXT.L_CMON_BL', 'mail_csf' => 'TEXT.L_MAIL_CSF',
        'exec_csf' => 'TEXT.L_EXEC_CSF', 'soc_min' => 'TEXT.L_SOC_MIN',
        'soc_target' => 'TEXT.L_SOC_TARGET', 'abrp_model' => 'TEXT.L_ABRP_MODEL');
    foreach (array('username', 'country', 'save_in_db', 'steuerung_ein',
                   'cron_ncs', 'cron_acs', 'ac_temp', 'bl_schwelle',
                   'mail_bl', 'exec_bl', 'cmon_bl', 'mail_csf', 'exec_csf',
                   'soc_min', 'soc_target', 'abrp_model') as $f) {
        if (isset($_POST[$f])) {
            $rn_roh = is_string($_POST[$f]) ? trim($_POST[$f], " \t\n\r") : null;
            if ($rn_roh === null || rn_zeichen_falsch($rn_roh)) {
                $rn_fehler[] = sprintf(rn_t('TEXT.F_ZEICHEN'), rn_e(rn_t($rn_feldname[$f])));
                rn_bean($f);
                continue;
            }
            // Nr. 21: den Laendercode grosszuschreiben bleibt still.
            $neu[$f] = ($f === 'country') ? strtoupper($rn_roh) : $rn_roh;
        }
    }
    for ($i = 1; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $nr = ($i === 1) ? '' : (string) $i;
        foreach (array('zoename' => 'TEXT.L_NAME', 'vin' => 'TEXT.L_VIN', 'zoeph' => 'TEXT.L_PHASE') as $f => $rn_lbl) {
            if (isset($_POST[$f . $nr])) {
                $rn_roh = is_string($_POST[$f . $nr]) ? trim($_POST[$f . $nr], " \t\n\r") : null;
                if ($rn_roh === null || rn_zeichen_falsch($rn_roh)) {
                    $rn_fehler[] = sprintf(rn_t('TEXT.F_ZEICHEN'),
                        rn_e(sprintf(rn_t('TEXT.H_FAHRZEUG_NR'), $i) . ': ' . rn_t($rn_lbl)));
                    rn_bean($f . $nr);
                    continue;
                }
                $neu[$f . $nr] = $rn_roh;
            }
        }
    }
    /* Kennwort und die beiden Schluessel: ein leeres Feld laesst den Wert
     * stehen, geloescht wird ueber den Haken daneben (Regeln/04; Befunde U5
     * und U6). Bis 2.1.12 liess sich das Kennwort gar nicht entfernen, und
     * weather_api_key und abrp_token standen als Klartext im Formular. Die
     * Schluessel werden wie bisher von Steuerzeichen und Anfuehrungszeichen
     * befreit; das Kennwort bleibt unbeschnitten. */
    foreach (array('password' => 'TEXT.L_PASSWORT', 'weather_api_key' => 'TEXT.L_WETTER',
                   'abrp_token' => 'TEXT.L_ABRP_TOKEN') as $f => $rn_lbl) {
        $rn_roh = (isset($_POST[$f]) && is_string($_POST[$f])) ? $_POST[$f] : '';
        if ($f !== 'password') {
            /* Nr. 19: nur der Leerraum am Rand faellt still weg; Anfuehrungs-
             * und Steuerzeichen sind eine Beanstandung (bis 2.1.14 still
             * entfernt). Der Wert reist nie zurueck, das Feld wird markiert. */
            $rn_roh = trim($rn_roh, " \t\n\r");
            if (rn_zeichen_falsch($rn_roh)) {
                $rn_fehler[] = sprintf(rn_t('TEXT.F_ZEICHEN'), rn_e(rn_t($rn_lbl)));
                rn_bean($f);
                continue;
            }
        }
        /* Renault-b1: das Kennwort aus der beiseitegelegten Datei - auf dem
         * Server gelesen, nie ueber das Formular. */
        if ($f === 'password' && !empty($_POST['password_aus_kaputt'])) {
            if ($rn_roh !== '' || !empty($_POST['password_loeschen'])) {
                $rn_fehler[] = rn_t('TEXT.F_PASSWORT_DOPPELT');
                rn_bean('password');
                continue;
            }
            $rn_pw = rn_kaputt_wert('password');
            if ($rn_pw === '') {
                $rn_fehler[] = rn_t('TEXT.F_PASSWORT_AUS_KAPUTT');
                rn_bean('password');
                continue;
            }
            $neu['password'] = $rn_pw;
            continue;
        }
        $rn_weg = !empty($_POST[$f . '_loeschen']);
        if ($rn_weg && $rn_roh !== '') {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_LOESCHEN_WIDERSPRUCH'), rn_e(rn_t($rn_lbl)));
            rn_bean($f);
        } elseif ($rn_weg) {
            $neu[$f] = '';
        } elseif ($rn_roh !== '') {
            $neu[$f] = $rn_roh;
        }
    }

    /* ---- Pruefungen. Beanstandungen werden GESAMMELT, nicht
     *      ueberschrieben - der Benutzer soll alle auf einmal sehen. Und
     *      eine halb ausgefuellte Zeile darf nicht das Speichern ALLER
     *      Felder verhindern; deshalb werden Werte, die fuer sich
     *      unbrauchbar sind, einzeln beanstandet. ---- */
    if ($neu['zoename'] === '') {
        $rn_fehler[] = rn_t('TEXT.F_NAME_LEER');
        rn_bean('zoename');
    }
    for ($i = 1; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $nr = ($i === 1) ? '' : (string) $i;
        $nm = $neu['zoename' . $nr];
        $vn = $neu['vin' . $nr];
        if ($nm !== '' && (strpos($nm, '/') !== false || strpos($nm, '#') !== false
            || strpos($nm, '+') !== false)) {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_NAME_SONDERZEICHEN'), $i);
            rn_bean('zoename' . $nr);
        }
        if ($vn !== '' && !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/i', $vn)) {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_VIN'), $i);
            rn_bean('vin' . $nr);
        }
        if (!in_array($neu['zoeph' . $nr], array('1', '2'), true)) {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_PHASE'), $i);
            rn_bean('zoeph' . $nr);
        }
    }
    // Doppelte Namen wuerden zwei Fahrzeuge in denselben Themenpfad legen.
    $rn_namen = array();
    for ($i = 1; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $nr = ($i === 1) ? '' : (string) $i;
        $nm = $neu['zoename' . $nr];
        if ($nm === '') { continue; }
        if (in_array($nm, $rn_namen, true)) {
            /* rn_e() um den NAMEN, nicht um die Meldung: der Name kommt aus
             * dem Formular, die Meldung aus der Sprachdatei und traegt
             * Auszeichnung. Wer die ganze Meldung maskiert, macht aus dem
             * Markup woertlichen Text - das ist die doppelte Maskierung. Wer
             * gar nichts maskiert, laesst eine Eingabe ungeprueft in die
             * Seite. */
            $rn_fehler[] = sprintf(rn_t('TEXT.F_NAME_DOPPELT'), rn_e($nm));
            rn_bean('zoename' . $nr);
        }
        $rn_namen[] = $nm;
    }
    if (!in_array($neu['save_in_db'], array('Y', 'N'), true)) {
        $rn_fehler[] = rn_t('TEXT.F_AUFZEICHNUNG');
        rn_bean('save_in_db');
    }
    if (!in_array($neu['steuerung_ein'], array('Y', 'N'), true)) {
        $rn_fehler[] = rn_t('TEXT.F_STEUERUNG');
        rn_bean('steuerung_ein');
    }
    if (!preg_match('/^[A-Z]{2}$/', $neu['country'])) {
        $rn_fehler[] = rn_t('TEXT.F_LAND');
        rn_bean('country');
    }
    foreach (array('cron_ncs' => array(1, 60), 'cron_acs' => array(1, 60),
                   'ac_temp' => array(16, 30), 'bl_schwelle' => array(1, 99)) as $f => $gr) {
        if (!preg_match('/^[0-9]+$/', $neu[$f]) || (int) $neu[$f] < $gr[0] || (int) $neu[$f] > $gr[1]) {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_ZAHL'), rn_t('TEXT.L_' . strtoupper($f)), $gr[0], $gr[1]);
            rn_bean($f);
        }
    }
    foreach (array('soc_min', 'soc_target') as $f) {
        if ($neu[$f] === '') { continue; }         // leer = nicht anfassen
        if (!preg_match('/^[0-9]+$/', $neu[$f]) || (int) $neu[$f] < 20 || (int) $neu[$f] > 100) {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_ZAHL'), rn_t('TEXT.L_' . strtoupper($f)), 20, 100);
            rn_bean($f);
        }
    }
    if ($neu['soc_min'] !== '' && $neu['soc_target'] !== ''
        && (int) $neu['soc_min'] > (int) $neu['soc_target']) {
        $rn_fehler[] = rn_t('TEXT.F_SOC_REIHENFOLGE');
        rn_bean('soc_min');
        rn_bean('soc_target');
    }
    /* Nr. 19: ein anderer Wert als ja/nein wurde bis 2.1.14 still zu "nein"
     * und gespeichert. Jetzt eine Beanstandung. */
    foreach (array('mail_bl', 'cmon_bl', 'mail_csf') as $f) {
        if (!in_array($neu[$f], array('Y', 'N'), true)) {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_AUSWAHL'), rn_e(rn_t($rn_feldname[$f])));
            rn_bean($f);
        }
    }
    // Ein Passwort ohne Benutzernamen ist fast immer ein Versehen.
    if ($neu['password'] !== '' && $neu['username'] === '') {
        $rn_fehler[] = rn_t('TEXT.F_BENUTZER_FEHLT');
        rn_bean('username');
    }
    /* exec_bl und exec_csf wie beim Zurueckspielen und beim Aufruf pruefen
     * (Befund U3, rn_wert_pruefen()). Bis 2.1.12 nahm das Formular "a&b" an;
     * der Abruf fuehrte den Befehl nie aus, und die eigene Sicherung liess
     * sich danach nicht mehr zurueckspielen. */
    foreach (array('exec_bl' => 'TEXT.L_EXEC_BL', 'exec_csf' => 'TEXT.L_EXEC_CSF') as $f => $rn_lbl) {
        if (!rn_wert_pruefen($f, $neu[$f])) {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_EXEC'), rn_e(rn_t($rn_lbl)));
            rn_bean($f);
        }
    }
    /* Nr. 36 b (Stufe 2, seit 2.1.16): Sprachausgabe und Anlaesse. Jede Beanstandung verhindert
     * das Speichern (Nr. 16); kein Sprechtoken steht in einer Meldung, ein leeres Tokenfeld heisst
     * "behalten", der Haken loescht, beides zugleich ist ein Widerspruch. */
    foreach (rn_ansage_anlaesse() as $rn_a) {
        $neu[$rn_a[0]] = isset($_POST[$rn_a[0]]) ? 'Y' : 'N';
    }
    $rn_tmangel = array();
    $rn_tbean = array();
    $rn_tneu = ansage_formular_lesen($_POST, rn_tts($rn_cfg), $rn_tmangel, $rn_tbean, rn_ansage_opt(),
                                     rn_ansage_k());
    foreach ($rn_tmangel as $rn_tm) {
        $rn_fehler[] = rn_e($rn_tm['text']);
    }
    foreach ($rn_tbean as $rn_tb) {
        rn_bean($rn_tb);     // X-2
    }
    $neu = array_merge($neu, rn_tts_flach($rn_tneu));

    /* Sicherheitsnetz: jeder in DIESEM Speichern geaenderte Wert muss die
     * Pruefung bestehen, die beim Zurueckspielen gilt - sonst waere die
     * eigene Sicherung nicht zurueckspielbar. Unveraenderte Altwerte
     * blockieren das Speichern nicht. */
    if (!$rn_fehler) {
        foreach (rn_vorgaben() as $k => $rn_v0) {
            if ((string) $neu[$k] === (string) $rn_cfg[$k]) { continue; }
            if (!rn_wert_taugt($neu[$k]) || !rn_wert_pruefen($k, (string) $neu[$k])) {
                $rn_fehler[] = sprintf(rn_t('TEXT.F_WERT_ALLG'), rn_e($k));
                rn_bean($k);
            }
        }
    }

    if (!$rn_fehler) {
        if (rn_config_write($neu)) {
            // Zwischenspeicher aller Fahrzeuge verwerfen - die Daten
            // koennten zu einer anderen Fahrgestellnummer gehoeren.
            // is_file davor: das @ unterdrueckt zwar die Ausgabe, ruft aber
            // trotzdem einen gesetzten Fehler-Aufnehmer - und rendern.py
            // haengt sich genau so ein.
            foreach (rn_fahrzeuge($neu) as $rn_f) {
                if (is_file($rn_f['session'])) { @unlink($rn_f['session']); }
            }
            $rn_cfg = rn_config_read();
            $rn_meldung = rn_t('TEXT.M_GESPEICHERT');
            /* Umbenannt oder entfernt (Befund M3): der Fahrzeugname ist der
             * Themenpraefix. Bis 2.1.12 blieben die Zustaende unter dem alten
             * Namen fuer immer retained im Broker, und das Gateway lieferte sie
             * nach jedem Neustart wieder an Loxone. Je weggefallenem Namen:
             * leeren, beim Broker nachlesen, das Ergebnis in die Meldung. */
            $rn_namen_nachher = array();
            foreach (rn_fahrzeuge($rn_cfg) as $rn_f0) { $rn_namen_nachher[] = $rn_f0['name']; }
            // Seit dem Welle-4-Bau an EINER Stelle (rn_lib.php), auch fuer das
            // Zurueckspielen (Renault-a2).
            $rn_alt = rn_mqtt_altnamen_abraeumen($rn_namen_vorher, $rn_namen_nachher);
            $rn_meldung .= $rn_alt['meldung'];
            $rn_misslungen = array_merge($rn_misslungen, $rn_alt['misslungen']);
        } else {
            $rn_fehler[] = rn_t('TEXT.F_SCHREIBEN');
        }
    }
    /* X-2: nur nach einer Beanstandung (dann ist nichts gespeichert) reisen
     * die eingetippten Werte zurueck - nie die Geheimnisse. */
    if ($rn_fehler) {
        $rn_eingaben_post = rn_eingaben_sammeln('einst');
    }
    $rn_tab = 'tab-settings';
}

/* Log leeren und Zwischenspeicher verwerfen: die Rueckgabe von unlink()
 * zaehlt (Befund U9). Bis 2.1.12 kam die Erfolgsmeldung bedingungslos, auch
 * wenn eine Datei stehen blieb (etwa eine, die root gehoert). */
/* Renault-b1: die gelesenen Felder einer beiseitegelegten Konfiguration ins
 * Formular holen - ueber denselben Weg wie X-2. Gespeichert wird erst mit
 * "Speichern"; Geheimnisse reisen nie. */
if (isset($_POST['kaputt_uebernehmen'])) {
    $rn_ang = rn_kaputt_angebot($rn_cfg);
    if ($rn_ang === null || !$rn_ang['felder']) {
        $rn_misslungen[] = rn_t('TEXT.F_KAPUTT_WEG');
    } else {
        $rn_werte = array();
        foreach (rn_eingabe_felder('einst') as $f) { $rn_werte[$f] = (string) $rn_cfg[$f]; }
        foreach ($rn_ang['felder'] as $k => $v) { $rn_werte[$k] = $v; }
        $rn_eingaben_post = array('formular' => 'einst', 'werte' => $rn_werte, 'falsch' => array());
        $rn_meldung = sprintf(rn_t('TEXT.M_KAPUTT_IM_FORMULAR'),
                              rn_e(implode(', ', array_keys($rn_ang['felder']))));
        renault_log('INFO', 'Werte aus der beiseitegelegten Konfiguration ' . basename($rn_ang['datei'])
            . ' ins Formular geholt (noch nicht gespeichert): ' . implode(', ', array_keys($rn_ang['felder'])));
    }
    $rn_tab = 'tab-settings';
}
if (isset($_POST['kaputt_verwerfen'])) {
    if (empty($_POST['kaputt_ja'])) {
        $rn_fehler[] = rn_t('TEXT.F_KAPUTT_HAKEN');
    } else {
        $rn_weg = array();
        $rn_rest = array();
        foreach (rn_kaputt_dateien() as $rn_d) {
            if (@unlink($rn_d)) { $rn_weg[] = basename($rn_d); } else { $rn_rest[] = basename($rn_d); }
        }
        if ($rn_rest) {
            $rn_misslungen[] = sprintf(rn_t('TEXT.F_KAPUTT_LOESCHEN'), rn_e(implode(', ', $rn_rest)));
        }
        if ($rn_weg) {
            $rn_meldung = sprintf(rn_t('TEXT.M_KAPUTT_GELOESCHT'), rn_e(implode(', ', $rn_weg)));
            renault_log('INFO', 'Beiseitegelegte Konfiguration geloescht: ' . implode(', ', $rn_weg));
        } elseif (!$rn_rest) {
            $rn_misslungen[] = rn_t('TEXT.F_KAPUTT_WEG');
        }
    }
    $rn_tab = 'tab-settings';
}

if (isset($_POST['log_leeren'])) {
    $rn_rest = array();
    // .tag: Merker der Tageszeile (Renault-a1, renault_log_taeglich()).
    foreach (array($rn_p['log'], $rn_p['log'] . '.1', $rn_p['log'] . '.wdh', $rn_p['log'] . '.tag') as $rn_d) {
        if (is_file($rn_d) && !@unlink($rn_d)) { $rn_rest[] = basename($rn_d); }
    }
    if ($rn_rest) {
        $rn_misslungen[] = sprintf(rn_t('TEXT.F_LOG_LEEREN'), rn_e(implode(', ', $rn_rest)));
    } else {
        $rn_meldung = rn_t('TEXT.M_LOG_LEER');
    }
    $rn_tab = 'tab-log';
}

if (isset($_POST['cache_leeren'])) {
    $rn_rest = array();
    foreach (rn_fahrzeuge($rn_cfg) as $rn_f) {
        if (is_file($rn_f['session']) && !@unlink($rn_f['session'])) { $rn_rest[] = basename($rn_f['session']); }
    }
    if (is_file($rn_p['anmeldung']) && !@unlink($rn_p['anmeldung'])) { $rn_rest[] = basename($rn_p['anmeldung']); }
    if ($rn_rest) {
        $rn_misslungen[] = sprintf(rn_t('TEXT.F_CACHE_LEEREN'), rn_e(implode(', ', $rn_rest)));
    } else {
        $rn_meldung = rn_t('TEXT.M_CACHE_LEER');
    }
    $rn_tab = 'tab-test';
}

/* ---------------- Testansage (Nr. 36 b, seit 2.1.16) ----------------
 * Spricht den Pruefsatz des Moduls ueber die eingestellte Ausgabeart - unabhaengig
 * von den Anlaessen. Ins Protokoll nur die Kurzform ohne Text und Token. F5 nach dem
 * Knopf spricht nicht erneut: der PRG-Block unten leitet um (303). */
if (isset($_POST['ansage_test'])) {
    $rn_ak = rn_ansage_k();
    $rn_ar = ansage_testansage(rn_tts($rn_cfg), $rn_ak);
    renault_log($rn_ar['stand'] === 0 ? 'WARN' : 'INFO', 'Testansage: ' . ansage_kurz($rn_ar));
    if ($rn_ar['stand'] === 1) {
        $rn_meldung = rn_e(rn_t('TEXT.M_ANSAGE_TEST_OK'));
    } elseif ($rn_ar['stand'] === -1) {
        $rn_meldung = rn_e(sprintf(rn_t('TEXT.M_ANSAGE_TEST_NICHTS'),
                                   ansage_kennung_text($rn_ar['kennung'], $rn_ak)));
    } else {
        $rn_misslungen[] = rn_e(sprintf(rn_t('TEXT.M_ANSAGE_TEST_FEHL'),
                                        ansage_kennung_text($rn_ar['kennung'], $rn_ak)));
    }
    $rn_tab = 'tab-test';
}

if (isset($_POST['test'])) {
    require_once __DIR__ . '/rn_test.php';
    list($rn_test_titel, $rn_test_text) = rn_test_ausfuehren((string) $_POST['test'], $rn_cfg);
    $rn_tab = 'tab-test';
}

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
// ---------- Loxone-Vorlage herunterladen (Hausstandard) ----------
/* Nur noch die Befehle (vo). Die Eingangsvorlage (vi) ist mit 2.1.9
 * entfallen; ein Absenden aus einer noch offenen alten Seite laeuft ins
 * normale Seitenrendern statt in eine falsche Datei. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage'])
    && $_POST['vorlage'] === 'vo') {
    list($rn_vname, $rn_vinhalt) = rn_vorlage_vo();
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $rn_vname . '"');
    echo $rn_vinhalt;
    exit;
}

$rn_broker  = rn_mqtt_broker();
$rn_autos   = rn_fahrzeuge($rn_cfg);
$rn_zeilen  = rn_log_tail();

$template_title = 'Renault NG';
$helplink       = 'https://wiki.loxberry.de/plugins/renault_ng/start';
$helptemplate   = 'help.html';

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rn_sichern'])) {
    /* Lesbarer Kopf: der Datei sieht sonst niemand an, von welchem Plugin,
     * welcher Anlage und welchem Tag sie stammt - und beim Umzug auf einen
     * zweiten LoxBerry ist genau das der Zweck. Die Schluessel beginnen mit
     * einem Unterstrich; rn_sicherung_lesen() UEBERGEHT sie, statt sie zu
     * beanstanden. */
    /* Kopf und Inhalt kommen seit dem Welle-4-Bau aus rn_sicherung_inhalt()
     * (rn_lib.php) - dieselbe Datei prueft rn_rueckspiel_altwerte() (X-3).
     * Wuerde das eigene Zurueckspielen sie abweisen, traegt der Kopf
     * '_warnung' - nur Namen, nie Werte; geliefert wird sie trotzdem
     * vollstaendig. */
    $rn_inhalt = rn_sicherung_inhalt();
    $rn_sich_warn = rn_rueckspiel_altwerte();
    if ($rn_sich_warn) {
        $rn_inhalt = array('_warnung' => sprintf(rn_t('TEXT.SICH_WARN_KOPF'),
                                                 implode(', ', $rn_sich_warn))) + $rn_inhalt;
    }
    $rn_js = json_encode($rn_inhalt,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($rn_js !== false) {
        renault_log('INFO', 'Einstellungen gesichert (Datei enthaelt Zugangsdaten '
            . 'und das Aktionstoken).');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="renault_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $rn_js;
        exit;
    }
    $rn_fehler[] = rn_t('TEXT.SICH_SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei
 * des Servers unterschieben. Dann die Groessengrenze - eine Sicherung
 * dieses Plugins ist wenige Kilobyte gross; alles darueber wird gar
 * nicht erst gelesen. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rn_zurueck'])) {
    if (!isset($_FILES['rn_sicherung']) || !is_array($_FILES['rn_sicherung'])
        || !isset($_FILES['rn_sicherung']['tmp_name'])
        /* is_string() zuerst (Befund U8): ein Feld rn_sicherung[] liefert hier
         * ein Feld, und is_uploaded_file() warf darauf unter PHP 8 einen
         * TypeError - HTTP 500 mit leerer Seite. */
        || !is_string($_FILES['rn_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['rn_sicherung']['tmp_name'])) {
        $rn_fehler[] = rn_t('TEXT.SICH_KEINE_DATEI');
    // 64 kB wie im Hausmuster; die echte Datei ist rund ein Kilobyte gross.
    } elseif ((int) $_FILES['rn_sicherung']['size'] > 65536) {
        $rn_fehler[] = rn_t('TEXT.SICH_ZU_GROSS');
    } else {
        /* Die Fahrzeugnamen VOR dem Zurueckspielen (Renault-a2, Befund M3):
         * eine Sicherung mit anderen Namen laesst sonst die Themen unter dem
         * alten Namen fuer immer im Broker. */
        $rn_namen_vorher = array();
        foreach (rn_fahrzeuge($rn_cfg) as $rn_f0) { $rn_namen_vorher[] = $rn_f0['name']; }
        list($rn_neu, $rn_mangel, $rn_n, $rn_hinweise) = rn_sicherung_lesen(
            (string) @file_get_contents($_FILES['rn_sicherung']['tmp_name']));
        if ($rn_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert
             * wird nichts. */
            $rn_fehler[] = rn_t('TEXT.SICH_ABGELEHNT') . ' ' . implode(' ', $rn_mangel);
            renault_log('WARN', 'Eine zurueckgespielte Sicherung wurde abgelehnt, '
                . 'die Konfiguration ist unveraendert. Erste Beanstandung: '
                . strip_tags((string) $rn_mangel[0]));
        } elseif (rn_config_write($rn_neu)) {
            /* Der Zwischenspeicher gehoert zu einer anderen Fahrgestellnummer
             * und die Anmeldung zu einem anderen Konto - beides wird
             * verworfen, wie es der Speichern-Handler auch tut. Und dem
             * Anwender wird gesagt, was mit dem Abruf geschieht: einen Dienst
             * zum Nachziehen gibt es nicht, den Takt macht der Cron. */
            foreach (rn_fahrzeuge($rn_neu) as $rn_f2) {
                if (is_file($rn_f2['session'])) { @unlink($rn_f2['session']); }
            }
            if (is_file($rn_p['anmeldung'])) { @unlink($rn_p['anmeldung']); }
            $rn_cfg = rn_config_read();
            /* Erfolg erst nach dem Zuruecklesen (Befund U4). Bis 2.1.12 hiess
             * es "zurueckgespielt", waehrend rn_config_read() im selben Aufruf
             * eine Sicherung ohne Token aus der Zweitschrift "heilte" - der alte
             * Stand stand wieder da, die zurueckgespielten Werte lagen als
             * .kaputt daneben. */
            $rn_anders = array();
            foreach (rn_vorgaben() as $k => $rn_v0) {
                if ((string) $rn_cfg[$k] !== (string) $rn_neu[$k]) { $rn_anders[] = $k; }
            }
            if ($rn_anders) {
                $rn_misslungen[] = sprintf(rn_t('TEXT.SICH_NICHT_WIRKSAM'),
                    rn_e(implode(', ', array_slice($rn_anders, 0, 8))));
                renault_log('ERROR', 'Die zurueckgespielte Sicherung steht beim Zuruecklesen '
                    . 'nicht da (abweichend: ' . implode(', ', $rn_anders) . ').');
            } else {
                $rn_meldung = sprintf(rn_t('TEXT.SICH_UEBERNOMMEN'), $rn_n) . ' '
                            . ($rn_hinweise ? implode(' ', $rn_hinweise) . ' ' : '')
                            . sprintf(rn_t('TEXT.SICH_DIENST'),
                                      max(1, (int) $rn_neu['cron_ncs']));
                renault_log('INFO', 'Sicherung zurueckgespielt: ' . $rn_n . ' Werte. '
                    . 'Zwischenspeicher und Anmeldung verworfen; der naechste Cron-Lauf '
                    . 'meldet sich mit den neuen Zugangsdaten an.');
                /* Renault-a2: weggefallene Fahrzeugnamen wie beim Speichern
                 * abraeumen - erst hier, wenn die Sicherung nachweislich
                 * dasteht. */
                $rn_namen_nachher = array();
                foreach (rn_fahrzeuge($rn_cfg) as $rn_f0) { $rn_namen_nachher[] = $rn_f0['name']; }
                $rn_alt = rn_mqtt_altnamen_abraeumen($rn_namen_vorher, $rn_namen_nachher);
                $rn_meldung .= $rn_alt['meldung'];
                $rn_misslungen = array_merge($rn_misslungen, $rn_alt['misslungen']);
            }
        } else {
            $rn_fehler[] = rn_t('TEXT.SICH_SCHREIBFEHLER');
            renault_log('ERROR', 'Die zurueckgespielte Sicherung liess sich nicht '
                . 'schreiben; die alte Konfiguration steht unveraendert.');
        }
    }
}

/* ==================================================================
 * PRG: JEDER POST ENDET MIT EINER UMLEITUNG (Befund U1, Regeln/04)
 * ==================================================================
 * Bis 2.1.12 antwortete jeder POST mit HTTP 200 und der fertigen Seite; F5
 * wiederholte Speichern, Zurueckspielen und die Pruefknoepfe (mit php -S
 * gemessen: sechs Handler, keine Location). Das Ergebnis reist jetzt als
 * Einmalmeldung (rn_einmal_schreiben(), 0600, 120 s) zum folgenden GET -
 * auch das eines POST, den der Wachposten abgewiesen hat. Die Downloads
 * (Vorlage, Sicherung) sind oben schon mit exit hinaus. Laesst sich die
 * Einmalmeldung nicht ablegen, wird wie bisher ohne Umleitung gezeigt: eine
 * Meldung darf nicht verlorengehen. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (rn_einmal_schreiben($rn_meldung, $rn_fehler, $rn_misslungen,
                            $rn_test_titel, $rn_test_text, $rn_cfg, $rn_eingaben_post)) {
        header('Location: index.php?form=' . rawurlencode(substr($rn_tab, 4)), true, 303);
        exit;
    }
    renault_log('WARN', 'Die Einmalmeldung liess sich nicht ablegen - die Seite wird '
        . 'ohne Umleitung gezeigt.');
}

/* Erst hier, nach ALLEN Handlern (Befund U2). */
$rn_ftoken = rn_formtoken($rn_cfg);
/* Renault-b1: gibt es eine beiseitegelegte Konfiguration mit lesbaren,
 * abweichenden Feldern? */
$rn_angebot = rn_kaputt_angebot($rn_cfg);

LBWeb::lbheader($template_title, $helplink, $helptemplate);

?>

<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss.
   Uebernommen aus VORLAGE_hausstandard.css.html - bis 2.0.6 fuehrte dieses
   Plugin fuer die Reiterflaechen einen eigenen Klassennamen. Damit lief die
   Gegenprobe aus der Pflichtpruefung ins Leere: sie sucht die Hausklasse der
   Flaeche zusammen mit der Kennung des Reiters.

   Der gesuchte Wortlaut steht hier ABSICHTLICH nicht - ein Kommentar, der
   die Zeichenfolge traegt, nach der ein Werkzeug sucht, wird als erste
   Fundstelle gelesen und das Werkzeug misst dann den Kommentar. Bis 2.1.5
   war genau das der Fall: die Zeile war die einzige woertliche Fundstelle
   in der ganzen Datei. */
.sm-wrap { max-width: 1100px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3, .sm-wrap h3.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
.sm-feld input, .sm-feld select { width: 100%; max-width: 420px; padding: 7px; box-sizing: border-box; }
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 720px; }
.sm-small { font-size: 0.88em; color: #555; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
/* Rollbehaelter fuer breite Tabellen. Ohne ihn sind Spalten ausserhalb des
   Fensters unerreichbar: .sm-tbl steht auf width:100%, der Behaelter auf
   max-width. Die Ladehistorie hat 17 Spalten. Wortgetreu aus
   VORLAGE_hausstandard.css.html. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }

/* Ein Auswahlfeld muss man als Auswahlfeld erkennen.
   Die Rahmen-CSS von jQuery Mobile setzt appearance:none und nimmt den Pfeil
   weg; data-role="none" haelt nur deren Umbauten fern, nicht deren
   Stilregeln. Ohne diese Regel sehen alle zehn Auswahlfelder dieser Seite
   aus wie Textfelder. Die Raute im SVG steht als %23 - eine rohe Raute
   beendet den CSS-Wert. Wortgetreu aus VORLAGE_hausstandard.css.html. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }

.sm-vorschau { background: #f4f4f4; border: 1px solid #ccc; padding: 10px;
    font-family: monospace; white-space: pre-wrap; font-size: 0.86em; overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES Knopf-Element mit
   eigenem Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse
   Schrift auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht.

   Der Elementname steht hier absichtlich NICHT ausgeschrieben: die Pruefung
   "jeder Knopf traegt data-role" sucht ihn im Quelltext, und ein Kommentar,
   der ihn erwaehnt, meldet sich als Knopf ohne Attribut. Dieselbe Klasse
   steht in REGELN_1 zweimal - einmal mit einer Zeichenfolge, die einen
   Skriptblock beendete, einmal mit einem Beispiel in der Form der echten
   Reiterliste. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-info { border: 1px solid #546e7a; background: #eef3f7; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-log { background: #1e1e1e; color: #ddd; font-family: monospace; font-size: 0.82em;
  padding: 10px; border-radius: 6px; max-height: 460px; overflow: auto; white-space: pre-wrap; }
/* sm-kacheln, sm-kachel und sm-pre sind mit 2.1.6 entfallen: sie standen
   im Stilblock und kamen in der ganzen Linie kein einziges Mal vor.
   sm-an bleibt und wird jetzt auch benutzt - bis 2.1.5 war nur das rote
   sm-aus verdrahtet, die Zustandsanzeige also einseitig gefaerbt. */
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
/* Eigene Ergaenzung (X-2, Welle-4-Bau): ein beanstandetes Feld. Steht
   zusammen mit aria-invalid; die Farbe allein waere fuer einen Vorleser
   unsichtbar. */
.sm-wrap .sm-beanstandet { border: 2px solid #b00000 !important; background: #fff4f4; }
</style>

<div class="sm-wrap">

<?php if ($rn_fehler) { ?>
<div class="sm-warnung"><b><?php echo rn_e(rn_t('TEXT.NICHT_GESPEICHERT')); ?></b><ul>
<?php foreach ($rn_fehler as $f) { echo '<li>' . $f . '</li>'; } ?>
</ul></div>
<?php } elseif ($rn_meldung !== '') { ?>
<div class="sm-hinweis"><?php echo $rn_meldung; ?></div>
<?php } ?>
<?php if ($rn_misslungen) { ?>
<div class="sm-warnung"><b><?php echo rn_e(rn_t('TEXT.NICHT_GELUNGEN')); ?></b><ul>
<?php foreach ($rn_misslungen as $f) { echo '<li>' . $f . '</li>'; } ?>
</ul></div>
<?php } ?>

<?php /* Kopf (Entscheidung Nr. 43, seit 2.1.17): Statusuebersicht ueber den
   Reitern, immer sichtbar. Renault hat keinen Dienst - der Abruf laeuft per
   Cron. Nur Werte, die die Seite ohnehin liest (Konfiguration, die
   session-Datei je Fahrzeug wie im Reiter Test, rn_mqtt_broker()); keine
   Netzabfrage. Vom Konto steht nur, ob Zugangsdaten da sind, nie der Name. */ ?>
<table class="sm-tbl" style="max-width:620px">
<tr><th><?php echo rn_e(rn_t('TEXT.KOPF_EIGENSCHAFT')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_WERT')); ?></th></tr>
<tr><td><?php echo rn_e(rn_t('TEXT.KOPF_ABRUF')); ?></td>
    <td><?php echo rn_e(sprintf(rn_t('TEXT.KOPF_OHNE_DIENST'), (int) $rn_cfg['cron_ncs'], (int) $rn_cfg['cron_acs'])); ?></td></tr>
<?php $rn_kk = ((string) $rn_cfg['username'] !== '' && (string) $rn_cfg['password'] !== ''); ?>
<tr><td><?php echo rn_e(rn_t('TEXT.KOPF_KONTO')); ?></td>
    <td class="<?php echo $rn_kk ? 'sm-an' : 'sm-aus'; ?>"><?php echo rn_e($rn_kk ? rn_t('TEXT.KOPF_KONTO_JA') : rn_t('TEXT.KOPF_KONTO_NEIN')); ?></td></tr>
<?php foreach ($rn_autos as $rn_kf) {
    $rn_ks = rn_session($rn_kf['nr']);
    $rn_kz = ($rn_ks && isset($rn_ks[25])) ? trim((string) $rn_ks[25]) : '';
    if (preg_match('/^[0-9]{12}$/', $rn_kz)) {
        $rn_kz = substr($rn_kz, 6, 2) . '.' . substr($rn_kz, 4, 2) . '.' . substr($rn_kz, 0, 4)
               . ' ' . substr($rn_kz, 8, 2) . ':' . substr($rn_kz, 10, 2);
    } ?>
<tr><td><?php echo rn_e(sprintf(rn_t('TEXT.KOPF_LETZTER_ERFOLG'), $rn_kf['name'])); ?></td>
    <td><?php echo $rn_kz !== '' ? rn_e($rn_kz) : rn_e(rn_t('TEXT.KOPF_NOCH_KEIN')); ?></td></tr>
<?php } ?>
<tr><td><?php echo rn_e(rn_t('TEXT.KOPF_BROKER')); ?></td>
    <td<?php echo $rn_broker ? '' : ' class="sm-aus"'; ?>><?php echo $rn_broker
        ? '<span class="sm-mono">' . rn_e($rn_broker['host'] . ':' . $rn_broker['port']) . '</span>'
        : rn_e(rn_t('TEXT.KOPF_KEIN_BROKER')); ?></td></tr>
<tr><td><?php echo rn_e(rn_t('TEXT.KOPF_SCHALTEN')); ?></td>
    <td><?php echo rn_e($rn_cfg['steuerung_ein'] === 'Y' ? rn_t('TEXT.KOPF_SCHALTEN_JA') : rn_t('TEXT.KOPF_SCHALTEN_NEIN')); ?></td></tr>
</table>

<!-- Reiterleiste: echte Verweise, sm-active vom SERVER, ausgeschrieben.
     Bis 1.4 standen hier <div> ohne Verweis, und sm-active vergab allein das
     JavaScript. Da .sm-seite auf display:none steht, war die Seite ohne
     JavaScript vollstaendig leer - und die Reiter liessen sich nicht einmal
     anklicken, weil ein <div> kein Verweis ist.
     Die Namen muessen mit $rn_reiter oben und den id der Bereiche unten
     uebereinstimmen; der Reiter Test misst das nach. -->
<div class="sm-tabs">
  <a class="sm-tab<?php echo $rn_tab === 'tab-settings' ? ' sm-active' : ''; ?>" data-ziel="tab-settings" href="index.php?form=settings"><?php echo rn_e(rn_t('REITER.EINSTELLUNGEN')); ?></a>
  <a class="sm-tab<?php echo $rn_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" data-ziel="tab-mqtt" href="index.php?form=mqtt">MQTT</a>
  <a class="sm-tab<?php echo $rn_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" data-ziel="tab-loxone" href="index.php?form=loxone"><?php echo rn_e(rn_t('REITER.LOXONE')); ?></a>
  <a class="sm-tab<?php echo $rn_tab === 'tab-test' ? ' sm-active' : ''; ?>" data-ziel="tab-test" href="index.php?form=test"><?php echo rn_e(rn_t('REITER.TEST')); ?></a>
  <a class="sm-tab<?php echo $rn_tab === 'tab-verlauf' ? ' sm-active' : ''; ?>" data-ziel="tab-verlauf" href="index.php?form=verlauf"><?php echo rn_e(rn_t('REITER.VERLAUF')); ?></a>
  <a class="sm-tab<?php echo $rn_tab === 'tab-log' ? ' sm-active' : ''; ?>" data-ziel="tab-log" href="index.php?form=log"><?php echo rn_e(rn_t('REITER.LOG')); ?></a>
</div>

<!-- ============================ Einstellungen ============================ -->
<div class="sm-seite<?php echo $rn_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">
<div class="sm-hinweis"><?php echo rn_t('TEXT.WAS_IST_DAS'); ?></div>
<?php if ($rn_angebot) { /* Renault-b1 */ ?>
<div class="sm-warnung">
<p><?php echo sprintf(rn_t('TEXT.KAPUTT_ANGEBOT'), rn_e(basename($rn_angebot['datei'])),
        rn_e(date('d.m.Y H:i', $rn_angebot['zeit']))); ?></p>
<?php if ($rn_angebot['felder']) { ?>
<p><?php echo sprintf(rn_t('TEXT.KAPUTT_FELDER'), rn_e(implode(', ', array_keys($rn_angebot['felder'])))); ?></p>
<?php } ?>
<?php if ($rn_angebot['geheim']) { ?>
<p><?php echo sprintf(rn_t('TEXT.KAPUTT_GEHEIM'), rn_e(implode(', ', $rn_angebot['geheim']))); ?></p>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo rn_e(rn_t('LEGENDE.LESEN')); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo rn_e(rn_t('LEGENDE.AKTION')); ?></span>
</div>
<div class="sm-knopfreihe">
<?php if ($rn_angebot['felder']) { ?>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="kaputt_uebernehmen" value="1"><?php echo rn_e(rn_t('TEXT.K_KAPUTT_UEBERNEHMEN')); ?></button>
  </form>
<?php } ?>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <label class="sm-small" style="align-self:center;margin-right:8px"><input data-role="none" type="checkbox" name="kaputt_ja" value="1" style="width:auto;margin:0 6px 0 0;vertical-align:middle"><?php echo rn_e(rn_t('TEXT.L_KAPUTT_JA')); ?></label>
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="kaputt_verwerfen" value="1"><?php echo rn_e(rn_t('TEXT.K_KAPUTT_VERWERFEN')); ?></button>
  </form>
</div>
</div>
<?php } ?>
<form method="post" action="index.php" autocomplete="off">
<input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo rn_e(rn_t('TEXT.H_KONTO')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_KONTO_TEXT'); ?></p>

<div class="sm-feld">
  <label for="username"><?php echo rn_e(rn_t('TEXT.L_BENUTZER')); ?></label>
  <input data-role="none" type="text" id="username" name="username" value="<?php echo rn_e(rn_ein_w('username', $rn_cfg['username'])); ?>"<?php echo rn_ein_m('username'); ?>>
</div>
<div class="sm-feld">
  <label for="password"><?php echo rn_e(rn_t('TEXT.L_PASSWORT')); ?></label>
  <input data-role="none" type="password" id="password" name="password" value=""<?php echo rn_ein_m('password'); ?>
         placeholder="<?php echo rn_e($rn_cfg['password'] !== '' ? rn_t('TEXT.P_GESPEICHERT') : rn_t('TEXT.P_NICHT_GESETZT')); ?>">
  <p class="sm-hilfe"><label><input data-role="none" type="checkbox" name="password_loeschen" value="1" style="width:auto;margin:0 6px 0 0;vertical-align:middle"><?php echo rn_e(rn_t('TEXT.L_PASSWORT_LOESCHEN')); ?></label></p>
<?php if ($rn_angebot && in_array('password', $rn_angebot['geheim'], true)) { /* Renault-b1 */ ?>
  <p class="sm-hilfe"><label><input data-role="none" type="checkbox" name="password_aus_kaputt" value="1" style="width:auto;margin:0 6px 0 0;vertical-align:middle"><?php echo rn_e(sprintf(rn_t('TEXT.L_PASSWORT_AUS_KAPUTT'), basename($rn_angebot['datei']))); ?></label></p>
<?php } ?>
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_PASSWORT')); ?></p>
</div>
<div class="sm-feld">
  <label for="country"><?php echo rn_e(rn_t('TEXT.L_LAND')); ?></label>
  <select data-role="none" id="country" name="country"<?php echo rn_ein_m('country'); ?>>
    <?php foreach (array('DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz',
                         'IT' => 'Italia', 'SE' => 'Sverige', 'FR' => 'France',
                         'EN' => rn_t('TEXT.L_ANDERE')) as $k => $v) { ?>
      <option value="<?php echo rn_e($k); ?>"<?php echo rn_ein_w('country', $rn_cfg['country']) === $k ? ' selected' : ''; ?>><?php echo rn_e($v); ?></option>
    <?php } ?>
  </select>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_FAHRZEUGE')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_FAHRZEUGE_TEXT'); ?></p>
<?php for ($rn_i = 1; $rn_i <= RN_MAX_FAHRZEUGE; $rn_i++) {
    $rn_nr = ($rn_i === 1) ? '' : (string) $rn_i; ?>
<h3><?php echo rn_e(sprintf(rn_t('TEXT.H_FAHRZEUG_NR'), $rn_i)); ?><?php
    echo $rn_i > 1 ? ' <span class="sm-small">' . rn_e(rn_t('TEXT.H_OPTIONAL')) . '</span>' : ''; ?></h3>
<div class="sm-feld">
  <label for="zoename<?php echo rn_e($rn_nr); ?>"><?php echo rn_e(rn_t('TEXT.L_NAME')); ?></label>
  <input data-role="none" type="text" id="zoename<?php echo rn_e($rn_nr); ?>" name="zoename<?php echo rn_e($rn_nr); ?>"
         value="<?php echo rn_e(rn_ein_w('zoename' . $rn_nr, $rn_cfg['zoename' . $rn_nr])); ?>"<?php echo rn_ein_m('zoename' . $rn_nr); ?>>
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_NAME')); ?>
     <span class="sm-mono">Renault/<?php echo rn_e($rn_cfg['zoename' . $rn_nr]); ?>/…</span></p>
</div>
<div class="sm-feld">
  <label for="vin<?php echo rn_e($rn_nr); ?>"><?php echo rn_e(rn_t('TEXT.L_VIN')); ?></label>
  <input data-role="none" type="text" maxlength="17" id="vin<?php echo rn_e($rn_nr); ?>" name="vin<?php echo rn_e($rn_nr); ?>"
         value="<?php echo rn_e(rn_ein_w('vin' . $rn_nr, $rn_cfg['vin' . $rn_nr])); ?>"<?php echo rn_ein_m('vin' . $rn_nr); ?>>
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_VIN')); ?></p>
</div>
<div class="sm-feld">
  <label for="zoeph<?php echo rn_e($rn_nr); ?>"><?php echo rn_e(rn_t('TEXT.L_PHASE')); ?></label>
  <select data-role="none" id="zoeph<?php echo rn_e($rn_nr); ?>" name="zoeph<?php echo rn_e($rn_nr); ?>"<?php echo rn_ein_m('zoeph' . $rn_nr); ?>>
    <option value="1"<?php echo rn_ein_w('zoeph' . $rn_nr, $rn_cfg['zoeph' . $rn_nr]) === '1' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_PHASE1')); ?></option>
    <option value="2"<?php echo rn_ein_w('zoeph' . $rn_nr, $rn_cfg['zoeph' . $rn_nr]) === '2' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_PHASE2')); ?></option>
  </select>
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_PHASE')); ?>
  <?php $rn_sx = rn_session($rn_i); if ($rn_sx && isset($rn_sx[26]) && $rn_sx[26] !== '') { ?>
     <b><?php echo rn_e(sprintf(rn_t('TEXT.H_MODELLKENNUNG'), $rn_sx[26])); ?></b>
  <?php } ?></p>
</div>
<?php } ?>

<h2><?php echo rn_e(rn_t('TEXT.H_TAKT')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_TAKT_TEXT'); ?></p>
<div class="sm-feld">
  <label for="cron_ncs"><?php echo rn_e(rn_t('TEXT.L_CRON_NCS')); ?></label>
  <input data-role="none" type="number" min="1" max="60" id="cron_ncs" name="cron_ncs" value="<?php echo rn_e(rn_ein_w('cron_ncs', $rn_cfg['cron_ncs'])); ?>"<?php echo rn_ein_m('cron_ncs'); ?>>
</div>
<div class="sm-feld">
  <label for="cron_acs"><?php echo rn_e(rn_t('TEXT.L_CRON_ACS')); ?></label>
  <input data-role="none" type="number" min="1" max="60" id="cron_acs" name="cron_acs" value="<?php echo rn_e(rn_ein_w('cron_acs', $rn_cfg['cron_acs'])); ?>"<?php echo rn_ein_m('cron_acs'); ?>>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_SCHALTEN')); ?></h2>
<div class="sm-warnung"><?php echo rn_t('TEXT.H_SCHALTEN_TEXT'); ?></div>
<div class="sm-feld">
  <label for="steuerung_ein"><?php echo rn_e(rn_t('TEXT.L_STEUERUNG')); ?></label>
  <select data-role="none" id="steuerung_ein" name="steuerung_ein"<?php echo rn_ein_m('steuerung_ein'); ?>>
    <option value="N"<?php echo rn_ein_w('steuerung_ein', $rn_cfg['steuerung_ein']) !== 'Y' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_AUS_VORGABE')); ?></option>
    <option value="Y"<?php echo rn_ein_w('steuerung_ein', $rn_cfg['steuerung_ein']) === 'Y' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_EIN')); ?></option>
  </select>
</div>
<div class="sm-feld">
  <label for="ac_temp"><?php echo rn_e(rn_t('TEXT.L_AC_TEMP')); ?></label>
  <input data-role="none" type="number" min="16" max="30" id="ac_temp" name="ac_temp" value="<?php echo rn_e(rn_ein_w('ac_temp', $rn_cfg['ac_temp'])); ?>"<?php echo rn_ein_m('ac_temp'); ?>>
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_AC_TEMP')); ?></p>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_MELDUNGEN')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_MELDUNGEN_TEXT'); ?></p>
<div class="sm-feld">
  <label for="bl_schwelle"><?php echo rn_e(rn_t('TEXT.L_BL_SCHWELLE')); ?></label>
  <input data-role="none" type="number" min="1" max="99" id="bl_schwelle" name="bl_schwelle" value="<?php echo rn_e(rn_ein_w('bl_schwelle', $rn_cfg['bl_schwelle'])); ?>"<?php echo rn_ein_m('bl_schwelle'); ?>>
</div>
<?php foreach (array('mail_bl' => 'TEXT.L_MAIL_BL', 'cmon_bl' => 'TEXT.L_CMON_BL',
                     'mail_csf' => 'TEXT.L_MAIL_CSF') as $rn_k => $rn_lbl) { ?>
<div class="sm-feld">
  <label for="<?php echo rn_e($rn_k); ?>"><?php echo rn_e(rn_t($rn_lbl)); ?></label>
  <select data-role="none" id="<?php echo rn_e($rn_k); ?>" name="<?php echo rn_e($rn_k); ?>"<?php echo rn_ein_m($rn_k); ?>>
    <option value="N"<?php echo rn_ein_w($rn_k, $rn_cfg[$rn_k]) !== 'Y' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_NEIN')); ?></option>
    <option value="Y"<?php echo rn_ein_w($rn_k, $rn_cfg[$rn_k]) === 'Y' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_JA')); ?></option>
  </select>
</div>
<?php } ?>
<div class="sm-feld">
  <label for="exec_bl"><?php echo rn_e(rn_t('TEXT.L_EXEC_BL')); ?></label>
  <input data-role="none" type="text" id="exec_bl" name="exec_bl" value="<?php echo rn_e(rn_ein_w('exec_bl', $rn_cfg['exec_bl'])); ?>"<?php echo rn_ein_m('exec_bl'); ?>>
</div>
<div class="sm-feld">
  <label for="exec_csf"><?php echo rn_e(rn_t('TEXT.L_EXEC_CSF')); ?></label>
  <input data-role="none" type="text" id="exec_csf" name="exec_csf" value="<?php echo rn_e(rn_ein_w('exec_csf', $rn_cfg['exec_csf'])); ?>"<?php echo rn_ein_m('exec_csf'); ?>>
  <p class="sm-hilfe"><?php echo rn_t('TEXT.H_EXEC'); ?></p>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_LADEZIEL')); ?></h2>
<div class="sm-info"><?php echo rn_t('TEXT.H_LADEZIEL_TEXT'); ?></div>
<div class="sm-feld">
  <label for="soc_min"><?php echo rn_e(rn_t('TEXT.L_SOC_MIN')); ?></label>
  <input data-role="none" type="number" min="20" max="100" id="soc_min" name="soc_min" value="<?php echo rn_e(rn_ein_w('soc_min', $rn_cfg['soc_min'])); ?>"<?php echo rn_ein_m('soc_min'); ?>>
</div>
<div class="sm-feld">
  <label for="soc_target"><?php echo rn_e(rn_t('TEXT.L_SOC_TARGET')); ?></label>
  <input data-role="none" type="number" min="20" max="100" id="soc_target" name="soc_target" value="<?php echo rn_e(rn_ein_w('soc_target', $rn_cfg['soc_target'])); ?>"<?php echo rn_ein_m('soc_target'); ?>>
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_SOC_LEER')); ?></p>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_AUFZEICHNUNG')); ?></h2>
<div class="sm-feld">
  <label for="save_in_db"><?php echo rn_e(rn_t('TEXT.L_SAVE_IN_DB')); ?></label>
  <select data-role="none" id="save_in_db" name="save_in_db"<?php echo rn_ein_m('save_in_db'); ?>>
    <option value="N"<?php echo rn_ein_w('save_in_db', $rn_cfg['save_in_db']) !== 'Y' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_NEIN')); ?></option>
    <option value="Y"<?php echo rn_ein_w('save_in_db', $rn_cfg['save_in_db']) === 'Y' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_JA_ANHAENGEN')); ?></option>
  </select>
  <p class="sm-hilfe"><?php echo rn_t('TEXT.H_AUFZEICHNUNG_TEXT'); ?></p>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_FREMDDIENSTE')); ?></h2>
<div class="sm-feld">
  <label for="weather_api_key"><?php echo rn_e(rn_t('TEXT.L_WETTER')); ?></label>
  <?php /* Wie das Kennwort (Befund U6): nie mit Wert in der Seite, nur
           "gespeichert / noch nicht gesetzt"; leer heisst unveraendert,
           geloescht wird ueber den Haken. Bis 2.1.12 standen beide Schluessel
           als Klartext im Formular. */ ?>
  <input data-role="none" type="password" id="weather_api_key" name="weather_api_key" value="" autocomplete="new-password"<?php echo rn_ein_m('weather_api_key'); ?>
         placeholder="<?php echo rn_e($rn_cfg['weather_api_key'] !== '' ? rn_t('TEXT.P_GESPEICHERT') : rn_t('TEXT.P_NICHT_GESETZT')); ?>">
  <p class="sm-hilfe"><label><input data-role="none" type="checkbox" name="weather_api_key_loeschen" value="1" style="width:auto;margin:0 6px 0 0;vertical-align:middle"><?php echo rn_e(rn_t('TEXT.L_SCHLUESSEL_LOESCHEN')); ?></label></p>
  <p class="sm-hilfe"><?php echo rn_t('TEXT.H_WETTER'); ?> <?php echo rn_e(rn_t('TEXT.H_GEHEIM')); ?></p>
</div>
<div class="sm-feld">
  <label for="abrp_token"><?php echo rn_e(rn_t('TEXT.L_ABRP_TOKEN')); ?></label>
  <input data-role="none" type="password" id="abrp_token" name="abrp_token" value="" autocomplete="new-password"<?php echo rn_ein_m('abrp_token'); ?>
         placeholder="<?php echo rn_e($rn_cfg['abrp_token'] !== '' ? rn_t('TEXT.P_GESPEICHERT') : rn_t('TEXT.P_NICHT_GESETZT')); ?>">
  <p class="sm-hilfe"><label><input data-role="none" type="checkbox" name="abrp_token_loeschen" value="1" style="width:auto;margin:0 6px 0 0;vertical-align:middle"><?php echo rn_e(rn_t('TEXT.L_SCHLUESSEL_LOESCHEN')); ?></label></p>
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_GEHEIM')); ?></p>
</div>
<div class="sm-feld">
  <label for="abrp_model"><?php echo rn_e(rn_t('TEXT.L_ABRP_MODEL')); ?></label>
  <input data-role="none" type="text" id="abrp_model" name="abrp_model" value="<?php echo rn_e(rn_ein_w('abrp_model', $rn_cfg['abrp_model'])); ?>"<?php echo rn_ein_m('abrp_model'); ?>>
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_ABRP')); ?></p>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_ANSAGE')); ?></h2>
<div class="sm-hinweis"><?php echo rn_t('TEXT.ANSAGE_ERKLAERUNG'); ?></div>
<?php echo ansage_formular_html(rn_tts($rn_cfg), array(
    'w' => function ($n, $g) { return rn_ein_w($n, $g); },
    'm' => function ($n) { return rn_ein_m($n); },
    'c' => function ($n, $g) { return rn_ein_h($n, $g); },
    'modi' => rn_ansage_modi()), rn_ansage_k()); ?>
<h3><?php echo rn_e(rn_t('TEXT.H_ANSAGE_ANLAESSE')); ?></h3>
<?php foreach (rn_ansage_anlaesse() as $rn_a) { ?>
<div class="sm-feld">
  <label style="display:inline-flex;align-items:center;gap:8px;">
    <input data-role="none" type="checkbox" name="<?php echo rn_e($rn_a[0]); ?>" value="Y" style="width:auto" <?php echo rn_ein_h($rn_a[0], (string) $rn_cfg[$rn_a[0]] === 'Y') ? 'checked' : ''; ?><?php echo rn_ein_m($rn_a[0]); ?>>
    <?php echo rn_e(rn_t($rn_a[1])); ?>
  </label>
</div>
<?php } ?>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_ANSAGE_ANLAESSE_HILFE'); ?></p>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo rn_e(rn_t('LEGENDE.AKTION')); ?></span>
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo rn_e(rn_t('LEGENDE.LESEN')); ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?php echo rn_e(rn_t('TEXT.K_SPEICHERN')); ?></button>
</div>
</form>

<h2><?= rn_t('TEXT.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= rn_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= rn_t('TEXT.SICH_WARNUNG') ?></div>
<div class="sm-hinweis"><?= rn_t('TEXT.SICH_OHNE_SPRECHTOKEN') ?></div>
<?php /* X-3 (Welle-4-Bau): Wuerde das Zurueckspielen die eigene Sicherung
         abweisen, steht es hier - gelb, nur Namen, nie Werte. Die Sicherung
         wird trotzdem vollstaendig geliefert, ihr Kopf traegt '_warnung'. */
$rn_sich_warn = rn_rueckspiel_altwerte();
if ($rn_sich_warn) { ?>
<div class="sm-warnung"><?php echo sprintf(rn_t('TEXT.SICH_WARN_KNOPF'), rn_e(implode(', ', $rn_sich_warn))); ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="rn_sichern" value="1"><?= rn_t('TEXT.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
    <input data-role="none" type="file" name="rn_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="rn_zurueck" value="1"><?= rn_t('TEXT.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================================= MQTT ================================= -->
<div class="sm-seite<?php echo $rn_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" id="tab-mqtt">

<h2><?php echo rn_e(rn_t('TEXT.H_GATEWAY')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_GATEWAY_TEXT'); ?></p>

<?php if (!$rn_broker) { ?>
<div class="sm-warnung"><?php echo rn_t('TEXT.H_KEIN_BROKER'); ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:34%"><?php echo rn_e(rn_t('TEXT.S_GROESSE')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_WERT')); ?></th></tr>
<tr><td><?php echo rn_e(rn_t('TEXT.S_BROKER')); ?></td><td class="sm-mono"><?php echo rn_e($rn_broker['host'] . ':' . $rn_broker['port']); ?></td></tr>
<tr><td><?php echo rn_e(rn_t('TEXT.S_LOKAL')); ?></td><td><?php echo $rn_broker['lokal'] ? rn_e(rn_t('TEXT.O_JA')) : rn_e(rn_t('TEXT.S_FREMDER_BROKER')); ?></td></tr>
<tr><td><?php echo rn_e(rn_t('TEXT.S_AUTOSTART')); ?></td><td><?php echo $rn_broker['autostart'] ? '<span class="sm-an">' . rn_e(rn_t('TEXT.O_JA')) . '</span>' : '<span class="sm-aus">' . rn_e(rn_t('TEXT.S_KEIN_AUTOSTART')) . '</span>'; ?></td></tr>
<tr><td><?php echo rn_e(rn_t('TEXT.S_BENUTZER')); ?></td><td class="sm-mono"><?php echo $rn_broker['benutzer'] !== '' ? rn_e($rn_broker['benutzer']) : '–'; ?></td></tr>
</table>
<?php } ?>

<h2><?php echo rn_e(rn_t('TEXT.H_ABO')); ?></h2>
<p class="sm-hilfe"><b><?php echo rn_abo_text(); ?></b> <?php echo rn_t('TEXT.H_ABO_WO'); ?></p>
<pre class="sm-vorschau"><?php foreach ($rn_autos as $rn_f) {
    echo 'Renault/' . rn_e($rn_f['name']) . "/#\n"; } ?></pre>

<h2><?php echo rn_e(rn_t('TEXT.H_THEMEN')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_THEMEN_TEXT'); ?></p>
<?php foreach ($rn_autos as $rn_f) { ?>
<h3><?php echo rn_e($rn_f['name']); ?> <span class="sm-small">(<?php echo rn_e(sprintf(rn_t('TEXT.S_PHASE'), $rn_f['zoeph'])); ?>)</span></h3>
<table class="sm-tbl">
<tr><th style="width:42%"><?php echo rn_e(rn_t('TEXT.S_THEMA')); ?></th><th style="width:10%"><?php echo rn_e(rn_t('TEXT.S_RETAINED')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_BEDEUTUNG')); ?></th></tr>
<?php foreach (rn_themen($rn_f['zoeph']) as $rn_thema => $rn_schl) { ?>
<tr><td class="sm-mono">Renault/<?php echo rn_e($rn_f['name'] . '/' . $rn_thema); ?></td>
    <td><?php echo rn_e(rn_t(rn_thema_retained($rn_thema) ? 'TEXT.O_JA' : 'TEXT.O_NEIN')); ?></td>
    <td><?php echo rn_e(rn_t($rn_schl)); ?></td></tr>
<?php } ?>
</table>
<?php } ?>
</div>

<!-- ========================= Einbindung in Loxone ========================= -->
<div class="sm-seite<?php echo $rn_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">

<h2><?php echo rn_e(rn_t('TEXT.H_LOXONE')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_LOXONE_TEXT'); ?></p>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_e(rn_t('TEXT.SCHRITT1')); ?></h3>
<p class="sm-hilfe"><?php echo rn_t('TEXT.SCHRITT1_TEXT'); ?></p>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_e(rn_t('TEXT.SCHRITT2')); ?></h3>
<p class="sm-hilfe"><b><?php echo rn_abo_text(); ?></b> <?php echo rn_t('TEXT.H_ABO_WO'); ?></p>
<pre class="sm-vorschau"><?php foreach ($rn_autos as $rn_f) {
    echo 'Renault/' . rn_e($rn_f['name']) . "/#\n"; } ?></pre>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_e(rn_t('TEXT.SCHRITT3')); ?></h3>
<p class="sm-hilfe"><?php echo rn_t('TEXT.SCHRITT3_TEXT'); ?></p>
<?php foreach ($rn_autos as $rn_f) { ?>
<table class="sm-tbl">
<?php
    /* Die Namenstabelle: ALLE Themen aus rn_themen(), dieselbe Liste, die
     * der Reiter Test gegen den Sendecode haelt. Der Titel ist das Thema mit
     * Unterstrich statt Schraegstrich und Prozentzeichen - so benennt das
     * Gateway den Eingang (mqttgateway.pl: s/[\/%]/_/g, am Geraet gelesen
     * 17.09.2026). */
    $rn_einheit = array();
    foreach (rn_vorlage_felder($rn_f['zoeph']) as $rn_w) { $rn_einheit[$rn_w[0]] = $rn_w[5]; } ?>
<tr><th style="width:40%"><?php echo rn_e(rn_t('TEXT.S_TITEL')); ?></th><th style="width:9%"><?php echo rn_e(rn_t('TEXT.S_RETAINED')); ?></th><th style="width:11%"><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_BEDEUTUNG')); ?></th></tr>
<?php foreach (rn_themen($rn_f['zoeph']) as $rn_thema => $rn_schl) { ?>
<tr><td class="sm-mono"><?php echo rn_e(str_replace(array('/', '%'), '_', 'Renault/' . $rn_f['name'] . '/' . $rn_thema)); ?></td>
    <td><?php echo rn_e(rn_t(rn_thema_retained($rn_thema) ? 'TEXT.O_JA' : 'TEXT.O_NEIN')); ?></td>
    <td><?php echo isset($rn_einheit[$rn_thema]) ? rn_e(trim(str_replace(array('<v.0>', '<v.1>'), '', $rn_einheit[$rn_thema]))) : ''; ?></td>
    <td><?php echo rn_e(rn_t($rn_schl)); ?></td></tr>
<?php } ?>
</table>
<?php } ?>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_e(rn_t('TEXT.H_VORLAGE')); ?></h3>
<div class="sm-hinweis"><?php echo rn_t('TEXT.H_VORLAGE_TEXT'); ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo rn_e(rn_t('LEGENDE.TECHNIK')); ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="vo">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo rn_e(rn_t('TEXT.K_VORLAGE_VQ')); ?></button>
</form>
</div>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_e(rn_t('TEXT.SCHRITT4')); ?></h3>
<?php if ($rn_cfg['steuerung_ein'] !== 'Y') { ?>
<div class="sm-warnung"><?php echo rn_t('TEXT.H_STEUERUNG_AUS'); ?></div>
<?php } ?>
<?php /* Der Rechnername aus HTTP_HOST als Vorschlag, mit Pruefhinweis (Befund
       U10); bis 2.1.12 stand hier nur der Platzhalter http://<LoxBerry>. */
      $rn_host = rn_rechnername(); ?>
<p class="sm-hilfe"><?php echo sprintf(rn_t('TEXT.SCHRITT4_TEXT'), rn_e('http://' . $rn_host)); ?></p>
<p class="sm-hilfe"><?php echo sprintf(rn_t('TEXT.H_ADRESSE_PRUEFEN'), rn_e($rn_host)); ?></p>
<table class="sm-tbl">
<tr><th style="width:30%"><?php echo rn_e(rn_t('TEXT.S_ZWECK')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_BEFEHL_EIN')); ?></th></tr>
<?php foreach ($rn_autos as $rn_f) { foreach (rn_befehle() as $rn_a => $rn_ang) { ?>
<tr><td><?php echo rn_e((count($rn_autos) > 1 ? $rn_f['name'] . ': ' : '') . rn_t($rn_ang[0])); ?></td>
    <td class="sm-mono"><?php echo rn_e(rn_aktionsadresse($rn_cfg, $rn_a, $rn_f['nr'])); ?></td></tr>
<?php } } ?>
<tr><td><?php echo rn_e(rn_t('TEXT.S_SELBSTTEST')); ?></td>
    <td class="sm-mono"><?php echo rn_e('http://' . $rn_host . rn_selbsttestadresse($rn_cfg)); ?></td></tr>
</table>
<p class="sm-hilfe"><?php echo rn_t('TEXT.SCHRITT4_ENDPUNKT'); ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo rn_e(rn_t('LEGENDE.AKTION_TOKEN')); ?></span>
</div>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?php echo rn_e(rn_t('TEXT.K_TOKEN_NEU')); ?></button>
  </form>
</div>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_e(rn_t('TEXT.SCHRITT5')); ?></h3>
<p class="sm-hilfe"><?php echo rn_t('TEXT.SCHRITT5_TEXT'); ?></p>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_e(rn_t('TEXT.SCHRITT6')); ?></h3>
<p class="sm-hilfe"><?php echo rn_t('TEXT.SCHRITT6_TEXT'); ?></p>
<?php $rn_e1 = $rn_autos[0]['name'];
/* X-10 (2.1.18): Spalte "Eingaenge verbinden mit" in der Schreibweise von
 * Werkzeuge/leitungen_setzen.py 1.1, sprachfrei: "#N" (Ausgang von Zeile N auf den
 * ersten Eingang), "V1 = #3", "I1 = #8, I2 = #9", "– (MQTT <Thema>)" fuer Eingaenge
 * ohne Quelle in der Liste. Bis 2.1.17: "Eingang &larr; #N". */ ?>
<table class="sm-tbl">
<tr><th>#</th><th><?php echo rn_e(rn_t('TEXT.S_BAUSTEIN')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_NAME_VORSCHLAG')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_PARAMETER')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_EINGAENGE')); ?></th></tr>
<tr><td>1</td><td><?php echo rn_e(rn_t('TEXT.B_VI')); ?></td><td>Auto_Akku</td><td><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?> <span class="sm-mono">&lt;v.0&gt; %</span></td><td>– (MQTT <span class="sm-mono">BattSOC</span>)</td></tr>
<tr><td>2</td><td><?php echo rn_e(rn_t('TEXT.B_VI')); ?></td><td>Auto_Reichweite</td><td><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?> <span class="sm-mono">&lt;v.0&gt; km</span></td><td>– (MQTT <span class="sm-mono">Range</span>)</td></tr>
<tr><td>3</td><td><?php echo rn_e(rn_t('TEXT.B_VI')); ?></td><td>Auto_laedt</td><td><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?> <span class="sm-mono">&lt;v.0&gt;</span></td><td>– (MQTT <span class="sm-mono">ChargingStatus</span>)</td></tr>
<tr><td>4</td><td><?php echo rn_e(rn_t('TEXT.B_VI')); ?></td><td>Auto_Kabel</td><td><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?> <span class="sm-mono">&lt;v.0&gt;</span></td><td>– (MQTT <span class="sm-mono">CableStatus</span>)</td></tr>
<tr><td>5</td><td><?php echo rn_e(rn_t('TEXT.B_VI')); ?></td><td>Auto_Abruf_ok</td><td><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?> <span class="sm-mono">&lt;v.0&gt;</span></td><td>– (MQTT <span class="sm-mono">ok</span>)</td></tr>
<tr><td>6</td><td><?php echo rn_e(rn_t('TEXT.B_VI')); ?></td><td>Auto_Abrufzeit</td><td><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?> <span class="sm-mono">&lt;v.0&gt;</span></td><td>– (MQTT <span class="sm-mono">phpCall</span>)</td></tr>
<tr><td>7</td><td><?php echo rn_e(rn_t('TEXT.B_STATUS')); ?></td><td>Auto_Status</td><td><?php echo rn_e(rn_t('TEXT.P_STATUS')); ?></td><td>V1 = #3</td></tr>
<tr><td>8</td><td><?php echo rn_e(rn_t('TEXT.B_VERGLEICHER')); ?></td><td>Auto_Akku_niedrig</td><td><?php echo rn_e(rn_t('TEXT.P_SCHWELLE20')); ?></td><td>#1</td></tr>
<tr><td>9</td><td><?php echo rn_e(rn_t('TEXT.B_TREPPENLICHT')); ?></td><td>Auto_Abruf_haengt</td><td><?php echo rn_e(rn_t('TEXT.P_HALTEZEIT')); ?></td><td><?php echo rn_e(rn_t('TEXT.P_FLANKE')); ?> #6</td></tr>
<tr><td>10</td><td><?php echo rn_e(rn_t('TEXT.B_ODER')); ?></td><td>Auto_Meldungen</td><td>–</td><td>I1 = #8, I2 = #9</td></tr>
<tr><td>11</td><td><?php echo rn_e(rn_t('TEXT.B_BENACHRICHTIGUNG')); ?></td><td>Auto_Melder</td><td><?php echo rn_e(rn_t('TEXT.P_TEXT_FREI')); ?></td><td>#10</td></tr>
<tr><td>12</td><td><?php echo rn_e(rn_t('TEXT.B_VQ')); ?></td><td>Auto_Klima_an</td><td><span class="sm-mono">…&amp;aktion=acnow</span></td><td><?php echo rn_e(rn_t('TEXT.S_VON_VISU')); ?></td></tr>
<tr><td>13</td><td><?php echo rn_e(rn_t('TEXT.B_VQ')); ?></td><td>Auto_Klima_aus</td><td><span class="sm-mono">…&amp;aktion=acoff</span></td><td><?php echo rn_e(rn_t('TEXT.S_VON_VISU')); ?></td></tr>
<tr><td>14</td><td><?php echo rn_e(rn_t('TEXT.B_VQ')); ?></td><td>Auto_laden_jetzt</td><td><span class="sm-mono">…&amp;aktion=chargenow</span></td><td><?php echo rn_e(rn_t('TEXT.S_VON_VISU')); ?></td></tr>
<tr><td>15</td><td><?php echo rn_e(rn_t('TEXT.B_VQ')); ?></td><td>Auto_laden_stopp</td><td><span class="sm-mono">…&amp;aktion=chargestop</span></td><td><?php echo rn_e(rn_t('TEXT.S_VON_VISU')); ?></td></tr>
<tr><td>16 <i><?php echo rn_e(rn_t('TEXT.H_OPTIONAL')); ?></i></td><td><?php echo rn_e(rn_t('TEXT.B_VQ')); ?></td><td>Auto_Ladeplan_an</td><td><span class="sm-mono">…&amp;aktion=cmon</span></td><td><?php echo rn_e(rn_t('TEXT.S_VON_VISU')); ?></td></tr>
</table>
<p class="sm-hilfe"><b><?php echo rn_e(rn_t('TEXT.ZU_9')); ?></b> <?php echo rn_t('TEXT.ZU_9_TEXT'); ?></p>
<p class="sm-hilfe"><b><?php echo rn_e(rn_t('TEXT.ZU_10')); ?></b> <?php echo rn_t('TEXT.ZU_10_TEXT'); ?></p>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_e(rn_t('TEXT.SCHRITT7')); ?></h3>
<p class="sm-hilfe"><?php echo rn_t('TEXT.SCHRITT7_TEXT'); ?></p>
</div>
</div>

<!-- ================================= Test ================================= -->
<div class="sm-seite<?php echo $rn_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">

<?php if ($rn_test_titel !== '') { ?>
<div class="sm-hinweis"><b><?php echo rn_e($rn_test_titel); ?></b></div>
<?php echo $rn_test_text; ?>
<?php } ?>

<h2><?php echo rn_e(rn_t('TEXT.H_SELBSTPRUEFUNG')); ?></h2>
<table class="sm-tbl">
<tr><th style="width:52%"><?php echo rn_e(rn_t('TEXT.S_FRAGE')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_ANTWORT')); ?></th></tr>
<?php
require_once __DIR__ . '/rn_test.php';
/* DREI Ausgaenge, nicht zwei.
 *
 * Bis 2.1.5 war das zweite Feld ein Boolescher Wert: es gab nur Haken oder
 * Kreuz. Damit standen Zeilen rot, die nichts bedeuteten - ein leeres
 * Protokoll (log/plugins liegt auf einer Ramdisk und ist nach jedem
 * Neustart leer), ein nicht feststellbarer Gateway-Autostart, eine noch
 * nicht geschriebene Konfiguration. "Ich konnte hier nichts messen" ist
 * weder Haken noch Kreuz; ein rotes Kreuz, das nichts bedeutet, ist
 * schlimmer als keine Pruefung.
 *
 *   true   Haken
 *   false  Kreuz
 *   null   Punkt - nicht feststellbar
 *   'info' Auskunft - hier wird nichts geprueft (seit 2.1.13, Befund U11) */
$rn_striche = 0;
foreach (rn_test_selbstpruefung($rn_cfg, $rn_broker, $rn_tab === 'tab-test') as $rn_z) {
    if ($rn_z[1] === 'info') { $rn_zeichen = '&#8505; '; }
    elseif ($rn_z[1] === null) { $rn_zeichen = '&#9679; '; $rn_striche++; }
    elseif ($rn_z[1])      { $rn_zeichen = '&#10004; '; }
    else                   { $rn_zeichen = '&#10008; '; }
    echo '<tr><td>' . rn_e($rn_z[0]) . '</td><td>'
       . $rn_zeichen . rn_e($rn_z[2]) . '</td></tr>';
}
if ($rn_striche > 0) {
    echo '<tr><td colspan="2" class="sm-hilfe">'
       . rn_e(sprintf(rn_t('TEXT.S_NICHT_MESSBAR'), $rn_striche)) . '</td></tr>';
}
?>
</table>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo rn_e(rn_t('LEGENDE.LESEN')); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo rn_e(rn_t('LEGENDE.TECHNIK')); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo rn_e(rn_t('LEGENDE.AKTION')); ?></span>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_NACHSEHEN')); ?></h2>
<div class="sm-knopfreihe">
<?php foreach (array('umgebung' => 'TEXT.K_UMGEBUNG', 'konfig' => 'TEXT.K_KONFIG',
                     'zwischen' => 'TEXT.K_ZWISCHEN', 'themen' => 'TEXT.K_THEMEN',
                     'vorlage' => 'TEXT.K_VORLAGE_PRUEFEN') as $rn_w => $rn_k) { ?>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="<?php echo rn_e($rn_w); ?>"><?php echo rn_e(rn_t($rn_k)); ?></button>
  </form>
<?php } ?>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_ANSAGE_TEST')); ?></h2>
<p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.ANSAGE_TEST_TEXT')); ?></p>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_test" value="1"><?php echo rn_e(rn_t('TEXT.K_ANSAGE_TEST')); ?></button>
  </form>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_TECHNIK')); ?></h2>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="cache_leeren" value="1"><?php echo rn_e(rn_t('TEXT.K_CACHE_LEEREN')); ?></button>
  </form>
</div>

<?php
/* Lesende und schaltende Knoepfe in GETRENNTEN Reihen.
 *
 * Bis 2.1.5 standen alle sieben Befehle orange (sm-b-aktion) unter der
 * Ueberschrift "Schalten" - auch "Daten sofort neu abrufen", das
 * rn_befehle() selbst als nicht veraendernd fuehrt. Orange heisst nach der
 * Farblegende "haelt den Betrieb an / veraendert"; und der Satz ueber der
 * Reihe sagt, die Knoepfe wirkten am Fahrzeug. Fuer den Abruf stimmt beides
 * nicht. Die Einteilung kommt jetzt aus rn_befehl_schaltet() - derselben
 * Funktion, an der auch der Endpunkt entscheidet. */
$rn_lesend = array();
$rn_schaltend = array();
foreach (rn_befehle() as $rn_a => $rn_ang) {
    if (rn_befehl_schaltet($rn_a)) { $rn_schaltend[$rn_a] = $rn_ang; }
    else                          { $rn_lesend[$rn_a]   = $rn_ang; }
}
?>
<h2><?php echo rn_e(rn_t('TEXT.H_ABRUF_TEST')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_ABRUF_TEST_TEXT'); ?></p>
<div class="sm-knopfreihe">
<?php foreach ($rn_autos as $rn_f) { foreach ($rn_lesend as $rn_a => $rn_ang) { ?>
  <a data-role="none" class="sm-btn sm-b-lesen" target="_blank"
     href="<?php echo rn_e(rn_aktionsadresse($rn_cfg, $rn_a, $rn_f['nr'])); ?>"><?php
    echo rn_e((count($rn_autos) > 1 ? $rn_f['name'] . ': ' : '') . rn_t($rn_ang[0])); ?></a>
<?php } } ?>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_SCHALTEN_TEST')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_SCHALTEN_TEST_TEXT'); ?></p>
<?php if ($rn_cfg['steuerung_ein'] !== 'Y') { ?>
<div class="sm-warnung"><?php echo rn_t('TEXT.H_STEUERUNG_AUS'); ?></div>
<?php } ?>
<div class="sm-knopfreihe">
<?php foreach ($rn_autos as $rn_f) { foreach ($rn_schaltend as $rn_a => $rn_ang) { ?>
  <a data-role="none" class="sm-btn sm-b-aktion" target="_blank"
     href="<?php echo rn_e(rn_aktionsadresse($rn_cfg, $rn_a, $rn_f['nr'])); ?>"><?php
    echo rn_e((count($rn_autos) > 1 ? $rn_f['name'] . ': ' : '') . rn_t($rn_ang[0])); ?></a>
<?php } } ?>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_LETZTER_STAND')); ?></h2>
<?php foreach ($rn_autos as $rn_f) { $rn_s = rn_session($rn_f['nr']); ?>
<h3><?php echo rn_e($rn_f['name']); ?></h3>
<?php if (!$rn_s) { ?>
<div class="sm-info"><?php echo rn_e(rn_t('TEXT.H_KEIN_ABRUF')); ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:44%"><?php echo rn_e(rn_t('TEXT.S_FELD')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_WERT')); ?></th></tr>
<?php foreach (rn_session_felder() as $rn_i => $rn_lbl) { ?>
<tr><td><?php echo rn_e(rn_t($rn_lbl)); ?></td>
    <td class="sm-mono"><?php echo isset($rn_s[$rn_i]) && $rn_s[$rn_i] !== '' ? rn_e($rn_s[$rn_i]) : '–'; ?></td></tr>
<?php } ?>
</table>
<?php } } ?>
</div>

<!-- ============================= Ladehistorie ============================= -->
<div class="sm-seite<?php echo $rn_tab === 'tab-verlauf' ? ' sm-active' : ''; ?>" id="tab-verlauf">
<h2><?php echo rn_e(rn_t('TEXT.H_VERLAUF')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_VERLAUF_TEXT'); ?></p>
<?php if ($rn_cfg['save_in_db'] !== 'Y') { ?>
<div class="sm-info"><?php echo rn_t('TEXT.H_AUFZEICHNUNG_AUS'); ?></div>
<?php } ?>

<?php
/* Tagesverlauf als reines SVG - ohne Fremdbibliothek, ohne canvas.
 * README versprach seit 1.4 eine "Diagramm-Seite"; es gab nur eine
 * Tabelle. Gezeichnet werden Batteriestand (Prozent) und Reichweite
 * (auf 100 % normiert) der letzten 24 Stunden aus database.csv. */
foreach ($rn_autos as $rn_f) {
    $rn_csv = $rn_f['csv'];
    echo '<h3>' . rn_e($rn_f['name']) . '</h3>';
    if (!is_readable($rn_csv)) {
        echo '<div class="sm-info">' . rn_t('TEXT.H_KEINE_CSV') . '</div>';
        continue;
    }
    $rn_reihen = @file($rn_csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($rn_reihen) || count($rn_reihen) < 2) {
        echo '<div class="sm-info">' . rn_e(rn_t('TEXT.H_CSV_LEER')) . '</div>';
        continue;
    }
    $rn_kopfzeile = array_shift($rn_reihen);       // Kopfzeile - wird unten gebraucht
    $rn_punkte = array();
    foreach (array_slice($rn_reihen, -288) as $rn_z) {   // hoechstens 24 h
        $rn_sp = explode(';', $rn_z);
        if (count($rn_sp) < 6) { continue; }
        $rn_ts = strtotime(str_replace('.', '-', $rn_sp[0]) . ' ' . $rn_sp[1]);
        if ($rn_ts === false) { continue; }
        $rn_punkte[] = array($rn_ts, (float) $rn_sp[3], (float) $rn_sp[5]);
    }
    if (count($rn_punkte) < 2) {
        echo '<div class="sm-info">' . rn_e(rn_t('TEXT.H_ZU_WENIG_PUNKTE')) . '</div>';
    } else {
        $rn_t0 = $rn_punkte[0][0];
        $rn_t1 = $rn_punkte[count($rn_punkte) - 1][0];
        $rn_spanne = max(1, $rn_t1 - $rn_t0);
        $rn_rmax = 1.0;
        foreach ($rn_punkte as $rn_pt) { if ($rn_pt[2] > $rn_rmax) { $rn_rmax = $rn_pt[2]; } }
        $rn_w = 900; $rn_h = 220; $rn_pad = 34;
        $rn_pfad_soc = ''; $rn_pfad_km = '';
        foreach ($rn_punkte as $rn_k => $rn_pt) {
            $rn_x = $rn_pad + ($rn_pt[0] - $rn_t0) / $rn_spanne * ($rn_w - 2 * $rn_pad);
            $rn_y1 = $rn_h - $rn_pad - ($rn_pt[1] / 100) * ($rn_h - 2 * $rn_pad);
            $rn_y2 = $rn_h - $rn_pad - ($rn_pt[2] / $rn_rmax) * ($rn_h - 2 * $rn_pad);
            $rn_pfad_soc .= ($rn_k ? ' L ' : 'M ') . round($rn_x, 1) . ' ' . round($rn_y1, 1);
            $rn_pfad_km  .= ($rn_k ? ' L ' : 'M ') . round($rn_x, 1) . ' ' . round($rn_y2, 1);
        }
        ?>
        <svg viewBox="0 0 <?php echo $rn_w; ?> <?php echo $rn_h; ?>" style="width:100%;height:auto;border:1px solid #ddd;border-radius:6px;background:#fff" role="img">
          <?php for ($rn_g = 0; $rn_g <= 4; $rn_g++) {
              $rn_y = $rn_h - $rn_pad - $rn_g / 4 * ($rn_h - 2 * $rn_pad); ?>
          <line x1="<?php echo $rn_pad; ?>" y1="<?php echo round($rn_y, 1); ?>" x2="<?php echo $rn_w - $rn_pad; ?>" y2="<?php echo round($rn_y, 1); ?>" stroke="#eee" stroke-width="1"/>
          <text x="4" y="<?php echo round($rn_y + 4, 1); ?>" font-size="11" fill="#888"><?php echo $rn_g * 25; ?>%</text>
          <?php } ?>
          <path d="<?php echo $rn_pfad_km; ?>" fill="none" stroke="#546e7a" stroke-width="1.5" stroke-dasharray="4 3"/>
          <path d="<?php echo $rn_pfad_soc; ?>" fill="none" stroke="#6dac20" stroke-width="2"/>
          <text x="<?php echo $rn_pad; ?>" y="<?php echo $rn_h - 8; ?>" font-size="11" fill="#666"><?php echo rn_e(date('d.m. H:i', $rn_t0)); ?></text>
          <text x="<?php echo $rn_w - $rn_pad; ?>" y="<?php echo $rn_h - 8; ?>" font-size="11" fill="#666" text-anchor="end"><?php echo rn_e(date('d.m. H:i', $rn_t1)); ?></text>
        </svg>
        <p class="sm-hilfe">
          <span style="color:#6dac20;font-weight:700">&#9473;</span> <?php echo rn_e(rn_t('TEXT.S_BATTERIESTAND')); ?> &nbsp;
          <span style="color:#546e7a;font-weight:700">&#9476;</span> <?php echo rn_e(sprintf(rn_t('TEXT.S_REICHWEITE_MAX'), round($rn_rmax))); ?>
        </p>
        <?php
    }
    // Tabelle bleibt zusaetzlich stehen - sie zeigt, was das Diagramm zeichnet.
    /* Die Tabelle hat 17 Spalten. Bis 2.1.5 stand sie OHNE Kopfzeile - die
     * wurde oben weggeworfen - und ohne Rollbehaelter in einem Behaelter
     * mit fester Hoechstbreite: siebzehn namenlose, gequetschte Spalten.
     * Die Ueberschriften kommen aus der Kopfzeile der Datei selbst, durch
     * die Sprachtabelle uebersetzt; damit stimmt ihre Zahl immer mit den
     * Daten ueberein, auch bei einer aelteren Aufzeichnung. */
    $rn_letzte = array_slice(array_reverse($rn_reihen), 0, 30);
    echo '<p class="sm-hilfe">' . rn_e(rn_t('TEXT.H_TABELLE_30')) . '</p>';
    echo '<div class="sm-breit"><table class="sm-tbl">';
    echo '<tr>';
    foreach (explode(';', (string) $rn_kopfzeile) as $rn_sp) {
        echo '<th>' . rn_e(rn_csv_spalte($rn_sp)) . '</th>';
    }
    echo '</tr>';
    foreach ($rn_letzte as $rn_z) {
        echo '<tr>';
        foreach (explode(';', $rn_z) as $rn_feld) { echo '<td class="sm-mono">' . rn_e($rn_feld) . '</td>'; }
        echo '</tr>';
    }
    echo '</table></div>';
}
?>
</div>

<!-- ============================== Logdateien ============================== -->
<div class="sm-seite<?php echo $rn_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2><?php echo rn_e(rn_t('TEXT.H_LOG')); ?></h2>
<p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_LOG_TEXT')); ?>
<span class="sm-mono"><?php echo rn_e($rn_p['log']); ?></span></p>
<div class="sm-hinweis"><?php echo rn_t('TEXT.H_LOG_RAMDISK'); ?></div>
<?php if (!$rn_zeilen) { ?>
<div class="sm-info"><?php echo rn_e(rn_t('TEXT.H_LOG_LEER')); ?></div>
<?php } else { ?>
<div class="sm-log"><?php foreach ($rn_zeilen as $rn_z) { echo rn_e($rn_z) . "\n"; } ?></div>
<?php } ?>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo rn_e(rn_t('LEGENDE.AKTION_LOG')); ?></span>
</div>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?php echo rn_e(rn_t('TEXT.K_LOG_LEEREN')); ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
    var reiter = document.querySelectorAll('.sm-tab');
    function zeige(id) {
        for (var i = 0; i < reiter.length; i++) {
            reiter[i].classList.toggle('sm-active', reiter[i].getAttribute('data-ziel') === id);
        }
        var seiten = document.querySelectorAll('.sm-seite');
        for (var j = 0; j < seiten.length; j++) {
            seiten[j].classList.toggle('sm-active', seiten[j].id === id);
        }
        var felder = document.querySelectorAll('input[name="activetab"]');
        for (var k = 0; k < felder.length; k++) { felder[k].value = id; }
        if (history.replaceState) {
            history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', ''));
        }
    }
    for (var m = 0; m < reiter.length; m++) {
        reiter[m].addEventListener('click', function (e) {
            e.preventDefault();
            zeige(this.getAttribute('data-ziel'));
        });
    }
    // Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
    // versteckten activetab-Felder aus und ist ansonsten wirkungslos.
    zeige(<?php echo json_encode($rn_tab); ?>);
})();
</script>

<?php LBWeb::lbfooter(); ?>
