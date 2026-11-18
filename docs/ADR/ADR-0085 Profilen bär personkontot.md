# ADR-0085 Profilen bär personkontot

**Status:** Antagen 2026-11-16 · Ändrar issue 53c § Beslut 1 och 9 (två skrivande ytor, Profil och Konton) · [[ADR-index]]

Tonys beslut 2026-11-16, efter att ha jämfört `/settings/accounts` med `/settings/profile`.

## Kontext

Issue 53c delade inställningarna i två sidor: Profil för personen (`user`) och Konton för varje konto personen är medlem i (`account`). Personens språk, tidszon och enhetssystem kan stå på *Följ kontots …*, och kontot har egna värden under.

I dag har varje användare exakt ett konto. Det enda som skapar en rad i `account_user` är registreringen (`CreatesUserWithPersonalAccount`), och organisationskonton går inte att skapa. Kontosidan visar därför ett enda kort, personkontot, med samma namn, språk, tidszon och enhetssystem som profilen. Det enda kortet har som profilen saknar är valutan och ett kontonamn som får skilja sig från personens. Två sidor och två lager för samma inställningar är svårt att förklara.

## Beslut

**1. Kontosidan tas bort.** `/settings/accounts` och raden i inställningarnas navigering utgår. Båda rutterna, `GET /settings/accounts` och `PATCH /settings/accounts/{account}`, tas bort utan omdirigering. Adressen lämnas fri åt organisationskontonas flik (§ 6).

**2. Profilen får en avdelning för personkontot.** Den visar två fält: kontonamnet och valutan. Personkontot är det konto där personen har rollen `owner` och kontot har `type` `personal`.

**3. Kontonamnet är ett eget fält och märks som synligt för andra.** Namnet följer inte personens namn. Fältet bär en hjälptext som säger att andra kan se namnet, till exempel i deltagarlistan ([[Konton och åtkomst]] § account).

**4. Språk, tidszon och enhetssystem har ett lager i gränssnittet och skrivs bara till `user`.** Valet *Följ kontots …* försvinner. Fälten visar personens gällande värde: `preferredLocale()`, `preferredTimezone()`, och för enhetssystemet `user.unit_system` med personkontots värde som reserv, och när profilen sparas skrivs värdet till `user`, aldrig till kontot. Kolumnerna står kvar på båda tabellerna. Registreringen sätter fortfarande bara kontots värden, så kontot blir reservvärdet för den som aldrig sparat sin profil, och `user` åsidosätter det när värdena läses.

**5. Behörigheten bor kvar i policyn och gäller bara kontots fält.** Kontonamnet och valutan skrivs efter `Gate::allows('update', $account)` mot App\Policies\AccountPolicy::update(), som i dag. Är kontot `read_only` eller `closed` säger policyn nej, och då visas de två fälten som text. Personens egna fält rör inte kontot och prövas inte mot policyn.

**6. Organisationskonton får en egen flik när de finns.** Den här ADR:en bygger ingenting för dem i förväg.

## Konsekvenser

- [[Konton och åtkomst]] § account: kontot döps om på profilsidan, inte i kontovyerna.
- `AccountSettingsController`, `UpdateAccountSettingsRequest`, `Settings/Accounts.vue` och `AccountSettingsForm.vue` tas bort. Reglerna för kontonamn och valuta flyttar till `UpdateProfileRequest`, så att de fortfarande finns på ett enda ställe.
- Översättningsnycklarna under `settings.accounts.*` och `settings.profile.*_follow*` som inte längre används tas bort.
- Ingen skrivning håller två tabeller lika: de tre inställningarna skrivs på ett ställe, kontots fält på ett annat.
- API:et ändras inte. Det har ingen skrivväg för kontot.
- Byggs i [[M64 Profilen bär kontot]] som 398.

## Alternativ som valts bort

- **Skriva de tre inställningarna både till `user` och till kontot.** Två ställen att hålla lika, som glider isär så fort en skrivning bara når det ena — till exempel när kontot är låst men personen inte är det.
- **Omdirigera `/settings/accounts` till profilen.** Skyddar några testares bokmärken, men upptar den adress organisationskontonas flik vill ha och måste rivas när den byggs.
- **Ta bort kolumnerna på `account`.** Registreringen sätter bara kontots värden och läsningen faller tillbaka på dem; att ta bort dem kräver en migration som först kopierar dem till `user`, ett eget arbete utan vinst i dag.
- **Båda lagren på profilsidan.** Ärligare mot datamodellen, men dubbleringen står kvar och har bara flyttat till en sida.
- **Kontonamnet följer personens namn.** Tar bort ett fält, men den som vill visa ett annat namn för andra än sitt eget kan då inte göra det.
- **Behålla kontosidan för framtida organisationskonton.** Bygger för något som inte finns. När ett organisationskonto går att skapa får det en egen flik.

## Uppföljning 2026-11-18

Kontexten säger att varje användare i dag har exakt ett konto. Från och med 2026-11-18 är det en regel och inte ett läge: en användare har ett konto och ett konto en användare (Tony: *"EN ANVÄNDARE PER KONTO!"*). Se [[Konton och åtkomst]] § account. Ändras regeln krävs en ny ADR.
