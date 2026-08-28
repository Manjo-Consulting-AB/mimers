# Tankar

Frågor **utan svar**. Så fort en punkt här är besvarad flyttar den till rätt dokument — datamodellen om den beskriver *vad*, en ADR om den beskriver *varför* — och stryks härifrån. Står något kvar som redan är avgjort blir filen värdelös som lista över vad som faktiskt återstår.

Öppna frågor som redan hör hemma i ett beslut bor där, inte här: S3-frågan och prispunkten i [[Översikt]] § Öppna frågor, och de fem sakerna att verifiera hos inleed i [[ADR-0018 Utvecklingsprocess och deploy]].

## Öppet

- **Testsviten kör mot sqlite i minnet, produktionen mot MariaDB 10.6.** Grön CI bevisar därför inte att schemat fungerar på servern. Skillnaderna som kan bita: `CHAR(26)` med längdkontroll, CHECK-villkor för uppräkningar, främmande nycklar med `RESTRICT` (sqlite kräver att de slås på per anslutning), och `utf8mb4_unicode_ci` som sqlite inte har någon motsvarighet till. Frågan är om CI ska köra sviten mot en MariaDB-tjänst istället för, eller vid sidan av, sqlite — och vad det kostar i körtid per PR. Restes i issue 2 när de första migrationerna kom in.

- **`last_active_at` skrivs vid varje autentiserat API-anrop.** Middlewaren från issue 3 gör en UPDATE per request. Kolumnen driver kontolivscykeln i [[Planer och kvoter]], där dygnsupplösning räcker gott — en skrivning per anrop är alltså långt mer än vad någon konsument behöver. Frågan är om den ska strypas till högst en skrivning per användare och tidsfönster, och var det i så fall hör hemma: i middlewaren, eller som en köad uppdatering. Restes i granskningen av issue 3.

- **En användare i flera konton har inget härlett språk.** `User::preferredLocale()` från issue 5 returnerar användarens eget `locale` när det är satt, annars kontots — men bara när personen hör till exakt ett konto. Med flera konton returneras `NULL`, vilket Laravel tolkar som "rör inte den aktiva inställningen". Det är medvetet konservativt: att välja `owner`-kontot eller det äldsta vore en gissning som ingen dokumentation stöder. Konsekvensen är att en varvsanställd som också har ett privatkonto får standardspråket i mejl tills någon sätter `locale` på användaren. Frågan är om det räcker, eller om flerkontofallet behöver en uttalad regel — den blir synlig först när mejlen blir många i [[Notiser]]. Restes i granskningen av issue 5.

- **Magic link kringgår TOTP helt.** Efter issue 6b kräver lösenordsinloggning en TOTP-kod när kontot har `totp_confirmed_at` satt — men magic link-inlösen gör ingen sådan kontroll. Den som kommer åt inkorgen loggar alltså in utan andra faktorn, och tvåfaktorn skyddar bara den väg in som redan krävde ett lösenord. Att issue 6b inte rörde magic link var avsiktligt och rätt avgränsat; frågan som inte är avgjord är vad som *ska* gälla. Tre vägar: kräva TOTP även efter en inlöst länk, sluta skicka magic link till konton med bekräftad TOTP, eller acceptera att magic link är en medvetet svagare väg som användaren väljer själv. Valet hänger ihop med [[ADR-0011 Autentisering]]:s motiv att ha två vägar in — att ett leveransproblem hos Postmark inte ska låsa ute alla — och bör avgöras innan tvåfaktorn marknadsförs som ett skydd. Restes i granskningen av issue 6b.

- **Larastan ser inte modellernas `casts()`, så castade kolumner typas som `string` i stället för `Carbon`.** Orsaken är docblocken `@return array<string, string>` över `casts()`: den kommer från Laravels egen skelettkod, men den överskuggar den literala arrayen i metodkroppen, och utan den literalen kan Larastan inte veta att `expires_at` är ett datum. Alla fyra modeller som har `casts()` bär docblocken i dag — `ContainerAccess`, `MagicLinkToken`, `TotpRecoveryCode` och `User` — så problemet är systemiskt, inte en egenhet hos en modell. Det är **inte** en inställning i `phpstan.neon`; den filen är avskalad (nivå 5, sökvägar, Larastan-tillägget) och saknar varje `parseModelCastsMethod`-liknande nyckel.

  Konsekvensen syns först i en resurs som läser en castad kolumn: `$this->expires_at?->toIso8601String()` faller på nivå 5 eftersom PHPStan tror att `expires_at` är en sträng. `ContainerAccessResource` (issue 9b) går därför via `$this->resource->getAttribute(...)`, som returnerar otypad `mixed` — en fasad som döljer symtomet i stället för att åtgärda orsaken, dokumenterad i resursens docblock. **Den ska bort** när modellerna är `In scope` i ett kommande svep.

  Ett besläktat hål i samma resurs: attribut som controllern sätter i minnet med `setAttribute()` — `grantee_ulid` och `granted_by_ulid`, ULID-uppslagen från issue 9b § Beslut 11 — finns varken som kolumn eller cast, så `@mixin` känner inte igen dem som egenskaper. De behöver en egen lösning; en `@property`-rad på modellen vore fel, för attributen finns bara under den ena resursens livstid.

  Frågan är alltså vilken av tre vägar som ska gälla, och den bör avgöras en gång för alla modeller: ta bort eller smalna av docblocken över `casts()` så PHPStan läser den literala arrayen, konfigurera Larastan så den läser docblocken ändå, eller acceptera `getAttribute()` som konvention och skriva in den. Valet hänger ihop med att nivån är medvetet 5 och ska höjas när konventionerna från issue 2 satt sig, se [[ADR-0022 Testramverk och statisk analys]] — en höjning gör det här mer akut, inte mindre. Restes i granskningen av issue 9b.

## Avgjort och flyttat

Punkterna nedan låg här som frågor och är besvarade. De står kvar som spår av var svaret hamnade, inget annat.

- Underkategorier, och om ett item kan tillhöra flera kategorier → [[Items och organisation]] § category. Kategorier är hierarkiska via `parent_id`, ett item tillhör högst en. Taggar är platta, medvetet.
- Fil-dedup via innehållshash och radering när sista referensen försvinner → [[Filer och lagring]] och [[ADR-0006 Innehållsadresserad lagring]].
- Utlåning med påminnelse → [[Items och organisation]] § loan. Påminnelsen går till den som lånat ut, aldrig till låntagaren; skälet står i [[ADR-0017 Missbruksvektorer]] § 7.
- Frontendteknik → [[ADR-0021 Frontendteknik]].
- GitHub-org och repo-struktur → [[Pipeline]] § Repo och organisation. Orgen bär bolagsnamnet, ett repo per app, koden i `Manjo-Consulting-AB/mimers`.
