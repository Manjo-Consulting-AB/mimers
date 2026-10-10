# ADR-0056 Flytande navigering

**Status:** Antagen 2026-10-10 · Bygger på [[ADR-0021 Frontendteknik]] och [[ADR-0049 Nyligen besökta]] · [[ADR-index]]

## Kontext

Webben körs med Inertia (ADR-0021). En visit byter adress och sidinnehåll utan att ladda om dokumentet. Ändå upplevs varje klick som en ny sida, av tre skäl:

- **Skalet byggs om.** Varje sida lägger sin layout i sin egen mall, så Vue river sidopanel, toppraden och containerns hjälte och bygger upp dem igen vid varje visit.
- **Varje klick väntar på servern.** På delad hosting startar PHP om för varje förfrågan, och inget händer på skärmen innan svaret kommer.
- **Ingenting hämtas i förväg.**

Frågan ställdes också om data kunde sparas i webbläsaren och växa ju mer systemet används, alltså en beständig klientcache.

## Beslut

### 1. Sidan deklarerar sin layout, och layouten står kvar

En sida lägger aldrig en layout i sin mall. Den deklarerar kedjan med `defineOptions({ layout: … })`, och ingen layout ritar en annan. Inertia behåller då instansen av varje layout som är densamma på nästa sida.

Behöver en layout props ur sidan skrivs kedjan som en pilfunktion med en parameter, och sidan skickar exakt de props den behöver. Ett tillstånd i en layout som hör till en enda sida, som en öppen meny, nollställs när `page.url` byts.

### 2. Förhämtning med ett cache i minnet

Inertias förhämtning är påslagen. En förhämtad sida är färsk i 30 sekunder. Mellan 30 sekunder och 5 minuter visas den direkt och hämtas om i bakgrunden. Sidopanelen, containerlistan och mobilens flikrad förhämtar vid hovring. Flikrader förhämtar alla sina flikar när sidan laddats, men bara där varje flik är billig att svara på.

### 3. Varje ändring tömmer cachet

Varje visit som inte är GET tömmer hela cachet. Cachetaggar används inte. En ändring som en annan medlem gör syns när sidan hämtas om enligt punkt 2.

### 4. En sida som skriver när den visas förhämtas inte

Itemsidan skriver ett besök (ADR-0049). En sida som tas ur cachet når aldrig servern, och då skrivs inget besök. Därför förhämtas ingen länk till itemsidan från en annan sida. Servern skriver inte heller något besök för en förfrågan med headern `Purpose: prefetch`. Samma regel gäller varje framtida GET-sida som skriver något när den visas.

### 5. Ingen beständig klientcache

Data sparas inte i webbläsarens lagring mellan sessioner. Cachet lever i minnet, per flik, och försvinner vid omladdning och utloggning.

## Motivering

Punkt 1 och 2 står för nästan hela den upplevda hastigheten. Det mesta av väntan gäller nästa klick, och det löser förhämtningen. Känslan av ny sida kommer av att skalet byggs om, och det löser de layouter som står kvar. Båda ryms i ADR-0021: servern bestämmer fortfarande allt, och ingen klientstore byggs.

En tömning per ändring är grov, men den går att lita på. Med cachetaggar blir varje ny ändring en fråga om vilka sidor den påverkar, och en glömd tagg visar gammal data utan att någon märker det.

## Konsekvenser

- **En ny sida deklarerar sin layout med `defineOptions`.** Proven i `BestaendeSkalTest.php` fäller en sida som lägger layouten i mallen.
- **En ny GET-sida som skriver något när den visas måste ta ställning till förhämtning**, enligt punkt 4.
- **Servern får fler förfrågningar.** Varje hovring och varje flikrad som förhämtar när sidan laddats kostar en förfrågan. Därför förhämtar itemsidans flikar bara vid hovring: de går alla till webbens tyngsta controller.
- **Uppskjutna props** (`Inertia::defer`) tas inte in nu. De övervägs för en enskild vy när dess svarstid är mätt.

## Alternativ

**Beständig klientcache i IndexedDB, local-first.** Data växer på klienten med användningen och visas direkt också efter en kallstart. Valdes bort. I delade containrar visar klienten gammal data, eller data användaren inte längre har åtkomst till, om inte en synkmekanism byggs: servern måste kunna säga vad som ändrats sedan sist. Data blir kvar på delade datorer. Varje ändring i API-resurserna måste också hanteras i klientens lager. Det är den andra kodbas ADR-0021 valde bort, och vinsten är liten när nästa klick redan går fort. Blir offline eller kallstart ett produktkrav ska det få ett eget ADR, och mobilapparna är troligen rätt plats för det.

**Hämta alla flikars innehåll på en gång och visa eller dölj i webbläsaren.** Valdes bort. Den första sidan blir tyngre, och vyn börjar hålla val i minnet, mot ADR-0021.

**Cachetaggar per domän.** Valdes bort enligt Motivering.
