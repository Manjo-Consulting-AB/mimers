<?php

use App\Console\AdvancesAccountLifecycle;
use App\Console\DeletesDormantAccounts;
use App\Console\DeliversNotifications;
use App\Console\DeliversWebhooks;
use App\Console\EnforcesDowngrades;
use App\Console\GeneratesLoanNotifications;
use App\Console\GeneratesQuotaWarnings;
use App\Console\GeneratesTaskNotifications;
use App\Console\PrunesExpiredMagicLinkTokens;
use App\Console\PrunesRegistrationIps;
use App\Console\PurgesExpiredExports;
use App\Console\PurgesExpiredStoredFiles;
use App\Console\PurgesExpiredTrash;
use App\Console\ReconcilesUsageCounters;
use App\Console\SendsWeeklyDigest;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Issue 5 · Magic link, uppföljning efter granskning av PR #34.
 * magic_link_token växer annars obegränsat — en rad per begärd länk, för
 * alltid, även efter förbrukning eller förfall. Gallringslogiken bor i
 * App\Console\PrunesExpiredMagicLinkTokens (testad direkt, se
 * tests/Feature/Auth/GallraMagicLinkTokensTest.php); det här är bara
 * schemaläggningen.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open. En closure körs i stället i samma PHP-process som
 * schemaläggningskörningen (`schedule:run`, som i sin tur triggas av en
 * riktig cron-rad hos inleed — se [[Pipeline]] för hur, rört inte här).
 */
Schedule::call(fn () => app(PrunesExpiredMagicLinkTokens::class)->handle())
    ->daily()
    ->name('prune-magic-link-tokens');

/*
 * Issue 17b · Fysisk radering av stored_file: tar bort bytena och raden när
 * reference_count har stått på noll i 30 dagar — se
 * App\Console\PurgesExpiredStoredFiles, [[Filer och lagring]] § Radering och
 * [[ADR-0008 Soft delete och papperskorg]]. Logiken bor i en vanlig klass,
 * testad direkt i tests/Feature/Attachment/FysiskRaderingTest.php; det här
 * är bara schemaläggningen.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan.
 */
Schedule::call(fn () => app(PurgesExpiredStoredFiles::class)->handle())
    ->daily()
    ->name('purge-expired-stored-files');

/*
 * Issue 20b · Gallringen av utgånget papperskorgsinnehåll: mjukraderade rader
 * vars deleted_at passerat retentionen tas bort på riktigt — se
 * App\Console\PurgesExpiredTrash, [[ADR-0008 Soft delete och papperskorg]] §
 * Retentionstiden i MVP och config/files.php § trash_retention_days. Logiken
 * bor i en vanlig klass, testad direkt i
 * tests/Feature/Trash/GallringTest.php; det här är bara schemaläggningen.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan. Kör gärna i
 * samma nattliga fönster som purge-expired-stored-files (17b), men det finns
 * inget beroende mellan dem: bilagor som gallras i natt markerar bytes som
 * får en egen 30-dagarsfrist ändå.
 */
Schedule::call(fn () => app(PurgesExpiredTrash::class)->handle())
    ->daily()
    ->name('purge-expired-trash');

/*
 * Issue 41b · Gallringen av färdiga exportartefakter: bytena tas bort och
 * raden sätts till `expired` när retentionen passerat — se
 * App\Console\PurgesExpiredExports, config/files.php § export_retention_days
 * och [[Backlog]] M6 § 41. Jobbet tar också hand om `failed`-rader äldre än
 * retentionen och föräldralösa `.part`-filer. Logiken bor i en vanlig klass,
 * testad direkt i tests/Feature/Export/ExportNedladdningTest.php; det här är
 * bara schemaläggningen.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan. Körs i samma
 * nattliga fönster som purge-expired-stored-files (17b) och
 * purge-expired-trash (20b).
 */
Schedule::call(fn () => app(PurgesExpiredExports::class)->handle())
    ->daily()
    ->name('purge-expired-exports');

