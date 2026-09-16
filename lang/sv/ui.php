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

        // Issue 65a § Beslut 6. Två koder och inte en: sidan har två
        // formulär, och "sparat" utan att säga vad hade varit sant men inte
        // svarat på vad som hände.
        'notification-preferences-updated' => 'Notisinställningarna är sparade.',
        'quiet-hours-updated' => 'Tysta timmar är sparade.',
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
        'item-created' => 'Itemet är skapat.',
        'item-updated' => 'Itemet är sparat.',
        'item-deleted' => 'Itemet ligger i papperskorgen. Det går att återställa i 30 dagar.',
        'item-link-created' => 'Relationen är skapad.',
        // Upp-knytningen tar bort kopplingen och ingenting annat — raden i
        // länken är hård (issue 14 § Beslut 10), men båda itemen finns kvar.
        'item-link-removed' => 'Kopplingen är borta. Båda itemen finns kvar.',

        // Issue 60 § Beslut 9. Bilagan är MJUK-raderad — `deleted_at` sätts
        // och bytena ligger kvar tills papperskorgen gallrar dem (ADR-0008) —
        // så texten säger papperskorgen och de 30 dagarna, aldrig "raderad".
        'attachment-uploaded' => 'Bilagan är uppladdad.',
        'attachment-deleted' => 'Bilagan ligger i papperskorgen. Det går att återställa den i 30 dagar.',

        // Issue 67a § Beslut 1. Tre koder och inte en: de tre knapparna gör tre
        // olika saker, och "sparat" utan att säga vad hade varit sant men inte
        // svarat på vad som hände. `loan-deleted` säger att RADEN är borta och
        // aldrig att prylen är tillbaka — den är mjukraderad och hamnar inte i
        // papperskorgen (issue 76 § Beslut 3), så texten lovar ingen
        // återställning.
        'loan-created' => 'Utlåningen är registrerad.',
        'loan-updated' => 'Utlåningen är sparad.',
        'loan-deleted' => 'Utlåningsraden är borta.',

        // Issue 62a § Beslut 7. EN kod för alla fyra typerna: återställningen
        // tar `type` i kroppen och delar en lista, så vyn har ingen anledning
        // att veta vilken av dem som just kom tillbaka — meningen säger
        // innehåll och inte item (samma skäl som issue 20a § Beslut 1).
        'trash-restored' => 'Innehållet är återställt.',

        // Issue 62b § Beslut 5 och 6. Raderingen landar på pärmlistan — och
        // meningen pekar på papperskorgen, där länken ligger alldeles under.
        // Återställningen säger att pärmen är tillbaka; den blir INTE aktiv av
        // sig själv, för att välja pärm är användarens handling.
        'container-trashed' => 'Pärmen ligger i papperskorgen.',
        'container-restored' => 'Pärmen är återställd.',

        // Issue 63a § Beslut 6 och 8. Pausen är samma skrivning som en ändrad
        // titel och får därför sin egen mening: "sparat" hade varit sant men
        // inte svarat på vad som hände. `schedule-deleted` nämner varken
        // papperskorgen eller 30 dagar — schemat går inte att återställa ur
        // en vy (issue 20a § Beslut 3), och en återställning som inte finns
        // får inte utlovas i en flash.
        'schedule-created' => 'Schemat är skapat.',
        'schedule-updated' => 'Schemat är sparat.',
        'schedule-paused' => 'Schemat är pausat. Det öppnar inga nya förekomster förrän du återupptar det.',
        'schedule-resumed' => 'Schemat är aktivt igen.',
        'schedule-deleted' => 'Schemat är borttaget.',

        // Issue 63b § Beslut 5 och 8. Avbockningen och överhoppningen får
        // var sin mening: de stänger samma rad men säger olika saker om
        // jobbet, och en gemensam "förekomsten är stängd" hade gjort loggen
        // omöjlig att läsa. Den nya förekomsten nämns inte i någon av dem —
        // sidan ritas om ur serverns svar och visar det nya datumet själv.
        'occurrence-completed' => 'Uppgiften är avbockad.',
        'occurrence-skipped' => 'Uppgiften är överhoppad och sparad som det — inte som utförd.',

        // Issue 63c § Beslut 7. `removed` säger att ingenting annat försvann:
        // raden är hård (issue 23 § Beslut 7) och det som går förlorat är
        // kopplingen — både schemana och både förekomsterna finns kvar, precis
        // som `item-link-removed` säger det för relationerna.
        'schedule-dependency-created' => 'Beroendet är tillagt.',
        'schedule-dependency-removed' => 'Beroendet är borttaget. Båda schemana finns kvar.',
        'occurrence-dependency-created' => 'Undantaget är tillagt.',
        'occurrence-dependency-removed' => 'Undantaget är borttaget. Både schemana och båda förekomsterna finns kvar.',

        // Issue 65b § Beslut 2, 3 och 4. Skapandet av en feed och en endpoint
        // har ingen egen kod: där är det hemligheten själv som är beskedet, och
        // en "skapat"-rad ovanför den hade bara upprepat den. Återkallandet och
        // webhookens två skrivningar får däremot var sin mening — den ena säger
        // att länken är död, den andra att raden sparades.
        'calendar-feed-revoked' => 'Kalenderlänken är återkallad. Den slutar uppdateras i kalendern.',
        'webhook-updated' => 'Webhooken är sparad.',
        'webhook-destroyed' => 'Webhooken är borttagen. Hemligheten som hörde till är det också.',

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

            // Issue 60 § Beslut 5: uppladdningens två gränser på webben.
            // :limit_bytes, :used_bytes och :file_bytes formateras till
            // läsbara tal av App\Support\Frontend\ApiErrorTranslator (Beslut
            // 6) — en gräns som "5368709120" är ingen gräns någon förstår.
            // Meningarna SKA använda talen: ett meddelande som slänger bort
            // `data` är sämre än felkoden det ersatte.
            'max_file_size_exceeded' => 'Filen är för stor. Den får vara högst :limit_bytes, och den här är :file_bytes.',
            'storage_exceeded' => 'Lagringen är full. Kontot har :used_bytes av :limit_bytes, och filen är :file_bytes.',
        ],

        // Issue 55a § Beslut 9: `PATCH` på en återkallad eller utgången rad
        // svarar `container_access.revoked` på `/api` och den här meningen i
        // webben. Samma kod, samma rad — se
        // App\Http\Controllers\ContainerAccessController::update().
        'container_access' => [
            'revoked' => 'Åtkomsten är återkallad eller har gått ut och går inte att ändra.',
        ],

        // Issue 62a § Beslut 7: `RestoreContent` kastar `trash.parent_deleted`
        // när föräldern fortfarande ligger i papperskorgen — en bilaga vars
        // item är raderat, eller en underkategori vars förälder är det
        // ([[ADR-0008 Soft delete och papperskorg]]). På webben blir koden
        // den här meningen i en ruta ovanför listan, aldrig en JSON-kropp.
        'trash' => [
            'parent_deleted' => 'Det går inte att återställa: det som innehållet hör till ligger fortfarande i papperskorgen. Återställ det först.',
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

        // Relationsformulärets fyra domänfel, se issue 58 § Beslut 6. De
        // kommer som App\Exceptions\Api\ApiException ur
        // App\Actions\Item\LinkItems och blir fältfel i
        // App\Http\Controllers\ItemLinkController — aldrig en rå JSON-kropp
        // mitt i en sida.
        //
        // `self`, `cross_container` och `pair_exists` hör till fältet `item`
        // (vilket item som valdes), `cycle` till `relation` (vilken riktning
        // som valdes). `pair_exists` bär den befintliga relationen i
        // `data.relation` och meningen SKA säga den — orden nedan är koden
        // översatt till ett adjektiv, och ett meddelande som slänger bort
        // `data` är sämre än felkoden det ersatte.
        'item_link' => [
            'self' => 'Ett item kan inte kopplas till sig självt.',
            'cross_container' => 'Relationer går bara mellan items i samma pärm.',
            'pair_exists' => 'De två är redan kopplade: motparten är :relation.',
            'relation_word' => [
                'parent' => 'överordnad',
                'child' => 'underordnad',
                'sibling' => 'syskon',
            ],
            'cycle' => 'Riktningen skulle göra en cirkel: det här itemet är redan överordnat motparten, direkt eller genom andra items.',
        ],

        // Utlåningens domänfel på webben, se issue 67a § Beslut 6 och issue 76
        // § Beslut 4. Koden kommer ur App\Exceptions\Api\ApiException och
        // `data.loan` bär den blockerande radens ULID — den behålls för
        // klienten men meningen är användarens.
        'loan' => [
            'already_open' => 'Itemet är redan utlånat. Registrera återlämningen först.',
        ],

        // Avslutsflödets domänfel på webben, se issue 63b § Beslut 6. De
        // kommer som App\Exceptions\Api\ApiException ur
        // App\Actions\Schedule\CloseOccurrence och blir ett formulärfel på
        // nyckeln `occurrence` i
        // App\Http\Controllers\ScheduleOccurrenceController — aldrig en rå
        // JSON-kropp mitt i en sida.
        //
        // **`blocked` är två nycklar och inte en.** `data.blocked_by` är en
        // LISTA av `{ulid, title, due_at}`, och ApiErrorTranslator skickar
        // `data` rakt in i `trans()` som ersättningar — en array som
        // ersättning är i bästa fall en varning och i sämsta ett undantag
        // mitt i felhanteringen. Kontrollern formulerar därför den ledande
        // meningen själv och fogar en `blocked_row` per blockerare efter
        // den, med titel och datum.
        //
        // **`not_open` säger varför utan att säga hur.** Koden bär
        // `data.status` (`completed` | `skipped`) för en API-klient, men för
        // användaren är svaret detsamma hur förekomsten stängdes: den är
        // redan avslutad. Att skriva ut råvärdet hade gett "redan completed".
        //
        // **`schedule.inactive` pekar på pausen.** Spärren i CloseOccurrence
        // (`! $schedule->is_active`) betyder "den här uppgiften gäller inte
        // just nu", och pausen är reversibel och synlig — därför en mening
        // som säger vad användaren kan göra åt saken, inte bara att det
        // nekades.
        'occurrence' => [
            'blocked' => 'Uppgiften kan inte bockas av än — de här är inte klara:',
            'blocked_row' => '• :title — förfaller :date',
            'not_open' => 'Förekomsten är redan avslutad och går inte att bocka av igen.',

            // Issue 63c § Beslut 6: förekomstberoendets tre egna felkoder, ur
            // App\Actions\Schedule\DependOccurrence. Alla hamnar på fältet
            // `depends_on` — varje regel handlar om vilken förekomst som
            // valdes — och aldrig som en rå JSON-kropp mitt i en sida.
            //
            // **Cykelmeningen använder `data`.** Koden bär `data.occurrence`
            // och `data.depends_on`, kanten som försöktes; meningen namnger
            // dem med sina schematitlar i stället för med ULID:er, för
            // "vilken kedja" är obegripligt som två strängar ur en databas.
            // Ett meddelande som slänger bort `data` är sämre än felkoden det
            // ersatte (issue 58 § Beslut 6, 56a § Beslut 4).
            'dependency_self' => 'En förekomst kan inte vänta på sig själv.',
            'dependency_cycle' => 'Riktningen skulle göra en cirkel: ":schedule" väntar redan på ":depends_on", direkt eller genom mellanled.',
            'dependency_not_in_container' => 'Beroenden går bara mellan förekomster i samma pärm.',
        ],

        'schedule' => [
            'inactive' => 'Schemat är pausat, och en avbockning skulle öppna en ny förekomst på ett schema ingen vill ha förekomster på. Återuppta schemat först.',

            // Issue 63c § Beslut 6: schemaberoendets tre egna felkoder, ur
            // App\Actions\Schedule\DependSchedule. Samma form och samma fält
            // som förekomstens ovan.
            'dependency_self' => 'Ett schema kan inte vänta på sig självt.',
            'dependency_cycle' => 'Riktningen skulle göra en cirkel: ":schedule" väntar redan på ":depends_on", direkt eller genom mellanled.',
            'dependency_not_in_container' => 'Beroenden går bara mellan scheman i samma pärm.',
        ],

        // Issue 65b § Beslut 5 · funktionsgrinden. `Entitlements::assertFeature()`
        // kastar `plan.feature_unavailable` med funktionens namn i
        // `data.feature` — en KOD (`webhooks`), precis som `item_link.pair_exists`
        // bär en — och App\Http\Controllers\WebhookEndpointController
        // översätter den i två steg: först till ett namn, sedan in i meningen.
        // Meningen är densamma på sidan och i fältfelet, för den formuleras en
        // gång och skickas både som prop och som fel.
        //
        // Planen namnges därför att webhooks är Pro-funktionen: servern svarar
        // vilken funktion som saknas, inte vilken plan som gäller, och "Pro" är
        // den enda planen som har den (migrationen som sår planerna). Får en
        // andra plan funktionen, eller en andra funktion en webbyta, är det
        // här meningen ska bli per funktion.
        'plan' => [
            // Meningen står utan länk med flit, också nu när planvyn finns (66a).
            // Strängen levereras även i API:ets felhölje, och markup i en
            // översättningssträng blir antingen escapad text hos API-klienten eller
            // `v-html` i vyn. Vill någon länka till planen är det en egen nyckel och
            // en egen länk bredvid felrutan i Webhooks.vue, inte en `<a>` här inne.
            'feature_unavailable' => ':feature kräver planen Pro.',
            'feature_name' => [
                'webhooks' => 'Webhooks',
            ],
        ],

        // Issue 65b § Beslut 7 · URL:ens SSRF-svar på webben.
        // `UrlSafetyValidator` svarar `webhook.unsafe_url` med en ORSAK i
        // `data.reason` — också den en kod (`reserved_ip`, `invalid_scheme`,
        // …) — och kontrollern översätter den i två steg på samma sätt som
        // ovan. Felet hamnar på fältet `url`: det handlar om vad användaren
        // skrev, och serverns mening är den användaren möter. Sidan gör ingen
        // egen kontroll av privata intervall, `localhost` eller
        // metadatatjänster.
        'webhook' => [
            'unsafe_url' => 'Adressen går inte att använda: :reason.',

            'unsafe_reason' => [
                'unparseable_url' => 'den går inte att tolka som en adress',
                'invalid_scheme' => 'den måste börja med https://',
                'userinfo' => 'den får inte bära användarnamn eller lösenord',
                'invalid_port' => 'porten är inte tillåten',
                'reserved_hostname' => 'den pekar på servern själv',
                'dns_lookup_failed' => 'adressen går inte att slå upp',
                'reserved_ip' => 'den pekar på ett internt nät',
            ],
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
            // Issue 66a § Beslut 1: plansidan ligger efter kontona — båda
            // handlar om KONTOT, och planen är svaret på vad det får.
            'plan' => 'Plan',
            // Issue 66b § Beslut 1: lagringsytan ligger direkt efter planen —
            // den är nedgraderingens steg 2 och svaret på planens egen
            // uppmaning, och plansidan länkar hit.
            'storage' => 'Lagring',
            'notifications' => 'Notiser',
            // Issue 65b § Beslut 1: webhookarna hör till KONTOT och ligger
            // därför bland inställningarna, efter notiserna — båda handlar om
            // vad som lämnar systemet, men den ena om personen och den andra
            // om kontot.
            'webhooks' => 'Webhooks',
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

    // Notiserna, se issue 65a. Sidan svarar på "när och hur vill jag bli
    // störd?" — vilka typer hon vill ha, direkt eller i
    // veckosammanfattningen, och när hon inte vill bli störd.
    //
    // Typnycklarna är kolumnvärdena i `notification.type` (`task.due`),
    // och de ligger NÄSTLADE under `type` med flit: nyckeln
    // `notifications.type.<typ>.label` är den form issuen föreskriver, och
    // punkten i typnamnet är samma punkt som skiljer leden i en
    // översättningsnyckel. En rad som hette `schedule_occurrence_due` vore
    // en rad ingen ställer in (Beslut 7).
    'notifications' => [
        'title' => 'Notiser',
        'heading' => 'Notiser',
        'intro' => 'Välj vad du vill få mejl om, och när du inte vill bli störd.',

        'types_heading' => 'Vad du vill få mejl om',

        // Förvalet OCH skälet till det (Beslut 3). Här är den enda plats
        // säsongen förklaras för användaren, och den är skälet att valet
        // går att förstå i stället för bara att göra.
        'digest_default' => 'Veckosammanfattning är standard för uppgiftspåminnelser. I april förfaller allting samtidigt, och tjugo separata mejl på en förmiddag är svårare att läsa än ett samlat.',

        // Märkningen på en typ användaren aldrig rört. Värdet kommer ur
        // `is_default` i serverns svar (31b § Beslut 2) och räknas aldrig
        // om i vyn.
        'default_badge' => 'Standard',

        'type' => [
            'task' => [
                'due' => [
                    'label' => 'Uppgift förfaller',
                    'description' => 'Dagen en schemalagd uppgift ska göras.',
                ],
                'overdue' => [
                    'label' => 'Uppgift är försenad',
                    'description' => 'När en uppgift har passerat sitt datum utan att bli avbockad.',
                ],
            ],
            'loan' => [
                'due' => [
                    'label' => 'Utlåning ska tillbaka',
                    'description' => 'När ett item du lånat ut närmar sig återlämningsdagen.',
                ],
            ],
            'quota' => [
                'warning' => [
                    'label' => 'Lagringsutrymmet börjar ta slut',
                    'description' => 'När en kvot i planen närmar sig sin gräns.',
                ],
            ],
            'invitation' => [
                'received' => [
                    'label' => 'Inbjudan till en pärm',
                    'description' => 'När någon bjuder in dig till en pärm.',
                ],
            ],
            'transfer' => [
                'requested' => [
                    'label' => 'Någon vill ta över ett konto',
                    'description' => 'När en begäran om ägarbyte väntar på dig.',
                ],
            ],
            'account' => [
                'inactive' => [
                    'label' => 'Kontot stängs av inaktivitet',
                    'description' => 'Innan ett konto du är med i stängs för att det inte har använts.',
                ],
            ],
        ],

        // De tre lägena, se Beslut 2. Beskrivningen säger vad läget gör
        // för användaren, inte vad kolumnerna heter.
        'mode' => [
            'direct' => [
                'label' => 'Direkt',
                'description' => 'Ett mejl när det händer.',
            ],
            'digest' => [
                'label' => 'I veckosammanfattningen',
                'description' => 'Samlat i ett mejl i veckan.',
            ],
            'never' => [
                'label' => 'Aldrig',
                'description' => 'Inget mejl för den här sorten.',
            ],
        ],

        'submit' => 'Spara',

        'quiet_hours' => [
            'heading' => 'Tysta timmar',
            'intro' => 'Under de här timmarna skickas inga mejl.',

            'start' => 'Från',
            'end' => 'Till',

            // Tomt är ett svar, inte ett saknat värde (Beslut 4).
            'empty_note' => 'Tomma fält betyder inga tysta timmar. Fyll i båda om du vill ha ett fönster.',
            // Midnatt är avsikten, inte ett fel (Beslut 4).
            'midnight_note' => 'Fönstret får gå över midnatt: 22:00 till 07:00 betyder kväll till morgon.',

            // Fördröjningen, inte borttagningen (Beslut 5).
            'delays_note' => 'En notis som infaller i det tysta fönstret kommer efteråt i stället — den försvinner inte.',

            // Tidszonen visas här och ändras på profilen (Beslut 4).
            'timezone' => 'Timmarna gäller i tidszonen :timezone.',
            'timezone_follows_account' => 'Timmarna gäller i kontots tidszon.',
            'timezone_link' => 'Ändra tidszonen på profilen.',

            'submit' => 'Spara tysta timmar',
        ],
    ],

    // Pärmens kalenderlänk, se issue 65b § Beslut 2 och 4 och
    // resources/js/pages/Containers/CalendarFeed.vue.
    //
    // Adressen är i praktiken ett lösenord till pärmens uppgifter
    // ([[Notiser]] § ICS-kalenderfeed), och texterna säger det på två ställen:
    // `url_once` vid visningen och `revoke_confirm` vid återkallandet. Den som
    // tappat bort länken har inget att hämta — svaret är att återkalla och
    // skapa en ny.
    'calendar' => [
        'title' => 'Kalender',
        'heading' => 'Kalender',
        'intro' => 'Prenumerera på pärmens uppgifter i kalendern du redan använder. Länken är personlig och visar bara det du själv får se.',

        // Texten till SecretOnce. `url_description` säger vad adressen är till
        // för, `url_once` att den inte går att se igen — båda behövs, och de
        // formuleras här och inte i komponenten.
        'url_label' => 'Kalenderadressen',
        'url_description' => 'Lägg in adressen i din kalenderapp. Den hämtar uppgifterna själv och håller sig uppdaterad.',
        'url_once' => 'Det här är enda gången adressen visas. Tappar du bort den återkallar du länken och skapar en ny.',

        'copy' => 'Kopiera adressen',
        'copied' => 'Adressen är kopierad',

        'create' => 'Skapa en kalenderlänk',

        'list_heading' => 'Dina länkar till den här pärmen',
        'empty' => 'Du har inga kalenderlänkar till den här pärmen än.',

        'revoke' => 'Återkalla',
        'revoke_confirm' => 'Återkalla kalenderlänken? Kalendern slutar uppdateras, och adressen går inte att få tillbaka.',

        // En återkallad rad ligger KVAR i listan (Beslut 4) och säger när
        // länken dog — den som undrar varför kalendern slutade uppdateras ska
        // se svaret i stället för en tom lista.
        'row' => [
            'created' => 'Skapad :date',
            'last_fetched' => 'Senast hämtad :date',
            'never_fetched' => 'Inte hämtad än',
            'revoked_badge' => 'Återkallad',
            'revoked_note' => 'Länken återkallades :date och kalendern uppdateras inte längre.',
        ],
    ],

    // Kontots webhooks, se issue 65b § Beslut 1, 3, 5, 6, 7 och 8 och
    // resources/js/pages/Settings/Webhooks.vue.
    //
    // Två hemligheter i samma form: `secret_once` motsvarar kalenderns
    // `url_once` och av samma skäl. `secret_description` förklarar vad
    // hemligheten används TILL — HMAC-SHA256 över kroppen — för en hemlighet
    // utan förklaring är en sträng man klistrar in någonstans och glömmer.
    'webhook' => [
        'title' => 'Webhooks',
        'heading' => 'Webhooks',
        'intro' => 'Skicka händelser till dina egna system. Varje leverans signeras, så mottagaren kan kontrollera att den kommer från oss.',

        'account_label' => 'Konto',

        'secret_label' => 'Hemligheten',
        'secret_description' => 'Verifiera signaturen med den: HMAC-SHA256 över kroppen, med hemligheten som nyckel. Utan den går våra leveranser inte att skilja från någon annans.',
        'secret_once' => 'Det här är enda gången hemligheten visas. Tappar du bort den tar du bort webhooken och skapar en ny.',

        'copy' => 'Kopiera hemligheten',
        'copied' => 'Hemligheten är kopierad',

        'create_heading' => 'Ny webhook',

        'url_label' => 'Adress',
        // Sidan gör ingen egen kontroll av privata intervall, `localhost`
        // eller metadatatjänster (Beslut 7) — men den som skriver en adress
        // ska veta vad som gäller innan servern svarar.
        'url_hint' => 'En publik https-adress. Adresser på det egna nätet, på servern själv eller utan https avvisas.',

        'event_types_label' => 'Händelser',

        // Namn och en rads förklaring per typ, som notistyperna i 65a § Beslut
        // 7. Nycklarna följer typnamnet i App\Models\WebhookEndpoint::
        // EVENT_TYPES (`task.due` blir `event_type.task.due`), så en ny typ
        // lägger sin text här och ingenstans annars.
        'event_type' => [
            'task' => [
                'due' => [
                    'label' => 'Uppgift förfaller',
                    'description' => 'När en schemalagd uppgift blir synlig eller förfaller.',
                ],
                'overdue' => [
                    'label' => 'Uppgift är försenad',
                    'description' => 'När en uppgift passerat sitt datum utan att bockas av.',
                ],
            ],
            'loan' => [
                'due' => [
                    'label' => 'Utlåning ska tillbaka',
                    'description' => 'När ett utlånat item närmar sig återlämningsdagen.',
                ],
            ],
            'quota' => [
                'warning' => [
                    'label' => 'Lagringsutrymmet börjar ta slut',
                    'description' => 'När en kvot i planen passerar 80 eller 100 procent.',
                ],
            ],
            'invitation' => [
                'received' => [
                    'label' => 'Inbjudan till en pärm',
                    'description' => 'När någon bjuder in en medlem till kontot.',
                ],
            ],
            'transfer' => [
                'requested' => [
                    'label' => 'Ägarbyte begärt',
                    'description' => 'När någon vill ta över ett konto.',
                ],
            ],
            'account' => [
                'inactive' => [
                    'label' => 'Kontot stängs av inaktivitet',
                    'description' => 'Innan ett konto stängs för att det inte har använts.',
                ],
            ],
        ],

        'create' => 'Skapa webhook',

        // Redigeringsläget (Beslut 3): SAMMA formulär som skapandet, men
        // PATCH i stället för POST och utan hemlighet — den som byter adress
        // eller lägger till en händelsetyp ska inte behöva rotera hemligheten,
        // för då måste mottagarsidan konfigureras om av ett skäl som inte är
        // hemlighetens. `edit` är radens knapp, `save` formulärets och
        // `cancel` vägen tillbaka utan att något skrivs.
        'edit' => 'Redigera',
        'save' => 'Spara',
        'cancel' => 'Avbryt',

        'list_heading' => 'Kontots webhooks',
        'empty' => 'Kontot har inga webhooks än.',

        'inactive_badge' => 'Avstängd',

        // Skillnaden är hela poängen med flaggan ur serverns svar (Beslut 8):
        // en rad som bara såg avstängd ut hade sett ut som om användaren själv
        // gjort det. Systemmeningen säger också att räknaren börjar om, för
        // det är vad återaktiveringen gör på servern.
        'disabled_by_system' => 'Systemet stängde av den efter upprepade leveransfel. Slå på den igen när adressen fungerar — då börjar räkningen om från noll.',
        'disabled_by_user' => 'Du har stängt av den. Slå på den igen när du vill ha leveranserna tillbaka.',

        'deactivate' => 'Stäng av',
        'activate' => 'Slå på',

        'destroy' => 'Ta bort',
        'destroy_confirm' => 'Ta bort webhooken? Hemligheten försvinner med den, och en ny webhook får en ny hemlighet.',
    ],

    // Pärmen, se issue 54. `nav` är sidonavigationen, en nyckel per post i
    // resources/js/layouts/containerSections.js — samma `key` där som här.
    // 56a (kategorier och taggar), 57 (items) och 63 (scheman) lägger sina
    // rader i samma lista och sina texter i samma gren. 55a (delning) har en
    // egen gren, `sharing` nedan, för sidan bär två sektioner och en egen
    // vokabulär — se issue 55a § Beslut 4 och 5. 62a (papperskorgen) har en
    // egen TOPPNIVÅgren, `trash`, av samma skäl.
    // Plansidan, se issue 66a § Beslut 4–9 och [[Planer och kvoter]].
    //
    // **Egen gren på toppnivå**, som `trash` och `sharing`: plansidan är sin
    // egen yta med sin egen vokabulär, och nycklarna ligger under `plan.*` och
    // ingen annanstans (Beslut 9). Ingen sträng i en .vue-fil — texten
    // formuleras på servern ur den här filen, och klienten slår bara upp den
    // ([[ADR-0021 Frontendteknik]]).
    'plan' => [
        'title' => 'Plan och förbrukning',
        'heading' => 'Plan och förbrukning',
        'intro' => 'Vad kontot har, hur mycket av varje gräns som är förbrukat, och vad en nedgradering skulle innebära.',

        'account_label' => 'Konto',
        'name_label' => 'Plan',
        'price_label' => 'Pris',
        'current_heading' => 'Aktuell plan',

        // Plannamnen per `code` (Beslut 9). Nycklarna är kolumnvärdet i
        // `plan.code`, aldrig plannamnet ur databasen: en översättning per kod
        // ger svaret på svenska, och en plan vars kod saknas syns som
        // `plan.names.…` i stället för att tyst bli engelsk.
        'names' => [
            'free' => 'Free',
            'pro' => 'Pro',
            'broker' => 'Broker',
            'yard' => 'Yard',
            'charter' => 'Charter',
        ],

        // Gratisplanens pris är noll, och "0 EUR" är rätt tal och fel mening.
        'price_free' => 'Kostnadsfritt',
        'period' => [
            'year' => 'per år',
            'month' => 'per månad',
        ],

        // Kontots status står överst och inte nedgrävd (Beslut 5). Nycklarna är
        // värdena i `account.read_only_reason` — en kod, precis som
        // `container.kind` — så en ny orsak är en ny rad här och ingen `if` i
        // vyn. Ett `active` konto har ingen orsak och får ingen ruta.
        //
        // Dagarna formulerar sidan med 62a:s två pluralnycklar
        // (`trash.expires.day` och `trash.expires.days`), se Plan.vue: samma
        // mening om samma sak, och `t()` har ingen pluralisering (issue 52
        // § Beslut 4).
        'status' => [
            'payment_failed' => 'Kontot är fryst: en betalning har uteblivit.',
            'over_quota' => 'Kontot är fryst: det ligger över gränsen för sin plan.',
            'inactivity' => 'Kontot är fryst: det har inte använts på länge.',
            'grace' => 'Fristen går ut:',
        ],

        'usage_heading' => 'Förbrukning och gränser',

        // De fyra numeriska gränserna (Beslut 4). Nycklarna är nycklarna i
        // `plan.limits`, aldrig påhittade egna namn.
        'limits' => [
            'containers' => 'Pärmar',
            'storage_bytes' => 'Lagringsutrymme',
            'max_file_bytes' => 'Största filstorlek',
            'shared_users_per_container' => 'Delade användare per pärm',
        ],

        // `:used` och `:limit` är färdigformaterade av servern — bytena med
        // Number::fileSize(), samma formatering som kvotfelmeningarna i 60a.
        'of' => ':used av :limit',
        'of_unlimited' => ':used av obegränsat',
        'per_container' => ':limit per pärm',

        // `null` är obegränsat och skrivs som ett ord, aldrig som noll och
        // aldrig som en full stapel (Beslut 4).
        'unlimited' => 'Obegränsat',
        'storage_bar' => ':percent procent av taket använt',

        // Funktionstabellen: en rad per nyckel i ReadPlanUsage::FEATURES
        // (Beslut 9).
        'features_heading' => 'Funktioner',
        'features' => [
            'webhooks' => 'Webhooks',
            'pdf_binder' => 'PDF-pärm',
            'ownership_transfer' => 'Ägarbyte',
            'loan_reminders' => 'Utlåningspåminnelser',
            'cost_reports' => 'Kostnadsrapporter',
        ],
        'included' => 'Ingår',
        'not_included' => 'Ingår inte',

        // Nedgraderingens fem steg, ordagrant ur [[Planer och kvoter]]
        // § Nedgradering (Beslut 6) — och det som INTE händer, som är det
        // viktigaste på hela sidan: items raderas aldrig, kostnadsrader
        // raderas aldrig.
        'downgrade' => [
            'heading' => 'Om du nedgraderar',
            'intro' => 'Items raderas aldrig — bara bilagor. Så går det till:',

            'steps' => [
                'freeze' => 'Betalningen uteblir och kontot blir fryst. Ingenting raderas.',
                'choose' => 'Du får dina bilagor listade och väljer själv vad som ska bort — du vet vilka fyrtio semesterbilder som kan gå och vilken besiktningsrapport som inte kan det.',
                'grace' => 'Du har tre månader på dig att betala eller exportera.',
                'purge' => 'Händer inget raderas bilagorna automatiskt, nyast först, tills kontot ryms i Free.',
                'restore' => 'Kontot återgår till aktivt på gratisnivån.',
            ],

            'kept' => [
                'items' => 'Dina items raderas aldrig.',
                'costs' => 'Kostnadsrader raderas aldrig. Kvittona kan försvinna med bilagorna — siffrorna står kvar.',
            ],

            // Förhandsvisningen (Beslut 7). Konkret när kontot ligger över
            // gratisgränsen, och "allt ryms" utan siffror om radering när det
            // inte gör det. `remove_one`/`remove_many` är två nycklar av samma
            // skäl som `trash.expires.day`/`days`: `t()` pluraliserar inte.
            'preview' => [
                'over' => 'Kontot ligger :over över :free.',
                'remove_one' => 'En bilaga skulle tas bort, nyast först.',
                'remove_many' => ':count bilagor skulle tas bort, nyast först.',
                'fits' => 'Allt ryms i Free.',
                'cleanup_link' => 'Välj själv vad som ska bort',
            ],
        ],
    ],

    // Lagringsytan, se issue 66b § Beslut 1–9 och [[Planer och kvoter]]
    // § Nedgradering. **Egen gren på toppnivå**, som `plan` och `trash`:
    // städningen är nedgraderingens steg 2, och nycklarna ligger under
    // `storage.*` och ingen annanstans. Ingen sträng i en .vue-fil.
    //
    // Två nycklar för samma mening där talet böjs (`preview.one`/`many`,
    // `confirm.one`/`many`, `result.one`/`many`/`none`): `t()` har ingen
    // pluralisering (issue 52 § Beslut 4), så talet väljer nyckel. `none` är
    // ett eget fall och inte en nolla i en pluralform — en rensning där alla
    // valda rader redan hunnit raderas är ingen rensning, och meningen ska
    // säga det i stället för att räkna upp noll filer.
    //
    // Meningsbyggnaden är hämtad ur dokumentets egen: "Hon vet vilka fyrtio
    // semesterbilder som kan gå och vilken besiktningsrapport som inte kan
    // det." Texten pekar på papperskorgen och de 30 dagarna (62a) och säger
    // aldrig "raderas permanent" — bilagorna mjukraderas ([[ADR-0008 Soft
    // delete och papperskorg]]).
    'storage' => [
        'title' => 'Lagring',
        'heading' => 'Lagring',
        'intro' => 'Välj själv vad som ska bort. Bilagorna flyttas till papperskorgen och kan återställas där i 30 dagar.',

        'account_label' => 'Konto',
        'usage_heading' => 'Lagringsutrymme',

        'list_heading' => 'Bilagor',
        'list_intro' => 'Störst först. Kryssa för det som kan gå — de fyrtio semesterbilderna kan det, besiktningsrapporten kan det inte.',
        'empty' => 'Kontot har inga bilagor.',

        // Pärm och item per rad, i den ordningen: sammanhanget är det som gör
        // valet möjligt. Skiljetecknet ligger i meningen och inte i mallen.
        'row' => [
            'location' => ':container — :item',
            // En bilaga vars item eller pärm ligger i papperskorgen räknas
            // fortfarande mot kontot och ska synas (Beslut 3).
            'trashed' => 'Pärmen eller itemet ligger i papperskorgen. Bilagan räknas ändå mot kontot.',
        ],

        // Urvalets förhandsvisning (Beslut 4): räknad i klienten ur
        // `byte_size` på de valda raderna. Det är ett urval och inte
        // förbrukningen — förbrukningen efter en rensning kommer ur serverns
        // svar.
        'preview' => [
            'one' => 'En bilaga vald: :freed frigörs och :remaining återstår.',
            'many' => ':count bilagor valda: :freed frigörs och :remaining återstår.',
        ],

        // Taket på 100 ULID:er per anrop (Beslut 5). Meningen säger både
        // taket och vad hon har valt, så hon vet hur mycket som ska bort.
        'limit_exceeded' => 'Du kan rensa högst :max bilagor i taget, och du har valt :count.',

        // Bekräftelsen (Beslut 6): antalet filer, det frigjorda utrymmet,
        // papperskorgen och de 30 dagarna.
        'confirm' => [
            'one' => 'En bilaga flyttas till papperskorgen och kan återställas där i 30 dagar. :freed frigörs nu. Vill du fortsätta?',
            'many' => ':count bilagor flyttas till papperskorgen och kan återställas där i 30 dagar. :freed frigörs nu. Vill du fortsätta?',
        ],

        'submit' => 'Flytta till papperskorgen',

        // Svaret efter en rensning (Beslut 8). `usage` bär serverns
        // förbrukning EFTER rensningen, formaterad med Number::fileSize() —
        // aldrig klientens subtraktion.
        'result' => [
            'one' => 'En bilaga ligger i papperskorgen och kan återställas där i 30 dagar.',
            'many' => ':count bilagor ligger i papperskorgen och kan återställas där i 30 dagar.',
            'none' => 'Inga bilagor togs bort — de var redan borta.',
            'usage' => 'Förbrukningen är nu :used.',
        ],
    ],

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
            // `items` ligger först, som raden i containerSections.js: itemen är
            // pärmen, kategorierna och taggarna är hur den är ordnad.
            'items' => 'Items',
            'categories' => 'Kategorier',
            'tags' => 'Taggar',
            'sharing' => 'Delning',
            'settings' => 'Inställningar',
            // Issue 65b § Beslut 1: kalenderlänken är en UTGÅNG ur produkten —
            // pärmens uppgifter prenumererade på ur någon annans kalender — och
            // ligger efter inställningarna, före papperskorgen. Raden står på
            // samma plats i containerSections.js.
            'calendar' => 'Kalender',
            // Sist, som raden i containerSections.js — papperskorgen är dit
            // man går när något gått fel (issue 62a § Beslut 1).
            'trash' => 'Papperskorgen',
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

        // Raderingen, se issue 62b § Beslut 4 och 5. `confirm` bär pärmens
        // namn: en bekräftelse som inte säger vad som försvinner är en
        // bekräftelse man klickar bort. Den säger att allt följer med, att
        // pärmen ligger kvar i papperskorgen i 30 dagar och att den går att
        // återställa därifrån — och ALDRIG "raderas permanent", för
        // raderingen är mjuk (issue 8) och det ordet vore osant.
        'destroy' => [
            'action' => 'Radera pärmen',
            'confirm' => ':name och allt i den flyttas till papperskorgen. Där ligger den kvar i 30 dagar och går att återställa. Vill du fortsätta?',
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

    // Itemsidorna, se issue 57a § Beslut 10 och issue 57b § Beslut 9. Samma
    // indelning som `container`: `index` är listan, `show` är detaljvyn,
    // `create`/`edit`/`destroy` är skrivytorna och `form` är fältetiketterna de
    // två formulären delar. Miniatyrerna är issue 61.
    'item' => [
        // Listan är pärmens förstasida. `empty` säger att PÄRMEN är tom och
        // aldrig att den kanske är det: en omfångsbegränsad mottagare ser bara
        // det hon når, och ett "inga träffar bland N" hade avslöjat hur många
        // rader som filtrerats bort (issue 73 § Beslut 6).
        //
        // Filterraden bor i resources/js/components/ItemFilterBar.vue och
        // meningen i den tomma träfflistan i pages/Containers/Items/Index.vue,
        // se issue 59a § Beslut 4, 5, 6 och 8. `filter_empty` räknar upp det
        // användaren SJÄLV satt och ingenting annat: inget tal om hur många
        // rader omfånget höll borta, ingen antydan om att svaret skulle vara
        // ofullständigt. En omfångsbegränsad mottagare får därför samma mening
        // som ägaren.
        //
        // Etiketterna i `filter_label_*` byggs i
        // resources/js/components/itemFilter.js och används på två ställen:
        // chipsen ovanför listan och meningen ovan. Taggarna har en egen form
        // för uppräkningen (`filter_label_tags`), så "taggarna Motor,
        // Impeller" står i stället för "taggen Motor, taggen Impeller".
        'index' => [
            'title' => 'Items',
            'heading' => 'Items',
            'create' => 'Nytt item',
            'empty' => 'Pärmen är tom.',

            'filter_heading' => 'Filtrera',
            'filter_q' => 'Sökord',
            'filter_tags' => 'Taggar',
            'filter_category' => 'Kategori',
            'filter_category_all' => '— alla kategorier —',
            'filter_submit' => 'Filtrera',
            'filter_active' => 'Aktiva filter',
            'filter_clear' => 'Rensa alla',
            'filter_remove' => 'Ta bort :filter',

            // Ett värde i länken som inte längre finns i mottagarens omfång —
            // en raderad tagg, en kategori flyttad till en annan pärm. Raden är
            // hela svaret: ingen 422, ingen redirect tillbaka till samma
            // querysträng (Beslut 3).
            'filter_dropped' => 'Ett filter i länken finns inte längre och har tagits bort.',

            // Läget "filter, inga rader". Utan filter säger `empty` att pärmen
            // är tom i stället.
            'filter_empty' => 'Inga träffar med de här filtren: :filters.',

            'filter_label_q' => 'sökordet ”:value”',
            'filter_label_tag' => 'taggen :name',
            'filter_label_tags' => 'taggarna :names',
            'filter_label_category' => 'kategorin :name',
        ],

        // Detaljvyns fältetiketter. Bara itemets EGNA fält, kategorin och
        // taggarna — relationer, bilagor, scheman, kostnader och utlåning är
        // issue 58, 60, 63, 45–47 och 67 och har inga nycklar här.
        'show' => [
            'description' => 'Beskrivning',
            'manufacturer' => 'Tillverkare',
            'model' => 'Modell',
            'serial_number' => 'Serienummer',
            'purchased_at' => 'Inköpsdatum',
            'warranty_until' => 'Garanti till',
            'position_note' => 'Placering',
            'category' => 'Kategori',
            'tags' => 'Taggar',
        ],

        // Formulärets fältetiketter, se issue 57b § Beslut 9. Produktens ord
        // och inte kolumnnamnen: *Inköpt* och *Garanti till och med* är vad
        // fältet frågar efter, till skillnad från detaljvyns sammanfattande
        // *Inköpsdatum* och *Garanti till*. Därför egna nycklar och inte
        // `show.*` återanvända — två ytor med olika uppgift får två ord.
        'form' => [
            'name' => 'Namn',
            'description' => 'Beskrivning',
            'manufacturer' => 'Tillverkare',
            'model' => 'Modell',
            'serial_number' => 'Serienummer',
            'purchased_at' => 'Inköpt',
            'warranty_until' => 'Garanti till och med',
            'position_note' => 'Var den finns',

            // Ett item ligger i HÖGST en kategori ([[ADR-0004 Fria taggar och
            // kategorier]]). Raden överst i väljaren är ett val och inte ett
            // tomt fält.
            'category' => 'Kategori',
            'category_none' => '— ingen kategori —',
            'categories_empty' => 'Pärmen har inga kategorier än.',
            'categories_empty_link' => 'Skapa kategorier',

            // Taggarna är kryssrutor, en per tagg i pärmen. En ny tagg skapas
            // på taggsidan och inte här: en väg till samma skrivning på två
            // ställen är två regler att hålla i takt (Beslut 5).
            'tags' => 'Taggar',
            'tags_empty' => 'Pärmen har inga taggar än.',
            'tags_empty_link' => 'Skapa taggar',

            // Kontot posten tillskrivs — varvet, inte den anställde. Bara vid
            // skapandet: vem som skapade raden är historik (Beslut 4).
            'account' => 'Konto',

            // Föräldern, när barn-itemet skapas ur detaljvyns länk (issue 58
            // § Beslut 7). En rad text och inte en väljare: länken har redan
            // besvarat frågan, och `parent` skickas bara i skapandeläget.
            'parent' => 'Skapas under: :name',
        ],

        'create' => [
            'title' => 'Nytt item',
            'heading' => 'Nytt item',
            'submit' => 'Skapa',
        ],

        // `action` är länken på detaljvyn; `title`/`heading`/`submit` är sidan
        // och formuläret.
        'edit' => [
            'action' => 'Redigera',
            'title' => 'Redigera item',
            'heading' => 'Redigera item',
            'submit' => 'Spara',
        ],

        // Raderingen är MJUK ([[ADR-0008 Soft delete och papperskorg]]), och
        // `confirm` säger det: papperskorgen och de 30 dagarna, aldrig
        // "raderas permanent", vilket vore osant (Beslut 8).
        'destroy' => [
            'action' => 'Radera',
            'confirm' => 'Itemet hamnar i papperskorgen och går att återställa i 30 dagar. Vill du fortsätta?',
        ],

        // Relationssektionen, se issue 58 § Beslut 3, 4, 8, 9 och 10.
        // Sektionen bor i resources/js/components/ItemLinkSection.vue.
        //
        // `group` är de tre rubrikerna och `relation` etiketterna i
        // riktningsväljaren — båda sedda från MOTPARTENS sida (Beslut 4),
        // vilket är samma håll som listan från servern läser. Motparten utanför
        // omfånget har ingen nyckel: den ritas inte alls (Beslut 3), och en
        // rad som beskriver något dolt vore själva läckaget.
        'links' => [
            'heading' => 'Relationer',
            'description' => 'Vad det här itemet hör till, och vad som hör till det.',

            'group' => [
                'parent' => 'Överordnade',
                'child' => 'Underordnade',
                'sibling' => 'Syskon',
            ],

            'empty' => 'Itemet är inte kopplat till något.',
            'remove' => 'Knyt upp',
            // Raderingen av en länk är hård (issue 14 § Beslut 10) och har
            // ingen papperskorg — det som försvinner är kopplingen, aldrig
            // itemen.
            'remove_confirm' => 'Bara kopplingen tas bort. Båda itemen finns kvar. Vill du fortsätta?',

            // Vägen till barn-itemet (Beslut 7).
            'create_child' => [
                'action' => 'Nytt item under det här',
            ],

            'form_heading' => 'Knyt ihop med ett annat item',
            'counterpart' => 'Item',
            'counterpart_none' => '— välj item —',
            'no_counterparts' => 'Det finns inga andra items att koppla till.',

            'relation' => [
                'label' => 'Motparten är',
                'none' => '— välj riktning —',
                'parent' => 'Överordnat item',
                'child' => 'Underordnat item',
                'sibling' => 'Syskon',
            ],

            // Beslut 8: vad en riktning gör med delningen, i en mening.
            // Ingen beräkning — ingen fråga om vilka grants som finns och
            // ingen räknare. Den siffran hör till delningsvyn (55a), och en
            // andra sanning om omfånget är en sanning som kan glida isär.
            'relation_note' => 'Den som delar ett överordnat item når även dess underordnade — syskon delar ingenting.',

            'submit' => 'Knyt ihop',
        ],

        // Bilagesektionen på detaljvyn, se issue 60. Sektionen bor i
        // resources/js/components/ItemAttachmentSection.vue: den bär sitt
        // eget formulär och sina egna fel, precis som ItemLinkSection gör för
        // relationerna, så ett kvotfel på en fil inte färgar resten av sidan.
        //
        // Filens `kind` (`image` | `document` | `other`) är ett domänvärde och
        // inte text — orden nedan är dess tre värden, och samma nycklar finns
        // i AttachmentResource för `/api`.
        //
        // `billing_note` säger VILKET konto som betalar innan filen väljs:
        // kvoten räknas på det uppladdande kontot och inte på pärmens ägare
        // ([[Filer och lagring]] § attachment, AGENTS.md § Sådant som är lätt
        // att göra fel), och den som laddar upp ska veta vad den kostar.
        //
        // `destroy_confirm` säger papperskorgen och de 30 dagarna. Raderingen
        // är mjuk (Beslut 7), och "raderas permanent" vore osant.
        'attachment' => [
            'heading' => 'Bilagor',
            'empty' => 'Itemet har inga bilagor.',

            'kind' => [
                'image' => 'Bild',
                'document' => 'Dokument',
                'other' => 'Övrigt',
            ],

            'download' => 'Ladda ner',
            'destroy' => 'Ta bort',
            'destroy_confirm' => 'Bilagan flyttas till papperskorgen och går att återställa i 30 dagar. Vill du fortsätta?',

            // Issue 61b § Beslut 7: visningens fyra strängar. `alt` står inte
            // här — det är filnamnet och kommer ur datan.
            //
            // `file_icon` är etiketten på den neutrala filikon som ritas i
            // stället för en miniatyr (Beslut 1): en bilaga utan derivat, som
            // en nyss uppladdad bild eller en PDF, får aldrig bli en trasig
            // bild. Den säger vad läsaren ser, inte vad filen är — filnamnet
            // står bredvid.
            //
            // `pdf_fallback` står under PDF-ramen (Beslut 4). Vyn kan inte
            // veta om webbläsaren har en egen läsare, så meningen är där hela
            // tiden och pekar på nedladdningslänken som varje rad har ändå
            // (Beslut 5).
            'viewer_heading' => 'Bildvisaren',
            'viewer_close' => 'Stäng',
            'file_icon' => 'Filen visas som ikon',
            'pdf_fallback' => 'Går PDF:en inte att visa? Ladda ner den i stället.',

            'upload_heading' => 'Ladda upp filer',
            'billing_note' => 'Lagringen räknas mot kontot nedan, inte mot pärmens ägare.',
            'account' => 'Kontot som betalar',
            'file' => 'Filer',

            // Issue 60b § Beslut 3: dropzonen är ett tillägg och filväljaren
            // bredvid är den väg som fungerar med tangentbord, skärmläsare och
            // på en telefon. Texten säger var filerna hamnar, inte hur.
            'dropzone' => 'Släpp filerna här',

            // Köns fyra tillstånd (Beslut 2 och 8). `failed` är en rad med ett
            // fel under sig och inte en fil som försvunnit: fil 3 och 4 laddas
            // upp ändå (Beslut 4).
            'status' => [
                'waiting' => 'Väntar',
                'uploading' => 'Laddar upp',
                'done' => 'Klar',
                'failed' => 'Misslyckades',
            ],

            // Sammanfattningen efter kön (Beslut 4). Talen är två, aldrig ett:
            // "3 av 4" säger både hur många som kom fram och hur många som
            // försöktes, och en rad som bara säger 3 döljer de misslyckade.
            //
            // Formen är "Uppladdade filer: 3 av 4" och inte "3 av 4 filer
            // laddades upp": substantivet och verbet böjs efter antalet, och
            // translate.js har ingen pluralisering med flit (issue 52 § Beslut
            // 4). Den bokstavliga meningen hade sagt "1 av 1 filer laddades
            // upp" — och en fil i taget är det vanligaste flödet, det 60a
            // byggde. Etiketten med talen är rätt i varje antal.
            'summary' => 'Uppladdade filer: :uploaded av :total.',

            // Takgränsen (Beslut 7). `throttle:uploads` svarar en
            // omdirigering med inloggningens mening på fältet `email` (issue
            // 53a § Beslut 6, bootstrap/app.php), och den meningen vore en
            // lögn om en uppladdning — kön har sin egen.
            'throttled' => 'För många uppladdningar. Vänta en stund och fortsätt.',

            // En avbruten uppladdning (60 § Klart när, Beslut 6). Meningen
            // lovar inte att filen INTE kom fram: anropet kan ha nått servern
            // innan länken bröts, och det är listan som vet. Därför "kontrollera
            // listan" och inte "försök igen" ensamt.
            'interrupted' => 'Uppkopplingen bröts. Kontrollera listan och försök igen.',

            // Stänger en misslyckad rad (Beslut 4). Den ligger i vyn tills
            // användaren stänger den; valet att försöka igen är att välja
            // filen på nytt.
            'dismiss' => 'Stäng',

            'submit' => 'Ladda upp',
        ],

        // Utlåningssektionen på detaljvyn, se issue 67a § Beslut 2–8. Sektionen
        // bor i resources/js/components/ItemLoanSection.vue: den bär sitt eget
        // formulär och sina egna fel, precis som ItemLinkSection gör för
        // relationerna och ItemAttachmentSection för bilagorna, så ett fältfel
        // på ett datum inte färgar resten av sidan.
        //
        // `borrowed_by`, `lent_at`, `due_at` och `returned_at` är hela
        // meningar byggda ur ett datum och ett namn — mallen sätter ihop dem,
        // och datumet är redan formaterat av formatDateOnly() (Beslut 3, 5).
        'loan' => [
            'heading' => 'Utlåning',
            'description' => 'Vem som har prylen, och när den ska tillbaka.',

            // Den öppna utlåningen står överst och historiken under
            // (Beslut 2). `returned_at IS NULL` är den öppna, och vilken rad
            // det är kommer färdigräknad från servern.
            'not_lent' => 'Itemet är inte utlånat.',

            'borrowed_by' => 'Lånad av :name',
            'lent_at' => 'Utlånad :date',
            'due_at' => 'Ska tillbaka :date',
            'no_due_at' => 'Ingen återlämningsdag satt',

            // Försenad är härledd på serverns datum (Beslut 5). Vyn jämför
            // aldrig `due_at` mot sin egen klocka — den läser flaggan
            // `openLoanOverdue` ur svaret.
            'overdue' => 'Försenad',

            // Adressen är en kontaktuppgift och aldrig en mottagaradress
            // (Beslut 4, [[ADR-0017 Missbruksvektorer]] § 7). Den visas som
            // text med möjlighet att kopiera, och `email_note` säger varför
            // fältet finns: systemet mejlar aldrig låntagaren, påminnelsen går
            // till den som lånat ut. Ingen `mailto:`-länk, ingen
            // påminnelseknapp och ingen delning — texten är hela svaret på
            // varför adressen står där.
            'contact' => 'Kontaktuppgift',
            'copy' => 'Kopiera adressen',
            'copied' => 'Adressen är kopierad',
            'email_note' => 'Adressen används aldrig för utskick. Systemet mejlar inte låntagaren — påminnelsen går till dig.',

            // Återlämning är en knapp och inte ett datumfält man måste förstå
            // (Beslut 3). Knappen sätter dagens datum — serverns, ur propen
            // `today` — och det egna datumet finns i formuläret bredvid.
            // `after_or_equal:lent_at` gäller båda vägarna, så ett datum före
            // utlåningen blir ett fältfel.
            'return_today' => 'Tillbaka idag',
            'return_date' => 'Återlämningsdatum',
            'return_submit' => 'Registrera',

            'history_heading' => 'Tidigare utlåningar',
            'returned_at' => 'Tillbaka :date',

            // Att ta bort raden är INTE att återlämna (Beslut 7). Den ena
            // suddar en felaktig registrering, den andra registrerar att
            // prylen kommit tillbaka — och `destroy_confirm` säger båda, så de
            // två knapparna inte går att förväxla. Raderingen är mjuk och
            // raden hamnar inte i papperskorgen (issue 76 § Beslut 3), så
            // texten lovar ingen återställning.
            'destroy' => 'Ta bort raden',
            'destroy_confirm' => 'Raden tas bort. Det här är inte en återlämning — prylen är fortfarande utlånad, och återlämningen registreras med den andra knappen. Vill du fortsätta?',

            // Formuläret. `lent_at` är förvalt till dagens datum (Beslut 3),
            // och de fyra datumen är `<input type="date">`: webbläsaren skickar
            // `Y-m-d`, exakt vad `date`-regeln i den delade FormRequesten tar
            // emot — ingen egen datumtolkning i JavaScript.
            'form_heading' => 'Låna ut',
            'form_name' => 'Lånad av',
            'form_email' => 'E-postadress',
            'form_lent_at' => 'Utlånad',
            'form_due_at' => 'Ska tillbaka',
            'form_returned_at' => 'Återlämnad',
            'form_note' => 'Anteckning',
            'form_submit' => 'Låna ut',
        ],

        // Schemat som regel — sektionen på detaljvyn och de två
        // formulärsidorna, se issue 63a § Beslut 2–8. Sektionen bor i
        // resources/js/components/ScheduleListSection.vue och formuläret i
        // ScheduleForm.vue; meningarna kommer ur den här filen och aldrig ur
        // en sträng i JavaScript.
        //
        // **Återkommandet är en mening och tre kolumner** (Beslut 2).
        // `recurrence_type` + `interval_unit` + `interval_count` betyder "Var
        // tolfte månad, räknat från senast utfört", och vyn skriver aldrig
        // kolumnvärdena. `t()` har ingen pluralisering (issue 52 § Beslut 4),
        // så varje enhet har TVÅ nycklar — en för `1` och en för `:count` —
        // och resources/js/components/schedulePresentation.js väljer på
        // talet, samma regel som 62a:s dagar i trashPresentation.js.
        //
        // Skillnaden mellan `fixed` och `interval` ligger i sista ledet:
        // kalendern mot senast utfört ([[Scheman och uppgifter]] § De två
        // återkommandetyperna). Den är hela poängen med två typer.
        //
        // **Skillnaden förklaras med exempel i formuläret, inte med ordet**
        // (Beslut 3). Meningarna i `form.recurrence_*` är [[ADR-0005 Schema
        // och förekomst]]:s egna exempel: ett val mellan tre ord utan
        // förklaring blir ett val någon gör fel en gång och sedan aldrig
        // ändrar.
        'schedule' => [
            'heading' => 'Scheman',
            'empty' => 'Itemet har inga scheman.',
            'add' => 'Nytt schema',
            'back' => 'Tillbaka till itemet',

            // Nästa förfall är den ÖPPNA förekomstens datum (Beslut 1). Ett
            // schema utan öppen förekomst — ett pausat, eller en
            // engångsuppgift som redan är klar — säger det i stället för att
            // visa ett tomt fält.
            'next_due' => 'Nästa förfall: :date',
            'no_next_due' => 'Ingen öppen förekomst.',

            'edit' => 'Redigera',
            'pause' => 'Pausa',
            'resume' => 'Återuppta',

            // Pausen är reversibel och synlig (Beslut 6): raden ligger kvar i
            // listan, gråtonad, med den här meningen. Förekomsterna rörs inte
            // — en pausad förekomst blockerar fortfarande de uppgifter som
            // beror på den, se issue 63c.
            'paused' => 'Pausad',
            'paused_note' => 'Schemat öppnar inga nya förekomster så länge det är pausat.',

            // Raderingen är mjuk, men papperskorgen listar fyra typer och
            // `schedule` är inte en av dem (issue 20a § Beslut 3). Texten
            // säger därför vad som försvinner och nämner varken 30 dagar
            // eller papperskorgen — att lova en väg tillbaka som inte finns är
            // värre än att inte lova någon (Beslut 8).
            'destroy' => 'Radera',
            'destroy_confirm' => 'Schemat och dess kommande förekomster tas bort. Vill du fortsätta?',

            'recurrence' => [
                'none' => 'En gång',

                'fixed' => [
                    'day' => 'Varje dag enligt kalendern',
                    'day_count' => 'Var :count:e dag enligt kalendern',
                    'week' => 'Varje vecka enligt kalendern',
                    'week_count' => 'Var :count:e vecka enligt kalendern',
                    'month' => 'Varje månad enligt kalendern',
                    'month_count' => 'Var :count:e månad enligt kalendern',
                    'year' => 'Varje år enligt kalendern',
                    'year_count' => 'Var :count:e år enligt kalendern',
                ],

                'interval' => [
                    'day' => 'Varje dag, räknat från senast utfört',
                    'day_count' => 'Var :count:e dag, räknat från senast utfört',
                    'week' => 'Varje vecka, räknat från senast utfört',
                    'week_count' => 'Var :count:e vecka, räknat från senast utfört',
                    'month' => 'Varje månad, räknat från senast utfört',
                    'month_count' => 'Var :count:e månad, räknat från senast utfört',
                    'year' => 'Varje år, räknat från senast utfört',
                    'year_count' => 'Var :count:e år, räknat från senast utfört',
                ],
            ],

            'form' => [
                'title' => 'Titel',
                'notes' => 'Anteckningar',
                'recurrence_type' => 'Återkommer',

                // Typnamnen är korta nog att rymmas i väljaren; förklaringen
                // nedanför bär skillnaden (Beslut 3).
                'type' => [
                    'none' => 'En gång',
                    'fixed' => 'Fast datum i kalendern',
                    'interval' => 'Intervall från senast utfört',
                ],

                'recurrence_none' => 'En gång. Uppgiften försvinner när den är klar.',
                'recurrence_fixed' => 'Återkommer på kalendern. Försäkringen förnyas 1 januari även om du betalade för sent.',
                'recurrence_interval' => 'Räknas från senast utfört. Oljebyte tolv månader efter förra bytet.',

                // Enheterna står i singular: de kombineras med ett antal, och
                // meningen ovan böjer ordet efter talet (Beslut 2).
                'unit' => 'Enhet',
                'units' => [
                    'day' => 'dag',
                    'week' => 'vecka',
                    'month' => 'månad',
                    'year' => 'år',
                ],
                'unit_none' => '— välj enhet —',
                'interval_count' => 'Antal',

                // `anchor_date` frågas för ALLA tre typerna (Beslut 4):
                // StoreScheduleRequest kräver den även för `interval` och
                // `none`, där den är seriens startpunkt och det första
                // förfallodatumet. Bara rubriken byter — ett obligatoriskt
                // fält som ser valfritt ut är ett 422 användaren inte
                // förstår.
                'anchor_date' => 'Första förfallodatum',
                'anchor_date_fixed' => 'Startpunkt i serien',

                // `lead_days` förklaras med vad den GÖR (Beslut 5): det är
                // `visible_from`, och utan meningen är fältet obegripligt.
                'lead_days' => 'Dagar innan förfall',
                'lead_days_hint' => 'Uppgiften dyker upp i todo-listan så här många dagar innan förfall.',
            ],

            'create' => [
                'title' => 'Nytt schema',
                'heading' => 'Nytt schema',
                'submit' => 'Skapa',
            ],

            'update' => [
                'title' => 'Redigera schema',
                'heading' => 'Redigera schema',
                'submit' => 'Spara',
            ],

            // Förekomsten — den enskilda gången, se issue 63b § Beslut 2–10.
            // Meningarna används på BÅDA ytorna: sektionen på itemet
            // (resources/js/components/ScheduleListSection.vue) och schemats
            // egen sida (resources/js/pages/Containers/Items/Schedules/Show.vue),
            // som delar formuläret resources/js/components/OpenOccurrence.vue.
            //
            // **Tre datum i rätt roll** (Beslut 2). `due` är förfallodagen,
            // `visible_from` är när uppgiften dök upp, och `window` är tiden
            // man har på sig — skillnaden dem emellan. Alla är DATE-kolumner
            // och formateras av formatDateOnly() i vyn, aldrig omräknade till
            // en annan tidszon (samma skäl som itemets datum, 57a).
            //
            // **Försenad är ett härlett tillstånd** (Beslut 3). Ordet nedan
            // ritas bara när serverns `overdue` är sant; vyn jämför aldrig
            // `due_at` mot klientens klocka.
            'occurrence' => [
                'heading' => 'Öppen förekomst',
                'none' => 'Ingen öppen förekomst.',
                'done' => 'Uppgiften är klar.',

                // Vägen till schemats sida, där historiken bor (Beslut 1).
                'view' => 'Förekomsterna',

                'due' => 'Förfaller :date',
                'visible_from' => 'Synlig sedan :date',
                'window' => ':days dagar på dig',
                'window_one' => '1 dag på dig',

                'overdue' => 'Försenad',

                // Kontot är varvet och inte den anställde
                // ([[Scheman och uppgifter]] § schedule_occurrence). Är hon
                // medlem i exakt ett konto ritas ingen väljare — ett val
                // mellan ett alternativ är ingen fråga — och raden nedan
                // visar i stället vilket konto som kommer att stå i loggen.
                'account' => 'Konto',
                'account_hint' => 'Kontot som står i loggen. Varvet, inte personen.',

                // Anteckningen är valfri och hamnar i historiken: "bytte även
                // termostaten" är precis den upplysning en logg är till för.
                'note' => 'Anteckning',
                'note_hint' => 'Valfri. Sparas i historiken.',

                'complete' => 'Bocka av',
                'skip' => 'Hoppa över',

                // Överhoppningen frågar innan den stänger (Beslut 5): den
                // öppnar nästa förekomst precis som en avbockning, men sparar
                // raden som överhoppad. En knapp som ser ut som den andra och
                // gör något annat i loggen ska inte gå att trycka fel på.
                'skip_confirm' => 'Uppgiften stängs som överhoppad, inte som utförd, och nästa förekomst öppnas precis som vid en avbockning. Vill du fortsätta?',

                // Historiken ÄR loggen (Beslut 7): avklarade och överhoppade
                // förekomster, ingen separat historiktabell. De två raderna
                // får inte se likadana ut — orden och färgen skiljer dem.
                'history' => 'Historik',
                'history_empty' => 'Inga avslutade förekomster än.',

                'status' => [
                    'open' => 'Öppen',
                    'completed' => 'Avklarad',
                    'skipped' => 'Överhoppad',
                ],

                'completed_at' => 'Avslutad :date',
                'completed_by' => 'av :name',
            ],

            // Beroendena, se issue 63c § Beslut 2, 3, 4, 5, 7 och 9. Sektionen
            // bor i resources/js/components/ScheduleDependencySection.vue och
            // ritas TVÅ gånger på schemats sida, en gång per nivå
            // (resources/js/pages/Containers/Items/Schedules/Show.vue).
            //
            // **Två nivåer, två rubriker** (Beslut 2). `heading_schedule` är
            // REGELN som ärvs av varje ny förekomst, `heading_occurrence` är
            // UNDANTAGET som bara gäller den här gången ([[ADR-0005 Schema och
            // förekomst]]). Skillnaden står i orden och inte i en typkolumn:
            // rubrikerna bär den, och en gemensam lista hade krävt att
            // användaren först förstod modellen.
            //
            // **`note_schedule` är ärvsmeningen.** Utan den ser en regel ut
            // som ett engångsval — den säger att varje ny förekomst kopplas till
            // motpartens DÅ öppna förekomst (Beslut 2).
            //
            // Nycklarna väljs på nivånamn (`schedule` | `occurrence`), samma
            // två ord som de två kontrollernas nivåer och som `level`-propen.
            'dependency' => [
                'heading_schedule' => 'Väntar alltid på',
                'heading_occurrence' => 'Väntar den här gången på',

                'note_schedule' => 'En regel för det här schemat. Varje ny förekomst kopplas automatiskt till motpartens då öppna förekomst.',
                'note_occurrence' => 'Ett undantag som bara gäller den här förekomsten.',

                'empty_schedule' => 'Schemat väntar inte på något.',
                'empty_occurrence' => 'Förekomsten väntar inte på något.',
                // Ett schema utan öppen förekomst har inga undantag att visa:
                // det finns ingen omgång att göra ett undantag för.
                'occurrence_none' => 'Ingen öppen förekomst, så det finns inga undantag att visa.',

                // Motparten är alltid ett SCHEMA, också på förekomstnivån: det
                // är motpartens öppna förekomst som väljs, men det användaren
                // känner igen är schemats titel och dess item (Beslut 3).
                'counterpart' => 'Motpart',
                'counterpart_none' => '— välj schema —',
                'no_counterparts' => 'Det finns inga andra scheman att vänta på.',

                'submit' => 'Lägg till',
                'remove' => 'Ta bort',
                // Raderingen är hård och bryter kopplingen och ingenting annat
                // (Beslut 7): både schemana och både förekomsterna finns kvar.
                'remove_confirm_schedule' => 'Beroendet tas bort. Båda schemana finns kvar. Vill du fortsätta?',
                'remove_confirm_occurrence' => 'Undantaget tas bort. Både schemana och båda förekomsterna finns kvar. Vill du fortsätta?',

                // `satisfied` kommer från servern (Beslut 4): en uppfylld rad är
                // avbockad och grå, en öppen rad är det som blockerar och syns
                // som sådan innan användaren försöker bocka av.
                'satisfied' => 'Klar',
                'blocking' => 'Blockerar',
            ],
        ],
    ],

    // Den globala sökningen, se issue 59b § Beslut 4, 6, 7 och 8. Sidan bor i
    // resources/js/pages/Search.vue, fältet i
    // resources/js/components/SearchField.vue — fältet ligger i den DELADE
    // layouten och syns därför på varje inloggad sida (Beslut 5).
    //
    // `empty` nämner sökordet och slutar där (Beslut 6): inget tal om hur
    // många rader som fanns, ingen antydan om att något dolts, ingen
    // uppräkning av vilka pärmar som genomsöktes — vilka pärmar som helst är
    // i sig en upplysning. En användare utan åtkomst till någonting alls får
    // ordagrant samma mening som en vars sökord inte matchar, för meningen
    // vet ingenting om omfånget.
    //
    // `intro` och `whole_words` är utgångsläget, alltså läget när ingen fråga
    // kördes alls (Beslut 4 och 7). Den sista raden säger att sökningen
    // matchar hela ord: svensk stemming finns inte i MVP ([[ADR-0012 Sök]]),
    // och ingen kompensation byggs i vyn — ingen stamning i JavaScript, ingen
    // andra sökning med trunkerat ord. En rad är hela svaret.
    //
    // `in_container` är prefixet före pärmnamnet, och bara prefixet: namnet är
    // en egen länk till pärmens förstasida (Beslut 3), så orden kan inte
    // ligga i samma sträng.
    'search' => [
        'title' => 'Sök',
        'heading' => 'Sök',

        'intro' => 'Söker i namn, beskrivning, tillverkare, modell och serienummer — i alla pärmar du når.',
        'whole_words' => 'Sökningen matchar hela ord: ”batteri” hittar inte ”batterier”.',
        'empty' => 'Inga träffar på ”:q”.',
        'in_container' => 'i',

        'field' => [
            'label' => 'Sök i alla pärmar',
            'placeholder' => 'Sökord',
            'submit' => 'Sök',
        ],
    ],

    // Todo-vyn, se issue 64. Startsidan efter inloggning — de öppna
    // förekomsterna över alla pärmar användaren når.
    //
    // De två tomma meningarna är olika med flit (Beslut 6): den ena säger att
    // användaren inte har någon pärm alls och bär en länk till att skapa en,
    // den andra att det inte finns något att göra. Ingen av dem nämner ett
    // antal eller antyder att något dolts — en omfångsbegränsad mottagare med
    // tom lista får ordagrant samma mening som en ägare vars uppgifter är
    // gjorda.
    'todo' => [
        'title' => 'Att göra',
        'heading' => 'Att göra',

        'due' => 'Förfaller :date',
        'complete' => 'Bocka av',

        // Sektionernas rubriker. Nyckeln är gruppens namn, samma tre ord som
        // kontrollern sorterar raderna i — ingen egen uppräkning i JavaScript
        // som kan glida ifrån serverns.
        'group' => [
            'overdue' => 'Försenat',
            'today' => 'Idag',
            'upcoming' => 'Kommande',
        ],

        'empty' => [
            'no_containers' => 'Du har inga pärmar än.',
            'create' => 'Skapa en pärm',
            'nothing' => 'Inget att göra just nu.',
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

    // Pärmens papperskorg, se issue 62a § Beslut 4, 5 och 9 och [[ADR-0008
    // Soft delete och papperskorg]] § Retentionstiden i MVP. Egen gren på
    // toppnivå och inte under `container`: papperskorgen är sin egen yta med
    // sin egen vokabulär, som `sharing`.
    //
    // **Tre nycklar för den återstående tiden, inte en.** `t()` har ingen
    // pluralisering (issue 52 § Beslut 4), så vyn väljer på talet: sista
    // dygnet säger `expires.today` och aldrig "0 dagar", en dag kvar säger
    // `expires.day` i singular, och resten `expires.days`.
    //
    // **`empty` säger att papperskorgen är tom och ingenting annat** (Beslut
    // 5, issue 74 § Beslut 1 och issue 73 § Beslut 6): ingen rad räknar
    // rader, och en omfångsbegränsad mottagare får exakt samma mening som
    // ägaren. Ett utgånget innehåll finns inte heller — vyn säger aldrig att
    // något försvunnit.
    'trash' => [
        'title' => 'Papperskorgen',
        'heading' => 'Papperskorgen',
        'description' => 'Det som raderats i pärmen. Efter 30 dagar tas det bort för gott.',
        'empty' => 'Papperskorgen är tom.',

        // `:date` formateras på klienten (formatDate), orden runt den här.
        'deleted_at' => 'Raderat :date',

        // Nycklarna är `type`-värdena ur RestoreRequest::TYPES, aldrig
        // påhittade egna namn — samma regel som container.kind. `container`
        // kom med issue 62b: en raderad pärm bär samma nyckel ur
        // TrashEntryResource, och raden är samma komponent i båda listorna.
        'type' => [
            'item' => 'Item',
            'attachment' => 'Bilaga',
            'category' => 'Kategori',
            'tag' => 'Tagg',
            'container' => 'Pärm',
        ],

        'expires' => [
            'today' => 'Försvinner idag',
            'day' => '1 dag kvar',
            'days' => ':days dagar kvar',
        ],

        'restore' => 'Återställ',

        // Papperskorgen för raderade PÄRMAR, se issue 62b § Beslut 7 och 8.
        // Den ligger på TOPPNIVÅ — en raderad pärm löses inte upp av
        // ruttbindningen — och `link` är raden under pärmlistan, alltid
        // synlig. Texten är konstant och räknar ingenting: ett tal hade varit
        // en fråga per sidladdning (Beslut 8).
        'containers' => [
            'title' => 'Papperskorgen',
            'heading' => 'Raderade pärmar',
            'description' => 'Pärmar du raderat. Efter 30 dagar tas de bort för gott.',
            'empty' => 'Inga raderade pärmar.',
            'link' => 'Papperskorgen',
            'back' => 'Till pärmarna',
        ],
    ],
];
