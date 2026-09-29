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

# ===================================================================
# 0. DIE UPGRADE-MARKE (Entscheidung 1 des Hausherrn, 29.09.2026; Befund I1)
# ===================================================================
# data/plugins/<ordner>.upgrade_laeuft sagt postinstall.sh und postupgrade.sh,
# dass dies eine AKTUALISIERUNG ist: nur dann wird aus Zweitschrift und
# Rettungsordner zurueckgespielt; ohne Marke legt postinstall.sh beides als
# <name>.alt beiseite (Neuinstallation). Entschieden wird allein am
# Vorhandensein, nicht am Alter. Sie liegt NEBEN dem Datenordner -
# purge_installation loescht nur den Ordner selbst -, und postupgrade.sh
# entfernt sie an seinem Ende (trap). Bis 2.1.12 gab es sie nicht: eine
# Neuinstallation spielte eine liegengebliebene Zweitschrift samt Kennwort und
# altem Token ein (in WSL gemessen, Faelle N2 und N3), und postupgrade.sh
# entschied am Alter des Rettungsordners (Fall U5).
#
# Lag die Marke schon VOR diesem Lauf, ist ein frueherer Versuch DIESES
# Updates nach preupgrade.sh abgebrochen (Abschnitt 2). Laesst sie sich nicht
# anlegen, bricht das Update hier ab, vor dem Sichern und vor
# purge_installation: ohne Marke hielte postinstall.sh es fuer eine
# Neuinstallation und legte die Einstellungen beiseite.
MARKE="${DATEN}.upgrade_laeuft"
RN_MARKE_VORHER=0
[ -f "$MARKE" ] && RN_MARKE_VORHER=1
if { date +%s > "$MARKE"; } 2>/dev/null && [ -s "$MARKE" ]; then
    echo "<OK> Marke fuer die laufende Aktualisierung angelegt ($MARKE)."
else
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen. Ohne sie hielte postinstall.sh"
    echo "<FAIL> dieses Update fuer eine Neuinstallation und legte die Einstellungen beiseite."
    echo "<FAIL> Die Aktualisierung wird abgebrochen; die bisherige Fassung bleibt installiert."
    exit 2
fi

