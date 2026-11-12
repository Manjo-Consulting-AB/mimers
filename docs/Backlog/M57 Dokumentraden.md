# M57 · Dokumentraden

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-12, efter Tonys genomgång av dokumentraden på containerns *Documents*-flik. Han hittade fyra saker: det finns ingen lightbox för filerna, *Download* öppnar filen i webbläsaren i stället för att ladda ner den, *Download* har ett annat typsnitt än de andra länkarna i raden, och raden bär fyra länkar som bryts i en smal kolumn. Orsaken till den andra står i uppföljningen 2026-11-12 i [[ADR-0019 Filleverans]].

Tonys två beslut: **samma rad på *Documents* och på itemets bilageflik**, och **en fil som inte går att visa har ett namn som inte är klickbart**. Nedladdningen sker då med *Download*.

**Releasen:** en, när 371–373 är mergade. Issuerna skrevs direkt i GitHub (#1172, #1173, #1174) och är sammanfattade här.

---

### 371. Download laddar ner i stället för att öppna filen

GitHub #1172. **Läget:** med filoriginet påslaget levererar `files.deliver` alltid `inline` för typer på tillåt-listan, så *Download* öppnar bilder och PDF:er i webbläsaren. Leveransen vet inte om användaren bad om att se eller att ladda ner.

**Lösningen:** ett nedladdningsuppsåt som servern signerar in i URL:en. `/files/{ulid}?download=1` ger en signerad URL som bär `download=1`, och filoriginet levererar då `attachment`. Parametern kan bara skärpa leveransen, aldrig vidga den till `inline`. Varje *Download*-länk i `Documents.vue` och `ItemAttachmentSection.vue` får `?download=1`, och länkar som visar en fil rörs inte.

**Läs:** [[ADR-0019 Filleverans]] § Uppföljning 2026-11-12 och § Uppföljning 2026-08-31

**Klart när:** `/files/{ulid}?download=1` ger en 302 till en URL som bär `download=1`; `files.deliver` svarar `attachment` med den, också för typer på tillåt-listan; utan `download` är leveransen oförändrad; en `download` som lagts till efter signeringen ger 403; varje *Download*-länk bär `?download=1`.

**Beror på:** —

### 372. Filnamnet öppnar filen i en lightbox

GitHub #1173. **Läget:** visaren finns bara på itemets bilageflik, och där finns både en miniatyr, en knapp *Preview* och ett filnamn som gör samma sak eller ingenting. På *Documents* finns ingen visare.

**Lösningen:** visaren bryts ut till `AttachmentViewer.vue` och används på båda flikarna. Namnet och miniatyren är öppnaren när filen går att visa (bild eller PDF, och bara när filoriginet är påslaget), och namnet är ren text annars. *Preview* tas bort.

**Läs:** `ItemAttachmentSection.vue` (visaren), `attachmentPresentation.js`, `Containers/Documents.vue` (kolumnen *File*)

**Klart när:** båda flikarna använder `AttachmentViewer`; ett klick på namnet eller miniatyren öppnar visaren för bilder och PDF; en fil som inte går att visa har ett namn som är ren text; *Preview* är borta; Escape och stängknappen stänger visaren och fokus går tillbaka till namnet.

**Beror på:** 368, 371

### 373. Download och More på dokumentraden

GitHub #1174. **Läget:** sista kolumnen bär upp till fyra textlänkar (*Download*, *Move…*, *Back to Inbox*, *Copy…*) som bryts i en smal kolumn, och *Download* saknar `text-sm font-medium`. *Delete* finns bara på bilagefliken.

**Lösningen:** två knappar i slutet av raden, *Download* och *More*, i `AttachmentRowActions.vue`. *More* öppnar en högerställd meny med *Move…*, *Back to Inbox*, *Copy…* och *Delete*, som visas efter `can.delete` och `containerIsInbox`. *Delete* är nytt på *Documents* och frågar först med `UiConfirmDialog`. Samma komponent ritar raden på bilagefliken.

**Läs:** `Containers/Documents.vue` (sista kolumnen), `ItemAttachmentSection.vue`, `Containers/Costs.vue` (mönstret för `<details>`), `UiConfirmDialog.vue`

**Klart när:** varje rad slutar med *Download* och *More* med samma klasser; menyn visar rätt rader efter `can.delete` och `containerIsInbox`; ett val stänger panelen och kör handlingen; *Delete* på *Documents* frågar, raderar och landar på fliken; en användare utan `can.delete` ser bara *Copy…*.

**Beror på:** 372
