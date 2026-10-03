<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Notisklockans skrivningar — att öppna den och att rensa den, se issue 127
 * och 647 samt [[M19 Dashboarden]] § 127 och [[Konton och åtkomst]] § user.
 *
 * **TVÅ metoder, och det är hela ytan.** Listan och siffran läses ur de
 * delade propsen (App\Http\Middleware\HandleInertiaRequests) och har ingen
 * rutt: en GET här hade varit en andra väg till samma läsning, och den vägen
 * hade behövt sin egen sortering och sitt eget tak att hålla i takt med
 * listans. Det som återstår för en kontroller är det klockan SKRIVER — att
 * den öppnades, och att den rensades.
 *
 * **Klockan är ingen kanal** (issue 127 § Beslut). Skrivningarna rör bara
 * användarradens egna kolumner: ingen `notification_delivery`, ingen
 * preferens, ingen tysta-timmar-beräkning och ingen kö. Den som öppnar
 * klockan har redan fått sina mejl; det här är bara "jag har sett dem", och
 * det svaret bor hos personen och inte i outboxen ([[ADR-0010
 * Notisarkitektur]] § Beslut).
 *
 * **Tidsstämplarna sätts till nu, aldrig till radens eget `created_at`.** Den
 * som öppnar eller rensar klockan har läst allt som fanns i den, och en
 * gräns satt vid den senaste radens tid hade lämnat de äldre utanför för
 * alltid.
 *
 * **Två kolumner och två frågor.** `notifications_read_at` styr SIFFRAN och
 * sätts av `store()`; `notifications_cleared_at` styr LISTAN och sätts av
 * `clear()`. Den som rensar sätter BÅDA — en rensad panel med en kvarstående
 * siffra hade varit en nolla som inte stämmer — men de förblir skilda
 * kolumner, för "markera som läst utan att rensa" är en fråga klockan redan
 * ställer och rensningen ska inte ta ifrån den dess svar.
 *
 * **Inbjudningarna nollställs inte här** (issue 131). Klockans siffra räknar
 * två saker — olästa `notification`-rader och väntande inbjudningar — och
 * ingen av skrivningarna rör den andra. En inbjudan är obesvarad till dess
 * att den besvarats och inte till dess att den setts: både att öppna och att
 * rensa klockan får siffran att falla med notiserna, men inbjudningarna står
 * kvar tills de accepterats eller avvisats på `/invitations`. Att låta en
 * tidsstämpel tysta dem hade varit att gömma en väntande inbjudan bakom ett
 * klick på en klocka.
 *
 * Svaret är `back()` och ingenting annat — mönstret från issue 51 § Beslut 5.
 * Klienten gör sin partiella omladdning i samma svep och behöver ingen
 * kropp tillbaka.
 */
class NotificationInboxController extends Controller
{
    /**
     * POST /notifications/read — klockan öppnades, siffran nollställs.
     *
     * Användaren kommer ur sessionen (rutten ligger bakom `auth`), aldrig ur
     * kroppen: en ULID i kroppen hade varit en rutt utan objekt att
     * auktorisera mot, och den enda raden en person får märka läst är sin egen
     * — samma regel som [[Konton och åtkomst]] § user gör för varje annan
     * personlig kolumn.
     */
    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->notifications_read_at = now();
        $user->save();

        return back();
    }

    /**
     * POST /notifications/clear — panelen rensas: listan töms, siffran
     * nollställs.
     *
     * **Ingen rad raderas.** `notification` är outboxen
     * ([[ADR-0010 Notisarkitektur]] § Beslut) och raderna står kvar orörda;
     * det som ändras är personens två tidsstämplar.
     * `notifications_cleared_at` flyttar listans gräns
     * (HandleInertiaRequests::notifications()), och `notifications_read_at`
     * nollställer siffran — samma kolumn `store()` sätter. Båda får SAMMA
     * ögonblick, så en rad aldrig kan hamna på ena sidan den ena gränsen och
     * andra sidan den andra.
     *
     * **Användaren kommer ur sessionen**, aldrig ur kroppen — samma regel som
     * `store()` ovan och [[Konton och åtkomst]] § user: den enda lista en
     * person får rensa är sin egen.
     */
    public function clear(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $nu = now();

        $user->notifications_cleared_at = $nu;
        $user->notifications_read_at = $nu;
        $user->save();

        return back();
    }
}
