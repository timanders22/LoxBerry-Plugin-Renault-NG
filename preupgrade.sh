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

# Die Pfade oben setzt der LoxBerry-Installer ein. Ruft jemand dieses
# Skript aus einem ausgepackten Archiv heraus auf, stehen dort keine Pfade ab
# der Wurzel, und bis 2.1.10 legte es dann Ordner mit dem Namen des
# Platzhalters im aktuellen Verzeichnis an. Ohne brauchbare Pfade: warnen,
# nichts tun.
for rn_pfad in "$KONF" "$DATEN" "$PROT" "$ALT"; do
    case "$rn_pfad" in
        /?*) ;;
        *) echo "<WARNING> preupgrade.sh wurde nicht vom LoxBerry-Installer aufgerufen (Pfad ohne Wurzel: $rn_pfad) - es wird nichts gesichert."
           exit 1 ;;
    esac
done

# Hat eine Konfigurationsdatei INHALT? Rueckgabe 0 ja, 1 nein, 2 nicht pruefbar.
#
# Inhalt heisst: die Datei ist vollstaendig geschrieben - sie endet mit dem
# schliessenden PHP-Tag, wie jede, die das Plugin je geschrieben hat - UND sie
# traegt ein Aktionstoken oder einen Benutzernamen des Renault-Kontos (eine
# Konfiguration aus 1.4 kennt das Token noch nicht). Eine leere, abgeschnittene
# oder fremde Datei hat keinen Inhalt. Bis 2.1.10 wurde hier gar nicht
# geprueft und in postupgrade.sh nur nach der GROESSE (Klasse C,
# Bestand-2026-09-18/klasse-C/Ergebnis.md). Dieselbe Funktion steht
# wortgleich in postupgrade.sh - preupgrade.sh laeuft aus dem Auspackordner
# und kann keine Datei des Plugins einbinden.
RN_INHALT_PHP=$(cat <<'PHPEOF'
$r = @file_get_contents($argv[1]);
if (!is_string($r) || !preg_match('/\?>\s*\z/', $r)) { exit(1); }
$wert = '\s*=\s*(\'(?:[^\'\\\\]|\\\\.)+\'|"(?:[^"\\\\]|\\\\.)+")\s*;';
exit(preg_match('/^[ \t]*\$(aktionstoken|username)' . $wert . '/m', $r) ? 0 : 1);
PHPEOF
)
rn_hat_inhalt() {
    [ -f "$1" ] || return 1
    php -r "$RN_INHALT_PHP" "$1" 2>/dev/null
    case "$?" in
        0) return 0 ;;
        1) return 1 ;;
        *) return 2 ;;
    esac
}

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
    # Fall A: schon umgezogen (1.6.0 und neuer).
    #
    # Gesichert wird nur eine Konfiguration MIT Inhalt. Bis 2.1.10 wurde
    # config.php bedingungslos ueber die Zweitschrift kopiert - eine
    # abgeschnittene Datei ersetzte damit die heile Zweitschrift, und der
    # einzige Rueckweg war fort (in WSL gemessen, Pruefung-Renault-NG-2.1.11,
    # Fall K5).
    rn_hat_inhalt "$KONF/config.php"
    rn_k=$?
    if [ "$rn_k" = 1 ]; then
        if rn_hat_inhalt "$SICH"; then
            echo "<WARNING> Die Konfiguration $KONF/config.php hat keinen Inhalt (leer, abgeschnitten"
            echo "<WARNING> oder ohne Token und Benutzer). Die vorhandene Zweitschrift $SICH"
            echo "<WARNING> bleibt unberuehrt und wird nach dem Update zurueckgespielt."
        else
            echo "<WARNING> Weder die Konfiguration noch die Zweitschrift hat Inhalt - nach dem"
            echo "<WARNING> Update bitte die Einstellungen neu eintragen."
        fi
        rc=1
    else
        [ "$rn_k" = 2 ] && echo "<INFO> Der Inhalt der Konfiguration liess sich nicht pruefen (php) - sie wird gesichert wie vorgefunden."
        if cp -f "$KONF/config.php" "$SICH"; then
            chmod 600 "$SICH" 2>/dev/null || echo "<WARNING> chmod 600 auf $SICH fehlgeschlagen."
            echo "<OK> Konfiguration gesichert nach $SICH."
        else
            echo "<ERROR> Konfiguration konnte nicht nach $SICH gesichert werden."
            rc=1
        fi
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
#
# Der Rettungsordner wird NEBEN seinem Platz gebaut (<rettung>.neu), jede
# Datei byteweise verglichen, und erst dann an seine Stelle gesetzt; ein
# vorhandener faellt zuletzt (Regeln/06, "Eine Sicherung wird neben ihrem
# Platz gebaut"). Er traegt den Zeitpunkt dieses Vorgangs (Datei
# "zeitpunkt", Unixzeit): postupgrade.sh spielt nur einen Rettungsordner aus
# DIESEM Update zurueck. Bis 2.1.10 blieb er nach jedem Update liegen und
# wurde bei jedem spaeteren wieder eingespielt, auch eine Datei aus einem
# Update vor Monaten (in WSL gemessen, Pruefung-Renault-NG-2.1.11, Faelle L2
# und L6).
#
# Dazu die Sitzung und die Ladehistorie einer Fassung 1.4 oder aelter: sie
# lagen im Programmordner, den der Installer gleich ebenfalls loescht. Sie
# gehoeren in den Rettungsordner, NICHT nach data/ (das wird mit
# abgeraeumt); bis 2.1.5 gingen sie genau dorthin.
NEU="${RETTUNG}.neu"
zu_retten=0
ls "$DATEN"/database*.csv >/dev/null 2>&1 && zu_retten=1
for f in session database.csv; do
    [ -f "$ALT/$f" ] && zu_retten=1
