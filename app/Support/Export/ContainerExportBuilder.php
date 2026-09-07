<?php

namespace App\Support\Export;

use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Export;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Loan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\Tag;
use App\Support\Notification\LocaleResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Byggaren av en export — läser en containers innehåll och packar det till
 * en ZIP med tre delar (issue 41 § Beslut 8):
 *
 * ```
 * container.json      maskinläsbar, fullständig
 * index.html          läsbar i en webbläsare, utan Mimers
 * filer/              originalbilagorna
 * ```
 *
 * Byggaren bor i Support snarare än i jobbet (Beslut 12): jobbet är bara
 * statusmaskinen (pending → running → ready/failed), allt som faktiskt läser
 * domänens tabeller och skriver byten ligger här, så att det går att öva och
 * felsöka utan att köa ett jobb.
 *
 * ZIP:en skrivs till en `.part`-sökväg och byts namn som sista steg (Beslut
 * 13): en avbruten körning lämnar aldrig en trasig fil som `ready`. All
 * diskåtkomst går genom Storage-abstraktionen ([[ADR-0007 Fillagring hos
 * inleed]]); ZipArchive kan inte skriva till en abstraktion, så precis som
 * GD i GenerateImageDerivatives nås bytena via Storage::disk('files')->path().
 *
 * Frågorna är N+1-fria (Beslut 14): relationerna laddas med `with()` i ett
 * fast antal frågor oavsett antalet items, och item_länkarna hämtas i en
 * enda fråga för hela containern.
 *
 * Mjukraderat innehåll följer inte med: SoftDeletes' globala scope gäller
 * automatiskt genom relationerna för item, kategori, tagg, schema, lån och
 * bilaga. Papperskorgen är inte pärmen (Beslut 9).
 *
 * Klassen är inte final — testsviten byter ut build() genom en anonym
 * underklass för att öva jobbets felhantering.
 */
