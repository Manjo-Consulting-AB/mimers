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
