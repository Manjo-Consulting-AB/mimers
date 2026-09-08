# Återläsning

Att läsa tillbaka en backup är sista utvägen, inte första. Den här runbooken är skriven för någon som ska återställa under press — Tony, eller en agent klockan två på natten — och den börjar därför med det som nästan alltid är rätt svar: papperskorgen. Scenarierna kommer i den ordning de faktiskt inträffar, var och en med kommandon att klistra in och en tydlig punkt där det är klart. Längst ner ligger installationsstegen för hela driftkedjan — 42a, 42b, 43 och återläsningstestet — som är den enda plats i valvet där de står samlade.

Kedjan i korthet: produktionsservern hos inleed dumpas dagligen och synkas veckovis till utvecklings-VPS:en via `deploy/drift/mimers-backup.sh` och `deploy/drift/hamta-backup.sh`, `deploy/drift/vakt.sh` larmar när något blir tyst, och `deploy/drift/aterlasningstest.sh` bevisar kvartalsvis att dumparna går att läsa tillbaka. Besluten bakom finns i [[ADR-0015 Backup]] och [[ADR-0008 Soft delete och papperskorg]]; miljöer och utrullning i övrigt står i [[Pipeline]].

## Börja i papperskorgen

> Har en användare tappat ett item, en container eller en bilaga — gå till papperskorgen först. Nästan varje "jag har tappat data"-ärende löses där, utan att backupen rörs ([[ADR-0008 Soft delete och papperskorg]]). Retentionen står i `config/files.php`. Gå vidare i det här dokumentet **först** när innehållet är hårdraderat, äldre än retentionen, eller när databasen eller filerna är trasiga på riktigt.

En runbook som lockar till en återläsning som inte behövdes är farligare än ingen alls: en återläsning väcker också data som någon bett om att få raderad (se avsnittet om att tillämpa raderingarna igen). Soft delete gör det tunga jobbet, och först när retentionen har passerat och innehållet gallrats är backupen rätt verktyg.

## Scenario A — en rad är hårdraderad och behövs tillbaka

Innehållet har gallrats ur papperskorgen, eller en dålig migration har raderat rader på riktigt. Då finns raderna kvar i backupen — men **aldrig** en hel återläsning för en rad. Läs i stället in dumpen i en skräpdatabas på VPS:en, plocka ut raderna där, och för in dem i produktion i ordning efter främmande nycklar.

```bash
# På VPS:en, där restic och en lokal MariaDB finns. Skräpdatabasens namn måste
# börja med mimers_restore_test — samma skydd som aterlasningstestet bär.
SKRAPDB="mimers_restore_test_manuell"

# 1. Senaste dumpen till en fil och in i skräpdatabasen
restic dump --tag dump latest /mimers-production.sql > /tmp/aterlasning.sql
mysql -e "CREATE DATABASE $SKRAPDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql "$SKRAPDB" < /tmp/aterlasning.sql

# 2. Hitta raden. Ta med föräldrarna: en item behöver sin container, sina
#    attachments och sina taggar — allt en främmande nyckel pekar på.
mysql -N -B "$SKRAPDB" -e "SELECT * FROM item WHERE ulid = '…'"
```

```bash
# 3. För ut raderna ur skräpdatabasen, utan att skapa om tabellerna. Ordningen
#    i mysqldump-anropet är insättningsordningen: föräldrar före barn.
mysqldump --no-create-info --skip-add-locks --where="ulid = '…'" \
  mimers_restore_test_manuell container item > /tmp/rader.sql

# 4. För in dem i produktionsdatabasen. Körs på produktionsservern, där
#    uppgifterna står i ~/mimers/shared/.env — se Scenario B för mönstret
#    med en --defaults-extra-file. Raderna måste komma i samma ordning som
#    ovan: containern först, sedan item.
mysql --defaults-extra-file=<tillfällig auth-fil> <databasnamn ur .env> < /tmp/rader.sql
```

