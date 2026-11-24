#!/usr/bin/env bash
# A Trade of Immersion: move a MANUAL install (deploy_toi.sh) into the DwemerDistro launcher install, keeping
# your conversations, memories, bios, dashboard settings (OpenRouter key, model, voice) and token.
#
# First install the server from the launcher (Mods > Custom mods > Browse mods > A Trade of Immersion >
# Install server), then run inside DwemerDistro as the dwemer user:
#   bash ~/toi-kagrenac-server/scripts/move_to_launcher.sh
#
# What it does:
#   1. Backs up both installs: Core's backup of the launcher install, plus a pg_dump of the manual database and a
#      copy of its config in ~/toi-move-backup-<time>/ (private, mode 700).
#   2. Replaces the launcher install's database (custom_trade_of_immersion, still empty) with a copy of the
#      manual one (trade_of_immersion_ai). The manual database itself is not changed.
#   3. Writes the manual config into the launcher install's config file, changing only the database name.
#   4. Runs the migrations and the health check, then moves the manual folder out of the web root into the
#      backup folder, so only one server answers.
set -euo pipefail

ID="trade-of-immersion"
WEB=/var/www/html
NEW="$WEB/TradeOfImmersionServer"
PORT="$(sed -n 's/^CUSTOM_MODS_PORT=//p' /etc/dwemerdistro_services.conf 2>/dev/null | tr -d '"' | head -n1)"
PORT="${PORT:-19000}"

[ "$(id -u)" -ne 0 ] || { echo "Run this as the dwemer user (not with sudo); it asks for sudo itself." >&2; exit 1; }
OLD=""
for f in "$WEB/custom-mods/trade-of-immersion" "$WEB/custom-mods/TradeOfImmersionServer"; do
    if sudo test -f "$f/config/config.php"; then OLD="$f"; break; fi
done
[ -n "$OLD" ] || { echo "No manual install found in $WEB/custom-mods/ (nothing to move)." >&2; exit 1; }
status="$(ddistro_custom_mod status --json)"
python3 -c '
import json, sys
mods = [m for m in json.loads(sys.argv[1])["mods"] if m.get("id") == sys.argv[2]]
ok = mods and mods[0].get("state") == "ready" and mods[0].get("install_mode") == "release" and mods[0].get("server_folder") == "TradeOfImmersionServer"
sys.exit(0 if ok else 1)' "$status" "$ID" || {
    echo "The launcher install is not ready. In the DwemerDistro launcher: Mods > Custom mods > Browse mods >" >&2
    echo "A Trade of Immersion > Install server, then run this again." >&2
    exit 1
}
NEWCFG="$NEW/config/config.php"
OLDCFG="$OLD/config/config.php"
OLDDB="$(sudo php -r '$c = require $argv[1]; echo $c["database"]["name"] ?? "";' "$OLDCFG")"
NEWDB="$(sudo php -r '$c = require $argv[1]; echo $c["database"]["name"] ?? "";' "$NEWCFG")"
[[ $OLDDB =~ ^[a-z_][a-z0-9_]{0,62}$ && $NEWDB =~ ^custom_[a-z0-9_]+$ && $OLDDB != "$NEWDB" ]] || {
    echo "Unexpected database names ('$OLDDB' -> '$NEWDB'); nothing was changed." >&2; exit 1; }
[ "$(grep -c "'$OLDDB'" <(sudo cat "$OLDCFG"))" = 1 ] || { echo "The manual config names its database more than once; nothing was changed." >&2; exit 1; }

BK="$HOME/toi-move-backup-$(date +%Y%m%d-%H%M%S)"
trap 'echo "FAILED. Backups are in $BK and in /var/lib/dwemerdistro-custom-mods/backups/$ID/; the manual install was not moved." >&2' ERR
install -d -m 700 "$BK"
echo "== Backups in $BK"
sudo ddistro_custom_mod backup "$ID"
sudo -u postgres pg_dump -Fc "$OLDDB" > "$BK/$OLDDB.dump"
sudo cat "$OLDCFG" > "$BK/manual-config.php"
chmod 600 "$BK"/*

echo "== Copying database $OLDDB -> $NEWDB"
# Restored into a side database first; the launcher's database is only swapped once the copy is complete.
TMPDB="${NEWDB}_incoming"
sudo -u postgres dropdb --if-exists "$TMPDB"
sudo -u postgres createdb -O dwemer "$TMPDB"
sudo -u postgres pg_restore --no-owner --role=dwemer --exit-on-error -d "$TMPDB" < "$BK/$OLDDB.dump"
sudo -u postgres dropdb "$NEWDB"
sudo -u postgres psql -qX -v ON_ERROR_STOP=1 -d postgres -c "ALTER DATABASE \"$TMPDB\" RENAME TO \"$NEWDB\""

echo "== Copying the manual config (only the database name changes)"
# Written through the existing file, so the launcher's owner, mode and inode stay as Core set them.
sudo sh -c 'sed "s/'"'"'$1'"'"'/'"'"'$2'"'"'/" "$3" > "$4"' sh "$OLDDB" "$NEWDB" "$OLDCFG" "$NEWCFG"

echo "== Migrations and health check"
sudo -u dwemer php "$NEW/scripts/migrate.php"
curl -fsS "http://127.0.0.1:$PORT/TradeOfImmersionServer/health.php" >/dev/null
code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/TradeOfImmersionServer/config/config.php")"
[ "$code" = 403 ] || { echo "ERROR: config.php answered HTTP $code on the launcher install." >&2; exit 1; }

echo "== Moving the manual copy out of the web root"
sudo mv "$OLD" "$BK/manual-server"
echo
echo "Done. Your data and token now live in the launcher install:"
echo "  Dashboard:  http://127.0.0.1:$PORT/TradeOfImmersionServer/ui/"
echo "  Mod config: \"kagrenacBaseUrl\": \"http://127.0.0.1:$PORT/TradeOfImmersionServer\" (the mod's default)"
echo "  The token did not change. The old database $OLDDB is kept; drop it later with: sudo -u postgres dropdb $OLDDB"
echo "  Undo: sudo mv '$BK/manual-server' '$OLD'"
