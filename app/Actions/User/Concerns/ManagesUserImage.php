<?php

namespace App\Actions\User\Concerns;

use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Attachment\StoreAttachment;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\User;
use App\Support\Plan\Entitlements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/*
 * Den gemensamma skrivvägen för en bild som hör till personen — profilbilden
 * och hjältebilden. Se [[ADR-0076 Profilbilden]] § 2, 3 och 7 och
 * [[ADR-0090 Dashboardens hjälte]] § 2.
 *
 * **Två bilder, en regel.** Profilbilden och hjältebilden gör exakt samma sak
 * med olika kolumner: bara `kind = image`, högst en levande, en ny bild
 * ersätter den förra som rensas direkt med `PurgeAttachment`, bytena räknas
 * mot personens personkonto, och utsnittet nollställs när bilden byts eller tas
 * bort. Regeln bodde i App\Actions\User\SetUserAvatar, SetUserAvatarCrop och
 * RemoveUserAvatar; den flyttades hit när hjältebilden (issue 434) hade blivit
 * en andra kopia av samma tre metoder. Prefixet (`avatar` eller `hero`) väljer
 * kolumnerna, ingenting annat skiljer.
 *
 * **Anroparen äger beroendena.** Traiten tar `StoreAttachment`,
 * `PurgeAttachment` och `Entitlements` som argument i stället för att läsa dem
 * ur `$this` — en trait som förutsätter privata egenskaper på den användande
 * klassen är en osynlig konstruktor som PHPStan inte kan följa. Actionerna
 * injicerar dem som förut och skickar in dem.
 */

trait ManagesUserImage
{
    /**
     * Sätter eller byter personens bild och rensar den förra.
     *
     * **Allt sker i samma transaktion och under radlås på personen.** Bytet,
     * StoreAttachments rad och rensningen av den förra bilden är ett enda
     * åtagande: fallerar något rullas pekaren, räkningen och bilagan tillbaka
     * tillsammans. `lockForUpdate` är en current read, så två samtidiga anrop
     * på samma person serialiseras — den som kommer sist ser den förstas
     * pekare och rensar den.
     */
    private function storeUserImage(
        User $user,
        UploadedFile $file,
        string $prefix,
        StoreAttachment $storeAttachment,
        PurgeAttachment $purgeAttachment,
        Entitlements $entitlements,
    ): Attachment {
        $account = $this->personalAccount($user);

        $byteSize = $file->getSize();
        if ($byteSize === false) {
            throw new RuntimeException('Den mottagna filen kunde inte läsas.');
        }

        // Billig avvisning före lagringen; StoreAttachment prövar totalkvoten
        // en gång till, auktoritativt, under radlåset på räknaren.
        $entitlements->assertFileWithinLimit($account, $byteSize);
        $entitlements->assertStorageWithinLimit($account, $byteSize);

        $pointer = $prefix.'_attachment_id';

        return DB::transaction(function () use ($user, $file, $account, $prefix, $pointer, $storeAttachment, $purgeAttachment): Attachment {
            // Raden läses om under låset: pekaren skrivs på raden och inte på
            // den instans anroparen skickade in, så en samtidig begäran ser
            // den förstas skrivning.
            $rad = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $forra = $rad->getAttribute($pointer);

            $bilaga = $storeAttachment->handleForUser($rad, $file, $account);

            $rad->setAttribute($pointer, $bilaga->id);

            // Ett utsnitt valt på den FÖRRA bilden säger ingenting om den nya
            // ([[ADR-0076 Profilbilden]] § 7). Alla tre nollställs på samma
            // rad som pekaren byts, så CHECK-villkoret aldrig ser en halv
            // punkt och nästa bild beskärs som förut — mitten och 100 %.
            $rad->setAttribute($prefix.'_focus_x', null);
            $rad->setAttribute($prefix.'_focus_y', null);
            $rad->setAttribute($prefix.'_zoom', null);

            $rad->save();

            if ($forra !== null && (int) $forra !== $bilaga->id) {
                // withTrashed() — pekaren kan peka på en bilaga som redan
                // mjukraderats eller rensats. PurgeAttachment är idempotent
                // per rad och en saknad rad är inget att städa.
                $gammal = Attachment::withTrashed()->find((int) $forra);

                if ($gammal !== null) {
                    $purgeAttachment->handle($gammal);
                }
            }

            // Instansen anroparen skickade in bär den nya pekaren — annars
            // vore den en förlegad läsning av samma slag som låset stänger.
            $user->refresh();

            return $bilaga;
        }, 3);
    }

