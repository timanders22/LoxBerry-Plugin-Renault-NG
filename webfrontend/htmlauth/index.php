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
    $rn_cfg['aktionstoken'] = rn_token_erzeugen();
    if (!rn_config_write($rn_cfg)) {
        $rn_fehler[] = rn_t('TEXT.F_TOKEN_SCHREIBEN');
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
$rn_ftoken = rn_formtoken($rn_cfg);

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
    } else {
        $rn_fehler[] = rn_t('TEXT.F_TOKEN_SCHREIBEN');
    }
    $rn_cfg = rn_config_read();
    $rn_tab = 'tab-loxone';
}

if (isset($_POST['speichern'])) {
    $neu = $rn_cfg;

    /* Einfache Textfelder. Leere Felder loeschen nichts, wo der Verlust
     * wehtut - das gilt hier fuer das Passwort. */
    foreach (array('username', 'country', 'save_in_db', 'steuerung_ein',
                   'cron_ncs', 'cron_acs', 'ac_temp', 'bl_schwelle',
                   'mail_bl', 'exec_bl', 'cmon_bl', 'mail_csf', 'exec_csf',
                   'soc_min', 'soc_target',
                   'weather_api_key', 'abrp_token', 'abrp_model') as $f) {
        if (isset($_POST[$f])) {
            // Nur Steuerzeichen und Anfuehrungszeichen entfernen - ein
            // preg_replace mit Positivliste zerstoert eingefuegte Werte.
            $neu[$f] = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST[$f]));
        }
    }
    for ($i = 1; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $nr = ($i === 1) ? '' : (string) $i;
        foreach (array('zoename', 'vin', 'zoeph') as $f) {
            if (isset($_POST[$f . $nr])) {
                $neu[$f . $nr] = trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', (string) $_POST[$f . $nr]));
            }
        }
    }
    if (isset($_POST['password']) && $_POST['password'] !== '') {
        $neu['password'] = (string) $_POST['password'];
    }

    /* ---- Pruefungen. Beanstandungen werden GESAMMELT, nicht
     *      ueberschrieben - der Benutzer soll alle auf einmal sehen. Und
     *      eine halb ausgefuellte Zeile darf nicht das Speichern ALLER
     *      Felder verhindern; deshalb werden Werte, die fuer sich
     *      unbrauchbar sind, einzeln beanstandet. ---- */
    if ($neu['zoename'] === '') {
        $rn_fehler[] = rn_t('TEXT.F_NAME_LEER');
    }
    for ($i = 1; $i <= RN_MAX_FAHRZEUGE; $i++) {
        $nr = ($i === 1) ? '' : (string) $i;
        $nm = $neu['zoename' . $nr];
        $vn = $neu['vin' . $nr];
        if ($nm !== '' && (strpos($nm, '/') !== false || strpos($nm, '#') !== false
            || strpos($nm, '+') !== false)) {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_NAME_SONDERZEICHEN'), $i);
        }
        if ($vn !== '' && !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/i', $vn)) {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_VIN'), $i);
        }
        if (!in_array($neu['zoeph' . $nr], array('1', '2'), true)) {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_PHASE'), $i);
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
        }
        $rn_namen[] = $nm;
    }
    if (!in_array($neu['save_in_db'], array('Y', 'N'), true)) {
        $rn_fehler[] = rn_t('TEXT.F_AUFZEICHNUNG');
    }
    if (!in_array($neu['steuerung_ein'], array('Y', 'N'), true)) {
        $rn_fehler[] = rn_t('TEXT.F_STEUERUNG');
    }
    if (!preg_match('/^[A-Z]{2}$/', $neu['country'])) {
        $rn_fehler[] = rn_t('TEXT.F_LAND');
    }
    foreach (array('cron_ncs' => array(1, 60), 'cron_acs' => array(1, 60),
                   'ac_temp' => array(16, 30), 'bl_schwelle' => array(1, 99)) as $f => $gr) {
        if (!preg_match('/^[0-9]+$/', $neu[$f]) || (int) $neu[$f] < $gr[0] || (int) $neu[$f] > $gr[1]) {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_ZAHL'), rn_t('TEXT.L_' . strtoupper($f)), $gr[0], $gr[1]);
        }
    }
    foreach (array('soc_min', 'soc_target') as $f) {
        if ($neu[$f] === '') { continue; }         // leer = nicht anfassen
        if (!preg_match('/^[0-9]+$/', $neu[$f]) || (int) $neu[$f] < 20 || (int) $neu[$f] > 100) {
            $rn_fehler[] = sprintf(rn_t('TEXT.F_ZAHL'), rn_t('TEXT.L_' . strtoupper($f)), 20, 100);
        }
    }
    if ($neu['soc_min'] !== '' && $neu['soc_target'] !== ''
        && (int) $neu['soc_min'] > (int) $neu['soc_target']) {
        $rn_fehler[] = rn_t('TEXT.F_SOC_REIHENFOLGE');
    }
    foreach (array('mail_bl', 'cmon_bl', 'mail_csf') as $f) {
        if (!in_array($neu[$f], array('Y', 'N'), true)) { $neu[$f] = 'N'; }
    }
    // Ein Passwort ohne Benutzernamen ist fast immer ein Versehen.
    if ($neu['password'] !== '' && $neu['username'] === '') {
        $rn_fehler[] = rn_t('TEXT.F_BENUTZER_FEHLT');
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
        } else {
            $rn_fehler[] = rn_t('TEXT.F_SCHREIBEN');
        }
    }
    $rn_tab = 'tab-settings';
}

