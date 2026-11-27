<?php

namespace App\Support\Export;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Audit\ListAuditEvents;
use App\Actions\Audit\PresentAuditEvents;
use App\Actions\Item\RenderItemNotes;
use App\Actions\Item\ResolveItemCover;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Export;
use App\Models\Item;
use App\Models\ItemLink;
use App\Support\Access\ItemScope;
use App\Support\Cost\MinorUnits;
use App\Support\Notification\LocaleResolver;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * Byggaren av PDF-pärmen — en containers innehåll som ETT dokument med
 * försättsblad, innehållsförteckning, items i hierarkisk ordning och
 * sidnummer (issue 445 · [[ADR-0091 PDF-pärmen]]). Bor bredvid
 * ContainerExportBuilder och delar dess regler:
 *
 *  - **Omfånget är beställarens** (`$export->requested_by_user_id`, issue 74
 *    § Beslut 8), upplöst här i build() och aldrig i en property. Ett item
 *    utanför omfånget finns inte i pärmen — varken som avsnitt, som rad i
 *    förteckningen eller som motpart i en relation. En nollställd beställare
 *    ger en pärm för ett tomt omfång, som i ZIP-byggaren.
 *  - **Mjukraderat följer inte med** — SoftDeletes' globala scope gäller
 *    genom relationerna.
 *  - **`.part`-mönstret** (Beslut 13): PDF:en skrivs till
 *    `{export}.pdf.part` och byts namn som sista steg.
 *
 * **Ordningen.** Trädet följer *Parent* och *Child*-relationerna ([[ADR-0080]]):
 * en `parent`-rad i item_link har föräldern i `from_item_id`. Items utan
 * förälder (eller vars förälder ligger utanför omfånget) hamnar överst,
 * syskon efter namn. Har ett item flera föräldrar placeras det under den med
 * lägst id och dyker inte upp en gång till; en cykel bryts av den besökta
 * mängden, och items som bara nås via en cykel hamnar på översta nivån.
 * Regeln är deterministisk och stabil.
 *
 * **Kostnader** summeras per valuta, aldrig omräknade ([[Items och
 * organisation]] § cost): containerns huvudvaluta står först, övriga i
 * bokstavsordning, och ingen rad lämnas utanför summan. Avrundningen sker vid
 * presentationen, inte per rad.
 *
 * **Historiken** ritas som meningen ur `ui.audit.action.{åtgärd}` (issue 116),
 * aldrig som den råa åtgärdskoden.
 *
 * **Sidnummer.** Varje del renderas för sig med dompdf så att dess sidantal
 * är känt; förteckningen renderas SIST (den behöver alla andra delars
 * sidantal), och eftersom dess egen längd flyttar alla sidnummer renderas den
 * om en gång om första gissningen (en sida) var fel. FPDI sätter ihop delarna
 * och stämplar *Page N of M* i sidfoten; försättsbladet och förteckningen
 * räknas med.
 *
 * **Minnet** (ADR-0031: `--memory=96`, 128M i CLI). Ett item i taget: dess
 * relationer laddas, delen renderas till en temporär PDF under
 * `exports/{container}/{export}.parts/` på `files`-disken, och både
 * dompdf-instansen och itemet släpps innan nästa. Aldrig en stor HTML.
 * Containerns bild är `medium`-varianten, aldrig originalet. Temporärmappen
 * tas bort i `finally`; jobbet tar bort `.part`-filen vid fel.
 *
 * **Typsnittet är Helvetica**, en av PDF:ens inbyggda kärnfonter (windows-1252:
 * svenska och västeuropeiska tecken). Ett inbäddat Unicode-typsnitt (DejaVu)
 * kostar runt 35 MB mer per rendering, och det är skillnaden mellan att rymmas
 * i arbetarens 96 MB och inte. **Pärmen har bara WinAnsi-tecken** (windows-1252):
 * svenska och västeuropeiska tecken fungerar, andra tecken ersätts av
 * typsnittet och fäller inte bygget. Unicode är en öppen fråga för 446.
 *
 * **En pärm för ett enda item** (issue 447 · ADR-0091): har exporten `item_id`
 * byggs samma sidor för just det itemet. Försättsbladet bär itemets namn,
 * bild och containerns namn, det finns ingen innehållsförteckning (ett item
 * behöver ingen) och sidnumren är som vanligt. Itemets barn tas inte med, och
 * ligger itemet utanför beställarens omfång blir pärmen ett försättsblad utan
 * avsnitt, som för en nollställd beställare.
 *
 * Ingen extern process och ingen webbläsare (ADR-0070): dompdf och FPDI är
 * ren PHP. Fjärrresurser är avstängda, dompdfs `chroot` är den egna
 * temporärmappen (inte disken) och `<img>` tas bort ur anteckningarna, så en
 * bildlänk i en anteckning kan inte läsa en fil från disken. Omslagsbilden
 * går som data-URI och behöver ingen filåtkomst.
 *
 * Klassen är inte final — testsviten byter ut build() genom en anonym
 * underklass för att öva jobbets felhantering.
 */
