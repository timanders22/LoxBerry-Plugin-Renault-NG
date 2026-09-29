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

# Die Pfade oben setzt der LoxBerry-Installer ein. Ruft jemand dieses
# Skript aus einem ausgepackten Archiv heraus auf, stehen dort keine Pfade ab
# der Wurzel, und bis 2.1.10 legte es dann Ordner mit dem Namen des
# Platzhalters im aktuellen Verzeichnis an. Ohne brauchbare Pfade: warnen,
# nichts tun.
for rn_pfad in "$KONF" "$DATEN"; do
    case "$rn_pfad" in
        /?*) ;;
        *) echo "<WARNING> postupgrade.sh wurde nicht vom LoxBerry-Installer aufgerufen (Pfad ohne Wurzel: $rn_pfad) - es wird nichts zurueckgespielt."
           exit 1 ;;
    esac
done

# ===================================================================
# 0. DIE UPGRADE-MARKE (Entscheidung 1 des Hausherrn, 29.09.2026; I1/I2)
# ===================================================================
# preupgrade.sh legt data/plugins/<ordner>.upgrade_laeuft als Erstes an.
# Zurueckgespielt wird nur, wenn sie VORHANDEN ist - kein Altersvergleich.
# Entfernt wird sie hier, am Ende dieses Skripts (trap, auch bei einem
# vorzeitigen exit): postinstall.sh laeuft VOR diesem Skript und braucht sie
# ebenso wie die Rueckspielung weiter unten. Fehlt sie, laeuft dieses Skript
# nicht im Zuge eines Updates, das preupgrade.sh begonnen hat - dann wird
# nichts eingespielt, und Zweitschrift und Rettungsordner bleiben liegen.
MARKE="${DATEN}.upgrade_laeuft"
rn_marke_weg() { rm -f "$MARKE" 2>/dev/null; }
trap rn_marke_weg EXIT
if [ ! -f "$MARKE" ]; then
    echo "<WARNING> Die Marke $MARKE fehlt - dieses Update wurde nicht von preupgrade.sh"
    echo "<WARNING> begonnen. Es wird NICHTS zurueckgespielt; Zweitschrift und Rettungsordner"
    echo "<WARNING> bleiben unberuehrt liegen."
    exit 1
fi

# Hat eine Konfigurationsdatei INHALT? Rueckgabe 0 ja, 1 nein, 2 nicht pruefbar.
# $1 ist die Datei, $2 (wahlweise) die Zweitschrift, gegen die "ohne Token"
# gemessen wird.
#
# Das Urteil faellt die BIBLIOTHEK (rn_konfig_datei_urteil() in rn_lib.php),
# dieselbe Frage, die rn_config_read() stellt (Befund I4); bis 2.1.12 stand
# hier eine eigene Pruefung, die ein schliessendes ?> verlangte. Gefragt wird
# die eben installierte Bibliothek; ersatzweise die im Auspackordner.
RN_LIB_NEU="REPLACELBPHTMLAUTHDIR/rn_lib.php"
case "$RN_LIB_NEU" in
    /?*) [ -f "$RN_LIB_NEU" ] || RN_LIB_NEU="" ;;
    *) RN_LIB_NEU="" ;;
esac
[ -n "$RN_LIB_NEU" ] || RN_LIB_NEU="$(cd "$(dirname "$0")" 2>/dev/null && pwd)/webfrontend/htmlauth/rn_lib.php"
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

mkdir -p "$KONF" 2>/dev/null

