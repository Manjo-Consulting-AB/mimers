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

        // Etiketten nämner båda med flit: LoginRequest provar samma
        // inskickade värde som engångskod och som återställningskod, och ger
        // samma fel oavsett vilket som misslyckades — se issue 53a § Beslut 4.
        'code' => [
            'label' => 'Engångskod eller återställningskod',
        ],

        'register' => [
            'title' => 'Skapa konto',
            'heading' => 'Skapa konto',
            'submit' => 'Skapa konto',
            'link' => 'Skapa ett konto',
            'password_hint' => 'Minst åtta tecken.',
            'login' => 'Har du redan ett konto? Logga in',
        ],

        'magic_link' => [
            'title' => 'Logga in med länk',
            'heading' => 'Logga in med länk',
            'link' => 'Logga in med en länk',
            'intro' => 'Vi skickar en inloggningslänk till din e-postadress. Länken går att använda en gång och gäller i en kvart.',
            'submit' => 'Skicka länken',
            'login' => 'Tillbaka till inloggningen',
        ],

        // Verifieringstexten renderas både i bannern på varje inloggad sida
        // och på /email/verify — se issue 53a § Beslut 7. Samma nycklar, en
        // komponent.
        'verify' => [
            'title' => 'Verifiera din e-postadress',
            'heading' => 'Verifiera din e-postadress',
            'banner' => 'Din e-postadress är inte verifierad än.',
            'body' => 'Vi skickar ett mejl med en verifieringslänk till din adress. Klicka på länken i mejlet för att bekräfta den.',
            'send' => 'Skicka verifieringsmejlet',
        ],

        'logout' => 'Logga ut',
    ],

    'form' => [
        'name' => 'Namn',
        'email' => 'E-post',
        'password' => 'Lösenord',
    ],

    'flash' => [
        'verification-link-sent' => 'Ett nytt verifieringsmejl har skickats.',
        // Aldrig "vi har skickat en länk till dig": MagicLinkRequestController
        // svarar likadant för en adress som inte finns, så vyn vet inte om
        // något mejl gick iväg — se issue 53a § Beslut 5.
        'magic-link-sent' => 'Om adressen finns hos oss har vi skickat en länk till den.',
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
