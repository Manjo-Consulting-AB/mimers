# ADR-0103 Finances över hela kontot

**Status:** Antagen 2026-11-28 · Bygger på [[ADR-0038 Gränsen för Pro i kostnaderna]] och [[ADR-0102 Transaktioner på items]], som båda gäller oförändrat · [[ADR-index]]

Tonys beslut 2026-11-28.

## Kontext

- Huvudmenyn har `/tasks` och `/documents`, som visar containerns flik över alla containrar användaren når. Finances har ingen sådan sida. Den som vill se vad allt hon äger kostar och ger måste öppna en container i taget.
- Containerns flik *Finances* ([[ADR-0050 Desktopdesignen]] § 9–11, issue 175 och 176) har två halvor. Den fria halvan är raderna, brickorna *Totalt* och *I år* och donuten per item. Pro-halvan är periodväljaren, filtren, grafen över tid, nedbrytningen per kategori och jämförelsen mot en annan period. Pro-halvan är alltså rapportvyn, men den når bara en container åt gången.
- Kostnadsrapporten i API:et, `GET /api/containers/{container}/costs/report`, är också låst till en container (issue 46 § Beslut 1).
- Motorn klarar redan flera containrar. `CostReport::summaryForContainers()` tar en lista med omfång, ett per container, och dashboardens donut per container bygger på den.

## Beslut

### Riktning 1, en sida på toppnivå

`GET /finances`, med ruttnamnet `finances`, är containerns flik *Finances* över hela kontot. Den har samma förhållande till fliken som `/documents` har till containerns dokumentflik. Raden i huvudmenyn ligger direkt efter *Documents*. Sidan heter *Finances*, precis som fliken ([[ADR-0102 Transaktioner på items]] Riktning 6).

### Riktning 2, urvalet är det användaren når

- Sidan visar transaktioner i varje container användaren når, med samma urval som hjältens månadssumma (`accessibleBy` och `listable`) och samma omfång som fliken. Omfånget löses upp per container med `ResolveItemScope`, så åtkomsten på itemnivå ([[ADR-0028 Åtkomst på itemnivå]]) gäller rad för rad.
- Grinden är `auth` och inget mer, samma linje som `/documents`. En användare utan containrar får en tom sida och inget fel.
- Inboxen ingår inte. Den bär inga transaktioner ([[ADR-0054 Inboxen]]).

### Riktning 3, den fria halvan

Den fria halvan har samma innehåll som flikens fria halva, men över alla containrar:

- **Brickorna *Totalt* och *I år*** visar per valuta utgifter, intäkter och netto, enligt [[ADR-0102 Transaktioner på items]] Riktning 5. Har användaren inga intäkter visas ingen nettorad, precis som på fliken.
- **Donuten är nedbruten per container** och räknar bara utgifter, som dashboardens donut.
- **Raderna** visas i en tabell med en kolumn för container. Den sorteras på datum, nyast först, och sidas om femtio.

Ingenting i den fria halvan kan användaren ställa frågor till. Rutten läser ingen parameter för den, utom `?page=`.

### Riktning 4, den betalda halvan är rapportvyn

Pro-halvan är flikens Pro-halva med två tillägg:

- Filtret `container[]`, med ULID:er för containrarna.
- Grupperingen *per container* i nedbrytningen, bredvid *per kategori*.

Allt annat är flikens: period, filter på item, kategori, motpart och riktning (`kind`), grafen över tid, nedbrytningen och jämförelsen mot en lika lång föregående period. Filtret gäller tabellen, grafen och nedbrytningen men inte brickorna, eftersom brickorna är fasta. Det är samma regel som på fliken.

**Kategorier och taggar hör till en container** ([[ADR-0004 Fria taggar och kategorier]]). Två containrar kan ha var sin kategori som heter *Motor*, och de är inte samma kategori. Därför gäller filtret på kategori och tagg, och nedbrytningen per kategori, bara när exakt en container är vald i `container[]`. Annars döljs de, och nedbrytningen är *per container*. Ett kategorifilter i querysträngen utan exakt en container ignoreras utan fel. Motparten är fritext och filtreras över alla containrar.

Det är här rapportvyn bor. Containerns flik behåller sin Pro-halva för en container. `/finances` är samma rapport där containern är ett filter i stället för en del av adressen.

### Riktning 5, rätten till rapporten hör till containern

Plangrinden läses på **containerns konto**, som på fliken (issue 176 § Beslut 4) och i `CostReportController`. Den läses inte på användarens eget konto. Så går det till:

- **Rapportens radmängd** är bara de containrar vars konto har `cost_reports`. En gäst med ett gratiskonto får alltså rapporten över en Pro-ägares container som delats med henne, precis som på fliken i dag. Har hon ett eget Pro-konto får hon inte rapporten över en gratisägares container.
- **Containrar utanför rapporten** syns ändå i den fria halvan, alltså i brickorna, donuten och tabellen utan filter. Rapporten säger hur många containrar som inte ingår och varför. Den hoppar inte tyst över dem.
- **När ett filter är satt** följer tabellen rapportens radmängd. Rader från containrar utanför rapporten filtreras inte, de visas inte alls. Annars hade ett filter svarat på en fråga som ägaren inte betalat för.
- **Ingår ingen container i rapporten** blir `report` `null` och sidan visar uppgraderingsytan. Filtret i querysträngen ignoreras då utan fel och utan 403, som på fliken (issue 176 § Beslut 2).

