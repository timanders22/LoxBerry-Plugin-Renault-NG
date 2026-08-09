<?php

require_once "loxberry_web.php";

// This will read your language files to the array $L
$L = LBSystem::readlanguage("language.ini");
$template_title = "Renault NG";
$helplink = "http://www.loxwiki.eu:80/x/2wzL";
$helptemplate = "help.html";


// Activate the second element

 
$navbar[1]['Name'] = 'Zur&uuml;ck zur Oberfl&auml;che';
$navbar[1]['URL']  = 'index.php';
$navbar[1]['active'] = True;
// Kein LoxBerry-Seitenrahmen mehr: diese Datei laeuft ueber den Cron
// (cron.10min) und gibt seit dieser Fassung Klartext aus. lbheader()
// und lbfooter() haetten HTML um den Klartext gelegt.





session_cache_limiter('nocache');

// rn_lib.php zuerst - rn_paths() sagt, wo die Konfiguration liegt.
require_once __DIR__ . '/rn_lib.php';
rn_umzug();

require 'api-keys.php';
require rn_paths()['config'];

//MQTT Start-----------------------------------------------
require_once "loxberry_io.php";
require_once "phpMQTT/phpMQTT.php";
// Get the MQTT Gateway connection details from LoxBerry
$creds = mqtt_connectiondetails();
// MQTT requires a unique client id
$client_id = uniqid(gethostname()."_client");
//MQTT ENDE-----------------------------------------------

// Kein zweites Sprachsystem mehr - siehe die ausfuehrliche Begruendung in
// abruf.php. Die HTML-Ausgabe dieser Datei ist ebenfalls entfallen; sie
// stammte aus der eigenstaendigen ZoePHP-Anwendung und war nur bei einem
// unmittelbaren Browseraufruf zu sehen. Aufgerufen wird history.php vom
// Cron (cron.10min), und dann geht die Ausgabe ohnehin nach /dev/null.
require_once __DIR__ . '/rn_lib.php';
header('Content-Type: text/plain; charset=utf-8');
// ZoePHP 20260520: one Europe-wide Gigya key ($gigya_api from api-keys.php),
// country-specific key selection removed.

$date_today = date_create('now');
$date_today = date_format($date_today, 'md');
$update_ok = FALSE;

//Request cached login
$session = file_get_contents(rn_paths()['session']);
$session = explode('|', $session);

//Retrieve new Gigya token if the session file is outdated
if ($session[0] !== $date_today) {
  //Login Gigya
  $update_ok = TRUE;
  $postData = array(
    'ApiKey' => $gigya_api,
    'loginId' => $username,
    'password' => $password,
    'include' => 'data',
    'sessionExpiration' => 60
  );
  $ch = curl_init('https://accounts.eu1.gigya.com/accounts.login');
  curl_setopt($ch, CURLOPT_POST, TRUE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
  $response = curl_exec($ch);
  if ($response === FALSE) die(curl_error($ch));  
  $responseData = json_decode($response, TRUE);
  $oauth_token = $responseData['sessionInfo']['cookieValue'];

  //Request Gigya JWT token
  $postData = array(
    'login_token' => $oauth_token,
    'ApiKey' => $gigya_api,
    'fields' => 'data.personId,data.gigyaDataCenter',
    'expiration' => 87000
  );
  $ch = curl_init('https://accounts.eu1.gigya.com/accounts.getJWT');
  curl_setopt($ch, CURLOPT_POST, TRUE);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
  $response = curl_exec($ch);
  if ($response === FALSE) die(curl_error($ch));
  $responseData = json_decode($response, TRUE);
  $session[1] = $responseData['id_token'];
  $session[0] = $date_today;
}

//Request charging history
$postData = array(
    'apikey: '.$kamereon_api,
    'x-gigya-id_token: '.$session[1]
);
$ch = curl_init('https://api-wired-prod-1-euw1.wrd-aws.com/commerce/v1/accounts/'.$session[2].'/kamereon/kca/car-adapter/v1/cars/'.$vin.'/charges?country='.$country.'&start='.date("Ymd", strtotime("-1 months")).'&end='.date("Ymd"));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_HTTPHEADER, $postData);

$response = curl_exec($ch);

if ($response === FALSE) die(curl_error($ch));
$responseData = json_decode($response, TRUE);
$data = array();
if (isset($responseData['data']['attributes']['charges'])) $data = $responseData['data']['attributes']['charges'];
// ZoePHP 20260520: sort charges by start date (newest first) instead of array_reverse
usort($data, function($a, $b) {
    return strcmp($b['chargeStartDate'], $a['chargeStartDate']);
});


