# M64 · Profilen bär kontot

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-16, efter Tonys beslut att ta bort `/settings/accounts`. Personkontots namn och valuta flyttar till profilsidan, och språk, tidszon och enhetssystem får ett enda lager i gränssnittet. Beslutet och motiveringen står i [[ADR-0085 Profilen bär personkontot]].

En issue.

**Releasen:** en, när 398 är mergad. Issuen skrevs direkt i GitHub (#1366) och är sammanfattad här.

---

### 398. Kontosidan in i profilen

GitHub #1366. **Läget:** `/settings/accounts` (`AccountSettingsController`, `Settings/Accounts.vue`, `AccountSettingsForm.vue`) visar ett kort per konto. Eftersom varje användare bara har sitt personkonto visar sidan samma namn, språk, tidszon och enhetssystem som `/settings/profile`. Profilen erbjuder *Följ kontots …* för de tre inställningarna.

**Lösningen:** profilsidan får en avdelning för personkontot med kontonamnet och valutan ([[ADR-0085 Profilen bär personkontot]] § 2). Kontonamnet har en hjälptext om att andra kan se det (§ 3). Språk, tidszon och enhetssystem förlorar valet *Följ kontots …*. De visar personens gällande värde, och `PATCH /settings/profile` skriver dem bara till `user`, aldrig till kontot (§ 4). Kontonamn och valuta skrivs i samma PATCH, som `account_name` och `currency`, och deras regler flyttar från `UpdateAccountSettingsRequest` till `UpdateProfileRequest`. Bara de två prövas med `Gate::allows('update', $account)`. Säger policyn nej visas de som text och skrivs inte (§ 5). Båda rutterna under `/settings/accounts`, utan omdirigering, kontrollern, `UpdateAccountSettingsRequest`, sidan, formulärkomponenten, raden i `settingsSections.js` och översättningsnycklar som inte längre används tas bort (§ 1). `accountDefaults` i `ProfileController` behövs inte längre och tas bort. [[Konton och åtkomst]] § account säger att kontot döps om på profilsidan.

**Läs:** [[ADR-0085 Profilen bär personkontot]], [[ADR-0037 Valutans arv]] § Beslut, [[Konton och åtkomst]] § account och § user

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated (skriver `account` och prövar behörighet)

**Klart när:** inställningarnas navigering saknar Konton, och `GET /settings/accounts` ger 404; profilen visar kontonamn och valuta för personkontot, och kontonamnet har hjälptexten om att andra kan se det; profilen saknar *Följ kontots …* och visar personens gällande värden; en sparning skriver språk, tidszon och enhetssystem till `user` och lämnar kontots värden orörda, och skriver namn och valuta till personkontot; en ogiltig valuta ger ett valideringsfel på fältet; med ett konto i `read_only` eller `closed` visas kontots fält som text och en sparning ändrar bara `user` och lyckas; ingen väg i sparningen skriver till ett annat konto än personkontot; `KontoinstallningarTest` och `KontovalutaTest` är omskrivna mot profilsidan och täcker punkterna, `InstallningsvyerTest` saknar kontosidan; hela sviten är grön.

**Beror på:** ingenting
