<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Växeln för framtida uppgifter — `PUT /settings/tasks`, se
 * [[M21 Uppgifterna i vardagen]] § 134.
 *
 * **EN rutt och ingen sida.** Växeln står i rubrikraden på `/tasks`
 * (resources/js/pages/Tasks/Index.vue) och på dashboardens uppgiftspanel
 * (resources/js/components/DashboardTasksPanel.vue), och båda ritar samma
 * komponent — resources/js/components/UpcomingTasksToggle.vue. En egen
 * inställningssida för en enda boolean hade varit en andra väg till samma
 * skrivning, och en yta ingen letar på: frågan "vilka uppgifter ska listan
 * visa?" ställs där listan står.
 *
 * **Objektet är anroparen själv.** Ingen ruttparameter och ingen policy, av
 * exakt samma skäl som App\Http\Controllers\Settings\
 * NotificationSettingsController § Beslut: en policy hade svarat på "får jag
 * ändra min egen flagga?", vars svar alltid är ja. Rutten ligger bakom
 * `auth`, och `user_id` kommer ur sessionen och aldrig ur kroppen.
 *
 * **`back()` och ingen flash.** Växeln är sitt eget kvitto — den slår om och
 * står kvar i sitt nya läge när sidan ritas om — och en ruta som sade
 * *inställningen sparad* hade varit en andra bekräftelse på samma sak. Samma
 * form och samma skäl som App\Http\Controllers\DismissedTipController.
 *
 * `show_upcoming_tasks` ligger inom `#[Fillable]` på App\Models\User, som
 * `locale` och `timezone`: den är en personlig inställning och regeln om vem
 * som får skriva den är rutten, inte en lista i modellen.
 */
class TaskPreferenceController extends Controller
{
    /**
     * PUT /settings/tasks — 302 tillbaka till sidan växeln står på.
     *
     * **Värdet valideras som en boolean och ingenting annat.** Kroppen bär
     * exakt ett fält, och ett värde som varken är sant eller falskt — en
     * sträng ur en handskriven request — blir ett valideringsfel på fältet,
     * inte en tystnande `(bool)`-kastning: `(bool) 'nej'` är `true`, och en
     * växel som svarar på fel fråga är värre än ett felmeddelande.
     *
     * `back()` och inte en omdirigering till en fast rutt: ytan finns på två
     * adresser, och den som slog om växeln på den ena ska stanna där.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'show_upcoming_tasks' => ['required', 'boolean'],
        ]);

        $request->user()->update([
            'show_upcoming_tasks' => $request->boolean('show_upcoming_tasks'),
        ]);

        return back();
    }
}
