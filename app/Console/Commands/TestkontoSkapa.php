<?php

namespace App\Console\Commands;

use App\Actions\Attachment\StoreAttachment;
use App\Actions\Attachment\TrashAttachment;
use App\Actions\Auth\CreatesUserWithPersonalAccount;
use App\Actions\Category\CreateCategory;
use App\Actions\Container\CreateContainer;
use App\Actions\Container\SetContainerCover;
use App\Actions\Cost\CreateCostEntry;
use App\Actions\Item\CreateItem;
use App\Actions\Item\DeleteItem;
use App\Actions\Item\LinkItems;
use App\Actions\Item\UpdateItem;
use App\Actions\Item\UpdateItemNotes;
use App\Actions\Loan\CreateLoan;
use App\Actions\Plan\GrantInternalPro;
use App\Actions\Schedule\CreateSchedule;
use App\Actions\Tag\CreateTag;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Favorite;
use App\Models\Item;
use App\Models\Loan;
use App\Models\Schedule;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Throwable;

/**
 * `testkonto:skapa` — bygg samma testkonto i vilken miljö som helst, se
 * issue 1107.
 *
 * Tony testar på staging och vill köra samma tester i produktion. Det finns
 * ingen import och ingen kloning mellan miljöerna, så kontot byggs i stället
 * från en fast uppsättning data. Kommandot körs för hand över SSH, precis som
 * `voucher:create`, och schemaläggs aldrig.
 *
 * Allt skapas genom appens egna actions och aldrig med rå insert, så kvoten,
 * lagringsräknaren, referensräkningen på `stored_file` och auditloggen blir
 * rätt av sig själva. Två ställen saknar action: en favorit sparas som
 * FavoriteController gör, genom `$user->favorites()`, och verifierad e-post
 * sätts med `markEmailAsVerified()`.
 *
 * Allt eller inget: hela bygget ligger i en transaktion. Filbytes som hunnit
 * skrivas innan ett steg föll städas av StoreAttachment, och kommandot lägger
 * ingen egen städning ovanpå.
 *
 * Kommandot raderar aldrig något. Finns adressen redan, i någon skiftlägesform,
 * avslutar det utan att skriva. Ingenting delas med någon annan och inget mejl
 * går till någon annan än kontot självt.
 */
class TestkontoSkapa extends Command
{
    protected $signature = 'testkonto:skapa
                            {email : Adressen kontot skapas för}
                            {--force : Fråga inte i production}';

    protected $description = 'Skapa testkontot med fast testdata. Lösenordet frågas.';

    /**
     * Kontots namn. Fast, så att två körningar ger igenkännbart samma konto.
     */
    private const NAME = 'Testkonto';

