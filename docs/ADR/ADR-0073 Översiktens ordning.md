# ADR-0073 Översiktens ordning

**Status:** Antagen 2026-11-12 · Ändrar placeringsreglerna för containerns översikt från issue 288 och 321 ([[M32 Formspråket]], [[M40 Fynd 2026-10-18]]) · Rör inte panelernas innehåll i [[ADR-0050 Desktopdesignen]] § 7 · [[ADR-index]]

Tonys beslut 2026-11-12, efter att översikten ritats olika på staging och i produktion.

## Kontext

Containerns översikt ritar sina paneler i ett rutnät som räknas fram ur innehållet. Platserna sätts med `lg:order-*`, `grid-flow-dense` och spann som växer när en panel saknas. *Costs* ritas inte utan kostnadsrader, och *Recent images* ritas inte utan bilder. Därför flyttar sig *Recent activity* mellan den högra kolumnen och den nedre vänstra, beroende på om containern har bilder. Det är samma kod i båda miljöerna, men två containrar ser olika ut, och användaren kan inte förutse var en panel står.

Tony vill att användaren själv bestämmer ordningen, och att det på sikt går att lägga till och ta bort paneler på översikten.

## Beslut

**1. Ordningen är personens, och gäller alla containrar.** En person har en ordning, och den gäller varje container hon öppnar. Ordningen hör till personen och inte till webbläsaren, som `dismissed_tip`. Containerns egen ordning och en gemensam ordning för medlemmarna ingår inte.

**2. Bara ordning och bredd, inte fri placering.** Översikten är en lista med paneler i personens ordning. Varje panel har en bredd: `narrow` är en kolumn och `wide` är två, i rutnätet om tre kolumner från `lg:`. Panelerna läggs i ordning med `grid-flow-dense`, utan fasta rader och utan radspann. Under `lg:` staplas de i samma ordning. Mobilen följer alltså personens ordning, och har ingen egen.

**3. Panelerna som ingår.** Fem paneler, med en stabil nyckel var:

| Nyckel | Panel | Förvald bredd |
|---|---|---|
| `tasks` | *Upcoming tasks* | `narrow` |
| `costs` | *Costs* | `narrow` |
| `details` | *Container details* | `narrow` |
| `items` | *Items* med *Recent images* under | `wide` |
| `activity` | *Recent activity* | `narrow` |

Förvalet är den ordning tabellen visar. *Recent images* är en del av panelen `items` och flyttas med den, så bilderna står direkt under itemen som issue 321 bestämde. Informationsytan med tipsen ingår inte: den står sist och försvinner när tipsen kryssats bort, och är en vägledning och inte en panel. Vilka paneler som finns, deras nycklar och deras förval står i en enda lista på servern.

**4. En panel står kvar när den är tom.** Varje panel ritas alltid och visar sitt tomma läge med `UiEmptyState`. *Costs* får ett tomt läge och ritas också utan kostnadsrader. Regeln från issue 288 och 321 att rutnätet aldrig lämnar en tom kolumn upphör. Den gick bara att hålla genom att flytta paneler, och en ordning som personen valt får inte flytta sig av sig själv. Ett hål i rutnätet är bättre än en panel som byter plats.

*Recent images* inuti `items` ritas fortfarande inte utan bilder. Det ändrar bara panelens höjd, aldrig någon annan panels plats.

**5. Personen ändrar ordningen i ett redigeringsläge.** En knapp på översikten, *Customize overview*, öppnar läget. I läget har varje panel:
- *Move up* och *Move down*, som knappar med 44 px träffyta, för tangentbord, skärmläsare och mobil. Det är huvudvägen.
- Ett draghandtag från `lg:`, med HTML5-drag. Det är en genväg på skrivbordet. Touch-drag byggs inte.
- En växel mellan `narrow` och `wide`.

Läget har också *Reset to default* och *Done*. Ordningen sparas när personen trycker *Done*, inte vid varje flytt.

