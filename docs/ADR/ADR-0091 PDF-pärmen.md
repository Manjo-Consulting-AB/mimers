# ADR-0091 PDF-pärmen

**Status:** Antagen 2026-11-22 · Bygger vidare på [[ADR-0014 Prismodell]], [[ADR-0086 Pro, Pro+ och grundarpris]], [[ADR-0080 Ny förälder från kartan]], [[ADR-0070 Subprocesser hos inleed]] och [[ADR-0031 Köarbetaren körs av schemaläggaren]] · [[ADR-index]]

Tonys beslut 2026-11-22.

## Kontext

- [[ADR-0014 Prismodell]] och [[ADR-0086 Pro, Pro+ och grundarpris]] lovar en utskrivbar PDF-pärm i Pro, men inget beslut säger vad pärmen är.
- *Tony 2026-11-22:* en pärm gör Mimers användbart utanför appen, till exempel när en båt, en husvagn eller en bil säljs. Köparen får något att bläddra i utan att ha ett konto.
- Tony godkände förslagen nedan samma dag och bestämde själv att användaren väljer om bilagor slås ihop eller läggs som länkar.

## Beslut

**1. Pro.** Pärmen grindas av `pdf_binder` på containerns ägarkonto (Pro och Pro+). Den som har `view` får beställa, och får det hen själv når: beställarens `ItemScope`, som exporten (issue 74 § Beslut 8).

**2. En pärm är en export.** Raden i `export` har `kind = binder`, byggs i kön, står i samma lista och har samma nedladdning och samma sju dagar. Den räknas inte mot kvoten: den är en tillfällig artefakt, som ZIP-exporten.

**3. Innehållet.** Försättsblad (containerns namn, bild, beskrivning, egna fält, ägare och datum), innehållsförteckning, och items i hierarkisk ordning efter *Parent*/*Child* ([[ADR-0080 Ny förälder från kartan]]). Per item: beskrivning, anteckning, egna fält, relationer, scheman med genomförda förekomster, lån och itemets egen historik. Sidnummer genom hela pärmen.

**4. Användaren väljer sektioner.** Allt är med som förval utom **kostnader**, som är av: en pärm för en försäljning ska inte visa vad saker kostade om ingen bett om det.

**5. Historik** betyder genomfört underhåll och itemets egna händelser, inte containerns eller kontots logg.

**6. Bilagor väljs per item**, inga som förval. Användaren väljer **slå ihop** (PDF-sidor och bilder läggs in i pärmen) eller **länkar** (förval). En länk pekar på bilagan i Mimers och kräver inloggning och åtkomst; vid en försäljning når köparen den efter ägarbytet. Filtyper som inte går att lägga in, PDF:er som inte går att läsa och bilagor över taket för sammanslagning står alltid som länk.

**7. Ingen extern process.** dompdf ritar sidorna och FPDI sätter ihop dem, ren PHP ([[ADR-0070 Subprocesser hos inleed]]). Bygget arbetar del för del inom arbetarens minne ([[ADR-0031 Köarbetaren körs av schemaläggaren]]).

**8. En pärm för ett item** byggs med samma motor: itemets försättsblad, ingen förteckning och inga barn.

**9. Notis.** `binder.ready` visas i appen när pärmen är klar.

## Konsekvenser

- `export` får `kind`, `item_id` och `options`. [[Filer och lagring]] § export beskriver dem, och [[Notiser]] listar den nya typen.
- Gratisversionen av FPDI läser inte PDF:er med komprimerad xref-tabell, så en del bilagor blir länkar i stället för sidor. Det betalda tillägget, eller en flytt till egen server där en extern process går att köra, kan ändra det senare.
- Minnet på delad hosting sätter ett praktiskt tak för stora pärmar.
- Agenter (MCP) kan inte beställa pärmar. Det kan läggas till senare.
- Byggs i [[M73 PDF-pärmen]].
