<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Renault NG (Gruppe 40, 2.1.20).
 *
 * WEG: Die Befehlsbruecke (bin/befehlsbruecke.php und .sh, byte-gleich aus der Stammfassung
 * Werkzeuge/gemeinsam/, 1.0.2) abonniert Renault/befehl/# und gibt jeden gueltigen Befehl an tun() dieser
 * Tabelle. tun() ruft DIESELBE Funktion wie der HTTP-Endpunkt (webfrontend/html/index.php) und die
 * Vorklimatisierung: rn_befehl_annehmen() in rn_lib.php, Quelle 'mqtt' - samt Steuerung, Fahrzeug,
 * Zugangsdaten, ac_temp und der Gleichwert-Bremse X-7 - und gibt array(ok, grund) fuer die Quittung zurueck
 * (rn_befehl_quittung()). Fahrzeug 1 unter Renault/befehl/<name>, Fahrzeug 2-4 unter Renault/befehl/<n>/<name>
 * (Teil {nr}). Das Praefix ist fest "Renault" wie die Werte.
 *
 * HAKEN: "Befehle ueber MQTT annehmen" (befehle_mqtt_ein, ab Werk aus, auch beim Update aus). Einen zweiten
 * Haken gibt es nicht: Renault kennt keine eingreifenden Befehle (weder Schliessen noch Oeffnen) - Regeln/07
 * :307 "eingreifend ... nur hinter eigenem Haken" trifft nicht zu. Die schaltenden Befehle (laden, klima_an,
 * klima_aus, ladeplan) wirken zusaetzlich nur mit "Steuerung" (steuerung_ein) - dieselbe zweite Sperre wie
 * fuer den HTTP-Weg. UDP 11884 ist im Heimnetz ohne Anmeldung erreichbar; Schutz sind die Haken.
 *
 * QUITTUNGSART "ausgefuehrt" fuer laden, klima_an, klima_aus und ladeplan: anders als MG (Gateway-Broker,
 * "uebergeben") wartet Renault auf die Antwort der Cloud - die Quittung sagt, ob RENAULT angenommen hat
 * (HTTP < 400) oder X-7 UNVERAENDERT meldete. Die Zeitschranke je Aufruf ist im Brueckenweg 10 s verbinden /
 * 15 s gesamt (bisher 30 s): unguenstigster Fall Anmeldung (2 Aufrufe) + persons (1) + Befehl mit
 * Rueckfallform (2) = 5 x 15 s = 75 s < BB_WACHE_GRENZE 180 s. "auffrischen" und "aussentemp" quittieren
 * "uebergeben": der Abruf laeuft abgekoppelt (rn_abruf_anstossen()), der Aussenwert wird nur gemerkt.
 *
 * SPERRZEITEN: Sollwerte und Zustaende 0 s - die 60-s-Gleichwert-Bremse X-7 des Plugins bleibt die eine
 * Pruefung; sie antwortet UNVERAENDERT, also angenommen. klima_an/klima_aus sind bei Renault Sollwerte
 * (X-7-Gruppe "klima"). auffrischen 60 s (Vorgabe der Bruecke) wie die Abrufbremse des Plugins.
 *
 * REGELN: die Datei setzt keine Konfiguration und schreibt nichts; die Konfiguration wird mit
 * rn_config_read(false) gelesen (legt nichts an, wie der unangemeldete Endpunkt).
 */
if (!function_exists('rn_befehl_annehmen')) {
    /* 2.1.23 (Fremdpruefung E, wie MGiSmart 1.1.30 und bin/healthcheck): welche Lage gilt, entscheidet der eigene
     * Ablageort. Bis 2.1.22 stand eine Kandidatenliste da: installiert gewann zwar der vom Installer eingesetzte Pfad
     * (am Geraet 01.11.2026 nachgesehen), fehlte er aber, waere als Naechstes
     * <home>/bin/plugins/webfrontend/htmlauth/rn_lib.php geladen worden - ein Ort ausserhalb des Plugins. */
    if (basename(dirname(__DIR__)) === 'plugins') {
        $rn_bib = dirname(dirname(dirname(__DIR__))) . '/webfrontend/htmlauth/plugins/' . basename(__DIR__) . '/rn_lib.php';
    } else {
        $rn_bib = dirname(__DIR__) . '/webfrontend/htmlauth/rn_lib.php';
    }
    if (is_file($rn_bib)) {
        require_once $rn_bib;
    }
}
$rn_grund_soll = 'Die 60-s-Gleichwert-Bremse X-7 des Plugins bleibt die eine Pruefung; sie antwortet UNVERAENDERT, '
    . 'also angenommen (Bauvorschrift Gruppe 40, 2.2).';

/* Ein Befehl der Bruecke -> Aktion des Plugins; Fahrzeug aus dem Teil {nr} (sonst 1). */
$rn_tun = function ($wie) {
    return function ($w, $t) use ($wie) {
        $nr = isset($t['nr']) ? (int) $t['nr'] : 1;
        rn_http_zeitschranke(array(10, 15));
        $aktion = $wie((string) $w);
        return rn_befehl_quittung(rn_befehl_annehmen($aktion, $nr, 'mqtt', rn_config_read(false)));
    };
};
$rn_fest = function ($a) { return function ($w) use ($a) { return $a; }; };
$rn_schalter = function ($an, $aus) { return function ($w) use ($an, $aus) { return $w === '1' ? $an : $aus; }; };

