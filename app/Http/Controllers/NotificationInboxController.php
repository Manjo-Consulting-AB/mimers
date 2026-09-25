<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Notisklockans skrivning — att öppna den, se issue 127 och
 * [[M19 Dashboarden]] § 127.
 *
 * **EN metod, och det är hela ytan.** Listan och siffran läses ur de delade
 * propsen (App\Http\Middleware\HandleInertiaRequests) och har ingen rutt: en
 * GET här hade varit en andra väg till samma läsning, och den vägen hade
 * behövt sin egen sortering och sitt eget tak att hålla i takt med listans.
 * Det som återstår för en kontroller är det enda klockan SKRIVER — att den
 * öppnades.
 *
 * **Klockan är ingen kanal** (issue 127 § Beslut). Skrivningen rör EN kolumn
 * på användarraden: ingen `notification_delivery`, ingen preferens, ingen
 * tysta-timmar-beräkning och ingen kö. Den som öppnar klockan har redan fått
 * sina mejl; det här är bara "jag har sett dem", och det svaret bor hos
 * personen och inte i outboxen ([[ADR-0010 Notisarkitektur]] § Beslut).
 *
 * **Tidsstämpeln sätts till nu, aldrig till radens eget `created_at`.** Den
 * som öppnar klockan har läst allt som fanns i den, och en siffra räknad mot
 * den senaste radens tid hade lämnat de äldre olästa för alltid.
 *
 * **Inbjudningarna nollställs inte här** (issue 131). Klockans siffra räknar
 * två saker — olästa `notification`-rader och väntande inbjudningar — och den
 * här skrivningen rör bara den första. En inbjudan är obesvarad till dess att
 * den besvarats och inte till dess att den setts: att öppna klockan får
 * siffran att falla med notiserna, men inbjudningarna står kvar tills de
 * accepterats eller avvisats på `/invitations`. Att låta tidsstämpeln tysta
 * dem hade varit att gömma en väntande inbjudan bakom ett klick på en klocka.
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
}
