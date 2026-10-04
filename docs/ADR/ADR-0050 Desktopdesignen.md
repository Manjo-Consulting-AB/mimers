# ADR-0050 Desktopdesignen

**Status:** Antagen 2026-09-30 · § 16 ersatt i frågan om GTD-listorna av [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] · Bygger vidare på [[ADR-0042 Designsystemet]], [[ADR-0038 Gränsen för Pro i kostnaderna]] och [[ADR-0041 Itemets vy]] · [[ADR-index]]

Tonys beslut 2026-09-29 och 30, vid gapanalysen efter [[M23 Mobilen och kartan]] och genomgången av designerns fyra nya bilder: `kostnader.png`, `dokument.png`, `uppgifter_1.png` och `Historik.png` i `docs/Design/`.

## Kontext

M17 byggde tokens, kärnkomponenter, flikrader och trepanelslayouten, och M23 byggde mobilskalet. Desktop fick aldrig sitt skal. `AppLayout` är en vit topprad och en innehållsyta på `max-w-3xl`, medan varje desktopbild ritar en mörk sidopanel och en yta på över 1 200 px. [[ADR-0042 Designsystemet]], [[ADR-0048 Mobilen och plusknappen]] och M17 § 106 skriver om *desktopskalets sidopanel* som om den fanns. Trepanelen för itemet delar 768 px på fyra kolumner.

Gapanalysen gjordes ur koden, inte i webbläsaren. Den hittade också en funktionslucka som inte är en designfråga: kostnadsraderna från M8 går bara att nå genom `/api`. Ingen webbsida skriver en kostnadsrad, trots att dashboardens donut ritar dem.

De fyra nya bilderna ritar containerns flikar *Dokument*, *Uppgifter*, *Kostnader* och *Historik*. Flera av dem förutsätter fält eller begrepp som inte finns.

## Beslut

### Skalet

**1. Desktop får en mörk sidopanel.** Raderna är exakt de som `ShellSections` har i dag: Översikt, Uppgifter, Containers, Överlåtelser, Sök och Inställningar. Under dem står containerlistan grupperad per art enligt [[ADR-0036 Containerns art]], och sist *Nyligen besökta* och *Favoriter*. Bildernas globala rader för *Kalender*, *Kostnader* och *Dokument* tas inte in. Det finns inga sådana globala sidor, och [[ADR-0041 Itemets vy]] har redan avvisat globala rader för struktur och karta. Toppraden bär plusknappen, sökfältet, klockan och avataren. Innehållsytan blir bred.

**2. Containerns hjälte står på alla containersidor.** På översikten är den stor, med talen ovanpå bilden. På de andra flikarna är den lägre. Hjälten visar containerns bild ([[ADR-0047 Containerns bild]]), art, namn och **hela `description` som undertitel**. Beskrivningen delas inte upp (issue 88), och den kortas inte heller. Blir den för lång är det en senare fråga.

**3. *Redigera container* i hjälten leder till inställningssidan.** *Inställningar* lämnar därmed flikraden. Sidan och dess sju sektioner finns kvar oförändrade ([[ADR-0042 Designsystemet]] § Konsekvenser).

**4. Containerns flikar är Översikt, Items, Dokument, Uppgifter, Kostnader och Historik.** Bildernas flik *Underhåll* tas inte in. Uppgifter och underhåll är **en** flik, och skillnaden är ett filter på `recurrence_type`, precis som [[ADR-0042 Designsystemet]] § Bildernas avvikelser säger.

**5. Raden *"Lägg till i denna container"* stryks.** Plusknappen ur [[ADR-0048 Mobilen och plusknappen]] § 2 gör redan samma sak på samma sida. Frågan om ordet *anteckning* i raden försvinner med den.

### Översikterna

**6. Dashboarden får bildens rutnät**: två kolumner och en högerspalt med uppgifterna och händelserna. Panelerna är de M19 byggde. Ingenting nytt räknas.

**7. Containerns översikt får bildens paneler**: kommande uppgifter, kostnadernas fasta summering, items, senaste aktiviteter, containerdetaljer, informationsytan och *Senaste bilder*. *Senaste bilder* är de senaste bilagorna med `kind = image` i containern, genom `ResolveItemScope`. Det kräver ingen ny kolumn.

### Kostnaderna

**8. Kostnadsraderna får ett webbgränssnitt, på itemet.** Itemet får en kostnadsflik där raderna listas, läggs till, ändras och tas bort, med samma regler som `CostEntryController`. *Kostnad* i plusknappens meny och i snabbåtkomsten leder dit. Det här är en funktionslucka och byggs först.

**9. Containerns kostnadsflik följer [[ADR-0038 Gränsen för Pro i kostnaderna]].** Fritt: raderna i en tabell, totalen, *i år* och donuten per item. Pro: periodväljaren, filtren, grafen över tid, nedbrytningen per kategori och jämförelsen mot en annan period (*+12 %*). En gratisanvändare ser en uppgraderingsyta där Pro-delen står. Den gränsen ritas och prövas på servern.

