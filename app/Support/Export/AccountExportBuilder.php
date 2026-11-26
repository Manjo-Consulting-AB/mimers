<?php

namespace App\Support\Export;

use App\Actions\Privacy\CollectPersonData;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Export;
use App\Models\User;
use App\Support\Access\ItemScope;
use App\Support\Notification\LocaleResolver;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Byggaren av en kontoexport — en ZIP med alla containrar kontot äger och
 * uppgifterna om personen (issue 1701 · [[ADR-0098 Kontoexporten]]):
 *
 * ```
 * index.html                         läsbar startsida med länkar
 * account.json                       personen, samma källa som privacy:collect
 * containers/<slug>-<ulid>/          en mapp per container
 *     container.json
 *     index.html
 *     filer/
 * ```
 *
 * Varje containers mapp skrivs av ContainerExportBuilder::addContainerTo(),
 * så de tre delarna är exakt de i containerexporten. **Omfånget är hela
 * containern** och inte ResolveItemScope: kontot äger allt i sina containrar
 * (ADR-0098 § 3). Mjukraderade containrar och mjukraderat innehåll följer
 * inte med — SoftDeletes' globala scope gäller som i containerexporten.
 *
 * Containrar som delats med personen listas i `shared_with_me` med namn,
 * ägarkontots namn och nivå; innehållet tillhör ett annat konto och tas
 * inte med (§ 4).
 *
 * `account.json` är CollectPersonData::collect() — en tystnadsbelagd spärr
 * nämns därför aldrig — plus `shared_with_me` och `not_included_note`.
 * Listan `not_included` är oförändrad.
 *
 * Containrarna laddas med sina relationer i en fråga, och varje container
 * kostar sedan ett fast antal frågor: antalet frågor växer med antalet
 * containrar, aldrig med antalet items (§ 10).
 *
 * ZIP:en skrivs till `.part` och byter namn som sista steg, som i
 * ContainerExportBuilder. Klassen är inte final — testsviten byter ut build().
 */
class AccountExportBuilder
{
    public function __construct(
        private readonly ContainerExportBuilder $containers,
        private readonly CollectPersonData $person,
    ) {}

    public function build(Export $export): string
    {
        $requester = $export->requestedBy;

        if ($export->account_id === null || $requester === null) {
            throw new RuntimeException('Kontoexporten saknar konto eller beställare.');
        }

        $disk = Storage::disk('files');

        $containers = Container::query()
            ->with(ContainerExportBuilder::CONTAINER_RELATIONS)
            ->where('account_id', $export->account_id)
            ->orderBy('id')
            ->get();

        $dir = 'exports/account-'.$export->account->ulid;
        $partial = $dir.'/'.$export->ulid.'.zip.part';
        $final = $dir.'/'.$export->ulid.'.zip';

        $disk->makeDirectory($dir);
        $disk->delete($partial);

        $zip = new ZipArchive;

        if ($zip->open($disk->path($partial), ZipArchive::CREATE) !== true) {
            throw new RuntimeException("Kunde inte öppna ZIP:en för skrivning ({$partial}).");
        }

        try {
            // Hela containern: kontot äger allt i den.
            $scope = ItemScope::unrestricted('read');
            $entries = [];

            foreach ($containers as $container) {
                $path = 'containers/'.$this->folder($container).'/';

                $this->containers->addContainerTo($zip, $container, $scope, $requester, $path);

                $entries[] = ['name' => $container->name, 'path' => $path];
            }

            $account = $this->person->collect((int) $requester->id);
            $account['shared_with_me'] = $this->sharedWithMe($requester);
            $account['not_included_note'] = $this->notIncludedNote($requester);

            $json = json_encode($account, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($json === false) {
                throw new RuntimeException('account.json kunde inte serialiseras.');
            }

            $html = $this->renderIndexHtml($requester, [
                'exported_at' => now()->toIso8601String(),
                'containers' => $entries,
                'shared_with_me' => $account['shared_with_me'],
            ]);

            $this->addEntry($zip, 'account.json', $json);
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
     * Mappnamnet `<slug>-<ulid>`: namnet gjort filsäkert, ULID:en gör mappen
     * unik. Ett namn utan några tecken som överlever sluggen blir `container`.
     */
    private function folder(Container $container): string
    {
        $slug = Str::limit(Str::slug($container->name), 60, '');
        $slug = trim($slug, '-');

        return ($slug === '' ? 'container' : $slug).'-'.$container->ulid;
    }

    /**
     * Containrar andra konton äger och som personen har giltig åtkomst till,
     * en rad per container med den högsta nivån. Två frågor oavsett antal
     * (åtkomsterna, sedan container och ägarkonto i ett svep).
     *
     * @return list<array{container: string, owner: string, level: string}>
     */
    private function sharedWithMe(User $user): array
    {
        $ownAccountIds = $this->person->accountIds((int) $user->id);

        $rows = ContainerAccess::query()
            ->validFor($user, $ownAccountIds)
            ->with('container.account')
            ->orderBy('id')
            ->get();

        $byContainer = [];

        foreach ($rows as $access) {
            $container = $access->container;

            if ($container === null || in_array($container->account_id, $ownAccountIds, true)) {
                continue;
            }

            $level = $access->level;
            $current = $byContainer[$container->id]['level'] ?? null;

            if ($current === 'write' || ($current === 'read' && $level !== 'write')) {
                continue;
            }

            $byContainer[$container->id] = [
                'container' => $container->name,
                'owner' => $container->account->name ?? '',
                'level' => $level,
            ];
        }

        return array_values($byContainer);
    }

    private function notIncludedNote(User $user): string
    {
        return $this->inLocale($user, fn (): string => trans('export.account_not_included'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderIndexHtml(User $user, array $payload): string
    {
        return $this->inLocale($user, fn (): string => view('export.account-index', [
            'payload' => $payload,
            'locale' => App::getLocale(),
        ])->render());
    }

    /**
     * Kör `$render` med den beställande användarens språk och återställer
     * appens locale i finally — samma mönster som i ContainerExportBuilder.
     *
     * @param  callable(): string  $render
     */
    private function inLocale(User $user, callable $render): string
    {
        $tidigare = App::getLocale();

        try {
            App::setLocale((new LocaleResolver)->forUser($user));

            return $render();
        } finally {
            App::setLocale($tidigare);
        }
    }

    private function addEntry(ZipArchive $zip, string $name, string $content): void
    {
        if ($zip->addFromString($name, $content) === false) {
            throw new RuntimeException("Kunde inte lägga {$name} i ZIP:en.");
        }
    }
}
