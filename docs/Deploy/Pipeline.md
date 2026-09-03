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
                   tagg vX.Y.Z vid stängd milstolpe
                        │
                   production.yml      hämtar SAMMA artefakt
                                       rullar ut i produktion
```

Taggen sätts **en gång per stängd milstolpe**, inte när det råkar passa — se § Releaseritualen och [[ADR-0018 Utvecklingsprocess och deploy]] § Befordranstakt.

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

| Secret | Innehåll | Värde |
|---|---|---|
| `DEPLOY_HOST` | serverns värdnamn | `prime5.inleed.net` |
| `DEPLOY_PORT` | SSH-port | `2020` |
| `DEPLOY_USER` | kontonamn hos inleed | `s174280` |
| `DEPLOY_KEY` | privat nyckel, **egen nyckel per miljö** | sätts inte i förväg |
| `DEPLOY_KNOWN_HOSTS` | utdata från `ssh-keyscan -p 2020 prime5.inleed.net` | tre rader: ed25519, rsa, ecdsa |
| `DEPLOY_PATH` | appkatalogen för miljön | `/home/s174280/mimers` respektive `/home/s174280/mimers-staging` |

`production` sätts dessutom upp med **required reviewer: Tony**. Det är den inställningen som gör att GitHub stannar och frågar innan produktionsdeployen kör — se begränsningen nedan.

`DEPLOY_KNOWN_HOSTS` läggs som secret istället för att köra `ssh-keyscan` i workflowen. Att keyscanna vid varje körning är att lita på vem som helst som svarar på adressen. Keyscanna en gång, och **jämför fingeravtrycken mot en uppkoppling du redan litar på** innan du klistrar in dem — annars har du bara flyttat samma godtrogenhet från körningen till uppsättningen.

### Kontoplanen tar bort tre av spärrarna

Repot är privat och orgen ligger på GitHubs **Free**-plan. Där finns tre av mekanismerna i [[ADR-0018 Utvecklingsprocess och deploy]] helt enkelt inte — API:et svarar `Upgrade to GitHub Pro or make this repository public`:

- **branch protection på `main`** — 403
- **repository rulesets** — 403, alltså inte heller vägen runt
- **required reviewer på en environment** — 422

*Environments* och *environment secrets* fungerar däremot, och `environment: production` ger fortfarande en spårbar deployhistorik. Men den stannar inte och frågar.

Konsekvensen är att **"ingen pushar direkt till `main`" och "produktion kräver ett godkännande" är överenskommelser, inte spärrar.** Tre vägar ur det:

1. **Uppgradera orgen till GitHub Team.** Ger tillbaka alla tre, och är det minsta ingreppet i processen som redan är beslutad.
2. **Flytta godkännandet in i workflowen.** Produktionsjobbet körs bara via `workflow_dispatch` med en bekräftelseinput. Svagare, eftersom den som startar körningen också är den som godkänner.
3. **Låt det stå som en överenskommelse** tills det finns fler än en person med skrivrättigheter.

Vilket det än blir ska det vara ett val. Skillnaden mot [[ADR-0018 Utvecklingsprocess och deploy]] får inte bli något man upptäcker den dag någon pushar fel.

## `.github/workflows/ci.yml`

Körs på varje PR. Grön här är förutsättningen för att merge-knappen ska gå att trycka.

```yaml
name: CI

on:
  pull_request:
    branches: [main]

permissions:
  contents: read

concurrency:
  group: ci-${{ github.head_ref }}
  cancel-in-progress: true

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: fileinfo, mbstring, pdo_sqlite, sqlite3, zip
          coverage: none

      - uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: npm

      - name: Installera beroenden
        run: |
          composer install --prefer-dist --no-interaction --no-progress
          npm ci

      # Bygget körs före testerna: en av dem läser public/build/manifest.json
      # och bevisar därmed att artefakten faktiskt produceras.
      - name: Bygg frontend
        run: npm run build

      - name: Kodstandard
        run: composer lint

      - name: Statisk analys
        run: composer analyse

      - name: Tester
        run: |
          cp .env.example .env
          php artisan key:generate
          composer test