**Klar när:** raden syns för användaren igen, och en återläsning av hela databasen har inte gjorts. Är fler än några rader borta, eller går det inte att reda ut vad som pekar på vad — gå till Scenario B.

## Scenario B — databasen i produktion är trasig

Databasen svarar inte, är korrupt, eller har fått en migration som förstörde data. Hela produktionsdatabasen återläsas från den senaste dumpen. Återläsningen sker på produktionsservern hos inleed, där `shared/.env` och databasuppgifterna finns.

```bash
# På produktionsservern hos inleed
php ~/mimers/current/artisan down --retry=300

# 1. För den senaste dumpen från VPS:en till servern — med en nyckel som kan
#    skriva till servern. Backupnyckeln i Installationsavsnittet kan bara läsa
#    (command=-låst till mimers-backup), så den duger inte här.
#    På VPS:en:   restic dump --tag dump latest /mimers-production.sql > dump.sql
#    Till servern: scp/rsync med den personliga nyckeln.

# 2. Läs in dumpen i produktionsdatabasen. Uppgifterna (DB_DATABASE, DB_USERNAME,
#    DB_PASSWORD) läses ur ~/mimers/shared/.env; exemplet bygger en
#    --defaults-extra-file som i deploy/drift/mimers-backup.sh — lösenordet
#    aldrig på en kommandorad.
mysql --defaults-extra-file=<tillfällig auth-fil> <databasnamn ur .env> < /tmp/dump.sql

# 3. För schemat fram till den kod som står i current. En dump är gårdagens
#    schema: migreringar som gjorts efter dumpen ligger inte i den.
php ~/mimers/current/artisan migrate --force

# 4. Kontrollera innan du öppnar: inloggning fungerar, en container syns, en
#    fil laddas ner, /api-containers svarar 401 (inte 404).
php ~/mimers/current/artisan up
```

**Migreringar rullas aldrig tillbaka i produktion.** Databasen följer inte med vid en kodrollback — se [[Pipeline]] § Rollback och expand/contract i [[ADR-0018 Utvecklingsprocess och deploy]]. Går en återläsning till en *äldre* dump än den kod som står i `current` är vägen framåt att migrera framåt igen, inte att backa koden.

**Klar när:** `artisan up` har kört, sajten svarar 200, och en användare kan logga in och se sitt innehåll. Glöm inte avsnittet om att tillämpa raderingarna igen.

## Scenario C — filer saknas

Filerna ligger i `shared/storage/files` hos inleed och är innehållsadresserade, alltså **oföränderliga**: en fil som saknas har aldrig funnits under den sökvägen, och en återläsning kan aldrig skriva över något nyare. Synka tillbaka de sökvägarna från VPS:ens kopia.

```bash
# Från VPS:en till produktionsservern. FILES_DIR är den synkade kopian på
# VPS:en (nyckeln i backup.env). Utan --delete, av samma skäl som i
# hamta-backup.sh: en återläsning ska aldrig kunna radera.
rsync -a "$FILES_DIR/" s174280@prime5.inleed.net:~/mimers/shared/storage/files/
```

