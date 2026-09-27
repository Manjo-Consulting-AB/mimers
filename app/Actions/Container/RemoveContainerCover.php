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
 * bilden. Den ligger här och inte hos anroparen, av samma skäl som i
 * SetContainerCover.
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
            // withTrashed() — containern kan ha mjukraderats mellan anroparens
            // uppslag och det här låset. Är raden redan gallrad finns ingen
            // pekare att nollställa.
            $rad = Container::withTrashed()
                ->whereKey($container->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null) {
                return false;
            }

            $bilagaId = $rad->cover_attachment_id;

            if ($bilagaId === null) {
                return false;
            }

            $rad->cover_attachment_id = null;
            $rad->save();

            $bilaga = Attachment::withTrashed()->find($bilagaId);

            if ($bilaga !== null) {
                $this->purgeAttachment->handle($bilaga);
            }

            // Instansen anroparen skickade in bär den nollställda pekaren —
            // annars vore den en förlegad läsning av samma slag som låset
            // stänger.
            $container->refresh();

            return true;
        });
    }
}
