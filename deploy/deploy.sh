#!/usr/bin/env bash
#
# Körs på servern, matad över SSH från GitHub Actions:
#   ssh HOST "bash -s -- /home/tony/mimers 2026-08-11-77bd02" < deploy/deploy.sh
#
# Motivering och kataloglayout: docs/Deploy/Pipeline.md
set -euo pipefail

APP="$1"          # t.ex. /home/tony/mimers
RELEASE="$2"      # katalognamn för den nya releasen
KEEP=5

DIR="$APP/releases/$RELEASE"

mkdir -p "$DIR"
tar -xzf "$APP/incoming/$RELEASE.tar.gz" -C "$DIR"
rm "$APP/incoming/$RELEASE.tar.gz"

# delade resurser in i releasen
ln -sfn "$APP/shared/.env" "$DIR/.env"
rm -rf "$DIR/storage"
ln -sfn "$APP/shared/storage" "$DIR/storage"

# filleverans (issue 19b): bytena ligger i shared/storage/files, webbroten får
# en _protected-symlänk in i dem. Länken läggs per release — en ny
# releasekatalog har ingen — och före flippen av current nedan, så webbroten
# pekar aldrig på en release utan skydd. .htaccess-regeln kopieras från repot
# vid varje utrullning: en handpåläggning på servern skrivs över, och regeln
# kan inte glida isär mellan miljöerna.
mkdir -p "$APP/shared/storage/files"
ln -sfn "$APP/shared/storage/files" "$DIR/public/_protected"
cp "$DIR/deploy/protected.htaccess" "$APP/shared/storage/files/.htaccess"

cd "$DIR"
php artisan config:cache
php artisan route:cache
php artisan view:cache

# underhållsläge på den nuvarande releasen
if [ -L "$APP/current" ]; then
  php "$APP/current/artisan" down --retry=30 || true
fi

php artisan migrate --force

ln -sfn "$DIR" "$APP/current"
php "$APP/current/artisan" up

# städa
cd "$APP/releases"
ls -1dt */ | tail -n +$((KEEP + 1)) | xargs -r rm -rf

echo "Utrullad: $RELEASE"