class ContainerExportBuilder
{
    public function build(Export $export): string
    {
        $disk = Storage::disk('files');

        $container = Container::query()
            ->with(['categories', 'tags'])
            ->findOrFail($export->container_id);

        $items = $container->items()
            ->with(['category', 'tags', 'attachments.storedFile', 'schedules.occurrences', 'loans'])
            ->orderBy('id')
            ->get();

        $dir = 'exports/'.$container->ulid;
        $partial = $dir.'/'.$export->ulid.'.zip.part';
        $final = $dir.'/'.$export->ulid.'.zip';

        $disk->makeDirectory($dir);
        $disk->delete($partial);

        $zip = new ZipArchive;

        if ($zip->open($disk->path($partial), ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Kunde inte öppna ZIP:en för skrivning ({$partial}).");
        }

        try {
            $itemArrays = $this->buildItems($items, $zip, $disk);

            $this->addLinks($itemArrays, $items);

            $payload = $this->payload($container, $itemArrays);

            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($json === false) {
                throw new RuntimeException('container.json kunde inte serialiseras.');
            }

            $html = $this->renderIndexHtml($export, $container, $payload);

            $this->addEntry($zip, 'container.json', $json);
            $this->addEntry($zip, 'index.html', $html);
        } catch (Throwable $e) {
            $zip->close();
            throw $e;
        }

        if ($zip->close() === false) {
            throw new RuntimeException("ZIP:en kunde inte stängas ({$partial}).");
        }

        $disk->move($partial, $final);

        return $final;
    }

    /**
     * Bygger item-arrayerna för container.json och skriver varje bilagas
     * byten till ZIP:en under `filer/{item-ulid}/{filnamn}` (Beslut 10).
     * Nyckeln är itemets löpnummer så att addLinks() kan hitta rätt array;
     * payload() gör om den till en lista.
     *
     * @param  Collection<int, Item>  $items
     * @return array<int, array<string, mixed>>
     */
    private function buildItems($items, ZipArchive $zip, FilesystemAdapter $disk): array
    {
        $itemArrays = [];

        foreach ($items as $item) {
            $usedNames = [];

            $attachments = [];

            foreach ($item->attachments as $attachment) {
                $entry = $this->attachmentEntry($attachment);

                $stored = $attachment->storedFile;

                if ($stored !== null && $disk->exists($stored->storage_path)) {
                    $filename = $this->uniqueFilename($attachment->filename, $usedNames);
                    $innerPath = 'filer/'.$item->ulid.'/'.$filename;

                    $this->addFile($zip, $disk->path($stored->storage_path), $innerPath);

                    $entry['path'] = $innerPath;
                } else {
                    $entry['missing'] = true;
                }

                $attachments[] = $entry;
            }

            $itemArrays[$item->id] = [
                'ulid' => $item->ulid,
                'name' => $item->name,
                'description' => $item->description,
                'manufacturer' => $item->manufacturer,
                'model' => $item->model,
                'serial_number' => $item->serial_number,
                'purchased_at' => self::toDate($item->purchased_at),
                'warranty_until' => self::toDate($item->warranty_until),
                'position_note' => $item->position_note,
                'category_ulid' => $item->category_id !== null
                    ? $this->categoryUlid($item)
                    : null,
                'tags' => $item->tags->pluck('ulid')->values()->all(),
                'links' => [],
                'schedules' => $item->schedules->map(fn (Schedule $schedule): array => $this->scheduleEntry($schedule))->values()->all(),
                'loans' => $item->loans->map(fn (Loan $loan): array => $this->loanEntry($loan))->values()->all(),
                'attachments' => $attachments,
                'created_at' => self::toTime($item->created_at),
                'updated_at' => self::toTime($item->updated_at),
            ];
        }

        return $itemArrays;
    }

    /**
     * Fyller varje items `links` med item_link-raderna där itemet är ena
     * änden, sedda från just det itemet. En fråga för hela containern
     * (Beslut 14). En länk vars motpart är mjukraderad följer inte med —
     * motparten finns inte i $items, så villkoret whereIn på båda ändarna
     * exkluderar raden (Beslut 9).
     *
     * @param  array<int, array<string, mixed>>  $itemArrays
     * @param  Collection<int, Item>  $items
     */
    private function addLinks(array &$itemArrays, $items): void
    {
        $ids = $items->pluck('id');

        $links = ItemLink::query()
            ->whereIn('from_item_id', $ids)
            ->whereIn('to_item_id', $ids)
            ->get();

        $ulidById = $items->keyBy('id')->map(fn (Item $item): string => $item->ulid)->all();

        foreach ($links as $link) {
            $fromId = $link->from_item_id;
            $toId = $link->to_item_id;

            if (isset($itemArrays[$fromId])) {
                $itemArrays[$fromId]['links'][] = [
                    'relation' => $link->relationSeenFromItem($fromId),
                    'item_ulid' => $ulidById[$toId],
                ];
            }

            if (isset($itemArrays[$toId])) {
                $itemArrays[$toId]['links'][] = [
                    'relation' => $link->relationSeenFromItem($toId),
                    'item_ulid' => $ulidById[$fromId],
                ];
            }
        }
    }

    /**
     * Toppnivån i container.json (Beslut 9): containern själv, kategorierna
     * (med förälderns ULID), taggarna och items. Inga löpnummer, inga interna
     * id:n, ingenting om container_access, inbjudningar, konton eller
     * e-postadresser.
     *
     * @param  array<int, array<string, mixed>>  $itemArrays
     * @return array<string, mixed>
     */
    private function payload(Container $container, array $itemArrays): array
    {
        $categoryUlidById = $container->categories->keyBy('id')->map(fn (Category $category): string => $category->ulid);

        return [
            'exported_at' => self::toTime(now()),
            'format_version' => 1,
            'container' => [
                'ulid' => $container->ulid,
                'name' => $container->name,
                'kind' => $container->kind,
                'created_at' => self::toTime($container->created_at),
            ],
            'categories' => $container->categories->map(fn (Category $category): array => [
                'ulid' => $category->ulid,
                'name' => $category->name,
                'position' => $category->position,
                'parent_ulid' => $category->parent_id !== null
                    ? ($categoryUlidById[$category->parent_id] ?? null)
                    : null,
            ])->values()->all(),
            'tags' => $container->tags->map(fn (Tag $tag): array => [
                'ulid' => $tag->ulid,
                'name' => $tag->name,
                'color' => $tag->color,
            ])->values()->all(),
            'items' => array_values($itemArrays),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleEntry(Schedule $schedule): array
    {
        return [
            'ulid' => $schedule->ulid,
            'title' => $schedule->title,
            'notes' => $schedule->notes,
            'recurrence_type' => $schedule->recurrence_type,
            'interval_unit' => $schedule->interval_unit,
            'interval_count' => $schedule->interval_count,
            'anchor_date' => self::toDate($schedule->anchor_date),
            'lead_days' => $schedule->lead_days,
            'is_active' => $schedule->is_active,
            'occurrences' => $schedule->occurrences->map(fn (ScheduleOccurrence $occurrence): array => $this->occurrenceEntry($occurrence))->values()->all(),
            'created_at' => self::toTime($schedule->created_at),
            'updated_at' => self::toTime($schedule->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function occurrenceEntry(ScheduleOccurrence $occurrence): array
    {
        return [
            'ulid' => $occurrence->ulid,
            'visible_from' => self::toDate($occurrence->visible_from),
            'due_at' => self::toDate($occurrence->due_at),
            'status' => $occurrence->status,
            'completed_at' => self::toTime($occurrence->completed_at),
            'completion_note' => $occurrence->completion_note,
            'created_at' => self::toTime($occurrence->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loanEntry(Loan $loan): array
    {
        return [
            'ulid' => $loan->ulid,
            'borrower_name' => $loan->borrower_name,
            'borrower_email' => $loan->borrower_email,
            'lent_at' => self::toDate($loan->lent_at),
            'due_at' => self::toDate($loan->due_at),
            'returned_at' => self::toDate($loan->returned_at),
            'note' => $loan->note,
            'created_at' => self::toTime($loan->created_at),
            'updated_at' => self::toTime($loan->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentEntry(Attachment $attachment): array
    {
        $stored = $attachment->storedFile;

        return [
            'ulid' => $attachment->ulid,
            'filename' => $attachment->filename,
            'kind' => $attachment->kind,
            'byte_size' => $stored?->byte_size,
            'mime_type' => $stored?->mime_type,
            'content_hash' => $stored?->content_hash,
        ];
    }

    private function categoryUlid(Item $item): ?string
    {
        // Kategorin är eager-loadad på itemet; `category_id` pekar alltid på
        // en levande kategori, men en kategori som mjukraderats efter att
        // itemet lästs ska inte fälla exporten.
        return $item->category?->ulid;
    }

    /**
     * Renderar index.html — en enda självständig fil utan externa resurser
     * (Beslut 11). Språket väljs från den beställande användarens locale,
     * samma regel som mejlen (AGENTS.md § Serverrenderat innehåll).
     */
    private function renderIndexHtml(Export $export, Container $container, array $payload): string
    {
        $locale = (new LocaleResolver)->forUser($export->requestedBy);

        return view('export.index', [
            'payload' => $payload,
            'containerName' => $container->name,
            'locale' => $locale,
        ])->render();
    }

    private function addEntry(ZipArchive $zip, string $name, string $content): void
    {
        if ($zip->addFromString($name, $content) === false) {
            throw new RuntimeException("Kunde inte lägga {$name} i ZIP:en.");
        }
    }

    private function addFile(ZipArchive $zip, string $sourcePath, string $innerPath): void
    {
        if ($zip->addFile($sourcePath, $innerPath) === false) {
            throw new RuntimeException("Kunde inte lägga {$innerPath} i ZIP:en.");
        }
    }

    /**
     * Filnamnet inne i ZIP:en, unikt inom itemet (Beslut 10): två bilagor med
     * samma filnamn på samma item får `-2`, `-3` före filändelsen. Namnet har
     * redan sanerats av StoreAttachment, men saneras igen här — ett filnamn
     * ur databasen är indata och får aldrig bli en sökväg utan att gå genom
     * basename() och en saneringsfunktion.
     *
     * @param  array<string, true>  $used
     */
    private function uniqueFilename(string $filename, array &$used): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $base = preg_replace('/[\x00-\x1F\x7F]/u', '', $base) ?? '';
        $base = ltrim($base, '.');
        $base = trim($base);

        if ($base === '') {
            $base = 'fil';
        }

        $base = mb_substr($base, 0, 255);

        $info = pathinfo($base);
        $extension = ($info['extension'] ?? '') !== '' ? '.'.$info['extension'] : '';
        $stem = $info['filename'];

        $candidate = $base;
        $counter = 1;

        while (isset($used[$candidate])) {
            $counter++;
            $candidate = $stem.'-'.$counter.$extension;
        }

        $used[$candidate] = true;

        return $candidate;
    }

    private static function toDate(?CarbonInterface $date): ?string
    {
        return $date?->toDateString();
    }

    private static function toTime(?CarbonInterface $time): ?string
    {
        return $time?->toIso8601String();
    }
}
