# Pipeline

Teknisk uppsättning för CI och deploy. **Varför** det ser ut så här står i [[ADR-0018 Utvecklingsprocess och deploy]] — läs den först om du undrar över en avvägning.

Det här dokumentet är underlaget för **issue 0** i [[Backlog]]. Sökvägar och versioner nedan är **verifierade på servern 2026-08-23**, inte gissningar — se [[ADR-0018 Utvecklingsprocess och deploy]] § Verifierat hos inleed för vad som testades och hur.

Produkten heter Mimers och domänen är `mimers.app` — se [[ADR-0020 Plattformsidentitet och frontendgräns]]. Kontot är `s174280` på `prime5.inleed.net`, SSH-port **2020**. Produktionen bor i `~/mimers` och svarar på `mimers.app`; staging bor i `~/mimers-staging` och ska svara på `staging.mimers.app`. Användarfilerna på `files.mimers.app`. Båda miljöerna ligger alltså på samma konto och hålls isär av `DEPLOY_PATH`, aldrig av att dela katalog.

Eftersom `.app` är HSTS-preloadad måste alla tre värdnamnen ha certifikat innan de svarar alls; det finns ingen HTTP-fallback att felsöka mot.

Webbfrontenden bor i samma Laravel-app enligt [[ADR-0021 Frontendteknik]], så pipelinen bygger både PHP-beroenden och frontend-assets i CI och skeppar dem i samma artefakt.

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
~/mimers/
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

Gjord för båda miljöerna 2026-08-23. Står här för att kunna göras om på en ny server.

