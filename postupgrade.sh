#!/bin/bash
# Wird NACH einem Plugin-Update ausgefuehrt (als Benutzer loxberry).
#
# Es holt zurueck, was preupgrade.sh NEBEN die geloeschten Ordner gerettet
# hat: die Konfiguration aus der Zweitschrift und die Ladehistorie aus dem
# Rettungsordner.
#
# Warum ueberhaupt: der Installer raeumt beim Update sowohl
# config/plugins/<ordner>/ als auch data/plugins/<ordner>/ ab (gemessen an
# plugininstall.pl, siehe preupgrade.sh). Bis 2.1.5 behauptete der
# Kommentar an dieser Stelle das Gegenteil - er sagte, LoxBerry lasse alle
# drei Ordner stehen. Fuer log/ trifft das zu, fuer die beiden anderen
# nicht. (Der alte Satz steht hier nicht im Wortlaut: sonst faende ihn
# jede Suche, die pruefen soll, ob er fort ist.)
#
# Reihenfolge im Installer, gemessen: preupgrade -> purge_installation ->
# Konfigordner neu anlegen und Archiv kopieren -> postinstall -> postupgrade
# -> postroot. Nach postupgrade fasst nichts mehr config/plugins/<ordner>
# an; die Ruecksicherung wirkt an dieser Stelle also.
#
# Rueckgabewert: 0 in Ordnung, 1 Warnung.

KONF="REPLACELBPCONFIGDIR"
DATEN="REPLACELBPDATADIR"
RETTUNG="${DATEN}.rettung"

rc=0

mkdir -p "$KONF" 2>/dev/null

# ===================================================================
# 1. KONFIGURATION
# ===================================================================
# Die Zweitschrift liegt seit 2.1.0 NEBEN dem Konfigordner. Der alte Ort
# wird noch beruecksichtigt, damit ein Update von 2.0.x nichts verliert.
for SICH in "${KONF}.backup.config.php" "$KONF/config.php.backup"; do
    if [ ! -s "$KONF/config.php" ] && [ -f "$SICH" ]; then
        if cp -f "$SICH" "$KONF/config.php"; then
            chmod 600 "$KONF/config.php" 2>/dev/null \
                || echo "<WARNING> chmod 600 auf die Konfiguration fehlgeschlagen."
            echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt ($SICH)."
        else
            echo "<ERROR> Konfiguration liess sich nicht aus $SICH wiederherstellen."
            rc=1
        fi
    fi
done

# Zugangsdaten gehen niemanden ausser loxberry etwas an.
if [ -f "$KONF/config.php" ]; then
    chmod 600 "$KONF/config.php" 2>/dev/null \
        || { echo "<WARNING> chmod 600 auf die Konfiguration fehlgeschlagen."; rc=1; }
fi

# ===================================================================
# 2. AUFZEICHNUNG
# ===================================================================
# Zurueckgeholt wird nur, was am Zielort noch nicht steht - eine bereits
# vorhandene Datei wird nie ueberschrieben. Der Rettungsordner bleibt
# danach liegen: er kostet nichts, und beim naechsten Update wird er neu
# beschrieben. Wer ihn loeschen will, findet ihn unter dem hier genannten
# Pfad.
if [ -d "$RETTUNG" ]; then
    mkdir -p "$DATEN" 2>/dev/null
    zurueck=0
    schon=0
    for f in "$RETTUNG"/database*.csv; do
        [ -f "$f" ] || continue
        ziel="$DATEN/$(basename "$f")"
        if [ -f "$ziel" ]; then
            schon=$((schon + 1))
            continue
        fi
        if cp -f "$f" "$ziel"; then
            zurueck=$((zurueck + 1))
        else
            echo "<ERROR> $(basename "$f") liess sich nicht zurueckholen."
            rc=1
        fi
    done
    for f in "$RETTUNG/session"; do
        [ -f "$f" ] || continue
        [ -f "$DATEN/session" ] && continue
        cp -f "$f" "$DATEN/session" 2>/dev/null && zurueck=$((zurueck + 1))
    done
    if [ "$zurueck" -gt 0 ] || [ "$schon" -gt 0 ]; then
        echo "<OK> Ladehistorie: $zurueck Datei(en) zurueckgeholt, $schon lagen schon da."
        echo "<INFO> Der Rettungsordner bleibt liegen: $RETTUNG"
    else
        echo "<INFO> Im Rettungsordner lag nichts zum Zurueckholen."
    fi
else
    echo "<INFO> Kein Rettungsordner vorhanden - vermutlich eine Erstinstallation."
fi

echo "<INFO> Konfiguration und Ladehistorie werden NEBEN ihren Ordnern gesichert,"
echo "<INFO> weil der Installer config/plugins/<ordner>/ und data/plugins/<ordner>/"
echo "<INFO> bei jedem Update abraeumt. Das Protokoll unter log/plugins/ bleibt"
echo "<INFO> stehen, ist aber eine Ramdisk und uebersteht keinen Neustart."

exit $rc
