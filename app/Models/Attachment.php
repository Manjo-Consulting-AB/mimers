<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kopplingen mellan ett item och de lagrade bytena, med användarens eget
 * filnamn — se [[Filer och lagring]] § attachment och [[ADR-0006
 * Innehållsadresserad lagring]]. Två användare som laddar upp samma manual
 * får två rader här, en rad i stored_file; varje rad bär sitt eget `filename`.
 *
 * `item_id`, `stored_file_id`, `uploaded_by_user_id` och `billed_account_id`
 * är medvetet UTESLUTNA ur `#[Fillable]`: de sätts explicit av
 * App\Actions\Attachment\StoreAttachment (från rutten, från dedupresultatet,
 * från token och från kroppens `account`), aldrig via massildelning — samma
 * resonemang som `Item`, se issue 16a § Beslut 14.
 */
#[Fillable(['filename', 'kind'])]
#[RouteKey('ulid')]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory, HasUlid, SoftDeletes;

    /**
     * Tabellen heter `attachment`, inte Eloquents standardplural
     * `attachments`.
     */
    protected $table = 'attachment';

    /**
     * Itemet bilagan sitter på.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Bytena bilagan pekar på. `mime_type` och `byte_size` läses härifrån
     * av App\Http\Resources\AttachmentResource — `kind` och `filename`
     * ligger på den här raden.
     *
     * @return BelongsTo<StoredFile, $this>
     */
    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class);
    }

    /**
     * Användaren som laddade upp bilagan, alltid från token (issue 16a §
     * Beslut 14).
     *
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * Kontot som betalar för bytena — det uppladdande kontot, inte
     * containerns ägare (issue 16a § Beslut 2). Läses av M4:s
     * förbrukningsräkning (issue 26).
     *
     * @return BelongsTo<Account, $this>
     */
    public function billedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'billed_account_id');
    }
}