### Riktning 6, valutan

Summor grupperas per valuta och räknas aldrig om ([[ADR-0016 Kostnadsregistrering]], [[ADR-0037 Valutans arv]]). En användare med SEK i en container och EUR i en annan får två rader i varje bricka och en donut per valuta.

### Riktning 7, ingångarna

Hjältens kostnadssummering på dashboarden (`HeroCosts`, [[ADR-0090 Dashboardens hjälte]]) blir en länk till `/finances`. I dag är den ingen länk. På sidan klickar man på en tårtbit i donuten per container, och det sätter `container[]` till den containern. För en gratisanvändare ignoreras filtret enligt Riktning 5. Hon stannar på den fria halvan, med Pro-märkningen synlig ([[ADR-0038 Gränsen för Pro i kostnaderna]] § "Visa mer" är grinden).

## Medvetet utanför

- **En REST-rutt och ett MCP-verktyg för rapporten över flera containrar.** Sidan lånar motorn från `CostReport`, på samma sätt som fliken gör, och API:ets rapport står kvar oförändrad för en container. En rapport över flera containrar i API:et och MCP har en egen fråga: vems plan gäller för en token? Den frågan tas när någon ber om den.
- **Omräkning mellan valutor**, med samma skäl som i [[ADR-0016 Kostnadsregistrering]].
- **Att registrera från sidan.** En transaktion hör till ett item. Sidan länkar till itemets flik och har inget eget formulär.
- **Sparade rapporter och export ur sidan.** Exporten är redan fri ([[ADR-0014 Prismodell]]) och sker per container.

## Motivering

**Samma mönster för tredje gången.** `/tasks` och `/documents` lärde användaren att en sida på toppnivå är flikens innehåll över allt hon når. Bryter Finances mönstret måste användaren lära sig en undantagsregel.

**Rapportvyn får en plats utan att få en egen meny.** Rapporten över en container finns redan på fliken. Det rapporten saknar är svaret på frågan över allt användaren äger. Det är också den rapport en Pro-användare med flera containrar har mest nytta av, och den passar naturligt i en vy som ändå redan visar alla containrar.

**Den fria halvan försvagar inte Pro.** [[ADR-0014 Prismodell]] kallar *"total kostnad över allt du äger"* ett Pro-argument. [[ADR-0038 Gränsen för Pro i kostnaderna]] § Motivering visar att gratisnivåns enda container redan gör det jobbet, och ADR-0038 gör dashboardens summa över alla containrar och donuten per container gratis. Brickorna och donuten på `/finances` är samma fasta summering i en större yta. Det som är Pro är att ställa frågor till den, och det ändras inte.

**Rätten hör till containern, eftersom allt annat läcker.** Hade användarens eget konto avgjort hade en gäst med Pro kunnat ställa frågor till en gratisägares data, som ägaren aldrig betalat för att låta någon fråga. Containerns konto är redan grinden på fliken och i API:et. En andra regel för samma rader hade gett två svar på samma fråga beroende på vilken sida frågan ställdes från.

## Konsekvenser

- En ny rutt `/finances` och en sida `Finances/Index`. Kontrollern är tunn ([[ADR-0024 Tunna controllers och actions]]). Urvalet och summorna kommer från `ListContainerCosts` och `CostReport`, som vidgas från en container till en lista med omfång.
- `CostReport` behöver en `build()` över flera containrar och grupperingen per container. `summaryForContainers()` finns redan och används av brickorna.
- En ny rad i `ShellSections.vue` och en ny nyckel under `nav.*` i `lang/`.
- `HeroCosts` blir en länk, enligt Riktning 7.
- Issuerna som kan påverka säkerhet eller betalning är `risk_class: elevated`. Det gäller grinden per container i Riktning 5 och testet att ett filter aldrig når en container utanför rapporten.
- Inget i datamodellen ändras.

## Alternativ som valts bort

- **Bara en lista utan rapport.** Det hade gett ett fönster till och lämnat rapporten över hela kontot obyggd. Valdes bort på Tonys beslut 2026-11-28.
- **Rapportvyn på en egen sida, till exempel `/reports`.** Det hade blivit två sidor med samma rader, en fri och en betald, och en meny till. Gränsen mellan det fasta och det frågbara går redan inom en sida på fliken.
- **Plangrinden på användarens konto.** Den läcker frågbarhet till en gratisägares data. Se Motivering.
- **Utelämna containrar utan Pro helt från sidan.** Det hade gjort den fasta summan beroende av andra ägares planer, och sidan hade visat en lägre total än dashboarden.
