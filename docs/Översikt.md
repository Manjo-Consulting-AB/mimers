# Översikt

Produkten på fem minuter. Tillbaka till [[00 Index]].

## Kärnidén

En **container** är ett sammanhang: en båt, en bil, en fastighet, en kund eller ett projekt. I containern lägger ägaren **objekt** — allt från en värmepump till en garderob. Varje objekt kan ha bilder, text, filer, en kategori, taggar, relationer till andra objekt och ett eller flera **scheman** för återkommande underhåll.

Orden användaren möter — container, objekt, relationer, uppgifter, historik — står i [[ADR-0032 Produktens ord]]. I koden heter objektet `item`, som det alltid gjort.

Systemet vet ingenting om båtar, bilar eller fastigheter. Taggar och kategorier är ett blankt papper som användaren fyller själv, vilket är det som gör samma produkt användbar för en fastighet eller ett projekt. Se [[ADR-0004 Fria taggar och kategorier]].

## Vem betalar

Rollerna nedan är den första marknaden — inte produktens definition. De definieras av hur de förhåller sig till containern, inte av vilken bransch de tillhör. Containern bär vilket sammanhang som helst.

| Roll | Äger containern | Betalar för |
|---|---|---|
| Privatperson | Ja | Fler än en container, mer utrymme, PDF-pärmen |
| Byggare/leverantör (varv, husbyggare) | Tillfälligt, överlämnar vid leverans | Leveransdokument med egen logotyp |
| Förmedlare (fastighetsmäklare, båtmäklare) | Tillfälligt, överlämnar vid affär | Snabbare due diligence, säljargument |
| Förvaltare (servicevarv, fastighetsskötare) | **Nej** — kunden äger, förvaltaren får delegerad åtkomst | Antal containers under förvaltning |
| Flottägare (charterbolag, bilpool) | Ja, permanent | Flottvy och tvärgående frågor över hundratals containers |

Att förvaltaren inte äger containern är ett medvetet val — se [[ADR-0002 Konto äger container]]. Rollnamnen och priserna står i [[ADR-0014 Prismodell]] § Tillägg 2026-11-27.

Priser och gränser: [[Planer och kvoter]] och [[ADR-0014 Prismodell]].

## Teknik

PHP 8.5 + Laravel på inleed.net. MariaDB 11.4, cron varje minut, filer på inleeds lagring i Sverige och Frankrike. Se [[ADR-0001 Stack]] och [[ADR-0007 Fillagring hos inleed]].

API:et är produkten. Frontenden ligger på samma origin, `mimers.app`, och samma API är det mobilapparna kopplar på när de byggs — se [[ADR-0020 Plattformsidentitet och frontendgräns]]. Därför får API:et aldrig innehålla användarvänd text, bara maskinläsbara felkoder. Se [[ADR-0013 Språk och i18n]].

Webben byggs med Inertia och Vue i samma Laravel-app och konsumerar alltså inte `/api` — regeln ovan gäller API-ytan, inte webbsidorna. Se [[ADR-0021 Frontendteknik]].

## Avgränsning

### Ingår i MVP

- Konton, containers, delning med R/RW, inbjudningar
- Objekt med kategorier, taggar, relationer, bilder och filer
- Scheman och uppgifter med förekomster och beroenden
- Fil-dedup via innehållshash
- Planer och kvoter med förbrukningsräkning — **även om betalning inte byggs**
- Notiser via e-post, ICS-kalenderfeed och webhooks
- Kostnadsregistrering per item, med rapporten bakom Pro — se [[ADR-0016 Kostnadsregistrering]]
- Soft delete och papperskorg
- Sök och filtrering
- Engelska

### Ingår inte i MVP

| Utelämnat | Varför |
|---|---|
| 2D-kartor för position i båten | Dyrast att bygga, och en tagg som heter "akterstuv" fyller samma funktion. Säljargument, inte kärnfunktion. |
| Betalningsintegration | Rättighetslagret byggs, betalflödet väntar tills det finns någon att fakturera. |
| B2B-funktioner (flottvy, white-label, personalroller) | Validera med två–tre B2B-kunder som designpartners först. Kontomodellen är dock förberedd. |
| OCR-sökning i PDF:er | Kostar pengar per sida att köra. Pro-funktion senare. |
| Web push, mobilappar | Efter MVP. Webhooks täcker behovet under tiden. |
| Meilisearch | Databasdrivern räcker tills det finns en VPS. |

## Öppna frågor

- B2B-priser är resonerade uppskattningar, inte marknadsdata.