class BinderBuilder
{
    /** Delar per omgång vid hopsättningen — ett filhandtag per del. */
    private const MERGE_CHUNK = 50;

    /** Sektionerna `options.sections` kan slå på; förvalet är allt utom kostnader. */
    public const SECTIONS = ['items', 'relations', 'schedules', 'history', 'costs'];

    public function build(Export $export): string
    {
        $disk = Storage::disk('files');

        $container = Container::query()
            ->with(['fields', 'coverAttachment.storedFile', 'account'])
            ->findOrFail($export->container_id);

        $requester = $export->requestedBy;

        $scope = $requester === null
            ? ItemScope::restricted([])
            : app(ResolveItemScope::class)->handle($requester, $container);

        $options = $this->resolveOptions($export);

        $locale = (new LocaleResolver)->forUser($requester);
        $previous = App::getLocale();

        $dir = 'exports/'.$container->ulid;
        $partial = $dir.'/'.$export->ulid.'.pdf.part';
        $final = $dir.'/'.$export->ulid.'.pdf';
        $tmp = $dir.'/'.$export->ulid.'.parts';

        $disk->makeDirectory($tmp);
        $disk->delete($partial);

        try {
            App::setLocale($locale);

            $this->assemble($export, $container, $scope, $options, $requester, $disk, $tmp, $partial);
        } catch (Throwable $e) {
            $disk->delete($partial);

            throw $e;
        } finally {
            App::setLocale($previous);
            $disk->deleteDirectory($tmp);
        }

        $disk->move($partial, $final);

        return $final;
    }

    /**
     * @return array{sections: list<string>, costs: bool, attachments: list<string>, mode: string}
     */
    private function resolveOptions(Export $export): array
    {
        $options = is_array($export->options) ? $export->options : [];

        $sections = $options['sections'] ?? ['items', 'relations', 'schedules', 'history'];
        $sections = array_values(array_intersect(self::SECTIONS, is_array($sections) ? $sections : []));

        // `options.costs` är kostnadernas egen strömbrytare och förvalet är
        // falskt; sektionen tas med bara när båda säger ja.
        $costs = ($options['costs'] ?? false) === true;
        $sections = array_values(array_diff($sections, ['costs']));

        if ($costs) {
            $sections[] = 'costs';
        }

        // Bilage-ULID:er; saknad nyckel eller något annat än en lista av
        // strängar är inga bilagor. Urvalet prövas mot pärmens items i
        // planAttachments().
        $attachments = is_array($options['attachments'] ?? null)
            ? array_values(array_unique(array_filter($options['attachments'], 'is_string')))
            : [];

        $mode = ($options['attachment_mode'] ?? 'link') === 'merge' ? 'merge' : 'link';

        return ['sections' => $sections, 'costs' => $costs, 'attachments' => $attachments, 'mode' => $mode];
    }

