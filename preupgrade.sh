#!/bin/bash
# Wird VOR einem Plugin-Update ausgefuehrt (als Benutzer loxberry).
#
# ===================================================================
# WAS DER INSTALLER BEIM UPDATE ABRAEUMT - gemessen, nicht vermutet
# ===================================================================
#
# Gemessen an sbin/plugininstall.pl, Zweig master (2054 Zeilen, 65 120
# Byte, geholt am 04.09.2026): die Unterroutine purge_installation hat ZWEI
# Aufrufstellen - eine bei der Deinstallation und eine im Upgrade-Zweig,
# unmittelbar NACH diesem Skript. Ihr Loeschblock steht unter
# "if ($pfolder)" OHNE Pruefung auf die Betriebsart und entfernt:
#
#     config/plugins/<ordner>/          <- weg, auch beim Update
#     data/plugins/<ordner>/            <- weg, auch beim Update
#     bin/plugins/<ordner>/
#     templates/plugins/<ordner>/
#     webfrontend/htmlauth/plugins/<ordner>/
#     webfrontend/html/plugins/<ordner>/
#
# Nur log/plugins/<ordner>/ haengt an der Betriebsart und bleibt beim
# Update stehen.
#
# Bis 2.1.5 stand hier das Gegenteil: "Seit 1.6.0 liegen die Daten dort, wo
# LoxBerry sie ohnehin stehen laesst … Damit braucht es weder Sicherung noch
# Wiederherstellung." Fuer log/ stimmt das, fuer config/ fing es die
# Zweitschrift ab - fuer data/ fing es nichts. Die Ladehistorie
# (database*.csv) war nach jedem Auto-Update fort, ohne Meldung, waehrend
# die Oberflaeche im Reiter Ladehistorie versprach, die Datei ueberlebe
# Updates und Neuinstallationen.
#
# Deshalb retten Konfiguration UND Aufzeichnung jetzt auf dieselbe Weise:
# NEBEN den Ordner, der geloescht wird. postupgrade.sh holt beides zurueck.
#
# Rueckgabewert: 0 in Ordnung, 1 Warnung (die Installation laeuft weiter).
# Auf jedes Kopieren folgt die Pruefung, ob es geklappt hat - ein <OK> fuer
# etwas, das nicht geschehen ist, ist schlechter als gar keine Meldung.

KONF="REPLACELBPCONFIGDIR"
DATEN="REPLACELBPDATADIR"
PROT="REPLACELBPLOGDIR"
ALT="REPLACELBPHTMLAUTHDIR"

rc=0

# Die beiden Rettungsorte liegen NEBEN dem jeweiligen Ordner. Der Installer
# loescht "<ordner>/" mit Schraegstrich; ein Nachbar gleichen Stammes bleibt
# davon unberuehrt.
SICH="${KONF}.backup.config.php"
RETTUNG="${DATEN}.rettung"

mkdir -p "$KONF" 2>/dev/null

# ===================================================================
# 1. KONFIGURATION
# ===================================================================
if [ -f "$KONF/config.php" ]; then
    # Fall A: schon umgezogen (1.6.0 und neuer)
    if cp -f "$KONF/config.php" "$SICH"; then
        chmod 600 "$SICH" 2>/dev/null || echo "<WARNING> chmod 600 auf $SICH fehlgeschlagen."
        echo "<OK> Konfiguration gesichert nach $SICH."
    else
        echo "<ERROR> Konfiguration konnte nicht nach $SICH gesichert werden."
        rc=1
    fi
