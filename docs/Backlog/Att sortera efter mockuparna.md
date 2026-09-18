# Att sortera efter mockuparna

Del av [[Backlog]]. **Det här är ingen milstolpe.** Det är en hållplats för arbete som är identifierat men ännu inte inplacerat, i väntan på genomgången av mockuparna.

Listan kommer ur genomgången av MVP:n 2026-09-17. Första omgången mockuper gicks igenom 2026-09-17 och 18; det som avgjordes där har flyttat till [[M14 Besluten ur mockupgenomgången]], och det som står kvar här väntar fortfarande på designen. Flera av dem kan visa sig vara överflödiga när den är känd, och minst en kan visa sig vara större än den ser ut — därför ligger de här i stället för i en milstolpe som påstår sig veta ordningen.

**En post lämnar den här filen när den blir en issue i en milstolpe** — eller, för ett beslut, när det står i en ADR. Står något kvar här som redan är byggt blir filen värdelös, precis som [[Tankar]] § Öppet.

---

## Avgjort vid mockupgenomgången

Tre beslut fattades 2026-09-17 och är utskrivna:

- [[ADR-0035 Relationen mellan objekt]] — `sibling` heter `related`. Tre relationer, inte fyra. Namnbytet går i databasen, inte bara i etiketten, och ska ligga **efter** omskrivningen av `lang/` i [[M13 Omskrivningen]].
- [[ADR-0036 Containerns art]] — `kind` blir fritt med autocomplete, CHECK-villkoret utgår, navigeringen grupperar vid minst två. Kategorimallarna tappar sin nyckel och hör därmed ihop med mallvalet i [[ADR-0033 Produktens omfång]].
- [[ADR-0037 Valutans arv]] — konto → container → rad, med omval på varje nivå. Ett ändrat förval rör aldrig gamla poster.
- [[ADR-0038 Gränsen för Pro i kostnaderna]] — en fast summering är fri, allt frågbart är Pro. Ersätter en rad i [[ADR-0016 Kostnadsregistrering]].

Alla fem har nu issues i [[M14 Besluten ur mockupgenomgången]] — 83 till 87.

**Skalen**, som inte är ett beslut utan en läsning av mockuparna: trepanelsvyn är vad användaren ser när ett objekt öppnas, dashboarden är vad som möter henne efter inloggning, containervyn ligger mellan dem.

---

## Dashboarden — avgjort 2026-09-18

Genomgången av dashboardmockupen mot datamodellen. Punkterna nedan är avgjorda men har ingen issue.

**Dashboarden är en ny sida, och `/dashboard` är i dag todo-vyn.** `TodoController` flyttar till en egen task-vy på egen URL, och den behåller allt som redan är genomtänkt i den — ordningen som räknas på servern, `can.update` per rad, den konstanta frågekostnaden via `ResolveItemScope`. Dashboardens högerspalt visar fem rader och länkar dit.

**Pagineringsförbudet faller.** Issue 64 § Beslut 3 och [[ADR-0005 Schema och förekomst]] motiverar den opaginerade listan med att *"i april förfaller allt samtidigt"*. Den premissen är båtpärmen, inte produkten — se [[ADR-0033 Produktens omfång]]. ADR-0005 står kvar som historik enligt regeln i [[ADR-0032 Produktens ord]]; omprövningen hör hemma i task-vyns issue och behöver ingen ADR.

**En uppgiftsbricka, inte två.** Mockupens *Uppgifter* och *Underhåll* är samma tabell — `schedule` skiljer dem bara åt via `recurrence_type`, och den skillnaden ska inte bäras av dashboarden. Siffran är antalet rader bakom "Visa alla", alltså `ScheduleOccurrence::scopeTodoFor()`, och inget annat tal. Brickorna blir tre.

**Händelsepanelen visar användarens egna rader**, `audit_log` filtrerat på `user_id`. Det är hennes egna handlingar, så ingen åtkomstfråga uppstår och panelen är `risk_class: none`. Kontots samlade logg är en annan funktion med andra läsare — bygg inte ihop dem. Tabellen har bara `(container_id, created_at)`; frågan behöver ett index till. Radernas text är `action` plus `meta`, inte meningar, så copyn hör till `lang/` och alltså efter [[M13 Omskrivningen]].

**Inbjudningar hamnar bakom klockan.** `Notification::TYPE_INVITATION_RECEIVED` finns redan och `notification` är indexerad på `(user_id, created_at)` — det som saknas är bara en yta som läser raderna. Alla sju notistyperna kan visas där. `user_id` är nullbar för en inbjudan till någon som ännu inte har konto; för henne är mejlet fortfarande enda vägen in, och det är rätt.

**Nollor är inte ett designproblem.** Tomma fält och nollställda tal accepteras tills användaren fyllt systemet. Undantaget är förstagångsanvändaren utan en enda container — `TodoController` skiljer redan på *ingen container alls* och *containrar utan uppgifter*, och den skillnaden ska behållas.

**Informationsytan** ersätter mockupens exempelbanner: korta tips som användaren bläddrar i ordning och kan dölja med ett kryss. Fyra krav: det dolda tillståndet lagras på användaren och inte i webbläsaren, det lagras per meddelande så att ett nytt viktigt meddelande kan tändas igen utan att riva hennes tidigare val, ordningen är bestämd och inte slumpad, och tipsen är strängar i `lang/` — alltså efter [[M13 Omskrivningen]].

**Dessa är kvar utan datakälla:** containerns foto och undertitel, kortens framdriftsstapel. Vädret är struket.

---

## Ännu inte issues

**Luckorna i kontot.** Byta lösenord. Byta e-post — egen issue och `risk_class: elevated`, för med tvingande tvåfaktor blir ett e-postbyte utan kodkrav en väg runt andra faktorn, samma klass av hål som issue 80 stängde. Inbjudningar syns i dag bara som mejl och inte när användaren loggar in.

**Verifieringarna.** Att en uppladdning bara lagras en gång (dedup och referensräkning) och att filer inte går att nå obehörigt ska bevisas av bestående tester, inte av en genomgång per release. Båda ytorna är `risk_class: elevated` enligt [[AGENTS.md]] § De tre axlarna.

**Kalenderfeedens namn** hämtas från URL:en i stället för produktnamnet och containerns namn.

**Notiser vid uppgift.** När skickas de? Frågan är först en uppslagning i [[Notiser]] och blir en issue bara om svaret och beteendet går isär.

**Delsträngssök kräver MariaDB i CI.** `LIKE '%ord%'` är dagens beteende via databasdrivaren; FULLTEXT-grenen i [[ADR-0012 Sök]] är avstängd tills sviten kan köras mot MariaDB. Den frågan står redan i [[Tankar]] § Öppet, rest i issue 2 och halvt besvarad 2026-09-03 — den behöver inte resas igen, den behöver avgöras.

**Designsystemet och genomgången vy för vy.** Väntar på mockuparna per definition. Tokens och kärnkomponenter före sidor, annars blir varje vy ett frihandsjobb.

**Miniatyrer i itemlistan** står redan som öppen fråga i [[Tankar]] § Öppet, rest när 61b skrevs. Den avgörs av designen och behöver inget eget spår här.
