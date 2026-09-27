# ADR-0049 Nyligen besökta

**Status:** Antagen 2026-09-27 · Bygger vidare på [[ADR-0048 Mobilen och plusknappen]] och [[ADR-0045 Radering av konto och person]] · [[ADR-index]]

Tonys beslut 2026-09-27, vid genomgången av mobilmockupen (bild 10) och vid planeringen av [[M23 Mobilen och kartan]].

## Kontext

Mobilmockupens sidomeny har en sektion *Nyligen besökta*: de items man senast öppnade, med namn och container. På en telefon sparar den flest tryck av allt i bilden, eftersom vägen till ett item annars går genom containern och trädet.

Ingenting i datamodellen svarar på frågan. `user.last_active_at` är en tidpunkt och inte en plats. Händelseloggen i [[ADR-0043 Tre loggar]] loggar ändringar, inte visningar, och ska inte börja göra det, eftersom den har en annan livslängd och en annan läsregel.

## Beslut

**Listan lagras på servern, per person.** En tabell `recent_visit`:

| Kolumn | Typ | Not |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| user_id | FK → user | |
| item_id | FK → item | |
| visited_at | TIMESTAMP | UTC |
| created_at, updated_at | | |

Unik nyckel `(user_id, item_id)` och index `(user_id, visited_at)`. Ingen `ulid`, eftersom raden aldrig syns utåt: listan visar itemets ULID och namn. Ingen `deleted_at`, eftersom raden inte är innehåll.

**En visning skriver en rad, och ett nytt besök uppdaterar den.** Skrivningen sker när itemets sida visas, i controllern och efter behörighetsgrinden, som en upsert på `(user_id, item_id)`. Den sker inte i en middleware, eftersom bara itemsidan räknas. Containerns sidor, sökträffar och kartan räknas inte.

**Varje person har högst 20 rader.** När en ny rad skrivs raderas de äldsta utöver 20, i samma förfrågan. Det behövs inget schemalagt jobb.

**Listan filtreras när den läses, aldrig när den skrivs.** Läsningen går genom `ResolveItemScope`, som favoritlistan i issue 106. Ett item man förlorat åtkomsten till, eller som ligger i papperskorgen, försvinner ur listan utan att någon räknare avslöjar det. Raden står kvar och blir synlig igen om åtkomsten kommer tillbaka. Listan visar högst tio items.

**Listan laddas först när den behövs.** Den är en valfri Inertia-prop som hämtas med en partiell omladdning när menyn öppnas, eller en gång när sidopanelen på desktop ritas. Den är inte en delad prop som frågas på varje sida.

**Den visas i skalet på båda skärmstorlekarna.** På mobilen står den i sidomenyn enligt [[ADR-0048 Mobilen och plusknappen]] § 1. På desktop står den i sidopanelen, direkt ovanför favoriterna. Varje rad visar itemets namn, containerns namn och hur länge sedan besöket var. Den visar inte vägen genom trädet, eftersom ett item kan ha flera ([[ADR-0041 Itemets vy]]).

**Raderna är personuppgifter.**
- **Personradering:** raderna raderas med personen och står i listan över det som bara är personens ([[ADR-0045 Radering av konto och person]] § Uppföljning 2026-09-27).
- **Item och container:** när ett item rensas ur papperskorgen raderas dess rader, och likaså när en container rensas. Främmande nycklar med RESTRICT kräver det, och ett item som inte finns ska inte ha någon historik.
- **Export:** exporten tar inte med raderna. Den exporterar containerns innehåll, inte vad en viss person tittat på.
- **Registerförteckningen** får en rad: syfte bekvämlighet, gallring de 20 senaste, rättslig grund berättigat intresse.

## Motivering

Att lagra listan i webbläsaren kostar ingenting på servern, men då följer den inte med mellan telefon och dator, och den visar namn på items man inte längre når. Ett namn i `localStorage` finns kvar efter att en åtkomst dragits in, och det kan läsas av den som tar över webbläsaren.

Taket på 20 gör tabellen begränsad per person utan gallringsjobb, och att filtrera vid läsning gör att en återfådd åtkomst inte tappar listan.

## Konsekvenser

- Byggs som [[M23 Mobilen och kartan]] § 160.
- [[Konton och åtkomst]] får avsnittet `recent_visit` och [[Registerförteckning]] en rad när § 160 byggs.
- En skrivning per visning av itemsidan. Skrivningen är en indexerad upsert och en begränsad DELETE, och den räknas med i frågeräkningsproven.
