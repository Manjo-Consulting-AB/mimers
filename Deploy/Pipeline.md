# Pipeline

Teknisk uppsättning för CI och deploy. **Varför** det ser ut så här står i [[ADR-0018 Utvecklingsprocess och deploy]] — läs den först om du undrar över en avvägning.

Det här dokumentet är underlaget för **issue 0** i [[Backlog]]. Filerna nedan är utgångspunkter, inte facit: sökvägar, domännamn och PHP-version ska anpassas efter hur kontot faktiskt ser ut hos inleed.

Tillbaka till [[00 Index]].

## Överblick

```
gren "issue-11"
   │
   ├─ PR ──────────► ci.yml            lint, analys, tester
   │
   └─ merge till main
        │
        └────────► staging.yml         bygger release.tar.gz
                        │              rullar ut på staging
                        │              artefakten sparas
                        │
                   tagg vX.Y.Z + godkännande
                        │
                   production.yml      hämtar SAMMA artefakt
                                       rullar ut i produktion
```

## Kataloglayout på servern

Identisk på staging och produktion, bara olika konto eller sökväg.

```
~/batparmen/
  incoming/                     inkommande paket, töms efter uppackning
  releases/
    2026-08-04-a3f19c/
    2026-08-11-77bd02/
  shared/
    .env                        skapas för hand, en gång
    storage/                    uppladdade filer, loggar, cache
  current -> releases/2026-08-11-77bd02
```

`shared/` rörs aldrig av en deploy. Det är därför en trasig utrullning inte kan ta med sig kundernas filer.

### Engångsuppsättning

```bash
mkdir -p ~/batparmen/{incoming,releases}
mkdir -p ~/batparmen/shared/storage/{app/public,logs}
mkdir -p ~/batparmen/shared/storage/framework/{cache/data,sessions,views}

# .env skapas här och bara här
nano ~/batparmen/shared/.env

# document root pekas om till releasen
ln -sfn ~/batparmen/current/public ~/domains/batparmen.se/public_html

# schemaläggaren
crontab -e
* * * * * cd ~/batparmen/current && php artisan schedule:run >> /dev/null 2>&1
```

Går det inte att peka om document root får `public_html` istället vara symlänken. Fungerar inte heller det — se frågorna till inleed i [[ADR-0018 Utvecklingsprocess och deploy]].

## Miljöer och secrets i GitHub

Lägg upp två *Environments* i repots inställningar: `staging` och `production`. Varje miljö får egna secrets med samma namn, så att workflow-filerna kan se likadana ut.

| Secret | Innehåll |
|---|---|
| `DEPLOY_HOST` | serverns värdnamn |
| `DEPLOY_PORT` | SSH-port |
| `DEPLOY_USER` | kontonamn hos inleed |
| `DEPLOY_KEY` | privat nyckel, **egen nyckel per miljö** |
| `DEPLOY_KNOWN_HOSTS` | utdata från `ssh-keyscan -p PORT HOST` |
| `DEPLOY_PATH` | t.ex. `/home/tony/batparmen` |

`production` sätts dessutom upp med **required reviewer: Tony**. Det är den inställningen som gör att GitHub stannar och frågar innan produktionsdeployen kör.

`DEPLOY_KNOWN_HOSTS` läggs som secret istället för att köra `ssh-keyscan` i workflowen. Att keyscanna vid varje körning är att lita på vem som helst som svarar på adressen.

## `.github/workflows/ci.yml`

Körs på varje PR. Grön här är förutsättningen för att merge-knappen ska gå att trycka.

```yaml
name: CI

on:
  pull_request:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none

      - name: Installera beroenden
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Kodstandard
        run: vendor/bin/pint --test

      - name: Statisk analys
        run: vendor/bin/phpstan analyse --no-progress

      - name: Tester
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan test
```

Testerna kör mot SQLite in-memory eller en MariaDB-service. Skiljer sig databasen för mycket från produktion får en `services:`-block med `mariadb:10.6` läggas till — men börja enkelt.

## `.github/workflows/staging.yml`

Bygger artefakten och rullar ut den på testmiljön. Körs automatiskt vid varje merge.

```yaml
name: Staging

on:
  push:
    branches: [main]

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Bygg
        run: composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

      - name: Paketera
        run: |
          tar -czf release.tar.gz \
            --exclude='./.git' \
            --exclude='./tests' \
            --exclude='./node_modules' \
            --exclude='./release.tar.gz' \
            .

      - name: Spara artefakten
        uses: actions/upload-artifact@v4
        with:
          name: release-${{ github.sha }}
          path: release.tar.gz
          retention-days: 90

  deploy:
    needs: build
    runs-on: ubuntu-latest
    environment: staging
    steps:
      - uses: actions/checkout@v4

      - uses: actions/download-artifact@v4
        with:
          name: release-${{ github.sha }}

      - name: Rulla ut
        env:
          HOST: ${{ secrets.DEPLOY_HOST }}
          PORT: ${{ secrets.DEPLOY_PORT }}
          USER: ${{ secrets.DEPLOY_USER }}
          PATH_REMOTE: ${{ secrets.DEPLOY_PATH }}
        run: |
          mkdir -p ~/.ssh
          echo "${{ secrets.DEPLOY_KEY }}" > ~/.ssh/id_ed25519
          chmod 600 ~/.ssh/id_ed25519
          echo "${{ secrets.DEPLOY_KNOWN_HOSTS }}" > ~/.ssh/known_hosts

          RELEASE=$(date +%Y-%m-%d)-$(echo "${{ github.sha }}" | cut -c1-7)

          scp -P "$PORT" release.tar.gz "$USER@$HOST:$PATH_REMOTE/incoming/$RELEASE.tar.gz"
          ssh -p "$PORT" "$USER@$HOST" "bash -s -- $PATH_REMOTE $RELEASE" < deploy/deploy.sh
```

