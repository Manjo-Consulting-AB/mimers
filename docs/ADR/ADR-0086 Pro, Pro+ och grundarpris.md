# ADR-0086 Pro, Pro+ och grundarpris

**Status:** Antagen 2026-11-18 · Ersätter konsumentnivåerna och ägarbytets bonus i [[ADR-0014 Prismodell]]; B2B-delen där gäller oförändrad · [[ADR-index]]

Tonys beslut 2026-11-18.

## Kontext

[[ADR-0014 Prismodell]] gav två konsumentnivåer: Gratis med 1 GB och Pro med 25 GB för 39–49 €/år, där prispunkten lämnades öppen.

Två saker skaver med det. De flesta behöver inte 25 GB, och den som gör det belastar lagringen hos inleed, där 200 GB finns från start (se [[ADR-0007 Fillagring hos inleed]]). Dessutom vill Tony inte att produkten ska uppfattas som girig. Det talar för ett lägre pris med mindre lagring, och ett högre pris för dem som behöver utrymmet.

Ett rakt lågt pris prövades och valdes bort, se § Alternativ.

## Beslut

**1. Tre konsumentnivåer.**

| | Gratis | Pro | Pro+ |
|---|---|---|---|
| Listpris | 0 | 39 €/år | 69 €/år |
| Grundarpris | — | 19 €/år | 39 €/år |
| Utrymme | 1 GB | 10 GB | 50 GB |

Pro och Pro+ har samma funktioner. Det enda som skiljer dem är utrymmet. Funktionerna och de övriga gränserna är desamma som i ADR-0014: obegränsat antal containers och delning, 64 MB per fil, PDF-pärm, ägarbyte, webhooks, utlåningspåminnelser och kostnadsrapporter. Gratis är oförändrad.

**2. Grundarpriset gäller det första året.** Den som startar en prenumeration inom tolv månader från den dag betalflödet öppnar får grundarpriset. Priset gäller så länge prenumerationen löper. Den som byter från Pro till Pro+ behåller grundarpriset och får grundarpriset för Pro+. Upphör prenumerationen och förnyas inte inom fristen på tre månader (se [[ADR-0009 Kvoter och livscykel]]), förlorar kontot grundarpriset.

**3. Inbjudningskoder kan ge grundarpriset, eller ett annat pris.** En voucher pekar redan på en plan (se [[ADR-0055 Inbjudningskoder och stängd registrering]]). En kod som pekar på en grundarplan ger alltså grundarpriset, oavsett datum. Senare kan vi skapa koder med andra priser utan ny kod: det blir bara nya planrader med `is_public = false`.

**4. Ett pris byts genom en ny planrad, aldrig genom att en befintlig skrivs om.** Grundarpriserna är egna rader, `pro_founder` och `pro_plus_founder`, med `is_public = false` och samma `limits` som `pro` och `pro_plus`. En prenumeration bär sitt pris genom sin `plan_id`. Höjer vi senare listpriset skapas en ny rad, och den gamla sätts till `is_public = false`. Befintliga prenumerationer ligger då kvar på sin rad och sitt pris.

**5. Vi lovar att priset inte höjs för befintliga kunder.** En löpande prenumeration behåller sitt pris. Prisändringar gäller bara nya prenumerationer. § 4 är det tekniska stödet för löftet.

**6. Vi är öppna med vart pengarna går.** En offentlig sida förklarar i klartext vad priset betalar för: lagring i Sverige och Frankrike, backup, support och utveckling. Den förklarar också vad vi inte gör: inga annonser, ingen försäljning av data. Sidan upprepar löftet i § 5 och att export och påminnelser är fria på alla nivåer.

**7. Bonusen vid ägarbyte sänker aldrig planen.** Mottagaren får fortfarande tolv månader, en gång per konto (se [[ADR-0017 Missbruksvektorer]] § 4). Har kontot redan en aktiv Pro- eller Pro+-rad förlängs den raden ett år, på samma plan. Annars ges Pro. Kvotprövningen sker som förut mot mottagarens nuvarande plan, innan bonusen ges. Är containern större än 10 GB behöver mottagaren alltså Pro+ för att kunna ta emot den.

## Motivering