**6. Egen kod, inget dragpaket.** Dra-och-släpp byggs med webbläsarens HTML5-drag och pilknapparna. Inget npm-paket läggs till. Ytan är liten, pilknapparna behövs ändå för tillgänglighet, och ett paket hade varit ett nytt beroende i leveranskedjan för en genväg. Säkerhetsgränsen ligger på servern (beslut 7) och är densamma med eller utan paket.

**7. Servern godtar bara den kända listan.** Det enda som korsar förtroendegränsen är listan personen sparar. Servern godtar den bara när den innehåller **varje** känd nyckel exakt en gång och varje bredd är `narrow` eller `wide`. Allt annat avvisas som ett valideringsfel, och då skrivs ingenting. Raden hör alltid till den inloggade personen, och ingen rutt tar ett person-id. Panelens rubrik kommer ur `lang/`, aldrig ur sparad data, och ingenting i läget ritas med `v-html`.

**8. En ny panel läggs sist.** Har personen sparat en ordning och en ny panel läggs till i listan senare, står den nya panelen sist med sin förvalda bredd tills personen flyttar den. En nyckel som tagits bort ur listan hoppas över när ordningen läses. Den raderas vid nästa sparning. Personer utan sparad ordning får förvalet.

**9. Bara webben.** Ordningen sparas och läses genom webbens rutter, som tipsen. API:et får ingen yta för den, eftersom ordningen är en inställning för webbens vy. Behöver en annan klient den senare blir det ett eget beslut.

## Motivering

En ordning som räknas fram ur innehållet kan inte vara förutsägbar. Att den beror på bilder, kostnader och andra data är just det som gjorde att staging och produktion såg olika ut.

En ordning per person för alla containrar täcker det vanliga behovet, att se det man själv bryr sig om först. Den är också billigast: en lista per person, ingen fråga om behörighet och ingen sammanslagning mellan containrar.

Ordning och bredd utan fri placering ger ett rutnät som alltid går att rita, och som går att styra helt med två knappar. Fri placering med kolumner och rader hade behövt ett rutnät som personen ritar själv, och det hade inte gått att använda med tangentbord.

## Konsekvenser

- Issue 350–354 i [[M50 Översiktens ordning]]. Raden står i [[Registerförteckning]].
- En ny tabell, `overview_panel`, i [[Konton och åtkomst]] § overview_panel, med en rad per panel och person. Raderna städas av `DeleteUser`.
- Panellistan bor i `App\Support\Frontend\OverviewPanels`, som `App\Support\Tips` för tipsen.
- `ContainerController::show()` skickar proppen `panels`, en ordnad lista med `{key, width}`. `Overview.vue` ritar panelerna i en loop över proppen i stället för med `lg:order-*`.
- `OversiktensRutnatTest` provar i dag reglerna från issue 288 och 321. Proven skrivs om efter beslut 2 och 4.
- En panel som läggs till senare kräver bara en rad i listan, en komponent och sitt tomma läge.

## Alternativ

**Ordning per person och container.** Valdes bort enligt beslut 1, eftersom det hade krävt en lista per container och person, och samma ändring i varje container.

**Ordning per container, gemensam för medlemmarna.** Valdes bort: det kräver ett beslut om vem som får ändra den, och en gäst hade fått ägarens val.

**Fri placering i kolumner och rader.** Valdes bort enligt motiveringen.

**Behålla regeln om att ingen kolumn står tom.** Valdes bort enligt beslut 4.

**Ett dragpaket som SortableJS.** Valdes bort enligt beslut 6. Bättre touch-drag vägde inte upp ett nytt beroende, och CI skannar ännu inte beroenden (se [[M51 Sårbarhetsskanning]]).

**Spara i `localStorage`.** Valdes bort enligt beslut 1, eftersom ordningen ska följa personen till nästa webbläsare. Infällda paneler på itemets sida sparas i `localStorage`, men det är ett tillfälligt val och inte en inställning.
