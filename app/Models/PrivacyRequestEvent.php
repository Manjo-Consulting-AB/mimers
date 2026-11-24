<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En händelse på ett integritetsärende — se [[ADR-0095
 * Integritetsbegäranden]] § 3 och § 7 samt [[Konton och åtkomst]]
 * § privacy_request_event.
 *
 * **Append-only och den enda vägen in är
 * App\Actions\Privacy\RecordPrivacyEvent.** Allt är därför UTESLUTET ur
 * `#[Fillable]` och sätts explicit av actionen — samma resonemang som
 * App\Models\AuditLog och App\Models\SecurityLog. Ingen `updated_at` och
 * ingen `deleted_at`: en händelse som kan ändras är inget bevis, och raden
 * försvinner bara med sitt ärende.
 *
 * Ingen `ulid`: ingen rutt, ingen resurs och ingen vy identifierar en
 * händelse. `actor` är handläggarens namn ur kommandots `--by`, `body` är
 * fritext och `data` är det som hör till slaget — värdena i `data` kommer ur
 * listorna i config/privacy.php.
 *
 * `EVENT_KINDS` är samma lista som kolumnens CHECK-villkor. Den finns här så
 * att RecordPrivacyEvent kan avvisa ett felstavat slag även i sqlite, där
 * CHECK-villkoret inte läggs på (se migrationen). Namnet bär `EVENT_` med
 * flit: ett naket `KINDS` är containerprovets markör för en stängd lista över
 * containerns art, och den listan får inte finnas (issue 84).
 */
#[Fillable([])]
class PrivacyRequestEvent extends Model
{
    /**
     * Ärendet skapades. Skrivs av OpenPrivacyRequest, i samma transaktion som
     * raden i privacy_request.
     */
    public const KIND_CREATED = 'created';

    /**
     * Ärendet lästes. Skrivs av ShowPrivacyRequest, så att en läsning går att
     * följa (ADR-0095 § 3).
     */
    public const KIND_VIEWED = 'viewed';

    /**
     * Alla slag en händelse kan ha — samma värden som CHECK-villkoret i
     * migrationen. Fler slag hör till senare issuers, men listan är redan nu
     * fullständig eftersom tabellen skapades för hela ärendet.
     *
     * @var list<string>
     */
    public const EVENT_KINDS = [
        self::KIND_CREATED,
        self::KIND_VIEWED,
        'status_changed',
        'assigned',
        'verification',
        'linked',
        'assessment',
        'action',
        'communication',
        'deadline_changed',
        'note',
        'attachment_added',
        'attachment_removed',
        'restriction_placed',
        'restriction_lifted',
    ];

    /**
     * Tabellen heter `privacy_request_event`, i singular.
     */
    protected $table = 'privacy_request_event';

    /**
     * Händelsen har ingen `updated_at` — se klassdocblocket.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Ärendet händelsen hör till.
     *
     * @return BelongsTo<PrivacyRequest, $this>
     */
    public function privacyRequest(): BelongsTo
    {
        return $this->belongsTo(PrivacyRequest::class);
    }
}
