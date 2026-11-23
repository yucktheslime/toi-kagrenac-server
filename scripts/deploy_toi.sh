#!/usr/bin/env bash
# A Trade of Immersion: installs or updates this server inside DwemerDistro from a clone of the
# mod repository (the server lives in its kagrenac-server/ subfolder, so the launcher's
# whole-repo route cannot be used). Safe to run again: config/config.php and the database are kept.
#
# Usage, inside DwemerDistro as the dwemer user:
#   git clone https://github.com/yucktheslime/a-trade-of-immersion.git ~/a-trade-of-immersion
#   bash ~/a-trade-of-immersion/kagrenac-server/scripts/deploy_toi.sh
# Later updates:
#   git -C ~/a-trade-of-immersion pull && bash ~/a-trade-of-immersion/kagrenac-server/scripts/deploy_toi.sh
set -euo pipefail

FOLDER="${TOI_SERVER_FOLDER:-TradeOfImmersionServer}"
case "$FOLDER" in
    *[!A-Za-z0-9._-]* | "" | .*) echo "ERROR: bad folder name '$FOLDER'" >&2; exit 1 ;;
esac
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="/var/www/html/custom-mods/$FOLDER"
PORT="$(sed -n 's/^CUSTOM_MODS_PORT=//p' /etc/dwemerdistro_services.conf 2>/dev/null | tr -d '"' | head -n1)"
PORT="${PORT:-19000}"

if [ "$(id -u)" -eq 0 ]; then
    echo "Run this as the dwemer user (not with sudo); it asks for sudo itself." >&2
    exit 1
fi
if [ ! -f "$SRC/dwemer-mod.json" ] || [ ! -f "$SRC/turn.php" ]; then
    echo "ERROR: $SRC does not look like kagrenac-server/." >&2
    exit 1
fi
command -v ddistro_custom_mod >/dev/null 2>&1 || {
    echo "ERROR: ddistro_custom_mod not found. Run 'Update Distro' in the DwemerDistro launcher first." >&2
    exit 1
}

# 1. Web protection for our folder, before any file is copied into the web root.
echo "== Web protection for custom-mods/$FOLDER"
sudo ddistro_custom_mod setup-web --protect-project "$FOLDER"

# 2. Copy the server files. Private files already in the destination are never overwritten.
echo "== Copying server files to $DEST"
sudo install -d -o "$(id -un)" -g "$(id -gn)" "$DEST"
tar -C "$SRC" \
    --exclude='./config/config.php' --exclude='./config/config.php.lock' \
    --exclude='./.git' --exclude='*.env' --exclude='./config.json' \
    -cf - . | tar -C "$DEST" --no-same-owner --no-overwrite-dir -xf -

# 3. Config (new random token only if missing), database, migrations.
echo "== Running install.sh"
sudo bash "$DEST/scripts/install.sh"

# 4. Check.
echo "== Health check"
if curl -fsS "http://127.0.0.1:$PORT/custom-mods/$FOLDER/health.php"; then
    echo
    code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/custom-mods/$FOLDER/config/config.php")"
    if [ "$code" = 403 ] || [ "$code" = 404 ]; then
        echo "OK: config is not served (HTTP $code)."
    else
        echo "WARNING: config/config.php answered HTTP $code. Do not use this server until it is 403." >&2
    fi
    echo
    echo "Dashboard: http://127.0.0.1:$PORT/custom-mods/$FOLDER/"
    echo "Mod config: kagrenacBaseUrl = http://127.0.0.1:$PORT/custom-mods/$FOLDER"
    echo "Pairing token: sudo php -r '\$c = require \"$DEST/config/config.php\"; echo \$c[\"token\"], PHP_EOL;'"
else
    echo "Health check failed. Try: sudo ddistro_custom_mod setup-web  (then run this script again)" >&2
    exit 1
fi