if (isset($_POST['log_leeren'])) {
    foreach (array($rn_p['log'], $rn_p['log'] . '.1', $rn_p['log'] . '.wdh') as $rn_d) {
        if (is_file($rn_d)) { @unlink($rn_d); }
    }
    $rn_meldung = rn_t('TEXT.M_LOG_LEER');
    $rn_tab = 'tab-log';
}

if (isset($_POST['cache_leeren'])) {
    foreach (rn_fahrzeuge($rn_cfg) as $rn_f) {
        if (is_file($rn_f['session'])) { @unlink($rn_f['session']); }
    }
    if (is_file($rn_p['anmeldung'])) { @unlink($rn_p['anmeldung']); }
    $rn_meldung = rn_t('TEXT.M_CACHE_LEER');
    $rn_tab = 'tab-test';
}

$rn_test_titel = '';
$rn_test_text  = '';
if (isset($_POST['test'])) {
    require_once __DIR__ . '/rn_test.php';
    list($rn_test_titel, $rn_test_text) = rn_test_ausfuehren((string) $_POST['test'], $rn_cfg);
    $rn_tab = 'tab-test';
}

// ---------- Loxone-Vorlage herunterladen (Hausstandard) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage'])) {
    list($rn_vname, $rn_vinhalt) = ($_POST['vorlage'] === 'vo')
        ? rn_vorlage_vo() : rn_vorlage();
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
LBWeb::lbheader($template_title, $helplink, $helptemplate);
?>

