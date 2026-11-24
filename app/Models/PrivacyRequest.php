<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\PrivacyRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ett ärende om en begäran från en registrerad enligt art. 15–21 — se
 * [[ADR-0095 Integritetsbegäranden]] och [[Konton och åtkomst]]
 * § privacy_request.
 *
 * Ytan är kommandon, inte en sida (ADR-0095 § 1): ärendet skapas av
 * App\Actions\Privacy\OpenPrivacyRequest, läses av
 * App\Actions\Privacy\ShowPrivacyRequest och flyttas av senare issuers
 * actions. Ingen rutt, ingen resurs och inget MCP-verktyg läser tabellen.
 *
 * **`reference` genereras och sätts explicit** av OpenPrivacyRequest och står
 * därför utanför `#[Fillable]`, tillsammans med `ulid` som HasUlid fyller i.
 * Formen är `PR-` och åtta tecken i Crockfords base32 —
 * `newReference()` är den enda platsen som stavar alfabetet.
 *
 * **`account_id` och `user_id` är identifierare utan främmande nycklar**,
 * som i `legal_hold`: ärendet överlever den radering det beställer. De sätts
 * först när identiteten är prövad (ADR-0095 § 2), och relationerna nedan
 * finns för den som läser en rad för hand — de kan peka på något som inte
 * finns längre.
 *
 * Ärendet skriver inget till `audit_log` eller `security_log` (ADR-0095
 * § 3). Händelserna bor i privacy_request_event.
 */
#[Fillable([
    'type',
    'status',
    'priority',
    'requester_name',
    'requester_email',
    'on_behalf_of',
    'account_id',
    'user_id',
    'role',
    'assignee',
    'description',
    'received_at',
    'deadline_at',
    'extended_months',
    'restriction_scope',
    'restricted_at',
    'restriction_lifted_at',
    'outcome_reason',
    'closed_at',
])]
class PrivacyRequest extends Model
{
    /** @use HasFactory<PrivacyRequestFactory> */
    use HasFactory, HasUlid;

    /**
     * Ärendet är mottaget men inte prövat. Det läge OpenPrivacyRequest
     * skapar, se ADR-0095 § 4.
     */
    public const STATUS_RECEIVED = 'received';

    /**
     * Förvalet för ett nytt ärende. Prioriteten sätts av handläggaren när
     * ärendet fördelas (issue 482).
     */
    public const PRIORITY_NORMAL = 'normal';

    /**
     * Rollen är obestämd till bedömningen avgör om Mimers är
     * personuppgiftsansvarig eller biträde för det begäran gäller
     * (ADR-0095 § 2 och § 7).
     */
    public const ROLE_UNDETERMINED = 'undetermined';

    /**
     * Prefixet i referensen. Formatet står i [[Konton och åtkomst]]
     * § privacy_request.
     */
    private const REFERENCE_PREFIX = 'PR-';

    /**
     * Crockfords base32 utan `I`, `L`, `O` och `U` — samma alfabet som
     * App\Actions\Voucher\GenerateVoucher använder, av samma skäl: tecknen
     * ska gå att läsa upp över telefon utan att `1` och `0` förväxlas.
     */
    private const REFERENCE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const REFERENCE_LENGTH = 8;

    /**
     * Tabellen heter `privacy_request`, i singular som `voucher` och
     * `legal_hold`.
     */
    protected $table = 'privacy_request';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extended_months' => 'integer',
            'received_at' => 'datetime',
            'deadline_at' => 'datetime',
            'restricted_at' => 'datetime',
            'restriction_lifted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * En ny referens — `PR-` och åtta slumpade tecken. Unikheten prövas av
     * den som skriver raden (OpenPrivacyRequest), för den här metoden känner
     * inte tabellen.
     *
     * `random_int` och aldrig `rand`/`mt_rand`: referensen är det den
     * registrerade får och handläggaren slår upp, och den ska inte gå att
     * förutsäga (ADR-0095 § 3).
     */
    public static function newReference(): string
    {
        $tecken = '';

        for ($i = 0; $i < self::REFERENCE_LENGTH; $i++) {
            $tecken .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
        }

        return self::REFERENCE_PREFIX.$tecken;
    }

    /**
     * Ärendets händelser. Append-only, i den ordning de skrevs — den som
     * läser dem väljer själv om hen vill ha dem i tidsordning.
     *
     * @return HasMany<PrivacyRequestEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PrivacyRequestEvent::class);
    }

    /**
     * Kontot ärendet gäller, när kopplingen gjorts. Kolumnen saknar främmande
     * nyckel (se migrationen), så relationen kan peka på ett raderat konto —
     * det är avsiktligt: ärendet överlever raderingen.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Personen ärendet gäller, när kopplingen gjorts. Samma avvägning som
     * `account()`: ingen främmande nyckel, och ett raderat konto lämnar
     * `account_id` orört.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
