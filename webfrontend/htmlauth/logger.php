<?php
/**
 * Renault - Protokoll
 *
 * Das Protokoll liegt seit 1.6.0 unter log/plugins/<ordner>/ und nicht mehr
 * neben dem Programm - dort loescht LoxBerry es bei jedem Update.
 *
 * ===================================================================
 * ZWEI AENDERUNGEN MIT 2.1.0
 * ===================================================================
 *
 * 1. KAPPUNG NACH HAUSSTANDARD: ab 500 kB bleiben die letzten 200 Zeilen
 *    stehen. Bis 2.0.6 wurde bei 1 MB umgedreht und eine Sicherung
 *    aufgehoben - also bis zu 2 MB. log/plugins liegt auf einer Ramdisk
 *    (sbin/createtmpfsfoldersinit.sh haengt es als tmpfs ein): eine
 *    unbegrenzt wachsende Datei frisst dort Arbeitsspeicher, nicht
 *    Plattenplatz.
 *
 * 2. WIEDERHOLUNGSBREMSE. Dieselbe Meldung wird hoechstens einmal je
 *    Stunde geschrieben; die uebrigen werden gezaehlt und beim naechsten
 *    Wechsel als eine Zeile zusammengefasst. Grund: bis 2.0.6 lief der
 *    Abruf auch ohne Zugangsdaten weiter und schrieb alle drei Minuten
 *    dieselbe Fehlerzeile - rund 480 gleichlautende Zeilen je Tag, in
 *    denen jede andere Meldung unterging.
 */

require_once __DIR__ . '/rn_lib.php';

define('RENAULT_LOGFILE', rn_paths()['log']);
define('RENAULT_LOG_MAX', 512000);   // 500 kB
define('RENAULT_LOG_REST', 200);     // so viele Zeilen bleiben stehen
define('RENAULT_LOG_WDH', 3600);     // Wiederholungsbremse in Sekunden

/** Kappen, wenn die Datei zu gross geworden ist. */
function renault_log_kappen()
{
    $f = RENAULT_LOGFILE;
    if (!is_file($f) || filesize($f) <= RENAULT_LOG_MAX) {
        return;
    }
    $zeilen = @file($f, FILE_IGNORE_NEW_LINES);
    if (!is_array($zeilen)) { return; }
    $rest = array_slice($zeilen, -RENAULT_LOG_REST);
    @file_put_contents($f, implode("\n", $rest) . "\n", LOCK_EX);
}

/** Eine Zeile ohne jede Bremse schreiben. */
function renault_log_roh($level, $msg)
{
    renault_log_kappen();
    $zeile = date('d.m.Y H:i:s') . ' [' . $level . '] ' . $msg . "\n";
    @file_put_contents(RENAULT_LOGFILE, $zeile, FILE_APPEND | LOCK_EX);
}

/**
 * Eine Zeile schreiben - hoechstens eine gleichlautende je Stunde.
 *
 * Der Zustand steht in einer Nebendatei: Pruefsumme der letzten Meldung,
 * Zeitpunkt, Zaehler der unterdrueckten Wiederholungen.
 */
function renault_log($level, $msg)
{
    $merker = RENAULT_LOGFILE . '.wdh';
    $jetzt  = time();
    $summe  = md5($level . '|' . $msg);

    /* Ist das Protokoll fort - geleert, gekappt oder nach einem Neustart der
     * Ramdisk verschwunden -, faengt die Bremse von vorn an. Sonst
     * unterdrueckt sie ausgerechnet die erste Zeile in einer leeren Datei,
     * und der Benutzer sieht nach dem Leeren gar nichts. Gemessen, weil ein
     * Pruefdurchlauf genau das gezeigt hat. */
    if (!is_file(RENAULT_LOGFILE)) {
        @unlink($merker);
    }

    $alt = array('', 0, 0);
    if (is_readable($merker)) {
        $roh = (string) @file_get_contents($merker);
        if ($roh !== '') { $alt = array_pad(explode('|', $roh), 3, 0); }
    }
    $gleiche  = ((string) $alt[0] === $summe);
    $innerhalb = (($jetzt - (int) $alt[1]) < RENAULT_LOG_WDH);

    if ($gleiche && $innerhalb) {
        // Unterdruecken und mitzaehlen.
        @file_put_contents($merker, $summe . '|' . (int) $alt[1] . '|' . ((int) $alt[2] + 1), LOCK_EX);
        return;
    }
    if ((int) $alt[2] > 0) {
        renault_log_roh('INFO', 'Die vorige Meldung wurde ' . (int) $alt[2]
            . ' weitere Male ausgeloest und hier nicht wiederholt.');
    }
    renault_log_roh($level, $msg);
    @file_put_contents($merker, $summe . '|' . $jetzt . '|0', LOCK_EX);
}

/**
 * Eine Schnittstellenantwort protokollieren: Statuscode und, nur im
 * Fehlerfall, ein gekuerzter Rumpf.
 *
 * Bei HTTP < 400 wird bewusst NUR der Statuscode geschrieben - eine
 * erfolgreiche Gigya-Antwort enthaelt den Sitzungswert, und der gehoert
 * nicht in eine Datei, die die Oberflaeche anzeigt.
 */
function renault_log_api($name, $ch, $response)
{
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === FALSE) {
        renault_log('ERROR', $name . ': cURL-Fehler: ' . curl_error($ch)
            . ' (nach ' . round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 1) . ' s)');
        return;
    }
    if ($http >= 400) {
        $kurz = substr(preg_replace('/\s+/', ' ', (string) $response), 0, 400);
        renault_log('ERROR', $name . ': HTTP ' . $http . ' - ' . $kurz);
    } else {
        renault_log('INFO', $name . ': HTTP ' . $http);
    }
}