**Klar när:** en känd fils URL svarar 200 igen:

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://mimers.app/files/<ulid>
```

## Scenario D — hela kontot hos inleed är borta

Det värsta fallet: kontot finns inte kvar, och med det sajten, databasen, filerna och `shared/.env`. Allt byggs upp på nytt, och backupen är enda källan. Ordningen spelar roll — följ stegen.

1. **Sätt upp en ny miljö** enligt [[Pipeline]] § Engångsuppsättning: katalogerna, databasen med rättigheter att skapa tabeller, och cron-raden för schemaläggaren. Installera sedan `~/bin/mimers-backup` och lägg tillbaka backupnyckeln enligt Installationsavsnittet nedan — gör det *före* återläsningen, så att kedjan är på plats när databasen finns igen.
2. **Återskapa `~/mimers/shared/.env`**: databasuppgifterna för den nya databasen, `DRIFT_TOKEN`, och en `APP_KEY`. Se rutan om `APP_KEY` nedan.
3. **Läs in senaste dumpen** och synka tillbaka filerna — kommandona i Scenario B och C, mot den nya servern.
4. **DNS**: peka `mimers.app` (och `files.mimers.app`) mot den nya servern om adressen ändrats. Verifiera att sajten svarar 200 och att ytan `GET /drift/heartbeat` svarar 404 utan token — då är `DRIFT_TOKEN` satt.

### Om APP_KEY är borta

`APP_KEY` finns **bara** på servern ([[Pipeline]] § Läget på GitHub och hos inleed) och ligger **inte** i backupen. Två kolumner är krypterade med den — `user.totp_secret` och `webhook_endpoint.secret` — så en återläsning med en ny `APP_KEY` ger en databas där tvåfaktorsinloggning och webhook-signaturer är obrukbara. Ett dokument som tiger om det låter någon återställa i tron att allt kom tillbaka, så var tydlig med både beskedet och åtgärden:

- **Finns den gamla `APP_KEY`** i lösenordshanteraren (se Var lösenorden ska finnas) — skriv in den i `.env` och allt fungerar som förut.
- **Saknas den** — generera en ny (`php artisan key:generate`), och kör sedan:

```sql
-- Med en ny APP_KEY går de gamla värdena inte att läsa. Nollställ tvåfaktorn
-- så att användarna registrerar om den, och stäng av endpointerna så att de
-- skapas om (secret går inte att återställa i efterhand).
UPDATE user SET totp_secret = NULL WHERE totp_secret IS NOT NULL;
UPDATE webhook_endpoint SET is_active = 0 WHERE is_active = 1;
```

**Klar när:** sajten svarar 200, en gammal container och dess filer syns, vakten är grön igen (backupnyckeln och cron-raden är på plats), och nästa kvartals återläsningstest går grönt.

## Efter varje återläsning: tillämpa raderingarna igen

Raderad användardata lever kvar i backuper (sista konsekvenspunkten i [[ADR-0015 Backup]]), och en återläsning återuppväcker den. Data som en användare bett om att få raderad ska inte komma tillbaka för att någon återställde en disk. Direkt efter varje återläsning:

1. **Mjukraderade rader vars `deleted_at` passerat retentionen** — de gallras av den schemalagda gallringen (`routes/console.php`), men direkt efter en återläsning ska de bort igen utan att vänta på nästa körning. Retentionen står i `config/files.php`; exemplet nedan använder 30 dagar.
2. **Konton som raderats efter dumpens tidpunkt** — en hårt raderad rad finns inte i dumpen, så en återläsning väcker kontot till liv igen. Stäm av mot era raderingsärenden och ta bort kontona igen.

```sql
-- Körs mot produktionsdatabasen. Kontrollen är en räkning: blir den inte noll
-- finns det arbete kvar (eller ett ärende att stämma av mot).
SELECT 'container' AS tabell, COUNT(*) AS antal FROM container
  WHERE deleted_at IS NOT NULL AND deleted_at < UTC_TIMESTAMP() - INTERVAL 30 DAY
UNION ALL SELECT 'item', COUNT(*) FROM item
  WHERE deleted_at IS NOT NULL AND deleted_at < UTC_TIMESTAMP() - INTERVAL 30 DAY
UNION ALL SELECT 'attachment', COUNT(*) FROM attachment
  WHERE deleted_at IS NOT NULL AND deleted_at < UTC_TIMESTAMP() - INTERVAL 30 DAY;