/*
 * Issue 50a · Gallringen av registrerings-IP:t: account.registration_ip nollas
 * på konton äldre än fristen i config/konton.php § registration_ip_retention_days
 * — se App\Console\PrunesRegistrationIps, [[Registerförteckning]] och
 * [[ADR-0017 Missbruksvektorer]] § Konsekvenser. Jobbet nollar en kolumn, det
 * raderar aldrig ett konto; det gör delete-dormant-accounts (29b) nedan, och
 * de två jobben har ingenting med varandra att göra. Logiken bor i en vanlig
 * klass, testad direkt i tests/Feature/Missbruk/RegistreringsIpTest.php; det
 * här är bara schemaläggningen. Kör i samma nattliga fönster som de andra
 * gallringsjobben.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan.
 */
Schedule::call(fn () => app(PrunesRegistrationIps::class)->handle())
    ->daily()
    ->name('prune-registration-ips');

/*
 * Issue 26b · Den nattliga avstämningen av usage_counter: räknar om
 * summorna, rättar det som glidit och larmar — se
 * App\Console\ReconcilesUsageCounters, [[Planer och kvoter]] § usage_counter
 * och issue 26a § Beslut 2. Logiken bor i en vanlig klass, testad direkt i
 * tests/Feature/Kvot/AvstamningTest.php; det här är bara schemaläggningen.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan. Kör i samma
 * nattliga fönster som gallringsjobben: en drift ska vara lagad innan
 * kontrollpunkterna (27a/27b) läser räknaren nästa dag.
 */
Schedule::call(fn () => app(ReconcilesUsageCounters::class)->handle())
    ->daily()
    ->name('reconcile-usage-counters');

/*
 * Issue 34b · Uppgiftsnotiserna: förekomster som blivit synliga eller
 * förfallit skapar en task.due/task.overdue per mottagare — se
 * App\Console\GeneratesTaskNotifications och [[Notiser]] § Kön. Logiken bor
 * i en vanlig klass, testad direkt i tests/Feature/Notis/UppgiftsnotisTest.php;
 * det här är bara schemaläggningen. Var 15:e minut, enligt tabellen i
 * [[Notiser]] § Kön.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan.
 */
Schedule::call(fn () => app(GeneratesTaskNotifications::class)->handle())
    ->everyFifteenMinutes()
    ->name('generate-task-notifications');

/*
 * Issue 34b · Kvotvarningarna: konton som passerat 80 % eller 100 % av
 * lagringsgränsen får en notis per ägare och administratör — se
 * App\Console\GeneratesQuotaWarnings och [[Notiser]] § Kön. Logiken bor i en
 * vanlig klass, testad direkt i tests/Feature/Notis/KvotvarningTest.php; det
 * här är bara schemaläggningen. Körs efter avstämningen ovan (26b) i samma
 * nattliga fönster, så varningen bygger på ett rättat tal (Beslut 2).
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan.
 */
Schedule::call(fn () => app(GeneratesQuotaWarnings::class)->handle())
    ->daily()
    ->name('generate-quota-warnings');

/*
 * Issue 76 · Utlåningsnotiserna: öppna utlåningar vars förfallodatum närmar
 * sig skapar en loan.due per mottagare — se App\Console\GeneratesLoanNotifications
 * och [[Notiser]] § Kön. Logiken bor i en vanlig klass, testad direkt i
 * tests/Feature/Utlaning/UtlaningsnotisTest.php; det här är bara
 * schemaläggningen. Dagligen, enligt tabellen i [[Notiser]] § Kön, i samma
 * nattliga fönster som kvotvarningarna ovan.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan.
 */
Schedule::call(fn () => app(GeneratesLoanNotifications::class)->handle())
    ->daily()
    ->name('generate-loan-notifications');

