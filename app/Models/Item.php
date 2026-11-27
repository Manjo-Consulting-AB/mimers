<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use App\Models\Concerns\RecordsAgentDeletion;
use App\Support\Access\ItemScope;
use Carbon\Carbon;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;

/**
 * The fundamental unit of the product — everything the user wants to
 * remember is an item, see [[Items och organisation]] § item. This issue
 * builds the table, the model and the CRUD surface; tags (`item_tag`) and
 * item-to-item links are later issues (13b and 14).
 *
 * `container_id`, `category_id`, `cover_attachment_id`, `created_by_user_id`
 * and `created_by_account_id` are deliberately EXCLUDED from `#[Fillable]`:
 * the controller sets them explicitly (from the route, from the validated
 * `category` ULID, from the validated `cover` ULID, and from the token/body
 * respectively), never via mass assignment — same reasoning as
 * `Container::$account_id`. `created_at`/`updated_at` timestamps are set by
 * Eloquent.
 *
 * **The six fixed fields are no longer item columns.** `manufacturer`,
 * `model`, `serial_number`, `purchased_at`, `warranty_until` and
 * `position_note` are container fields with their values in
 * `item_field_value` since issue 420, and issue 425 drops the columns
 * ([[ADR-0089 Egna fält på items]] § Riktning 5). They are neither
 * mass-assignable nor read through this model.
 */
