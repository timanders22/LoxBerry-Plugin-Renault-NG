<?php
/**
 * Die beiden festen Schluessel der Renault-Schnittstelle.
 *
 * WAS HIER STEHT - UND WAS NICHT
 *
 * Es sind die Schluessel der Renault-APP, nicht Zugangsdaten des Betreibers.
 * Sie identifizieren die anfragende Anwendung gegenueber Gigya (Anmeldung) und
 * Kamereon (Fahrzeugdaten). Sie sind oeffentlich bekannt: jedes freie
 * Renault-Projekt fuehrt dieselben Zeichenketten, sie stehen in
 * Fehlerberichten, Wikis und Paketverzeichnissen. Wer sie kennt, kann damit
 * NICHTS - ohne Benutzername und Passwort eines Renault-Kontos antwortet die
 * Schnittstelle nicht.
 *
 * Persoenlich ist einzig, was in config.php steht: Benutzername, Passwort,
 * Fahrgestellnummer. Das steht NICHT in dieser Datei und gehoert auch nicht
 * hierher.
 *
 * DESHALB: diese Datei nicht "aufraeumen". Ein Freigabewerkzeug, das nach
 * "API key" sucht, meldet sie - zu Recht, denn es kann den Unterschied nicht
 * kennen. Ohne die Schluessel gibt es aber keine Anmeldung, und das Plugin
 * ist unbrauchbar. Die Regel bleibt: die Schluessel sind EINGEBAUT und stehen
 * hier, nicht in der Konfiguration - sonst muesste jeder Anwender zwei
 * Zeichenketten von Hand eintragen, die fuer alle gleich sind.
 *
 * Wenn Renault sie wechselt, wird HIER geaendert, mit einer neuen Fassung.
 *
 * Seit 2.1.35 gibt es dazu einen NOTBEHELF fuer die Zeit, bis diese Fassung
 * erscheint: im Reiter Einstellungen ("Fortgeschritten") laesst sich je ein
 * eigener Gigya- und Kamereon-Schluessel eintragen. Er steht dann in
 * config.php (Rechte 0600) und gilt statt des Schluessels von hier; ab Werk
 * sind beide Felder leer, und es gilt dieser. Gelesen werden die Schluessel
 * an einer Stelle, rn_api_schluessel() in rn_lib.php; der Reiter Test sagt,
 * welcher gerade gilt (nie den Wert).
 */

// Gigya API key (Europe-wide)
// Source: ZoePHP release 20260520 - Renault uses one key for all EU countries now,
// the old country-specific keys have been deactivated.
$gigya_api = '3_VgdkgtIRH3AdHvJm-cjV2ug2EFE0lxt0IJzMC4MFqZjFpn_GYFXVdNZ19L7wZX0N';

// Backwards compatibility for scripts that still select a key by country:
$DE = $gigya_api;
$AT = $gigya_api;
$SE = $gigya_api;
$GB = $gigya_api;
$IT = $gigya_api;

//Kamereon API key
$kamereon_api = 'YjkKtHmGfaceeuExUDKGxrLZGGvtVS0J';
?>