    /**
     * @param  array{sections: list<string>, costs: bool, attachments: list<string>, mode: string}  $options
     */
    private function assemble(Export $export, Container $container, ItemScope $scope, array $options, $requester, FilesystemAdapter $disk, string $tmp, string $partial): void
    {
        $sections = $options['sections'];

        // Trädet byggs av itemraderna utan relationer — de laddas ett item i
        // taget i renderingen nedan.
        $items = $container->items()->inScope($scope)
            ->when($export->item_id !== null, fn ($q) => $q->whereKey($export->item_id))
            ->orderBy('name')->orderBy('id')->get()->keyBy('id');

        $single = $export->item_id !== null ? $items->first() : null;

        [$ordered, $relatedLinks] = $this->order($items);

        // Rubrikerna behöver bara id, namn och djup — resten hör till delen.
        $parts = [];

        $parts['cover'] = $export->item_id !== null
            ? $this->renderItemCover($container, $single, $disk, $tmp)
            : $this->renderCover($container, $disk, $tmp, $export);

        $entries = [];

        // Återstående utrymme för sammanslagna bilagor, delat av hela pärmen.
        $budget = (int) config('files.binder_merge_bytes');

        if (in_array('items', $sections, true)) {
            $itemsById = $items;

            foreach ($ordered as $n => [$id, $depth]) {
                /** @var Item $item */
                $item = $itemsById[$id];

                $plan = $this->planAttachments($item, $options, $disk, $tmp, $n, $budget);

                $path = $this->renderItem($item, $depth, $container, $sections, $relatedLinks, $itemsById, $requester, $disk, $tmp, $n, $plan['links']);

                $entries[] = ['name' => $item->name, 'depth' => $depth, 'paths' => [$path]];

                // Sammanslagna bilagor står direkt efter itemets avsnitt, med
                // en egen rad i förteckningen.
                foreach ($plan['merged'] as $merged) {
                    $entries[] = ['name' => $merged['name'], 'depth' => $depth + 1, 'paths' => $merged['paths']];
                }

                // Itemets laddade relationer behövs inte längre.
                $item->unsetRelations();
                gc_collect_cycles();
            }
        }

        // Ett item behöver ingen innehållsförteckning: försättsbladet och
        // avsnitten sätts ihop direkt, och `merge()` stämplar sidnumren.
        if ($export->item_id !== null) {
            $this->merge($disk, [$parts['cover'], ...array_merge(...array_column($entries, 'paths'))], $partial, $tmp);

            return;
        }

        $coverPages = $this->pageCount($disk, $parts['cover']);
        $itemPages = array_map(fn (array $e): int => array_sum(array_map(fn (string $p): int => $this->pageCount($disk, $p), $e['paths'])), $entries);

        // Förteckningen renderas sist. Första gissningen är en sida; stämmer
        // den inte flyttas alla sidnummer, och den renderas om med rätt längd.
        $tocPages = 1;

        for ($try = 0; $try < 3; $try++) {
            $rows = [];
            $page = $coverPages + $tocPages + 1;

            foreach ($entries as $i => $entry) {
                $rows[] = ['name' => $entry['name'], 'depth' => $entry['depth'], 'page' => $page];
                $page += $itemPages[$i];
            }

            $tocPath = $this->renderPart($disk, $tmp.'/toc.pdf', 'export.binder.toc', ['rows' => $rows]);
            $actual = $this->pageCount($disk, $tocPath);

            if ($actual === $tocPages) {
                break;
            }

            $tocPages = $actual;
        }

        $this->merge($disk, [$parts['cover'], $tocPath, ...array_merge(...array_column($entries, 'paths'))], $partial, $tmp);
    }

