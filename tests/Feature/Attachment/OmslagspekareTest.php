<?php

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Item\ResolveItemCover;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;

/*
 * Issue 93 · Omslagspekaren och den HÅRDA raderingen — se [[ADR-0041
 * Itemets vy]] § Konsekvenser och [[ADR-0008 Soft delete och papperskorg]].
 *
 * Itemets sida av omslagsbilden prövas i tests/Feature/Item/OmslagsbildTest.
 * Det här är bilagans: vad som händer med pekaren när bilagan försvinner på
 * riktigt.
 *
 * **Avvikelsen från husets `onDelete('restrict')` är hela ärendet.** Ett
 * omslag är en preferens och inte data, och en preferens får aldrig hindra
 * papperskorgens gallring. Bilagor raderas hårt på två ställen —
 * App\Actions\Attachment\PurgeAttachment när en bilaga gallras ur
 * papperskorgen, och App\Actions\Trash\PurgeContent när ett item gör det — och
 * med RESTRICT hade båda fallit på ett främmandenyckelfel. Felet hade synts
 * först i drift, i en nattlig körning, i stället för här.
 *
 * Det första testet går genom den RIKTIGA vägen ut ur papperskorgen och inte
 * genom `forceDelete()` på en modell: det är den körningen som annars hade
 * fallit i produktion.
 *
 * Ingen `Storage::fake()` behövs — bytena på disken rörs av issue 17b, inte av
 * gallringen (PurgeAttachment § Beslut 4).
 */

/**
 * Ett item med en medlem och en bild, plus bilden.
 *
 * @return array{0: Item, 1: Attachment}
 */
function omslagspekareBild(string $filnamn = 'foto.jpg'): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$item, Attachment::factory()->for($item, 'item')->create([
        'filename' => $filnamn,
        'kind' => 'image',
    ])];
}

/*
 * Klart när: en bilaga som raderas hårt nollställer pekaren i stället för att
 * blockera raderingen.
 *
 * Gallringen är papperskorgens egen väg (issue 20a/20b) — bilagan
 * mjukraderas först, precis som i drift — och itemet står kvar efteråt med
 * pekaren nollställd. Kastar raderingen ett främmandenyckelfel är det testet
 * som faller, och det är exakt det fel som annars hade synts i den nattliga
 * gallringen.
 */
it('nollställer pekaren när bilagan gallras, i stället för att blockera', function () {
    [$item, $bild] = omslagspekareBild();

    $item->cover_attachment_id = $bild->id;
    $item->save();

    $bild->delete();

    (new PurgeAttachment)->handle($bild);

    expect(Attachment::withTrashed()->whereKey($bild->id)->exists())->toBeFalse();
    expect($item->refresh()->cover_attachment_id)->toBeNull();
    expect(Item::query()->whereKey($item->id)->exists())->toBeTrue();
});

/*
 * Samma sak för en hård radering som inte går genom papperskorgen: regeln
 * sitter i den främmande nyckeln och gäller varje DELETE, inte bara den väg
 * som råkar vara den vanliga.
 */
it('nollställer pekaren även vid forceDelete på modellen', function () {
    [$item, $bild] = omslagspekareBild();

    $item->cover_attachment_id = $bild->id;
    $item->save();

    $bild->forceDelete();

    expect($item->refresh()->cover_attachment_id)->toBeNull();
});

/*
 * Följden av det hela: itemet blir inte av med sitt ansikte. När den valda
 * bilagan gallras hårt är pekaren null, steg 1 i upplösningen har ingenting
 * att pröva, och steg 2 ger itemets äldsta bild — samma svar som när valet
 * aldrig gjorts ([[ADR-0041 Itemets vy]] § Beslut).
 */
it('faller tillbaka på den äldsta bilden när den valda bilagan gallrats', function () {
    [$item, $aldsta] = omslagspekareBild('gammal.jpg');

    $vald = Attachment::factory()->for($item, 'item')->create([
        'filename' => 'ny.jpg',
        'kind' => 'image',
    ]);

    $item->cover_attachment_id = $vald->id;
    $item->save();

    (new PurgeAttachment)->handle($vald);

    expect($item->refresh()->cover_attachment_id)->toBeNull();
    expect((new ResolveItemCover)->handle($item)?->id)->toBe($aldsta->id);
});
