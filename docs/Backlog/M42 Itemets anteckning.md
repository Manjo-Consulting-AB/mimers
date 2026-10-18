# M42 · Itemets anteckning

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-19, efter Tonys fynd på v0.24.0. Det här ingår: anteckningen redigeras direkt på itemets översikt, sparas av sig själv och ritas som Markdown. Den hamnar ovanför *Recent activity*, och beskrivningen och snabbfakta läggs ihop. Besluten står i [[ADR-0066 Itemets anteckning]].

**Ordningen:** 325 bygger på 324.

**Detta ingår inte:** schemats och inkorgsuppgiftens `notes`, en WYSIWYG-editor, versionskontroll vid samtidig redigering och `/api`.

---

### 324. Anteckningens rutt, ritning och logg

GitHub #960. **Ritningen.** En ny Action, `App\Actions\Item\RenderItemNotes`, tar en sträng eller `null` och returnerar HTML eller `null` ([[ADR-0066 Itemets anteckning]] § 2). Den använder `Str::markdown()` med `html_input: escape`, `allow_unsafe_links: false` och `ExternalLinkExtension` (ny flik, `rel="noopener noreferrer nofollow"`). En tom eller blank anteckning ger `null`. `ItemController::show()` lägger till sidproppen `notesHtml` och sidproppen `item.notes` står kvar som rå text.

**Rutten.** `PATCH /containers/{container}/items/{item}/notes`, med namnet `containers.items.notes.update` (§ 6). Grinden är `update` på itemet. En ny `UpdateItemNotesRequest` tar `notes` som `nullable|string`, samma regel som `UpdateItemRequest`. Svaret är JSON: `{ "notes": …, "notes_html": … }`. Ett fel i valideringen svarar 422 med fälten, och en saknad behörighet svarar 403. Det som sparas är bara `notes`. Kategorin, taggarna och omslaget rörs inte.

**Loggen.** En ny Action, `App\Actions\Item\UpdateItemNotes`, skriver `notes` och loggar i en transaktion (§ 10). Ändrar sparningen ingenting skrivs ingen rad. Annars skrivs `item.updated` med `meta.changed: ['notes']`, utom när itemets senaste händelse redan är `item.updated` med `meta.changed` lika med `['notes']`, av samma användare och yngre än tio minuter. Då skrivs ingen ny rad. Texten följer aldrig med i `meta`, samma regel som i `UpdateItem`.

**Läs:** [[ADR-0066 Itemets anteckning]] § 2, 6, 7 och 10, `app/Actions/Item/UpdateItem.php` (docblocken), `app/Http/Controllers/ItemController.php` (`show()` och `update()`), `app/Http/Requests/Item/UpdateItemRequest.php`

**Klart när:** `RenderItemNotes` ritar rubriker, listor, länkar, kod och tabeller; HTML i texten ritas som text; en `javascript:`-länk blir ingen länk; en extern länk öppnas i ny flik med `rel="noopener noreferrer nofollow"`; tom text ger `null`; `notesHtml` finns i sidproppen; `PATCH …/notes` sparar bara `notes` och svarar med `notes` och `notes_html`; utan `update` svarar den 403; två sparningar inom tio minuter av samma användare ger en loggrad; en sparning efter tio minuter, eller av en annan användare, ger en ny; en sparning utan ändring ger ingen rad; texten finns aldrig i `meta`; hela testsviten är grön.

**Beror på:** —

### 325. Anteckningens ruta på itemets översikt

GitHub #961. **Ordningen.** Översiktsfliken i `Containers/Items/Show.vue` byggs om enligt [[ADR-0066 Itemets anteckning]] § 4:
1. raden med omslagsbilden till vänster och, till höger, beskrivningen ovanför `ItemQuickFacts`. Utan bild tar beskrivningen och snabbfakta hela bredden.
2. *All fields*
3. `ItemNotes`
4. `ItemActivityPanel`

`<dl>` med beskrivningen och anteckningen tas bort. Regeln för `overviewEmpty` är oförändrad.

**Komponenten.** `ItemNotes.vue` tar `item`, `notesHtml`, `canEdit` och adressen till rutten i 324 (§ 3, 5, 6 och 8).
- **Läsläget** är en ruta i `UiCard`-form med rubriken *Notes* och den ritade HTML:en med `v-html`. Det här är det enda stället i koden där `v-html` används för innehåll, och docblocken säger varför.
- **Med `canEdit`** gör ett klick i rutan, utom på en länk, eller Enter när rutan har fokus, rutan till ett `<textarea>` med markören i slutet av texten. Fältet växer med texten. När det tappar fokus, eller vid Esc, ritas läsläget igen, med svarets `notes_html`. En tom anteckning visar fältet direkt, med platshållaren *Write a note…*. Under fältet står *Markdown supported*.
- **Sparningen** sker med `useHttp` från `@inertiajs/vue3`, en sekund efter sista tangenttrycket och direkt när fältet tappar fokus. Bara en förfrågan åt gången: kommer en ny ändring medan en sparning pågår skickas den när den första är klar. Raden bredvid rubriken visar *Saving…*, *Saved* eller *Not saved* med knappen *Try again*. `beforeunload` varnar medan en ändring väntar.
- **Utan `canEdit`** ritas läsläget utan att gå att klicka på. En tom anteckning ritar ingen ruta.

Typografin för den ritade HTML:en står i en klass i `resources/css/app.css`, med designsystemets token.

**Formuläret.** `ItemForm` ritar inte fältet *Notes* i redigeringsläget och skickar inte `notes` när ett item ändras (§ 9). När ett item skapas finns fältet kvar. Orden kommer ur `lang/en/ui.php`.

**Läs:** [[ADR-0066 Itemets anteckning]] § 3–9, `resources/js/pages/Containers/Items/Show.vue` (översiktsfliken och dess docblock), `resources/js/components/ItemQuickFacts.vue`, `resources/js/components/ItemForm.vue` (docblocken om `notes`), `resources/js/components/UiCard.vue`

**Klart när:** översikten visar bild, beskrivning och snabbfakta i en rad, sedan *All fields*, anteckningen och *Recent activity*; anteckningen ritas som Markdown; den som har `update` kan klicka i rutan och skriva; texten sparas en sekund efter sista tangenttrycket och när fältet tappar fokus, utan att sidan laddas om; statusraden visar *Saving…*, *Saved* och *Not saved* med *Try again*; en tom anteckning visar fältet direkt för den som har `update` och ingen ruta för den som saknar det; den som saknar `update` ser den ritade anteckningen utan fält; *Edit item* har inget fält för anteckningen och skickar inte `notes`; formuläret för ett nytt item har kvar fältet; hela testsviten är grön.

**Beror på:** 324
