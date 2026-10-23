#!/usr/bin/env bash
# Starts the dev environment and prepares Nextcloud: Calendar app, Quick Booking, mail, test user.
set -euo pipefail
cd "$(dirname "$0")"

occ() { docker compose exec -T -u www-data nextcloud php occ "$@"; }

# Random admin password in .env (read automatically by docker compose, kept out of version control)
[ -f .env ] || (umask 077; echo "QB_ADMIN_PASSWORD=$(head -c 18 /dev/urandom | base64 | tr -d '/+=')" > .env)
QB_ADMIN_PASSWORD="$(sed -n 's/^QB_ADMIN_PASSWORD=//p' .env)"

docker compose up -d

echo "Waiting for Nextcloud to finish installing..."
until occ status --output=json 2>/dev/null | grep -q '"installed":true'; do sleep 3; done

# The bind mount of quickbook makes custom_apps root-owned; app installs need it writable
docker compose exec -T nextcloud chown www-data:www-data /var/www/html/custom_apps

occ app:install calendar || occ app:enable calendar
occ app:enable quickbook
occ app:disable firstrunwizard || true
occ config:system:set mail_smtpmode --value=smtp
occ config:system:set mail_smtphost --value=mailpit
occ config:system:set mail_smtpport --value=1025 --type=integer
occ config:system:set mail_from_address --value=nextcloud
occ config:system:set mail_domain --value=example.test
occ config:system:set debug --value=true --type=boolean
occ user:setting admin settings email admin@example.test
occ user:setting admin core timezone Europe/Amsterdam

echo
echo "Ready:"
echo "  Nextcloud  http://localhost:8080  (admin / $QB_ADMIN_PASSWORD)"
echo "  Mailpit    http://localhost:8025"
echo "Create an appointment schedule in Calendar, then open"
echo "  Settings > Personal > Quick Booking  for the booking links."
