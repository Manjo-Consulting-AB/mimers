# ADR-0030 Miljövariabler ur GitHubs secrets

**Status:** Antagen 2026-09-08 · [[ADR-index]]

## Kontext

`shared/.env` skapas för hand på servern, en per miljö, och rörs inte av utrullningen — `deploy.sh` symlänkar bara in den i releasen. [[Pipeline]] § *Engångsuppsättning* säger det rakt ut: *".env skapas här och bara här, en per miljö."*

M6-retrons avläsning av servern visade vad den ordningen kostar. Produktionens `shared/.env` bar 21 nycklar — `APP_*`, `LOG_*` och `DB_*` — och `MAIL_MAILER=log`. Samtidigt fanns `MAIL_MAILER`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `MAILGUN_DOMAIN`, `MAILGUN_SECRET`, `MAILGUN_ENDPOINT` och `MAILGUN_WEBHOOK_SIGNING_KEY` satta som Environment-secrets i GitHub, för båda miljöerna, sedan 2026-09-06 och -07.

Ingen av dem nådde appen. GitHubs Environment-secrets är bara synliga för de workflow-steg som uttryckligen namnger dem, och `production.yml` namnger sex: de sex `DEPLOY_*`. Följden var att M5 låg utrullad i produktion sedan v0.3.0 utan att kunna skicka ett enda mejl — `config/mail.php` har `env('MAIL_MAILER', 'log')`, så utan raden i `.env` blir kanalen loggfilen. Notiskärnan la rader i outboxen, leveransloopen skrev dem till `laravel.log`, och ingenting i kedjan sa till.

Felet är inte att någon satte fel värde. Det är att systemet hade **två platser** som såg ut som rätt plats, och bara den ena var kopplad till körningen. Den kopplade var dessutom den som inte syns någonstans utom på servern.

## Beslut

**Utrullningen skriver in miljöns secrets i `shared/.env` innan `config:cache` körs.** GitHubs Environment-secrets blir källan för de nycklar som finns där; `shared/.env` blir det renderade resultatet.

Formen är en **upsert, inte en rendering av hela filen**. `deploy/env-uppdatera.sh` skriver bara de nycklar den får, och lämnar varje annan rad i filen orörd.

Mekaniken, i `staging.yml` och `production.yml`:

1. Värdena skrivs som `NYCKEL=värde` till serverns `shared/.env.inkommande` **över stdin** — aldrig som argument, eftersom argument syns i `ps` för varje annan kund på den delade maskinen — med `umask 077`.
2. `env-uppdatera.sh` validerar varje rad, tar backup av `.env`, upsertar nycklarna, kontrollerar att resultatet fortfarande bär `APP_KEY`, och byter filen med `mv`.
3. Först därefter kör `deploy.sh`, som cachar konfigurationen.

Fragmentet tas bort av en `trap … EXIT`, alltså också när körningen avbryts.

Nycklarna som omfattas i dag är de sju ovan. Listan står på ett ställe — stegets `env:`-block i de två workflowfilerna.

## Motivering

**Upsert och inte hel rendering, därför att `APP_KEY` finns bara på servern.** Den och databasuppgifterna har aldrig passerat GitHub, och en app vars `APP_KEY` byts har obrukbara krypterade kolumner och obrukbara signerade länkar. Att flytta dem till secrets är en egen, större migrering; att rendera hela filen utan att först ha flyttat dem vore att radera dem vid nästa utrullning. Upserten lämnar allt den inte känner till i fred och är därför den enda formen som är säker att införa mitt i ett projekt.

**Före `config:cache` och inte efter.** `deploy.sh` kör `php artisan config:cache`, så en `.env` som ändras efteråt får ingen effekt förrän nästa utrullning. Det är den fällan som gör en manuell `.env`-redigering svår att lita på: filen ser rätt ut och appen läser ändå det gamla värdet.

**Stdin och inte argument.** Servern är delad hosting. Ett kommando med `MAILGUN_SECRET=...` i argumentlistan är läsbart i `ps` för varje annan kund på maskinen så länge anropet pågår. Samma resonemang som `MYSQL_PWD` i `retro-fakta`.

**Fail-closed på fyra ställen**, eftersom `.env` är den enda filen på servern som inte finns någon annanstans: en rad som inte är `NYCKEL=värde` avbryter innan något skrivits; en tom secret hoppas över i stället för att blanka en satt rad; ett resultat utan `APP_KEY` skrivs inte; och en `.env` som saknas skapas inte — den ska skapas för hand.

**Varför nu och inte efter releasen.** Frågan restes i samband med v0.4.0, och alternativet — att klistra in sju rader för hand i två miljöer — löser exakt en gång det som annars återkommer vid varje ny nyckel. Miljöskillnaderna är dessutom steg 4 i [[Pipeline]] § *Releaseritualen*, och den punkten blir kontrollerbar först när det finns en definierad plats att kontrollera mot.

## Konsekvenser

- `deploy/env-uppdatera.sh` finns i repot och körs av båda utrullningsworkflowarna. Den skriver bara i `shared/.env` och rör ingen release.
- `.env.example`s rad *"sätt MAIL_MAILER=mailgun och nycklarna nedan i staging/produktionens shared/.env"* gäller inte längre för de sju nycklarna. De sätts i GitHub; filen på servern är resultatet.
- [[Pipeline]] § *Miljöer och secrets i GitHub* får de sju i tabellen, med en rad om att listan i workflowfilen är det som avgör vilka som transporteras.
- Nycklar utanför listan når fortfarande inte servern. Läggs en ny secret upp måste den också läggas till i `env:`-blocket i båda filerna, annars händer ingenting — tyst, precis som den här ADR:n handlar om. `retro-fakta` är motmedlet: den visar vilka nycklar som faktiskt ligger i `shared/.env` efter en utrullning.
- Upp till fem `.env.bak.<tidsstämpel>` sparas i `shared/`. De innehåller hemligheter och ligger med samma rättigheter som originalet; de ska aldrig hämtas hem.
- `APP_KEY` och databasuppgifterna är fortfarande handskötta och finns bara på servern. Ska de också flyttas till secrets är det en egen ADR — och den måste svara på hur en `APP_KEY` byts utan att befintlig krypterad data blir obrukbar.

## Alternativ

**Låt `shared/.env` förbli enda sanningen och ta bort secreten ur GitHub.** Minst rörliga delar, och det som gällde fram till nu. Avfärdat: felet som avslöjade problemet var att ingen kunde se att filen saknade nycklar. En sanning som bara finns på servern går inte att granska, inte att versionshantera och inte att återställa på en ny maskin.

**Rendera hela `shared/.env` ur secrets vid varje utrullning.** Renast på sikt — miljön blir reproducerbar och en ny server är en utrullning bort. Avfärdat för nu: kräver att `APP_KEY` och databasuppgifterna först flyttas till GitHub, och en felaktig rendering skulle då ta produktionens nyckel med sig. Rätt mål, fel ordning.

**En hemlighetstjänst (Vault, SOPS, age-krypterad fil i repot).** Avfärdat: löser ett problem vi inte har, på en driftprofil som medvetet är så tråkig som möjligt ([[ADR-0018 Utvecklingsprocess och deploy]]). GitHubs secrets är redan där, redan åtkomstkontrollerade per miljö, och redan det som håller deploynycklarna.