/*
 * Issue 28b · Verkställandet av nedgraderingen: konton vars frist gått ut får
 * bilagor raderade, nyast först, tills kontot ligger under gratisplanens gräns
 * och återgår till active — se App\Console\EnforcesDowngrades, [[Planer och
 * kvoter]] § Nedgradering och [[ADR-0009 Kvoter och livscykel]]. Logiken bor i
 * en vanlig klass, testad direkt i
 * tests/Feature/Kvot/NedgraderingsraderingTest.php; det här är bara
 * schemaläggningen.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan. Kör i samma
 * nattliga fönster som gallringsjobben: en nedgradering ska vara klar innan
 * kontrollpunkterna (27a/27b) läser räknaren nästa dag.
 */
Schedule::call(fn () => app(EnforcesDowngrades::class)->handle())
    ->daily()
    ->name('enforce-downgrades');

/*
 * Issue 29a · Kontolivscykeln: påminnelsen vid 12 månader och stängningen
 * vid 15, plus återöppningen när en medlem återvänder — se
 * App\Console\AdvancesAccountLifecycle, [[Planer och kvoter]] §
 * Kontolivscykel och [[ADR-0009 Kvoter och livscykel]]. Tidsgränserna bor i
 * config/konton.php. Logiken bor i en vanlig klass, testad direkt i
 * tests/Feature/Konto/LivscykelTest.php; det här är bara schemaläggningen.
 * Kör i samma nattliga fönster som gallrings- och nedgraderingsjobben.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan.
 */
Schedule::call(fn () => app(AdvancesAccountLifecycle::class)->handle())
    ->daily()
    ->name('advance-account-lifecycle');

/*
 * Issue 29b · Kontolivscykelns sista steg: raderingen vid 18 månader — se
 * App\Console\DeletesDormantAccounts, [[Planer och kvoter]] § Kontolivscykel
 * och [[ADR-0009 Kvoter och livscykel]]. Jobbet körs efter 29a:s steg i
 * routes/console.php — stängningen (15 månader) måste ha hunnit före
 * raderingen (18), och återöppningen ska ha fått öppna konton vars medlemmar
 * återvänt. Logiken bor i en vanlig klass, testad direkt i
 * tests/Feature/Konto/KontoraderingTest.php; det här är bara schemaläggningen.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan. Kör i samma
 * nattliga fönster som 29a och de andra jobben.
 */
Schedule::call(fn () => app(DeletesDormantAccounts::class)->handle())
    ->daily()
    ->name('delete-dormant-accounts');

/*
 * Issue 34a · Leveransloopen: plockar `pending`-leveranser vars `available_at`
 * passerats, kör dem genom e-postkanalen och bokför utfallet — se
 * App\Console\DeliversNotifications, [[Notiser]] § Kön och config/notiser.php
 * § delivery. Logiken bor i en vanlig klass, testad direkt i
 * tests/Feature/Notis/LeveransloopTest.php; det här är bara schemaläggningen.
 * Körs varje minut, utan överlappning (Beslut 1): cachelåset i
 * `withoutOverlapping()` gör att en långsam körning som fortfarande skickar
 * när nästa minut slår till inte läser samma `pending`-rader en gång till.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan.
 */
Schedule::call(fn () => app(DeliversNotifications::class)->handle())
    ->everyMinute()
    ->name('deliver-notifications')
    ->withoutOverlapping();

/*
 * Issue 37b · Webhook-leveransloopen: anropar kontons endpoints för de
 * `pending`-rader vars `next_attempt_at` passerats och bokför utfallet — se
 * App\Console\DeliversWebhooks, [[Notiser]] § Webhooks och config/notiser.php
 * § webhook. Logiken bor i en vanlig klass, testad direkt i
 * tests/Feature/Notis/WebhookleveransTest.php; det här är bara schemaläggningen.
 * Körs varje minut, utan överlappning (Beslut 5): cachelåset i
 * `withoutOverlapping()` gör att en långsam körning som fortfarande anropar
 * när nästa minut slår till inte läser samma `pending`-rader en gång till.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan.
 */
