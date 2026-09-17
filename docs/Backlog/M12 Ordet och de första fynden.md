# M12 · Ordet och de första fynden

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-17, efter att M10 stängts och webben gick att använda på riktigt för första gången. Tre av fyra issues här är fynd ur den genomgången: saker som är byggda, testade och gröna — men som användaren inte kan nå eller inte förstår.

**Det gemensamma draget är värt att notera.** Ingen av dem är en trasig funktion. Sökningen matchar, säkerhetssidan fungerar, inställningarna sparar. Det som saknas är vägen dit och orden på skylten — och en grind som en andra inloggningsväg går förbi.

### 77. Ordet: container och objekt
Gränssnittet bär i dag tre ordförråd för två begrepp: svenskan säger *pärm* om containern men *item* om itemet, engelskan säger *binder*, och dokumentationen och koden säger `container` och `item`. [[ADR-0032 Produktens ord]] avgör saken: **container** och **objekt** på svenska, **container** och **item** på engelska, och *pärm* utgår ur varje sträng användaren möter.

Identifierare rörs inte — de är redan rätt. Det här är ett byte av text och prosa, inte av kod.
**Läs:** [[ADR-0032 Produktens ord]], [[AGENTS.md]] § Språk i koden
**Klart när:** ingen sträng i `lang/` bär ordet pärm eller binder, svenska gränssnittet säger objekt där det i dag säger item, båda språkfilerna har samma nycklar, och hela testsviten är grön.
**Beror på:** -
**Byggd som:** 77a strängarna användaren möter — `lang/` och de tester som påstår något om dem, 77b prosan i koden — kommentarer, docblock och testnamn. Två sessioner därför att de har olika grindar: 77a syns i gränssnittet och kan gå sönder, 77b rör inte en enda rad som körs.

### 78. Sökningen: fältet och vägen dit
`GET /search` finns, frågar rätt och filtrerar på omfång — men sidan har **inget sökfält**, och ingen länk i navigeringen pekar dit. Den enda vägen in är att skriva `?q=` i adressfältet för hand. Utifrån ser det ut som att sökningen inte hittar något; i själva verket har ingen fråga ställts.

Utgångslägets text påstår dessutom att sökningen matchar *hela ord*. Det stämmer inte: databasdrivrutinen formulerar `LIKE '%ord%'`, så en delsträng mitt i ett ord ger träff. Texten beskriver [[ADR-0012 Sök]]s FULLTEXT-gren, som är avstängd tills CI kör mot MariaDB.
**Läs:** [[ADR-0012 Sök]] § Beslut, `app/Actions/Item/SearchAccessibleItems.php`, `resources/js/components/ItemFilterBar.vue` (mönstret för ett sökfält som postar en querysträng finns redan)
**Klart när:** sökfältet står på sidan och bär den fråga som ställdes, navigeringen har en väg till sökningen, en sökning går att spara och dela som länk, och utgångslägets text säger något som är sant om vad sökningen matchar.
**Beror på:** 59

### 79. Vägen till inställningarna
Sju inställningssidor är byggda — profil, konton, plan, lagring, notiser, webhookar och säkerhet. Ingen av dem går att nå från gränssnittet: `AppLayout` har fyra länkar (översikt, containers, ägarbyten, logga ut) och ingen av dem leder till `/settings`. Sektionsnavigeringen i `SettingsLayout` syns först när man redan står på en inställningssida.

Konsekvensen är inte kosmetisk. Tvåfaktorn slås på under Säkerhet, och en användare som inte hittar dit kan inte koppla sin kodapp.
**Läs:** `resources/js/layouts/AppLayout.vue`, `resources/js/layouts/settingsSections.js`, issue 53b och 53c i [[M10 Webbfrontend]]
**Klart när:** en inloggad användare når varje inställningssida med enbart tangentbord från vilken sida som helst, vägen dit fungerar också i det hopfällda mobilläget, och `/settings` landar på profilen som förut.
**Beror på:** 53

### 80. Magic link går förbi bekräftad tvåfaktor
`LoginRequest::authenticate()` kräver en engångskod av varje konto med `totp_confirmed_at` satt. Magic link-inloggningen gör ingen sådan kontroll: `MagicLinkLoginController` loggar in användaren direkt när token konsumerats, på både webben och `/api`. Den som har tvåfaktor påslagen kan alltså logga in utan sin andra faktor genom att be om en länk — och en angripare som kommer åt brevlådan behöver aldrig koden.

[[ADR-0011 Autentisering]] säger *att* TOTP ska finnas, men inte att den gäller varje väg in. Det gör den nu: en bekräftad tvåfaktor krävs på **alla** inloggningsvägar. Återställningskoderna (issue 6c) är utvägen när appen är borta — magic link får inte vara det.
**Läs:** [[ADR-0011 Autentisering]], [[Konton och åtkomst]] § user, `app/Http/Requests/Auth/LoginRequest.php`, `app/Support/Auth/TotpBroker.php`
**Klart när:** ett konto med bekräftad TOTP kommer inte in via magic link utan att ange engångskod eller återställningskod, på både webben och `/api`; ett konto utan bekräftad TOTP loggar in precis som förut; en konsumerad token kan inte återanvändas för ett andra försök; och felkoden följer [[AGENTS.md]] § Felformat på API-sidan.
**Beror på:** 6