done
if [ "$zu_retten" = 1 ]; then
    rm -rf "$NEU"
    if mkdir -p "$NEU" 2>/dev/null; then
        gerettet=0
        fehler=0
        for f in "$DATEN"/database*.csv; do
            [ -f "$f" ] || continue
            ziel="$NEU/$(basename "$f")"
            if cp -p "$f" "$ziel" && cmp -s "$f" "$ziel"; then
                gerettet=$((gerettet + 1))
            else
                echo "<ERROR> $(basename "$f") konnte nicht gesichert werden."
                fehler=1
            fi
        done
        for f in session database.csv; do
            [ -f "$ALT/$f" ] || continue
            # Was schon aus data/ kam, ist der juengere Stand.
            [ -f "$NEU/$f" ] && continue
            if cp -p "$ALT/$f" "$NEU/$f" && cmp -s "$ALT/$f" "$NEU/$f"; then
                echo "<OK> $f aus dem Programmordner gesichert."
            else
                echo "<ERROR> $f aus dem Programmordner liess sich nicht sichern."
                fehler=1
            fi
        done
        JETZT=$(date +%s 2>/dev/null)
        case "$JETZT" in
            ''|*[!0-9]*)
                echo "<WARNING> Die Uhr ist nicht lesbar - der Rettungsordner bekommt keinen Zeitpunkt,"
                echo "<WARNING> und postupgrade.sh spielt ihn deshalb nicht zurueck. Er liegt unter $RETTUNG." ;;
            *) echo "$JETZT" > "$NEU/zeitpunkt" ;;
        esac
        if [ "$fehler" = 0 ]; then
            rm -rf "${RETTUNG}.alt"
            [ -d "$RETTUNG" ] && mv "$RETTUNG" "${RETTUNG}.alt"
            if mv "$NEU" "$RETTUNG"; then
                rm -rf "${RETTUNG}.alt"
                echo "<OK> $gerettet Datei(en) der Ladehistorie gesichert nach $RETTUNG."
            else
                [ -d "${RETTUNG}.alt" ] && mv "${RETTUNG}.alt" "$RETTUNG"
                echo "<ERROR> Der Rettungsordner liess sich nicht an seinen Platz setzen - die"
                echo "<ERROR> Kopie liegt unter $NEU."
                rc=1
            fi
        else
            echo "<ERROR> Die Ladehistorie liess sich nicht vollstaendig sichern. Ein vorhandener"
            echo "<ERROR> Rettungsordner bleibt unberuehrt; die Teilkopie liegt unter $NEU."
            rc=1
        fi
    else
        echo "<ERROR> Rettungsordner $NEU liess sich nicht anlegen - die"
        echo "<ERROR> Ladehistorie geht bei diesem Update verloren."
        rc=1
    fi
else
    echo "<INFO> Keine Ladehistorie vorhanden - nichts zu sichern."
fi

# ===================================================================
# 3. UPDATE VON 1.4 ODER AELTER: das Protokoll
# ===================================================================
# Damals lag auch das Protokoll im Programmordner. Es darf direkt nach log/
# (das bleibt stehen); Sitzung und Ladehistorie sind oben im
# Rettungsordner.
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

exit $rc
