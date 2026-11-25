<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Carbon\Carbon;
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
 * App\Actions\Privacy\ShowPrivacyRequest, flyttas av
 * App\Actions\Privacy\ChangePrivacyRequestStatus och fördelas av
 * App\Actions\Privacy\AssignPrivacyRequest. Ingen rutt, ingen resurs och
 * inget MCP-verktyg läser tabellen.
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
 *
 * Relationerna kan ge null: `account_id` och `user_id` är valfria och kan
 * peka på ett konto eller en person som raderats sedan kopplingen gjordes.
 *
 * @property-read Account|null $account
 * @property-read User|null $user
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
     * Identiteten eller ett ombuds behörighet måste prövas innan ärendet
     * handläggs vidare — se RecordPrivacyVerification och ADR-0095 § 2.
     */
    public const STATUS_VERIFICATION_REQUIRED = 'verification_required';

    /**
     * Ärendet prövas: vilken typ av behandling det gäller och vilken roll
     * Mimers har (ADR-0095 § 7).
     */
    public const STATUS_UNDER_ASSESSMENT = 'under_assessment';

    /**
     * Begäran verkställs.
     */
    public const STATUS_IN_PROGRESS = 'in_progress';

    /**
     * Handläggaren väntar på kompletterande uppgifter från den registrerade.
     */
    public const STATUS_AWAITING_REQUESTER = 'awaiting_requester';

    /**
     * Begäran bifölls fullt ut. Det enda avslutade läge som inte kräver ett
     * skäl, eftersom bifallet är sitt eget svar (ADR-0095 § 4).
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * Begäran bifölls delvis.
     */
    public const STATUS_PARTIALLY_COMPLETED = 'partially_completed';

    /**
     * Begäran avslogs.
     */
    public const STATUS_REJECTED = 'rejected';

    /**
     * Ärendet avslutades utan sakprövning: begäran återkallades, var en
     * dubblett eller gick inte att knyta till en identitet (ADR-0095 § 4).
     */
    public const STATUS_CLOSED = 'closed';

    /**
     * Låg prioritet, satt av handläggaren när ärendet fördelas.
     */
    public const PRIORITY_LOW = 'low';

    /**
     * Förvalet för ett nytt ärende. Prioriteten sätts av handläggaren när
     * ärendet fördelas.
     */
    public const PRIORITY_NORMAL = 'normal';

    /**
     * Hög prioritet, satt av handläggaren när ärendet fördelas.
     */
    public const PRIORITY_HIGH = 'high';

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
     * Är statusen ett avslutat läge? Uppdelningen i öppna och avslutade
     * lägen bor i config/privacy.php, som också bär den fullständiga listan;
     * en ändring där slår igenom i ChangePrivacyRequestStatus utan att
     * actionen stavar en status.
     */
    public static function isClosedStatus(string $status): bool
    {
        return in_array($status, (array) config('privacy.closed_statuses'), true);
    }

    /**
     * Den URSPRUNGLIGA fristen: mottagandet plus `deadline_months`, utan
     * förlängning. Det är den `privacy:extend` prövar emot — en förlängning
     * efter den vägras, också när fristen redan förlängts, eftersom den
     * registrerade ska ha beskedet inom den första månaden (ADR-0095 § 5).
     *
     * `deadline_at` är den gällande fristen och kan ha flyttats av en
     * förlängning eller av `privacy:deadline`; den här metoden rör den inte.
     */
    public function originalDeadline(): Carbon
    {
        return $this->received_at->copy()
            ->startOfDay()
            ->addMonthsNoOverflow((int) config('privacy.deadline_months'));
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
