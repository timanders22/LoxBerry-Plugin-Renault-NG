#!/bin/bash
# Renault NG - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# X-1 (Bauform Abfahrtsassistent 1.6.19, Entscheidung 1 vom 29.09.2026). Der
# Installer ruft dieses Skript bei JEDEM Einbau auf, nach dem Aufraeumen der
# alten Fassung und VOR dem Kopieren von Konfiguration, Cron-Datei und
# Oberflaeche (sbin/plugininstall.pl: preupgrade :846, purge :874,
# preinstall :877, Cron :990, HTML :1066 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: Zweitschrift und
# Rettungsordner werden von postupgrade.sh gebraucht.
#
# Ohne Marke ist es eine NEUINSTALLATION. Eine liegengebliebene Zweitschrift
# (config/plugins/<ordner>.backup.config.php - Benutzer, Kennwort und
# Aktionstoken im Klartext) und ein liegengebliebener Rettungsordner der
# Ladehistorie (data/plugins/<ordner>.rettung) einer frueheren Installation
# gehen nach <name>.alt, gemeldet mit genau einer <WARNING>.
#
# Bis 2.1.14 tat das erst postinstall.sh. Die Bibliothek heilt aber im Takt:
# rn_umzug() spielt bei fehlender config.php eine Zweitschrift mit Token ein,
# und cron.03min liegt am Geraet schon vor postinstall.sh. Ein Takt in dieser
# Luecke holte die Einstellungen der frueheren Installation zurueck (in der
# README als "Bekannte Grenze" gefuehrt; im Pruefstand nachgestellt,
# vb_ren2_bau_skripte/proben/x1_*.txt). postinstall.sh behaelt seinen Block
# als zweites Netz. Die Selbstheilung der Bibliothek liest .alt nie; die
# Deinstallation raeumt es ab.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-renault_ng}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelsuche wie in den uebrigen Hakenskripten: ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst
# (Regeln/06, Raumklima-Vorfall).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postupgrade.sh spielt zurueck.
    exit 0
fi

SICH="$BASE/config/plugins/$PFOLDER.backup.config.php"
RETTUNG="$BASE/data/plugins/$PFOLDER.rettung"
BEISEITE=""
FEST=""
for ZIEL in "$SICH" "$RETTUNG"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then
        rm -rf "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
[ -f "$SICH.alt" ] && [ ! -L "$SICH.alt" ] && chmod 600 "$SICH.alt" 2>/dev/null

if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    RN_TEXT="<WARNING> Neuinstallation: Einstellungen bzw. Ladehistorie einer frueheren Installation werden NICHT uebernommen."
    [ -n "$BEISEITE" ] && RN_TEXT="$RN_TEXT Beiseitegelegt:$BEISEITE (die Zweitschrift enthaelt das Renault-Kennwort im Klartext; die Deinstallation raeumt sie ab; wer die alten Einstellungen will, spielt eine Sicherung ueber den Reiter Einstellungen zurueck)."
    [ -n "$FEST" ] && RN_TEXT="$RN_TEXT Nicht zu verschieben, bitte von Hand entfernen - sonst uebernimmt der erste Abruf die Einstellungen daraus:$FEST"
    echo "$RN_TEXT"
fi
# Ausdruecklich 0: ein Rest, der sich nicht verschieben laesst, steht in der
# Warnung; postinstall.sh versucht es danach noch einmal.
exit 0