`retention-days: 90` är inte kosmetik. Går artefakten ut går det inte längre att skeppa den commiten till produktion utan att bygga om, och då är bygg-en-gång-principen bruten.

## `.github/workflows/production.yml`

Bygger ingenting. Hämtar artefakten som redan testats på staging.

```yaml
name: Produktion

on:
  release:
    types: [published]

jobs:
  deploy:
    runs-on: ubuntu-latest
    environment: production
    steps:
      - uses: actions/checkout@v4

      - name: Hämta artefakten som testades på staging
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          SHA=$(git rev-parse HEAD)
          RUN=$(gh run list --commit "$SHA" --workflow staging.yml \
                  --status success --limit 1 --json databaseId -q '.[0].databaseId')
          if [ -z "$RUN" ]; then
            echo "Ingen grön staging-körning för $SHA. Taggen pekar på en commit som aldrig rullats ut på staging."
            exit 1
          fi
          gh run download "$RUN" -n "release-$SHA"

      - name: Rulla ut
        env:
          HOST: ${{ secrets.DEPLOY_HOST }}
          PORT: ${{ secrets.DEPLOY_PORT }}
          USER: ${{ secrets.DEPLOY_USER }}
          PATH_REMOTE: ${{ secrets.DEPLOY_PATH }}
        run: |
          mkdir -p ~/.ssh
          echo "${{ secrets.DEPLOY_KEY }}" > ~/.ssh/id_ed25519
          chmod 600 ~/.ssh/id_ed25519
          echo "${{ secrets.DEPLOY_KNOWN_HOSTS }}" > ~/.ssh/known_hosts

          RELEASE=${{ github.event.release.tag_name }}-$(date +%Y%m%d%H%M)

          scp -P "$PORT" release.tar.gz "$USER@$HOST:$PATH_REMOTE/incoming/$RELEASE.tar.gz"
          ssh -p "$PORT" "$USER@$HOST" "bash -s -- $PATH_REMOTE $RELEASE" < deploy/deploy.sh
```

Kontrollen i mitten är värd att lägga märke till: **taggar man en commit som aldrig varit grön på staging avbryts deployen.** Utan den raden är hela resonemanget i ADR-0018 bara en överenskommelse.

## `deploy/deploy.sh`

Körs på servern. Ligger i repot så att den versioneras med koden.

```bash
#!/usr/bin/env bash
set -euo pipefail

APP="$1"          # t.ex. /home/tony/batparmen
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
```

Ordningen är medveten:

- **Cacherna byggs före underhållsläget.** Det tunga arbetet sker medan siten fortfarande svarar.
- **`down` körs på den gamla releasen.** Underhållsflaggan hamnar i `shared/storage` och gäller därför båda — det är just därför `storage` är delad.
- **Migrationerna körs innan flippen**, medan ingen trafik finns. Med expand/contract tål den gamla koden det nya schemat, så ordningen är säker även om något går fel.
- **`ln -sfn` är atomiskt.** Det finns inget ögonblick där `current` pekar på ingenting.

## Rollback

```bash
ls -1dt ~/batparmen/releases/       # hitta den förra
ln -sfn ~/batparmen/releases/2026-08-04-a3f19c ~/batparmen/current
php ~/batparmen/current/artisan up
```

Tio sekunder. **Databasen rullas inte tillbaka** — se expand/contract i [[ADR-0018 Utvecklingsprocess och deploy]].

## Branch protection

Under repots *Rules* eller *Branch protection* för `main`:

- kräv pull request före merge
- kräv en godkänd granskning
- kräv att `CI / test` är grön
- kräv att grenen är uppdaterad mot `main` före merge
- blockera force push och radering
- **inkludera administratörer** — annars gäller inget av ovanstående för Tony, och undantaget kommer att användas en sen kväll

## Vad issue 0 ska bevisa

En tom Laravel, utan en rad domänkod, som:

1. får en PR att bli grön i `ci.yml`
2. hamnar på staging automatiskt vid merge
3. svarar på `https://staging.batparmen.se`
4. skeppas till produktion via en release `v0.0.1` med Tonys godkännande
5. kan rullas tillbaka med ett symlänkbyte
6. har en fungerande minutcron på båda miljöerna

Först när alla sex punkterna stämmer börjar issue 1.