**10. *Kostnader per kategori* grupperar på itemets kategori.** Ingen kategorikolumn på `cost_entry`, precis som [[ADR-0040 Underträdets summor]] säger.

**11. Betalningsmetoden stryks.** Fältet finns inte. Skulle det behövas är det ett beslut, inte en kolumn i förbifarten, samma resonemang som artikelnumret i [[ADR-0042 Designsystemet]].

### Dokumenten

**12. Dokumentfliken filtrerar på `attachment.kind`**: bild, dokument och övrigt. Bildens typer *Manual*, *Kvitto*, *Service* och *Försäkring* finns inte, och riktiga dokumenttyper är ett senare beslut. Övriga filter är item, uppladdare och datum.

**13. *Lägg till dokument* laddar upp till ett item.** En bilaga i containern tillhör alltid ett item, utom containerns egen bild ([[ADR-0047 Containerns bild]]). Knappen låter användaren välja itemet först.

**14. *Senast öppnade* visar användarens egna öppningar**, ur tabellen i [[ADR-0051 Senast öppnade filer]].

**15. Lagringsstapeln visar det konto en uppladdning i containern debiteras.** Kvoter räknas på uppladdande konto. För ägarkontots medlem är det ägarkontot, och för en gäst är det gästens eget. Ingen ser någon annans förbrukning.

### Uppgifterna

**16. Containerns uppgiftsflik är en tavla med de grupper som redan finns**: *Försenade*, *Idag*, *Kommande* och *Klart*, med underhållet som filter. Bildens GTD-listor (Inbox, Next, Waiting, Calendar, Someday) förutsätter status, prioritet, kontext och tilldelning som `schedule_occurrence` inte har. Det är en annan uppgiftsmodell och står i [[Efter MVP]]. *Snabblänkarna* till kalenderflödet och exporten tas in.

### Historiken

**17. Historikfliken grupperas per dag och filtreras på typ, användare, item och datum**, ur händelseloggen i [[ADR-0043 Tre loggar]] och med dess läsregel. Diagrammen *Aktiviteter över tid*, *Aktivitetstyper* och *Senaste aktiva items* är nya aggregat under samma läsregel, så en gäst räknar bara det hen får se. De byggs efter listan. *Exportera historik* tas inte in, eftersom containerexporten redan finns under inställningarna.

## Motivering

**Skalet först, för bredden styr allt annat.** Varje panel i bilderna förutsätter en yta på över 1 200 px. En översikt byggd i 768 px måste göras om när skalet kommer.

**Bilderna är nyare än besluten men inte mer genomtänkta**, samma läsning som i [[ADR-0042 Designsystemet]]. Underhållsfliken, GTD-listorna, dokumenttyperna, betalningsmetoden och kategorikolumnen är alla fält eller begrepp som bilden ritar och datamodellen saknar. Att rita dem vore att lova dem.

**Kostnadsraderna går före designen**, eftersom en webbanvändare i dag kan se kostnader men inte registrera dem. Det är en lucka i det som redan är byggt, inte en ny yta.

## Konsekvenser

- Byggs som [[M24 Desktopdesignen]], issue 168–182.
- `containerSections.js` får flikarna *Dokument*, *Uppgifter* och *Kostnader*, och *Inställningar* lämnar `TAB_KEYS`.
- Ytor som inte har en datakälla ritas inte: containerns framdriftsstapel, vädret, betalningsmetoden, dokumenttyperna, leverantören och artikelnumret i itemets detaljruta.
- De 82 komponenter som bär råa Tailwind-färger migreras när deras sida byggs om, enligt [[ADR-0042 Designsystemet]] § Konsekvenser. Itemets sektioner har flest och får en egen issue.
- GTD-tavlan står i [[Efter MVP]].
- Gapanalysen gjordes ur koden. Proportioner och luft bedöms mot skärmdumpar när skalet är byggt.

## Alternativ

**Ta in bildernas globala sidopanel med Kalender, Kostnader och Dokument.** Valdes bort. Det finns inga sådana globala sidor, och [[ADR-0041 Itemets vy]] har redan avvisat samma sorts navigering.

**Bygga GTD-tavlan som bilden ritar den.** Valdes bort för MVP. Den kräver en ny uppgiftsmodell, inte en ny vy.

**Rita kostnadsfliken helt fri.** Valdes bort. Allt frågbart är Pro enligt [[ADR-0038 Gränsen för Pro i kostnaderna]], och den gränsen ändras inte av en bild.

## Uppföljning 2026-10-04 — GTD-listorna

§ 16 sköt GTD-listorna till efter MVP. [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] tar in dem före lansering: listan lagras på förekomsten, och Calendar och Done härleds. Tavlan som vy är fortfarande ett senare steg. Containerns flik är en lista sedan issue 225.
