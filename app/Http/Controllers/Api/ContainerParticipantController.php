<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParticipantResource;
use App\Models\Account;
use App\Models\Container;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Deltagarlistan: vilka som har åtkomst till containern JUST NU, läsbar för
 * varje deltagare — inte bara för ägarkontot. Se issue 9c och
 * [[Konton och åtkomst]] § Behörighetsregler, stycket "Att hantera
 * åtkomster och att se dem är två olika saker".
 *
 * En IDENTITETSLISTA, inte en förvaltningsvy. Förvaltningsvyn — nivåer,
 * utgångsdatum, vem som beviljade, återkallad historik och obesvarade
 * inbjudningar — är ägarkontots och bor i
 * App\Http\Controllers\Api\ContainerAccessController respektive
 * App\Http\Controllers\Api\ContainerInvitationController. Den här klassen
 * lägger sig BREDVID dem, den ändrar dem inte.
 *
 * INGEN behörighetslogik bor här (ADR-0024, issue 9c § Beslut 9): metoden
 * anropar bara `Gate::authorize('view', ...)` och litar på svaret från
 * App\Policies\ContainerPolicy::view(). Grinden är `view` och INTE
 * `viewAccesses()` — de heter nästan likadant och betyder motsatta saker.
 * `viewAccesses()` är ägarkontots förvaltningsvy; `view` är "får läsa
 * containern", vilket inkluderar en `read`-guest. Det är hela poängen: den
 * som LÄSER något känsligt ska veta vem mer som kan.
 */
class ContainerParticipantController extends Controller
{
    /**
     * GET /api/containers/{container}/participants — 200.
     *
     * Deltagarna är modellens deltagare (issue 9c § Beslut 3): ägarkontot
     * som EN post med `role: owner`, plus en post per GILTIG
     * `container_access`-rad med `role` satt till radens `kind`
     * (`member` | `managed` | `guest`). Ett `managed`-konto med tolv
     * anställda blir en post, inte tolv — att expandera det vore att lämna
     * ut ett varvs personalregister till en charterkund. Ägarkontots
     * medlemmar räknas av samma skäl inte upp var för sig.
     *
     * Giltighet läses ur `ContainerAccess::scopeValid()` (§ Beslut 5) —
     * villkoret formuleras inte om här, se issue 9a § Beslut 8. Återkallade
     * och utgångna rader faller alltså bort; att de syns i historiken är
     * förvaltningsvyns sak (9b § Beslut 10). Obesvarade inbjudningar hör
     * inte hit alls (§ Beslut 4): en inbjudan är inte åtkomst, och att visa
     * den skulle röja en adress för någon som ännu inte sagt ja — därför
     * rörs `invitation` inte av den här frågan.
     *
     * Sedan issue 72 § Beslut 9 grupperas raderna på
     * `(grantee_type, grantee_id)`: en mottagare med fyra itemåtkomster ger
     * EN post, inte fyra. Posten är oförändrad i form och bär ingen
     * `item`-nyckel, ingen `reach` och ingen `level` — att deltagarlistan
     * inte avslöjar vem som har vilket omfång är samma princip som redan
     * gäller nivåer och utgångsdatum, se [[ADR-0028 Åtkomst på itemnivå]]
     * § Konsekvenser och App\Http\Resources\ParticipantResource.
     *
     * Har en mottagare flera rader med olika `kind` — teoretiskt möjligt —
     * vinner den rad som sorteras först på `created_at`. `kind` styr bara
     * presentation (issue 9a § Beslut 6) och aldrig behörighet, så vilken
     * som vinner spelar ingen roll; sorteringen på `id` efter `created_at`
     * gör ändå valet deterministiskt.
     *
     * Ordningen är ägaren först, sedan `created_at` stigande (§ Beslut 7).
     * Ingen sortering på namn — den skulle flytta runt posterna varje gång
     * någon döper om sitt konto. `id` är bara ett deterministiskt
     * andrahandsled: `created_at` har sekundupplösning, så två rader som
     * beviljas i samma sekund skulle annars kunna byta plats mellan två
     * anrop. Löpnumret styr bara sorteringen och lämnar aldrig svaret.
     *
     * TRE frågor, oavsett antal deltagare (§ Beslut 8): åtkomstraderna, en
     * `User::whereIn(...)` och en `Account::whereIn(...)` där ägarkontot
     * hämtas i samma svep. Samma teknik som 9b § Beslut 11 —
     * `ContainerAccess` har medvetet ingen `grantee()`-relation, se
     * modellens docblock, och `$container->account` läses aldrig här
     * eftersom det hade blivit en fjärde fråga.
     */
    public function index(Container $container): JsonResponse
    {
        Gate::authorize('view', $container);

        $accesses = $container->accesses()
            ->valid()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $userIds = $accesses->where('grantee_type', 'user')
            ->pluck('grantee_id')
            ->unique()
            ->values()
            ->all();

        $accountIds = $accesses->where('grantee_type', 'account')
            ->pluck('grantee_id')
            ->push($container->account_id)
            ->unique()
            ->values()
            ->all();

        $users = User::query()->whereIn('id', $userIds)->get(['id', 'ulid', 'name'])->keyBy('id');
        $accounts = Account::query()->whereIn('id', $accountIds)->get(['id', 'ulid', 'name'])->keyBy('id');

        $ownerAccount = $accounts->get($container->account_id);

        $participants = [[
            'type' => 'account',
            'ulid' => $ownerAccount->ulid,
            'name' => $ownerAccount->name,
            'role' => 'owner',
        ]];

        $sedda = [];

        foreach ($accesses as $access) {
            $nyckel = $access->grantee_type.':'.$access->grantee_id;

            if (isset($sedda[$nyckel])) {
                continue;
            }

            $sedda[$nyckel] = true;

            $grantee = $access->grantee_type === 'user'
                ? $users->get($access->grantee_id)
                : $accounts->get($access->grantee_id);

            $participants[] = [
                'type' => $access->grantee_type,
                'ulid' => $grantee->ulid,
                'name' => $grantee->name,
                'role' => $access->kind,
            ];
        }

        return ParticipantResource::collection($participants)->response();
    }
}
