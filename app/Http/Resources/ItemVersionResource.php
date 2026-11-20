<?php

namespace App\Http\Resources;

use App\Models\Agent;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\ItemField;
use App\Models\ItemVersion;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Formatet för en version av ett item, se [[ADR-0088 Versionshistorik för
 * items]] Riktning 2 och 5 och issue 413.
 *
 * **Alla itemets fält, och pekarna som ULID.** Fälten är desamma som
 * versionen sparade: `name`, `description`, `notes`, `icon`, `icon_color`,
 * `category`, `cover`, `tags` och `fields`. `category`, `cover`, `tags[].ulid`
 * och `fields[].id` är ULID:er — löpnummer läcker aldrig ut
 * (AGENTS.md § Databaskonventioner). `replaced_by` säger vem som ersatte
 * versionen: agentens namn när `replaced_by_agent_id` finns, annars
 * användarens, med `agent` som en flagga så vyn kan skilja fallen.
 *
 * **Fälten bär sitt värde och sin etikett.** `fields` är de fältvärden
 * versionen sparade — fältet slås upp med `withTrashed()`, så en etikett som
 * raderats sedan dess går fortfarande att läsa. Ett värde är alltid en sträng
 * eller null; tal och datum lagras som sina råa strängar, som
 * App\Actions\Item\RecordItemVersion skrev dem.
 *
 * **Inga frågor per rad.** `prepare()` laddar kategorier, omslag, användare,
 * agenter, taggar och fält för HELA samlingen i ett fast antal frågor, och
 * lägger dem som relationer på varje version. Kontrollerna anropar den före
 * `collection()`/`make()`, så listan kostar lika många frågor oavsett antal
 * versioner. Utan den hade resursen slagit upp varje pekare per rad.
 *
 * @mixin ItemVersion
 */
class ItemVersionResource extends JsonResource
{
    /**
     * Laddar versionernas pekare i ett fast antal frågor.
     *
     * @param  iterable<ItemVersion>  $versions
     */
    public static function prepare(iterable $versions): void
    {
        $versions = collect($versions);

        if ($versions->isEmpty()) {
            return;
        }

        $categories = self::byId(Category::withTrashed(), $versions->pluck('category_id')->filter()->unique()->all());
        $covers = self::byId(Attachment::withTrashed(), $versions->pluck('cover_attachment_id')->filter()->unique()->all());
        $users = self::byId(User::query(), $versions->pluck('replaced_by_user_id')->filter()->unique()->all());
        $agents = self::byId(Agent::query(), $versions->pluck('replaced_by_agent_id')->filter()->unique()->all());

        $tagIds = $versions
            ->flatMap(fn (ItemVersion $version): array => array_map('intval', $version->tag_ids ?? []))
            ->unique()
            ->values()
            ->all();

        $fieldIds = $versions
            ->flatMap(fn (ItemVersion $version): array => array_keys($version->field_values ?? []))
            ->unique()
            ->values()
            ->all();

        $tags = self::byId(Tag::withTrashed(), $tagIds);
        $fields = self::byId(ItemField::withTrashed(), $fieldIds);

        foreach ($versions as $version) {
            $version->setRelation('category', $categories->get($version->category_id));
            $version->setRelation('coverAttachment', $covers->get($version->cover_attachment_id));
            $version->setRelation('replacedByUser', $users->get($version->replaced_by_user_id));
            $version->setRelation('replacedByAgent', $agents->get($version->replaced_by_agent_id));
            $version->setRelation(
                'tags',
                $tags->only(array_map('intval', $version->tag_ids ?? []))->values(),
            );
            $version->setRelation(
                'fields',
                $fields->only(array_keys($version->field_values ?? []))->values(),
            );
        }
    }

