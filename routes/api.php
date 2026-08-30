<?php

use App\Http\Controllers\Api\Auth\AuthenticatedTokenController;
use App\Http\Controllers\Api\Auth\MagicLinkLoginController;
use App\Http\Controllers\Api\Auth\MagicLinkRequestController;
use App\Http\Controllers\Api\Auth\RecoveryCodeController;
use App\Http\Controllers\Api\Auth\RegisteredUserController;
use App\Http\Controllers\Api\Auth\TotpController;
use App\Http\Controllers\Api\ContainerAccessController;
use App\Http\Controllers\Api\ContainerController;
use App\Http\Controllers\Api\ContainerInvitationController;
use App\Http\Controllers\Api\ContainerParticipantController;
use App\Http\Controllers\Api\InvitationResponseController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Support\Auth\LoginRateLimiter;
use Illuminate\Support\Facades\Route;

/*
 * Issue 4 · Autentisering med lösenord. Personal access tokens för
 * mobilappar och B2B, se [[ADR-0011 Autentisering]]. Delar
 * RegisterRequest/LoginRequest med webbens sessionsinloggning i
 * routes/web.php — samma ogiltiga indata avvisas likadant på båda ytorna.
 */
Route::post('/register', [RegisteredUserController::class, 'store']);

// throttle:login · issue 7 · Rate limiting och felkodsformat. Se
// App\Providers\AppServiceProvider::configureLoginRateLimiting().
Route::post('/login', [AuthenticatedTokenController::class, 'store'])
    ->middleware('throttle:'.LoginRateLimiter::NAME);

/*
 * Issue 5 · Magic link. throttle:login återanvänds rakt av på
 * begäranrutten, se motsvarande kommentar i routes/web.php och
 * App\Support\Auth\MagicLinkBroker. Konsumtionsrutten utfärdar en
 * personal access token, se App\Http\Controllers\Api\Auth\MagicLinkLoginController.
 */
Route::post('/login/magic-link', [MagicLinkRequestController::class, 'store'])
    ->middleware('throttle:'.LoginRateLimiter::NAME);

Route::post('/login/magic-link/consume', [MagicLinkLoginController::class, 'store']);