    /**
     * Trädordningen: `[[item_id, djup], ...]` i djupet-först-ordning, plus
     * `related`-länkarna mellan items i omfånget för relationsavsnittet.
     *
     * @param  Collection<int, Item>  $items  nycklade på id, redan sorterade på namn
     * @return array{0: list<array{0: int, 1: int}>, 1: array<int, list<array{relation: string, item_id: int}>>}
     */
    private function order($items): array
    {
        $ids = $items->keys()->all();

        $links = ItemLink::query()
            ->whereIn('from_item_id', $ids)
            ->whereIn('to_item_id', $ids)
            ->orderBy('from_item_id')
            ->get(['from_item_id', 'to_item_id', 'relation']);

        $parentOf = [];
        $children = [];
        $related = [];

        foreach ($links as $link) {
            $from = (int) $link->from_item_id;
            $to = (int) $link->to_item_id;

            if ($link->relation === 'related') {
                $related[$from][] = ['relation' => 'related', 'item_id' => $to];
                $related[$to][] = ['relation' => 'related', 'item_id' => $from];

                continue;
            }

            // Lägst föräldra-id vinner; länkarna är sorterade på from_item_id.
            if (! isset($parentOf[$to])) {
                $parentOf[$to] = $from;
                $children[$from][] = $to;
            }
        }

        $ordered = [];
        $visited = [];

        $walk = function (int $id, int $depth) use (&$walk, &$ordered, &$visited, $children, $items): void {
            if (isset($visited[$id])) {
                return;
            }

            $visited[$id] = true;
            $ordered[] = [$id, $depth];

            $kids = $children[$id] ?? [];

            usort($kids, fn (int $a, int $b): int => [$items[$a]->name, $a] <=> [$items[$b]->name, $b]);

            foreach ($kids as $kid) {
                $walk($kid, $depth + 1);
            }
        };

        foreach ($items as $id => $item) {
            if (! isset($parentOf[$id])) {
                $walk((int) $id, 0);
            }
        }

        // Items som bara nås genom en cykel hamnar överst i stället för att
        // försvinna ur pärmen.
        foreach ($items as $id => $item) {
            $walk((int) $id, 0);
        }

        return [$ordered, $related];
    }

    private function renderCover(Container $container, FilesystemAdapter $disk, string $tmp, Export $export): string
    {
        return $this->renderPart($disk, $tmp.'/cover.pdf', 'export.binder.cover', [
            'container' => $container,
            'owner' => $container->account->name,
            'image' => $this->coverImage($container->coverAttachment, $disk),
            'date' => now()->toDateString(),
            'fields' => $container->fields,
        ]);
    }

    /**
     * Försättsbladet för en pärm om ett enda item: itemets namn och bild, och
     * containerns namn. Saknas itemet (utanför omfånget) står bara containern.
     */
    private function renderItemCover(Container $container, ?Item $item, FilesystemAdapter $disk, string $tmp): string
    {
        $cover = $item === null ? null : app(ResolveItemCover::class)->handle($item);

        return $this->renderPart($disk, $tmp.'/cover.pdf', 'export.binder.item_cover', [
            'container' => $container,
            'title' => $item === null ? $container->name : $item->name,
            'owner' => $container->account->name,
            'image' => $this->coverImage($cover, $disk),
            'date' => now()->toDateString(),
        ]);
    }

