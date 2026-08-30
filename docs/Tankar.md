# Tankar

Frågor **utan svar**. Så fort en punkt här är besvarad flyttar den till rätt dokument — datamodellen om den beskriver *vad*, en ADR om den beskriver *varför* — och stryks härifrån. Står något kvar som redan är avgjort blir filen värdelös som lista över vad som faktiskt återstår.

Öppna frågor som redan hör hemma i ett beslut bor där, inte här: S3-frågan och prispunkten i [[Översikt]] § Öppna frågor, och de fem sakerna att verifiera hos inleed i [[ADR-0018 Utvecklingsprocess och deploy]].

## Öppet

- **Testsviten kör mot sqlite i minnet, produktionen mot MariaDB 10.6.** Grön CI bevisar därför inte att schemat fungerar på servern. Skillnaderna som kan bita: `CHAR(26)` med längdkontroll, CHECK-villkor för uppräkningar, främmande nycklar med `RESTRICT` (sqlite kräver att de slås på per anslutning), och `utf8mb4_unicode_ci` som sqlite inte har någon motsvarighet till. Frågan är om CI ska köra sviten mot en MariaDB-tjänst istället för, eller vid sidan av, sqlite — och vad det kostar i körtid per PR. Restes i issue 2 när de första migrationerna kom in.

- **`last_active_at` skrivs vid varje autentiserat API-anrop.** Middlewaren från issue 3 gör en UPDATE per request. Kolumnen driver kontolivscykeln i [[Planer och kvoter]], där dygnsupplösning räcker gott — en skrivning per anrop är alltså långt mer än vad någon konsument behöver. Frågan är om den ska strypas till högst en skrivning per användare och tidsfönster, och var det i så fall hör hemma: i middlewaren, eller som en köad uppdatering. Restes i granskningen av issue 3.

- **En användare i flera konton har inget härlett språk.** `User::preferredLocale()` från issue 5 returnerar användarens eget `locale` när det är satt, annars kontots — men bara när personen hör till exakt ett konto. Med flera konton returneras `NULL`, vilket Laravel tolkar som "rör inte den aktiva inställningen". Det är medvetet konservativt: att välja `owner`-kontot eller det äldsta vore en gissning som ingen dokumentation stöder. Konsekvensen är att en varvsanställd som också har ett privatkonto får standardspråket i mejl tills någon sätter `locale` på användaren. Frågan är om det räcker, eller om flerkontofallet behöver en uttalad regel — den blir synlig först när mejlen blir många i [[Notiser]]. Restes i granskningen av issue 5.

- **Magic link kringgår TOTP helt.** Efter issue 6b kräver lösenordsinloggning en TOTP-kod när kontot har `totp_confirmed_at` satt — men magic link-inlösen gör ingen sådan kontroll. Den som kommer åt inkorgen loggar alltså in utan andra faktorn, och tvåfaktorn skyddar bara den väg in som redan krävde ett lösenord. Att issue 6b inte rörde magic link var avsiktligt och rätt avgränsat; frågan som inte är avgjord är vad som *ska* gälla. Tre vägar: kräva TOTP även efter en inlöst länk, sluta skicka magic link till konton med bekräftad TOTP, eller acceptera att magic link är en medvetet svagare väg som användaren väljer själv. Valet hänger ihop med [[ADR-0011 Autentisering]]:s motiv att ha två vägar in — att ett leveransproblem hos Postmark inte ska låsa ute alla — och bör avgöras innan tvåfaktorn marknadsförs som ett skydd. Restes i granskningen av issue 6b.

## Avgjort och flyttat

Punkterna nedan låg här som frågor och är besvarade. De står kvar som spår av var svaret hamnade, inget annat.

- Underkategorier, och om ett item kan tillhöra flera kategorier → [[Items och organisation]] § category. Kategorier är hierarkiska via `parent_id`, ett item tillhör högst en. Taggar är platta, medvetet.
- Fil-dedup via innehållshash och radering när sista referensen försvinner → [[Filer och lagring]] och [[ADR-0006 Innehållsadresserad lagring]].
- Utlåning med påminnelse → [[Items och organisation]] § loan. Påminnelsen går till den som lånat ut, aldrig till låntagaren; skälet står i [[ADR-0017 Missbruksvektorer]] § 7.
- Frontendteknik → [[ADR-0021 Frontendteknik]].
- GitHub-org och repo-struktur → [[Pipeline]] § Repo och organisation. Orgen bär bolagsnamnet, ett repo per app, koden i `Manjo-Consulting-AB/mimers`.
- Larastan ser inte modellernas `casts()`, så en castad kolumn typades som `string` i stället för `Carbon` → `parseModelCastsMethod: true` i `phpstan.neon`, se [[ADR-0022 Testramverk och statisk analys]] § Konsekvenser. Löser hela typningsproblemet utan en enda kodändring i modellerna. Två sakfel stod tidigare på den här punkten: flaggan **finns** — i `vendor/larastan/larastan/extension.neon`, `false` som standard — och att ta bort `@return array<string, string>` över `casts()` löser ingenting, Larastan läser aldrig `casts()`-metoden så länge flaggan är av, oavsett docblock. `ContainerAccessResource` och `InvitationResource` läser nu castade kolumner via egenskapsåtkomst; de docblocken förklarar också varför `setAttribute()`-attributen `grantee_ulid`, `granted_by_ulid` och `invited_by_ulid` fortsatt läses via `getAttribute()` — de är varken kolumn eller cast. Restes i granskningen av issue 9b, avgjort i issue 63.