```bash
for APP in ~/mimers ~/mimers-staging; do
  mkdir -p "$APP"/{incoming,releases}
  mkdir -p "$APP"/shared/storage/{app/public,logs}
  mkdir -p "$APP"/shared/storage/framework/{cache/data,sessions,views}
done

# .env skapas här och bara här, en per miljö. Återstår.
nano ~/mimers/shared/.env
nano ~/mimers-staging/shared/.env

# document root: public_html ersätts av en symlänk in i releasen
mv ~/domains/mimers.app/public_html ~/domains/mimers.app/public_html.orig-placeholder
ln -s /home/s174280/mimers/current/public ~/domains/mimers.app/public_html

# schemaläggaren, en rad per miljö
crontab -e
* * * * * cd /home/s174280/mimers/current && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/s174280/mimers-staging/current && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

**Symlänken fungerar** — LiteSpeed följer den genom `current` hela vägen till releasen. Testat med en attrapprelease på `mimers.app`, som just nu svarar 503 från den i väntan på första riktiga utrullningen.

**Cron är per konto, inte per site.** Det är en enda crontab och den delas med övriga domäner på kontot. Använd absolut sökväg till PHP: crontabens egen `PATH` börjar med `/usr/local/php81/bin`, så ett naket `php` blir fel version. `/usr/local/bin/php` är 8.4.

**Uppgifter som schemaläggs måste vara `->call()` eller `->job()`.** `proc_open` är avstängt, så `->command(...)` fungerar inte. Se [[ADR-0018 Utvecklingsprocess och deploy]].

## Repo och organisation

Koden bor i **`Manjo-Consulting-AB/mimers`**. Orgen bär bolagsnamnet och får ett repo per app; produktidentiteten sitter i domänen, inte i org-sluggen. En egen org per app hade inte gett någon ytterligare avskärmning — secrets, environments och branch protection är per repo — men hade dubblat det som faktiskt administreras på org-nivå: 2FA-policy, medlemmar, rulesets och app-installationer.

Dokumentationen låg först i `yachting-earth/storage` och förs över med `Transfer ownership`, inte som en kopia: ADR-historiken och PR-diskussionerna är en del av värdet och följer inte med en filkopiering.

**Det som inte följer med en flytt eller ett nytt repo** och alltså måste läggas upp igen:

- branch protection på `main`, inklusive **inkludera administratörer**
- de två miljöerna och deras `DEPLOY_*`-secrets
- required reviewer på `production`
- installationen av GitHub-appen på orgen, med åtkomst till repot

## Miljöer och secrets i GitHub

Lägg upp två *Environments* i repots inställningar: `staging` och `production`. Varje miljö får egna secrets med samma namn, så att workflow-filerna kan se likadana ut.

| Secret | Innehåll |
|---|---|
| `DEPLOY_HOST` | serverns värdnamn |
| `DEPLOY_PORT` | SSH-port |
| `DEPLOY_USER` | kontonamn hos inleed |
| `DEPLOY_KEY` | privat nyckel, **egen nyckel per miljö** |
| `DEPLOY_KNOWN_HOSTS` | utdata från `ssh-keyscan -p PORT HOST` |
| `DEPLOY_PATH` | t.ex. `/home/tony/mimers` |

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
          php-version: '8.4'
          coverage: none

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm

      - name: Installera beroenden
        run: |
          composer install --prefer-dist --no-interaction --no-progress
          npm ci

      - name: Bygg frontend
        run: npm run build

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
          php-version: '8.4'

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm

      - name: Bygg backend
        run: composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

      - name: Bygg frontend
        run: |
          npm ci
          npm run build

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

Frontendbygget körs **här**, inte på servern. `public/build` ligger i arbetskatalogen när `tar` körs och följer därför med i artefakten, medan `node_modules` exkluderas. Servern behöver fortfarande varken git, composer eller node — se [[ADR-0018 Utvecklingsprocess och deploy]] och [[ADR-0021 Frontendteknik]]. Bygger CI inte frontenden på varje PR upptäcks ett trasigt Vue-bygge först vid utrullning, vilket är därför samma steg finns i `ci.yml`.

## Uppladdningsgränser

Serverns standard är `upload_max_filesize = 2M` och `post_max_size = 8M`, vilket är meningslöst för en produkt som samlar manualer och kvitton. `php_value` i `.htaccess` slår igenom hos inleed — verifierat 2026-08-23 — och `public/.htaccess` följer med i artefakten. Gränserna bor därför **i repot**, inte som handpåläggning på servern:

```apache
php_value upload_max_filesize 64M
php_value post_max_size 72M
php_value memory_limit 256M
```

`.user.ini` fungerar också i princip, men slog inte igenom inom `user_ini.cache_ttl` på 300 sekunder vid testet. `.htaccess` gäller direkt och är därför valet.

Behöver gränsen höjas över vad `.htaccess` tillåter finns CloudLinuxs PHP Selector i DirectAdmin-panelen som andra väg.

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
ls -1dt ~/mimers/releases/       # hitta den förra
ln -sfn ~/mimers/releases/2026-08-04-a3f19c ~/mimers/current
php ~/mimers/current/artisan up
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
3. svarar på `https://staging.mimers.app`
4. skeppas till produktion via en release `v0.0.1` med Tonys godkännande
5. kan rullas tillbaka med ett symlänkbyte
6. har en fungerande minutcron på båda miljöerna

Först när alla sex punkterna stämmer börjar issue 1.

## Återstår på GitHub och hos inleed

Serverupplägget är gjort. Det här är vad som saknas innan genomlöpet ovan kan köras, och inget av det går att lägga i en PR:

**I repots inställningar**

- branch protection på `main` enligt avsnittet ovan, inklusive **inkludera administratörer**. Required status check `CI / test` slås på först när issue 1 är inne — dessförinnan är CI nödvändigtvis röd, eftersom det inte finns någon `composer.json` att installera.
- de två miljöerna med sina `DEPLOY_*`-secrets, egen nyckel per miljö
- required reviewer på `production`

**Hos inleed**

- `staging.mimers.app` och `files.mimers.app` som sites, med DNS. Ingen av dem finns ännu; `.app` är HSTS-preloadad, så de måste ha certifikat innan de svarar alls.
- `shared/.env` per miljö, skapad för hand på servern och ingen annanstans
- en databas per miljö. Kontots databastak går inte att läsa över SSH — kontrollera i DirectAdmin-panelen att två till ryms.
- städa bort `~/domains/mimers.app/public_html.orig-placeholder` och attrappreleasen `~/mimers/releases/0000-00-00-attrapp` när första riktiga utrullningen har gått igenom
