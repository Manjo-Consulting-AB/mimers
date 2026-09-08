# ADR-0031 Köarbetaren körs av schemaläggaren

**Status:** Antagen 2026-09-08 · [[ADR-index]]

## Kontext

`QUEUE_CONNECTION=database` står i båda miljöernas `shared/.env`, och **ingenting tömmer kön**. Två jobb dispatchas i kod: `GenerateImageDerivatives` (issue 18, ute sedan v0.1.0) och `BuildContainerExport` (issue 41a, ingår i v0.4.0). Varken `deploy.sh`, `routes/console.php` eller crontabben startar en arbetare. Avläst på plats 2026-09-08 med `retro-fakta`, se issue 235.

Ingen skada har skett ännu: `storage/files` är tom i båda miljöerna, så inget miniatyrjobb har någonsin köats. Exporten ändrar det. Första `POST /containers/{c}/exports` lägger en rad i `jobs` som ingen plockar upp — raden står `pending` för alltid, pollningen svarar aldrig `ready`, nedladdningen ger 404. Sviten ser det inte, eftersom testkön är synkron.

Driftmiljön är hos inleed, och den avgör vad som är möjligt:

- **Ingen processövervakare.** Ingen supervisor, ingen systemd-enhet, ingen väg att hålla en daemon vid liv.
- **`proc_open` är avstängt**, i både webb-SAPI och CLI. `Schedule::command(...)` och `->runInBackground()` fungerar därför inte — se [[ADR-0018 Utvecklingsprocess och deploy]] och AGENTS.md § *Driftmiljön saknar proc_open*.
- **Cron är per konto**, en enda crontab som delas med kontots övriga domäner. Där ligger redan en minutrad per miljö som kör `artisan schedule:run`.
- **CLI-PHP är 8.4 med `memory_limit=128M` och `max_execution_time=0`.** `pcntl` och `posix` är laddade, men `posix_kill` står i `disable_functions`.

## Beslut

**Kön dras av en schemalagd `queue:work --stop-when-empty`, registrerad sist i `routes/console.php` och anropad genom `Artisan::call` inuti en `Schedule::call`.** Ingen ny cron-rad, ingen daemon, ingen ny process att övervaka: minutcronen som redan finns kör den.

```php
Schedule::call(function () {
    if (config('queue.default') === 'sync') {
        return;
    }

    Artisan::call('queue:work', [
        '--stop-when-empty' => true,
        '--max-time' => 50,
        '--timeout' => 300,
        '--memory' => 96,
        '--tries' => 1,
    ]);
})->everyMinute()->name('drain-queue')->withoutOverlapping();
```

`Artisan::call` kör kommandot **i schemaläggarens egen process** — ingen `proc_open`, alltså inget som spricker hos inleed. `queue:work` är i sig en loop i samma process; det är `queue:listen` som startar barnprocesser, och den är förbjuden redan.

Fyra tal, och varför de ser ut så:

- **`--max-time=50`** — arbetaren lämnar över till nästa minuts körning i stället för att bli en daemon i smyg. Talet kontrolleras mellan jobb, inte under.
- **`--timeout=300` med `DB_QUEUE_RETRY_AFTER=600`.** Villkoret som måste hålla är `max-time + timeout < retry_after`: annars kan ett jobb som fortfarande kör bli reserverat av nästa arbetare och köras två gånger. 50 + 300 < 600. Förvalet 90 s för `retry_after` är för snävt för en ZIP-byggnad, och det är exportens kö vi bygger för.
- **`--memory=96`** under processens `memory_limit=128M`, så arbetaren stannar själv i stället för att dö på en fatal.
- **`--tries=1`** — båda jobben hanterar sina egna fel. Ett jobb som slår i timeouten hamnar i `failed_jobs` vid nästa försök i stället för att köras om i evighet.

