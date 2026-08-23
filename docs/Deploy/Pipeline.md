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

**Medvetet uppskjuten 2026-08-23** — kontoplanen tillåter det inte, och Tony valde att inte uppgradera för det. Se § Kontoplanen tar bort tre av spärrarna och [[ADR-0018 Utvecklingsprocess och deploy]] § Spärrarna är uppskjutna. Listan står kvar som specifikation för den dag den går att verkställa, och som beskrivning av vad som gäller på disciplin tills dess.

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
4. skeppas till produktion via en release `v0.0.1` med Tonys godkännande — som i dag är en handpåläggning, inte en spärr
5. kan rullas tillbaka med ett symlänkbyte
6. har en fungerande minutcron på båda miljöerna

Först när alla sex punkterna stämmer börjar issue 1.

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

**Saknas**

- **branch protection och required reviewer.** Uppskjutet, inte bortglömt — se § Kontoplanen tar bort tre av spärrarna. Ska inte bockas av; ska tas upp igen om fler än agenten börjar pusha.
- **själva genomlöpet.** Kedjan är obeprövad tills en tom Laravel gått hela vägen; se § Vad issue 0 ska bevisa.
- städa bort `~/domains/mimers.app/public_html.orig-placeholder`, `~/domains/staging.mimers.app/public_html.orig-placeholder` och attrappreleasen `~/mimers/releases/0000-00-00-attrapp` när första riktiga utrullningen har gått igenom

### En röjd nyckel, och vad den lärde oss om `authorized_keys`

En privat nyckel, `claude_rsa`, låg kvar i serverns egen `~/.ssh/`, och dess publika halva stod i `authorized_keys` — den låste alltså upp maskinen den låg på. Den hämtades hem, togs bort från servern, och raden ersattes 2026-08-23 av ett nytt nyckelpar. Verifierat: den nya nyckeln loggar in, den röjda raden är borta.

Två saker att ta med sig:

- **`authorized_keys` innehåller numera driftkritiska rader.** `github-actions-staging` och `github-actions-production` är deploykedjans enda väg in. Faller de bort slutar utrullningen fungera — och det märks först vid nästa release, inte när misstaget görs.
- **Redigera därför aldrig filen genom DirectAdmins SSH Keys-sida.** Den skriver om `authorized_keys` i sin helhet. Vid nyckelbytet ovan överlevde deployraderna, men ordningen i filen ändrades, vilket visar att hela filen skrevs om. Lägg till och ta bort additivt över shell, med en backup före.