```

Stegen anropar composer-skript i stället för binärerna direkt. `composer lint` är `pint --test`, `composer analyse` är `phpstan analyse --no-progress` och `composer test` är `artisan test` — samma kommandon som [[AGENTS.md]] listar, men med ett namn som går att köra likadant lokalt. `composer fix` kör Pint utan `--test` och rättar i stället för att larma.

Extensionlistan i `setup-php` är inte kosmetik. `ext-fileinfo` är ett hårt krav från Flysystem, och testsviten kör SQLite in-memory — utan `pdo_sqlite` och `sqlite3` faller allt, och felmeddelandet pekar inte på orsaken.

Testerna kör mot SQLite in-memory, konfigurerat i `phpunit.xml`. Skiljer sig databasen för mycket från produktion får en `services:`-block med `mariadb:10.6` läggas till — men börja enkelt.

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
          # Arkivet skrivs utanför trädet och flyttas in efteråt. Skrivs det
          # direkt i katalogen det läser ändras "." medan tar läser den, och
          # GNU tar avslutar med kod 1 och "file changed as we read it". Det
          # slår till sporadiskt, beroende på tajmning, så felet ser ut som en
          # flakighet i CI i stället för det det är. Se Pipeline.md § Paketering.
          tar -czf ../release.tar.gz --exclude='./.git' --exclude='./tests' --exclude='./node_modules' .
          mv ../release.tar.gz release.tar.gz

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
          case "$PATH_REMOTE" in
            /*) ;;
            *) echo "DEPLOY_PATH är inte en absolut sökväg. Sätt secreten från PowerShell eller GitHubs webbgränssnitt, inte från Git Bash."; exit 1 ;;
          esac

          ssh -p "$PORT" "$USER@$HOST" "mkdir -p $PATH_REMOTE/incoming && cat > $PATH_REMOTE/incoming/$RELEASE.tar.gz" < release.tar.gz
          ssh -p "$PORT" "$USER@$HOST" "bash -s -- $PATH_REMOTE $RELEASE" < deploy/deploy.sh
```

Frontendbygget körs **här**, inte på servern. `public/build` ligger i arbetskatalogen när `tar` körs och följer därför med i artefakten, medan `node_modules` exkluderas. Servern behöver fortfarande varken git, composer eller node — se [[ADR-0018 Utvecklingsprocess och deploy]] och [[ADR-0021 Frontendteknik]]. Bygger CI inte frontenden på varje PR upptäcks ett trasigt Vue-bygge först vid utrullning, vilket är därför samma steg finns i `ci.yml`.

## Paketering

`tar` skriver arkivet i katalogen ovanför och flyttar in det efteråt. Skrivs det direkt i arbetskatalogen ändras `.` medan tar läser den, och GNU tar avslutar med kod 1:

```
tar: .: file changed as we read it
```

`--exclude='./release.tar.gz'` räcker inte, vilket är hela poängen med att skriva ned det här — det är katalogens mtime som ändras, inte filen som råkar komma med. Det slår till beroende på tajmning: samma steg gick igenom i körningen före den som föll. Ett fel som kommer och går ser ut som en flakighet i CI, och den gissningen kostar mer än raden gör.

## Vägen in på servern

`DEPLOY_PATH` var i tre utrullningar i rad fel, på ett sätt som ingen kunde se. Secreten hade satts från Git Bash, och MSYS skriver om en absolut POSIX-sökväg till en Windows-sökväg med Git-installationen som rot. `/home/s174280/mimers-staging` blev alltså:

```
C:/Users/tony/AppData/Local/Programs/Git/home/s174280/mimers-staging
```

En GitHub-secret går inte att läsa tillbaka, och sökvägen maskeras till `***` i loggen. Felet visade sig därför tre gånger i tre olika förklädnader: först som `scp: dest open "***/...": No such file or directory`, sedan — när ett `mkdir -p` lagts till — som en katalog vid namn `C:` i hemkatalogen, och till sist som

```
tar (child): Cannot connect to C: resolve failed
```

eftersom `tar` tolkar `C:` som ett fjärrvärdnamn.

**Sätt aldrig en secret som innehåller en sökväg från Git Bash.** Använd PowerShell eller GitHubs webbgränssnitt. Det gäller alla `DEPLOY_*`-secrets, inte bara den här.

Steget vägrar därför tidigt om `PATH_REMOTE` inte börjar med `/`:

```bash
case "$PATH_REMOTE" in
  /*) ;;
  *) echo "DEPLOY_PATH är inte en absolut sökväg. Sätt secreten från PowerShell eller GitHubs webbgränssnitt, inte från Git Bash."; exit 1 ;;
esac
```