elif [ -f "$ALT/config.php" ]; then
    # Fall B: Update von 1.4 oder aelter - die Datei liegt noch beim
    # Programm. Gerettet wird sie ueber $SICH; die Kopie in den Konfigordner
    # ist nur fuer den Fall, dass postupgrade.sh nicht laeuft - der Ordner
    # selbst wird gleich ebenfalls geloescht.
    cp -f "$ALT/config.php" "$KONF/config.php" 2>/dev/null
    if cp -f "$ALT/config.php" "$SICH"; then
        chmod 600 "$KONF/config.php" "$SICH" 2>/dev/null
        echo "<OK> Konfiguration aus dem Programmordner gesichert nach $SICH."
    else
        echo "<ERROR> Konfiguration aus dem Programmordner konnte nicht gesichert werden."
        rc=1
    fi
elif [ -f "$KONF/config.php.backup" ]; then
    # Fall C: nur die alte Zweitschrift ist noch da (Update von 2.0.x).
    if cp -f "$KONF/config.php.backup" "$SICH"; then
        chmod 600 "$SICH" 2>/dev/null
        echo "<OK> Alte Zweitschrift aus dem Konfigordner herausgeholt."
    else
        echo "<ERROR> Alte Zweitschrift konnte nicht herausgeholt werden."
        rc=1
    fi
else
    echo "<INFO> Keine Konfiguration gefunden - vermutlich eine Erstinstallation."
fi

# ===================================================================
# 2. AUFZEICHNUNG (database*.csv)
# ===================================================================
# Das ist die einzige Datei des Plugins, die sich nicht neu beschaffen
# laesst: der Zwischenspeicher wird beim naechsten Abruf neu geschrieben,
# die Anmeldung neu geholt, das Protokoll ist ohnehin fluechtig. Die
# Ladehistorie waechst ueber Monate und ist danach fort.
if ls "$DATEN"/database*.csv >/dev/null 2>&1; then
    if mkdir -p "$RETTUNG" 2>/dev/null; then
        gerettet=0
        for f in "$DATEN"/database*.csv; do
            [ -f "$f" ] || continue
            if cp -f "$f" "$RETTUNG/$(basename "$f")"; then
                gerettet=$((gerettet + 1))
            else
                echo "<ERROR> $(basename "$f") konnte nicht gesichert werden."
                rc=1
            fi
        done
        echo "<OK> $gerettet Datei(en) der Ladehistorie gesichert nach $RETTUNG."
    else
        echo "<ERROR> Rettungsordner $RETTUNG liess sich nicht anlegen - die"
        echo "<ERROR> Ladehistorie geht bei diesem Update verloren."
        rc=1
    fi
else
    echo "<INFO> Keine Ladehistorie vorhanden - nichts zu sichern."
fi

# ===================================================================
# 3. UPDATE VON 1.4 ODER AELTER
# ===================================================================
# Damals lagen Sitzung, Ladehistorie und Protokoll im Programmordner, den
# der Installer gleich ebenfalls loescht. Das Protokoll darf direkt nach
# log/ (das bleibt stehen), die Ladehistorie geht in den Rettungsordner -
# NICHT nach data/, das gleich mit abgeraeumt wird. Bis 2.1.5 ging sie
# genau dorthin, und das Installationsprotokoll meldete dazu <OK>.
mkdir -p "$PROT" 2>/dev/null
for paar in "renault.log:$PROT" "renault.log.1:$PROT"; do
    f="${paar%%:*}"; ziel="${paar##*:}"
    if [ -f "$ALT/$f" ] && [ ! -f "$ziel/$f" ]; then
        if cp -f "$ALT/$f" "$ziel/$f"; then
            echo "<OK> $f in den dauerhaften Ordner geholt."
        else
            echo "<WARNING> $f liess sich nicht in den dauerhaften Ordner holen."
            rc=1
        fi
    fi
done
for f in "session" "database.csv"; do
    if [ -f "$ALT/$f" ]; then
        if mkdir -p "$RETTUNG" 2>/dev/null && cp -f "$ALT/$f" "$RETTUNG/$f"; then
            echo "<OK> $f aus dem Programmordner nach $RETTUNG gesichert."
        else
            echo "<ERROR> $f aus dem Programmordner liess sich nicht sichern."
            rc=1
        fi
    fi
done

exit $rc
