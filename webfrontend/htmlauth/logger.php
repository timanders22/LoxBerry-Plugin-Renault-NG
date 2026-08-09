<?php
// Simple logger for the Renault plugin (v1.2)
// Writes to renault.log in the plugin directory, auto-rotates at 1 MB.

// Das Protokoll liegt seit 1.6.0 unter log/plugins/<ordner>/ und nicht mehr
// neben dem Programm - dort loescht LoxBerry es bei jedem Update.
require_once __DIR__ . '/rn_lib.php';
define('RENAULT_LOGFILE', rn_paths()['log']);

function renault_log($level, $msg) {
  // Rotate: keep one backup when the log exceeds 1 MB
  if (file_exists(RENAULT_LOGFILE) && filesize(RENAULT_LOGFILE) > 1048576) {
    @rename(RENAULT_LOGFILE, RENAULT_LOGFILE.'.1');
  }
  $line = date('d.m.Y H:i:s').' ['.$level.'] '.$msg."\n";
  @file_put_contents(RENAULT_LOGFILE, $line, FILE_APPEND | LOCK_EX);
}

// Log an API response: status code + shortened body, mask nothing sensitive
function renault_log_api($name, $ch, $response) {
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($response === FALSE) {
    renault_log('ERROR', $name.': cURL-Fehler: '.curl_error($ch));
    return;
  }
  $short = substr(preg_replace('/\s+/', ' ', $response), 0, 400);
  if ($http >= 400) renault_log('ERROR', $name.': HTTP '.$http.' - '.$short);
  else renault_log('INFO', $name.': HTTP '.$http);
}
?>