// scopeBindings(): issue 9b § Beslut 1 — {access} nedan måste slås upp
// INOM {container}, annars går det att återkalla en åtkomst i fel
// container via en ULID från en annan (behörighetshål). Ingen effekt på
// övriga rutter i gruppen, som bara har ett rutt-parametrar var.
Route::middleware('auth:sanctum')->scopeBindings()->group(function () {
    Route::post('/logout', [AuthenticatedTokenController::class, 'destroy']);

    // Samma kontroller som webbens verification.send, se
    // App\Http\Controllers\Auth\EmailVerificationNotificationController.
    Route::post('/email/verification-notification', EmailVerificationNotificationController::class);

    // Issue #19 · TOTP-hemlighet: aktivering och verifiering. Se
    // App\Http\Controllers\Api\Auth\TotpController och
    // App\Support\Auth\TotpBroker. Inloggningskravet är issue 6b (#31).
    Route::post('/totp', [TotpController::class, 'store']);
    Route::post('/totp/confirm', [TotpController::class, 'confirm']);
    Route::delete('/totp', [TotpController::class, 'destroy']);

    // Issue 6c · Återställningskoder. Se
    // App\Http\Controllers\Api\Auth\RecoveryCodeController och
    // App\Support\Auth\RecoveryCodeBroker.
    Route::post('/totp/recovery-codes', [RecoveryCodeController::class, 'store']);

    // Issue 8 · Container — CRUD-ytan, se App\Http\Controllers\Api\ContainerController
    // och App\Policies\ContainerPolicy. Behörighet avgörs helt i policyn,
    // se issue 8 § Beslut 2. Webbens containervyer är issue 54, se
    // issue 8 § Beslut 1 — inga rutter här läggs i routes/web.php.
    Route::get('/containers', [ContainerController::class, 'index']);
    Route::post('/containers', [ContainerController::class, 'store']);
    Route::get('/containers/{container}', [ContainerController::class, 'show']);
    Route::patch('/containers/{container}', [ContainerController::class, 'update']);
    Route::delete('/containers/{container}', [ContainerController::class, 'destroy']);

    // Issue 9b · Åtkomstytan — bevilja, lista och återkalla delegerade
    // åtkomster till en container, se
    // App\Http\Controllers\Api\ContainerAccessController och
    // App\Policies\ContainerPolicy::viewAccesses()/manageAccess()/revokeAccess().
    // Deltagarlistan (vem som HAR åtkomst just nu, sedd av alla deltagare)
    // är en annan yta, issue 9c — läggs inte här.
    Route::get('/containers/{container}/accesses', [ContainerAccessController::class, 'index']);
    Route::post('/containers/{container}/accesses', [ContainerAccessController::class, 'store']);
    Route::delete('/containers/{container}/accesses/{access}', [ContainerAccessController::class, 'destroy']);

    // Issue 9c · Deltagarlistan — vem som HAR åtkomst just nu, läsbar för
    // VARJE deltagare och inte bara ägarkontot. Se
    // App\Http\Controllers\Api\ContainerParticipantController. Grinden är
    // App\Policies\ContainerPolicy::view(), den befintliga — inte
    // viewAccesses() ovan, som är ägarkontots förvaltningsvy (issue 9c §
    // Beslut 2). Bara GET: listan är en vy, aldrig ett sätt att ändra
    // något (§ Beslut 1). Ansiktsraden i webben är issue 55.
    Route::get('/containers/{container}/participants', [ContainerParticipantController::class, 'index']);

    // Issue 10a · Inbjudningar — avsändarytan: bjud in en e-postadress som
    // ännu inte har konto, lista containerns inbjudningar, dra tillbaka en
    // som skickats fel. Se
    // App\Http\Controllers\Api\ContainerInvitationController och
    // App\Policies\ContainerPolicy::viewAccesses()/manageAccess(), som
    // används oförändrade sedan 9b (issue 10a § Beslut 10).
    // scopeBindings() på gruppen ovan gäller även {invitation} — utan det
    // går en inbjudan i container B att dra tillbaka via container A:s
    // rutt. Mottagarsidan (mejlet, acceptera, avvisa) är issue 10b och
    // ligger nedan.
    Route::get('/containers/{container}/invitations', [ContainerInvitationController::class, 'index']);
    Route::post('/containers/{container}/invitations', [ContainerInvitationController::class, 'store']);
    Route::delete('/containers/{container}/invitations/{invitation}', [ContainerInvitationController::class, 'destroy']);

    // Issue 10b · Inbjudningar — mottagarsidan: acceptera eller avvisa en
    // inbjudan man fått i ett mejl. Se
    // App\Http\Controllers\Api\InvitationResponseController.
    //
    // Tokenet ligger i KROPPEN och inte i sökvägen (issue 10b § Beslut 1),
    // av samma skäl som /login/magic-link/consume ovan: ett token i en URL
    // hamnar i åtkomstloggar, i `Referer` och i webbläsarhistoriken.
    // Därför inga rutt-parametrar här, och `scopeBindings()` på gruppen
    // saknar betydelse för de här två.
    //
    // Inget `verified`-middleware: verifieringskravet gäller bara accept
    // och kollas i kod, så klienten får veta VARFÖR den nekades och kan
    // skicka användaren till "verifiera din e-post" (§ Beslut 6 och 7).
    Route::post('/invitations/accept', [InvitationResponseController::class, 'accept']);
    Route::post('/invitations/reject', [InvitationResponseController::class, 'reject']);

    // Issue 12 · Taggar — platt lista per container, se
    // App\Http\Controllers\Api\TagController. Ingen show(), se issue 12 §
    // Beslut 1. `scopeBindings()` på gruppen ovan gäller även {tag} — utan
    // det löser en tagg-ULID från container A upp under container B, se
    // App\Models\Container::tags(). Grindarna är `view` (GET) och `update`
    // (POST/PATCH/DELETE) på App\Policies\ContainerPolicy, inte en ny
    // TagPolicy — issue 12 § Beslut 2. Kopplingen till items (`item_tag`)
    // är issue 13b, filtrering på tagg är issue 15a — ingendera här.
    Route::get('/containers/{container}/tags', [TagController::class, 'index']);
    Route::post('/containers/{container}/tags', [TagController::class, 'store']);
    Route::patch('/containers/{container}/tags/{tag}', [TagController::class, 'update']);
    Route::delete('/containers/{container}/tags/{tag}', [TagController::class, 'destroy']);
});
