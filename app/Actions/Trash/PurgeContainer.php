<?php

namespace App\Actions\Trash;

use App\Actions\Usage\AdjustUsage;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

/**
 * Gallrar en mjukraderad container med HELA sitt innehåll — papperskorgen
 * för raderade containers, issue 20c. Den enda vägen till permanent radering
 * av en container: items, bilagor, kategorier, taggar, åtkomster och
 * inbjudningar försvinner med den. Den som anropar
 * (App\Console\PurgesExpiredTrash) väljer vilken container som ska bort och
 * har redan bevisat att retentionen passerats — actionen är verktyget, inte
 * grinden, precis som PurgeContent.
 *
 * Att radera en container satte bara `deleted_at` på container-raden (issue
 * 8): innehållet ligger kvar, oftast HELT levande. Allt ska ändå bort, och
 * ordningen är inte en detalj — FK:erna är RESTRICT genomgående
 * ([[Datamodell – översikt]]), så containern kan inte forceDelete:as så
 * länge items, kategorier, taggar, åtkomster eller inbjudningar pekar på
 * den.
 *
 * Innehållet gallras nedifrån och upp genom App\Actions\Trash\PurgeContent
 * (Beslut 5) — samma metoder som 20b anropar, ingen andra formulering av
 * hur ett item tas bort: `item()` tar med sig bilagorna (genom
 * PurgeAttachment, Beslut 3), `item_tag`-pivoterna och `item_link` åt båda
 * hållen, `category()` nollställer pekarna in i sig, `tag()` tar sina
 * pivotrader. Alla uppslag tar med mjukraderade rader: innehåll som raderats
 * för sig (20a) ska också bort, och det är CONTAINERNS `deleted_at` som
 * avgör — inte innehållets.
 *
 * `container_access` och `invitation` pekar på containern och har ingen
 * mening utan den, så de tas hårt (Beslut 5 punkt 5) — glöms de faller
 * `forceDelete()` på ett främmandenyckelfel varje natt.
 *
 * Hela containern hanteras i EN transaktion (Beslut 7): misslyckas något
 * rullas allt tillbaka och nästa natt tar om containern. En halvt raderad
 * container är ett tillstånd ingen kod vet hur den ska tolka.
 *
 * Kontot rörs aldrig (Beslut 8) — att gallra den sista containern på ett
 * konto raderar inte kontot.
 *
 * `AdjustUsage` anropas här med `new`, inte konstruktorinjicering — medvetet,
 * se [[ADR-0024 Tunna controllers och actions]]. Räknaren är en beroendefri,
 * tillståndslös lövaction utan egna beroenden att injicera eller mocka, och
 * den här actionen är befintlig kod som 26a bara lägger ett anrop i; att trä
 * räknaren genom konstruktorn vore omarbetning utan mottagare.
 */
class PurgeContainer
{
    public function __construct(
        private readonly PurgeContent $purgeContent,
    ) {}

    public function handle(Container $container): void
    {
        DB::transaction(function () use ($container): void {
            // withTrashed() — innehållet i containern är oftast inte
            // mjukraderat (containerns deleted_at avgör), men det som
            // raderats för sig ska också med.
            foreach (Item::withTrashed()->where('container_id', $container->id)->get() as $item) {
                $this->purgeContent->item($item);
            }

            foreach (Category::withTrashed()->where('container_id', $container->id)->get() as $category) {
                $this->purgeContent->category($category);
            }

            foreach (Tag::withTrashed()->where('container_id', $container->id)->get() as $tag) {
                $this->purgeContent->tag($tag);
            }

            DB::table('container_access')->where('container_id', $container->id)->delete();
            DB::table('invitation')->where('container_id', $container->id)->delete();

            // issue 26a § Beslut 6 — containerräknaren minskas bara för en
            // container som fortfarande var LEVANDE precis innan forceDelete.
            // Den vanliga vägen (mjukradering, 30 dagar, sedan gallring)
            // minskade redan räknaren vid mjukraderingen; ett andra avdrag
            // vore dubbelräkning.
            //
            // Radens tillstånd läses UNDER radlåset precis innan forceDelete
            // (granskningsfynd 1): instansen laddades av anroparen helt
            // utanför transaktionen, och en oskyddad `deleted_at`-avläsning kan
            // vara förlegad och dubbelräkna under samtidighet.
            $rad = Container::withTrashed()
                ->whereKey($container->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null) {
                return;
            }

            $varLevande = $rad->deleted_at === null;
            $accountId = $rad->account_id;

            // Byggarformen, inte Model::forceDelete, ger ANTALET raderade
            // rader: Model::forceDelete returnerar bara sant för instansen
            // (den fanns), oavsett om DELETE:en träffade något, och skyddar
            // därför inte mot ett andra anrop som laddat om containern
            // (granskningsfynd 2).
            $raderade = Container::withTrashed()
                ->whereKey($container->getKey())
                ->forceDelete();

            if ($raderade === 0) {
                return;
            }

            if ($varLevande) {
                (new AdjustUsage)->handle($accountId, containersDelta: -1);
            }
        });
    }
}
