#!/bin/bash
# Wird VOR einem Plugin-Update ausgefuehrt (als Benutzer loxberry).
#
# ===================================================================
# WARUM HIER FAST NICHTS MEHR STEHT
# ===================================================================
#
# Bis 1.4 sicherte dieses Skript Konfiguration, Sitzung, Ladehistorie und
# Protokoll nach /tmp/renault_api_upgrade, und postupgrade.sh holte sie
# von dort zurueck. Zwei Dinge waren daran falsch:
#
# 1. /tmp ist auf dem LoxBerry eine Ramdisk. Faellt zwischen den beiden
#    Schritten der Strom aus, oder startet das System neu, ist der Ordner
#    leer. Die Ladehistorie und das Protokoll waren dann fort - ohne
#    Sicherung, ohne Meldung. Nur die Konfiguration ueberlebte, weil es
#    daneben noch eine zweite Sicherung im Konfigordner gab.
#
# 2. Der eigentliche Grund fuer die ganze Uebung war ein anderer Fehler:
#    Die Nutzdaten lagen in webfrontend/htmlauth, also IM PROGRAMMORDNER.
#    Genau den loescht LoxBerry beim Update und legt ihn neu an.
#
# Seit 1.6.0 liegen die Daten dort, wo LoxBerry sie ohnehin stehen laesst:
#
#     config/plugins/<ordner>/   Konfiguration
#     data/plugins/<ordner>/     Sitzung und Ladehistorie
#     log/plugins/<ordner>/      Protokoll
#
# Damit braucht es weder Sicherung noch Wiederherstellung. Was hier bleibt,
# ist eine Sicherheitskopie der Konfiguration - fuer den Fall, dass jemand
# das Plugin deinstalliert und neu installiert, denn dabei raeumt LoxBerry
# auch den Konfigordner ab.

KONF="REPLACELBPCONFIGDIR"
ALT="REPLACELBPHTMLAUTHDIR"

mkdir -p "$KONF" 2>/dev/null

# Fall A: schon umgezogen (1.6.0 und neuer)
if [ -f "$KONF/config.php" ]; then
    cp -f "$KONF/config.php" "$KONF/config.php.backup"
    chmod 600 "$KONF/config.php.backup" 2>/dev/null
    echo "<OK> Konfiguration gesichert."
# Fall B: Update von 1.4 oder aelter - die Datei liegt noch beim Programm.
# Sie wird HIER schon in den Konfigordner geholt, denn gleich wird der
# Programmordner geloescht.
elif [ -f "$ALT/config.php" ]; then
    cp -f "$ALT/config.php" "$KONF/config.php"
    cp -f "$ALT/config.php" "$KONF/config.php.backup"
    chmod 600 "$KONF/config.php" "$KONF/config.php.backup" 2>/dev/null
    echo "<OK> Konfiguration aus dem Programmordner in den Konfigordner geholt."
else
    echo "<INFO> Keine Konfiguration gefunden - vermutlich eine Erstinstallation."
fi

# Ebenso Sitzung, Ladehistorie und Protokoll: Sie ueberstehen das Update nur,
# wenn sie den Programmordner JETZT verlassen. Das Umziehen selbst macht
# rn_umzug() beim ersten Aufruf; hier geht es nur darum, sie zu retten.
DATEN="REPLACELBPDATADIR"
PROT="REPLACELBPLOGDIR"
mkdir -p "$DATEN" "$PROT" 2>/dev/null
for paar in "session:$DATEN" "database.csv:$DATEN" "renault.log:$PROT" "renault.log.1:$PROT"; do
    f="${paar%%:*}"; ziel="${paar##*:}"
    if [ -f "$ALT/$f" ] && [ ! -f "$ziel/$f" ]; then
        cp -f "$ALT/$f" "$ziel/$f"
        echo "<OK> $f in den dauerhaften Ordner geholt."
    fi
done

exit 0
