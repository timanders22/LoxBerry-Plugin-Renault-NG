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
 * ist unbrauchbar. Sie in eine Konfiguration zu verschieben verlagert das
 * Problem nur: dann muesste jeder Anwender zwei Zeichenketten von Hand
 * eintragen, die fuer alle gleich sind.
 *
 * Wenn Renault sie wechselt, wird HIER geaendert - und nur hier.
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
