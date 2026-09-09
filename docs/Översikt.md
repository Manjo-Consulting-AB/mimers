# Översikt

Produkten på fem minuter. Tillbaka till [[00 Index]].

## Kärnidén

En **container** är ett ägt objekt: en båt, husvagn, stuga eller bil. I containern lägger ägaren **items** — allt från en MPPT-regulator till en garderob. Varje item kan ha bilder, text, filer, en kategori, taggar, relationer till andra items och ett eller flera **scheman** för återkommande underhåll.

Systemet vet ingenting om båtar. Taggar och kategorier är ett blankt papper som användaren fyller själv, vilket är det som gör produkten lika användbar för en husvagn. Se [[ADR-0004 Fria taggar och kategorier]].

## Vem betalar

| Segment | Äger containern | Betalar för |
|---|---|---|
| Privatperson | Ja | Fler än en container, mer utrymme, PDF-pärmen |
| Nybyggnadsvarv | Tillfälligt, överlämnar vid leverans | Leveransdokument med egen logotyp |
| Mäklare | Tillfälligt, överlämnar vid affär | Snabbare due diligence, säljargument |
| Servicevarv | **Nej** — kunden äger, varvet får delegerad åtkomst | Antal båtar under förvaltning |
| Charterbolag | Ja, permanent | Flottvy och tvärgående frågor över hundratals båtar |

Att servicevarvet inte äger containern är ett medvetet val — se [[ADR-0002 Konto äger container]].

Priser och gränser: [[Planer och kvoter]] och [[ADR-0014 Prismodell]].

## Teknik

PHP 8.4 + Laravel på inleed.net. MariaDB 10.6, cron varje minut, filer på inleeds lagring i Sverige och Frankrike. Se [[ADR-0001 Stack]] och [[ADR-0007 Fillagring hos inleed]].

API:et är produkten. Frontenden ligger på samma origin, `mimers.app`, och samma API är det mobilapparna kopplar på när de byggs — se [[ADR-0020 Plattformsidentitet och frontendgräns]]. Därför får API:et aldrig innehålla användarvänd text, bara maskinläsbara felkoder. Se [[ADR-0013 Språk och i18n]].

Webben byggs med Inertia och Vue i samma Laravel-app och konsumerar alltså inte `/api` — regeln ovan gäller API-ytan, inte webbsidorna. Se [[ADR-0021 Frontendteknik]].

## Avgränsning

### Ingår i MVP

- Konton, containers, delning med R/RW, inbjudningar
- Items med kategorier, taggar, relationer, bilder och filer
- Scheman och uppgifter med förekomster och beroenden
- Fil-dedup via innehållshash
- Planer och kvoter med förbrukningsräkning — **även om betalning inte byggs**
- Notiser via e-post, ICS-kalenderfeed och webhooks
- Kostnadsregistrering per item, med rapporten bakom Pro — se [[ADR-0016 Kostnadsregistrering]]
- Soft delete och papperskorg
- Sök och filtrering
- Svenska och engelska

### Ingår inte i MVP

| Utelämnat | Varför |
|---|---|
| 2D-kartor för position i båten | Dyrast att bygga, och en tagg som heter "akterstuv" fyller samma funktion. Säljargument, inte kärnfunktion. |
| Betalningsintegration | Rättighetslagret byggs, betalflödet väntar tills det finns någon att fakturera. |
| B2B-funktioner (flottvy, white-label, personalroller) | Validera med två–tre varv som designpartners först. Kontomodellen är dock förberedd. |
| OCR-sökning i PDF:er | Kostar pengar per sida att köra. Pro-funktion senare. |
| Web push, mobilappar | Efter MVP. Webhooks täcker behovet under tiden. |
| Meilisearch | Databasdrivern räcker tills det finns en VPS. |

## Öppna frågor

- Exakt prispunkt inom 39–49 €/år.
- B2B-priser är resonerade uppskattningar, inte marknadsdata.
