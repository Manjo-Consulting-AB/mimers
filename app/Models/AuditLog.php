<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Revisionsloggens rad — systemets egen anteckning om vad som hänt i en
 * container, se [[Konton och åtkomst]] § audit_log och issue 40. Den ENDA vägen
 * in är App\Actions\Audit\RecordAuditEvent (issue 40 § Beslut 6) — allt är
 * därför UTESLUTET ur `#[Fillable]`, sätts explicit av Actionen och aldrig
 * via massilldelning, samma resonemang som App\Models\Notification.
 *
 * Ingen `updated_at`, ingen `deleted_at` (Beslut 3): en revisionslogg som
 * kan ändras är inget bevis, och en som kan mjukraderas är sämre än ingen
 * alls. `const UPDATED_AT = null` stänger av Eloquents andra tidsstämpel;
 * migrationen skapar inte kolumnen. Rader raderas aldrig och gallras aldrig.
 *
 * `action` är ett ÖPPET namnrum (Beslut 6) — `container.transferred` och
 * `access.revoked` är de två första värdena men fler kan komma, så det finns
 * ingen `ACTIONS`-lista här, bara konstanterna så att anroparna (issue 40)
 * aldrig stavar en sträng.
 *
 * `subject_type` är ett domännamn, inte ett klassnamn (Beslut 4): ingen
 * `morphTo()` och ingen `subject()`-relation, av samma skäl som
 * App\Models\ContainerAccess medvetet saknar `grantee()`. `subject_id` bär
 * subjektets ULID och skrivs och läses bara som identifierare, aldrig som
 * join.
 *
 * Samma sak gäller `item_id` sedan issue 107: en identifierare, ingen
 * främmande nyckel och ingen `item()`-relation. Kolumnen sätts på varje
 * händelse som hör till ett item, oavsett subjekt, och gör att en rad kan
 * läsas upp per item. `account_id`, `user_id` och `container_id` förlorade
 * sina nycklar i samma migrering — [[ADR-0043 Tre loggar]] § Händelseloggen:
 * loggen överlever det den handlar om.
 *
 * `meta` bär data om händelsen — kontonas ULID:er, mottagarens typ och nivå
 * — och aldrig en e-postadress eller något annat som loggens läsare inte
 * behöver (Beslut 9 och 10). Castas till array; tom serialiseras som `{}`
 * av resursen, aldrig `[]`.
 */
