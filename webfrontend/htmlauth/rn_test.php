<?php
/**
 * Renault - die Aktionen des Reiters Test
 *
 * Jede Funktion antwortet ohne Loxone auf die Frage, ob die Einrichtung
 * traegt. Es wird nur gelesen; nichts wird an Renault gesendet.
 */

require_once __DIR__ . '/rn_lib.php';

function rn_block($text)
{
    return '<div class="sm-log">' . rn_e($text) . '</div>';
}

/** Ein Geheimnis so zeigen, dass man es wiedererkennt, aber nicht liest. */
function rn_maske($s, $behalten = 3)
{
    $s = (string) $s;
    if ($s === '') {
        return 'LEER / NICHT GESETZT';
    }
    if (strlen($s) <= $behalten) {
        return str_repeat('*', strlen($s));
    }
    return substr($s, 0, $behalten) . str_repeat('*', min(strlen($s) - $behalten, 12));
}

function rn_test_ausfuehren($was, $cfg)
{
    $p = rn_paths();

    switch ($was) {

        case 'umgebung':
            $z = array();
            $z[] = 'PHP-Fassung       : ' . PHP_VERSION;
            foreach (array('curl', 'json', 'openssl') as $modul) {
                $z[] = sprintf('%-18s: %s', 'Erweiterung ' . $modul,
                    extension_loaded($modul) ? 'vorhanden' : 'FEHLT');
            }
            $z[] = '';
            foreach (array('config' => 'config.php', 'session' => 'session',
                           'log' => 'renault.log', 'csv' => 'database.csv') as $k => $name) {
                $d = $p[$k];
                $z[] = sprintf('%-18s: %s', $name,
                    is_readable($d)
                        ? 'vorhanden (' . number_format(filesize($d) / 1024, 1, ',', '.') . ' kB)'
                        : 'nicht vorhanden');
            }
            $z[] = '';
            /* Bis 1.4 stand hier die Schreibbarkeit von $p['eigen'], also des
               PROGRAMMordners - dort lagen die Nutzdaten. Seit 1.6.0 schreibt
               das Plugin dort nicht mehr; geprueft gehoeren die drei Ordner,
               in denen es das jetzt tut. */
            foreach (array('konfdir' => 'Konfigurationsordner',
                           'datadir' => 'Datenordner',
                           'logdir'  => 'Protokollordner') as $k => $name) {
                $z[] = sprintf('%-18s: %s  (%s)', $name,
                    is_writable($p[$k]) ? 'beschreibbar' : 'NICHT beschreibbar',
                    $p[$k]);
            }
            $broker = rn_mqtt_broker();
            $z[] = 'MQTT-Broker       : ' . ($broker
                ? $broker['host'] . ':' . $broker['port']
                : 'nicht in general.json gefunden - MQTT-Gateway eingerichtet?');
            return array('Umgebung', rn_block(implode("\n", $z)));

        case 'konfig':
            $z = array();
            $z[] = sprintf('%-22s: %s', 'Name des Fahrzeugs', $cfg['zoename']);
            $z[] = sprintf('%-22s: %s', 'Benutzer', $cfg['username'] !== ''
                ? $cfg['username'] : 'LEER / NICHT GESETZT');
            $z[] = sprintf('%-22s: %s', 'Passwort', rn_maske($cfg['password'], 2));
            $z[] = sprintf('%-22s: %s', 'Fahrgestellnummer', $cfg['vin'] !== ''
                ? rn_maske($cfg['vin'], 6) : 'LEER / NICHT GESETZT');
            $z[] = sprintf('%-22s: %s', 'Land', $cfg['country']);
            $z[] = sprintf('%-22s: %s', 'Generation', 'Phase ' . $cfg['zoeph']);
            $z[] = '';
            $z[] = 'Themenpfad            : Renault/' . $cfg['zoename'] . '/...';
            $z[] = 'Einzutragendes Abo    : Renault/' . $cfg['zoename'] . '/#';
            $hinweis = '';
            if ($cfg['username'] === '' || $cfg['password'] === '' || $cfg['vin'] === '') {
                $hinweis = '<p class="sm-small">Solange Benutzer, Passwort oder '
                         . 'Fahrgestellnummer fehlen, kann das Plugin nichts abrufen.</p>';
            }
            return array('Gespeicherte Konfiguration',
                rn_block(implode("\n", $z)) . $hinweis);

        case 'zwischen':
            $s = rn_session();
            if (!$s) {
                return array('Zwischengespeicherte Daten',
                    '<p class="sm-small">Es gibt noch keine <span class="sm-mono">session</span>-Datei. '
                    . 'Entweder wurde noch nie abgerufen, oder die Einstellungen '
                    . 'wurden gerade gespeichert &ndash; dann wird sie bewusst geleert.</p>');
            }
            $z = array();
            foreach (rn_session_felder() as $i => $label) {
                $z[] = sprintf('%-32s: %s', $label,
                    isset($s[$i]) && $s[$i] !== '' ? $s[$i] : '-');
            }
            $z[] = '';
            $z[] = 'Felder insgesamt                : ' . count($s);
            return array('Zwischengespeicherte Daten', rn_block(implode("\n", $z)));
    }

    return array('Unbekannte Pr&uuml;fung',
        '<p class="sm-small">Diese Pr&uuml;fung gibt es nicht.</p>');
}
