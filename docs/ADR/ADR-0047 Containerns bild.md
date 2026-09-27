# ADR-0047 Containerns bild

**Status:** Antagen 2026-09-27 · Besvarar frågan om containerns hjältebild och kortens foto i [[ADR-0042 Designsystemet]] § Bildernas avvikelser · Bygger vidare på [[ADR-0041 Itemets vy]] och [[ADR-0045 Radering av konto och person]] · [[ADR-index]]

Tonys beslut 2026-09-27, vid genomgången av mobilmockupen och vid planeringen av [[M23 Mobilen och kartan]].

## Kontext

Tre mockuper av fem ritar en bild på containern: dashboardens kort, containerns hjältebild och, sedan 2026-09-27, varje rad i containerlistan på mobilen. Frågan har stått öppen sedan 2026-09-18, i två formuleringar som är en och samma: `attachment.item_id` är `NOT NULL`, så en container kan inte äga en fil.

Itemet har redan en omslagsbild. `item.cover_attachment_id` från issue 93 är en nullbar pekare till en av itemets egna bilagor. Allt som gör en fil säker och billig, alltså dedup, referensräkning, miniatyrer, virusskanning, kvot och leverans, hänger på `attachment`.

## Beslut

**En bilaga kan tillhöra en container.** `attachment` får en nullbar `container_id`, `item_id` blir nullbar, och ett CHECK-villkor kräver att **exakt en** av dem är satt. Nyckeln är `ON DELETE RESTRICT` som alla andra. Indexet blir `(container_id, deleted_at)`.

**Containerns bild är en pekare, som itemets.** `container.cover_attachment_id` är en nullbar FK till en bilaga vars `container_id` är containerns egen. Ett CHECK-villkor kan inte uttrycka *"egen"*, så det upprätthålls i den action som sätter pekaren, precis som för itemet.

**En containerbilaga är alltid en bild, och det finns högst en levande per container.** Uppladdningen godtar bara det som `StoreAttachment` redan klassar som `kind = image`. Den som byter bild ersätter den gamla. Mer än så behövs inte i dag. Dokument och bildpaneler på containernivå, som containermockupen ritade, är en egen fråga och kan byggas på samma kolumn senare utan ny migrering.

**Allt annat är som för en vanlig bilaga.** Samma dedup på innehållshash, samma `reference_count`, samma miniatyrer, samma virusskanning och samma storleksgräns. `billed_account_id` är det uppladdande kontot, och bytena räknas mot dess kvot ([[Planer och kvoter]]).

**Vem som får göra vad:**
- **Se bilden** får den som når containern över huvud taget, även med åtkomst till ett enda item. Bilden är containerns ansikte, och den som ser containerns namn i listan ser också dess bild.
- **Byta eller ta bort** den får den som får ändra containerns namn (`ContainerPolicy::update`).
- **Leveransen** går genom samma rutter som bilagor ([[ADR-0019 Filleverans]]). Grinden blir `view` på containern när `container_id` är satt och `view` på itemet när `item_id` är satt, och det avgörs i ett enda ställe, inte på varje anropsställe.

**En borttagen bild går inte till papperskorgen.** Den rensas direkt med `PurgeAttachment`, som minskar `reference_count`. Bytena ligger ändå kvar i minst 30 dagar enligt [[Filer och lagring]] § Radering. En containerbild är inget man vill ångra; man laddar upp den igen. Det följer regeln i [[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26: papperskorgen tar emot det man kan vilja ångra, inte egenskaper.

**Livscykeln följer containern.** När en container rensas ur papperskorgen rensas dess bilagor med. Vid kontoradering följer en främmande containerbilaga containern på samma sätt som en itembilaga ([[ADR-0045 Radering av konto och person]] § Beslut 1). Exporten tar med bilden.

**Ytan är två vägar till samma val.** En penna på bilden överst i containern, och ett eget avsnitt under containerns inställningar. Båda öppnar samma val: *Ta ett foto*, *Välj från enheten* och *Ta bort bilden*. Utan bild visas en neutral yta med artens ikon, aldrig en tom ram.

## Motivering

Alternativet som ligger närmast till hands, en egen pekare från containern direkt till `stored_file`, låter mindre men är större. Det ändrar vad `reference_count` betyder, *"antal levande `attachment`-rader"*, och det mitt under issue 148, som ska bevisa just den räkningen. Varje väg som i dag räknar referenser skulle behöva en andra källa, och så skulle kvoten, exporten och kontoraderingen också behöva det.

Med en nullbar `container_id` bär `attachment` fortfarande all logik. Det som ändras är frågan *"vilket item tillhör bilagan?"*, och den ställs på ett fåtal ställen. Fem anropar `$attachment->item` direkt 2026-09-27.

## Konsekvenser

- Ändringen rör filleverans och är `risk_class: elevated`. Datamodellen och grinden är [[M23 Mobilen och kartan]] § 158, ytan § 159.
- **§ 158 väntar på issue 148 och 149 i [[M22 Redo för testare]].** Bevisen för referensräkningen och filåtkomsten ska finnas innan bilagan får en andra ägare. Det är just de proven som säger om 158 bröt något.
- Varje läsare av `attachment` som förutsätter ett item måste klara `item_id = NULL`: leveransens grind, resurserna, exporten, kontoraderingens flytt av `billed_account_id`, missbruksrapporten och papperskorgens listning. Papperskorgen ska aldrig visa en containerbilaga.
- Dashboardkortens foto och containerns hjältebild får sin datakälla. Framdriftsstapeln är fortfarande struken.
- [[Filer och lagring]] § attachment och [[Konton och åtkomst]] § container skrivs om när § 158 byggs.

## Alternativ

**`container.cover_stored_file_id`, en pekare direkt till bytena.** Valdes bort av skälet under Motivering: den ger `reference_count` två källor.

**Välj bilden bland containerns items bilder.** Ingen ny kolumn på `attachment`, bara en pekare till en befintlig bilaga. Valdes bort: den som bara har åtkomst till ett item skulle se en bild från ett item hen inte når ([[ADR-0028 Åtkomst på itemnivå]]), och den som vill ha en bild på båten måste först skapa ett item att lägga den på.
