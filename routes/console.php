<?php

use App\Console\AdvancesAccountLifecycle;
use App\Console\DeletesDormantAccounts;
use App\Console\DeliversNotifications;
use App\Console\EnforcesDowngrades;
use App\Console\GeneratesQuotaWarnings;
use App\Console\GeneratesTaskNotifications;
use App\Console\PrunesExpiredMagicLinkTokens;
use App\Console\PurgesExpiredStoredFiles;
use App\Console\PurgesExpiredTrash;
use App\Console\ReconcilesUsageCounters;
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