Schedule::call(fn () => app(DeliversWebhooks::class)->handle())
    ->everyMinute()
    ->name('deliver-webhooks')
    ->withoutOverlapping();

/*
 * Issue 35 · Veckosammanfattningen: samlar `digest`-markerade leveranser till
 * ett mejl per mottagare — se App\Console\SendsWeeklyDigest, [[Notiser]] §
 * notification_preference och config/notiser.php § digest. Logiken bor i en
 * vanlig klass, testad direkt i
 * tests/Feature/Notis/VeckosammanfattningTest.php; det här är bara
 * schemaläggningen.
 *
 * Måndagar 06:00 UTC — 07:00 eller 08:00 i Sverige, före arbetsdagen och
 * efter natten. `available_at` och tysta timmar läses INTE av jobbet (Beslut
 * 6): fönstret finns för att ingen ska väckas klockan tre, och ett veckobrev
 * som skickas en bestämd morgon väcker ingen. Att skjuta varje mottagares
 * sammanfattning till hens lokala morgon vore ett andra köschema för en enda
 * mejltyp.
 *
 * `Schedule::call(...)`, ALDRIG `Schedule::command(...)` eller
 * `->runInBackground()` — båda går via Symfony Process/proc_open, avstängt
 * hos inleed i både webb-SAPI och CLI, se AGENTS.md § Driftmiljön saknar
 * proc_open och kommentaren för magic link-gallringen ovan.
 */
Schedule::call(fn () => app(SendsWeeklyDigest::class)->handle())
    ->weeklyOn(1, '06:00')
    ->name('send-weekly-digest');

/*
 * Issue 237 · Köarbetaren: tömmer `jobs`-tabellen varje minut. Inget annat
 * startar en arbetare — `QUEUE_CONNECTION=database` står i båda miljöernas
 * shared/.env, och varken deploy.sh eller crontabben kör queue:work. Se
 * ADR-0031 för besluten. Logiken bor inte i en vanlig klass (till skillnad
 * från posterna ovan) för att den inte bär någon: ett kommandoanrop och fem
 * flaggor, och en klass runt `Artisan::call` vore ett lager utan innehåll.
 *
 * `Artisan::call` kör kommandot i schemaläggarens egen process — ingen
 * `Schedule::command(...)` eller `->runInBackground()`, båda går via Symfony
 * Process/proc_open, avstängt hos inleed i både webb-SAPI och CLI, se
 * AGENTS.md § Driftmiljön saknar proc_open. `queue:work` är i sig en loop i
 * samma process; det är `queue:listen` som startar barnprocesser.
 *
 * Posten ligger SIST med flit, flytta den aldrig uppåt: slår ett jobb i
 * `--timeout` anropar Laravel `Worker::kill()`, som anropar `posix_kill`
 * (avstängt hos inleed) och därefter `exit()` — processen dör mitt i
 * schemaläggningskörningen. Ligger posten sist har allt annat som var i tur
 * redan kört, och nästa minut startar en ny process ändå.
 *
 * `withoutOverlapping(10)` — inte förvalet 1440 minuter. `exit()` ovan
 * hoppar över mutex-städningen som annars sköts av `finish()` i ett finally
 * eller pcntl-signalhanteraren, så låset måste kunna löpa ut av sig självt
 * efter en timeout; med förvalet stannar posten i 24 timmar, tyst. Tio
 * minuter ligger säkert över den lagliga maxkörningen på `--max-time` 50 s
 * plus en sista jobbtimeout på 300 s ≈ 5,8 min, och en fastkilad post
 * självläker inom tio minuter i stället för ett dygn.
 *
 * Sync-grenen är inte en artighet (Beslut 4): sync-drivern kan inte poppas
 * ifrån, och testsviten kör med den.
 */
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
})->everyMinute()->name('drain-queue')->withoutOverlapping(10);