    /**
     * Omslagsbilden som data-URI, ur `medium`-varianten — aldrig originalet.
     * Saknas varianten eller bytena ritas ingen bild.
     */
    private function coverImage(?Attachment $cover, FilesystemAdapter $disk): ?string
    {
        $stored = $cover?->storedFile;

        $derivative = $stored?->derivatives()->where('variant', 'medium')->first();

        if ($derivative === null || ! $disk->exists($derivative->storage_path)) {
            return null;
        }

        $mime = $disk->mimeType($derivative->storage_path) ?: 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($derivative->storage_path));
    }

    /**
     * @param  list<string>  $sections
     * @param  array<int, list<array{relation: string, item_id: int}>>  $relatedLinks
     * @param  Collection<int, Item>  $itemsById
     * @param  list<array{filename: string, type: ?string, size: ?string, url: string, note: ?string}>  $attachments  bilagorna som står som länkar
     */
    private function renderItem(Item $item, int $depth, Container $container, array $sections, array $relatedLinks, $itemsById, $requester, FilesystemAdapter $disk, string $tmp, int $n, array $attachments = []): string
    {
        $with = ['category', 'tags', 'ownFields', 'fieldValues', 'loans'];

        if (in_array('schedules', $sections, true)) {
            $with['schedules'] = fn ($q) => $q->orderBy('id');
            $with['schedules.occurrences'] = fn ($q) => $q->where('status', 'completed')->orderBy('completed_at');
        }

        if (in_array('costs', $sections, true)) {
            $with['costs'] = fn ($q) => $q->expenses()->orderBy('incurred_on')->orderBy('id');
        }

        $item->load($with);

        $relations = [];

        if (in_array('relations', $sections, true)) {
            foreach ($relatedLinks[$item->id] ?? [] as $link) {
                $relations[] = ['relation' => $link['relation'], 'name' => $itemsById[$link['item_id']]->name];
            }
        }

        $history = [];

        if (in_array('history', $sections, true) && $requester !== null) {
            $history = array_map(fn (array $row): array => [
                'date' => substr($row['created_at'], 0, 10),
                'sentence' => $this->sentence($row),
            ], app(PresentAuditEvents::class)->handle(app(ListAuditEvents::class)->forItem($requester, $item)));
        }

        $costs = null;

        if (in_array('costs', $sections, true)) {
            $main = $container->effectiveCurrency();
            $sums = [];

            foreach ($item->costs as $cost) {
                $sums[$cost->currency] = ($sums[$cost->currency] ?? 0) + (int) $cost->amount;
            }

            uksort($sums, fn (string $x, string $y): int => [$x !== $main, $x] <=> [$y !== $main, $y]);

            $totals = [];

            foreach ($sums as $code => $sum) {
                $totals[] = ['currency' => $code, 'amount' => MinorUnits::format($sum, $code).' '.$code];
            }

            $costs = [
                'rows' => $item->costs->map(fn ($c): array => [
                    'date' => $c->incurred_on->toDateString(),
                    'description' => $c->description,
                    'supplier' => $c->counterparty,
                    'amount' => MinorUnits::format((int) $c->amount, $c->currency).' '.$c->currency,
                ])->all(),
                'totals' => $totals,
            ];
        }

        return $this->renderPart($disk, $tmp.'/item-'.$n.'.pdf', 'export.binder.item', [
            'item' => $item,
            'level' => min($depth + 1, 6),
            'sections' => $sections,
            'notesHtml' => preg_replace('/<img\b[^>]*>/i', '', (string) app(RenderItemNotes::class)->handle($item->notes)),
            'fields' => app(ContainerExportBuilder::class)->fieldEntries($item, $container),
            'relations' => $relations,
            'history' => $history,
            'costs' => $costs,
            'attachments' => $attachments,
        ]);
    }

    /**
     * Itemets valda bilagor, delade i länkar och sammanslagna.
     *
     * Urvalet är `options.attachments` skärat med itemets egna bilagor: en
     * ULID som hör till ett annat item, en annan container eller ett item
     * utanför beställarens omfång (som aldrig kommer hit) tas aldrig upp.
     * Ett item i taget, en fil i taget (ADR-0007): inget annat än den
     * bilagan som just läses ligger i minnet, och normaliserade PDF:er skrivs
     * till temporärmappen i stället för att hållas kvar.
     *
     * Allt som inte går att slå ihop — fel filtyp, saknade bytes, en PDF som
     * FPDI inte läser (krypterad, trasig eller med komprimerad xref), en bild
     * utan medium-variant eller en fil över taket — blir en länk med en rad om
     * varför. En bilaga fäller aldrig pärmen.
     *
     * @param  array{sections: list<string>, costs: bool, attachments: list<string>, mode: string}  $options
     * @return array{links: list<array{filename: string, type: ?string, size: ?string, url: string, note: ?string}>, merged: list<array{name: string, paths: list<string>}>}
     */
    private function planAttachments(Item $item, array $options, FilesystemAdapter $disk, string $tmp, int $n, int &$budget): array
    {
        $plan = ['links' => [], 'merged' => []];

        if ($options['attachments'] === []) {
            return $plan;
        }

        $attachments = $item->attachments()
            ->whereIn('ulid', $options['attachments'])
            ->with('storedFile')
            ->orderBy('filename')
            ->orderBy('id')
            ->get();

        foreach ($attachments as $i => $attachment) {
            $stored = $attachment->storedFile;

            $link = [
                'filename' => $attachment->filename,
                'type' => $stored?->mime_type,
                'size' => $stored === null ? null : Number::fileSize((int) $stored->byte_size),
                'url' => route('files.download', $attachment),
                'note' => null,
            ];

            $mime = (string) $stored?->mime_type;

            if ($options['mode'] !== 'merge' || ($mime !== 'application/pdf' && ! str_starts_with($mime, 'image/'))) {
                $plan['links'][] = $link;

                continue;
            }

            $result = $this->mergeAttachment($attachment, $mime === 'application/pdf', $disk, $tmp, $n.'-'.$i, $budget);

            if (is_string($result)) {
                $link['note'] = __($result);
                $plan['links'][] = $link;
            } else {
                $plan['merged'][] = ['name' => $attachment->filename, 'paths' => $result];
            }
        }

        return $plan;
    }

    /**
     * Gör en bilaga till sidor i pärmen. Returnerar delarnas sökvägar, eller
     * språknyckeln till raden som förklarar varför den blir en länk.
     *
     * @return list<string>|string
     */
    private function mergeAttachment(Attachment $attachment, bool $pdf, FilesystemAdapter $disk, string $tmp, string $key, int &$budget): array|string
    {
        $stored = $attachment->storedFile;

        // En bild läses ur sin medium-variant, aldrig originalet.
        $source = $stored->storage_path;
        $derivative = null;

        if (! $pdf) {
            $derivative = $stored->derivatives()->where('variant', 'medium')->first();
            $source = $derivative?->storage_path;
        }

        if ($source === null) {
            return 'export.attachment_unmerged';
        }

        if (! $disk->exists($source)) {
            return 'export.missing';
        }

        $size = (int) $disk->size($source);

        if ($size > $budget) {
            return 'export.attachment_over_cap';
        }

        $divider = $tmp.'/att-'.$key.'-divider.pdf';
        $out = $tmp.'/att-'.$key.'.pdf';

        try {
            if ($pdf) {
                $doc = new Fpdi('P', 'pt');
                $doc->SetAutoPageBreak(false);
                $pages = $this->appendPages($doc, $disk->path($source), false, 0, 0);

                if ($pages === 0) {
                    return 'export.attachment_unmerged';
                }

                // Skrivs om till en vanlig PDF: en fil FPDI inte kan återge
                // fäller här, inte i hopsättningen längre fram.
                $doc->Output('F', $disk->path($out));
                $doc->cleanUp(true);
                unset($doc);

                $this->renderPart($disk, $divider, 'export.binder.attachment', ['filename' => $attachment->filename, 'image' => null]);

                $paths = [$divider, $out];
            } else {
                $mime = $disk->mimeType($source) ?: 'image/jpeg';
                $image = 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($source));

                $this->renderPart($disk, $divider, 'export.binder.attachment', ['filename' => $attachment->filename, 'image' => $image]);
                unset($image);

                $paths = [$divider];
            }
        } catch (Throwable) {
            $disk->delete([$divider, $out]);

            return 'export.attachment_unmerged';
        }

        $budget -= $size;

        return $paths;
    }

    /**
     * Historikradens mening ur `ui.audit.action.*`, med samma platshållare och
     * ersättare som historikfliken.
     *
     * @param  array<string, mixed>  $row
     */
    private function sentence(array $row): string
    {
        $user = $row['user'] ?? __('ui.audit.fallback.user');

        if ($row['agent'] !== null) {
            $user = $row['agent']['name'] === null
                ? __('ui.history.row.actor_via_removed_agent', ['user' => $user])
                : __('ui.history.row.actor_via_agent', ['user' => $user, 'agent' => $row['agent']['name']]);
        }

        return __('ui.audit.action.'.$row['action'], [
            'user' => $user,
            'item' => $row['item'] ?? __('ui.audit.fallback.item'),
            'fields' => implode(', ', array_map(fn (string $f): string => __('ui.audit.field.'.$f), $row['changed'])),
        ]);
    }

    /**
     * Renderar en vy till en PDF på disken och släpper dompdf-instansen.
     *
     * @param  array<string, mixed>  $data
     */
    protected function renderPart(FilesystemAdapter $disk, string $path, string $view, array $data): string
    {
        $html = view($view, $data)->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'Helvetica');
        $options->set('chroot', [dirname($disk->path($path))]);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($html);
        $dompdf->render();

        $disk->put($path, (string) $dompdf->output());

        unset($dompdf, $html);

        return $path;
    }

    private function pageCount(FilesystemAdapter $disk, string $path): int
    {
        $pdf = new Fpdi;
        $count = $pdf->setSourceFile($disk->path($path));
        $pdf->cleanUp(true);

        return $count;
    }

    /**
     * Sätter ihop delarna och stämplar *Page N of M* i sidfoten.
     *
     * FPDI läser en källfil först när utdata skrivs, så alla källor måste
     * vara öppna till `Output()`. Med tusentals items vore det tusentals
     * öppna filhandtag — därför sätts delarna ihop i omgångar om
     * `MERGE_CHUNK`, var och en till en mellan-PDF, och mellan-PDF:erna sätts
     * sedan ihop. Sidnumren stämplas i första steget, med rätt förskjutning
     * och totalen, som räknas i förväg.
     *
     * @param  list<string>  $paths
     */
    private function merge(FilesystemAdapter $disk, array $paths, string $partial, string $tmp): void
    {
        $counts = array_map(fn (string $p): int => $this->pageCount($disk, $p), $paths);
        $total = array_sum($counts);

        $chunks = array_chunk(array_keys($paths), self::MERGE_CHUNK);

        $offset = 0;
        $outputs = [];

        foreach ($chunks as $c => $indexes) {
            $target = count($chunks) === 1 ? $partial : $tmp.'/merge-'.$c.'.pdf';

            $offset = $this->stampChunk($disk, array_map(fn (int $i): string => $paths[$i], $indexes), $target, $offset, $total);
            $outputs[] = $target;
        }

        if (count($chunks) > 1) {
            $pdf = new Fpdi('P', 'pt');
            $pdf->SetAutoPageBreak(false);

            foreach ($outputs as $output) {
                $this->appendPages($pdf, $disk->path($output), false, 0, 0);
            }

            $pdf->Output('F', $disk->path($partial));
        }

        if (! $disk->exists($partial)) {
            throw new RuntimeException("Pärmen kunde inte skrivas ({$partial}).");
        }
    }

    /**
     * Skriver en omgång delar till `$target` med sidnummer från `$offset + 1`
     * och returnerar nästa förskjutning.
     *
     * @param  list<string>  $paths
     */
    private function stampChunk(FilesystemAdapter $disk, array $paths, string $target, int $offset, int $total): int
    {
        $pdf = new Fpdi('P', 'pt');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetFont('Helvetica', '', 8);

        foreach ($paths as $path) {
            $offset = $this->appendPages($pdf, $disk->path($path), true, $offset, $total);
        }

        $pdf->Output('F', $disk->path($target));
        $pdf->cleanUp(true);

        return $offset;
    }

    private function appendPages(Fpdi $pdf, string $file, bool $stamp, int $number, int $total): int
    {
        $pages = $pdf->setSourceFile($file);

        for ($i = 1; $i <= $pages; $i++) {
            $size = $pdf->getTemplateSize($template = $pdf->importPage($i));

            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);

            $number++;

            if ($stamp) {
                $label = (string) __('export.page_of', ['page' => $number, 'total' => $total]);
                $label = mb_convert_encoding($label, 'ISO-8859-1', 'UTF-8');

                $pdf->SetXY(0, $size['height'] - 28);
                $pdf->Cell($size['width'], 12, $label, 0, 0, 'C');
            }
        }

        return $number;
    }
}
