#!/bin/sh
# Builds the signed App Store release from the committed code:
#   dist/quickbook-<version>.tar.gz  with appinfo/signature.json (code signing)
#   dist/quickbook-<version>.tar.gz.sha256
#   dist/quickbook-<version>.tar.gz.sig  (paste into the App Store release form)
# Needs the App Store key and certificate in ~/.nextcloud/certificates/ (or CERTS=...)
# and the running dev environment (make dev): occ there does the code signing.
set -eu
cd "$(dirname "$0")/.."

APP=quickbook
CERTS=${CERTS:-$HOME/.nextcloud/certificates}
KEY=$CERTS/$APP.key
CRT=$CERTS/$APP.crt
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' $APP/appinfo/info.xml)
OUT=dist/$APP-$VERSION.tar.gz

[ -f "$KEY" ] && [ -f "$CRT" ] || { echo "Missing $KEY or $CRT" >&2; exit 1; }
[ "$(openssl x509 -in "$CRT" -noout -pubkey)" = "$(openssl pkey -in "$KEY" -pubout)" ] \
	|| { echo "$CRT does not belong to $KEY" >&2; exit 1; }
[ -z "$(git status --porcelain -- $APP)" ] || { echo "Commit $APP/ first: the release is built from git" >&2; exit 1; }
CID=$(docker compose -f dev/compose.yaml ps -q nextcloud)
[ -n "$CID" ] || { echo "Start the dev environment first: make dev" >&2; exit 1; }

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"; docker exec "$CID" rm -rf /tmp/qb-sign' EXIT
git archive HEAD $APP | tar -x -C "$STAGE"

# The key only exists in the local dev container while signing
docker exec "$CID" mkdir -p /tmp/qb-sign
docker cp -q "$STAGE/$APP" "$CID:/tmp/qb-sign/$APP"
docker cp -q "$KEY" "$CID:/tmp/qb-sign/app.key"
docker cp -q "$CRT" "$CID:/tmp/qb-sign/app.crt"
docker exec "$CID" chown -R www-data:www-data /tmp/qb-sign
docker exec -u www-data "$CID" php occ integrity:sign-app \
	--privateKey=/tmp/qb-sign/app.key --certificate=/tmp/qb-sign/app.crt --path=/tmp/qb-sign/$APP
docker cp -q "$CID:/tmp/qb-sign/$APP/appinfo/signature.json" "$STAGE/$APP/appinfo/signature.json"

mkdir -p dist
tar --sort=name --owner=0 --group=0 --numeric-owner --mode='u+rwX,go+rX,go-w' \
	-C "$STAGE" -czf "$OUT" $APP
(cd dist && sha256sum "$(basename "$OUT")" > "$(basename "$OUT").sha256")
openssl dgst -sha512 -sign "$KEY" "$OUT" | openssl base64 > "$OUT.sig"

# Check the archive signature the way the App Store does
openssl base64 -d -in "$OUT.sig" > "$STAGE/sig.bin"
openssl x509 -in "$CRT" -noout -pubkey > "$STAGE/pub.pem"
openssl dgst -sha512 -verify "$STAGE/pub.pem" -signature "$STAGE/sig.bin" "$OUT" >/dev/null

echo "Built $OUT (signed), $OUT.sha256 and $OUT.sig"
