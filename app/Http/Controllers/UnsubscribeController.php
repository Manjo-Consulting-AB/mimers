<?php

namespace App\Http\Controllers;

use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Notification\LocaleResolver;
use App\Support\Notification\NotificationPreferences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

/**
 * Avanmälan från en notistyp utan inloggning, se issue 32b och [[Notiser]] §
 * email_suppression. Mottagaren klickar den signerade länken i mejlet (GET,
 * bekräftelsesidan) och stänger av via formuläret (POST) — eller så POSTar
 * mejlklienten direkt via List-Unsubscribe One-Click (RFC 8058), se
 * App\Mail\NotificationMail. Båda vägarna kräver en giltig signatur; GET
 * ändrar ingenting (Beslut 1) — en mejlklient som hämtar länkar i förväg ska
 * inte stänga av något av sig själv.
 *
 * Avanmälan skriver en notification_preference-rad (enabled = false) för just
 * den typen och kanalen, ingenting annat (Beslut 6): den som tröttnat på
 * uppgiftspåminnelser ska fortfarande få veta att kontot är på väg att raderas.
 *
 * En okänd typ i sökvägen ger 404, inte en rad med ett påhittat typnamn
 * (Beslut 6). Listan är NotificationPreferences::types() — modellen har ingen
 * `Notification::TYPES` (öppen typnamnrymd), samma väg som 31b tog.
 */
class UnsubscribeController extends Controller
{
    public function __construct(
        private readonly NotificationPreferences $preferences,
        private readonly LocaleResolver $locales,
    ) {}

    public function confirm(Request $request, User $user, string $type): View
    {
        $this->abortIfUnknown($type);

        // Sidan är serverrenderad för en känd mottagare — språket väljs från
        // mottagarens locale, inte från requestens Accept-Language (AGENTS.md §
        // Felformat om serverrenderat innehåll). Länken bär `ulid`, så
        // mottagaren är känd här.
        App::setLocale($this->locales->forUser($user));

        // Formuläret POSTar till exakt den URL som verifierades — queryn bär
        // `expires` och `signature`, och den signerade POST:en valideras av
        // samma middleware som GET:en.
        return view('notis.avregistrera', [
            'type' => $type,
            'url' => $request->fullUrl(),
        ]);
    }

    public function store(User $user, string $type): View
    {
        $this->abortIfUnknown($type);

        NotificationPreference::updateOrCreate(
            ['user_id' => $user->id, 'type' => $type, 'channel' => NotificationDelivery::CHANNEL_EMAIL],
            ['enabled' => false, 'digest' => false],
        );

        App::setLocale($this->locales->forUser($user));

        return view('notis.avregistrerad');
    }

    private function abortIfUnknown(string $type): void
    {
        if (! in_array($type, $this->preferences->types(), true)) {
            abort(404);
        }
    }
}