**Registreringen sist i filen är en del av beslutet, inte en formalitet.** Slår ett jobb i `--timeout` kallar Laravel `Worker::kill()`, som anropar `posix_kill` — avstängt här — och sedan `exit()`. Processen dör alltså mitt i schemaläggningskörningen. Ligger arbetaren sist har allt annat som var i tur redan kört, och nästa minut startar en ny process ändå. Ligger den först tar en långsam export med sig leveransloopen.

**Arbetaren hoppas över när `queue.default` är `sync`.** Sync-drivern kan inte poppas ifrån, och sviten kör med den.

## Motivering

Det som gick fel var inte att kön var fel konfigurerad utan att **starten fanns bara som ett handgrepp ingen gjorde**. Två jobb rullades ut mot en kö som aldrig hade någon arbetare, och det syntes inte i något test, i någon release och i någon checklista. Väljer man då en lösning som är ännu ett handgrepp på servern har man betalat med en release och köpt samma risk igen.

Därför bor arbetaren i repot. Den rullas ut med koden, den granskas i en diff, den kan testas, och den kan inte glömmas bort i en ny miljö. Den syns dessutom i `retro-fakta`, som letar efter `queue:work` i `routes/console.php` — en cron-rad kan skriptet inte se, eftersom `crontab -l` inte svarar under den låsta nyckeln ([[ADR-0029 Agentens läsåtkomst till servern]]).

Priset är en minuts latens innan ett jobb plockas upp, och att kön delar process och minnestak med schemaläggningen. För en exportknapp som ändå pollar, och för miniatyrer som ingen väntar på, är det rätt pris.

## Konsekvenser

- `routes/console.php` får en `drain-queue`-post, sist i filen. Konventionen "`Schedule::call`, aldrig `Schedule::command`" gäller fortfarande — `Artisan::call` är vägen runt, inte ett undantag från den.
- `.env.example` får `DB_QUEUE_RETRY_AFTER=600`. Nyckeln behöver **inte** sättas i `shared/.env`: förvalet i `config/queue.php` ändras i samma svep, så miljöerna får rätt värde utan handpåläggning.
- **Jobb måste hålla sig under fem minuter.** Byggs exporten någon gång om till något som tar längre tid är det den här ADR:n som ska ändras, inte `--timeout` i förbifarten.
- [[Pipeline]] § *Engångsuppsättning* skriver ut att ingen extra cron-rad behövs, och § *Releaseritualen* får en punkt: läs av `jobs` och `köarbetare` i `retro-fakta` efter utrullningen.
- Ett jobb som slår i timeouten avbryter resten av den minutens schemaläggningskörning. Accepterat därför att arbetaren ligger sist och nästa minut kör ändå — men det är skälet till att posten aldrig får flyttas uppåt i filen.
- Väljer vi någon gång en riktig kötjänst — Redis hos en annan leverantör, eller en VPS med supervisor — faller hela den här konstruktionen bort. Den är en anpassning till delad hosting, inte en arkitektur.

## Alternativ

**En egen cron-rad med `queue:work`.** Egen process, eget minnestak, ingen risk att ta med schemaläggningen i fallet. Avfärdat: det är ett handgrepp per miljö, utanför repot, osynligt för både granskning och `retro-fakta` — exakt den sortens steg som skapade issue 235. Skillnaden i robusthet är dessutom mindre än den ser ut, eftersom minutcronen redan är den enda som håller allt annat vid liv.

**`QUEUE_CONNECTION=sync`.** Noll infrastruktur, och miniatyrerna hade fungerat. Avfärdat: ZIP-byggandet skulle då ske i webbrequesten, vilket är precis det 41a:s asynkrona design finns till för att undvika. Den största containern spränger `max_execution_time` i webb-SAPI:n först, alltså felar det för den kund som har mest att förlora.

**`Schedule::job()` i stället för en kö.** Fungerar inte för det här: `->job()` *lägger* jobbet på kön, den tömmer den inte.

**Daemon som startas av cron med en pid-fil.** Avfärdat: den skulle behöva startas om vid varje utrullning eftersom `current` flippar, och det finns inget som kan starta om den. En arbetare som lever över en release kör dessutom gammal kod ur en borttagen release.