// Be careful about the required namespace on inctancing new objects:
$mqtt = new Bluerhinos\phpMQTT($creds['brokerhost'],  $creds['brokerport'], $client_id);
    if( $mqtt->connect(true, NULL, $creds['brokeruser'], $creds['brokerpass'] ) ) {

// ZoePHP 20260520: obsolete requests to the retired Renault app-config AWS bucket removed.

//Output
// Kopf der ZoePHP-Seite entfallen - Ausgabe jetzt als Klartext.
echo $zoename."\n".str_repeat('-', 40)."\n";
for ($i = 0; $i < count($data); $i++) {
  if (!empty($data[$i]['chargeStartDate']) && !empty($data[$i]['chargeEndDate']) && !empty($data[$i]['chargeEnergyRecovered'])) {
    $s = date_create_from_format(DATE_ISO8601, $data[$i]['chargeStartDate'], timezone_open('UTC'));
    $s = date_timezone_set($s, timezone_open('Europe/Berlin'));
    $sts = $s;
    $sd = date_format($s, 'd.m.Y');
    $st = date_format($s, 'H:i');
    $s = date_create_from_format(DATE_ISO8601, $data[$i]['chargeEndDate'], timezone_open('UTC'));
    $s = date_timezone_set($s, timezone_open('Europe/Berlin'));
    $ets = $s;
    $ed = date_format($s, 'd.m.Y');
    $et = date_format($s, 'H:i');
    $cd = date_diff($sts, $ets);
    $cdm = date_interval_format($cd, '%a') * 24 * 60;
    $cdm += date_interval_format($cd, '%h') * 60;
    $cdm += date_interval_format($cd, '%i');
    echo rn_t('MELDUNG.START').': '.$sd.' '.$st."\n";
    if ($zoeph == 1) {
      echo rn_t('MELDUNG.LADEVORGANG').': '.$data[$i]['chargeStartBatteryLevel'].' % '
         . rn_t('MELDUNG.BIS').' '.$data[$i]['chargeEndBatteryLevel'].' % '
         . rn_t('MELDUNG.IN').' '.$cdm.' '.rn_t('MELDUNG.MINUTEN')."\n";
      $s = $data[$i]['chargeStartInstantaneousPower']/1000;
      echo rn_t('MELDUNG.LEISTUNG').': '.$data[$i]['chargePower'].' ('.$s.' kW)'."\n";
	
	$mqtt->publish("Renault/$zoename/chargeStartBatteryLevel(Prozent)", @$data[0]['chargeStartBatteryLevel'], 0, 1);
	$mqtt->publish("Renault/$zoename/chargeEndBatteryLevel(Prozent)", @$data[0]['chargeEndBatteryLevel'], 0, 1);
	$mqtt->publish("Renault/$zoename/chargeDuration(min)", $cdm, 0, 1);
	$mqtt->publish("Renault/$zoename/chargePowerAverage(kW)", round(@$data[0]['chargeEnergyRecovered'] * 60 / ($cdm+0.0000001), 2), 0, 1);

    } else {
      echo rn_t('MELDUNG.LADEVORGANG').': '.round($data[$i]['chargeEnergyRecovered'], 2).' kWh '
         . rn_t('MELDUNG.IN').' '.$cdm.' '.rn_t('MELDUNG.MINUTEN')."\n";

	$mqtt->publish("Renault/$zoename/chargePowerAverage(kW)", round(@$data[0]['chargeEnergyRecovered'] * 60 / ($cdm+0.0000001), 2), 0, 1);
	$mqtt->publish("Renault/$zoename/chargeEnergyRecovered(kWh)", @$data[0]['chargeEnergyRecovered'], 0, 1);
	$mqtt->publish("Renault/$zoename/chargeDuration(min)", $cdm, 0, 1);
    }
    echo rn_t('MELDUNG.STATUS').': '.$data[$i]['chargeEndStatus'].' '
       . rn_t('MELDUNG.UM').' '.$ed.' '.$et."\n".str_repeat('-', 40)."\n";
	$mqtt->publish("Renault/$zoename/chargeEndStatus", @$data[0]['chargeEndStatus'], 0, 1);
  }
}
curl_close($ch);

        //$mqtt->publish("Renault/$zoename/BattSOC", $session[12], 0, 1);
	$mqtt->publish("Renault/$zoename/chargeStartInstantaneousPower", @$data[0]['chargeStartInstantaneousPower'], 0, 1);
	//$mqtt->publish("Renault/$zoename/chargeStartDate", $data[0]['chargeStartDate'], 0, 1);
	//$mqtt->publish("Renault/$zoename/chargeStartTime", $st, 0, 1);
	//$mqtt->publish("Renault/$zoename/chargeEndDate", $data[0]['chargeEndDate'], 0, 1);
	//$mqtt->publish("Renault/$zoename/chargeEndTime", $et, 0, 1);
	
	

 //print_r($data);





        $mqtt->close();
    } else {
        echo "MQTT connection failed";
    }


//Cache new Gigya token
if ($update_ok === TRUE) {
  $session = implode('|', $session);
  file_put_contents(rn_paths()['session'], $session);
}
?>
