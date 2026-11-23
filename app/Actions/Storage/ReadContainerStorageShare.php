<?php

namespace App\Actions\Storage;

use App\Models\Container;
use Illuminate\Support\Facades\DB;

/**
 * Containerns andel av ägarkontots lagring — issue 459 · M79.
 *
 * Summan av `stored_file.byte_size` för containerns bilagor: containerns egen
 * bild plus bilagorna på items i containern, även de i papperskorgen. Frågan
 * är rå, så inga SoftDeletes-scope filtrerar bort papperskorgen.
 *
 * **Filtrerat på `attachment.billed_account_id = container.account_id`.**
 * Det gör att andelen aldrig blir större än det ägarkontot faktiskt debiteras
 * för: en gästs uppladdning debiteras gästens eget konto och ligger i
 * containern utan att belasta ägarens kvot. Både toppradens kvotmodul och
 * panelen *Containerdetaljer* läser den här actionen, så de två visar samma
 * tal.
 */
class ReadContainerStorageShare
{
    public function handle(Container $container): int
    {
        return (int) DB::table('attachment')
            ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
            ->where('attachment.billed_account_id', $container->account_id)
            ->where(function ($query) use ($container) {
                $query->where('attachment.container_id', $container->id)
                    ->orWhereIn('attachment.item_id', DB::table('item')
                        ->select('id')
                        ->where('container_id', $container->id));
            })
            ->sum('stored_file.byte_size');
    }
}