/* auffrischen: ein abgekoppelter Abruf nur dieses Fahrzeugs, wenn die Abrufsperre frei ist. */
$rn_auffrischen = function ($w, $t) {
    $nr = isset($t['nr']) ? (int) $t['nr'] : 1;
    $cfg = rn_config_read(false);
    $f = rn_fahrzeug($nr, $cfg);
    if (!$f) {
        return array(false, 'FAHRZEUG_FEHLT');
    }
    if ((string) $cfg['username'] === '' || (string) $cfg['password'] === '' || (string) $f['vin'] === '') {
        return array(false, 'NICHT_EINGERICHTET');
    }
    $r = rn_abruf_anstossen($nr);
    return $r === 'kein_start' ? array(false, 'ABRUF_START') : array(true, '');
};

/* Name => array(Art, Sperre s | null fuer die Vorgabe 60, Titel in Loxone, Kachel, Quittung, Aufruf) */
$rn_def = array(
    'auffrischen' => array('puls', null, 'Renault Werte abfragen (MQTT)', 'BEFEHL.K_AUFFRISCHEN', 'uebergeben', $rn_auffrischen),
    'laden'       => array('schalter', 0, 'Renault Laden (MQTT)', 'BEFEHL.K_LADEN', 'ausgefuehrt', $rn_tun($rn_schalter('chargenow', 'chargestop'))),
    'klima_an'    => array('puls', 0, 'Renault Vorklimatisierung ein (MQTT)', 'BEFEHL.K_KLIMA_AN', 'ausgefuehrt', $rn_tun($rn_fest('acnow'))),
    'klima_aus'   => array('puls', 0, 'Renault Vorklimatisierung aus (MQTT)', 'BEFEHL.K_KLIMA_AUS', 'ausgefuehrt', $rn_tun($rn_fest('acoff'))),
    'ladeplan'    => array('schalter', 0, 'Renault Ladeplan (MQTT)', 'BEFEHL.K_LADEPLAN', 'ausgefuehrt', $rn_tun($rn_schalter('cmon', 'cmoff'))),
);
$rn_befehle_mqtt = array();
foreach (array('' => false, '{nr}/' => true) as $rn_vor => $rn_n) {
    foreach ($rn_def as $rn_name => $rn_d) {
        $rn_e = array('art' => $rn_d[0], 'eingreifend' => false,
                      'titel' => $rn_n ? preg_replace('/^Renault /', 'Renault Fahrzeug N ', $rn_d[2]) : $rn_d[2],
                      'kachel' => $rn_d[3], 'quittung' => $rn_d[4], 'tun' => $rn_d[5]);
        if ($rn_d[1] !== null) {
            $rn_e['sperre_s'] = $rn_d[1];
            $rn_e['begruendung'] = $rn_grund_soll;
        }
        $rn_befehle_mqtt[$rn_vor . $rn_name] = $rn_e;
    }
}
/* MG E-E4 B: die Aussentemperatur der Hauswetterstation aus Loxone - nur einmal, gilt fuer alle Fahrzeuge
 * (Wettergrenze der Vorklimatisierung, 30 min frisch). */
$rn_befehle_mqtt['aussentemp'] = array('art' => 'zahl', 'eingreifend' => false, 'min' => -40, 'max' => 60,
    'sperre_s' => 0, 'begruendung' => 'Ein Messwert: jeder neue Wert zaehlt, auch ein gleicher (frischt das Alter auf).',
    'titel' => 'Renault Außentemperatur aus Loxone (MQTT)', 'kachel' => 'BEFEHL.K_AUSSENTEMP', 'quittung' => 'uebergeben',
    'tun' => function ($w, $t) { return rn_befehl_quittung(rn_aussentemp_merken($w)); });

return array(
    'fassung' => 1,
    'linie'   => 'renault_ng',
    'plugin'  => 'Renault',
    'geladen' => function_exists('rn_befehl_annehmen') && function_exists('rn_befehl_quittung')
                 && function_exists('rn_config_read') && function_exists('rn_aussentemp_merken')
                 && function_exists('rn_abruf_anstossen'),
    'einstellungen' => function () {
        $c = rn_config_read(false);   // wie der unangemeldete Endpunkt: nichts anlegen, nichts heilen
        return array('praefix'     => 'Renault',
                     'annehmen'    => (string) $c['befehle_mqtt_ein'] === 'Y',
                     'eingreifend' => false,
                     'mqtt_ein'    => rn_mqtt_zugang() !== null);
    },
    'protokoll' => function ($stufe, $text) {
        // 2.1.22: bb_log() setzt "Befehlsbruecke: " schon vor den Text - bis 2.1.21 stand es doppelt (Nachzug 01.11.2026, wie MGiSmart 1.1.30).
        rn_melden($stufe === 'FEHL' ? 'ERROR' : $stufe, $text);
    },
    'teile' => array('nr' => '[2-4]'),
    'befehle' => $rn_befehle_mqtt,
);