<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss.
   Uebernommen aus VORLAGE_hausstandard.css.html - bis 2.0.6 fuehrte dieses
   Plugin eine eigene Fassung mit .sm-pane statt .sm-seite. Damit lief die
   Gegenprobe aus der Pflichtpruefung ins Leere, die genau nach
   'sm-seite sm-active" id="tab-' sucht. */
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
.sm-pre, .sm-vorschau { background: #f4f4f4; border: 1px solid #ccc; padding: 10px;
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
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
</style>

<div class="sm-wrap">

<?php if ($rn_fehler) { ?>
<div class="sm-warnung"><b><?php echo rn_e(rn_t('TEXT.NICHT_GESPEICHERT')); ?></b><ul>
<?php foreach ($rn_fehler as $f) { echo '<li>' . $f . '</li>'; } ?>
</ul></div>
<?php } elseif ($rn_meldung !== '') { ?>
<div class="sm-hinweis"><?php echo $rn_meldung; ?></div>
<?php } ?>

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
<form method="post" action="index.php" autocomplete="off">
<input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo rn_e(rn_t('TEXT.H_KONTO')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_KONTO_TEXT'); ?></p>

<div class="sm-feld">
  <label for="username"><?php echo rn_e(rn_t('TEXT.L_BENUTZER')); ?></label>
  <input data-role="none" type="text" id="username" name="username" value="<?php echo rn_e($rn_cfg['username']); ?>">
</div>
<div class="sm-feld">
  <label for="password"><?php echo rn_e(rn_t('TEXT.L_PASSWORT')); ?></label>
  <input data-role="none" type="password" id="password" name="password" value=""
         placeholder="<?php echo rn_e($rn_cfg['password'] !== '' ? rn_t('TEXT.P_GESPEICHERT') : rn_t('TEXT.P_NICHT_GESETZT')); ?>">
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_PASSWORT')); ?></p>
</div>
<div class="sm-feld">
  <label for="country"><?php echo rn_e(rn_t('TEXT.L_LAND')); ?></label>
  <select data-role="none" id="country" name="country">
    <?php foreach (array('DE' => 'Deutschland', 'AT' => 'Österreich', 'CH' => 'Schweiz',
                         'IT' => 'Italia', 'SE' => 'Sverige', 'FR' => 'France',
                         'EN' => rn_t('TEXT.L_ANDERE')) as $k => $v) { ?>
      <option value="<?php echo rn_e($k); ?>"<?php echo $rn_cfg['country'] === $k ? ' selected' : ''; ?>><?php echo rn_e($v); ?></option>
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
         value="<?php echo rn_e($rn_cfg['zoename' . $rn_nr]); ?>">
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_NAME')); ?>
     <span class="sm-mono">Renault/<?php echo rn_e($rn_cfg['zoename' . $rn_nr]); ?>/…</span></p>
</div>
<div class="sm-feld">
  <label for="vin<?php echo rn_e($rn_nr); ?>"><?php echo rn_e(rn_t('TEXT.L_VIN')); ?></label>
  <input data-role="none" type="text" maxlength="17" id="vin<?php echo rn_e($rn_nr); ?>" name="vin<?php echo rn_e($rn_nr); ?>"
         value="<?php echo rn_e($rn_cfg['vin' . $rn_nr]); ?>">
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_VIN')); ?></p>
</div>
<div class="sm-feld">
  <label for="zoeph<?php echo rn_e($rn_nr); ?>"><?php echo rn_e(rn_t('TEXT.L_PHASE')); ?></label>
  <select data-role="none" id="zoeph<?php echo rn_e($rn_nr); ?>" name="zoeph<?php echo rn_e($rn_nr); ?>">
    <option value="1"<?php echo $rn_cfg['zoeph' . $rn_nr] === '1' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_PHASE1')); ?></option>
    <option value="2"<?php echo $rn_cfg['zoeph' . $rn_nr] === '2' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_PHASE2')); ?></option>
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
  <input data-role="none" type="number" min="1" max="60" id="cron_ncs" name="cron_ncs" value="<?php echo rn_e($rn_cfg['cron_ncs']); ?>">
</div>
<div class="sm-feld">
  <label for="cron_acs"><?php echo rn_e(rn_t('TEXT.L_CRON_ACS')); ?></label>
  <input data-role="none" type="number" min="1" max="60" id="cron_acs" name="cron_acs" value="<?php echo rn_e($rn_cfg['cron_acs']); ?>">
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_SCHALTEN')); ?></h2>
<div class="sm-warnung"><?php echo rn_t('TEXT.H_SCHALTEN_TEXT'); ?></div>
<div class="sm-feld">
  <label for="steuerung_ein"><?php echo rn_e(rn_t('TEXT.L_STEUERUNG')); ?></label>
  <select data-role="none" id="steuerung_ein" name="steuerung_ein">
    <option value="N"<?php echo $rn_cfg['steuerung_ein'] !== 'Y' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_AUS_VORGABE')); ?></option>
    <option value="Y"<?php echo $rn_cfg['steuerung_ein'] === 'Y' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_EIN')); ?></option>
  </select>
</div>
<div class="sm-feld">
  <label for="ac_temp"><?php echo rn_e(rn_t('TEXT.L_AC_TEMP')); ?></label>
  <input data-role="none" type="number" min="16" max="30" id="ac_temp" name="ac_temp" value="<?php echo rn_e($rn_cfg['ac_temp']); ?>">
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_AC_TEMP')); ?></p>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_MELDUNGEN')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_MELDUNGEN_TEXT'); ?></p>
<div class="sm-feld">
  <label for="bl_schwelle"><?php echo rn_e(rn_t('TEXT.L_BL_SCHWELLE')); ?></label>
  <input data-role="none" type="number" min="1" max="99" id="bl_schwelle" name="bl_schwelle" value="<?php echo rn_e($rn_cfg['bl_schwelle']); ?>">
</div>
<?php foreach (array('mail_bl' => 'TEXT.L_MAIL_BL', 'cmon_bl' => 'TEXT.L_CMON_BL',
                     'mail_csf' => 'TEXT.L_MAIL_CSF') as $rn_k => $rn_lbl) { ?>
<div class="sm-feld">
  <label for="<?php echo rn_e($rn_k); ?>"><?php echo rn_e(rn_t($rn_lbl)); ?></label>
  <select data-role="none" id="<?php echo rn_e($rn_k); ?>" name="<?php echo rn_e($rn_k); ?>">
    <option value="N"<?php echo $rn_cfg[$rn_k] !== 'Y' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_NEIN')); ?></option>
    <option value="Y"<?php echo $rn_cfg[$rn_k] === 'Y' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_JA')); ?></option>
  </select>
</div>
<?php } ?>
<div class="sm-feld">
  <label for="exec_bl"><?php echo rn_e(rn_t('TEXT.L_EXEC_BL')); ?></label>
  <input data-role="none" type="text" id="exec_bl" name="exec_bl" value="<?php echo rn_e($rn_cfg['exec_bl']); ?>">
</div>
<div class="sm-feld">
  <label for="exec_csf"><?php echo rn_e(rn_t('TEXT.L_EXEC_CSF')); ?></label>
  <input data-role="none" type="text" id="exec_csf" name="exec_csf" value="<?php echo rn_e($rn_cfg['exec_csf']); ?>">
  <p class="sm-hilfe"><?php echo rn_t('TEXT.H_EXEC'); ?></p>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_LADEZIEL')); ?></h2>
<div class="sm-info"><?php echo rn_t('TEXT.H_LADEZIEL_TEXT'); ?></div>
<div class="sm-feld">
  <label for="soc_min"><?php echo rn_e(rn_t('TEXT.L_SOC_MIN')); ?></label>
  <input data-role="none" type="number" min="20" max="100" id="soc_min" name="soc_min" value="<?php echo rn_e($rn_cfg['soc_min']); ?>">
</div>
<div class="sm-feld">
  <label for="soc_target"><?php echo rn_e(rn_t('TEXT.L_SOC_TARGET')); ?></label>
  <input data-role="none" type="number" min="20" max="100" id="soc_target" name="soc_target" value="<?php echo rn_e($rn_cfg['soc_target']); ?>">
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_SOC_LEER')); ?></p>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_AUFZEICHNUNG')); ?></h2>
<div class="sm-feld">
  <label for="save_in_db"><?php echo rn_e(rn_t('TEXT.L_SAVE_IN_DB')); ?></label>
  <select data-role="none" id="save_in_db" name="save_in_db">
    <option value="N"<?php echo $rn_cfg['save_in_db'] !== 'Y' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_NEIN')); ?></option>
    <option value="Y"<?php echo $rn_cfg['save_in_db'] === 'Y' ? ' selected' : ''; ?>><?php echo rn_e(rn_t('TEXT.O_JA_ANHAENGEN')); ?></option>
  </select>
  <p class="sm-hilfe"><?php echo rn_t('TEXT.H_AUFZEICHNUNG_TEXT'); ?></p>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_FREMDDIENSTE')); ?></h2>