# Hat eine Konfigurationsdatei INHALT? Rueckgabe 0 ja, 1 nein, 2 nicht pruefbar.
# $1 ist die Datei, $2 (wahlweise) die Zweitschrift, gegen die "ohne Token"
# gemessen wird.
#
# Das Urteil faellt die BIBLIOTHEK (rn_konfig_datei_urteil() in rn_lib.php) -
# dieselbe Frage, die rn_config_read() stellt (Befund I4). Bis 2.1.12 stand
# hier eine eigene Pruefung, die ein schliessendes ?> verlangte: eine von der
# Bibliothek als heil gelesene Konfiguration ohne ?> galt als leer, und die
# aeltere Zweitschrift kam zurueck (in WSL gemessen, Fall U3). Dieses Skript
# laeuft aus dem Auspackordner des NEUEN Archivs; die Bibliothek daneben ist
# die, die nach dem Update gilt. Fehlt sie, heisst das "nicht pruefbar".
RN_LIB_NEU="$(cd "$(dirname "$0")" 2>/dev/null && pwd)/webfrontend/htmlauth/rn_lib.php"
RN_INHALT_PHP=$(cat <<'PHPEOF'
require $argv[1];
exit(rn_konfig_datei_urteil($argv[2], isset($argv[3]) ? $argv[3] : '') === '' ? 0 : 1);
PHPEOF
)
rn_hat_inhalt() {
    [ -f "$1" ] || return 1
    [ -f "$RN_LIB_NEU" ] || return 2
    php -r "$RN_INHALT_PHP" "$RN_LIB_NEU" "$1" "${2:-}" 2>/dev/null
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
    rn_hat_inhalt "$KONF/config.php" "$SICH"
    rn_k=$?
    if [ "$rn_k" = 1 ]; then
        # Nie ersatzlos verwerfen (Befund I4): die Datei kommt als
        # <ordner>.config.php.kaputt.<zeit> NEBEN den Ordner - purge_installation
        # loescht den Ordner gleich. Bis 2.1.12 ging eine teilweise lesbare
        # Konfiguration beim Update spurlos verloren (in WSL gemessen, Fall U6).
        # Angelegt mit umask 077, also nie lesbar fuer andere; uninstall raeumt
        # sie mit ab.
        if rn_hat_inhalt "$SICH"; then
            echo "<WARNING> Die Konfiguration $KONF/config.php ist unbrauchbar (leer, abgeschnitten"
            echo "<WARNING> oder ohne Token, waehrend die Zweitschrift eines traegt). Die vorhandene"
            echo "<WARNING> Zweitschrift $SICH bleibt unberuehrt und wird nach dem Update zurueckgespielt."
        else
            echo "<WARNING> Weder die Konfiguration noch die Zweitschrift hat Inhalt - nach dem"
            echo "<WARNING> Update bitte die Einstellungen neu eintragen."
        fi
        RN_KAPUTT="${KONF}.config.php.kaputt.$(date +%Y%m%d%H%M%S 2>/dev/null)"
        if ( umask 077 && cp "$KONF/config.php" "$RN_KAPUTT" ) 2>/dev/null; then
            echo "<WARNING> Die unbrauchbare Konfiguration liegt als $RN_KAPUTT daneben."
        else
            echo "<WARNING> Die unbrauchbare Konfiguration liess sich nicht beiseitelegen und geht"
            echo "<WARNING> mit dem Update verloren."
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
    # Dieses Skript laeuft nur bei einem Update - "Erstinstallation" war hier
    # nie die richtige Erklaerung (Befund I3).
    echo "<INFO> Keine Konfiguration vorhanden - das Plugin wurde noch nicht eingerichtet."
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
# Datei byteweise verglichen, und erst dann an seine Stelle gesetzt (Regeln/06,
# "Eine Sicherung wird neben ihrem Platz gebaut"). Bis 2.1.10 blieb er nach
# jedem Update liegen und wurde bei jedem spaeteren wieder eingespielt, auch
# eine Datei aus einem Update vor Monaten (in WSL gemessen,
# Pruefung-Renault-NG-2.1.11, Faelle L2 und L6).
#
# Seit 2.1.13 (Entscheidung 1, Befund I2) gibt es keine Datei "zeitpunkt" und
# keinen Altersvergleich mehr: postupgrade.sh spielt zurueck, wenn die Marke
# vorhanden ist. Damit dabei nie ein Bestand aus einem FRUEHEREN Vorgang
# eingespielt wird, raeumt dieses Skript einen vorhandenen Rettungsordner
# vorher weg - immer, nicht nur wenn ein neuer entsteht. Bis 2.1.12 blieb er
# ohne neue Ladehistorie liegen, und jedes weitere Update endete mit
# <WARNING> und Rueckgabewert 1 (in WSL gemessen, Fall U5). Einzige Ausnahme:
# lag die Marke schon vor diesem Lauf und gibt es nichts Neues zu retten, ist
# ein Versuch DIESES Updates nach dem Loeschen des Datenordners abgebrochen,
# und der Rettungsordner ist die einzige Abschrift der Ladehistorie.
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
if [ -d "$RETTUNG" ]; then
    if [ "$RN_MARKE_VORHER" = 1 ] && [ "$zu_retten" = 0 ]; then
        echo "<INFO> Der Rettungsordner $RETTUNG stammt aus einem abgebrochenen Versuch dieses"
        echo "<INFO> Updates und bleibt fuer postupgrade.sh liegen."
    elif rm -rf "$RETTUNG"; then
        echo "<INFO> Ein Rettungsordner aus einem frueheren Vorgang lag noch da; er ist entfernt,"
        echo "<INFO> damit er nie in dieses Update eingespielt wird."
    else
        echo "<ERROR> Der alte Rettungsordner $RETTUNG liess sich nicht entfernen."
        rc=1
    fi
fi
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
        if [ "$fehler" = 0 ]; then
            # Ein alter Rettungsordner ist oben schon weggeraeumt. Die
            # Baustelle heisst nicht mehr "${RETTUNG}.alt": .alt ist seit 2.1.13
            # der Name des bei einer Neuinstallation beiseitegelegten Bestands
            # (postinstall.sh), und der darf hier nicht verschwinden.
            if mv "$NEU" "$RETTUNG"; then
                echo "<OK> $gerettet Datei(en) der Ladehistorie gesichert nach $RETTUNG."
            else
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
