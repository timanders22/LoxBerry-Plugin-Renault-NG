<?php
/**
 * Renault - Bedienoberflaeche
 *
 * Ausschliesslich Oberflaeche. Der Datenabruf steht in abruf.php und wird
 * vom Cron aufgerufen; die Befehle von Loxone nimmt der Endpunkt unter
 * webfrontend/html/index.php entgegen (mit Token, ohne Anmeldung).
 */

require_once 'loxberry_web.php';
require_once __DIR__ . '/rn_lib.php';

// Frueher wurde hier LBSystem::readlanguage('language.ini') aufgerufen und
// das Ergebnis nach $L gelegt. $L wurde jedoch NIE gelesen - die Texte
// holt rn_t() unmittelbar aus templates/lang/language_<sprache>.ini. Der
// Aufruf suchte ausserdem eine Datei 'language.ini', die es hier gar nicht
// gibt (nur language_de.ini und language_en.ini). Ersatzlos entfallen.
$rn_p       = rn_paths();
$rn_meldung = '';
$rn_fehler  = array();

/* Aktiver Reiter: aus dem abgesendeten Formular (activetab) oder aus der
   Adresse (?form=...). Letzteres brauchen die Reiter, seit sie echte
   Verweise sind. Die Positivliste MUSS jeden Reiter enthalten - fehlt
   einer, springt die Seite nach jedem Absenden zurueck auf Einstellungen. */
$rn_muster = '/^tab-(settings|mqtt|loxone|test|verlauf|log)$/';
$rn_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['form']) ? 'tab-' . (string) $_GET['form'] : '');
$rn_tab = preg_match($rn_muster, $rn_wunsch) ? $rn_wunsch : 'tab-settings';

$rn_cfg = rn_config_read();

// Beim ersten Aufruf ein Token erzeugen, damit der Endpunkt fuer Loxone
// sofort benutzbar ist.
if ($rn_cfg['aktionstoken'] === '') {
    $rn_cfg['aktionstoken'] = rn_token_erzeugen();
    rn_config_write($rn_cfg);
}

/* ---------------------------------------------------------------- *
 * Formulare
 * ---------------------------------------------------------------- */
if (isset($_POST['token_neu'])) {
    $rn_cfg['aktionstoken'] = rn_token_erzeugen();
    if (rn_config_write($rn_cfg)) {
        $rn_meldung = 'Neues Token erzeugt. <b>Die Adressen in Loxone m&uuml;ssen '
                    . 'angepasst werden</b> &ndash; die alten funktionieren nicht mehr.';
    }
    $rn_cfg = rn_config_read();
    $rn_tab = 'tab-loxone';
}

if (isset($_POST['speichern'])) {
    $neu = $rn_cfg;
    foreach (array('zoename', 'username', 'vin', 'country', 'zoeph', 'save_in_db') as $f) {
        if (isset($_POST[$f])) { $neu[$f] = trim((string) $_POST[$f]); }
    }
    // Leeres Passwortfeld heisst "unveraendert lassen", nicht "loeschen".
    if (isset($_POST['password']) && $_POST['password'] !== '') {
        $neu['password'] = (string) $_POST['password'];
    }
    if ($neu['zoename'] === '') {
        $rn_fehler[] = 'Der Name des Fahrzeugs darf nicht leer sein &ndash; er bildet den MQTT-Themenpfad.';
    }
    if (strpos($neu['zoename'], '/') !== false || strpos($neu['zoename'], '#') !== false
        || strpos($neu['zoename'], '+') !== false) {
        $rn_fehler[] = 'Der Name darf kein <span class="sm-mono">/</span>, '
                     . '<span class="sm-mono">#</span> oder <span class="sm-mono">+</span> enthalten '
                     . '&ndash; das sind Sonderzeichen in MQTT-Themen.';
    }
    if ($neu['vin'] !== '' && !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/i', $neu['vin'])) {
        $rn_fehler[] = 'Die Fahrgestellnummer (VIN) besteht aus 17 Zeichen ohne I, O und Q.';
    }
    if (!in_array($neu['zoeph'], array('1', '2'), true)) {
        $rn_fehler[] = 'Die Phase muss 1 oder 2 sein.';
    }
    if (!in_array($neu['save_in_db'], array('Y', 'N'), true)) {
        $rn_fehler[] = 'Die Aufzeichnung kennt nur ja oder nein.';
    }
    if (!preg_match('/^[A-Z]{2}$/', $neu['country'])) {
        $rn_fehler[] = 'Das Land ist ein Zwei-Buchstaben-K&uuml;rzel.';
    }
    if (!$rn_fehler) {
        if (rn_config_write($neu)) {
            @unlink($rn_p['session']);   // Zwischenspeicher verwerfen
            $rn_cfg = rn_config_read();
            $rn_meldung = 'Gespeichert. Der Zwischenspeicher wurde geleert, '
                        . 'der naechste Abruf holt alles neu.';
        } else {
            $rn_fehler[] = 'Die Datei <span class="sm-mono">config.php</span> liess sich nicht '
                         . 'schreiben. Rechte im Plugin-Ordner pruefen.';
        }
    }
    $rn_tab = 'tab-settings';
}

if (isset($_POST['log_leeren'])) {
    @unlink($rn_p['log']);
    @unlink($rn_p['log'] . '.1');
    $rn_meldung = 'Das Protokoll wurde geleert.';
    $rn_tab = 'tab-log';
}

