#!/bin/bash

# Bashscript which is executed by bash *AFTER* complete installation is done
# (but *BEFORE* postupdate). Use with caution and remember, that all systems may
# be different!
#
# Exit code must be 0 if executed successfull. 
# Exit code 1 gives a warning but continues installation.
# Exit code 2 cancels installation.
#
# Will be executed as user "loxberry".
#
# You can use all vars from /etc/environment in this script.
#
# We add 5 additional arguments when executing this script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

# To use important variables from command line use the following code:
COMMAND=$0    # Zero argument is shell command
PSHNAME=$2    # Second argument is Plugin-Name for scipts etc.
PDIR=$3       # Third argument is Plugin installation folder
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
              # Base folder of LoxBerry

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

echo "<INFO> Command is: $COMMAND"
echo "<INFO> (Short) Name is: $PSHNAME"
echo "<INFO> Installation folder is: $PDIR"
echo "<INFO> Plugin CGI folder is: $PCGI"
echo "<INFO> Plugin HTML folder is: $PHTML"
echo "<INFO> Plugin Template folder is: $PTEMPL"
echo "<INFO> Plugin Data folder is: $PDATA"
echo "<INFO> Plugin Log folder (on RAMDISK!) is: $PLOG"
echo "<INFO> Plugin CONFIG folder is: $PCONFIG"
echo "<INFO> Plugin SBIN folder is: $PSBIN"
echo "<INFO> Plugin BIN folder is: $PBIN"

# ===================================================================
# NEUINSTALLATION ODER AKTUALISIERUNG? (Entscheidung 1, 29.09.2026; Befund I1)
# ===================================================================
# Nur bei einer Aktualisierung wird aus der Zweitschrift und dem
# Rettungsordner zurueckgespielt. Ob eine vorliegt, sagt allein die Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# und postupgrade.sh am Ende entfernt - kein Altersvergleich.
#
# Fehlt sie, ist dies eine Neuinstallation. Dann werden liegengebliebene
# Bestaende einer frueheren Installation NICHT uebernommen, sondern nach
# <name>.alt gelegt: die Zweitschrift der Konfiguration (Benutzer, Kennwort,
# Aktionstoken im Klartext) und der Rettungsordner der Ladehistorie. Bis
# 2.1.12 holte der erste Cron-Lauf oder Seitenaufruf die Zweitschrift ueber
# rn_umzug() zurueck (in WSL gemessen, Faelle N2 und N3: tokALT999 und das
# alte Kennwort). Die Bibliothek liest .alt nie; uninstall raeumt sie ab.
PDIR="${PDIR:-renault_ng}"
BASE="${5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    echo "<WARNING> Der LoxBerry-Ordner liess sich nicht bestimmen - liegengebliebene"
    echo "<INFO> Zweitschriften einer frueheren Installation wurden nicht geprueft."
    exit 1
fi
rc=0
MARKE="$BASE/data/plugins/$PDIR.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    echo "<INFO> Aktualisierung (Marke $MARKE vorhanden): Zweitschrift und Rettungsordner"
    echo "<INFO> bleiben fuer postupgrade.sh liegen."
else
    rn_beiseite=""
    for rn_x in "$BASE/config/plugins/$PDIR.backup.config.php" "$BASE/data/plugins/$PDIR.rettung"; do
        [ -e "$rn_x" ] || continue
        rm -rf "$rn_x.alt" 2>/dev/null
        if mv -f "$rn_x" "$rn_x.alt"; then
            [ -f "$rn_x.alt" ] && chmod 600 "$rn_x.alt" 2>/dev/null
            rn_beiseite="$rn_beiseite $rn_x.alt"
        else
            echo "<ERROR> $rn_x liess sich nicht beiseitelegen - bitte von Hand entfernen,"
            echo "<ERROR> sonst uebernimmt der erste Abruf die Einstellungen daraus."
            rc=1
        fi
    done
    if [ -n "$rn_beiseite" ]; then
        echo "<WARNING> Neuinstallation: Einstellungen bzw. Ladehistorie einer frueheren Installation werden NICHT uebernommen und liegen beiseite:$rn_beiseite"
        echo "<INFO> Die Zweitschrift enthaelt das Renault-Kennwort im Klartext. Die Deinstallation"
        echo "<INFO> raeumt beides mit ab; wer die alten Einstellungen will, spielt eine Sicherung ueber"
        echo "<INFO> den Reiter Einstellungen zurueck."
        rc=1
    fi
fi

exit $rc
