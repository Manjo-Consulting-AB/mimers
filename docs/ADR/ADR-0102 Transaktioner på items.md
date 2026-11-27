# ADR-0102 Transaktioner på items

**Status:** Antagen 2026-11-27 · Vidgar [[ADR-0016 Kostnadsregistrering]] från kostnader till transaktioner; övriga beslut i ADR-0016 och [[ADR-0038 Gränsen för Pro i kostnaderna]] gäller · [[ADR-index]]

Tonys beslut 2026-11-27.

## Kontext

- Itemets kostnadsflik visar bara kostnader.
- Ett item kan vara vad som helst, så det kan också ge pengar: en aktie som köps och säljs, en kund som debiteras eller en motor som servas.
- Kostnadsraden ska därför bli en **transaktion** som kan gå åt båda hållen.

## Beslut

Utformat i issue 526–532 i [[M99 Transaktioner på items]].

### Riktning 1, en transaktion har en riktning

`cost_entry` får `kind` ENUM(`expense`, `income`), NOT NULL. Befintliga rader blir `expense`. Förvalet är `expense` både i formuläret och i API:et.

### Riktning 2, tecknet gäller inom riktningen

`amount` får fortfarande vara negativt. En kreditfaktura är en negativ *utgift* och ingen intäkt. Netto per item och valuta är summan av intäkterna minus summan av utgifterna, och inget annat. Rapporten håller därmed isär "fick 500 kr tillbaka på impellern" och "sålde aktier för 500 kr".

### Riktning 3, motpart i stället för leverantör

`supplier` döps om till `counterparty`. Motparten kan vara en leverantör, en kund eller en köpare. Den är fortfarande fritext, och autocomplete hämtar värden från containerns rader **med samma riktning**. I API:ets svar står `supplier` kvar som alias för `counterparty` i 90 dagar.

### Riktning 4, antal

En ny kolumn `quantity` DECIMAL(18,6) NULL, för 100 aktier, 3 timmar eller 40 liter. Styckpriset lagras inte utan räknas fram vid visning, som belopp delat med antal. Ett antal ändrar ingen summa.

### Riktning 5, summeringarna

De fasta summeringarna ([[ADR-0038 Gränsen för Pro i kostnaderna]]) bär per valuta **utgifter, intäkter och netto**. Donutarna och nedbrytningarna per item och per container ([[ADR-0040 Underträdets summor]]) räknar **bara utgifter**, eftersom en tårta av netton med negativa bitar inte går att läsa. Dashboardens hjälte och månadssumman ([[ADR-0090 Dashboardens hjälte]]) visar fortfarande kostnader. Pro-gränsen gäller oförändrat: det fasta är fritt och det frågbara är Pro. Rapportvyn får `kind` som ett filter och kolumnerna utgift, intäkt och netto.

### Riktning 6, ytan

Fliken heter *Finances* på itemet och på containern. Formuläret får ett val *Expense / Income* med *Expense* förvalt. Har ett item inga intäkter ser fliken ut som i dag, med samma rubriker, samma summa och ingen nettorad. Den som bara loggar kostnader ska inte märka ändringen, och produkten ska fortfarande vara neutral utåt.

### Riktning 7, API och MCP

Det som läggs till är fritt, och det som bryter avvecklas på 90 dagar ([[ADR-0087 MCP-server för agenter]] § Riktning 15). MCP får `transactions_create`, `transactions_list`, `transactions_update` och `transactions_delete` med `kind`, `quantity` och `counterparty`. `costs_*` står kvar i 90 dagar med beskrivningen *"Deprecated, use transactions_…"* och läser och skriver bara utgifter. Samma princip gäller REST: `/transactions`-rutter läggs till, och `/costs`-rutterna står kvar i 90 dagar och visar bara utgifter.

### Riktning 8, det interna namnbytet

`cost_entry` döps om till `item_transaction` och `CostEntry` till `ItemTransaction` i koden, i en egen issue som kommer sist (532). Inget annat i M99 beror på den. `transaction` används inte som tabellnamn, eftersom det är ett nyckelord i SQL.

## Medvetet utanför

Samma linje som [[ADR-0016 Kostnadsregistrering]]: kostnadslogg, inte bokföring.

- Orealiserat värde och marknadspris, alltså en värdering av itemet, som är en annan fråga.
- FIFO, snittkostnad och skatteberäkning.
- Fakturastatus, betald eller obetald, som kan läggas till senare som en nullbar `settled_on`.
- Moms, med skälen i [[ADR-0016 Kostnadsregistrering]].

## Motivering

Modellen bar redan det mesta: raden hänger på ett item, ärver kategori och taggar och tillåter negativa belopp. En kolumn och ett namnbyte räcker. En egen tabell för intäkter hade gett två registreringsflöden för samma sak, samma skäl som [[ADR-0016 Kostnadsregistrering]] ger mot kostnader på containernivå.

## Konsekvenser

- Issue 526–532 i [[M99 Transaktioner på items]].
- Tabellen i [[Items och organisation]] § cost_entry får `kind` och `quantity`, och `supplier` blir `counterparty`.
- Den som bara loggar kostnader märker ingen skillnad.
- `costs_*` och `/costs` avvecklas efter 90 dagar.

## Alternativ som valts bort

- **Tecknet som riktning**, där positivt är intäkt och negativt är utgift: då går kreditfakturan inte att skilja från en intäkt.
- **En egen intäktstabell:** två registreringsflöden för samma sak.
- **Döpa om `costs_*` utan avvecklingstid:** det strider mot [[ADR-0087 MCP-server för agenter]] § Riktning 15.