**Grundarpriset ger det låga priset utan att låsa affären vid det.** 19 € ligger nära de 10 € som ADR-0014 valde bort, eftersom intäkten inte bär supporten. Om priset vore 19 € för alla och måste höjas senare, skulle det se ut precis som det Evernote kritiseras för. Ett listpris på 39 € som ligger fast, och en tydlig rabatt för dem som kommer tidigt, ger samma generositet till de första kunderna utan det problemet. Det passar också hur produkten ska marknadsföras det första året: de tidiga användarna belönas för att de tog en chans.

**Girighet uppfattas sällan utifrån prisnivån.** Det som uppfattas som girigt är prishöjningar efter att kunden låst in sig, data som hålls som gisslan och gränser som kommer som en överraskning. Det andra och tredje är redan hanterat: export och påminnelser är gratis, fristen är tre månader och användaren väljer själv vad som ska bort, och kvoten förklaras med ett meddelande. Det första hanteras av § 5. Öppenheten i § 6 gör mer för förtroendet än tio euro hit eller dit.

**Ett för lågt pris är en risk för ett arkiv.** Den som lägger in tio års dokumentation är mest rädd för att tjänsten läggs ner. Ett pris som uppenbart bär driften är en del av löftet.

**10 GB i Pro ger plats för år av ackumulering.** Med 3–5 MB per mobilbild rymmer 10 GB ungefär 2 000–3 000 bilder och dokument. Det räcker för flera objekt under flera år. Den som slår i taket har då mycket historik i appen, och då blir uppgraderingen ett naturligt steg och inte ett hinder. 5 GB hade drabbat det samlade kontot för båt, hus och bil, som Pro positioneras som, för tidigt.

**Pro+ ligger tydligt över Pro.** Med 69 € mot 39 € är steget 30 € för fem gånger så mycket utrymme. Det är lätt att motivera för den som behöver det, men det drar inte alla till Pro+. Risken är att Pro+ jämförs med vanlig molnlagring, där 50–100 GB kostar 10–20 €/år. Den risken accepteras: den som behöver Pro+ har redan sin historik här, och köper sammanhanget runt filerna, inte gigabyten.

**Lagringen är billigare än den ser ut.** Kvoten mäter den logiska storleken, och dedup sänker den faktiska förbrukningen (se [[Planer och kvoter]]). De flesta fyller aldrig sin kvot. Den mindre Pro-kvoten handlar därför mest om kapacitetsplanering hos inleed och om att den som använder mycket också betalar mer. Den betyder lite för kostnaden per kund.

## Konsekvenser

- `plan` får tre nya rader: `pro_plus`, `pro_founder` och `pro_plus_founder`. `pro` får `storage_bytes` sänkt från 25 GB till 10 GB och `price_amount` satt till 3900 EUR. Det kräver en migration och en kodissue.
- **Befintliga konton behöver ingen flytt.** Kontona i systemet har Pro och använder långt under 10 GB, så sänkningen av kvoten drabbar ingen.
- Bonusen i `AcceptOwnershipTransfer` gör idag om en rad med annan plan till Pro. Med Pro+ skulle det sänka planen, så § 7 kräver en ändring i koden.
- Grundaråret räknas från den dag betalflödet öppnar. Datumet bestäms när betalflödet byggs, och skrivs in här och i [[Planer och kvoter]].
- Hur en voucher ger ett **pris** och inte bara en **period** (`duration_days`) bestäms när betalflödet byggs. Planraden finns redan, men kopplingen till Paddle eller Lemon Squeezy gör det inte.
- Sidan om vart pengarna går (§ 6) ska finnas innan betalflödet öppnar. Vad den ska innehålla är en öppen fråga i [[Tankar]].
- Den öppna frågan om prispunkt i [[Översikt]] är besvarad.

## Alternativ

**19 €/år med 5 GB för alla, utan grundarpris.** Tonys första förslag. Valdes bort: det halverar intäkten per kund (17,5 € netto efter avgiften till merchant of record, mot 36,5 €), och ett pris som måste höjas senare ser girigare ut än ett som ligger fast.

**En Pro-nivå på 39 € med 25 GB, som i ADR-0014.** Valdes bort: de som använder lite betalar för de som använder mycket, och kvoten är stor i förhållande till kapaciteten hos inleed.

**Grundarpriset till de N första kunderna.** Valdes bort: det kräver en räknare och ett tal, och ett datum är lättare att marknadsföra. Inbjudningskoderna i § 3 ger den riktade varianten när den behövs.

**Pro+ för 49 € med 50 GB.** Valdes bort: steget från Pro blir bara 10 €, och då får Pro+ en stor del av de kunder som hade klarat sig med Pro.