    /**
     * Containers, i ordning. `files` är itemet som får anteckning, bild och
     * PDF; `items` är träd där `children` ger nästa nivå.
     *
     * @var list<array<string, mixed>>
     */
    private const CONTAINERS = [
        [
            'name' => 'Hemmet',
            'kind' => 'house',
            'cover' => true,
            'categories' => [
                'Utrustning' => ['Vitvaror', 'Verktyg'],
                'Inredning' => ['Möbler', 'Elektronik'],
            ],
            'tags' => ['Garanti' => '#2563eb', 'Service' => '#d97706', 'Lånas ut' => '#16a34a'],
            'files' => 'Diskmaskin',
            'items' => [
                ['name' => 'Kök', 'children' => [
                    ['name' => 'Kyl och frys', 'category' => 'Vitvaror', 'tags' => ['Garanti']],
                    ['name' => 'Diskmaskin', 'category' => 'Vitvaror', 'tags' => ['Garanti', 'Service'], 'children' => [
                        ['name' => 'Diskmaskinsfilter', 'category' => 'Vitvaror', 'tags' => ['Service']],
                    ]],
                    ['name' => 'Espressomaskin', 'category' => 'Elektronik', 'tags' => ['Service']],
                ]],
                ['name' => 'Vardagsrum', 'children' => [
                    ['name' => 'Soffa', 'category' => 'Möbler', 'tags' => []],
                    ['name' => 'TV', 'category' => 'Elektronik', 'tags' => ['Garanti'], 'children' => [
                        ['name' => 'Soundbar', 'category' => 'Elektronik', 'tags' => ['Garanti']],
                    ]],
                ]],
                ['name' => 'Borrmaskin', 'category' => 'Verktyg', 'tags' => ['Lånas ut']],
                ['name' => 'Stege', 'category' => 'Verktyg', 'tags' => ['Lånas ut']],
                ['name' => 'Snöslunga', 'category' => 'Verktyg', 'tags' => ['Service']],
            ],
        ],
        [
            'name' => 'Båten',
            'kind' => 'boat',
            'cover' => false,
            'categories' => [
                'Motor och drift' => ['Motor', 'Bränsle'],
                'Säkerhet' => ['Flytvästar', 'Signalutrustning'],
            ],
            'tags' => ['Inför säsong' => '#0891b2', 'Besiktning' => '#dc2626', 'Reservdel' => '#7c3aed'],
            'files' => 'Utombordare',
            'items' => [
                ['name' => 'Utombordare', 'category' => 'Motor', 'tags' => ['Inför säsong', 'Besiktning'], 'children' => [
                    ['name' => 'Propeller', 'category' => 'Motor', 'tags' => ['Reservdel'], 'children' => [
                        ['name' => 'Reservpropeller', 'category' => 'Motor', 'tags' => ['Reservdel']],
                    ]],
                    ['name' => 'Tändstift', 'category' => 'Motor', 'tags' => ['Reservdel']],
                ]],
                ['name' => 'Bränsletank', 'category' => 'Bränsle', 'tags' => ['Inför säsong']],
                ['name' => 'Flytvästar', 'category' => 'Flytvästar', 'tags' => ['Besiktning'], 'children' => [
                    ['name' => 'Flytväst barn', 'category' => 'Flytvästar', 'tags' => ['Besiktning']],
                    ['name' => 'Flytväst vuxen', 'category' => 'Flytvästar', 'tags' => ['Besiktning']],
                ]],
                ['name' => 'Nödraketer', 'category' => 'Signalutrustning', 'tags' => ['Besiktning']],
                ['name' => 'Brandsläckare', 'category' => 'Säkerhet', 'tags' => ['Besiktning']],
                ['name' => 'Ankare', 'category' => null, 'tags' => []],
            ],
        ],
        [
            'name' => 'Sommarstugan',
            'kind' => 'cottage',
            'cover' => false,
            'categories' => [
                'Trädgård' => ['Gräsklippning', 'Odling'],
                'Byggnad' => ['Vatten', 'Värme'],
            ],
            'tags' => ['Vårstädning' => '#65a30d', 'Vinterförvaring' => '#0284c7', 'Akut' => '#e11d48'],
            'files' => 'Vattenpump',
            'items' => [
                ['name' => 'Vattenpump', 'category' => 'Vatten', 'tags' => ['Vinterförvaring', 'Akut'], 'children' => [
                    ['name' => 'Tryckkärl', 'category' => 'Vatten', 'tags' => ['Vinterförvaring']],
                ]],
                ['name' => 'Gräsklippare', 'category' => 'Gräsklippning', 'tags' => ['Vårstädning'], 'children' => [
                    ['name' => 'Kniv till gräsklippare', 'category' => 'Gräsklippning', 'tags' => ['Vårstädning'], 'children' => [
                        ['name' => 'Slipsten', 'category' => 'Gräsklippning', 'tags' => []],
                    ]],
                ]],
                ['name' => 'Växthus', 'category' => 'Odling', 'tags' => ['Vårstädning']],
                ['name' => 'Kamin', 'category' => 'Värme', 'tags' => ['Vinterförvaring']],
                ['name' => 'Vedbod', 'category' => 'Värme', 'tags' => []],
                ['name' => 'Trädgårdsslang', 'category' => 'Odling', 'tags' => ['Vinterförvaring']],
                ['name' => 'Brunnslock', 'category' => 'Vatten', 'tags' => ['Akut']],
            ],
        ],
    ];