$rn_test_titel = '';
$rn_test_text  = '';
if (isset($_POST['test'])) {
    require_once __DIR__ . '/rn_test.php';
    list($rn_test_titel, $rn_test_text) = rn_test_ausfuehren((string) $_POST['test'], $rn_cfg);
    $rn_tab = 'tab-test';
}

$rn_broker  = rn_mqtt_broker();
$rn_session = rn_session();
$rn_zeilen  = rn_log_tail();
$rn_thema   = $rn_cfg['zoename'];

$template_title = 'Renault';
$helplink       = 'https://wiki.loxberry.de/plugins/renault_api/start';
$helptemplate   = 'help.html';
LBWeb::lbheader($template_title, $helplink, $helptemplate);
?>

<style>
.sm-wrap { max-width: 1100px; }
.sm-wrap h2 { color: #4f7d17; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px;
  font-size: 1.15em; margin: 22px 0 8px; }
.sm-wrap h3.sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-small { font-size: 0.88em; color: #555; }
.sm-mono { font-family: monospace; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
  padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important;
  text-decoration: none !important; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; }
.sm-tbl td, .sm-tbl th { border: 1px solid #ddd; padding: 6px 9px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
.sm-row { margin: 8px 0; }
.sm-row label { display: block; font-weight: 600; font-size: 0.9em; margin-bottom: 2px; }
.sm-row input, .sm-row select { width: 100%; max-width: 420px; padding: 7px; box-sizing: border-box; }
.sm-alert { padding: 10px 12px; border-radius: 6px; margin: 10px 0; font-size: 0.9em; }
.sm-ok   { background: #eaf5e0; border: 1px solid #6dac20; }
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
.sm-info { background: #eef3f7; border: 1px solid #546e7a; }
.sm-log { background: #1e1e1e; color: #ddd; font-family: monospace; font-size: 0.82em;
  padding: 10px; border-radius: 6px; max-height: 460px; overflow: auto; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; }
.sm-wrap .sm-knopfreihe button, .sm-wrap .sm-btn {
  border: 0 !important; border-radius: 6px !important; padding: 9px 16px !important;
  font-size: 0.9em !important; cursor: pointer; color: #fff !important;
  font-weight: 600 !important; text-shadow: none !important; box-shadow: none !important;
  opacity: 1 !important; margin: 0 !important; text-decoration: none; display: inline-block; }
.sm-wrap .sm-b-lesen button,   .sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-b-lesen button:hover,   .sm-wrap .sm-b-lesen button:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-b-technik button, .sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-b-technik button:hover, .sm-wrap .sm-b-technik button:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-b-aktion button,  .sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-b-aktion button:hover,  .sm-wrap .sm-b-aktion button:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-step { border-left: 3px solid #6dac20; padding: 2px 0 2px 12px; margin: 12px 0; }
.sm-vorschau { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-family: monospace;
  white-space: pre-wrap; font-size: 0.86em; }
</style>

<div class="sm-wrap">

<?php if ($rn_fehler) { ?>
<div class="sm-alert sm-warn"><b><?php echo rn_t('TEXT.NICHT_GESPEICHERT'); ?></b><ul>
<?php foreach ($rn_fehler as $f) { echo '<li>' . $f . '</li>'; } ?>
</ul></div>
<?php } elseif ($rn_meldung !== '') { ?>
<div class="sm-alert sm-ok"><?php echo $rn_meldung; ?></div>
<?php } ?>

<?php
/*
 * Reiter als echte Verweise, sm-active vom SERVER.
 *
 * Bis 1.4 standen hier <div class="sm-tab"> ohne Verweis, und sm-active
 * vergab allein das JavaScript am Seitenende. Da .sm-pane auf display:none
 * steht, war die Seite ohne JavaScript vollstaendig leer - und die Reiter
 * liessen sich nicht einmal anklicken, weil ein <div> kein Verweis ist.
 *
 * $rn_tab wurde serverseitig laengst ermittelt und nur ans JavaScript
 * weitergereicht. Diese Liste, die Positivliste weiter oben und die id der
 * Flaechen muessen deckungsgleich bleiben - alle drei.
 */
$rn_reiter = array(
    'tab-settings' => rn_t('REITER.EINSTELLUNGEN'),
    'tab-mqtt'     => rn_t('REITER.MQTT'),
    'tab-loxone'   => rn_t('REITER.LOXONE'),
    'tab-test'     => rn_t('REITER.TEST'),
    'tab-verlauf'  => rn_t('REITER.VERLAUF'),
    'tab-log'      => rn_t('REITER.LOG'),
);
?>
<div class="sm-tabs">
<?php foreach ($rn_reiter as $rn_id => $rn_bez) { ?>
  <a class="sm-tab<?php echo $rn_tab === $rn_id ? ' sm-active' : ''; ?>" data-ziel="<?php echo rn_e($rn_id); ?>"
     href="index.php?form=<?php echo rn_e(substr($rn_id, 4)); ?>"><?php echo $rn_bez; ?></a>
<?php } ?>
</div>

<!-- ============================ <?php echo rn_t('TEXT.EINSTELLUNGEN'); ?> ============================ -->
<div class="sm-pane<?php echo $rn_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">
<form method="post" action="index.php" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo rn_t('TEXT.ZUGANG_ZUM_RENAULT_KONTO'); ?></h2>
<p class="sm-small"><?php echo rn_t('TEXT.DIESELBEN_ZUGANGSDATEN_WIE_IN_DER_'); ?><span class="sm-mono"><?php echo rn_t('TEXT.CONFIG_PHP'); ?></span><?php echo rn_t('TEXT.RECHTE_0600_UND_SCHICKT_SIE_NUR_AN'); ?></p>

<div class="sm-row">
  <label for="username"><?php echo rn_t('TEXT.BENUTZER_E_MAIL_ADRESSE'); ?></label>
  <input data-role="none" type="text" id="username" name="username"
         value="<?php echo rn_e($rn_cfg['username']); ?>">
</div>
<div class="sm-row">
  <label for="password"><?php echo rn_t('TEXT.PASSWORT'); ?></label>
  <input data-role="none" type="password" id="password" name="password" value=""
         placeholder="<?php echo $rn_cfg['password'] !== ''
             ? 'gespeichert &ndash; leer lassen, um es zu behalten'
             : 'noch nicht gesetzt'; ?>">
  <p class="sm-small"><?php echo rn_t('TEXT.DAS_GESPEICHERTE_PASSWORT_WIRD_NIE'); ?></p>
</div>
<div class="sm-row">
  <label for="vin"><?php echo rn_t('TEXT.FAHRGESTELLNUMMER_VIN'); ?></label>
  <input data-role="none" type="text" id="vin" name="vin" maxlength="17"
         value="<?php echo rn_e($rn_cfg['vin']); ?>">
  <p class="sm-small"><?php echo rn_t('TEXT.17_ZEICHEN_STEHT_IM_FAHRZEUGSCHEIN'); ?>
  <i><?php echo rn_t('TEXT.FAHRZEUGDATEN'); ?></i>.</p>
</div>
<div class="sm-row">
  <label for="country"><?php echo rn_t('TEXT.LAND'); ?></label>
  <select data-role="none" id="country" name="country">
    <?php foreach (array('DE' => 'Deutschland', 'AT' => '&Ouml;sterreich',
                         'CH' => 'Schweiz', 'IT' => 'Italien', 'SE' => 'Schweden',
                         'FR' => 'Frankreich', 'EN' => 'andere / Englisch') as $k => $v) { ?>
      <option value="<?php echo $k; ?>"<?php
        echo $rn_cfg['country'] === $k ? ' selected' : ''; ?>><?php echo $v; ?></option>
    <?php } ?>
  </select>
</div>

<h2><?php echo rn_t('TEXT.FAHRZEUG'); ?></h2>
<div class="sm-row">
  <label for="zoename"><?php echo rn_t('TEXT.NAME_DES_FAHRZEUGS'); ?></label>
  <input data-role="none" type="text" id="zoename" name="zoename"
         value="<?php echo rn_e($rn_cfg['zoename']); ?>">
  <p class="sm-small"><?php echo rn_t('TEXT.BILDET_DEN_MQTT_THEMENPFAD'); ?>
  <span class="sm-mono"><?php echo rn_t('TEXT.RENAULT_3'); ?><?php echo rn_e($rn_cfg['zoename']); ?>/&hellip;</span>
  <?php echo rn_t('TEXT.OHNE'); ?> <span class="sm-mono">/</span>, <span class="sm-mono">#</span>
  und <span class="sm-mono">+</span>.</p>
</div>
<div class="sm-row">
  <label for="zoeph"><?php echo rn_t('TEXT.FAHRZEUG_GENERATION'); ?></label>
  <select data-role="none" id="zoeph" name="zoeph">
    <option value="1"<?php echo $rn_cfg['zoeph'] === '1' ? ' selected' : ''; ?>><?php echo rn_t('TEXT.PHASE_1_MIT_AUSSEN_UND_BATTERIETEM'); ?></option>
    <option value="2"<?php echo $rn_cfg['zoeph'] === '2' ? ' selected' : ''; ?>><?php echo rn_t('TEXT.PHASE_2_MIT_GPS_POSITION'); ?></option>
  </select>
  <p class="sm-small"><?php echo rn_t('TEXT.WELCHE_WERTE_RENAULT_LIEFERT_HAENG'); ?></p>
</div>

<h2><?php echo rn_t('TEXT.AUFZEICHNUNG'); ?></h2>
<div class="sm-row">
  <label for="save_in_db"><?php echo rn_t('TEXT.WERTE_IN'); ?> <span class="sm-mono"><?php echo rn_t('TEXT.DATABASE_CSV'); ?></span> <?php echo rn_t('TEXT.MITSCHREIBEN'); ?></label>
  <select data-role="none" id="save_in_db" name="save_in_db">
    <option value="N"<?php echo $rn_cfg['save_in_db'] !== 'Y' ? ' selected' : ''; ?>><?php echo rn_t('TEXT.NEIN'); ?></option>
    <option value="Y"<?php echo $rn_cfg['save_in_db'] === 'Y' ? ' selected' : ''; ?>><?php echo rn_t('TEXT.JA_JEDE_ABFRAGE_ANHNGEN'); ?></option>
  </select>
  <p class="sm-small"><?php echo rn_t('TEXT.SICHTBAR_IM_REITER'); ?> <i><?php echo rn_t('TEXT.LADEHISTORIE'); ?></i><?php echo rn_t('TEXT.DIE_DATEI_WAECHST_LANGSAM_ABER_STE'); ?></p>
</div>

<div class="sm-knopfreihe sm-b-aktion">
  <button data-role="none" type="submit" name="speichern" value="1"><?php echo rn_t('TEXT.SPEICHERN'); ?></button>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo rn_t('LEGENDE.AKTION_CACHE'); ?></span>
</div>
</form>

<h2><?php echo rn_t('TEXT.ABRUFZYKLUS'); ?></h2>
<p class="sm-small"><?php echo rn_t('TEXT.DER_ABRUF_LAEUFT_UEBER_DEN_LOXBERR'); ?><span class="sm-mono"><?php echo rn_t('TEXT.CRON_03MIN'); ?></span><?php echo rn_t('TEXT.DIE_LADEHISTORIE_ALLE_ZEHN_MINUTEN'); ?></p>
</div>

<!-- ================================= <?php echo rn_t('TEXT.MQTT'); ?> ================================= -->
<div class="sm-pane<?php echo $rn_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" id="tab-mqtt">

<h2><?php echo rn_t('TEXT.ZUSTAND_DES_MQTT_GATEWAYS'); ?></h2>
<p class="sm-small"><?php echo rn_t('TEXT.DAS_MQTT_GATEWAY_IST_SEIT_LOXBERRY'); ?> <b><?php echo rn_t('TEXT.BESTANDTEIL_DES_SYSTEMS'); ?></b> <?php echo rn_t('TEXT.UND_KEIN_PLUGIN_ES_WIRD_UNTER'); ?> <i><?php echo rn_t('TEXT.SYSTEM_MQTT_GATEWAY'); ?></i>
<?php echo rn_t('TEXT.EINGERICHTET'); ?></p>

<?php if (!$rn_broker) { ?>
<div class="sm-alert sm-warn">In <span class="sm-mono"><?php echo rn_t('TEXT.CONFIG_SYSTEM_GENERAL_JSON'); ?></span>
<?php echo rn_t('TEXT.STEHT_KEIN_BROKER_DAS_PLUGIN_MELDE'); ?>
<i>System &rarr; MQTT Gateway</i> <?php echo rn_t('TEXT.EINRICHTEN'); ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:34%"><?php echo rn_t('TEXT.GRE'); ?></th><th><?php echo rn_t('TEXT.WERT'); ?></th></tr>
<tr><td><?php echo rn_t('TEXT.BROKER'); ?></td><td class="sm-mono"><?php
  echo rn_e($rn_broker['host'] . ':' . $rn_broker['port']); ?></td></tr>
<tr><td><?php echo rn_t('TEXT.EIGENER_BROKER_AUF_DEM_LOXBERRY'); ?></td><td><?php
  echo $rn_broker['lokal'] ? 'ja' : 'nein &ndash; es wird ein fremder Broker verwendet'; ?></td></tr>
<tr><td><?php echo rn_t('TEXT.GATEWAY_STARTET_AUTOMATISCH'); ?></td><td><?php
  echo $rn_broker['autostart'] ? 'ja' : 'nein &ndash; nach einem Neustart kommt nichts an'; ?></td></tr>
<tr><td><?php echo rn_t('TEXT.BENUTZER'); ?></td><td class="sm-mono"><?php
  echo $rn_broker['benutzer'] !== '' ? rn_e($rn_broker['benutzer']) : '&ndash;'; ?></td></tr>
</table>
<?php } ?>

<h2><?php echo rn_t('TEXT.DAS_EINZUTRAGENDE_ABO'); ?></h2>
<p class="sm-small"><b><?php echo rn_t('TEXT.OHNE_DIESEN_EINTRAG_KOMMT_AM_MINIS'); ?></b>
<?php echo rn_t('TEXT.EINZUTRAGEN_UNTER'); ?> <i><?php echo rn_t('TEXT.SYSTEM_MQTT_GATEWAY_ABONNEMENTS'); ?></i>:</p>
<pre class="sm-vorschau">Renault/<?php echo rn_e($rn_thema); ?>/#</pre>

<h2><?php echo rn_t('TEXT.VERFFENTLICHTE_THEMEN'); ?></h2>
<p class="sm-small"><?php echo rn_t('TEXT.ALLE_WERTE_WERDEN'); ?> <b><?php echo rn_t('TEXT.RETAINED'); ?></b> <?php echo rn_t('TEXT.GESENDET_EIN_NEU_VERBUNDENER_TEILN'); ?></p>
<table class="sm-tbl">
<tr><th style="width:46%"><?php echo rn_t('TEXT.THEMA'); ?></th><th><?php echo rn_t('TEXT.BEDEUTUNG'); ?></th></tr>
<?php foreach (rn_themen($rn_cfg['zoeph']) as $thema => $bedeutung) { ?>
<tr><td class="sm-mono">Renault/<?php echo rn_e($rn_thema . '/' . $thema); ?></td>
    <td><?php echo $bedeutung; ?></td></tr>
<?php } ?>
</table>

<p class="sm-small"><?php echo rn_t('TEXT.MQTT_IST_DER_REGELWEG_DIE_BEFEHLE_'); ?>
<i><?php echo rn_t('TEXT.EINBINDUNG_IN_LOXONE'); ?></i>.</p>
</div>

<!-- ========================= Einbindung in Loxone ========================= -->
<div class="sm-pane<?php echo $rn_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">

<h2><?php echo rn_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<p class="sm-small"><?php echo rn_t('TEXT.DAS_PLUGIN_HOLT_DIE_FAHRZEUGDATEN_'); ?></p>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_t('TEXT.SCHRITT_1_WEG_FESTLEGEN'); ?></h3>
<p class="sm-small"><b><?php echo rn_t('TEXT.MQTT_IST_DER_REGELWEG'); ?></b> <?php echo rn_t('TEXT.FUER_ALLE_MESSWERTE_FUER_DIE_BEFEH'); ?></p>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_t('TEXT.SCHRITT_2_ABO_IM_MQTT_GATEWAY_EINT'); ?></h3>
<p class="sm-small"><b>Ohne diesen Eintrag kommt am Miniserver nichts an.</b>
<?php echo rn_t('TEXT.UNTER'); ?> <i>System &rarr; MQTT Gateway &rarr; Abonnements</i>:</p>
<pre class="sm-vorschau">Renault/<?php echo rn_e($rn_thema); ?>/#</pre>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_t('TEXT.SCHRITT_3_VIRTUELLE_EINGNGE_ANLEGE'); ?></h3>
<p class="sm-small"><?php echo rn_t('TEXT.DAS_MQTT_GATEWAY_LEGT_DIE_EINGNGE_'); ?></p>
<table class="sm-tbl">
<tr><th><?php echo rn_t('TEXT.TITEL'); ?></th><th><?php echo rn_t('TEXT.EINHEIT'); ?></th><th>Bedeutung</th></tr>
<tr><td class="sm-mono">Renault_<?php echo rn_e($rn_thema); ?>_<?php echo rn_t('TEXT.BATTERYLEVEL'); ?></td><td><?php echo rn_t('TEXT.V_0'); ?></td><td><?php echo rn_t('TEXT.BATTERIESTAND'); ?></td></tr>
<tr><td class="sm-mono">Renault_<?php echo rn_e($rn_thema); ?>_<?php echo rn_t('TEXT.RANGEHVACOFF'); ?></td><td><?php echo rn_t('TEXT.V_0KM'); ?></td><td><?php echo rn_t('TEXT.REICHWEITE'); ?></td></tr>
<tr><td class="sm-mono">Renault_<?php echo rn_e($rn_thema); ?>_<?php echo rn_t('TEXT.CHARGINGSTATUS'); ?></td><td>&lt;v.0&gt;</td><td><?php echo rn_t('TEXT.LADESTATUS'); ?></td></tr>
<tr><td class="sm-mono">Renault_<?php echo rn_e($rn_thema); ?>_<?php echo rn_t('TEXT.PLUGSTATUS'); ?></td><td>&lt;v.0&gt;</td><td><?php echo rn_t('TEXT.KABEL_EINGESTECKT'); ?></td></tr>
<tr><td class="sm-mono">Renault_<?php echo rn_e($rn_thema); ?>_Mileage</td><td>&lt;v.0&gt;&nbsp;km</td><td><?php echo rn_t('TEXT.KILOMETERSTAND'); ?></td></tr>
<tr><td class="sm-mono">Renault_<?php echo rn_e($rn_thema); ?>_<?php echo rn_t('TEXT.PHPCALL'); ?></td><td>&lt;v.0&gt;</td><td><?php echo rn_t('TEXT.ZEITSTEMPEL_DES_LETZTEN_ABRUFS'); ?></td></tr>
</table>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_t('TEXT.SCHRITT_4_BEFEHLE_SENDEN'); ?></h3>

<div class="sm-alert sm-warn"><b><?php echo rn_t('TEXT.GENDERT_IN_FASSUNG1_6_0'); ?></b> <?php echo rn_t('TEXT.DIE_BEFEHLE_LAUFEN_NICHT_MEHR_BER'); ?>
<span class="sm-mono"><?php echo rn_t('TEXT.ADMIN_PLUGINS_INDEX_PHP_ACNOW'); ?></span><?php echo rn_t('TEXT.SONDERN_BER_DEN_ENDPUNKT_UNTEN'); ?> <b><?php echo rn_t('TEXT.OHNE_ANMELDUNG_DAFR_MIT_TOKEN'); ?></b><?php echo rn_t('TEXT.WER_VON_EINER_LTEREN_FASSUNG_KOMMT'); ?></div>

<p class="sm-small"><?php echo rn_t('TEXT.JE_BEFEHL_EIN'); ?> <b><?php echo rn_t('TEXT.VIRTUELLER_AUSGANG'); ?></b> <?php echo rn_t('TEXT.VOM_TYP'); ?>
<i><?php echo rn_t('TEXT.VIRTUELLER_AUSGANG_BEFEHL'); ?></i><?php echo rn_t('TEXT.ADRESSE_DES_VIRTUELLEN_AUSGANGS'); ?>
<span class="sm-mono"><?php echo rn_t('TEXT.HTTP_LOXBERRY_ADRESSE'); ?></span><?php echo rn_t('TEXT.IM_BEFEHL_DANN'); ?></p>
<table class="sm-tbl">
<tr><th style="width:34%"><?php echo rn_t('TEXT.ZWECK'); ?></th><th><?php echo rn_t('TEXT.BEFEHL_BEI_EIN'); ?></th></tr>
<?php foreach (rn_befehle() as $p => $zweck) { ?>
<tr><td><?php echo $zweck; ?></td>
    <td class="sm-mono"><?php echo rn_e(rn_aktionsadresse($rn_cfg, $p)); ?></td></tr>
<?php } ?>
</table>
<p class="sm-small"><?php echo rn_t('TEXT.DER_ENDPUNKT_LIEGT_IM_UNANGEMELDET'); ?></p>

<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" type="submit" name="token_neu" value="1"><?php echo rn_t('TEXT.NEUES_TOKEN_ERZEUGEN'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo rn_t('LEGENDE.AKTION_TOKEN'); ?></span>
</div>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_t('TEXT.SCHRITT_5_AUSFALLERKENNUNG'); ?></h3>
<p class="sm-small"><?php echo rn_t('TEXT.SCHWEIGT_RENAULT_BEHALTEN_DIE_VIRT'); ?>
<span class="sm-mono">phpCall</span> <?php echo rn_t('TEXT.MITFUEHREN_DER_WERT_AENDERT_SICH_B'); ?></p>
<p class="sm-small"><?php echo rn_t('TEXT.SCHWELLE_DEUTLICH_UEBER_DEN_ABRUFT'); ?></p>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_t('TEXT.SCHRITT_6_KOMPLETTE_BAUSTEIN_LISTE'); ?></h3>
<p class="sm-small"><?php echo rn_t('TEXT.VON_OBEN_NACH_UNTEN_ABARBEITEN_DIE'); ?></p>
<table class="sm-tbl">
<tr><th>#</th><th><?php echo rn_t('TEXT.BAUSTEIN_TYP'); ?></th><th><?php echo rn_t('TEXT.NAME_VORSCHLAG'); ?></th><th><?php echo rn_t('TEXT.PARAMETER'); ?></th><th><?php echo rn_t('TEXT.EINGNGE_VERBINDEN_MIT'); ?></th></tr>
<tr><td>1</td><td><?php echo rn_t('TEXT.VIRTUELLER_EINGANG'); ?></td><td><?php echo rn_t('TEXT.AUTO_AKKU'); ?></td><td>Einheit <span class="sm-mono">&lt;v.0&gt; %</span></td><td>MQTT <span class="sm-mono">BatteryLevel</span></td></tr>
<tr><td>2</td><td>Virtueller Eingang</td><td><?php echo rn_t('TEXT.AUTO_REICHWEITE'); ?></td><td>Einheit <span class="sm-mono">&lt;v.0&gt; km</span></td><td>MQTT <span class="sm-mono">RangeHvacOff</span></td></tr>
<tr><td>3</td><td>Virtueller Eingang</td><td><?php echo rn_t('TEXT.AUTO_LAEDT'); ?></td><td>Einheit <span class="sm-mono">&lt;v.0&gt;</span></td><td>MQTT <span class="sm-mono">ChargingStatus</span></td></tr>
<tr><td>4</td><td>Virtueller Eingang</td><td><?php echo rn_t('TEXT.AUTO_KABEL'); ?></td><td>Einheit <span class="sm-mono">&lt;v.0&gt;</span></td><td>MQTT <span class="sm-mono">PlugStatus</span></td></tr>
<tr><td>5</td><td>Virtueller Eingang</td><td><?php echo rn_t('TEXT.AUTO_ABRUFZEIT'); ?></td><td>Einheit <span class="sm-mono">&lt;v.0&gt;</span></td><td>MQTT <span class="sm-mono">phpCall</span></td></tr>
<tr><td>6</td><td><?php echo rn_t('TEXT.STATUSBAUSTEIN'); ?></td><td><?php echo rn_t('TEXT.AUTO_STATUS'); ?></td><td><?php echo rn_t('TEXT.TEXT_JE_LADESTATUS'); ?></td><td><?php echo rn_t('TEXT.EINGANG1_3'); ?></td></tr>
<tr><td>7</td><td><?php echo rn_t('TEXT.ANALOGSPEICHER'); ?></td><td><?php echo rn_t('TEXT.AUTO_AKKU_VORWERT'); ?></td><td><?php echo rn_t('TEXT.TEXT'); ?></td><td><?php echo rn_t('TEXT.EINGANG_1'); ?></td></tr>
<tr><td>8</td><td><?php echo rn_t('TEXT.VERGLEICHER'); ?></td><td><?php echo rn_t('TEXT.AUTO_AKKU_NIEDRIG'); ?></td><td><?php echo rn_t('TEXT.SCHWELLE_20'); ?></td><td>Eingang &larr; #1</td></tr>
<tr><td>9</td><td><?php echo rn_t('TEXT.TREPPENLICHTSCHALTER'); ?></td><td><?php echo rn_t('TEXT.AUTO_ABRUF_HAENGT'); ?></td><td><?php echo rn_t('TEXT.HALTEZEIT_1200S'); ?></td><td><?php echo rn_t('TEXT.EINGANG_FLANKENERKENNUNG_VON_5'); ?></td></tr>
<tr><td>10</td><td><?php echo rn_t('TEXT.ODER'); ?></td><td><?php echo rn_t('TEXT.AUTO_MELDUNGEN'); ?></td><td>&ndash;</td><td><?php echo rn_t('TEXT.EINGNGE_8_UND_9'); ?></td></tr>
<tr><td>11</td><td><?php echo rn_t('TEXT.BENACHRICHTIGUNG'); ?></td><td><?php echo rn_t('TEXT.AUTO_MELDER'); ?></td><td><?php echo rn_t('TEXT.TEXT_FREI'); ?></td><td><?php echo rn_t('TEXT.EINGANG_10'); ?></td></tr>
<tr><td>12</td><td>Virtueller Ausgang Befehl</td><td><?php echo rn_t('TEXT.AUTO_KLIMA_AN'); ?></td><td><?php echo rn_t('TEXT.BEFEHL_BEI_EIN_2'); ?> <span class="sm-mono"><?php echo rn_t('TEXT.ACNOW'); ?></span></td><td><?php echo rn_t('TEXT.VON_DER_VISUALISIERUNG'); ?></td></tr>
<tr><td>13</td><td>Virtueller Ausgang Befehl</td><td><?php echo rn_t('TEXT.AUTO_LADEN_JETZT'); ?></td><td>Befehl bei EIN: <span class="sm-mono"><?php echo rn_t('TEXT.CHARGENOW'); ?></span></td><td>von der Visualisierung</td></tr>
<tr><td>14 <i><?php echo rn_t('TEXT.OPTIONAL'); ?></i></td><td>Virtueller Ausgang Befehl</td><td><?php echo rn_t('TEXT.AUTO_LADEPLAN_AN'); ?></td><td>Befehl bei EIN: <span class="sm-mono"><?php echo rn_t('TEXT.CMON'); ?></span></td><td>von der Visualisierung</td></tr>
</table>

<p class="sm-small"><b>Zu #9:</b> <?php echo rn_t('TEXT.DER_TREPPENLICHTSCHALTER_WIRD_VON_'); ?> <span class="sm-mono">phpCall</span> <?php echo rn_t('TEXT.NEU_ANGESTOSSEN_LAEUFT_ER_AB_HAT_D'); ?></p>
<p class="sm-small"><b><?php echo rn_t('TEXT.ZU_10_UND_11'); ?></b> <?php echo rn_t('TEXT.DER_BENACHRICHTIGUNGS_BAUSTEIN_SEN'); ?> <b><?php echo rn_t('TEXT.NIEMALS_MEHRERE_QUELLEN_DIREKT_AN_'); ?></b> <?php echo rn_t('TEXT.ERST_UEBER_ODER_ZUSAMMENFUEHREN_SO'); ?></p>
</div>

<div class="sm-step">
<h3 class="sm-h3"><?php echo rn_t('TEXT.SCHRITT_7_GEGENPROBE'); ?></h3>
<p class="sm-small"><?php echo rn_t('TEXT.IM_REITER'); ?> <i><?php echo rn_t('TEXT.TEST'); ?></i> <i><?php echo rn_t('TEXT.DATEN_SOFORT_NEU_ABRUFEN'); ?></i>
<?php echo rn_t('TEXT.DRUECKEN_DANACH_IN_LOXONE_CONFIG_D'); ?></p>
</div>
</div>

<!-- ================================= Test ================================= -->
<div class="sm-pane<?php echo $rn_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">

<?php if ($rn_test_titel !== '') { ?>
<div class="sm-alert sm-ok"><b><?php echo rn_e($rn_test_titel); ?></b></div>
<?php echo $rn_test_text; ?>
<?php } ?>

<h2><?php echo rn_t('TEXT.SELBSTPRFUNG'); ?></h2>
<table class="sm-tbl">
<tr><th style="width:44%"><?php echo rn_t('TEXT.FRAGE'); ?></th><th><?php echo rn_t('TEXT.ANTWORT'); ?></th></tr>
<tr><td><?php echo rn_t('TEXT.ZUGANGSDATEN_HINTERLEGT'); ?></td><td><?php
  echo ($rn_cfg['username'] !== '' && $rn_cfg['password'] !== '')
     ? '&#10004; ja' : '&#10008; nein &ndash; Reiter Einstellungen'; ?></td></tr>
<tr><td><?php echo rn_t('TEXT.FAHRGESTELLNUMMER_GESETZT'); ?></td><td><?php
  echo $rn_cfg['vin'] !== '' ? '&#10004; ja' : '&#10008; nein'; ?></td></tr>
<tr><td><?php echo rn_t('TEXT.MQTT_GATEWAY_EINGERICHTET'); ?></td><td><?php
  echo $rn_broker ? '&#10004; ja (' . rn_e($rn_broker['host'] . ':' . $rn_broker['port']) . ')'
                  : '&#10008; kein Broker in general.json'; ?></td></tr>
<tr><td><?php echo rn_t('TEXT.SCHON_EINMAL_DATEN_ABGERUFEN'); ?></td><td><?php
  echo $rn_session ? '&#10004; ja' : '&#10008; nein &ndash; noch keine session-Datei'; ?></td></tr>
<tr><td><?php echo rn_t('TEXT.PROTOKOLL_VORHANDEN'); ?></td><td><?php
  echo $rn_zeilen ? '&#10004; ja (' . count($rn_zeilen) . ' Zeilen gelesen)'
                  : '&#10008; noch leer'; ?></td></tr>
</table>

<h2><?php echo rn_t('TEXT.NACHSEHEN'); ?></h2>
<div class="sm-knopfreihe sm-b-lesen">
<?php foreach (array('umgebung' => 'Umgebung pr&uuml;fen',
                     'konfig'   => 'Gespeicherte Konfiguration',
                     'zwischen' => 'Zwischengespeicherte Daten') as $wert => $text) { ?>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" type="submit" name="test" value="<?php echo rn_e($wert); ?>"><?php
      echo $text; ?></button>
  </form>
<?php } ?>
</div>

<h2><?php echo rn_t('TEXT.SCHALTEN'); ?></h2>
<p class="sm-small"><?php echo rn_t('TEXT.DIESE_KNPFE_SPRECHEN_SOFORT_MIT_RE'); ?></p>
<div class="sm-knopfreihe sm-b-aktion">
<?php foreach (rn_befehle() as $p => $zweck) { ?>
  <a class="sm-btn sm-b-aktion" target="_blank"
     href="<?php echo rn_e(rn_aktionsadresse($rn_cfg, $p)); ?>"><?php
    echo $zweck; ?></a>
<?php } ?>
</div>
<p class="sm-small"><?php echo rn_t('TEXT.DIE_KNPFE_RUFEN_DENSELBEN_ENDPUNKT'); ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo rn_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo rn_t('LEGENDE.AKTION_SENDEN'); ?></span>
</div>

<h2><?php echo rn_t('TEXT.LETZTER_STAND'); ?></h2>
<?php if (!$rn_session) { ?>
<div class="sm-alert sm-info"><?php echo rn_t('TEXT.ES_WURDE_NOCH_KEIN_ABRUF_DURCHGEFH'); ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:44%"><?php echo rn_t('TEXT.FELD'); ?></th><th>Wert</th></tr>
<?php foreach (rn_session_felder() as $i => $label) { ?>
<tr><td><?php echo $label; ?></td>
    <td class="sm-mono"><?php echo isset($rn_session[$i]) && $rn_session[$i] !== ''
        ? rn_e($rn_session[$i]) : '&ndash;'; ?></td></tr>
<?php } ?>
</table>
<?php } ?>
</div>

<!-- ============================= Ladehistorie ============================= -->
<div class="sm-pane<?php echo $rn_tab === 'tab-verlauf' ? ' sm-active' : ''; ?>" id="tab-verlauf">
<h2><?php echo rn_t('TEXT.AUFGEZEICHNETE_WERTE'); ?></h2>
<p class="sm-small"><?php echo rn_t('TEXT.IST_DIE_AUFZEICHNUNG_IM_REITER'); ?> <i>Einstellungen</i>
<?php echo rn_t('TEXT.EINGESCHALTET_HAENGT_JEDER_ERFOLGR'); ?>
<span class="sm-mono">database.csv</span> <?php echo rn_t('TEXT.AN_DIE_DATEI_UEBERLEBT_UPDATES_UND'); ?></p>
<?php if ($rn_cfg['save_in_db'] !== 'Y') { ?>
<div class="sm-alert sm-info"><?php echo rn_t('TEXT.DIE_AUFZEICHNUNG_IST_DERZEIT'); ?> <b><?php echo rn_t('TEXT.AUSGESCHALTET'); ?></b><?php echo rn_t('TEXT.BIS_LOXBERRY_FASSUNG1_4_LIESS_SIE_'); ?></div>
<?php } ?>
<p class="sm-small"><?php echo rn_t('TEXT.DER_ZEHN_MINUTEN_CRON_MACHT_ETWAS_'); ?>
<b><?php echo rn_t('TEXT.LADEVORGAENGE_DES_LETZTEN_MONATS'); ?></b> <?php echo rn_t('TEXT.VON_RENAULT_UND_MELDET_SIE_PER_MQT'); ?></p>
<?php
$rn_csv = $rn_p['csv'];
if (!is_readable($rn_csv)) { ?>
<div class="sm-alert sm-info"><?php echo rn_t('TEXT.NOCH_KEINE'); ?> <span class="sm-mono">database.csv</span>
<?php echo rn_t('TEXT.VORHANDEN_SIE_ENTSTEHT_BEIM_ERSTEN'); ?></div>
<?php } else {
    $rn_reihen = @file($rn_csv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $rn_reihen = is_array($rn_reihen) ? array_slice(array_reverse($rn_reihen), 0, 100) : array();
    if (!$rn_reihen) { ?>
<div class="sm-alert sm-info"><?php echo rn_t('TEXT.DIE_DATEI_IST_NOCH_LEER'); ?></div>
<?php } else { ?>
<p class="sm-small"><?php echo rn_t('TEXT.NEUESTE_ZEILE_OBEN_HCHSTENS_100_ZE'); ?></p>
<table class="sm-tbl">
<?php foreach ($rn_reihen as $rn_z) { ?>
<tr><?php foreach (explode(';', $rn_z) as $rn_feld) {
        echo '<td class="sm-mono">' . rn_e($rn_feld) . '</td>';
    } ?></tr>
<?php } ?>
</table>
<?php } } ?>
</div>

<!-- ============================== Logdateien ============================== -->
<div class="sm-pane<?php echo $rn_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2><?php echo rn_t('TEXT.PROTOKOLL'); ?></h2>
<p class="sm-small"><?php echo rn_t('TEXT.NEUESTE_ZEILE_OBEN_DATEI'); ?>
<span class="sm-mono"><?php echo rn_e($rn_p['log']); ?></span></p>
<?php if (!$rn_zeilen) { ?>
<div class="sm-alert sm-info"><?php echo rn_t('TEXT.DIE_PROTOKOLLDATEI_IST_LEER_ODER_N'); ?></div>
<?php } else { ?>
<div class="sm-log"><?php
  foreach ($rn_zeilen as $rn_z) { echo rn_e($rn_z) . "\n"; }
?></div>
<?php } ?>

<div class="sm-knopfreihe sm-b-aktion" style="margin-top:12px;">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" type="submit" name="log_leeren" value="1"><?php echo rn_t('TEXT.PROTOKOLL_LEEREN'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo rn_t('LEGENDE.AKTION_LOG'); ?></span>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
    var reiter = document.querySelectorAll('.sm-tab');
    var seiten = document.querySelectorAll('.sm-pane');
    function zeige(ziel) {
        for (var i = 0; i < reiter.length; i++) {
            reiter[i].classList.toggle('sm-active',
                reiter[i].getAttribute('data-ziel') === ziel);
        }
        for (var j = 0; j < seiten.length; j++) {
            seiten[j].classList.toggle('sm-active', seiten[j].id === ziel);
        }
    }
    for (var k = 0; k < reiter.length; k++) {
        reiter[k].addEventListener('click', function (e) {
            e.preventDefault();
            zeige(this.getAttribute('data-ziel'));
        });
    }
    zeige(<?php echo json_encode($rn_tab); ?>);
})();
</script>

<?php LBWeb::lbfooter(); ?>
