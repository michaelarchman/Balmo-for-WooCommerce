#!/usr/bin/env bash
# Build an installable plugin zip into dist/ (folder name = plugin slug).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="balmo-for-woocommerce"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/balmo.php" | tr -d '[:space:]')"
[ -n "$VERSION" ] || { echo "Plugin version not found in balmo.php" >&2; exit 1; }

DIST="$ROOT/dist"
ZIP="$DIST/$SLUG-$VERSION.zip"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$SLUG"
cp "$ROOT/balmo.php" "$STAGE/$SLUG/"
cp -R "$ROOT/includes" "$ROOT/assets" "$STAGE/$SLUG/"
find "$STAGE" -name '.DS_Store' -delete

mkdir -p "$DIST"
rm -f "$ZIP"
(cd "$STAGE" && zip -qr "$ZIP" "$SLUG")
echo "Built $ZIP"