Poängen är att felet ska ha ett namn. Ett `mkdir -p` utan den kontrollen gör saken sämre, inte bättre: det förvandlar ett tydligt "sökvägen finns inte" till en tyst felaktig katalog som utrullningen sedan skriver 5 MB skräp i.

Paketet skickas med `ssh` och `cat` i stället för `scp`, av ett besläktat skäl: `scp` går sedan OpenSSH 9 över SFTP, som inte har något skal bakom sig och därför varken expanderar `~` eller beter sig som raden efter. `ssh ... "cat > fil" < release.tar.gz` går genom samma skal som `bash -s`-raden. **Skriv inte tillbaka det till `scp`** utan att sätta `-O`.

### Kör `ssh` med `-4`

`mimers.app` och `staging.mimers.app` har **både** en A-post (`185.189.49.45`) och en AAAA-post (`2001:67c:750::30`). Utvecklings-VPS:en har ingen default-route för IPv6, och `ssh` provar AAAA först. Utan `-4` blir felet därför:

```
ssh: connect to host staging.mimers.app port 22: Network is unreachable
```

Det ser ut som att servern är nere. Den är det inte — routen saknas i andra änden av kabeln. Hela raden som fungerar:

```bash
ssh -4 -p 2020 -i ~/.ssh/<nyckel> s174280@prime5.inleed.net
```

Två saker som gör felet svårare än det borde vara:

- **`curl` döljer det.** `curl https://staging.mimers.app/` faller tillbaka till A-posten och svarar 200. En grön `curl` bevisar alltså ingenting om huruvida `ssh` kommer fram.
- **Port 22 timeoutar tyst.** SSH lyssnar på **2020**; ett anrop mot 22 hänger tills `ConnectTimeout` löper ut, vilket är ännu en förklädnad för samma "servern verkar nere".

`DEPLOY_HOST` i utrullningen är värdnamnet, och GitHubs runners har IPv6 — därför har utrullningen aldrig sett det här. Felet finns bara på vägen in från VPS:en.

