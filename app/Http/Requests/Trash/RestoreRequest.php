<?php

namespace App\Http\Requests\Trash;

use App\Models\Container;
use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * POST /api/containers/{container}/trash/restore, se issue 20a § Beslut 6.
 * Kroppen är `{"type": "item|attachment|category|tag", "ulid": "..."}`.
 *
 * Valideringen bevisar att ULID:en finns i typens tabell, INOM den här
 * containern och är mjukraderad — allt annat är 422 `validation.failed`.
 * Gränsdragningen är samma som issue 13a § Beslut 7: en ULID ur en annan
 * container är ett valideringsfel, inte en 404 och aldrig ett tyst "hittade
 * inget". `Rule::exists` går direkt mot tabellen och ser därför även
 * mjukraderade rader — vilket är precis det vi vill här, i kontrast till
 * de flesta andra uppslag som tvärtom lägger `whereNull('deleted_at')`.
 *
 * Betydelsefullt: utgångna rader (äldre än retentionen) faller INTE här —
 * de finns i containern och är mjukraderade, så `exists` passerar dem. Att
 * de ändå inte går att återställa (404 `resource.not_found`, § Beslut 5)
 * avgörs av uppslaget i kontrollern, inte av valideringen: att filtrera på
 * retention här skulle göra ett utgånget innehåll till ett 422-valideringsfel.
 *
 * För `attachment` går containertillhörigheten genom itemet
 * (attachment.item.container_id, § Beslut 3) — en whereIn-subfråga mot
 * itemets id:n i containern i stället för en kolumn på attachment-raden,
 * hela skillnaden mot de andra tre typerna. Itemet tas med `withTrashed()`
 * så att en bilaga vars item ligger i papperskorgen passerar valideringen
 * och i stället fångas av RestoreContent (Beslut 8).
 *
 * Auktorisationen avgörs av kontrollern mot `ContainerPolicy::update()`,
 * inte här — samma mönster som StoreItemRequest::authorize() (issue 13a §
 * Beslut 2).
 */
class RestoreRequest extends FormRequest
{
    /**
     * Typnamnen är API-kontrakt, skrivna i singular som tabellnamnen
     * (issue 20a § Beslut 3).
     *
     * @var list<string>
     */
    public const TYPES = ['item', 'attachment', 'category', 'tag'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'ulid' => ['required', 'string', $this->ulidExistsRule()],
        ];
    }

    /**
     * `Rule::exists` mot typens tabell med två villkor: containern (för
     * attachment genom en subfråga mot `item`) och `deleted_at IS NOT NULL`
     * — raden ska LIGGA i papperskorgen för att gå att återställa, se issue
     * 20a § Att se upp med ("`restore()` på en rad som inte är raderad är
     * en tyst no-op").
     */
    private function ulidExistsRule(): Exists
    {
        $type = $this->input('type');
        $container = $this->route('container');
        assert($container instanceof Container);

        return match ($type) {
            'item', 'category', 'tag' => Rule::exists($type, 'ulid')->where(
                fn ($query) => $query
                    ->where('container_id', $container->id)
                    ->whereNotNull('deleted_at')
            ),
            // Bilagans containertillhörighet går genom itemet (Beslut 3).
            // Ingen join här — Rule::exists kör sina where-closures i en
            // nästlad where-grupp där join inte överlever; i stället en
            // whereIn mot itemets id:n i containern. Itemet tas med
            // withTrashed(): en bilaga vars item ligger i papperskorgen ska
            // PASSERA valideringen så att RestoreContent kan svara
            // trash.parent_deleted — valideringen får inte svälja fallet.
            'attachment' => Rule::exists('attachment', 'ulid')->where(
                fn ($query) => $query
                    ->whereNotNull('attachment.deleted_at')
                    ->whereIn('attachment.item_id', Item::query()->withTrashed()
                        ->where('container_id', $container->id)
                        ->select('id'))
            ),
            // Okänd typ: type-regeln ovan rapporterar felet, men ulid-regeln
            // får ändå inte krascha på en tabell som inte finns.
            default => Rule::exists('item', 'ulid')->where(fn ($query) => $query->whereRaw('1 = 0')),
        };
    }
}
