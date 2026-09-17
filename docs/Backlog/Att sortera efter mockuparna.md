# Att sortera efter mockuparna

Del av [[Backlog]]. **Det här är ingen milstolpe.** Det är en hållplats för arbete som är identifierat men ännu inte inplacerat, i väntan på genomgången av mockuparna.

Listan kommer ur genomgången av MVP:n 2026-09-17. Första omgången mockuper gicks igenom samma dag och avgjorde issue 83 och de fyra besluten nedan; resten av posterna väntar fortfarande på designen. Flera av dem kan visa sig vara överflödiga när den är känd, och minst en kan visa sig vara större än den ser ut — därför ligger de här i stället för i en milstolpe som påstår sig veta ordningen.

**En post lämnar den här filen när den blir en issue i en milstolpe** — eller, för ett beslut, när det står i en ADR. Står något kvar här som redan är byggt blir filen värdelös, precis som [[Tankar]] § Öppet.

---

### 83. Containerkontexten sätts av navigeringen

Systemet behöver veta vilken container användaren arbetar i — det är därför `App\Support\Frontend\ActiveContainer` finns. Men det är bokföring, och bokföring ska inte ha en knapp. I dag möter användaren "gör aktiv" i containerlistan, trycker på den och ser en markering flytta sig i samma lista. Vad knappen bokför syns ingenstans, och att inte förstå vad den gör är rätt slutsats.

**Avgjort vid mockupgenomgången 2026-09-17: mekanismen stannar, knappen försvinner.** Kontexten sätts av att användaren öppnar en container. Markeringen i navigeringen — den mockuparna visar under *Mina containers* — blir en effekt av var användaren befinner sig, inte en inställning hon gör.

**Det här ändras**

- `App\Http\Controllers\ActiveContainerController` och rutten `containers.active` utgår. Det finns inget att skicka en `PUT` till när kontexten inte längre är ett val.
- Knappen i `resources/js/pages/Containers/Index.vue` och markeringen som är dess enda verkan utgår.
- `ActiveContainer::set()` anropas när en container öppnas, utöver de tre befintliga ställena: skapad container, antagen inbjudan, mottaget ägarbyte.
- `AktivContainerTest` prövar i dag att rutten sätter nyckeln. Den ska pröva att navigeringen gör det.

**Det här ändras inte**

- `App\Support\Frontend\ActiveContainer` och sessionsnyckeln. De är hela poängen.
- Propen `activeContainer` i `HandleInertiaRequests`. Den är vad navigeringens markering kommer att läsa när navigeringen byggs, och den delas ut som i dag.
- Kontrollen i `forUser()` mot `Container::scopeAccessibleBy()`. Att kontexten sätts implicit gör åtkomstkontrollen viktigare, inte mindre viktig — en container användaren mist åtkomsten till får aldrig ligga kvar som kontext.

**Klart när**

- [ ] `PUT /containers/{container}/active` finns inte längre.
- [ ] Att öppna en container sätter sessionsnyckeln till containerns ULID.
- [ ] Att öppna en container användaren saknar åtkomst till lämnar kontexten orörd.
- [ ] Propen `activeContainer` delas fortfarande ut och bär ULID:t för den senast öppnade containern.
- [ ] Containerlistan har ingen knapp som sätter kontexten.

**Axlar:** `ambiguity: low` · `blast_radius: cross-module` — den delade propen är något andra issues byggt på · `risk_class: none`

**Läs:** `app/Support/Frontend/ActiveContainer.php`, issue 51 § Beslut 4 och issue 55 i [[M10 Webbfrontend]]
**Beror på:** -

---

## Avgjort vid mockupgenomgången

Tre beslut fattades 2026-09-17 och är utskrivna:

- [[ADR-0035 Relationen mellan objekt]] — `sibling` heter `related`. Tre relationer, inte fyra. Namnbytet går i databasen, inte bara i etiketten, och ska ligga **efter** omskrivningen av `lang/` i [[M13 Omskrivningen]].
- [[ADR-0036 Containerns art]] — `kind` blir fritt med autocomplete, CHECK-villkoret utgår, navigeringen grupperar vid minst två. Kategorimallarna tappar sin nyckel och hör därmed ihop med mallvalet i [[ADR-0033 Produktens omfång]].
- [[ADR-0037 Valutans arv]] — konto → container → rad, med omval på varje nivå. Ett ändrat förval rör aldrig gamla poster.

Ingen av dem har en issue ännu.

**Skalen**, som inte är ett beslut utan en läsning av mockuparna: trepanelsvyn är vad användaren ser när ett objekt öppnas, dashboarden är vad som möter henne efter inloggning, containervyn ligger mellan dem.

---

## Ännu inte issues

**Luckorna i kontot.** Byta lösenord. Byta e-post — egen issue och `risk_class: elevated`, för med tvingande tvåfaktor blir ett e-postbyte utan kodkrav en väg runt andra faktorn, samma klass av hål som issue 80 stängde. Inbjudningar syns i dag bara som mejl och inte när användaren loggar in.

**Verifieringarna.** Att en uppladdning bara lagras en gång (dedup och referensräkning) och att filer inte går att nå obehörigt ska bevisas av bestående tester, inte av en genomgång per release. Båda ytorna är `risk_class: elevated` enligt [[AGENTS.md]] § De tre axlarna.

**Kalenderfeedens namn** hämtas från URL:en i stället för produktnamnet och containerns namn.

**Notiser vid uppgift.** När skickas de? Frågan är först en uppslagning i [[Notiser]] och blir en issue bara om svaret och beteendet går isär.

**Delsträngssök kräver MariaDB i CI.** `LIKE '%ord%'` är dagens beteende via databasdrivaren; FULLTEXT-grenen i [[ADR-0012 Sök]] är avstängd tills sviten kan köras mot MariaDB. Den frågan står redan i [[Tankar]] § Öppet, rest i issue 2 och halvt besvarad 2026-09-03 — den behöver inte resas igen, den behöver avgöras.

**Designsystemet och genomgången vy för vy.** Väntar på mockuparna per definition. Tokens och kärnkomponenter före sidor, annars blir varje vy ett frihandsjobb.

**Miniatyrer i itemlistan** står redan som öppen fråga i [[Tankar]] § Öppet, rest när 61b skrevs. Den avgörs av designen och behöver inget eget spår här.