<div class="sm-feld">
  <label for="weather_api_key"><?php echo rn_e(rn_t('TEXT.L_WETTER')); ?></label>
  <input data-role="none" type="text" id="weather_api_key" name="weather_api_key" value="<?php echo rn_e($rn_cfg['weather_api_key']); ?>">
  <p class="sm-hilfe"><?php echo rn_t('TEXT.H_WETTER'); ?></p>
</div>
<div class="sm-feld">
  <label for="abrp_token"><?php echo rn_e(rn_t('TEXT.L_ABRP_TOKEN')); ?></label>
  <input data-role="none" type="text" id="abrp_token" name="abrp_token" value="<?php echo rn_e($rn_cfg['abrp_token']); ?>">
</div>
<div class="sm-feld">
  <label for="abrp_model"><?php echo rn_e(rn_t('TEXT.L_ABRP_MODEL')); ?></label>
  <input data-role="none" type="text" id="abrp_model" name="abrp_model" value="<?php echo rn_e($rn_cfg['abrp_model']); ?>">
  <p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_ABRP')); ?></p>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo rn_e(rn_t('LEGENDE.AKTION')); ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?php echo rn_e(rn_t('TEXT.K_SPEICHERN')); ?></button>
</div>
</form>
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
<tr><td><?php echo rn_e(rn_t('TEXT.S_AUTOSTART')); ?></td><td><?php echo $rn_broker['autostart'] ? rn_e(rn_t('TEXT.O_JA')) : '<span class="sm-aus">' . rn_e(rn_t('TEXT.S_KEIN_AUTOSTART')) . '</span>'; ?></td></tr>
<tr><td><?php echo rn_e(rn_t('TEXT.S_BENUTZER')); ?></td><td class="sm-mono"><?php echo $rn_broker['benutzer'] !== '' ? rn_e($rn_broker['benutzer']) : '–'; ?></td></tr>
</table>
<?php } ?>

