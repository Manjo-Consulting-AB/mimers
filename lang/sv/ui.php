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
        'invitation-sent' => 'Inbjudan är skickad.',
        'invitation-revoked' => 'Inbjudan är tillbakadragen.',
        'invitation-accepted' => 'Inbjudan är accepterad. Pärmen ligger under Pärmar.',
        'invitation-rejected' => 'Inbjudan är avvisad.',
        'category-created' => 'Kategorin är skapad.',
        'category-updated' => 'Kategorin är sparad.',
        'category-deleted' => 'Kategorin är raderad.',
        'category-preset-applied' => 'Kategorierna är tillagda. Ändra dem precis som du vill.',
        'tag-created' => 'Taggen är skapad.',
        'tag-updated' => 'Taggen är sparad.',
        'tag-deleted' => 'Taggen är raderad.',
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
            // Issue 55b § Beslut 6: de två gränserna ett inbjudningsformulär
            // kan slå i. Delningstaket räknar mottagare och obesvarade
            // inbjudningar, kontotaket räknar utskicken (issue 27 § Beslut 5,
            // issue 48). Båda hamnar under `errors.quota` på webben.
            'shared_users_exceeded' => 'Delningen har nått kontots tak (:used av :limit).',
            'pending_invitations_exceeded' => 'Kontot har nått sitt tak för utestående inbjudningar (:used av :limit).',
        ],

        // Issue 55a § Beslut 9: `PATCH` på en återkallad eller utgången rad
        // svarar `container_access.revoked` på `/api` och den här meningen i
        // webben. Samma kod, samma rad — se
        // App\Http\Controllers\ContainerAccessController::update().
        'container_access' => [
            'revoked' => 'Åtkomsten är återkallad eller har gått ut och går inte att ändra.',
        ],

        // Issue 55b: inbjudningarnas felkoder. `already_pending` hamnar på
        // fältet `email` och `not_pending` på formulärnyckeln `invitation`;
        // resten blir det besked vyn visar för det tillståndet. Ingen av dem
        // får någonsin bli en rå JSON-kropp i en webbläsare — samma regel som
        // issue 54 § Beslut 4.
        'invitation' => [
            'already_pending' => 'Den här adressen har redan en inbjudan som väntar på svar.',
            'not_pending' => 'Inbjudan är redan besvarad och går inte att dra tillbaka.',
            'expired' => 'Inbjudan har gått ut.',
            'email_mismatch' => 'Inbjudan gäller en annan e-postadress än den du är inloggad med.',
            'email_not_verified' => 'Verifiera din e-postadress först, och försök sedan igen.',
        ],

        // Kategorins domänfel, se issue 56a § Beslut 4. De tre första gäller en
        // FLYTT och hamnar på fältet `parent`; de två sista gäller en RADERING
        // och hamnar på formulärnyckeln `category`, som sidan ritar som en ruta
        // över trädet (App\Http\Controllers\CategoryController).
        //
        // Talen kommer ur `ApiException::data()` — `max_depth`, `children`,
        // `items` — och meddelandet SKA använda dem. Ett meddelande utan talet
        // är sämre än felkoden det ersatte.
        'category' => [
            'max_depth_exceeded' => 'En kategori får ligga på högst :max_depth nivåer.',
            'cycle' => 'En kategori kan inte flyttas in i sig själv eller in i en av sina egna underkategorier.',
            'parent_not_in_container' => 'Den valda överordnade kategorin ligger inte i den här pärmen.',
            'has_children' => 'Kategorin har :children underkategorier och kan inte raderas.',
            'has_items' => 'Kategorin har :items items och kan inte raderas.',
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
            'categories' => 'Kategorier',
            'tags' => 'Taggar',
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

        // Kategoriträdet, se issue 56a § Beslut 1, 2, 3 och 4. `description`
        // är den ena halvan av ADR-0004:s skillnad — taggsidan bär den andra,
        // och en vy som visar två likadana listor river det beslutet.
        'categories' => [
            'title' => 'Kategorier',
            'heading' => 'Kategorier',
            'description' => 'Var sakerna hör hemma. Ett item ligger i högst en kategori, och kategorierna bildar ett träd på högst fem nivåer.',

            'name' => 'Namn',
            'parent' => 'Överordnad kategori',
            'parent_root' => '— ingen, lägg i roten —',
            'position' => 'Position',

            'create_heading' => 'Ny kategori',
            'create' => 'Skapa',
            'save' => 'Spara',
            'destroy' => 'Radera',
            'empty' => 'Inga kategorier än.',

            // Förslaget på en tom pärm, se issue 56b § Beslut 4. Orden i
            // själva uppsättningen finns INTE här och ska aldrig hit: de bor i
            // resources/js/data/categoryPresets.js, per språk och typ.
            // `preset_not_empty` är rutten svar på en pärm som redan har
            // kategorier och hamnar på formulärnyckeln `categories` — en
            // mening och inte en API-felkod, för rutten finns bara på webben.
            'preset_heading' => 'Färdig uppsättning',
            'preset_description' => 'Vi kan fylla pärmen med ett färdigt förslag på kategorier. Du kan döpa om, flytta och radera dem precis som vanliga kategorier efteråt.',
            'preset_apply' => 'Lägg till uppsättningen',
            'preset_dismiss' => 'Nej tack',
            'preset_not_empty' => 'Pärmen har redan kategorier. En uppsättning går bara att lägga i en tom pärm.',
        ],

        // Tagglistan, se issue 56a § Beslut 6 och 8. `description` är den
        // andra halvan av ADR-0004:s skillnad. `item_count` bär träffräknaren,
        // som är per omfång och aldrig per pärm.
        'tags' => [
            'title' => 'Taggar',
            'heading' => 'Taggar',
            'description' => 'Allt annat man vill kunna filtrera på. En tagg är platt, ligger utanpå kategorin och ett item kan bära hur många som helst.',

            'name' => 'Namn',
            'color' => 'Färg',
            'color_placeholder' => '#rrggbb',
            // Färgen är valfri, och `null` är ett svar — ingen standardfärg
            // väljs åt användaren (Beslut 8).
            'no_color' => 'Ingen färg',
            'item_count' => 'Sitter på :count items',

            'create_heading' => 'Ny tagg',
            'create' => 'Skapa',
            'save' => 'Spara',
            'destroy' => 'Radera',
            'empty' => 'Inga taggar än.',
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

            // Mottagaren och beviljaren visas med NAMN, aldrig med sin ULID —
            // uppslagen skickas som egna propar och formuleras i
            // resources/js/components/accessPresentation.js. Varken `User`
            // eller `Account` använder SoftDeletes, så en rad kan faktiskt
            // vara borta: då blir det den här meningen och inte ULID:en.
            // Ingen e-postadress någonsin ([[Konton och åtkomst]]
            // § Behörighetsregler, sista stycket).
            'grantee_unknown' => 'Borttagen mottagare',
            'granted_by_unknown' => 'Borttagen användare',

            // Utgångsfältet renderas bara på en rad som REDAN har ett datum,
            // och meningen nedan säger varför det inte går att ta bort
            // (arkitektsvaret § 3): en gäst utan utgång motsäger Beslut 5, och
            // vägen från gäst till permanent går genom `kind`, som är
            // `prohibited` med flit.
            'expires_at' => 'Giltig till',
            'expires_fixed' => 'Utgången kan flyttas framåt men inte tas bort. En gäst som ska bli permanent återkallas och bjuds in på nytt som medlem.',

            'save' => 'Spara nivå',
            'revoke' => 'Återkalla',
        ],

        // Den tredje sektionen, se issue 55b § Beslut 5. Listan visar adressen
        // — det är hela skillnaden mot åtkomstlistan ovan, och den är
        // avsändarens egen lista över vad hon själv skickat. Grinden är
        // densamma (`viewAccesses()`), och `invitations` är `null` för den som
        // inte får se den.
        'invitations' => [
            'heading' => 'Inbjudningar',
            'description' => 'Adresser som bjudits in men ännu inte svarat. En inbjudan ger ingen åtkomst förrän den accepterats.',

            'email' => 'E-post',
            // Omfånget i formuläret. Ett enskilt item är den enda ytan i M10
            // där en itemavgränsad delning kan skapas, se [[ADR-0028 Åtkomst
            // på itemnivå]] § Beslut ("`invitation` speglar omfånget").
            'item' => 'Omfång',
            'item_container' => 'Hela pärmen',

            'submit' => 'Bjud in',
            'revoke' => 'Dra tillbaka',
            'expires' => 'Går ut :date',
            'invited_by' => 'Inbjuden av',
            'empty' => 'Inga inbjudningar än.',

            // Statusen kommer ur App\Http\Resources\InvitationResource och
            // läses aldrig ur kolumnen: en `pending`-rad som passerat sitt
            // `expires_at` redovisas som `expired` utan att raden ändras
            // (issue 10a § Beslut 7). Nycklarna är kolumnvärdena ur
            // App\Models\Invitation::STATUSES.
            'status' => [
                'pending' => 'Väntar på svar',
                'expired' => 'Utgången',
                'accepted' => 'Accepterad',
                'rejected' => 'Avvisad',
                'revoked' => 'Tillbakadragen',
            ],
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

    // Mejlets landningssida, se issue 55b § Beslut 2, 3 och 4.
    // App\Http\Controllers\InvitationResponseController renderar exakt ett av
    // fem tillstånd, och texterna nedan är det ena ledet i att hålla dem
    // åtskilda. `mismatch` och `unavailable` är de två som måste vara
    // FORMULERADE olika men INFORMERA lika lite: `unavailable` säger aldrig om
    // tokenet finns, och `mismatch` avslöjar aldrig vilken adress inbjudan
    // gäller.
    'invitation' => [
        'title' => 'Inbjudan',
        'heading' => 'Inbjudan',

        // Pärmens namn och inbjudarens namn visas också för en gäst. Det är
        // ingen ny uppgift: InvitationNotification skriver ut pärmens namn i
        // både ämnesrad och brödtext, och den som har länken har fått mejlet.
        // Adressen inbjudan gäller visas däremot aldrig.
        'intro' => ':inviter har bjudit in dig till pärmen :container.',
        'level' => 'Nivå: :level',

        // Gästen har ingen adress att jämföra med och därför inget formulär
        // att svara i — vägen går via inloggning eller registrering, och
        // tokenet ligger kvar i sessionen till dess.
        'guest' => 'Logga in eller skapa ett konto med adressen inbjudan gäller. Sedan kan du svara.',

        'mismatch' => 'Den här inbjudan gäller en annan e-postadress än den du är inloggad med.',
        'unavailable' => 'Den här inbjudan går inte längre att använda.',

        'accept' => 'Acceptera',
        'reject' => 'Avvisa',
        'home' => 'Till startsidan',
    ],
];
