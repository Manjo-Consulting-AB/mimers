<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En öppningsrad: personen öppnade bilagans byten, se [[ADR-0051 Senast
 * öppnade filer]] och [[M24 Desktopdesignen]] § 177.
 *
 * **Raden är ett par, och paret är unikt** — `(user_id, attachment_id)` bärs
 * av ett unikt index i migrationen, och skrivningen är en upsert på det.
 * Modellen är därför en pivot i samma form som App\Models\RecentVisit: ingen
 * `ulid` (ingen rutt identifierar en enskild rad), ingen `deleted_at` (raden
 * är inget innehåll, och papperskorgen listar fyra typer), och ingen
 * `container_id` (containern härleds ur bilagans item respektive ur bilagans
 * egen kolumn, se App\Models\Attachment::owningContainer()).
 *
 * `user_id` och `attachment_id` är medvetet UTESLUTNA ur `#[Fillable]`, samma
 * regel som App\Models\RecentVisit och App\Models\Favorite: de sätts av
 * App\Actions\Attachment\RecordAttachmentOpen ur den inloggade användaren och
 * ur ruttens bilaga, aldrig via massildelning. `opened_at` står utanför av
 * samma skäl — den är klockan vid skrivningen och ingenting en anropare
 * väljer.
 *
 * **Raden speglar åtkomsten, den ger den inte.** Modellen bär ingen
 * åtkomstregel och prövar ingenting: skrivningen ligger EFTER grinden i
 * App\Http\Controllers\AttachmentDownloadController, och läsningen går genom
 * App\Actions\Attachment\ListRecentOpens, som filtrerar med
 * App\Actions\Access\ResolveItemScope som varje annan listning. En bilaga
 * användaren förlorat åtkomsten till försvinner därför ur listan medan raden
 * ligger kvar — och blir synlig igen om åtkomsten kommer tillbaka.
 *
 * Ingen `HasFactory`: ingen fabrik behövs — en rad skrivs genom sin action,
 * och den är två främmande nycklar och en tidsstämpel.
 */
#[Fillable([])]
class AttachmentOpen extends Model
{
    /**
     * Tabellen heter `attachment_open`, inte Eloquents standardplural.
     */
    protected $table = 'attachment_open';

    /**
     * Tidsstämpeln för öppningen, i UTC som alla tidsstämplar i huset.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
        ];
    }

    /**
     * Användaren öppningen tillhör.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Bilagan öppningen gäller.
     *
     * Relationen bär SoftDeletes-scopet, precis som `RecentVisit::item()` gör
     * för itemet: en mjukraderad bilaga syns inte genom den, och det är vad
     * App\Actions\Attachment\ListRecentOpens vilar på när papperskorgen ska
     * hållas borta ur listan.
     *
     * @return BelongsTo<Attachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
