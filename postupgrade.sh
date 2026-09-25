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

# Hat eine Konfigurationsdatei INHALT? Rueckgabe 0 ja, 1 nein, 2 nicht pruefbar.
#
# Inhalt heisst: die Datei ist vollstaendig geschrieben - sie endet mit dem
# schliessenden PHP-Tag, wie jede, die das Plugin je geschrieben hat - UND sie
# traegt ein Aktionstoken oder einen Benutzernamen des Renault-Kontos (eine
# Konfiguration aus 1.4 kennt das Token noch nicht). Eine leere, abgeschnittene
# oder fremde Datei hat keinen Inhalt. Bis 2.1.10 wurde hier gar nicht
# geprueft und in postupgrade.sh nur nach der GROESSE (Klasse C,
# Bestand-2026-09-18/klasse-C/Ergebnis.md). Dieselbe Funktion steht
# wortgleich in preupgrade.sh - preupgrade.sh laeuft aus dem Auspackordner
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
# bleibt als config.php.kaputt (0600) daneben liegen.
for SICH in "${KONF}.backup.config.php" "$KONF/config.php.backup"; do
    [ -f "$SICH" ] || continue
    rn_hat_inhalt "$KONF/config.php"
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
        if mv -f "$KONF/config.php" "$KONF/config.php.kaputt"; then
            chmod 600 "$KONF/config.php.kaputt" 2>/dev/null
            echo "<WARNING> Die vorhandene Konfiguration hatte keinen Inhalt; sie liegt als"
            echo "<WARNING> $KONF/config.php.kaputt daneben."
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
# Zurueckgespielt wird nur ein Rettungsordner aus DIESEM Update: sein
# Zeitpunkt (preupgrade.sh) muss eine Zahl sein und hoechstens 3600 s alt;
# bis 300 s "aus der Zukunft" gilt er noch (die Uhr kann zwischen den beiden
# Skripten ein Stueck zurueckspringen - Bauart Govee 0.9.20). Beide Zahlen
# werden VOR der Rechnung als Zahl geprueft: bash wertet in $(( )) den
# INHALT einer Variablen aus, und ein Zeitpunkt wie a[$(befehl)] fuehrte
# den Befehl aus (Klasse M, Bestand-2026-09-18). Ohne lesbare Uhr faellt
# die Pruefung geschlossen aus: nichts einspielen, sagen wo es liegt.
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
    rn_t0=$(cat "$RETTUNG/zeitpunkt" 2>/dev/null)
    rn_jetzt=$(date +%s 2>/dev/null)
    rn_gilt=0
    rn_grund=""
    case "$rn_t0" in
        ''|*[!0-9]*)
            rn_grund="er traegt keinen gueltigen Zeitpunkt (Rest einer Fassung bis 2.1.10 oder eines abgebrochenen Updates)" ;;
        *)
            case "$rn_jetzt" in
                ''|*[!0-9]*)
                    rn_grund="die Uhr ist nicht lesbar" ;;
                *)
                    rn_alter=$((rn_jetzt - rn_t0))
                    if [ "$rn_alter" -ge -300 ] && [ "$rn_alter" -le 3600 ]; then
                        rn_gilt=1
                    else
                        rn_grund="er stammt nicht aus diesem Update (angelegt vor $rn_alter s)"
                    fi ;;
            esac ;;
    esac
    if [ "$rn_gilt" = 1 ]; then
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
    else
        echo "<WARNING> Der Rettungsordner $RETTUNG wird NICHT zurueckgespielt:"
        echo "<WARNING> $rn_grund. Er bleibt unberuehrt liegen; wer die Ladehistorie daraus"
        echo "<WARNING> braucht, kopiert sie von Hand nach $DATEN/."
        rc=1
    fi
else
    echo "<INFO> Kein Rettungsordner vorhanden - vermutlich eine Erstinstallation."
fi

echo "<INFO> Konfiguration und Ladehistorie werden NEBEN ihren Ordnern gesichert,"
echo "<INFO> weil der Installer config/plugins/<ordner>/ und data/plugins/<ordner>/"
echo "<INFO> bei jedem Update abraeumt. Das Protokoll unter log/plugins/ bleibt"
echo "<INFO> stehen, ist aber eine Ramdisk und uebersteht keinen Neustart."

exit $rc
