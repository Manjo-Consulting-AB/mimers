<?php

namespace App\Models;

use Database\Factories\LegalHoldFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En rättslig spärr på ett konto — se [[ADR-0043 Tre loggar]] § Den
 * rättsliga spärren, [[Registerförteckning]] och migrationen.
 *
 * Spärren sätts när en anmälan enligt DSA artikel 16 behöver utredas, när en
 * myndighet begär det, eller när vi själva misstänker ett brott. Den stoppar
 * gallringen av kontots innehåll och kontoraderingen av kontot, och den syns
 * inte för användaren: allt fungerar som vanligt i vyerna, det som raderas
 * hamnar i papperskorgen — det är bara gallringen som uteblir.
 *
 * **Ett konto är spärrat när det har en rad utan `lifted_at`.** Den frågan
 * ställs på ett enda ställe, `covers()`, och de två jobb som raderar hårt
 * anropar den: App\Console\PurgesExpiredTrash och
 * App\Console\DeletesDormantAccounts. Kontrollen ligger i jobben och inte i
 * App\Actions\Trash\PurgeContainer eller App\Actions\Account\DeleteAccount —
 * de är verktygen, jobben är grindarna.
 *
 * Ingen `deleted_at`: en rad tas aldrig bort, och en hävd spärr lämnar sin
 * rad kvar (ADR-0043). Raderna skrivs och läses bara av
 * App\Actions\LegalHold\PlaceLegalHold och LiftLegalHold, som anropas från
 * kommandoraden — tabellen har ingen yta i webben och inget API.
 */
#[Fillable(['account_id', 'case_number', 'reason', 'lifted_at'])]
class LegalHold extends Model
{
    /** @use HasFactory<LegalHoldFactory> */
    use HasFactory;

    /**
     * Tabellen heter `legal_hold`, inte Eloquents standardplural.
     */
    protected $table = 'legal_hold';

    /**
     * `lifted_at` är NULL så länge spärren gäller och en tidpunkt när den
     * hävts. Casten behövs för att kolumnen ska gå att jämföra och rendera
     * som en Carbon — i sqlite (testsviten) ligger tidsstämpeln som text.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lifted_at' => 'datetime',
        ];
    }

    /**
     * Kontot spärren gäller. Relationen är den vanliga BelongsTo trots att
     * kolumnen saknar främmande nyckel (se migrationen) — den används av
     * fabriken och av den som läser en rad för hand, och `covers()` går
     * förbi den och frågar på `account_id` för att slippa ladda kontot.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Gäller en rättslig spärr för kontot? Den enda fråga gallringsjobben
     * ställer, och den enda formuleringen av den.
     *
     * Ingen egen regel per jobb: en spärr som bara en del av gallringen
     * känner till är värre än ingen spärr, för den ser ut att gälla.
     * När issue 115 lägger till loggarnas gallring är det den här frågan
     * som även det jobbet ställer.
     */
    public static function covers(Account $account): bool
    {
        return self::query()
            ->where('account_id', $account->id)
            ->whereNull('lifted_at')
            ->exists();
    }
}