<h2><?php echo rn_e(rn_t('TEXT.H_ABO')); ?></h2>
<p class="sm-hilfe"><b><?php echo rn_e(rn_t('TEXT.H_ABO_PFLICHT')); ?></b> <?php echo rn_t('TEXT.H_ABO_WO'); ?></p>
<pre class="sm-vorschau"><?php foreach ($rn_autos as $rn_f) {
    echo 'Renault/' . rn_e($rn_f['name']) . "/#\n"; } ?></pre>

<h2><?php echo rn_e(rn_t('TEXT.H_THEMEN')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_THEMEN_TEXT'); ?></p>
<?php foreach ($rn_autos as $rn_f) { ?>
<h3><?php echo rn_e($rn_f['name']); ?> <span class="sm-small">(<?php echo rn_e(sprintf(rn_t('TEXT.S_PHASE'), $rn_f['zoeph'])); ?>)</span></h3>
<table class="sm-tbl">
<tr><th style="width:46%"><?php echo rn_e(rn_t('TEXT.S_THEMA')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_BEDEUTUNG')); ?></th></tr>
<?php foreach (rn_themen($rn_f['zoeph']) as $rn_thema => $rn_schl) { ?>
<tr><td class="sm-mono">Renault/<?php echo rn_e($rn_f['name'] . '/' . $rn_thema); ?></td>
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
<p class="sm-hilfe"><b><?php echo rn_e(rn_t('TEXT.H_ABO_PFLICHT')); ?></b> <?php echo rn_t('TEXT.H_ABO_WO'); ?></p>
<pre class="sm-vorschau"><?php foreach ($rn_autos as $rn_f) {
    echo 'Renault/' . rn_e($rn_f['name']) . "/#\n"; } ?></pre>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_e(rn_t('TEXT.SCHRITT3')); ?></h3>
<p class="sm-hilfe"><?php echo rn_t('TEXT.SCHRITT3_TEXT'); ?></p>
<?php foreach ($rn_autos as $rn_f) { ?>
<table class="sm-tbl">
<tr><th><?php echo rn_e(rn_t('TEXT.S_TITEL')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_BEDEUTUNG')); ?></th></tr>
<?php foreach (rn_vorlage_felder($rn_f['zoeph']) as $rn_w) { ?>
<tr><td class="sm-mono">Renault_<?php echo rn_e($rn_f['name'] . '_' . $rn_w[0]); ?></td>
    <td><?php echo rn_e($rn_w[5]); ?></td>
    <td><?php echo rn_e(rn_t($rn_w[1])); ?></td></tr>
<?php } ?>
</table>
<?php } ?>

<h3 class="sm-h3"><?php echo rn_e(rn_t('TEXT.H_VORLAGE')); ?></h3>
<div class="sm-hinweis"><?php echo rn_t('TEXT.H_VORLAGE_TEXT'); ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo rn_e(rn_t('LEGENDE.TECHNIK')); ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
  <input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
  <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
  <input data-role="none" type="hidden" name="vorlage" value="vi">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo rn_e(rn_t('TEXT.K_VORLAGE_VI')); ?></button>
</form>
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
<p class="sm-hilfe"><?php echo rn_t('TEXT.SCHRITT4_TEXT'); ?></p>
<table class="sm-tbl">
<tr><th style="width:30%"><?php echo rn_e(rn_t('TEXT.S_ZWECK')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_BEFEHL_EIN')); ?></th></tr>
<?php foreach ($rn_autos as $rn_f) { foreach (rn_befehle() as $rn_a => $rn_ang) { ?>
<tr><td><?php echo rn_e((count($rn_autos) > 1 ? $rn_f['name'] . ': ' : '') . rn_t($rn_ang[0])); ?></td>
    <td class="sm-mono"><?php echo rn_e(rn_aktionsadresse($rn_cfg, $rn_a, $rn_f['nr'])); ?></td></tr>
<?php } } ?>
<tr><td><?php echo rn_e(rn_t('TEXT.S_SELBSTTEST')); ?></td>
    <td class="sm-mono"><?php echo rn_e(rn_selbsttestadresse($rn_cfg)); ?></td></tr>
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
<?php $rn_e1 = $rn_autos[0]['name']; ?>
<table class="sm-tbl">
<tr><th>#</th><th><?php echo rn_e(rn_t('TEXT.S_BAUSTEIN')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_NAME_VORSCHLAG')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_PARAMETER')); ?></th><th><?php echo rn_e(rn_t('TEXT.S_EINGAENGE')); ?></th></tr>
<tr><td>1</td><td><?php echo rn_e(rn_t('TEXT.B_VI')); ?></td><td>Auto_Akku</td><td><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?> <span class="sm-mono">&lt;v.0&gt; %</span></td><td>MQTT <span class="sm-mono">BattSOC</span></td></tr>
<tr><td>2</td><td><?php echo rn_e(rn_t('TEXT.B_VI')); ?></td><td>Auto_Reichweite</td><td><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?> <span class="sm-mono">&lt;v.0&gt; km</span></td><td>MQTT <span class="sm-mono">Range</span></td></tr>
<tr><td>3</td><td><?php echo rn_e(rn_t('TEXT.B_VI')); ?></td><td>Auto_laedt</td><td><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?> <span class="sm-mono">&lt;v.0&gt;</span></td><td>MQTT <span class="sm-mono">ChargingStatus</span></td></tr>
<tr><td>4</td><td><?php echo rn_e(rn_t('TEXT.B_VI')); ?></td><td>Auto_Kabel</td><td><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?> <span class="sm-mono">&lt;v.0&gt;</span></td><td>MQTT <span class="sm-mono">CableStatus</span></td></tr>
<tr><td>5</td><td><?php echo rn_e(rn_t('TEXT.B_VI')); ?></td><td>Auto_Abruf_ok</td><td><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?> <span class="sm-mono">&lt;v.0&gt;</span></td><td>MQTT <span class="sm-mono">ok</span></td></tr>
<tr><td>6</td><td><?php echo rn_e(rn_t('TEXT.B_VI')); ?></td><td>Auto_Abrufzeit</td><td><?php echo rn_e(rn_t('TEXT.S_EINHEIT')); ?> <span class="sm-mono">&lt;v.0&gt;</span></td><td>MQTT <span class="sm-mono">phpCall</span></td></tr>
<tr><td>7</td><td><?php echo rn_e(rn_t('TEXT.B_STATUS')); ?></td><td>Auto_Status</td><td><?php echo rn_e(rn_t('TEXT.P_STATUS')); ?></td><td><?php echo rn_e(rn_t('TEXT.S_EINGANG')); ?> &larr; #3</td></tr>
<tr><td>8</td><td><?php echo rn_e(rn_t('TEXT.B_VERGLEICHER')); ?></td><td>Auto_Akku_niedrig</td><td><?php echo rn_e(rn_t('TEXT.P_SCHWELLE20')); ?></td><td><?php echo rn_e(rn_t('TEXT.S_EINGANG')); ?> &larr; #1</td></tr>
<tr><td>9</td><td><?php echo rn_e(rn_t('TEXT.B_TREPPENLICHT')); ?></td><td>Auto_Abruf_haengt</td><td><?php echo rn_e(rn_t('TEXT.P_HALTEZEIT')); ?></td><td><?php echo rn_e(rn_t('TEXT.P_FLANKE')); ?> #6</td></tr>
<tr><td>10</td><td><?php echo rn_e(rn_t('TEXT.B_ODER')); ?></td><td>Auto_Meldungen</td><td>–</td><td><?php echo rn_e(rn_t('TEXT.S_EINGAENGE')); ?> #8, #9</td></tr>
<tr><td>11</td><td><?php echo rn_e(rn_t('TEXT.B_BENACHRICHTIGUNG')); ?></td><td>Auto_Melder</td><td><?php echo rn_e(rn_t('TEXT.P_TEXT_FREI')); ?></td><td><?php echo rn_e(rn_t('TEXT.S_EINGANG')); ?> &larr; #10</td></tr>
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
foreach (rn_test_selbstpruefung($rn_cfg, $rn_broker) as $rn_z) {
    echo '<tr><td>' . rn_e($rn_z[0]) . '</td><td>'
       . ($rn_z[1] ? '&#10004; ' : '&#10008; ') . rn_e($rn_z[2]) . '</td></tr>';
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

<h2><?php echo rn_e(rn_t('TEXT.H_TECHNIK')); ?></h2>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="formtoken" value="<?php echo rn_e($rn_ftoken); ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="cache_leeren" value="1"><?php echo rn_e(rn_t('TEXT.K_CACHE_LEEREN')); ?></button>
  </form>
</div>

<h2><?php echo rn_e(rn_t('TEXT.H_SCHALTEN_TEST')); ?></h2>
<p class="sm-hilfe"><?php echo rn_t('TEXT.H_SCHALTEN_TEST_TEXT'); ?></p>
<?php if ($rn_cfg['steuerung_ein'] !== 'Y') { ?>
<div class="sm-warnung"><?php echo rn_t('TEXT.H_STEUERUNG_AUS'); ?></div>
<?php } ?>
<div class="sm-knopfreihe">
<?php foreach ($rn_autos as $rn_f) { foreach (rn_befehle() as $rn_a => $rn_ang) { ?>
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
    array_shift($rn_reihen);                       // Kopfzeile
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
    $rn_letzte = array_slice(array_reverse($rn_reihen), 0, 30);
    echo '<p class="sm-hilfe">' . rn_e(rn_t('TEXT.H_TABELLE_30')) . '</p><table class="sm-tbl">';
    foreach ($rn_letzte as $rn_z) {
        echo '<tr>';
        foreach (explode(';', $rn_z) as $rn_feld) { echo '<td class="sm-mono">' . rn_e($rn_feld) . '</td>'; }
        echo '</tr>';
    }
    echo '</table>';
}
?>
</div>

<!-- ============================== Logdateien ============================== -->
<div class="sm-seite<?php echo $rn_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2><?php echo rn_e(rn_t('TEXT.H_LOG')); ?></h2>
<p class="sm-hilfe"><?php echo rn_e(rn_t('TEXT.H_LOG_TEXT')); ?>
<span class="sm-mono"><?php echo rn_e($rn_p['log']); ?></span></p>
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
