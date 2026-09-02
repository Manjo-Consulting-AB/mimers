<?php

use App\Console\PrunesExpiredMagicLinkTokens;
use App\Console\PurgesExpiredStoredFiles;
use App\Console\PurgesExpiredTrash;
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