#[Fillable(['name', 'description', 'notes', 'icon', 'icon_color'])]
#[RouteKey('ulid')]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, HasUlid, RecordsAgentDeletion, Searchable, SoftDeletes;

    /**
     * Paletten för itemets ikonfärg — åtta nycklar, aldrig en hex-kod
     * ([[ADR-0060 Itemets ikon]] § 5). Det här är det enda stället servern
     * känner till färgerna; klientens tokens `--color-tint-<nyckel>` läses
     * ur samma lista i `itemIconPresentation.js`, men servern validerar mot
     * den här. Paletten saknar rött med flit: `danger` betyder att något
     * har passerat ([[ADR-0057 Ikoner och typsnitt]] § 3).
     *
     * @var list<string>
     */
    public const ICON_COLORS = ['slate', 'blue', 'sky', 'teal', 'green', 'amber', 'orange', 'violet'];

    /**
     * The table is called `item`, not Eloquent's default plural `items`.
     */
    protected $table = 'item';

    /**
     * The searchable columns, issue 15b § Beslut 3 and now issue 420: the
     * item's own text columns — `name`, `description` and `notes`. The six
     * fixed fields are no longer item columns. Their values are searched
     * through `item_field_value.value_text` by
     * App\Models\Item::scopeMatchingText(), which is the one place the app
     * formulates "what free text matches".
     *
     * The keys are the columns, the values are ignored by the engine. Since
     * issue 425 the FULLTEXT index on `item` is `(name, description, notes)` —
     * exactly these three columns — so the two lists are a documented mirror
     * that tests/Feature/Item/AnteckningsfaltTest checks.
     *
     * No #[SearchUsingFullText] on purpose: that attribute makes Scout emit
     * `whereFullText(...)`, which MariaDB handles and SQLite (the in-memory
     * test database) does not — the suite would fall on every search
     * (Beslut 3). The FULLTEXT indexes therefore stay unused until CI runs
     * against MariaDB or Meilisearch arrives; turning it on is one line.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'notes' => $this->notes,
        ];
    }

    /**
     * Begränsar frågan till de items vars fritext matchar $term: itemets egna
     * textkolumner (`toSearchableArray()`) och de textvärden som hör till
     * itemet i `item_field_value.value_text` — issue 420 och
     * [[Items och organisation]] § Sök och filtrering.
     *
     * **Formuleringen bor HÄR** därför att båda sökvägarna
     * (App\Actions\Item\SearchAccessibleItems och
     * App\Actions\Item\ListItems) måste söka samma sak: en andra avskrift
     * glider isär precis som en andra formulering av "vad mottagaren når".
     *
     * `%term%` och inte `whereFullText`: samma LIKE-formulering som Scout's
     * databasdrivrutin använder i dag, och den enda som SQLite i testsviten
     * klarar (se toSearchableArray()). `item_field_value.value_text` bär
     * dessutom ett eget FULLTEXT-index, redo när sökningen flyttas till
     * MariaDB eller Meilisearch.
     *
     * Ett värde i ett fält som ligger i papperskorgen räknas inte: fältet
     * syns inte på itemet och ska inte heller göra det i sökningen
     * ([[ADR-0089 Egna fält på items]] § Riktning 3).
     *
     * @param  Builder<Item>  $query
     * @return Builder<Item>
     */
    public function scopeMatchingText(Builder $query, string $term): Builder
    {
        $like = '%'.$term.'%';
        $columns = array_keys($this->toSearchableArray());

        return $query->where(function (Builder $query) use ($like, $columns): void {
            foreach ($columns as $column) {
                $query->orWhere($query->qualifyColumn($column), 'like', $like);
            }

            $query->orWhereExists(function ($sub) use ($like): void {
                $sub->selectRaw('1')
                    ->from('item_field_value')
                    ->join('item_field', 'item_field.id', '=', 'item_field_value.item_field_id')
                    ->whereColumn('item_field_value.item_id', 'item.id')
                    ->whereNull('item_field.deleted_at')
                    ->where('item_field_value.value_text', 'like', $like);
            });
        });
    }

    /**
     * The container the item belongs to. Every item lives in exactly one
     * container; the route nests `{item}` under `{container}` and resolves
     * it through App\Models\Container::items() (issue 13a § Beslut 1).
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * The category the item belongs to, at most one ([[ADR-0004 Fria taggar
     * och kategorier]]) — or null for an uncategorised item. Referenced by
     * ULID in the API, resolved within the container by the request
     * validation, see issue 13a § Beslut 7.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The account the item is attributed to — the yard, not the employee
     * (issue 13a § Beslut 6). Read by App\Http\Resources\ItemResource as
     * `created_by_account`; `created_by_user` is deliberately not exposed
     * in this issue.
     *
     * @return BelongsTo<Account, $this>
     */
    public function createdByAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'created_by_account_id');
    }

    /**
     * Itemets taggar — flera, till skillnad från kategorin som det finns
     * högst en av, se [[ADR-0004 Fria taggar och kategorier]] och issue 13b.
     * Kopplingen bor i `item_tag` och sätts med itemet, aldrig via egna
     * rutter (issue 13b § Beslut 2).
     *
     * `->withTimestamps()` fyller `created_at`/`updated_at` på pivotraden —
     * kolumnerna finns i migrationen och ska fyllas (issue 13b § Att se upp
     * med). Uppsättningen sätts i klump med samma ersätt-semantik som
     * `sync()` men konstant frågeantal, se
     * App\Http\Controllers\Api\ItemController::replaceTags.
     *
     * SoftDeletes' globala scope gäller genom relationen: en mjukraderad
     * tagg försvinner ur itemets svar medan pivotraden ligger kvar, så en
     * återupplivad tagg kommer tillbaka på sina items (issue 13b § Beslut 9).
     *
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'item_tag')
            ->withTimestamps();
    }

    /**
     * Itemets bilagor — de uppladdade filerna, se [[Filer och lagring]] §
     * attachment och issue 16a/16b. Listningen i
     * App\Http\Controllers\Api\AttachmentController::index() går genom den
     * här relationen, och det är DEN som scopeBindings() löser `{attachment}`
     * inom `{item}` genom — en bilaga på ett annat item ger 404 (issue 16b §
     * Beslut 1). Mjukraderade bilagor filtreras bort av SoftDeletes globala
     * scope medan raden ligger kvar, redo för papperskorgen (issue 20a).
     *
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Itemets scheman — reglerna för återkommande underhåll, noll eller
     * flera, se [[Scheman och uppgifter]] § schedule och [[ADR-0005 Schema
     * och förekomst]] (issue 21). Listningen i
     * App\Http\Controllers\Api\ScheduleController::index() går genom den här
     * relationen, och det är DEN som scopeBindings() löser `{schedule}`
     * inom `{item}` genom — ett schema på ett annat item ger 404 (issue 21 §
     * Beslut 1). Mjukraderade scheman filtreras bort av SoftDeletes globala
     * scope medan raden ligger kvar.
     *
     * Inga förekomster här: `schedule_occurrence` skapas i issue 22a.
     *
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    /**
     * Itemets utlåningar — historiken plus högst en öppen, se
     * [[Items och organisation]] § loan och issue 76. Listningen i
     * App\Http\Controllers\Api\LoanController::index() går genom den här
     * relationen, och det är DEN som scopeBindings() löser `{loan}` inom
     * `{item}` genom — ett lån på ett annat item ger 404 (issue 76 § Beslut
     * 5). Mjukraderade lån filtreras bort av SoftDeletes globala scope medan
     * raden ligger kvar.
     *
     * @return HasMany<Loan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * The item's cost entries — the history of what it has cost, see
     * [[Items och organisation]] § cost_entry, [[ADR-0016 Kostnadsregistrering]]
     * and issue 45a. The listing in
     * App\Http\Controllers\Api\CostEntryController::index() goes through this
     * relation, and it is THIS one that scopeBindings() resolves `{cost}`
     * within `{item}` through — a cost entry on another item gives 404
     * (issue 45a § Beslut 7). Soft-deleted entries are filtered out by
     * SoftDeletes' global scope while the row stays put.
     *
     * @return HasMany<CostEntry, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(CostEntry::class);
    }

    /**
     * Itemets versioner, nyast först — ögonblicksbilderna före varje ändring
     * ([[ADR-0088 Versionshistorik för items]] och issue 411).
     *
     * Ingen SoftDeletes på `item_version`: en version tas bort för gott och
     * går aldrig via papperskorgen. Ordningen är `created_at` fallande med
     * `id` som andrasortering, så två versioner inom samma sekund ändå får en
     * stabil ordning.
     *
     * @return HasMany<ItemVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ItemVersion::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Itemets EGNA fält — de som gäller bara det här itemet, utöver
     * containerns, se [[ADR-0089 Egna fält på items]] § Riktning 2 och issue
     * 417. Containerns fält ligger i App\Models\Container::fields().
     *
     * Ordningen är `position` stigande med `id` som tiebreak. Relationen
     * krävs dessutom av `scopeBindings()`: `{ownField}` under itemet löses
     * genom den, så en ULID för ett annat items fält — eller för ett
     * containerfält — blir 404.
     *
     * Globala SoftDeletes-scopet gäller: ett fält i papperskorgen är inte
     * med.
     *
     * @return HasMany<ItemField, $this>
     */
    public function ownFields(): HasMany
    {
        return $this->hasMany(ItemField::class, 'item_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * Itemets fältvärden — en rad per fält som har ett värde, oavsett om
     * fältet är containerns eller itemets eget, se [[ADR-0089 Egna fält på
     * items]] § Riktning 9 och issue 418.
     *
     * Relationen finns för App\Http\Resources\ItemResource: värdena läses
     * per `item_field_id` i minnet i stället för en fråga per fält. Raden
     * adresseras med item och fält och har ingen egen `ulid`.
     *
     * Ett värde i ett fält som ligger i papperskorgen finns kvar här medan
     * definitionen är raderad; resursen ritar det inte, eftersom fältet
     * självt saknas i `container.fields` och `ownFields`. Det kommer
     * tillbaka med fältet ([[ADR-0089 Egna fält på items]] § Riktning 3).
     *
     * @return HasMany<ItemFieldValue, $this>
     */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(ItemFieldValue::class);
    }

    /**
     * Ett items egna fält följer itemet till papperskorgen och tillbaka, se
     * [[ADR-0089 Egna fält på items]] § Riktning 2 och 3 och
     * [[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26:
     * medan itemet ligger i papperskorgen syns dess fält ingenstans, och
     * när itemet kommer tillbaka gör fälten det också.
     *
     * **EN tidsstämpel binder dem samman**, samma mekanism som kategorins
     * underträd (App\Actions\Category\DeleteCategory): fälten får itemets
     * `deleted_at`, och återställningen tar bara tillbaka de fält som delar
     * just den tiden. Ett eget fält som raderades FÖRE itemet har en annan
     * tidsstämpel och ligger kvar i papperskorgen för sig.
     *
     * Kroken ligger i modellen och inte i App\Actions\Item\DeleteItem,
     * eftersom den måste gälla varje väg som mjukraderar ett item — API:et,
     * webben och containerpapperskorgen. Den hänger på `trashed`, inte på
     * `deleting`: `deleting` körs FÖRE `runSoftDelete()`, alltså innan
     * itemet har fått sin `deleted_at`, och ett eget `now()` där kan hamna en
     * sekund före itemets `freshTimestamp()` — då matchar återställningen
     * ingenting och fälten blir kvar i papperskorgen som skräp. `trashed`
     * (registrerad med `softDeleted()`) körs efter att `deleted_at` satts, så
     * fälten får exakt itemets tidsstämpel. En fysisk gallring rör inga fält
     * här — `trashed` avfyras aldrig för den; App\Actions\Trash\PurgeContent
     * bokför och raderar dem själv, i samma transaktion.
     *
     * **En rubrik som containern har fått medan itemet låg i papperskorgen
     * vinner.** Ett eget fält vars rubrik krockar med ett levande
     * containerfält lämnas kvar i papperskorgen i stället för att ge två
     * fält med samma rubrik på itemet; det går att återställa för sig när
     * rubriken är ledig ([[ADR-0089 Egna fält på items]] § Riktning 9).
     */
    protected static function booted(): void
    {
        static::softDeleted(function (Item $item): void {
            ItemField::query()
                ->where('item_id', $item->getKey())
                ->whereNull('deleted_at')
                ->update(['deleted_at' => $item->deleted_at]);
        });

        static::restoring(function (Item $item): void {
            $item->ownFieldsRestoredAt = $item->deleted_at;
        });

        static::restored(function (Item $item): void {
            if ($item->ownFieldsRestoredAt === null) {
                return;
            }

            $fields = ItemField::onlyTrashed()
                ->where('item_id', $item->getKey())
                ->where('deleted_at', $item->ownFieldsRestoredAt)
                ->get();

            foreach ($fields as $field) {
                $conflict = ItemField::query()
                    ->where('container_id', $item->container_id)
                    ->whereNull('item_id')
                    ->where('label', $field->label)
                    ->exists();

                if ($conflict) {
                    continue;
                }

                $field->restore();
                $field->clearAgentDeletionMark();
            }

            $item->ownFieldsRestoredAt = null;
        });
    }

    /**
     * Tidsstämpeln som de egna fälten kastades med i den pågående
     * återställningen — satt av `restoring`, läst och nollad av `restored`
     * (se `booted()`). Ingen kolumn och ingen relation: den lever bara under
     * ett `restore()`.
     */
    protected ?Carbon $ownFieldsRestoredAt = null;

    /**
     * Begränsar frågan till items som bär ALLA taggar i $tagIds — flera
     * taggar kombineras med OCH (issue 15a § Beslut 2). En join mot
     * `item_tag` med `whereIn('tag_id', $ids)`, grupperad på itemets nyckel
     * med `HAVING COUNT(DISTINCT tag_id) = <antal>` (Beslut 3) — inte en
     * `whereHas()` per tagg, som ger en underfråga per filtervärde och gör
     * indexet `(tag_id, item_id)` från 13b § Beslut 1 meningslös.
     *
     * `$tagIds` är LÖPNUMMER, inte ULID:er — kontrollern löser upp ULID:erna
     * inom containern först, en fråga oavsett antal (issue 15a § Beslut 9).
     *
     * @param  Builder<Item>  $query
     * @param  list<int>  $tagIds
     * @return Builder<Item>
     */
    public function scopeWithAllTags(Builder $query, array $tagIds): Builder
    {
        $matching = DB::table('item_tag')
            ->select('item_id')
            ->whereIn('tag_id', $tagIds)
            ->groupBy('item_id')
            ->havingRaw('COUNT(DISTINCT tag_id) = ?', [count($tagIds)]);

        return $query->whereIn('item.id', $matching);
    }

    /**
     * Begränsar frågan till items i någon av kategorierna $categoryIds —
     * kategorin själv plus alla ättlingar, som
     * App\Actions\Category\ResolveCategoryDescendants har räknat ut (issue
     * 15a § Beslut 4). Ättlingsupplösningen ligger ALLTID i Actionen,
     * aldrig inlindad i ett scope (Beslut 6).
     *
     * @param  Builder<Item>  $query
     * @param  list<int>  $categoryIds
     * @return Builder<Item>
     */
    public function scopeInCategoryTree(Builder $query, array $categoryIds): Builder
    {
        return $query->whereIn('category_id', $categoryIds);
    }

    /**
     * Begränsar frågan till de items $scope når — issue 73 § Beslut 1. Ett
     * OMFATTANDE omfång (ägarkontots medlem, container-bred grant) lägger
     * inget villkor alls: `itemIds()` svarar `null` och "hela containern"
     * ska inte materialiseras till en `whereIn` med varje löpnummer.
     *
     * Formuleringen bor HÄR och skrivs inte för hand i en controller —
     * två formuleringar av "vad mottagaren når" glider isär precis som två
     * formuleringar av "giltig access" (issue 9a § Beslut 8).
     *
     * Kolumnen kvalificeras (`item.id`, inte `id`): scopet används både på
     * rena item-frågor och i Scouts `query()`-callback, och kostnadsrapporten
     * och exporten joinar mot `item` i issue 74. En otvetydig kolumn är
     * billigare än en join som plötsligt får en tvetydig `id`.
     *
     * @param  Builder<Item>  $query
     * @return Builder<Item>
     */
    public function scopeInScope(Builder $query, ItemScope $scope): Builder
    {
        return $scope->isUnrestricted()
            ? $query
            : $query->whereIn('item.id', $scope->itemIds());
    }

    /**
     * Markeringarna på det här itemet — vilka som har det som favorit, se
     * [[ADR-0042 Designsystemet]] § Konsekvenser och [[M17 Designsystemet]]
     * § 105. Spegeln av App\Models\User::favorites(), och den sida av paret
     * som det omvända indexet `(item_id, user_id)` bär.
     *
     * Relationen är "vilka har märkt itemet", aldrig "itemet är en favorit":
     * en favorit är ett faktum om relationen mellan en användare och ett
     * item, och det finns därför ingen flagga att läsa på itemet självt —
     * och inget fält i App\Http\Resources\ItemResource.
     *
     * @return HasMany<Favorite, $this>
     */
    public function favoritedBy(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * Länkarna där det här itemet är från-sidan (`from_item_id`), se
     * [[Items och organisation]] § item_link och issue 14. Tillsammans med
     * linksTo() täcker de LÄSNINGEN i
     * App\Http\Controllers\Api\ItemLinkController::index() — de två
     * relationerna kombineras med union (issue 14 § Beslut 8), så listan
     * aldrig blir N+1. Inget mer: inga `parents()`/`children()`/`related()`-
     * hjälprelationer, och relationerna bäddas inte in i ItemResource (issue
     * 14 § Beslut 9).
     *
     * @return HasMany<ItemLink, $this>
     */
    public function linksFrom(): HasMany
    {
        return $this->hasMany(ItemLink::class, 'from_item_id');
    }

    /**
     * Länkarna där det här itemet är till-sidan (`to_item_id`), se
     * linksFrom() ovan.
     *
     * @return HasMany<ItemLink, $this>
     */
    public function linksTo(): HasMany
    {
        return $this->hasMany(ItemLink::class, 'to_item_id');
    }
}