```

Blir räkningen inte noll, och det inte rör sig om ett pågående ärende — ta bort raderna på samma sätt som gallringen gör, eller låt nästa schemalagda körning ta dem.

## Installation av driftkedjan

De tre skripten från 42 och 43 samt återläsningstestet installeras för hand — inget av dem installeras av `deploy/deploy.sh` (skälet står i `deploy/drift/mimers-backup.sh` och [[ADR-0029 Agentens läsåtkomst till servern]]). Det här avsnittet är den enda plats där hela kedjan står samlad. Följ det när VPS:en eller kontot hos inleed ska sättas upp på nytt.

**1. Nyckelparet.** Tony genererar det på VPS:en; privathalvan lämnar aldrig VPS:en.

```bash
# På utvecklings-VPS:en
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_mimers-backup -N '' -C 'mimers-backup'
```

**2. `~/bin/mimers-backup` och raden i `authorized_keys`** — på produktionsservern hos inleed. Raden läggs till additivt, med en backup före, aldrig genom DirectAdmins SSH Keys-sida ([[Pipeline]] § En röjd nyckel). Den publika halvan från steg 1 förs över först.

```bash
# På produktionsservern hos inleed
cp <repo>/deploy/drift/mimers-backup.sh ~/bin/mimers-backup
chmod 700 ~/bin/mimers-backup

cp ~/.ssh/authorized_keys ~/.ssh/authorized_keys.bak
printf 'command="/home/s174280/bin/mimers-backup",restrict %s\n' \
  "$(cat id_ed25519_mimers-backup.pub)" >> ~/.ssh/authorized_keys
```

**3. Skripten på VPS:en.**

```bash
# På utvecklings-VPS:en
mkdir -p ~/bin ~/.config/mimers-backup
cp <repo>/deploy/drift/hamta-backup.sh ~/bin/hamta-backup.sh
cp <repo>/deploy/drift/vakt.sh ~/bin/vakt.sh
cp <repo>/deploy/drift/aterlasningstest.sh ~/bin/aterlasningstest.sh
chmod 700 ~/bin/hamta-backup.sh ~/bin/vakt.sh ~/bin/aterlasningstest.sh
```

**4. `backup.env` och `restic.pass`, båda med läge 600.** Hela filen:

```bash
# ~/.config/mimers-backup/backup.env — läge 600. Nycklarna läses av
# hamta-backup.sh, vakt.sh och aterlasningstest.sh. Värdena här är
# platshållare; de riktiga finns bara på VPS:en och i lösenordshanteraren.
# SSH_HOST/SSH_USER är produktionskontot hos inleed.

# 42b — hämtaren (deploy/drift/hamta-backup.sh)
SSH_HOST=prime5.inleed.net
SSH_PORT=2020
SSH_USER=s174280
SSH_KEY=/home/<vps-användare>/.ssh/id_ed25519_mimers-backup
SSH_KNOWN_HOSTS=/home/<vps-användare>/.ssh/known_hosts
REMOTE_FILES=mimers/shared/storage/files/
RESTIC_REPOSITORY=/home/<vps-användare>/mimers-backup/restic
RESTIC_PASSWORD_FILE=/home/<vps-användare>/.config/mimers-backup/restic.pass
FILES_DIR=/home/<vps-användare>/mimers-backup/filer
STATE_DIR=/home/<vps-användare>/.local/state/mimers-backup
MIN_FREE_MB=2048
NOTIFY_CMD=/home/<vps-användare>/.local/bin/notify-tony

# 43 — vakten (deploy/drift/vakt.sh). DRIFT_TOKEN är samma hemlighet som står
# i produktionens shared/.env. DRIFT_JOBS är den enda plats i hela systemet där
# bevakningen av kön står; drain-queue först.
DRIFT_URL=https://mimers.app/drift/heartbeat
DRIFT_TOKEN=<samma hemlighet som i produktionens shared/.env>
DRIFT_JOBS="drain-queue:20 deliver-notifications:15"
BACKUP_MAXAGE="dump:1560 filer:11520 arkiv:46080"
ALARM_COOLDOWN_MIN=360
PULSE_DAYS=7

