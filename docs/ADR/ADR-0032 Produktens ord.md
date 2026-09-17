# ADR-0032 Produktens ord

**Status:** Antagen 2026-09-17 · [[ADR-index]]

Fattat efter M10, när hela webbfrontenden var byggd och orden gick att läsa på riktigt. Kompletterar [[ADR-0013 Språk och i18n]], som beslutade *vilka språk* användaren möter men inte *vilka ord*.

## Kontext

Systemet har hittills burit tre ordförråd för samma sak.

**Dokumentationen och koden** säger `container` och `item`. Det är [[Översikt]]s kärnidé, det är tabellnamnen, och det är vad [[AGENTS.md]] § Språk i koden kräver av varje identifierare.

**Den svenska gränssnittstexten** säger *pärm* om containern — `nav.containers` är `'Pärmar'`, `container-created` är `'Pärmen är skapad.'` — men **item** om itemet: `item.index.title` är `'Items'` och knappen heter `'Nytt item'`. Samma vy blandar alltså ett svenskt ord ur en metafor med ett engelskt ord ur datamodellen.

**Den engelska gränssnittstexten** säger *binder*: `'Binders'`, `'The binder for the boat, the caravan, the house and the car.'`. Den översätter metaforen i stället för att använda ordet systemet självt bygger på.

Pärmmetaforen valdes aldrig i ett beslut — den växte fram i marknadsföringstexten och letade sig in i språkfilerna. Den kostar på två sätt. Användaren möter två ord för samma sak beroende på vilken vy hon står i, och den som läser en bugganmälan måste översätta *pärm* till `container` för att hitta koden.

## Beslut

**Ett ordförråd, samma begrepp på båda språken:**

| Begrepp | Svenska | Engelska | Koden |
|---|---|---|---|
| Det övergripande sammanhanget | **Container** | **Container** | `container` |
| Något som hör till containern | **Objekt** | **Item** | `item` |
| Hur objekt hör ihop | **Relationer** | **Relations** | `item_link` |
| Sådant som ska göras | **Uppgifter** | **Tasks** | `schedule`, `schedule_occurrence` |
| Sådant som har hänt över tid | **Historik** | **History** | `audit_log`, avslutade förekomster |

Ett **objekt** är något som hör till containern och kan bära information, filer, uppgifter, kostnader, relationer och historik.

**Ordet *pärm* utgår ur gränssnittet** — på båda språken, i varje sträng användaren möter, och i den prosa som beskriver ytorna.

**Identifierare rörs inte.** `container` och `item` är redan rätt ord i koden, och [[AGENTS.md]] § Språk i koden gäller oförändrat: engelska i identifierare, svenska i prosa. Ingen tabell, kolumn, rutt, klass eller felkod byter namn av det här beslutet.

**Skrivna beslut skrivs inte om.** ADR:er och stängda milstolpars backlogfiler är historik och behåller sin ordalydelse; att redigera dem vore att dölja vad som faktiskt beslutades. Ny text använder de nya orden.

## Motivering

Container och objekt är ordpar som fungerar på båda språken utan att översättas fel, och de är de ord systemet redan är byggt av. Avståndet mellan vad användaren ser och vad utvecklaren läser blir noll.

Pärmen beskriver dessutom fel sak. En pärm samlar papper; en container samlar objekt som i sin tur bär filer, uppgifter, kostnader och historik. Metaforen bär bara det första lagret, och den som möter den förväntar sig ett dokumentarkiv — inte ett underhållssystem.

*Objekt* framför *sak*, *pryl* eller *föremål*: de tre sista är alla fysiska, och ett objekt kan lika gärna vara en försäkring, ett garantibevis eller en mätpunkt. Objektet är dessutom ordet som redan används om saken i [[Översikt]] § Kärnidén.

## Konsekvenser

- `lang/sv/ui.php`, `lang/sv/notiser.php`, `lang/en/ui.php` och övriga språkfiler byter ord. Nycklarna står still — `nav.containers` heter fortfarande så, den bär bara ett nytt värde.
- Testerna som påstår något om en sträng följer med i samma ändring.
- Kommentarer och docblock i `app/`, `routes/`, `resources/js/` och `tests/` talar om containers och objekt. Det är prosa, inte kod, och ändras utan att en rad beteende rörs.
- [[Översikt]] § Kärnidén skrivs om så att *objekt* betyder en sak i dokumentationen: containern är **ett ägt ting** — en båt, en husvagn — och objekten är det som ligger i den.
- PDF-**pärmen** ([[Efter MVP]], Pro) är kvar som funktionsnamn tills den byggs. Den byter namn i den issue som bygger den, inte i förväg.
- `docs/Konkurrens.md` och annan marknadsföringstext som säljer pärmmetaforen är en produktfråga och inte en gränssnittsfråga — den tas när den skrivs om, se [[Tankar]].

## Alternativ

**Behålla pärmen och översätta item till svenska.** Hade gett ett konsekvent svenskt gränssnitt — *pärm* och *sak*. Valdes bort: avståndet till koden blir kvar, och varje bugganmälan måste översättas i båda riktningarna.

**Byta ordet i koden i stället.** Att döpa om `container` till `pärm` går emot [[AGENTS.md]] § Språk i koden och vore en migrering genom hela datamodellen för att bevara en metafor ingen beslutat om.

**Låta det vara.** Billigast i dag. Valdes bort — priset betalas varje gång någon skriver en ny sträng och måste gissa vilket av tre ordförråd som gäller i just den vyn.
