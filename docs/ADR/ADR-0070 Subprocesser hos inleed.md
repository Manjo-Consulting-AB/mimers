# ADR-0070 Subprocesser hos inleed

**Status:** Antagen 2026-11-06 · Ersätter [[ADR-0018 Utvecklingsprocess och deploy]] § *proc_open är avstängt* och [[ADR-0031 Köarbetaren körs av schemaläggaren]] § *Beslut* · [[ADR-index]]

Tonys beslut 2026-11-06, efter att kontot höjts till PHP 8.5.

## Kontext

Avstängningen av `proc_open` verifierades 2026-08-23 och har sedan dess styrt hur driftkoden skrivs. Schemaläggningen använder `->call()` och `->job()`. Köarbetaren körs inne i schemaläggarens process ([[ADR-0031 Köarbetaren körs av schemaläggaren]]). Ett tjugotal klasser och tio prov hänvisar till regeln i AGENTS.md.

Den 2026-11-06 visade det sig att `disable_functions` går att ändra i inleeds kontrollpanel, **per PHP-version**. Kontot höjdes till 8.5.12, och listan för 8.5 sattes till:

```
exec, system, passthru, shell_exec, escapeshellarg, escapeshellcmd, dl, popen,
show_source, posix_mkfifo, posix_setpgid, posix_setsid, posix_setuid,
posix_setgid, posix_seteuid, posix_setegid, posix_uname
```

`proc_open`, `proc_close`, `posix_kill` och `posix_getpwuid` är alltså öppna. Allt som startar ett program genom ett skal är fortfarande stängt.

Två saker gör öppningen ömtålig:

- **Standardlistan skiljer sig mellan versionerna.** Listan för 8.5 var inte densamma som för 8.4 innan någon rörde den: `escapeshellarg` och `escapeshellcmd` tillkom. Byter någon PHP-version i panelen, eller återställer inleed kontot, kan `proc_open` stängas igen utan att något i repot ändras.
- **En kod som kräver `proc_open` ser då frisk ut.** `Schedule::command(...)` kastar i schemaläggarens körning, och felet syns bara i loggen.

## Beslut

1. **Förvalet är oförändrat: kör i processen.** Schemalagda uppgifter är `->call(...)` eller `->job(...)`. Köer dras med `queue:work`, aldrig `queue:listen`. Befintlig kod skrivs inte om.
2. **`proc_open` får användas där en ADR säger det, och bara där.** Den första användningen är köarbetaren, nedan. En issue som vill starta en subprocess utan ett sådant beslut ska stanna och fråga.
3. **Funktionerna i listan ovan används aldrig**, inte heller genom ett bibliotek. Ett externt program som behöver ett skal går inte att köra.
4. **Köarbetaren får en egen process när `proc_open` finns.** `drain-queue` registreras som `Schedule::command('queue:work', [...])->runInBackground()` med samma flaggor som i ADR-0031. En jobbtimeout dödar då bara arbetaren, och kön får eget minnestak. Kravet att arbetaren ligger sist i `routes/console.php` faller bort.
5. **Saknas `proc_open` faller arbetaren tillbaka till ADR-0031:s väg**, `Artisan::call` i en `Schedule::call`, och loggar en varning varje gång den gör det. Kön töms alltså i båda lägena. Det som går förlorat vid en återställning är isoleringen, inte kön.
6. **`retro-fakta` skriver ut `disable_functions` för CLI-PHP**, så att en återställning syns vid nästa retro och inte först när någon läser loggen.

## Motivering

Att öppna en funktion i panelen är billigt, men att bygga på den gör koden beroende av en inställning som inte finns i repot. Därför ändras regeln från ett förbud till ett krav på beslut: varje användning har en ADR som säger varför, och den enda användningen som beslutas här har en reservväg tillbaka till det som fungerade före 2026-11-06.

Köarbetaren väljs först för att det är där ADR-0031 själv redovisar priset av att köra i processen: en jobbtimeout tar med sig resten av schemaläggningskörningen, och kön delar minnestak med allt annat. Båda försvinner med en egen process. `posix_kill` är öppen nu, men det ändrar inget i sig: `Worker::kill()` avslutar processen som förut, nu med en signal i stället för ett fel.

## Konsekvenser

- AGENTS.md § *Subprocesser i driftmiljön* ersätter § *Driftmiljön saknar proc_open*. Hänvisningarna i koden, proven och valvet följer det nya namnet.
- `KoarbetareTest` ändras av den issue som bygger punkt 4 och 5: posten är inte längre en closure och behöver inte ligga sist.
- Låset på `drain-queue` behåller sin livslängd på tio minuter (ADR-0031 § *Uppföljning 2026-09-08*). En bakgrundsprocess som dör släpper inte låset förrän det löper ut.
- `exec` och `popen` är fortfarande stängda, så `git` och liknande anropas inte vid körning. Kommentaren i `config/app.php` stämmer fortfarande i sak.

## Alternativ

**Skriv om all schemaläggning till `Schedule::command`.** Laravels vanliga mönster, men ingen vinst för uppgifter som redan fungerar i processen, och alla blir beroende av panelinställningen. Avfärdat.

**Behåll förbudet.** Ingen risk, men köarbetaren fortsätter att dela process med schemaläggningen fast det inte längre behövs. Avfärdat.
