#!/usr/bin/env bash
# A Trade of Immersion: MANUAL install/update of this server inside DwemerDistro, from a clone of the
# server repository. The recommended route is the DwemerDistro launcher (Mods > Custom mods > Browse mods,
# search "Trade of Immersion"); use this script only when the launcher route is not available.
# Safe to run again: config/config.php and the database are kept.
#
# The manual copy lives at /var/www/html/custom-mods/trade-of-immersion (database trade_of_immersion_ai),
# served at http://127.0.0.1:19000/custom-mods/trade-of-immersion. DwemerDistro Core's web protection
# (setup-web --protect-project trade-of-immersion) is turned on before any file is copied, so config/,
# lib/, sql/, scripts/ and docs/ answer 403. A launcher install uses another folder
# (/var/www/html/TradeOfImmersionServer) and database (custom_trade_of_immersion), so the two never collide.
#
# A copy made by the 0.3.0 script (/var/www/html/custom-mods/TradeOfImmersionServer, not protected) is
# moved into the protected folder with its config and token; the database is untouched.
#
# Usage, inside DwemerDistro as the dwemer user:
#   git clone https://github.com/yucktheslime/toi-kagrenac-server.git ~/toi-kagrenac-server
#   bash ~/toi-kagrenac-server/scripts/deploy_toi.sh
# Later updates:
#   git -C ~/toi-kagrenac-server pull && bash ~/toi-kagrenac-server/scripts/deploy_toi.sh
# (A clone of the main mod repository works too: bash ~/a-trade-of-immersion/kagrenac-server/scripts/deploy_toi.sh)
set -euo pipefail

ID="trade-of-immersion"
WEB=/var/www/html
DEST="$WEB/custom-mods/$ID"
ROUTE="/custom-mods/$ID"
OLD="$WEB/custom-mods/TradeOfImmersionServer"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="$(sed -n 's/^CUSTOM_MODS_PORT=//p' /etc/dwemerdistro_services.conf 2>/dev/null | tr -d '"' | head -n1)"
PORT="${PORT:-19000}"
BASE="http://127.0.0.1:$PORT$ROUTE"

if [ "$(id -u)" -eq 0 ]; then
    echo "Run this as the dwemer user (not with sudo); it asks for sudo itself." >&2
    exit 1
fi
if [ ! -f "$SRC/dwemer-mod.json" ] || [ ! -f "$SRC/turn.php" ]; then
    echo "ERROR: $SRC does not look like the server folder (dwemer-mod.json, turn.php)." >&2
    exit 1
fi
command -v ddistro_custom_mod >/dev/null 2>&1 || {
    echo "ERROR: ddistro_custom_mod not found. Run 'Update Distro' in the DwemerDistro launcher first." >&2
    exit 1
}

code_of() { curl -s -o /dev/null -w '%{http_code}' "$1" || true; }

# 1. Web protection for our folder, before any file is copied or moved into the web root.
echo "== Web protection for $ROUTE"
sudo ddistro_custom_mod setup-web --protect-project "$ID"
sudo ddistro_custom_mod setup-web

# 2. Move a 0.3.0 copy (unprotected folder name) into the protected folder, keeping its config and token.
if [ -d "$OLD" ] && [ ! -e "$DEST" ]; then
    echo "== Moving the old copy $OLD to $DEST (config, token and database are kept)"
    sudo mv "$OLD" "$DEST"
elif [ -d "$OLD" ]; then
    echo "WARNING: both $OLD and $DEST exist. $OLD is not protected; remove it once you no longer need it:" >&2
    echo "         sudo rm -rf '$OLD'" >&2
fi

# 3. Copy the server files. Private files already in the destination are never overwritten.
echo "== Copying server files to $DEST"
sudo install -d -o "$(id -un)" -g "$(id -gn)" "$DEST"
tar -C "$SRC" \
    --exclude='./config/config.php' --exclude='./config/config.php.lock' --exclude='./config/.config-*' \
    --exclude='./.git' --exclude='./.github' --exclude='*.env' --exclude='./config.json' \
    -cf - . | tar -C "$DEST" --no-same-owner --no-overwrite-dir -xf -

# 4. Config (new random token only if missing), database, migrations.
echo "== Running install.sh"
sudo bash "$DEST/scripts/install.sh"

# 5. Check health and that private files are not served.
echo "== Health check"
if ! curl -fsS "$BASE/health.php"; then
    echo
    echo "Health check failed. Start the DwemerDistro launcher, then run: sudo ddistro_custom_mod setup-web" >&2
    exit 1
fi
echo
bad=0
for path in config/config.php lib/app.php scripts/migrate.php sql/; do
    c="$(code_of "$BASE/$path")"
    if [ "$c" = 403 ] || [ "$c" = 404 ]; then
        echo "OK: $path is not served (HTTP $c)."
    else
        echo "ERROR: $path answered HTTP $c. Do not use this server until it is 403." >&2
        bad=1
    fi
done
if [ "$bad" = 1 ]; then
    echo "Fix: sudo ddistro_custom_mod setup-web --protect-project $ID && sudo ddistro_custom_mod setup-web, then run this script again." >&2
    exit 1
fi
c="$(code_of "http://127.0.0.1:$PORT/custom-mods/TradeOfImmersionServer/config/config.php")"
if [ "$c" = 200 ]; then
    echo "ERROR: the old unprotected copy still serves its config: remove $OLD" >&2
    exit 1
fi
echo
echo "Dashboard:  $BASE/"
echo "Mod config: \"kagrenacBaseUrl\": \"$BASE\""
echo "Token:      sudo php -r '\$c = require \"$DEST/config/config.php\"; echo \$c[\"token\"], PHP_EOL;'"
