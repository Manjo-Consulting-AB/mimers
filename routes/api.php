<?php

use App\Http\Controllers\Api\AccountStorageController;
use App\Http\Controllers\Api\AttachmentController;
use App\Http\Controllers\Api\Auth\AuthenticatedTokenController;
use App\Http\Controllers\Api\Auth\MagicLinkLoginController;
use App\Http\Controllers\Api\Auth\MagicLinkRequestController;
use App\Http\Controllers\Api\Auth\RecoveryCodeController;
use App\Http\Controllers\Api\Auth\RegisteredUserController;
use App\Http\Controllers\Api\Auth\TotpController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContainerAccessController;
use App\Http\Controllers\Api\ContainerController;
use App\Http\Controllers\Api\ContainerInvitationController;
use App\Http\Controllers\Api\ContainerParticipantController;
use App\Http\Controllers\Api\ContainerTrashController;
use App\Http\Controllers\Api\InvitationResponseController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ItemLinkController;
use App\Http\Controllers\Api\ItemSearchController;
use App\Http\Controllers\Api\OccurrenceDependencyController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ScheduleDependencyController;
use App\Http\Controllers\Api\ScheduleOccurrenceController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TodoController;
use App\Http\Controllers\Api\TrashController;
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

    // Issue 11 · Kategorier — en hierarki per container, se
    // App\Http\Controllers\Api\CategoryController och
    // App\Actions\Category\MoveCategory. {category} nästlas under
    // {container} med samma scopeBindings() som gruppen redan har, löst
    // genom App\Models\Container::categories() (issue 11 § Beslut 1) —
    // samma resonemang som {access} ovan. Grindarna är view()/update(),
    // båda befintliga i App\Policies\ContainerPolicy — ingen ny
    // policymetod, se issue 11 § Beslut 2. Ingen show(): trädet hämtas i
    // sin helhet av index().
    Route::get('/containers/{container}/categories', [CategoryController::class, 'index']);
    Route::post('/containers/{container}/categories', [CategoryController::class, 'store']);
    Route::patch('/containers/{container}/categories/{category}', [CategoryController::class, 'update']);
    Route::delete('/containers/{container}/categories/{category}', [CategoryController::class, 'destroy']);

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

    // Issue 13a · Items — the fundamental unit of the product, nested under
    // {container} like categories and tags, see
    // App\Http\Controllers\Api\ItemController and issue 13a § Beslut 1.
    // `scopeBindings()` on the group above also applies to {item} — without
    // it an item ULID from container A resolves under container B, resolved
    // through App\Models\Container::items(). The gates are the existing
    // view()/update() on App\Policies\ContainerPolicy, no new policy method
    // (§ Beslut 2). Tags (`item_tag`) are issue 13b, links between items are
    // issue 14 — neither here.
    Route::get('/containers/{container}/items', [ItemController::class, 'index']);
    Route::post('/containers/{container}/items', [ItemController::class, 'store']);
    Route::get('/containers/{container}/items/{item}', [ItemController::class, 'show']);
    Route::patch('/containers/{container}/items/{item}', [ItemController::class, 'update']);
    Route::delete('/containers/{container}/items/{item}', [ItemController::class, 'destroy']);

    // Issue 16a · Uppladdning av bilagor — bara POST här; listning och
    // radering är 16b, nedladdning 19a. `{item}` nästlas under `{container}`
    // med samma scopeBindings() som items ovan, löst genom
    // App\Models\Container::items(). Grinden är den befintliga update() på
    // App\Policies\ContainerPolicy, ingen ny policymetod (issue 16a §
    // Beslut 1). throttle:uploads — den första rutten som skriver byte ska
    // inte kunna loopas obegränsat, se
    // App\Providers\AppServiceProvider::configureUploadRateLimiting().
    Route::post('/containers/{container}/items/{item}/attachments', [AttachmentController::class, 'store'])
        ->middleware('throttle:uploads');

    // Issue 16b · Bilagelistan och mjukraderingen — GET listar itemets
    // bilagor nyast först, DELETE mjukraderar en (issue 16b § Beslut 1–5).
    // `{attachment}` nästlas under `{item}` med samma scopeBindings() som
    // gruppen redan har, löst genom App\Models\Item::attachments() — hela
    // skyddet mot en bilaga-ULID från ett annat item (Beslut 1). Grindarna
    // är view() (GET) och update() (DELETE), båda befintliga i
    // App\Policies\ContainerPolicy — ingen ny policymetod (Beslut 2).
    Route::get('/containers/{container}/items/{item}/attachments', [AttachmentController::class, 'index']);
    Route::delete('/containers/{container}/items/{item}/attachments/{attachment}', [AttachmentController::class, 'destroy']);

    // Issue 15b · Fritextsök — den ENDA toppnivårutten som rör items, och
    // den enda som finns just för att frågan är global: en sökning över ALLT
    // användaren har åtkomst till, inte inom en pärm hon redan valt (issue
    // 15b § Beslut 5). Rutten bär sitt eget åtkomstfilter (Container::scopeAccessibleBy(),
    // Beslut 4) i stället för rutt-nästlingens grind — och det är därför den
    // är issuens riskyta. Bara `q`, inga tagg-/kategorifilter (Beslut 6).
    // Sökvyn i webben är issue 59.
    Route::get('/items', [ItemSearchController::class, 'index']);

    // Issue 14 · Relationer mellan items, se
    // App\Http\Controllers\Api\ItemLinkController och
    // App\Actions\Item\LinkItems. {item} nästlas under {container} precis som
    // items ovan, och {other} binds INTE av scopeBindings() — motparten slås
    // upp inom containern i destroy() (issue 14 § Beslut 1 och 7). Grindarna
    // är view() (GET) och update() (POST/DELETE), båda befintliga i
    // App\Policies\ContainerPolicy — ingen ny policymetod (§ Beslut 2).
    Route::get('/containers/{container}/items/{item}/links', [ItemLinkController::class, 'index']);
    Route::post('/containers/{container}/items/{item}/links', [ItemLinkController::class, 'store']);
    Route::delete('/containers/{container}/items/{item}/links/{other}', [ItemLinkController::class, 'destroy']);

    // Issue 21 · Scheman — regeln för återkommande underhåll, noll eller
    // flera per item, se App\Http\Controllers\Api\ScheduleController och
    // App\Models\Schedule. {item} nästlas under {container} som items ovan,
    // och {schedule} binds av gruppens scopeBindings() genom
    // App\Models\Item::schedules() — hela skyddet mot ett schema på ett
    // annat item (issue 21 § Beslut 1). Grindarna är view() (GET) och
    // update() (POST/PATCH/DELETE), båda befintliga i
    // App\Policies\ContainerPolicy — ingen ny policymetod (§ Beslut 2).
    // Ingen show(): listan hämtar hela uppsättningen (§ Beslut 1).
    // Ett schema som skapas aktivt öppnar sin första förekomst genom
    // App\Actions\Schedule\OpenNextOccurrence (issue 22a) — men det är
    // ACTIONEN som skapar raden i schedule_occurrence, aldrig en klient
    // via den här rutten.
    Route::get('/containers/{container}/items/{item}/schedules', [ScheduleController::class, 'index']);
    Route::post('/containers/{container}/items/{item}/schedules', [ScheduleController::class, 'store']);
    Route::patch('/containers/{container}/items/{item}/schedules/{schedule}', [ScheduleController::class, 'update']);
    Route::delete('/containers/{container}/items/{item}/schedules/{schedule}', [ScheduleController::class, 'destroy']);

    // Issue 22a · Förekomsterna av ett schema — den öppna plus historiken,
    // se App\Http\Controllers\Api\ScheduleOccurrenceController och
    // App\Http\Resources\ScheduleOccurrenceResource. {schedule} binds av
    // gruppens scopeBindings() genom App\Models\Item::schedules() precis som
    // schemarutterna ovan — ett schema på ett annat item ger 404 (issue 22 §
    // Beslut 1). Bara GET: en förekomst skapas aldrig av en klient, den enda
    // vägen in är App\Actions\Schedule\OpenNextOccurrence. Grinden är den
    // befintliga view() på App\Policies\ContainerPolicy — ingen ny
    // policymetod. Todo-listan över containers (issue 24) lägger sin egen
    // rutt här senare, aldrig i den här listningen.
    Route::get('/containers/{container}/items/{item}/schedules/{schedule}/occurrences', [ScheduleOccurrenceController::class, 'index']);

    // Issue 22b · Avslut — complete()/skip(), se
    // App\Http\Controllers\Api\ScheduleOccurrenceController och
    // App\Actions\Schedule\CloseOccurrence. Två rutter i stället för ett
    // statusfält i kroppen (issue 22b § Beslut 1): en `{"status": "open"}`-
    // kropp vore en väg att återöppna, och det vill vi inte ha. Båda tar
    // `{"account", "completion_note"?}` (Beslut 2). `{occurrence}` binds av
    // gruppens scopeBindings() genom App\Models\Schedule::occurrences()
    // precis som `{schedule}` genom Item::schedules() ovan — en förekomst i
    // ett annat schema ger 404. Grinden är den befintliga update() på
    // App\Policies\ContainerPolicy. Beroendekontrollen (issue 23b) och
    // notisavbrottet (M5) läggs in i CloseOccurrence av sina issues, aldrig
    // som nya rutter här.
    Route::post('/containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}/complete', [ScheduleOccurrenceController::class, 'complete']);
    Route::post('/containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}/skip', [ScheduleOccurrenceController::class, 'skip']);

    // Issue 23b · Beroenden mellan förekomster — "den här gången måste jag
    // måla innan jag sjösätter", se App\Http\Controllers\Api\OccurrenceDependencyController
    // och App\Actions\Schedule\DependOccurrence. {occurrence} är alltid den
    // BEROENDE sidan, den som väntar, och binds av gruppens scopeBindings()
    // genom App\Models\Schedule::occurrences() precis som förekomsterna ovan.
    // {other} binds INTE — motparten slås upp inom containern i destroy(),
    // precis som ScheduleDependencyController och ItemLinkController (issue 23a
    // § Beslut 3). Grindarna är view() (GET) och update() (POST/DELETE), båda
    // befintliga i App\Policies\ContainerPolicy — ingen ny policymetod.
    // GET listar den här förekomstens beroenden (vad den väntar på), inte vad
    // som väntar på den. Arvet från schemanivån sitter i
    // App\Actions\Schedule\OpenNextOccurrence och spärren i avslutsflödet i
    // App\Actions\Schedule\CloseOccurrence — ingendera är en rutt här.
    Route::get('/containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}/dependencies', [OccurrenceDependencyController::class, 'index']);
    Route::post('/containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}/dependencies', [OccurrenceDependencyController::class, 'store']);
    Route::delete('/containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}/dependencies/{other}', [OccurrenceDependencyController::class, 'destroy']);

    // Issue 23a · Beroenden mellan scheman — regeln "impellern kan inte bytas
    // innan motorn är servad", se App\Http\Controllers\Api\ScheduleDependencyController
    // och App\Actions\Schedule\DependSchedule. {schedule} är alltid den
    // BEROENDE sidan, den som väntar, och binds av gruppens scopeBindings()
    // genom App\Models\Item::schedules() precis som schemarutterna ovan
    // (issue 23 § Beslut 3). {other} binds INTE — motparten slås upp inom
    // containern i destroy(), precis som ItemLinkController (issue 14 §
    // Beslut 7). Grindarna är view() (GET) och update() (POST/DELETE), båda
    // befintliga i App\Policies\ContainerPolicy — ingen ny policymetod.
    // GET listar det här schemats beroenden (vad det väntar på), inte vad som
    // väntar på det (§ Beslut 3). Arvet till förekomsterna och spärren i
    // avslutsflödet är issue 23b, todo-listans filtrering issue 24 —
    // ingendera här.
    Route::get('/containers/{container}/items/{item}/schedules/{schedule}/dependencies', [ScheduleDependencyController::class, 'index']);
    Route::post('/containers/{container}/items/{item}/schedules/{schedule}/dependencies', [ScheduleDependencyController::class, 'store']);
    Route::delete('/containers/{container}/items/{item}/schedules/{schedule}/dependencies/{other}', [ScheduleDependencyController::class, 'destroy']);

    // Issue 20a · Papperskorgen — lista och återställ mjukraderat innehåll
    // i en LEVANDE container, se App\Http\Controllers\Api\TrashController och
    // App\Actions\Trash\RestoreContent. Två rutter, nästlade under
    // {container} (issue 20a § Beslut 1): papperskorgen är EN lista, så en
    // rutt per typ (/trash/items, /trash/tags, ...) vore fyra ytor med
    // identisk logik. Grindarna är view() (GET) och update() (POST), båda
    // befintliga i App\Policies\ContainerPolicy — ingen ny policymetod, ingen
    // TrashPolicy. Restore-kroppen tar type+ulid; den enda ruttparametern är
    // {container}, så scopeBindings() på gruppen ändrar ingenting här.
    // Gallringen som tömmer papperskorgen är 20b; papperskorgen för raderade
    // containers är 20c.
    Route::get('/containers/{container}/trash', [TrashController::class, 'index']);
    Route::post('/containers/{container}/trash/restore', [TrashController::class, 'restore']);

    // Issue 20c · Papperskorgen för raderade CONTAINERS — lista och
    // återställ en container som någon råkat radera, se
    // App\Http\Controllers\Api\ContainerTrashController och
    // App\Http\Requests\Trash\RestoreContainerRequest. Toppnivå (issue 20c §
    // Beslut 1): en raderad container kan inte nästlas under sig själv —
    // {container}-bindningen ser bara levande rader — så listan bor på
    // /trash/containers och restore tar ULID:en i kroppen, samma form som
    // 20a § Beslut 6. Listan begränsas av själva frågan (containers vars
    // ägarkonto användaren är medlem i), restore grinden är den befintliga
    // delete()-metoden på App\Policies\ContainerPolicy — ingen ny
    // policymetod (Beslut 2). Gallringen som tömmer den här papperskorgen är
    // samma jobb som 20b, App\Console\PurgesExpiredTrash.
    Route::get('/trash/containers', [ContainerTrashController::class, 'index']);
    Route::post('/trash/containers/restore', [ContainerTrashController::class, 'restore']);

    // Issue 24 · Todo-listan — "vad ska jag göra?", se
    // App\Http\Controllers\Api\TodoController och
    // App\Models\ScheduleOccurrence::scopeTodoFor(). En toppnivårutt precis
    // som fritextsökningen (issue 15b § Beslut 5): frågan är global per
    // definition — alla öppna förekomster över ALLA containers användaren har
    // åtkomst till — så rutten bär sitt eget åtkomstfilter (Container::scopeAccessibleBy()
    // genom relationskedjan, Beslut 2) i stället för rutt-nästlingens grind,
    // och det är därför den är M3:s riskyta. Bara GET: listan är en vy; allt
    // som ändrar en uppgift går genom 22b:s rutter ovan.
    Route::get('/todo', [TodoController::class, 'index']);

    // Issue 28 · Nedgraderingen, steg 2 — kontots urvalslista av bilagor,
    // se App\Http\Controllers\Api\AccountStorageController och
    // App\Policies\AccountPolicy. En KONTOruta, inte en containerruta (issue
    // 28 § Beslut 2): listan är kontots levande bilagor tvärs över containers
    // — kontot är det som belastas, och bilagor kan ligga i containers kontot
    // inte äger — så rutten bär sitt eget filter (billed_account_id) i
    // stället för rutt-nästlingens grind, precis som todo-listan (issue 24).
    // Grindarna är de nya viewStorage()/manageStorage() på AccountPolicy —
    // inte ContainerPolicy (Beslut 4): rensningen är tillåten fastän kontot
    // är read_only, och det är hela poängen med ytan.
    Route::get('/accounts/{account}/storage', [AccountStorageController::class, 'index']);
    Route::delete('/accounts/{account}/storage', [AccountStorageController::class, 'destroy']);
});