    /**
     * @param  list<int|string>  $ids
     * @return EloquentCollection<int, Model>
     */
    private static function byId(Builder $query, array $ids): EloquentCollection
    {
        if ($ids === []) {
            return new EloquentCollection;
        }

        return $query->whereKey($ids)->get()->keyBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'description' => $this->description,
            'notes' => $this->notes,
            'icon' => $this->icon,
            'icon_color' => $this->icon_color,
            'category' => $this->categoryUlid(),
            'cover' => $this->coverUlid(),
            'tags' => $this->tagList(),
            'fields' => $this->fieldList(),
            'replaced_at' => $this->replaced_at->toIso8601String(),
            'replaced_by' => $this->replacedBy(),
        ];
    }

    /**
     * Kategorins ULID, även när kategorin sedan dess ligger i papperskorgen.
     */
    private function categoryUlid(): ?string
    {
        /** @var Category|null $category */
        $category = $this->relationLoaded('category')
            ? $this->getRelation('category')
            : ($this->category_id === null ? null : Category::withTrashed()->find($this->category_id));

        return $category?->ulid;
    }

    /**
     * Omslagsbildens ULID, även när bilagan sedan dess ligger i
     * papperskorgen — versionen pekar på den som den var.
     */
    private function coverUlid(): ?string
    {
        /** @var Attachment|null $cover */
        $cover = $this->relationLoaded('coverAttachment')
            ? $this->getRelation('coverAttachment')
            : ($this->cover_attachment_id === null ? null : Attachment::withTrashed()->find($this->cover_attachment_id));

        return $cover?->ulid;
    }

    /**
     * Versionens taggar, som ItemResource bär dem: `{ulid, name, color}`,
     * sorterade på namn och utan löpnummer. En tagg som raderats hårt finns
     * inte längre att namnge och faller därför bort.
     *
     * @return list<array{ulid: string, name: string, color: string|null}>
     */
    private function tagList(): array
    {
        /** @var EloquentCollection<int, Tag> $tags */
        $tags = $this->relationLoaded('tags')
            ? $this->getRelation('tags')
            : Tag::withTrashed()->whereIn('id', array_map('intval', $this->tag_ids ?? []))->get();

        return $tags
            ->sortBy('name')
            ->values()
            ->map(fn (Tag $tag): array => [
                'ulid' => $tag->ulid,
                'name' => $tag->name,
                'color' => $tag->color,
            ])
            ->all();
    }

    /**
     * Fältvärdena versionen sparade, med fältets ULID, etikett och typ.
     *
     * @return list<array{id: string, label: string, type: string, value: string|null}>
     */
    private function fieldList(): array
    {
        $values = $this->field_values ?? [];

        /** @var EloquentCollection<int, ItemField> $fields */
        $fields = $this->relationLoaded('fields')
            ? $this->getRelation('fields')
            : ItemField::withTrashed()->whereIn('id', array_keys($values))->get();

        return $fields
            ->sortBy('label')
            ->values()
            ->map(fn (ItemField $field): array => [
                'id' => $field->ulid,
                'label' => $field->label,
                'type' => $field->type,
                'value' => $values[$field->id] ?? null,
            ])
            ->all();
    }

    /**
     * Vem som ersatte versionen: agentens namn när agenten finns, annars
     * användarens. `agent` säger vilket av fallen det är; `name` är null när
     * båda raderna är borta, och vyn har då en egen ersättare.
     *
     * @return array{name: string|null, agent: bool}
     */
    private function replacedBy(): array
    {
        /** @var Agent|null $agent */
        $agent = $this->relationLoaded('replacedByAgent')
            ? $this->getRelation('replacedByAgent')
            : ($this->replaced_by_agent_id === null ? null : Agent::query()->find($this->replaced_by_agent_id));

        /** @var User|null $user */
        $user = $this->relationLoaded('replacedByUser')
            ? $this->getRelation('replacedByUser')
            : ($this->replaced_by_user_id === null ? null : User::query()->find($this->replaced_by_user_id));

        return [
            'name' => $agent->name ?? $user->name ?? null,
            'agent' => $agent !== null,
        ];
    }
}
