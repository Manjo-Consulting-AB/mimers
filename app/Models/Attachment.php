<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use App\Models\Concerns\RecordsAgentDeletion;
use Database\Factories\AttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kopplingen mellan ett item, en container eller en person och de lagrade
 * bytena, med användarens eget filnamn — se [[Filer och lagring]]
 * § attachment och [[ADR-0006 Innehållsadresserad lagring]]. Två användare
 * som laddar upp samma manual får två rader här, en rad i stored_file; varje
 * rad bär sitt eget `filename`.
 *
 * **Exakt en av `item_id`, `container_id` och `user_id` är satt**
 * ([[ADR-0047 Containerns bild]] och [[ADR-0076 Profilbilden]] § 1).
 * Villkoret upprätthålls av ett CHECK i databasen; den här klassen läser det
 * genom `accessSubject()` och `owningContainer()` i stället för att varje
 * läsare själv ska fråga "vilket item tillhör bilagan?".
 *
 * `item_id`, `container_id`, `user_id`, `stored_file_id`,
 * `uploaded_by_user_id` och `billed_account_id` är medvetet UTESLUTNA ur
 * `#[Fillable]`: de sätts explicit av App\Actions\Attachment\StoreAttachment
 * (från rutten, från dedupresultatet, från token och från kroppens
 * `account`), aldrig via massildelning — samma resonemang som `Item`, se
 * issue 16a § Beslut 14.
 */
#[Fillable(['filename', 'kind'])]
#[RouteKey('ulid')]
class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory, HasUlid, RecordsAgentDeletion, SoftDeletes;

    /**
     * Tabellen heter `attachment`, inte Eloquents standardplural
     * `attachments`.
     */
    protected $table = 'attachment';

    /**
     * Itemet bilagan sitter på, eller null för en containerbilaga.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Containern bilagan sitter på, eller null för en itembilaga.
     *
     * Relationen är den RÅA kolumnen. Den som vill veta vilken container en
     * bilaga hör till — oavsett slag — frågar owningContainer() i stället;
     * för en itembilaga är den här null medan owningContainer() ger itemets
     * container.
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * Personen bilagan hör till, eller null för en item- eller
     * containerbilaga. Det är profilbildens ägare ([[ADR-0076 Profilbilden]]
     * § 1).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Modellen behörigheten prövas mot: containern för en containerbilaga,
     * itemet för en itembilaga, personen för en personbilaga. Null när ingen
     * av dem finns.
     *
     * **Frågan "vilket item tillhör bilagan?" ställs här och inte hos
     * anroparen** ([[ADR-0047 Containerns bild]] § Beslut, sista stycket).
     * Grinden blir `view` på containern när `container_id` är satt, `view` på
     * itemet när `item_id` är satt och `viewAvatar` på personen när
     * `user_id` är satt — containern för att bilden är containerns ansikte och
     * den som når containern ser den, itemet för att [[ADR-0028 Åtkomst på
     * itemnivå]] gäller en itembilaga, och personen för att synligheten står i
     * [[ADR-0076 Profilbilden]] § 4.
     *
     * Containern och personen läses genom relationerna. Containern bär sitt
     * SoftDeletes-scope — en mjukraderad container svarar null, precis som ett
     * mjukraderat item gör — medan `user` inte har någon soft delete alls.
     * Anroparen behöver alltså bara pröva `trashed()` på det som faktiskt kan
     * vara mjukraderat.
     */
    public function accessSubject(): Item|Container|User|null
    {
        if ($this->user_id !== null) {
            return $this->user;
        }

        return $this->container_id !== null ? $this->container : $this->item;
    }

    /**
     * Containern bilagan hör till, oavsett slag: sin egen när `container_id`
     * är satt, annars itemets. Null när ingen av dem finns — eller när
     * containern är mjukraderad, eftersom relationsläsningen bär
     * SoftDeletes-scopet.
     *
     * Byggd på accessSubject() och inte på en egen gren: två formuleringar av
     * "bilagans container" glider isär, och den ena hade glömt containergrenen.
     */
    public function owningContainer(): ?Container
    {
        $subject = $this->accessSubject();

        // En personbilaga hör till en person och aldrig till en container —
        // profilbilden följer inte med någon container ([[ADR-0076
        // Profilbilden]] § 5).
        if ($subject instanceof User) {
            return null;
        }

        return $subject instanceof Container ? $subject : $subject?->container;
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
