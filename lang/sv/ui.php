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
        // Pärmen är produktens ord för containern, se [[ADR-0002 Konto äger
        // container]] och Översikt. Länken kom med issue 54 § Beslut 7.
        'containers' => 'Pärmar',
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
        'profile-updated' => 'Profilen är sparad.',
        'account-updated' => 'Kontouppgifterna är sparade.',
        'container-created' => 'Pärmen är skapad.',
        'container-updated' => 'Pärmen är sparad.',
        'access-updated' => 'Åtkomsten är sparad.',
        'access-revoked' => 'Åtkomsten är återkallad.',
        'session-expired' => 'Din session hann gå ut. Försök igen.',
    ],

    'error' => [
        'title' => 'Fel :status',
        '403' => 'Du har inte behörighet till den här sidan.',
        '404' => 'Sidan finns inte.',
        '429' => 'Du har gjort för många försök. Vänta en stund och försök igen.',
        '500' => 'Något gick fel hos oss. Försök igen om en stund.',

        // Webbens översättning av API-felkoder, se issue 54 § Beslut 4 och
        // App\Support\Frontend\ApiErrorTranslator. Under `error` ligger en
        // gren per domän i koden — `quota.containers_exceeded` slås upp som
        // `error.quota.containers_exceeded`. `generic` är reserven: en kod
        // utan nyckel ska bli en begriplig mening, aldrig en rå kod på
        // skärmen.
        'generic' => 'Något gick fel. Försök igen om en stund.',

        'quota' => [
            'containers_exceeded' => 'Kontot har nått sitt tak för antal pärmar (:used av :limit).',
        ],

        // Issue 55a § Beslut 9: `PATCH` på en återkallad eller utgången rad
        // svarar `container_access.revoked` på `/api` och den här meningen i
        // webben. Samma kod, samma rad — se
        // App\Http\Controllers\ContainerAccessController::update().
        'container_access' => [
            'revoked' => 'Åtkomsten är återkallad eller har gått ut och går inte att ändra.',
        ],
    ],

    // Inställningarna, se issue 53b. `nav` är sidonavigationen, en nyckel per
    // post i resources/js/layouts/settingsSections.js — samma `key` där som
    // här. Issue 53c (Profil, Konto), 65 (Notiser) och 66 (Plan) lägger sina
    // nycklar i samma gren.
    'settings' => [
        'title' => 'Inställningar',

        'nav' => [
            'profile' => 'Profil',
            'accounts' => 'Konton',
            'security' => 'Säkerhet',
        ],

        // Språk- och enhetsnamnen till väljarna. Nycklarna är kolumnvärdena
        // (`sv_SE`, `metric`), inte katalognamnen `sv`/`en`: en kolumn som
        // heter `sv_SE` ska slås upp som `sv_SE`, se
        // App\Support\Notification\LocaleResolver.
        'locales' => [
            'sv_SE' => 'svenska',
            'en_GB' => 'engelska',
        ],

        'units' => [
            'metric' => 'metriskt',
            'imperial' => 'imperialt',
        ],

        // Profilen, se issue 53c. `*_follow` är förstavalet i varje väljare —
        // det postar `null`, som betyder "följ kontots inställning". Den
        // namngivna formen bär kontots gällande värde i parentesen och
        // används när användaren är medlem i exakt ett konto; `*_follow_plain`
        // är reserven när flera konton gör värdet oavgörbart.
        'profile' => [
            'title' => 'Profil',
            'heading' => 'Profil',

            'name' => 'Namn',

            // E-postadressen visas men ändras inte här (Beslut 3): bytet
            // kräver ett verifieringsflöde som ingen issue beskriver.
            'email' => 'E-post',
            'email_verified' => 'Adressen är verifierad.',
            'email_unverified' => 'Adressen är inte verifierad än.',
            'email_no_change' => 'E-postadressen kan inte ändras här.',

            'locale' => 'Språk',
            'locale_follow' => 'Följ kontots språk (:account)',
            'locale_follow_plain' => 'Följ kontots språk',

            'timezone' => 'Tidszon',
            'timezone_follow' => 'Följ kontots tidszon (:timezone)',
            'timezone_follow_plain' => 'Följ kontots tidszon',

            'unit_system' => 'Enhetssystem',
            'unit_follow' => 'Följ kontots enhetssystem (:unit)',
            'unit_follow_plain' => 'Följ kontots enhetssystem',

            'submit' => 'Spara',
        ],

        // Kontosidan, se issue 53c § Beslut 9. `role` är användarens egen roll
        // på kortet; `read_only` förklarar varför ett kort saknar formulär när
        // användaren har rollen för det men kontot är fryst.
        'accounts' => [
            'title' => 'Konton',
            'heading' => 'Konton',
            'intro' => 'Ett konto i taget. Ändringarna gäller alla som är med i kontot.',

            'roles' => [
                'owner' => 'Ägare',
                'admin' => 'Administratör',
                'member' => 'Medlem',
            ],

            'read_only' => 'Du kan se uppgifterna men inte ändra dem.',
            'empty' => 'Du är inte med i något konto.',

            'submit' => 'Spara',
        ],

        'security' => [
            'title' => 'Säkerhet',
            'heading' => 'Säkerhet',

            'totp' => [
                'heading' => 'Tvåfaktorsinloggning',
                'intro' => 'Tvåfaktorsinloggning kräver en engångskod från en autentiseringsapp varje gång du loggar in.',

                // Läge 2, se issue 53b § Beslut 4: ingen QR-kod, URI och
                // hemlighet som text. Varningen om att hemligheten bara visas
                // en gång står i recovery_once nedan, för kodarket.
                'enable' => 'Aktivera tvåfaktor',
                'setup_intro' => 'Skanna länken med din autentiseringsapp, eller skriv in hemligheten för hand. Bekräfta sedan med koden appen visar.',
                'uri_label' => 'Länk till autentiseringsappen',
                'secret_label' => 'Hemlighet att skriva in för hand',
                'copy' => 'Kopiera länken',
                'copied' => 'Länken är kopierad',
                'code_label' => 'Engångskod',
                'confirm' => 'Bekräfta och slå på',
                'confirmed_at' => 'Tvåfaktorsinloggning är på sedan :date.',

                // Varningarna står som text i formuläret, aldrig i en
                // confirm()-dialog, se issue 53b § Beslut 7. Båda kommer ur
                // brokerklassernas beslut: en omgenerering raderar hela arket
                // (RecoveryCodeBroker § Beslut 3), och en avstängning tar
                // koderna med sig (§ Beslut 5).
                'recovery_heading' => 'Återställningskoder',
                'recovery_remaining' => 'Koder kvar: :count',
                'recovery_warning' => 'Ett nytt ark gör alla tidigare koder obrukbara direkt.',
                'recovery_generate' => 'Generera nya koder',
                'recovery_once' => 'Koderna visas bara den här gången. Spara dem där du kommer åt dem utan appen.',

                'disable_heading' => 'Stäng av tvåfaktorsinloggning',
                'disable_warning' => 'När du stänger av raderas återställningskoderna. Slår du på igen får du ett nytt ark.',
                'disable_submit' => 'Stäng av tvåfaktorn',
            ],
        ],
    ],

    // Pärmen, se issue 54. `nav` är sidonavigationen, en nyckel per post i
    // resources/js/layouts/containerSections.js — samma `key` där som här.
    // 56a (kategorier och taggar), 57 (items), 62 (papperskorg) och 63
    // (scheman) lägger sina rader i samma lista och sina texter i samma gren.
    // 55a (delning) har en egen gren, `sharing` nedan, för sidan bär två
    // sektioner och en egen vokabulär — se issue 55a § Beslut 4 och 5.
    'container' => [
        // `kind` styr presentation och bara presentation (issue 54 § Beslut
        // 8, [[ADR-0002 Konto äger container]]). Nycklarna är kolumnvärdena
        // ur App\Models\Container::KINDS, aldrig påhittade egna namn — samma
        // regel som settings.locales.
        'kind' => [
            'boat' => 'Båt',
            'caravan' => 'Husvagn',
            'house' => 'Hus',
            'car' => 'Bil',
            'other' => 'Övrigt',
        ],

        'nav' => [
            'sharing' => 'Delning',
            'settings' => 'Inställningar',
        ],

        'index' => [
            'title' => 'Pärmar',
            'heading' => 'Pärmar',
            'create' => 'Ny pärm',
            'empty' => 'Du har inga pärmar än.',
            'shared' => 'Delad med dig',
            'active' => 'Aktiv',
            'make_active' => 'Gör aktiv',
            'edit' => 'Redigera',
        ],

        'create' => [
            'title' => 'Ny pärm',
            'heading' => 'Ny pärm',

            'name' => 'Namn',
            'kind' => 'Typ',
            'account' => 'Konto',
            'account_choose' => 'Välj konto',

            'submit' => 'Skapa',
        ],

        'edit' => [
            'title' => 'Inställningar',
            'heading' => 'Inställningar',

            'name' => 'Namn',
            'kind' => 'Typ',

            'submit' => 'Spara',
        ],
    ],

    // Delningssidan, se issue 55a. Sidan bär två sektioner med olika publik
    // (§ Beslut 3): deltagarna ser varje deltagare, åtkomsterna ser bara
    // ägarkontot. Texterna nedan följer samma uppdelning — `participants`
    // beskriver identiteter, `accesses` och `level` beskriver vad en åtkomst
    // ger.
    'sharing' => [
        'title' => 'Delning',
        'heading' => 'Delning',

        'participants' => [
            'heading' => 'Deltagare',
            'description' => 'Alla som har åtkomst till pärmen just nu. Ett konto räknas som en deltagare, aldrig som sina medlemmar.',
        ],

        // Rollen i deltagarlistan. Ägarkontot får `owner`, varje giltig
        // åtkomstrad sin `kind` — se App\Actions\Access\ListParticipants.
        // Etiketterna är kortare än `kind`-meningarna nedan: här är de en
        // kolumn i en lista, där en förklaring av vad formen betyder.
        'role' => [
            'owner' => 'Ägare',
            'member' => 'Medlem',
            'managed' => 'Organisation',
            'guest' => 'Gäst',
        ],

        'accesses' => [
            'heading' => 'Åtkomster',
            'description' => 'Allt som delats av pärmen, och historiken över det som återkallats eller gått ut.',

            // Ingen nivå får radera pärmen, hantera åtkomster eller initiera
            // ägarbyte. Meningen står EN gång på sidan och inte per rad, se
            // issue 55a § Beslut 4.
            'limits' => 'Ingen åtkomst ger rätt att radera pärmen, hantera åtkomster eller initiera ett ägarbyte. Det är alltid ägarkontots.',

            'level' => 'Nivå',
            'grantee' => 'Mottagare',
            'granted_by' => 'Beviljad av',
            'expires' => 'Går ut :date',

            'save' => 'Spara nivå',
            'revoke' => 'Återkalla',
        ],

        'history' => [
            'heading' => 'Historik',
            'revoked' => 'Återkallad :date',
            'expired' => 'Gick ut :date',
        ],

        // Omfånget: `reach` kommer färdigt ur ContainerAccessResource och
        // räknas aldrig om i vyn (issue 55a § Beslut 6). En containerbred rad
        // bär inget `reach` alls — talet är `null` där med flit.
        'scope' => [
            'container' => 'Hela pärmen',
            'item' => ':item når :reach items',
        ],

        // `kind` presenteras med sin konsekvens och går inte att ändra — den
        // är `prohibited` i UpdateContainerAccessRequest (issue 55a
        // § Beslut 5). Nycklarna är kolumnvärdena ur `container_access.kind`.
        'kind' => [
            'member' => 'En person — sambon eller delägaren.',
            'managed' => 'En organisation med servicerelation, till exempel ett varv. Den äger inte pärmen, och det den skapar tillskrivs organisationen.',
            'guest' => 'Tillfällig åtkomst med ett utgångsdatum.',
        ],

        // De fyra nivåerna, en etikett och en beskrivning var, formulerade ur
        // regel 3 i [[Konton och åtkomst]] § Behörighetsregler. Nycklarna är
        // kolumnvärdena ur AccessLevel::LADDER — samma lista som väljaren
        // får som prop, så en nivå som saknar text syns som sin nyckel.
        //
        // Bara `read` och `write` visas som vanliga val; `create` och
        // `delete` ligger bakom "Avancerat" (§ Beslut 4).
        'level' => [
            'read' => [
                'label' => 'Läsa',
                'description' => 'Läser. Rör ingenting.',
            ],
            'create' => [
                'label' => 'Lägga till',
                'description' => 'Lägger till bilagor, kostnader, scheman och nya underliggande items — men rör aldrig något som redan finns.',
            ],
            'write' => [
                'label' => 'Ändra',
                'description' => 'Ändrar därtill det som redan står i pärmen.',
            ],
            'delete' => [
                'label' => 'Radera',
                'description' => 'Mjukraderar och återställer ur papperskorgen.',
            ],
        ],

        'advanced' => 'Avancerat',

        // Ett `read_only`-ägarkonto får återkalla men inte ändra nivå —
        // regel 4 undantar återkallandet uttryckligen. Vyn skriver ut det i
        // stället för att låta användaren upptäcka det som ett 403
        // (issue 55a § Beslut 9).
        'frozen' => 'Kontot är fryst och kan inte ändra nivåer. Att återkalla en åtkomst går fortfarande.',
    ],
];