    /** @var list<Attachment> */
    private array $attachments = [];

    private int $containerCount = 0;

    private int $itemCount = 0;

    public function handle(
        CreatesUserWithPersonalAccount $createUser,
        GrantInternalPro $grantInternalPro,
    ): int {
        $email = $this->argument('email');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Adressen är ingen giltig e-postadress.');

            return self::FAILURE;
        }

        // Skiftlägesokänsligt: `Mia@Manjo.ME` är samma adress som
        // `mia@manjo.me`, och kommandot skriver aldrig över ett konto.
        if (User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->exists()) {
            $this->error("Det finns redan ett konto med adressen {$email}.");

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $this->option('force')
            && ! $this->confirm("Skapa testkontot {$email} i production?", false)) {
            return self::FAILURE;
        }

        $password = $this->askPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $this->attachments = [];
        $this->containerCount = 0;
        $this->itemCount = 0;

        try {
            $user = DB::transaction(function () use ($createUser, $grantInternalPro, $email, $password): User {
                $user = $createUser->handle(self::NAME, $email, $password, null);
                $user->markEmailAsVerified();

                $grantInternalPro->handle($user);

                $account = $user->accounts()->wherePivot('role', 'owner')->firstOrFail();

                $this->build($user, $account);

                return $user;
            });
        } catch (Throwable $e) {
            $this->removeOrphanedFiles();
            $this->error('Kontot skapades inte: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            Auth::forgetUser();
        }

        $this->summarize($user);

        return self::SUCCESS;
    }

    /**
     * Efter en rollback ligger raderna borta men bytes som StoreAttachment
     * skrivit står kvar: dess egen städning gäller bara dess egen transaktion,
     * och inne i vår yttre är den en savepoint. Tar bort de filer som skrevs
     * under körningen och vars `stored_file`-rad inte längre finns.
     */
    private function removeOrphanedFiles(): void
    {
        foreach ($this->attachments as $attachment) {
            $path = $attachment->storedFile->storage_path;

            if (! StoredFile::query()->where('storage_path', $path)->exists()) {
                $disk = Storage::disk('files');
                $disk->delete($path);

                // Bildvarianterna ligger bredvid originalet som `<sökväg>_namn`.
                foreach ($disk->files(dirname($path)) as $file) {
                    if (str_starts_with($file, $path.'_')) {
                        $disk->delete($file);
                    }
                }
            }
        }
    }

