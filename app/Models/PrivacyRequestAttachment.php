<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En fil på ett integritetsärende — se [[ADR-0095 Integritetsbegäranden]]
 * § 10 och [[Konton och åtkomst]] § privacy_request_attachment.
 *
 * Filen ligger på disken `privacy` under `{ärendets ulid}/{bilagans ulid}`,
 * utan filändelse. Raden bär det som beskriver den: originalnamnet,
 * storleken och SHA-256 räknad på servern, samt vem som lade till den.
 *
 * **Ingen koppling till stored_file.** Bilagan dedupliceras inte, räknas
 * inte mot någon kvot och nås aldrig av PurgesExpiredStoredFiles — den hör
 * inte till den innehållsadresserade lagringen (ADR-0006).
 *
 * **Den enda vägen in är App\Actions\Privacy\AttachPrivacyAttachment**, som
 * skapar raden i samma transaktion som händelsen `attachment_added`. Allt är
 * därför UTESLUTET ur `#[Fillable]` och sätts explicit, som på
 * PrivacyRequestEvent: en bilaga som kan skrivas från en klientbegäran hade
 * varit en fil ingen bad om.
 *
 * Ärendet och bilagan skrivs och läses i EN transaktion av actionen;
 * relationen finns för `privacy:show` och för gallringen (issue 487).
 */
#[Fillable([])]
class PrivacyRequestAttachment extends Model
{
    use HasUlid;

    /**
     * Tabellen heter `privacy_request_attachment`, i singular.
     */
    protected $table = 'privacy_request_attachment';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Ärendet bilagan hör till.
     *
     * @return BelongsTo<PrivacyRequest, $this>
     */
    public function privacyRequest(): BelongsTo
    {
        return $this->belongsTo(PrivacyRequest::class);
    }
}