Verifierat 2026-09-03, när staging skulle tas ur underhållsläge efter issue #136.

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
          case "$PATH_REMOTE" in
            /*) ;;
            *) echo "DEPLOY_PATH är inte en absolut sökväg. Sätt secreten från PowerShell eller GitHubs webbgränssnitt, inte från Git Bash."; exit 1 ;;
          esac

          ssh -p "$PORT" "$USER@$HOST" "mkdir -p $PATH_REMOTE/incoming && cat > $PATH_REMOTE/incoming/$RELEASE.tar.gz" < release.tar.gz
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

# filleverans (issue 19b): bytena ligger i shared/storage/files, webbroten får
# en _protected-symlänk in i dem. Länken läggs per release — en ny
# releasekatalog har ingen — och före flippen av current nedan, så webbroten
# pekar aldrig på en release utan skydd. .htaccess-regeln kopieras från repot
# vid varje utrullning: en handpåläggning på servern skrivs över, och regeln
# kan inte glida isär mellan miljöerna.
mkdir -p "$APP/shared/storage/files"
ln -sfn "$APP/shared/storage/files" "$DIR/public/_protected"
# Skriv via en punktfil i samma katalog och byt med mv: cp trunkerar målet
# först, och ett avbrutet anrop lämnar datakatalogen utan regler medan förra
# releasen servar. mv är en rename inom samma filsystem och därmed atomiskt.
cp "$DIR/deploy/protected.htaccess" "$APP/shared/storage/files/.htaccess.ny"
mv -f "$APP/shared/storage/files/.htaccess.ny" "$APP/shared/storage/files/.htaccess"

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

## Filleverans

Nedladdningsrutten i issue 19a svarar med `X-LiteSpeed-Location: /_protected/…`, och LiteSpeed levererar bytena med `sendfile()` — se [[ADR-0019 Filleverans]]. Katalogen som URI:n pekar på finns inte i repot; den läggs av `deploy.sh` i varje ny release:

```
$DIR/public/_protected                     →  $APP/shared/storage/files   symlänk
$APP/shared/storage/files/.htaccess           från deploy/protected.htaccess
```

`public_html` är redan en symlänk till `current/public` (§ Engångsuppsättning), så webbroten får en pekare in i `shared/storage/files`, där bytena ligger kvar när releasen städas bort. Länken läggs före flippen av `current`; en ny releasekatalog har ingen `_protected`, och ett fönster där webbroten pekar på en release utan den vore en öppen katalog.

`.htaccess`-regeln kopieras från `deploy/protected.htaccess` vid varje utrullning, av samma skäl som uppladdningsgränserna i `public/.htaccess`: den versioneras med koden och kan inte glida isär mellan miljöerna. En handpåläggning på servern skrivs över nästa gång — det är avsikten.

Regeln är en tillåt-lista som nekar direkt åtkomst men tillåter intern omdirigering:

```apache
RewriteEngine On
RewriteCond %{ORG_REQ_URI} !^/files/[A-Za-z0-9]+$
RewriteRule ^ - [F,L]

Options -Indexes
```

`%{ORG_REQ_URI}` håller URI:n från det ursprungliga anropet och ändras inte av LiteSpeeds interna omdirigering: ett direkt anrop mot `/_protected/…` har inte formen `/files/{ulid}` och nekas, medan ett anrop mot `/files/{ulid}` behåller den sökvägen och passerar. Regeln är en tillåt-lista, inte en neka-lista på URI:ns textform — filuppslaget görs på en normaliserad sökväg, så en neka-lista på `^/_protected/` hade missat kringgångar som `//_protected/…` eller `/./_protected/…`. En ULID innehåller varken `/`, `.` eller `%`, så ingen sådan variant kan tillfredsställa villkoret. `Options -Indexes` är bältet utöver hängslet: skulle regeln sluta gälla ska en katalogförfrågan ändå inte räkna upp innehållet.

`FILES_INTERNAL_REDIRECT=true` sätts av Tony i `shared/.env`, en gång per miljö — ingen kod sätter den. Utan den strömmar appen filerna genom PHP med samma headers: allting fungerar, och det enda som märks är att processpoolen tar slut den dag någon laddar ner mycket.

Skyddet bevisas mot en utrullad miljö med `deploy/verifiera-filleverans.sh <bas-url> <ulid>`, som gör fyra anrop med `curl` och avslutar med kod 1 så fort något avviker:

1. `GET /_protected/` → **403**
2. `GET /_protected/ab/cd/<känd hash>` → **403**
3. `GET //_protected/ab/cd/<känd hash>` → **403** (kringgångsform)
4. `GET /files/{ulid}` med en giltig token → **200**, icke-tom kropp, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` och ett `Content-Type` som inte är `text/html`

De tre första anropen kräver ingen inloggning; det fjärde behöver en bilaga som kontot får läsa och ett sanctum personal access token. Bilagan ska inte vara HTML — `text/html` vore ett korrekt svar för en `.html`-bilaga men kan inte skiljas från appens standardsvar. Token sätts hellre i `FILES_TOKEN` än som argument: ett argument syns i `ps` på den delade servern och hamnar i skalhistoriken. Kört mot staging efter merge, med utdata klistrad i PR-tråden (issue 19b § Beslut 7).

## Rollback

```bash
ls -1dt ~/mimers/releases/       # hitta den förra
ln -sfn ~/mimers/releases/2026-08-04-a3f19c ~/mimers/current
ln -sfn ~/mimers/shared/storage/files ~/mimers/current/public/_protected
php ~/mimers/current/artisan up
```

Tio sekunder. **Databasen rullas inte tillbaka** — se expand/contract i [[ADR-0018 Utvecklingsprocess och deploy]].

Raden med `_protected` återskapar symlänken för filleverans: en release som rullades ut före issue 19b saknar `public/_protected`, och utan raden ger samtliga nedladdningar 404 tills nästa deploy — utan att något i proceduren antyder varför. Länken pekar in i `shared/storage/files`, där bytena och `.htaccess` bor.

**Provad på staging 2026-08-23**, med två releaser av samma kod. `current` flippades till föregående release, sajten kontrollerades, och `current` flippades tillbaka. Sajten svarade 200 i alla tre lägena.

Att mäta att flippen faktiskt togs är knepigare än det låter: två releaser av samma kod ger identiska asset-hashar och identisk Inertia-`version`, så utifrån ser de likadana ut. Provet gjordes därför med en fil som bara fanns i den gamla releasens `public/` — den gav 404 före flippen, 200 efter, och 404 igen efter återställningen.

**LiteSpeed följer den omflippade symlänken direkt**, utan omstart och utan cache-rensning. Det var den tysta risken: cachar webbservern den upplösta sökvägen ser en rollback ut att lyckas utan att ha bytt något.

## Releaseritualen

**En release per stängd milstolpe** — takten och skälet står i [[ADR-0018 Utvecklingsprocess och deploy]] § Befordranstakt. Här står bara handgreppen.

Publiceringen är utrullningen: `production.yml` triggas på `release: published`, och produktionsmiljön har **inga protection rules** på nuvarande kontoplan. Det finns alltså inget godkännandesteg mellan `gh release create` och en flippad `current`. Bocka av listan före kommandot, inte efter.

**1. Milstolpen är faktiskt stängd.** Inga öppna issues kvar i den, och den sista är mergad till `main`.

```bash
gh issue list --state open -L 100
```

**2. Staging är grön på den commit du tänker tagga.** Det är inte samma sak som att den senaste körningen är grön — en avbruten eller röd körning på just din commit betyder ingen artefakt att hämta.

```bash
SHA=$(git rev-parse origin/main)
gh run list --workflow staging.yml -L 10 \
  --json databaseId,headSha,conclusion \
  -q "[.[] | select(.headSha==\"$SHA\" and .conclusion==\"success\")][0]"
```

**3. Artefakten finns kvar.** Retentionen är 90 dagar. Är den utgången finns ingenting att befordra — då får en tom commit till `main` bygga om, och taggen peka på den i stället.

```bash
gh api repos/:owner/:repo/actions/runs/RUN_ID/artifacts \
  --jq '.artifacts[] | "\(.name) expired=\(.expired)"'
```

**4. Miljöskillnaderna är genomgångna.** Diffa `.env.example` mot förra taggen och kontrollera att varje ny nyckel antingen har rätt standardvärde i `config/` eller är satt i produktionens `shared/.env`. Det är den enda punkten i listan som inte går att verifiera från GitHub — `shared/.env` finns bara på servern.

```bash
git diff v0.1.0..origin/main -- .env.example
```

**5. Publicera.** Taggen skapas av `gh` på angiven commit; release notes grupperas per milstolpe.

```bash
gh release create v0.2.0 --target "$SHA" \
  --title "v0.2.0 — M4 i produktion" --notes-file notes.md
```

**6. Följ utrullningen och verifiera.** Migrationerna körs av `deploy.sh` med underhållsläge runt sig; loggen är det enda stället de syns.

```bash
gh run list --workflow production.yml -L 1
gh run view RUN_ID --log | grep -E "DONE|Utrullad|error"
curl -sS -H "Accept: application/json" -w "\nHTTP %{http_code}\n" https://mimers.app/api/containers
```

Sista raden ska ge `401 auth.unauthenticated`, inte `404`. En `404` betyder att rutterna är gamla — `current` pekar på fel release, eller `route:cache` kördes mot en halv utrullning.

Går något fel: se § Rollback, och kom ihåg att databasen inte följer med tillbaka.

## Branch protection

**Medvetet uppskjuten 2026-08-23** — kontoplanen tillåter det inte, och Tony valde att inte uppgradera för det. Se § Kontoplanen tar bort tre av spärrarna och [[ADR-0018 Utvecklingsprocess och deploy]] § Spärrarna är uppskjutna. Listan står kvar som specifikation för den dag den går att verkställa, och som beskrivning av vad som gäller på disciplin tills dess.

Under repots *Rules* eller *Branch protection* för `main`:

- kräv pull request före merge
- kräv en godkänd granskning
- kräv att `CI / test` är grön
- kräv att grenen är uppdaterad mot `main` före merge
- blockera force push och radering
- **inkludera administratörer** — annars gäller inget av ovanstående för Tony, och undantaget kommer att användas en sen kväll

## Vad issue 0 ska bevisa

**Avklarat 2026-08-23.** Alla sex punkterna nedan är bevisade; `v0.0.1` gick i produktion samma dag och `https://mimers.app` svarar. Produktionen står sedan 2026-09-03 på `v0.1.0` med M0–M3. Listan står kvar som beskrivning av vad kedjan gör, inte som en checklista att beta av igen.

En tom Laravel, utan en rad domänkod, som:

1. får en PR att bli grön i `ci.yml`
2. hamnar på staging automatiskt vid merge
3. svarar på `https://staging.mimers.app`
4. skeppas till produktion via en release `v0.0.1` med Tonys godkännande — som i dag är en handpåläggning, inte en spärr
5. kan rullas tillbaka med ett symlänkbyte
6. har en fungerande minutcron på båda miljöerna

Kedjan tog fem försök att få igenom första gången. Alla fem felen var av samma sort — sådana som bara syns skarpt — och de står dokumenterade i § Vägen in på servern och § Paketering, eftersom nästa person annars kommer att gissa på flakighet.

## Läget på GitHub och hos inleed

Avstämt 2026-08-23. Allt som går att förbereda innan det finns kod är gjort.

**Klart i repots inställningar**

- miljöerna `staging` och `production` finns, med alla sex `DEPLOY_*`-secrets i båda
- egen deploynyckel per miljö, ed25519, utan lösenfras. Båda verifierade genom en faktisk inloggning innan de sattes som secret. Privatnycklarna finns bara som GitHub-secrets — de går inte att läsa tillbaka, och behövs en ny genereras den om.
- `DEPLOY_KNOWN_HOSTS` innehåller serverns tre värdnycklar, med fingeravtrycken jämförda mot en uppkoppling som redan var betrodd

**Klart hos inleed**

- sajter och DNS för alla tre värdnamn: `staging.mimers.app`, `files.mimers.app` och `files.staging.mimers.app`. Filsubdomänen per miljö behövs för att [[ADR-0019 Filleverans]] ska gå att testa mot en utrullad staging och inte mot produktionens filer.
- webbroten för både `mimers.app` och `staging.mimers.app` är symlänkar till respektive `current/public`. Filsubdomänerna behåller sina riktiga kataloger — de pekas aldrig om, de får bara `_protected`-symlänken.
- `shared/.env` i båda miljöerna, `chmod 600`, med egen genererad `APP_KEY`. De finns bara på servern.
- en databas per miljö — `s174280_mimers` och `s174280_mimers-staging` — verifierade från servern: MariaDB 10.6.27, tomma, med rättigheter att skapa och ta bort tabeller. Kontot hade inget databastak i vägen.
- båda baserna ändrade från `latin1_swedish_ci` till `utf8mb4` / `utf8mb4_unicode_ci`, medan de var tomma. Laravel sätter teckenuppsättning per anslutning och per tabell ändå, men nu kan ingenting ärva fel standard.
- minutcron per miljö, med absolut sökväg till `/usr/local/bin/php`
- **LiteSpeed står framför PHP på samma maskin.** Det finns ingen proxy framför den och ingen lastbalanserare. `REMOTE_ADDR` för ett inkommande anrop är därför den riktiga klienten, inte en proxy, och LSAPI sätter schemat självt — `https://mimers.app` levererade korrekta absoluta URL:er redan i `v0.0.1`, innan `TrustProxies` fanns i kodbasen. Betrodd proxy är alltså loopbacken och ingenting annat: `trustProxies(at: ['127.0.0.1', '::1'])` i `bootstrap/app.php`. Litar appen på fler adresser än så kan vilken klient som helst sätta `X-Forwarded-Host` och styra vilken domän signerade länkar pekar på, och `X-Forwarded-For` och därmed vilken IP rate limiting räknar på. Fastställt i issue 4.

**Saknas**

- **branch protection och required reviewer.** Uppskjutet, inte bortglömt — se § Kontoplanen tar bort tre av spärrarna. Ska inte bockas av; ska tas upp igen om fler än agenten börjar pusha.

### En röjd nyckel, och vad den lärde oss om `authorized_keys`

En privat nyckel, `claude_rsa`, låg kvar i serverns egen `~/.ssh/`, och dess publika halva stod i `authorized_keys` — den låste alltså upp maskinen den låg på. Den hämtades hem, togs bort från servern, och raden ersattes 2026-08-23 av ett nytt nyckelpar. Verifierat: den nya nyckeln loggar in, den röjda raden är borta.

Två saker att ta med sig:

- **`authorized_keys` innehåller numera driftkritiska rader.** `github-actions-staging` och `github-actions-production` är deploykedjans enda väg in. Faller de bort slutar utrullningen fungera — och det märks först vid nästa release, inte när misstaget görs.
- **Redigera därför aldrig filen genom DirectAdmins SSH Keys-sida.** Den skriver om `authorized_keys` i sin helhet. Vid nyckelbytet ovan överlevde deployraderna, men ordningen i filen ändrades, vilket visar att hela filen skrevs om. Lägg till och ta bort additivt över shell, med en backup före.