    /**
     * Sparar fokuspunkt och zoom på personens bild.
     *
     * **En person UTAN bild har ingen punkt att sätta.** Saknar raden pekaren
     * returneras `false` och ingenting skrivs — kontrollerna gör det till en
     * 404, för ett utsnitt på en bild som inte finns är ett anrop mot ett
     * objekt som inte finns.
     *
     * Alla tre kolumnerna skrivs i samma `save()`, så CHECK-villkoret för
     * paret aldrig ser en halv punkt.
     */
    private function cropUserImage(User $user, int $x, int $y, int $zoom, string $prefix): bool
    {
        $pointer = $prefix.'_attachment_id';

        return DB::transaction(function () use ($user, $x, $y, $zoom, $prefix, $pointer): bool {
            $rad = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null || $rad->getAttribute($pointer) === null) {
                return false;
            }

            $rad->setAttribute($prefix.'_focus_x', $x);
            $rad->setAttribute($prefix.'_focus_y', $y);
            $rad->setAttribute($prefix.'_zoom', $zoom);
            $rad->save();

            // Instansen anroparen skickade in bär det nya utsnittet.
            $user->refresh();

            return true;
        });
    }

    /**
     * Tar bort personens bild, eller är en no-op när den redan är borta.
     *
     * **Rensas, inte mjukraderas.** Bilden går inte till papperskorgen
     * (ADR-0076 § 2): den som ångrar sig laddar upp den igen.
     *
     * **Ordningen: pekaren först, bilagan sedan.** `PurgeAttachment`
     * nollställer pekaren själv innan den raderar bilagan hårt, eftersom
     * nyckeln är RESTRICT; att nollställa först gör ordningen till actionens
     * egen regel i stället för en bieffekt av en främmande nyckel.
     *
     * Pekaren läses och nollställs under radlås av samma skäl som i
     * storeUserImage(): en samtidig borttagning och ett samtidigt byte som
     * båda läste pekaren ur egna instanser kunde lämna den ena bilagan
     * föräldralös.
     */
    private function removeUserImage(User $user, string $prefix, PurgeAttachment $purgeAttachment): bool
    {
        $pointer = $prefix.'_attachment_id';

        return DB::transaction(function () use ($user, $prefix, $pointer, $purgeAttachment): bool {
            $rad = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null || $rad->getAttribute($pointer) === null) {
                return false;
            }

            $bilagaId = (int) $rad->getAttribute($pointer);

            $rad->setAttribute($pointer, null);

            // Utsnittet hör till bilden och försvinner med den
            // ([[ADR-0076 Profilbilden]] § 7): nollställs på samma rad som
            // pekaren, så en ny bild aldrig ärver den gamlas fokuspunkt.
            $rad->setAttribute($prefix.'_focus_x', null);
            $rad->setAttribute($prefix.'_focus_y', null);
            $rad->setAttribute($prefix.'_zoom', null);

            $rad->save();

            $bilaga = Attachment::withTrashed()->find($bilagaId);

            if ($bilaga !== null) {
                $purgeAttachment->handle($bilaga);
            }

            // Instansen anroparen skickade in bär den nollställda pekaren.
            $user->refresh();

            return true;
        });
    }

    /**
     * Personens personkonto, eller ett fältfel när hon saknar ett.
     *
     * Uppslaget är App\Support\Account\ActingAccount:s ordagrant: `type =
     * 'personal'` bland personens medlemskap, lägst `id` vinner. ADR-0076 § 3:
     * en person utan personkonto har ingen kvot att belasta och kan inte ladda
     * upp en bild — hon ser initialerna och en förklaring. Koden är densamma
     * för båda bilderna: hindret är personkontot, inte bildens plats.
     */
    private function personalAccount(User $user): Account
    {
        $personal = $user->accounts()
            ->where('account.type', 'personal')
            ->orderBy('account.id')
            ->first();

        if ($personal === null) {
            throw ApiException::make('avatar.no_personal_account');
        }

        return $personal;
    }
}
