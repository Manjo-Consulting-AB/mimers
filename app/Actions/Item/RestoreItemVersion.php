<?php

namespace App\Actions\Item;

use App\Actions\Field\FieldRules;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemVersion;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Återställer ett item till en version, se [[ADR-0088 Versionshistorik för
 * items]] Riktning 5 och issue 413.
 *
 * **Skrivningen går genom App\Actions\Item\UpdateItem.** Versionen fyller
 * itemet och lämnar de önskade mängderna till samma action som redigerings-
 * formuläret använder, så att återställningen loggas och bokförs som en
 * vanlig ändring. `restoredFrom` säger åt UpdateItem att spara nuläget med
 * `breakBurst: true` FÖRE skrivningen — det är den versionen som gör
 * återställningen ångringsbar.
 *
 * **Det som inte längre finns hoppas över och lämnar det nuvarande värdet
 * orört** (Riktning 5, utformad av Claude): en mjukraderad eller raderad
 * kategori, en tagg som inte finns, och en omslagsbild som inte längre är en
 * levande bilaga på itemet. Fälten hoppas över som HELHET — en taggmängd där
 * en tagg saknas rörs inte alls — och `handle()` svarar med fältens namn i
 * ordningen `category`, `tags`, `cover`, så att API:ets `meta.skipped` och
 * webbens kvitto säger samma sak.
 *
 * **Fältvärdena följer versionens helhet.** Varje fält som itemet kan bära
 * ett värde i idag skrivs till versionens värde, eller töms när versionen
 * saknar ett värde: en återställning ska ge itemets fält som de var, inte
 * bara lägga versionens värden ovanpå de nuvarande. Ett fält som har raderats
 * sedan versionen spelades in finns inte bland de tillämpliga fälten och
 * hoppas därför över — [[ADR-0089 Egna fält på items]] § Riktning 9 — utan
 * att räknas i `skipped`, som bara namnger de tre fälten i issuen.
 *
 * Behörigheten prövas av anroparen (`ItemPolicy::update`); actionen äger bara
 * skrivningen. Skriver en agent (`$agentId`, issue 415) får både
 * återställningens egen `item.updated`-rad (via App\Support\Mcp\AgentAudit)
 * och versionen av nuläget agenten som aktör.
 */
class RestoreItemVersion
{
    public function __construct(private readonly UpdateItem $updateItem) {}

    /**
     * @param  int|null  $agentId  Agenten när en agent återställer (issue 415).
     * @return list<string> de fält som hoppades över: `category`, `tags`, `cover`
     */
    public function handle(Item $item, ItemVersion $version, User $actor, ?int $agentId = null): array
    {
        $skipped = ['category' => false, 'tags' => false, 'cover' => false];

        $item->fill([
            'name' => $version->name,
            'description' => $version->description,
            'notes' => $version->notes,
            'icon' => $version->icon,
            'icon_color' => $version->icon_color,
        ]);

        $this->restoreCategory($item, $version, $skipped);
        $tags = $this->restoreTags($item, $version, $skipped);
        $this->restoreCover($item, $version, $skipped);

        $this->updateItem->handle(
            $item,
            $actor,
            $tags !== null,
            $tags ?? new Collection,
            $this->fieldEntries($item, $version),
            restoredFrom: $version->ulid,
            agentId: $agentId,
        );

        return array_keys(array_filter($skipped));
    }

    /**
     * Kategorin, eller det nuvarande värdet när versionens kategori inte
     * längre finns i containern.
     *
     * @param  array<string, bool>  $skipped
     */
    private function restoreCategory(Item $item, ItemVersion $version, array &$skipped): void
    {
        if ($version->category_id === null) {
            $item->category_id = null;

            return;
        }

        $category = Category::query()
            ->whereKey($version->category_id)
            ->where('container_id', $item->container_id)
            ->first();

        if ($category === null) {
            $skipped['category'] = true;

            return;
        }

        $item->category_id = $category->id;
    }

    /**
     * Taggmängden, eller null när någon av versionens taggar inte längre
     * finns — då rörs den nuvarande mängden inte alls.
     *
     * @param  array<string, bool>  $skipped
     * @return Collection<int, Tag>|null
     */
    private function restoreTags(Item $item, ItemVersion $version, array &$skipped): ?Collection
    {
        $ids = array_values(array_unique(array_map('intval', $version->tag_ids ?? [])));

        if ($ids === []) {
            return new Collection;
        }

        $tags = Tag::query()
            ->whereIn('id', $ids)
            ->where('container_id', $item->container_id)
            ->get();

        if ($tags->count() !== count($ids)) {
            $skipped['tags'] = true;

            return null;
        }

        return $tags;
    }

    /**
     * Omslagsbilden, eller det nuvarande värdet när bilagan inte längre är en
     * levande bilaga på itemet.
     *
     * @param  array<string, bool>  $skipped
     */
    private function restoreCover(Item $item, ItemVersion $version, array &$skipped): void
    {
        if ($version->cover_attachment_id === null) {
            $item->cover_attachment_id = null;

            return;
        }

        $alive = $item->attachments()
            ->whereKey($version->cover_attachment_id)
            ->exists();

        if (! $alive) {
            $skipped['cover'] = true;

            return;
        }

        $item->cover_attachment_id = $version->cover_attachment_id;
    }

    /**
     * Versionens fältvärden översatta till den form UpdateItem tar emot:
     * varje fält itemet kan bära ett värde i idag, med versionens värde —
     * eller null när versionen inte hade något värde, så att en senare
     * skrivning töms. Ett fält som raderats sedan versionen spelades in
     * saknas bland de tillämpliga fälten och hoppas över.
     *
     * @return list<array{field: string, value: mixed}>
     */
    private function fieldEntries(Item $item, ItemVersion $version): array
    {
        $values = $version->field_values ?? [];
        $entries = [];

        foreach (FieldRules::applicableFields($item->container, $item)->get() as $field) {
            $entries[] = [
                'field' => $field->ulid,
                'value' => $values[$field->id] ?? null,
            ];
        }

        return $entries;
    }
}