# ===================================================================
# 1. KONFIGURATION
# ===================================================================
# Die Zweitschrift liegt seit 2.1.0 NEBEN dem Konfigordner. Der alte Ort
# wird noch beruecksichtigt, damit ein Update von 2.0.x nichts verliert.
#
# Entschieden wird nach INHALT, nicht nach Groesse. Bis 2.1.10 wurde nur
# zurueckgespielt, wenn config.php fehlte oder 0 Byte hatte: eine
# abgeschnittene oder fremde Datei galt als "vorhanden", die heile
# Zweitschrift blieb draussen; und eine leere Zweitschrift wurde kopiert und
# als "wiederhergestellt" gemeldet (in WSL gemessen,
# Pruefung-Renault-NG-2.1.11, Faelle K1 bis K3). Eine verdraengte Datei
# liegt seit 2.1.13 als <ordner>.config.php.kaputt.<zeit> (0600) NEBEN dem
# Ordner (Befund I4); bis 2.1.12 lag sie darin und ging beim naechsten Update
# mit dem Ordner.
for SICH in "${KONF}.backup.config.php" "$KONF/config.php.backup"; do
    [ -f "$SICH" ] || continue
    rn_hat_inhalt "$KONF/config.php" "$SICH"
    rn_ist=$?
    [ "$rn_ist" = 0 ] && break
    rn_hat_inhalt "$SICH"
    rn_zs=$?
    if [ "$rn_zs" = 1 ]; then
        echo "<WARNING> Die Zweitschrift $SICH hat keinen Inhalt (leer, abgeschnitten oder ohne"
        echo "<WARNING> Token und Benutzer) - sie wird NICHT zurueckgespielt."
        rc=1
        continue
    fi
    if [ "$rn_ist" = 2 ] || [ "$rn_zs" = 2 ]; then
        # Ohne php nicht pruefbar: dann nur die fruehere Regel - eine
        # vorhandene, nicht leere Konfiguration bleibt.
        if [ -s "$KONF/config.php" ]; then
            echo "<INFO> Der Inhalt liess sich nicht pruefen (php) - die vorhandene Konfiguration bleibt."
            break
        fi
    fi
    if [ -f "$KONF/config.php" ]; then
        RN_KAPUTT="${KONF}.config.php.kaputt.$(date +%Y%m%d%H%M%S 2>/dev/null)"
        if mv -f "$KONF/config.php" "$RN_KAPUTT"; then
            chmod 600 "$RN_KAPUTT" 2>/dev/null
            echo "<WARNING> Die vorhandene Konfiguration war unbrauchbar; sie liegt als"
            echo "<WARNING> $RN_KAPUTT daneben."
        fi
    fi
    if cp -f "$SICH" "$KONF/config.php"; then
        chmod 600 "$KONF/config.php" 2>/dev/null \
            || echo "<WARNING> chmod 600 auf die Konfiguration fehlgeschlagen."
        echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt ($SICH)."
        break
    else
        echo "<ERROR> Konfiguration liess sich nicht aus $SICH wiederherstellen."
        rc=1
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
# Zurueckgespielt wird, wenn die Marke vorhanden ist (oben geprueft) - kein
# Altersvergleich mehr (Entscheidung 1, Befund I2). Bis 2.1.12 musste der
# Zeitpunkt im Rettungsordner hoechstens 3600 s alt sein: ein Update mit mehr
# als einer Stunde zwischen preupgrade.sh und diesem Skript galt als fremd.
# Dass hier nie ein Bestand aus einem FRUEHEREN Vorgang liegt, stellt
# preupgrade.sh sicher: es raeumt einen alten Rettungsordner vorher weg.
#
# Steht am Ziel schon eine Datei, hat ein Abruf in der Luecke zwischen dem
# Loeschen des Datenordners und diesem Skript sie angelegt - cron.03min
# laeuft waehrend des Updates weiter, und abruf.php legt eine fehlende
# Aufzeichnung neu an. Bis 2.1.10 blieb die gerettete Historie dann
# draussen ("lagen schon da"): Monate weg, eine Zeile da (in WSL gemessen,
# Pruefung-Renault-NG-2.1.11, Fall L1). Jetzt werden beide nach INHALT
# zusammengefuehrt: die gerettete Datei, dahinter die Zeilen aus der Luecke
# ohne ihre Kopfzeile. Das deckt auch einen Lauf, der vor preupgrade.sh
# begann und erst nach dem Loeschen schreibt - den haette keine Sperre
# aufgehalten.
#
# Der Rettungsordner wird nur entfernt, wenn jede Datei nachweislich
# zurueck ist (die Datei am Ziel beginnt byteweise mit der geretteten);
# sonst bleibt er liegen, und die Meldung nennt ihn.
if [ -d "$RETTUNG" ]; then
    if [ -f "$MARKE" ]; then
        mkdir -p "$DATEN" 2>/dev/null
        zurueck=0
        zusammen=0
        belegt=0
        for f in "$RETTUNG"/database*.csv; do
            [ -f "$f" ] || continue
            ziel="$DATEN/$(basename "$f")"
            if [ ! -f "$ziel" ]; then
                if cp -p "$f" "$ziel"; then
                    zurueck=$((zurueck + 1))
                else
                    echo "<ERROR> $(basename "$f") liess sich nicht zurueckholen."
                    rc=1
                fi
            else
                tmp="$ziel.tmp.$$"
                neu_zeilen=$(grep -vc '^Date;' "$ziel")
                if { cat "$f"; [ -n "$(tail -c 1 "$f")" ] && echo; grep -v '^Date;' "$ziel" || true; } > "$tmp" \
                   && mv -f "$tmp" "$ziel"; then
                    zusammen=$((zusammen + 1))
                    echo "<INFO> $(basename "$f"): $neu_zeilen Zeile(n) aus der Zeit des Updates an die gerettete Ladehistorie angehaengt."
                else
                    rm -f "$tmp"
                    echo "<ERROR> $(basename "$f") liess sich nicht mit der geretteten Ladehistorie zusammenfuehren."
                    rc=1
                fi
            fi
            # Belegt ist die Rueckholung, wenn die Datei am Ziel mit der
            # geretteten beginnt.
            if [ -f "$ziel" ] && head -c "$(wc -c < "$f")" "$ziel" | cmp -s - "$f"; then
                belegt=$((belegt + 1))
            fi
        done
        gesamt=$(ls "$RETTUNG"/database*.csv 2>/dev/null | wc -l)
        for f in "$RETTUNG/session"; do
            [ -f "$f" ] || continue
            [ -f "$DATEN/session" ] && continue
            cp -f "$f" "$DATEN/session" 2>/dev/null
        done
        if [ "$zurueck" -gt 0 ] || [ "$zusammen" -gt 0 ]; then
            echo "<OK> Ladehistorie: $zurueck Datei(en) zurueckgeholt, $zusammen mit den Zeilen aus der Zeit des Updates zusammengefuehrt."
        else
            echo "<INFO> Im Rettungsordner lag keine Ladehistorie."
        fi
        if [ "$belegt" = "$gesamt" ]; then
            rm -rf "$RETTUNG" && echo "<INFO> Alles zurueck - der Rettungsordner ist entfernt."
        else
            echo "<WARNING> Nur $belegt von $gesamt Datei(en) sind nachweislich zurueck - der"
            echo "<WARNING> Rettungsordner bleibt liegen: $RETTUNG"
            rc=1
        fi
    fi
else
    # Befund I3: dieses Skript laeuft nur bei einem Update.
    echo "<INFO> Kein Rettungsordner vorhanden - nichts zurueckzuholen."
fi

echo "<INFO> Konfiguration und Ladehistorie werden NEBEN ihren Ordnern gesichert,"
echo "<INFO> weil der Installer config/plugins/<ordner>/ und data/plugins/<ordner>/"
echo "<INFO> bei jedem Update abraeumt. Das Protokoll unter log/plugins/ bleibt"
echo "<INFO> stehen, ist aber eine Ramdisk und uebersteht keinen Neustart."

exit $rc
