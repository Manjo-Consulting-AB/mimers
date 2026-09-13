<?php

/*
 * Gränssnittstexten, se issue 52 § Beslut 4 och 5. Det här är den enda
 * språkfilen som delas till frontenden (HandleInertiaRequests::share());
 * notiser.php och export.php är serverrenderat innehåll och når aldrig en
 * Vue-komponent.
 *
 * En toppnyckel per yta — nav, auth, form, flash, error, common — så att en
 * senare issue ser var dess strängar hör hemma utan att behöva fråga. Nyckeln
 * ligger kvar i den formen på klientsidan: t('nav.dashboard').
 */
return [
    'common' => [
        'brand' => 'Mimers',
        'tagline' => 'Pärmen för båten, husvagnen, huset och bilen.',
        'to_dashboard' => 'Till översikten',
        'home' => 'Till startsidan',
    ],

    'nav' => [
        'dashboard' => 'Översikt',
        'login' => 'Logga in',
    ],

    'auth' => [
        'login' => [
            'title' => 'Logga in',
            'heading' => 'Logga in',
            'submit' => 'Logga in',
        ],
    ],

    'form' => [
        'email' => 'E-post',
        'password' => 'Lösenord',
    ],

    'flash' => [
        'verification-link-sent' => 'Ett nytt verifieringsmejl har skickats.',
        'magic-link-sent' => 'Vi har skickat en inloggningslänk till din e-post.',
        'totp-confirmed' => 'Tvåfaktorsinloggning är påslagen.',
        'totp-disabled' => 'Tvåfaktorsinloggning är avstängd.',
        'session-expired' => 'Din session hann gå ut. Försök igen.',
    ],

    'error' => [
        'title' => 'Fel :status',
        '403' => 'Du har inte behörighet till den här sidan.',
        '404' => 'Sidan finns inte.',
        '429' => 'Du har gjort för många försök. Vänta en stund och försök igen.',
        '500' => 'Något gick fel hos oss. Försök igen om en stund.',
    ],
];
