<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\ContainerTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * En mall som kontot har sparat: en containers kategorier och fält, att
 * använda igen — se [[Items och organisation]] § container_template och
 * [[ADR-0089 Egna fält på items]] § Riktning 6 och 9, issue 421.
 *
 * **En mall är en kopia, inte en koppling.** `content` bär trädet och fälten
 * som de såg ut när mallen sparades; ingenting i containern pekar tillbaka på
 * raden här. En ändring i mallen — eller en radering — rör aldrig en container
 * som redan har fått innehållet.
 *
 * `content` är JSON i formen
 * `{"categories": [{"name", "children": [...]}], "fields": [{"label", "type"}]}`,
 * i ordning. Casten till `array` gör att servern läser och skriver formen på
 * ett ställe; klienten ser den som den är.
 *
 * `account_id` och `billed_account_id` står UTESLUTNA ur `#[Fillable]` och
 * sätts explicit av App\Actions\Template\SaveContainerTemplate, samma regel
 * som för Category och ItemField: mallen ägs av kontot, och betalaren är det
 * agerande kontot.
 *
 * **Agenterna når inte hit** (ADR-0089 § Riktning 8): en agent får lista och
 * använda mallar, aldrig spara eller ta bort dem. Modellen bär därför ingen
 * agentmärkning.
 */
#[Fillable(['name', 'content'])]
#[RouteKey('ulid')]
class ContainerTemplate extends Model
{
    /** @use HasFactory<ContainerTemplateFactory> */
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * Tabellen heter `container_template`, inte Eloquents standardplural.
     */
    protected $table = 'container_template';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }

    /**
     * Kontot som äger mallen — samma konto som containern mallen sparades
     * från. Det är också den enda behörighetsfrågan: bara kontots medlemmar
     * når mallen (issue 421 § 4).
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Mallarna i de konton användaren är medlem i — den gemensamma
     * formuleringen av "vilka mallar får hon se", använd av listningen,
     * routebindningen och uppslaget i anropen. Formulera den inte om i
     * kontrollern.
     *
     * @param  Builder<ContainerTemplate>  $query
     * @return Builder<ContainerTemplate>
     */
    public function scopeMemberOf(Builder $query, User $user): Builder
    {
        // `account.id` och inte `id`: kopplingstabellen `account_user` har en
        // egen id-kolumn, så det oqualificerade namnet är tvetydigt i joinen.
        return $query->whereIn('account_id', $user->accounts()->pluck('account.id')->all());
    }
}
