<?php

namespace App\Actions\Invitation;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\AccessLevel;
use Illuminate\Support\Facades\DB;

/**
 * Ändrar nivån på en inbjudan som ännu inte besvarats — se issue 430.
 *
 * **E-postadressen och omfånget rörs aldrig.** `email`, `item_id` och
 * `container_id` står kvar på raden; det är bara nivåerna som går att ändra,
 * och `UpdateInvitationRequest` nekar de andra fälten i kroppen. Att flytta
 * en inbjudan till ett annat item är att skicka en ny inbjudan, inte att
 * ändra den här. Inget nytt mejl skickas heller: mottagaren har redan sitt
 * token, och en nivåändring ska inte se ut som en ny inbjudan i inkorgen.
 *
 * **Samma nivåkontroll som App\Actions\Invitation\CreateInvitation.** Ett
 * `agent_level` över inbjudans egen nivå är `invitation.agent_level_too_high`
 * (422) — annars kunde en ändring ge en inbjudan ett agenttak som
 * `CreateInvitation` hade vägrat att skicka.
 *
 * **Engångsspärren sitter i UPDATE-satsen**, som i
 * App\Actions\Invitation\RevokeInvitation: en rad som inte längre är
 * `pending` — accepterad, avvisad eller återkallad — ändras inte, och
 * svaret är `invitation.not_pending` (422). En utgången rad är fortfarande
 * `pending` i kolumnen (issue 10a § Beslut 7) och går därför att ändra,
 * precis som den går att dra tillbaka.
 *
 * **Ingen `Gate::authorize()`**, samma linje som de två andra actionerna:
 * anroparen prövar behörighet med `manageAccess()`.
 *
 * Sedan issue 430 skrivs `invitation.updated` i samma transaktion som raden,
 * som `CreateInvitation` och `RevokeInvitation` gör sina rader
 * ([[ADR-0043 Tre loggar]] § Händelseloggen). `meta` bär itemets ULID och
 * nivån — **aldrig adressen** (issue 40 § Beslut 10) och aldrig tokenet.
 */
class UpdateInvitation
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  Container  $container  Containern inbjudan hör till, redan
     *                                upplöst av route-modellbindningen. Bärs
     *                                för loggradens `container_id`,
     *                                `account_id` och `meta.item`.
     * @param  Invitation  $invitation  Raden ur route-modellbindningen.
     * @param  string  $level  Ett steg i AccessLevel::LADDER.
     * @param  string|null  $agentLevel  Ägarens tak för den inbjudnas agenter,
     *                                   eller `null` för ingen agentåtkomst.
     *                                   Aldrig högre än `$level`.
     * @param  User  $actor  Den som ändrar; blir `user_id` på loggraden.
     *
     * @throws ApiException 422 `invitation.agent_level_too_high`, 422
     *                      `invitation.not_pending`.
     */
    public function handle(
        Container $container,
        Invitation $invitation,
        string $level,
        ?string $agentLevel,
        User $actor,
    ): Invitation {
        if ($agentLevel !== null && AccessLevel::rank($agentLevel) > AccessLevel::rank($level)) {
            throw ApiException::make('invitation.agent_level_too_high', ['level' => $level, 'agent_level' => $agentLevel], 422);
        }

        DB::transaction(function () use ($container, $invitation, $level, $agentLevel, $actor): void {
            $updated = Invitation::query()
                ->whereKey($invitation->getKey())
                ->where('status', 'pending')
                ->update([
                    'level' => $level,
                    'agent_level' => $agentLevel,
                ]);

            if ($updated !== 1) {
                throw ApiException::make('invitation.not_pending', ['invitation' => $invitation->ulid], 422);
            }

            // `withTrashed()`: en inbjudan till ett sedan länge mjukraderat
            // item ska loggas med sitt item, inte som `null` — samma skäl som
            // App\Actions\Invitation\RevokeInvitation använder det.
            $item = $invitation->item_id === null
                ? null
                : Item::withTrashed()->whereKey($invitation->item_id)->first();

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_INVITATION_UPDATED,
                account: $container->account,
                user: $actor,
                container: $container,
                item: $item,
                subjectType: 'invitation',
                subjectUlid: $invitation->ulid,
                meta: [
                    'item' => $item?->ulid,
                    'level' => $level,
                ],
            );
        });

        return $invitation->refresh();
    }
}
