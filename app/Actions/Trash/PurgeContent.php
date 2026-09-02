<?php

namespace App\Actions\Trash;

use App\Actions\Attachment\PurgeAttachment;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Gallrar innehåll ur papperskorgen — den enda vägen till permanent radering
 * av mjukraderat innehåll (issue 20b). Varje metod tar en rad som redan fått
 * beskedet att den ska bort och lämnar databasen konsistent; den som anropar
 * (App\Console\PurgesExpiredTrash, 20c, issue 28) väljer vad som gallras och
 * ser till att retentionen har passerats — Actionen är verktyget, inte
 * grinden, precis som PurgeAttachment.
 *
 * Fyra publika metoder, en per typ (Beslut 2): `attachment()`, `item()`,
 * `category()`, `tag()`. Varje metod kapslar sitt arbete i en transaktion —
 * ett delvis genomfört item får aldrig lämnas kvar med bilagor borta.
 *
 * Främmande nycklar är RESTRICT genomgående ([[Datamodell – översikt]]), så
 * ordningen är inte en detalj: bilagorna och pivotraderna måste bort före
 * itemet, pekarna in i en kategori måste nollställas före kategorin. Gör man
 * det i fel ordning faller gallringen på ett främmandenyckelfel varje natt.
 *
 * En bilaga gallras ALLTID genom PurgeAttachment (Beslut 3), aldrig med en
 * egen forceDelete: den Actionen minskar stored_file.reference_count och
 * sätter purge_after när räknaren når noll. En andra väg förbi den vore den
 * dubbla sanning som [[ADR-0006 Innehållsadresserad lagring]] varnar för —
 * räknaren och bytena glider isär och antingen läcker lagring för alltid
 * eller raderar bytes någon annan fortfarande äger.
 */
class PurgeContent
{
    public function __construct(
        private readonly PurgeAttachment $purgeAttachment,
    ) {}

    /**
     * Gallrar en bilaga. Hela poängen är delegationen: det här är den enda
     * väg som går genom PurgeAttachment (Beslut 3), som minskar räknaren och
     * markerar bytena för fysisk radering — den här metoden vet inget om
     * varken raden eller räknaren.
     */
    public function attachment(Attachment $attachment): void
    {
        $this->purgeAttachment->handle($attachment);
    }

    /**
     * Gallrar ett item med allt som hänger på det (Beslut 4). Ett item som
     * gallras finns det inget att hänga en bilaga på längre, och FK:n är
     * RESTRICT — den kan alltså inte ligga kvar. Ordningen per item:
     *
     * 1. varje bilaga på itemet — ÄVEN de som aldrig mjukraderades — genom
     *    PurgeAttachment,
     * 2. item_tag-raderna för itemet, hårt (pivoten har varken `ulid` eller
     *    `deleted_at`, issue 13b § Beslut 3),
     * 3. item_link-raderna där itemet är `from_item_id` ELLER `to_item_id`,
     *    hårt — en länk kan peka på itemet från andra hållet, och en hasMany
     *    i en riktning hittar bara hälften,
     * 4. forceDelete på itemet.
     *
     * Allt i EN transaktion: en bilaga som hunnit bort men inte itemet vore
     * en permanent radering av innehåll som papperskorgen fortfarande lovar
     * att kunna visa.
     */
    public function item(Item $item): void
    {
        DB::transaction(function () use ($item): void {
            $itemId = $item->getKey();

            // withTrashed() — bilagor som mjukraderats syns inte annars, och
            // de måste med (Beslut 4). Relationen följer också det globala
            // scopet, så uppslaget görs som en egen fråga.
            foreach (Attachment::withTrashed()->where('item_id', $itemId)->get() as $bilaga) {
                $this->purgeAttachment->handle($bilaga);
            }

            DB::table('item_tag')->where('item_id', $itemId)->delete();

            ItemLink::query()
                ->where('from_item_id', $itemId)
                ->orWhere('to_item_id', $itemId)
                ->delete();

            $item->forceDelete();
        });
    }

    /**
     * Gallrar en kategori genom att först nollställa pekarna in i sig
     * (Beslut 5): `item.category_id` och `category.parent_id` är båda
     * nullbara och RESTRICT, så ingendera kan peka på en borta kategori.
     * Båda uppslagen tar med mjukraderade rader — underkategorin som ligger
     * i papperskorgen med en förälder som gallras blir rotkategori, och ett
     * mjukraderat item tappar sin kategorikoppling precis som ett levande.
     *
     * Kategorin har då legat raderad i 30 dagar och har i praktiken redan
     * varit osynlig för de items som pekade på den. Alternativet — att vägra
     * gallra så länge något pekar — ger en papperskorg som aldrig töms.
     */
    public function category(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            $categoryId = $category->getKey();

            Item::withTrashed()
                ->where('category_id', $categoryId)
                ->update(['category_id' => null]);

            Category::withTrashed()
                ->where('parent_id', $categoryId)
                ->update(['parent_id' => null]);

            $category->forceDelete();
        });
    }

    /**
     * Gallrar en tagg (Beslut 6). `item_tag`-pivotraderna tas hårt, sedan
     * forceDelete på taggen. Ingen annan tabell pekar på `tag`.
     */
    public function tag(Tag $tag): void
    {
        DB::transaction(function () use ($tag): void {
            DB::table('item_tag')->where('tag_id', $tag->getKey())->delete();

            $tag->forceDelete();
        });
    }
}