# 44 — återläsningstestet (deploy/drift/aterlasningstest.sh). Förvalen duger;
# nycklarna är valfria och kan uteslutas.
RESTORE_TEST_DB=mimers_restore_test
MIN_TABLES=25
```

```bash
# restic.pass innehåller restic-lösenordet, en rad. Skapas med läge 600 och
# behöver inte läsas av något skript — hamta-backup.sh pekar bara på filen.
printf '%s\n' '<restic-lösenordet>' > ~/.config/mimers-backup/restic.pass
chmod 600 ~/.config/mimers-backup/restic.pass ~/.config/mimers-backup/backup.env
```

**5. `restic init`** — första gången, innan `hamta-backup.sh dump` kan köra:

```bash
# På utvecklings-VPS:en. Repot och lösenordsfilen läses ur backup.env; raderna
# motsvarar det skripten exporterar.
RESTIC_REPOSITORY=/home/<vps-användare>/mimers-backup/restic \
RESTIC_PASSWORD_FILE=/home/<vps-användare>/.config/mimers-backup/restic.pass \
  restic init
```

**6. `DRIFT_TOKEN` i produktionens `shared/.env`** — på produktionsservern. Token genereras slumpmässigt, skrivs in i `.env` (samma värde som i `backup.env` ovan), och ytan `GET /drift/heartbeat` svarar därefter 404 utan token i stället för 200.

```bash
# På produktionsservern hos inleed. Värdet förs också in i backup.env på VPS:en.
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

**7. En lokal MariaDB för återläsningstestet** — på VPS:en. Återläsningstestet och Scenario A läser in dumpen i en skräpdatabas på VPS:ens egen MariaDB, så en server måste finnas där. Den användare cron kör som måste få skapa, fylla och släppa databasen `mimers_restore_test`. Fungerar den användarens `mysql`-anslutning inte med rättigheter, peka `aterlasningstest.sh` mot en egen användare via `MYSQL_EXTRA` i `backup.env` (t.ex. `MYSQL_EXTRA=--defaults-extra-file=/home/<vps-användare>/.config/mimers-backup/aterlasning.cnf`).

**8. Cron-radena** — på VPS:en. `$HOME` sätts av cron, så raderna fungerar som de står:

```
17 2 * * *   $HOME/bin/hamta-backup.sh dump
23 3 * * 0   $HOME/bin/hamta-backup.sh filer
41 4 1 * *   $HOME/bin/hamta-backup.sh arkiv
7  * * * *   $HOME/bin/vakt.sh
0  5 1 1,4,7,10 *   $HOME/bin/aterlasningstest.sh
```

## Var lösenorden ska finnas

Inget lösenordsvärde står i det här dokumentet eller i repot. De hemligheter kedjan bär ska finnas på **två** ställen, varav ett inte är VPS:en:

| Hemlighet | Finns på | Finns också i |
|---|---|---|
| restic-lösenordet (`restic.pass`) | VPS:en, `RESTIC_PASSWORD_FILE`, läge 600 | lösenordshanteraren |
| `APP_KEY` | produktionens `shared/.env` hos inleed, läge 600 | lösenordshanteraren |
| `DRIFT_TOKEN` | produktionens `shared/.env` | VPS:ens `backup.env` |
| databasuppgifterna | produktionens `shared/.env` | — (skapas per miljö, se [[Pipeline]] § Engångsuppsättning) |

`APP_KEY` och restic-lösenordet är de två som ett förlorat konto inte går att återskapa utan: `APP_KEY` finns inte i backupen (se Scenario D) och restic-lösenordet är det som låser upp backupen. Därför hör båda hemma i lösenordshanteraren — saknas restic-lösenordet där är backupen också borta, och saknas `APP_KEY` där kostar en återläsning allas tvåfaktor och webhookar.
