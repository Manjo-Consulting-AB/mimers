# ADR-0006 Innehållsadresserad lagring

**Status:** Antagen 2026-08-03 · [[ADR-index]]

## Kontext

Från [[Tankar]]: när en fil laddas upp verifieras hashen mot befintliga filer. Är den redan sparad skapas bara en länk. När den sista länken raderas försvinner filen. Syftet är att aldrig lagra samma fil två gånger.

Tusentals båtägare laddar upp samma Victron-manual, samma Volvo Penta-serviceschema, samma Garmin-handbok.

## Beslut

Filer identifieras av **SHA-256 av innehållet**. `stored_file` är bytena med en referensräknare; `attachment` är kopplingen till ett item med användarens eget filnamn.

**Hashen beräknas alltid på servern.** En hash som kommer från klienten accepteras aldrig.

Dedupen är **osynlig för användaren**. Hon laddar upp sin fil och är nöjd. Systemet sköter resten i bakgrunden.

## Motivering

Besparingen är verklig och växer med antalet användare. Marginalen på Pro-abonnemanget förbättras påtagligt när femhundra användare med samma manual kostar en manual.

Att hashen måste beräknas serverside är en **säkerhetsfråga, inte en optimering**: accepterar systemet en klientberäknad hash kan vem som helst skapa en `attachment` mot en annan användares fil genom att gissa hashen, och därmed läsa innehåll hen inte har rätt till.

## Konsekvenser

- **Radering av en fil frigör ofta noll byte** eftersom andra refererar samma hash. Kvoten mäter därför *logisk* storlek — vad användaren upplever sig lagra — medan faktisk diskförbrukning är lägre. Håll isär talen: fakturera på det första, kapacitetsplanera på det andra.
- Referensräknaren minskas när en attachment lämnar papperskorgen, inte vid soft delete. Annars försvinner bytena medan användaren tror att filen går att återställa.
- Filer är **oföränderliga**. Det gör dem perfekta för inkrementell backup med rsync, se [[ADR-0015 Backup]].
- Fysisk radering fördröjs minst 30 dagar efter att räknaren nått noll.
- `storage_path` delar hashen i prefix (`files/ab/cd/…`) så att ingen katalog får hundratusen poster.

## Alternativ

**Dedup per konto istället för globalt.** Undviker frågan om att dela lagring mellan användare. Valdes bort — större delen av besparingen ligger just i att samma manual delas mellan många.

**Ingen dedup.** Enklast. Valdes bort — lagringskostnaden är en av få rörliga kostnader i affären.
