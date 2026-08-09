#!/bin/bash
# Wird NACH einem Plugin-Update ausgefuehrt (als Benutzer loxberry).
#
# Bis 1.4 holte dieses Skript die Dateien aus /tmp/renault_api_upgrade
# zurueck. Das entfaellt: Seit 1.6.0 liegen sie in config/, data/ und log/,
# und die raeumt LoxBerry beim Update nicht ab. Siehe preupgrade.sh.
#
# Uebrig bleibt der Fall, dass gar keine Konfiguration da ist - etwa nach
# Deinstallation und Neuinstallation. Dann greift die Sicherheitskopie.

KONF="REPLACELBPCONFIGDIR"

mkdir -p "$KONF" 2>/dev/null

if [ ! -s "$KONF/config.php" ] && [ -f "$KONF/config.php.backup" ]; then
    cp -f "$KONF/config.php.backup" "$KONF/config.php"
    chmod 600 "$KONF/config.php" 2>/dev/null
    echo "<OK> Konfiguration aus der Sicherheitskopie wiederhergestellt."
fi

# Zugangsdaten gehen niemanden ausser loxberry etwas an.
[ -f "$KONF/config.php" ] && chmod 600 "$KONF/config.php" 2>/dev/null

# Falls von 1.4 aktualisiert wurde und preupgrade.sh nicht lief (etwa bei
# einer Installation ueber das Archiv statt ueber das Auto-Update), holt
# rn_umzug() beim ersten Seitenaufruf nach, was noch im Programmordner liegt.
echo "<INFO> Nutzdaten liegen ab dieser Fassung unter config/, data/ und log/."
echo "<INFO> Die Sicherung ueber /tmp entfaellt - sie war eine Ramdisk und"
echo "<INFO> haette einen Neustart mitten im Update nicht ueberlebt."

exit 0