    /**
     * Frågar lösenordet två gånger. Det är aldrig ett argument eller en
     * flagga, så det hamnar inte i skalets historik. Null när det inte håller.
     */
    private function askPassword(): ?string
    {
        $password = $this->secret('Lösenord');
        $again = $this->secret('Lösenord igen');

        if (! is_string($password) || $password !== $again) {
            $this->error('Lösenorden är inte lika.');

            return null;
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::defaults()]],
        );

        if ($validator->fails()) {
            $this->error('Lösenordet uppfyller inte appens lösenordsregel.');

            return null;
        }

        return $password;
    }

    private function build(User $user, Account $account): void
    {
        // SetContainerCover prövar Gate::authorize; en artisan-körning har
        // ingen inloggad användare, så vi är kontots ägare under bygget.
        Auth::setUser($user);

        $containers = array_map(
            fn (array $spec): Container => $this->buildContainer($user, $account, $spec),
            self::CONTAINERS,
        );

        $this->extrasForFirstContainer($user, $account, $containers[0]);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function buildContainer(User $user, Account $account, array $spec): Container
    {
        $container = app(CreateContainer::class)->handle($user, $account, $spec['name'], $spec['kind']);
        $this->containerCount++;

        if ($spec['cover']) {
            $this->attachments[] = app(SetContainerCover::class)->handle(
                $container,
                $this->pngFile($spec['name']),
                $user,
                $account,
            );
        }

        /** @var array<string, Category> $categories */
        $categories = [];

        foreach ($spec['categories'] as $top => $children) {
            $parent = app(CreateCategory::class)->handle($container, $top, null, null, $user);
            $categories[$top] = $parent;

            foreach ($children as $child) {
                $categories[$child] = app(CreateCategory::class)->handle($container, $child, $parent, null, $user);
            }
        }

        /** @var array<string, Tag> $tags */
        $tags = [];

        foreach ($spec['tags'] as $name => $color) {
            $tags[$name] = app(CreateTag::class)->handle($container, $name, $color, $user);
        }

        foreach ($spec['items'] as $itemSpec) {
            $this->buildItem($user, $account, $container, $categories, $tags, $itemSpec, null, $spec['files']);
        }

        return $container;
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, Tag>  $tags
     * @param  array<string, mixed>  $spec
     */
    private function buildItem(
        User $user,
        Account $account,
        Container $container,
        array $categories,
        array $tags,
        array $spec,
        ?Item $parent,
        string $filesItem,
    ): Item {
        $item = app(CreateItem::class)->handle(
            $container,
            $account,
            $user,
            new Item(['name' => $spec['name'], 'description' => $spec['name'].' i testkontot.']),
            isset($spec['category']) ? $categories[$spec['category']] : null,
            new Collection(array_map(fn (string $name): Tag => $tags[$name], $spec['tags'] ?? [])),
            $parent,
        );
        $this->itemCount++;

        if ($spec['name'] === $filesItem) {
            $this->addContent($user, $account, $item);
        }

        foreach ($spec['children'] ?? [] as $child) {
            $this->buildItem($user, $account, $container, $categories, $tags, $child, $item, $filesItem);
        }

        return $item;
    }

    /**
     * Anteckning med markdown, en bild och en PDF på samma item.
     */
    private function addContent(User $user, Account $account, Item $item): Attachment
    {
        $name = $item->name;

        app(UpdateItemNotes::class)->handle(
            $item,
            $user,
            "# {$name}\n\nAnteckning med **markdown**.\n\n- Köpt för några år sedan\n- Manualen ligger som PDF\n- Se även bilden\n",
        );

        $image = app(StoreAttachment::class)->handle($item, $this->pngFile($name), $user, $account);
        $pdf = app(StoreAttachment::class)->handle($item, $this->pdfFile($name), $user, $account);

        $this->attachments[] = $image;
        $this->attachments[] = $pdf;

        return $image;
    }

    private function extrasForFirstContainer(User $user, Account $account, Container $container): void
    {
        $items = Item::query()->where('container_id', $container->id)->get()->keyBy('name');
        $today = $user->today();

        // Itemomslag: bilden på Diskmaskin.
        $diskmaskin = $items['Diskmaskin'];
        $image = Attachment::query()->where('item_id', $diskmaskin->id)->where('kind', 'image')->firstOrFail();
        $diskmaskin->cover_attachment_id = $image->id;
        app(UpdateItem::class)->handle($diskmaskin, $user, false, new Collection);

        app(LinkItems::class)->handle($items['Kyl och frys'], $diskmaskin, 'related', $user);

        app(CreateLoan::class)->handle($items['Borrmaskin'], $user, new Loan([
            'borrower_name' => 'Berit Granne',
            'lent_at' => $today->copy()->subDays(5)->toDateString(),
            'due_at' => $today->copy()->addDays(9)->toDateString(),
            'note' => 'Lånad till altanbygget.',
        ]));

        $costs = app(CreateCostEntry::class);
        $costs->handle($container, $diskmaskin, [
            'incurred_on' => $today->copy()->subDays(40)->toDateString(),
            'amount' => '6495.00',
            'currency' => 'SEK',
            'description' => 'Inköp av diskmaskin',
            'supplier' => 'Elgiganten',
        ], $user);
        $costs->handle($container, $diskmaskin, [
            'incurred_on' => $today->copy()->subDays(12)->toDateString(),
            'amount' => '389.50',
            'currency' => 'SEK',
            'description' => 'Nytt filter',
            'supplier' => 'Elgiganten',
        ], $user);

        app(CreateSchedule::class)->handle($items['Snöslunga'], $user, new Schedule([
            'title' => 'Service av snöslungan',
            'recurrence_type' => 'interval',
            'interval_unit' => 'year',
            'interval_count' => 1,
            'anchor_date' => $today->copy()->addDays(14)->toDateString(),
            'lead_days' => 7,
            'is_active' => true,
        ]));

        // Ingen action finns för favoriter; kontrollern gör samma sak.
        $favorite = new Favorite;
        $favorite->item_id = $diskmaskin->id;
        $user->favorites()->save($favorite);

        // Papperskorgen: ett item och en bilaga.
        app(DeleteItem::class)->handle(
            app(CreateItem::class)->handle(
                $container,
                $account,
                $user,
                new Item(['name' => 'Gammal brödrost', 'description' => 'Slängd, ligger i papperskorgen.']),
                null,
                new Collection,
                null,
            ),
            $user,
        );
        $this->itemCount++;

        $stege = $items['Stege'];
        $gammalBild = app(StoreAttachment::class)->handle($stege, $this->pngFile('Gammal bild på stegen'), $user, $account);
        $this->attachments[] = $gammalBild;
        app(TrashAttachment::class)->handle($gammalBild, $user);
    }

    /**
     * En enfärgad PNG med itemets namn, ritad med GD. Färgen följer namnet så
     * att varje bild får eget innehåll och därmed egen `stored_file`.
     */
    private function pngFile(string $text): UploadedFile
    {
        $image = imagecreatetruecolor(480, 320);

        if ($image === false) {
            throw new RuntimeException('GD kunde inte skapa en bild.');
        }

        $hash = crc32($text);
        $background = imagecolorallocate($image, 60 + ($hash & 0x7F), 60 + (($hash >> 8) & 0x7F), 60 + (($hash >> 16) & 0x7F));
        $white = imagecolorallocate($image, 255, 255, 255);

        if ($background === false || $white === false) {
            throw new RuntimeException('GD kunde inte allokera färger.');
        }

        imagefill($image, 0, 0, $background);
        // Inbyggda typsnitt är ISO-8859-1; å, ä och ö ritas annars som skräp.
        imagestring($image, 5, 20, 150, mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8'), $white);

        $path = $this->tempPath('png');
        imagepng($image, $path);

        return new UploadedFile($path, $text.'.png', 'image/png', null, true);
    }

    /**
     * En minimal giltig PDF med en tom sida och itemets namn som titel.
     */
    private function pdfFile(string $text): UploadedFile
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>',
            '<< /Title ('.preg_replace('/[^A-Za-z0-9 ]/', '', $text).') >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= 'trailer
<< /Size '.(count($objects) + 1)." /Root 1 0 R /Info 4 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        $path = $this->tempPath('pdf');
        file_put_contents($path, $pdf);

        return new UploadedFile($path, $text.'.pdf', 'application/pdf', null, true);
    }

    private function tempPath(string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), 'testkonto');

        if ($path === false) {
            throw new RuntimeException('Kunde inte skapa en temporär fil.');
        }

        // tempnam lämnar en tom fil som vi skriver över; den städas av
        // operativsystemet, och bytena ligger redan kopierade i lagringen.
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }

    private function summarize(User $user): void
    {
        $account = $user->accounts()->wherePivot('role', 'owner')->firstOrFail();
        $plan = $account->currentPlan();
        $bytes = (int) DB::table('usage_counter')->where('account_id', $account->id)->value('storage_bytes');

        $this->line("Testkontot {$user->email} skapades.");
        $this->line('Plan: '.$plan->code);
        $this->line("Containers: {$this->containerCount}");
        $this->line("Items: {$this->itemCount}");
        $this->line('Bilagor: '.count($this->attachments));
        $this->line("Använda bytes: {$bytes}");
    }
}