#[Fillable([])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory, HasUlid;

    /**
     * Revisionsloggen har ingen `updated_at` — se klassdocblocket ovan och
     * migrationens docblock (Beslut 3).
     */
    public const UPDATED_AT = null;

    public const ACTION_CONTAINER_TRANSFERRED = 'container.transferred';

    public const ACTION_ACCESS_REVOKED = 'access.revoked';

    /**
     * Containerns sista rad, skriven av App\Actions\Trash\PurgeContainer
     * (issue 107). Gallringen i issue 115 räknar tolv månader från den.
     */
    public const ACTION_CONTAINER_PURGED = 'container.purged';

    /**
     * Kontots sista rad, skriven av App\Actions\Account\DeleteAccount
     * (issue 107) — för de rader som saknar container. Issue 115 räknar tolv
     * månader från den på samma sätt.
     */
    public const ACTION_ACCOUNT_DELETED = 'account.deleted';

    /*
     * Innehållshändelserna (issue 109). Varje skrivning på ett item och det
     * som hänger på det — itemet självt, taggarna, relationerna, bilagorna
     * och kostnadsraderna — får sitt eget namn här, så anroparna aldrig
     * stavar en sträng. Alla bär `item_id`; [[ADR-0043 Tre loggar]]
     * § Händelseloggen.
     */

    public const ACTION_ITEM_CREATED = 'item.created';

    public const ACTION_ITEM_UPDATED = 'item.updated';

    public const ACTION_ITEM_DELETED = 'item.deleted';

    public const ACTION_ITEM_RESTORED = 'item.restored';

    /**
     * Taggmängden byttes. Egen handling och inte ett fält på `item.updated`:
     * en tagg är en koppling till en annan tabell, och `meta` bär vilka
     * taggar som lades till och togs bort som ULID:er — aldrig deras namn.
     */
    public const ACTION_ITEM_TAGS_CHANGED = 'item.tags_changed';

    public const ACTION_ITEM_LINK_CREATED = 'item_link.created';

    public const ACTION_ITEM_LINK_DELETED = 'item_link.deleted';

    public const ACTION_ATTACHMENT_CREATED = 'attachment.created';

    public const ACTION_ATTACHMENT_DELETED = 'attachment.deleted';

    public const ACTION_ATTACHMENT_RESTORED = 'attachment.restored';

    public const ACTION_COST_ENTRY_CREATED = 'cost_entry.created';

    public const ACTION_COST_ENTRY_UPDATED = 'cost_entry.updated';

    public const ACTION_COST_ENTRY_DELETED = 'cost_entry.deleted';

    /*
     * Uppgifts- och utlåningshändelserna (issue 110). Scheman, förekomster,
     * beroenden och lån — allt som skrivs på en uppgift eller en utlåning.
     * Varje rad bär `item_id`; [[ADR-0043 Tre loggar]] § Händelseloggen.
     */

    public const ACTION_SCHEDULE_CREATED = 'schedule.created';

    /**
     * Schemat ändrades — eller pausades, eller återupptogs: pausen är samma
     * skrivning som en ändring av titeln, bara `meta.changed` skiljer dem.
     */
    public const ACTION_SCHEDULE_UPDATED = 'schedule.updated';

    public const ACTION_SCHEDULE_DELETED = 'schedule.deleted';

    /**
     * Förekomsten bockades av. Den nya förekomsten `CloseOccurrence` öppnar i
     * samma transaktion loggas INTE — den är en följd av avbockningen, inte
     * en handling (issue 110).
     */
    public const ACTION_SCHEDULE_OCCURRENCE_COMPLETED = 'schedule_occurrence.completed';

    /**
     * Förekomsten hoppades över — en EGEN handling, inte en avbockning med en
     * annan flagga: historiken skiljer dem, och nästa `interval`-förfall
     * räknas ur ett annat datum (issue 22b § Beslut 4).
     */
    public const ACTION_SCHEDULE_OCCURRENCE_SKIPPED = 'schedule_occurrence.skipped';

    /**
     * Beroendena bär sina två ULID:er i `meta` och ingen `subject_type`:
     * raden i `schedule_dependency`/`occurrence_dependency` har ingen egen
     * ULID — paret identifierar den (issue 23 § Beslut 1, issue 23b
     * § Beslut 1), samma form som `item_link.created` bär.
     */
    public const ACTION_SCHEDULE_DEPENDENCY_CREATED = 'schedule_dependency.created';

    public const ACTION_SCHEDULE_DEPENDENCY_DELETED = 'schedule_dependency.deleted';

    public const ACTION_OCCURRENCE_DEPENDENCY_CREATED = 'occurrence_dependency.created';

    public const ACTION_OCCURRENCE_DEPENDENCY_DELETED = 'occurrence_dependency.deleted';

    public const ACTION_LOAN_CREATED = 'loan.created';

    public const ACTION_LOAN_UPDATED = 'loan.updated';

    /**
     * Lånet lämnades tillbaka — `returned_at` gick från null till ett datum.
     * Egen handling och inte `loan.updated`: återlämningen är den händelse
     * utlåningen finns för, och historiken (issue 116) formulerar den som en
     * egen mening.
     */
    public const ACTION_LOAN_RETURNED = 'loan.returned';

    public const ACTION_LOAN_DELETED = 'loan.deleted';

    /**
     * Tabellen heter `audit_log`, inte Eloquents standardplural `audit_logs`.
     */
    protected $table = 'audit_log';

    /**
     * Get the attributes that should be cast.
     *
     * `meta` är JSON (i sqlite en TEXT-kolumn) och castas till array — utan
     * castet vore den en sträng. `created_at` saknar `updated_at` att spegla
     * mot och castas därför uttryckligen.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Kontot händelsen rör — den som ägde containern när händelsen skedde,
     * eller kontot en kontonivåhändelse gäller. Null för systemhändelser
     * utan konto.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Den handlande användaren — den som accepterade ett ägarbyte eller
     * återkallade en åtkomst. Null för händelser ett jobb orsakat; resursen
     * hittar då inte på en systemanvändare (issue 40 § Beslut 11).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Containern händelsen gäller, när den gäller en — null för
     * kontonivåhändelser.
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }
}
