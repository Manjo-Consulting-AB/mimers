<?php

namespace App\Support\Contact;

/**
 * Kontaktformulärets tio kategorier — M89 ·
 * [[ADR-0096 Kontaktformuläret]] § 2.
 *
 * **Listan finns på ett ställe.** Valideringen
 * (App\Http\Requests\ContactRequest), sidan
 * (App\Http\Controllers\ContactController::create()) och mejlet
 * (App\Mail\ContactMessageMail) läser nycklarna härifrån. Etiketterna bor i
 * `lang/en/ui.php` under `contact.categories.<nyckel>`, så en ny kategori är
 * en rad i listan och en rad i katalogen — inte en tredje sanning i vyn.
 *
 * Ordningen är den tabellen i ADR-0096 anger, och den är avsiktlig: sidan
 * ritar kategorierna i den ordning listan står, så en omkastning här ändrar
 * formulärets ordning.
 */
final class ContactCategory
{
    /**
     * Nycklarna i den ordning formuläret visar dem.
     *
     * @var list<string>
     */
    public const KEYS = [
        'general',
        'technical',
        'account',
        'billing',
        'privacy',
        'features',
        'sharing',
        'agents',
        'business',
        'other',
    ];

    /**
     * Listan som sidan får som prop.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return self::KEYS;
    }

    /** Etiketten ur `lang/`, i appens aktuella språk. */
    public static function label(string $key): string
    {
        return (string) trans('ui.contact.categories.'.$key);
    }
}
