<?php

namespace App\Actions\Container;

use App\Actions\Attachment\PurgeAttachment;
use App\Models\Attachment;
use App\Models\Container;
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

        $bilagaId = $container->cover_attachment_id;

        if ($bilagaId === null) {
            return false;
        }

        $container->cover_attachment_id = null;
        $container->save();

        $bilaga = Attachment::withTrashed()->find($bilagaId);

        if ($bilaga !== null) {
            $this->purgeAttachment->handle($bilaga);
        }

        return true;
    }
}
