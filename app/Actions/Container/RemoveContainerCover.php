<?php

namespace App\Actions\Container;

use App\Actions\Attachment\PurgeAttachment;
use App\Models\Attachment;
use App\Models\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Tar bort containerns bild — [[ADR-0047 Containerns bild]] § Beslut och
 * issue 158: pekaren nollställs och bilagan rensas.
 *
 * **Rensas, inte mjukraderas.** En containerbild går inte till
 * papperskorgen (ADR-0047 § Beslut, fjärde stycket): den som ångrar sig
 * laddar upp bilden igen. PurgeAttachment minskar `reference_count` och
 * lämnar bytena på disken med `purge_after` satt — de ligger kvar i minst
 * 30 dagar enligt [[Filer och lagring]] § Radering.
 *
 * **Grinden är `ContainerPolicy::update`**, samma pinne som att sätta
 * bilden. Den ligger i `handle()` och inte hos anroparen, av samma skäl som
 * i SetContainerCover. `purge()` är kärnan utan grind — den delas med
 * App\Actions\Attachment\TrashAttachment, vars anropare redan har sin egen.
 * Samma uppdelning som PurgeAttachment gör: verktyget prövar inte vem som
 * får, det gör den som äger ytan.
 *
 * **Ordningen: pekaren först, bilagan sedan.** Hade bilagan rensats först
 * hade `cover_attachment_id` pekat på en rad som inte finns — och
 * `ON DELETE SET NULL` hade räddat den, men bara för att nyckeln råkar vara
 * satt så. Att nollställa först gör ordningen till actionens egen regel i
 * stället för en bieffekt av en främmande nyckel.
 *
 * **Pekaren läses och nollställs under radlås** (granskningsfynd 1), av
 * samma skäl som i SetContainerCover: en samtidig rensning och ett samtidigt
 * bildbyte som båda läste pekaren ur sina egna instanser kunde lämna den ena
 * bilagan föräldralös — aldrig nollställd, aldrig rensad, kvar på kontots
 * `reference_count` och kvot. `lockForUpdate` är en current read, så den som
 * kommer sist ser den förstas skrivning. Rensningen ligger INNANFÖR
 * transaktionen: kastar den rullas nollställningen tillbaka tillsammans med
 * räkningen.
 *
 * En container UTAN bild är inget fel: anropet är en no-op och returnerar
 * false, så ytan kan visa "ta bort bilden" utan att först behöva veta om
 * det finns någon.
 *
 * `Attachment::withTrashed()` vid uppslaget: pekaren kan peka på en bilaga
 * som redan mjukraderats (av en äldre väg, eller av en samtidig rensning).
 * PurgeAttachment är idempotent per rad och tål en sådan rad.
 */
class RemoveContainerCover
{
    public function __construct(private readonly PurgeAttachment $purgeAttachment) {}

    public function handle(Container $container): bool
    {
        Gate::authorize('update', $container);

        return DB::transaction(function () use ($container): bool {
            $bilagaId = $this->clearPointer($container);

            if ($bilagaId === null) {
                return false;
            }

            $bilaga = Attachment::withTrashed()->find($bilagaId);

            if ($bilaga !== null) {
                $this->purgeAttachment->handle($bilaga);
            }

            return true;
        });
    }

    /**
     * Kärnan, utan grind: den bilaga som anroparen pekar ut rensas, och
     * containerns pekare nollställs om den pekar på just den raden.
     *
     * App\Actions\Attachment\TrashAttachment tar den här vägen när en
     * containerbilaga väljs för borttagning — av lagringsytan eller av
     * nedgraderingen. En sådan bilaga går INTE till papperskorgen (ADR-0047
     * § Beslut, fjärde stycket): den rensas direkt. Grinden ligger hos
     * anroparen där (AccountPolicy::manageStorage, eller systemet vid
     * nedgraderingen), precis som ContainerPolicy::update ligger hos
     * handle() — kärnan prövar ingen.
     *
     * Villkoret på pekaren är det som skiljer den här vägen från handle():
     * TrashAttachment rensar en BESTÄMD rad, och en containerbilaga som inte
     * är containerns bild ska inte släppa en pekare som går någon annanstans.
     * Containern slås upp med `withTrashed()` — en bilaga i en container i
     * papperskorgen kan väljas, och pekaren ska nollställas ändå.
     *
     * Ingen egen kontroll av om bilagan finns: PurgeAttachment är idempotent
     * per rad och en redan rensad rad är inget att städa.
     */
    public function purge(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment): void {
            if ($attachment->container_id !== null) {
                $container = Container::withTrashed()->find($attachment->container_id);

                if ($container !== null) {
                    $this->clearPointer($container, $attachment->getKey());
                }
            }

            $this->purgeAttachment->handle($attachment);
        });
    }

    /**
     * Nollställer pekaren under radlås och lämnar tillbaka id:t som stod där.
     * Null när det inte fanns något att nollställa: containern är gallrad,
     * pekaren var redan tom, eller `$endast` namnger en annan rad.
     *
     * @param  int|null  $endast  Nollställ bara när pekaren är just den här
     *                            bilagan — TrashAttachment rensar en bestämd
     *                            rad och får inte släppa någon annans pekare.
     */
    private function clearPointer(Container $container, ?int $endast = null): ?int
    {
        // withTrashed() — containern kan ha mjukraderats mellan anroparens
        // uppslag och det här låset. Är raden redan gallrad finns ingen
        // pekare att nollställa.
        $rad = Container::withTrashed()
            ->whereKey($container->getKey())
            ->lockForUpdate()
            ->first();

        if ($rad === null) {
            return null;
        }

        $bilagaId = $rad->cover_attachment_id;

        if ($bilagaId === null || ($endast !== null && $bilagaId !== $endast)) {
            return null;
        }

        $rad->cover_attachment_id = null;

        // Fokuspunkten nollställs tillsammans med pekaren (issue 682): en
        // punkt vald på en bild som inte längre finns är ingenting att behålla.
        $rad->cover_focus_x = null;
        $rad->cover_focus_y = null;

        $rad->save();

        // Instansen anroparen skickade in bär den nollställda pekaren —
        // annars vore den en förlegad läsning av samma slag som låset
        // stänger.
        $container->refresh();

        return $bilagaId;
    }
}
