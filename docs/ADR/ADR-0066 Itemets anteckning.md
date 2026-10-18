# ADR-0066 Itemets anteckning

**Status:** Antagen 2026-10-19 · Ändrar issue 96 och 102: anteckningen redigeras på itemets sida och inte i *Edit item*, och den ritas som Markdown · [[ADR-index]]

Tonys beslut 2026-10-19, efter fynden på v0.24.0.

## Kontext

Itemets `notes` är en TEXT-kolumn som bara går att skriva i *Edit item*. På översikten ritas den som ren text med `whitespace-pre-line`, i samma `<dl>` som beskrivningen och ovanför snabbfakta. Den som vill skriva en rad om itemet måste alltså öppna formuläret, skriva och spara hela itemet.

Tony vill att anteckningen fungerar mer som i Evernote: den är öppen och redo när itemet öppnas, sparas av sig själv och tillåter enkel formatering.

## Beslut

**1. Anteckningen är Markdown i ett vanligt textfält.** Ingen WYSIWYG-editor. Kolumnen är oförändrad, så ingen migration behövs. Det som redan står i `notes` är redan giltig Markdown och ritas som förut, med radbrytningarna kvar.

**2. Servern ritar Markdown till HTML.** Det sker med `Str::markdown()`, alltså med `league/commonmark`, som redan följer med Laravel. Inget nytt paket behövs. Inställningarna är:
- `html_input: escape`, så att HTML i texten ritas som text
- `allow_unsafe_links: false`, så att `javascript:` och liknande inte blir länkar
- GitHub-varianten (listor, tabeller, genomstrykning, autolänkar och kryssrutor som bara går att läsa)
- `ExternalLinkExtension`, så att en länk öppnas i en ny flik med `rel="noopener noreferrer nofollow"`

Ritningen bor i en Action, `App\Actions\Item\RenderItemNotes`, och används både av sidan och av svaret när anteckningen sparas. `/api` returnerar `notes` som rå text, som i dag.

**3. Den ritade anteckningen visas med `v-html` i en enda komponent, `ItemNotes.vue`.** Förbudet mot `v-html` i [[ADR-0057 Ikoner och typsnitt]] § 2 gäller ikonernas SVG och står kvar. Det här undantaget gäller bara HTML som servern har ritat enligt § 2. Klienten ritar aldrig Markdown själv. Typografin (rubriker, listor, kod, citat, tabeller och länkar) står i en egen klass i `resources/css/app.css` och använder designsystemets token. Ett typografiplugin hade varit ett nytt npm-paket.

**4. På itemets översikt flyttas anteckningen ned, och beskrivningen och snabbfakta läggs ihop.** Ordningen uppifrån är:
1. raden med omslagsbilden till vänster och, till höger, beskrivningen ovanför snabbfakta. Utan bild tar beskrivningen och snabbfakta hela bredden.
2. *All fields*
3. anteckningens ruta
4. *Recent activity*

**5. Den som har `update` på itemet redigerar direkt i rutan.** Rutan visar den ritade anteckningen. Ett klick i rutan, utom på en länk, eller Enter när rutan har fokus, gör den till ett textfält med markören i texten. När fältet tappar fokus, eller vid Esc, ritas den igen. Är anteckningen tom står ett textfält med platshållartext direkt, så att man kan börja skriva utan att först klicka fram det. Under rutan står en kort rad om att Markdown fungerar.

**6. Anteckningen sparas av sig själv.** Den sparas en sekund efter att man slutat skriva, och direkt när fältet tappar fokus. Den skickas till `PATCH /containers/{container}/items/{item}/notes` med Inertias `useHttp`, som en vanlig HTTP-förfrågan och inte som ett sidbesök. Sidan laddas alltså inte om, och det man skriver medan en sparning pågår står kvar. Svaret är JSON med den ritade anteckningen. En rad bredvid rutan visar *Saving…*, *Saved* eller *Not saved* (med *Try again*). Lämnar man sidan medan en sparning väntar varnar webbläsaren.

**7. Den som sparar sist vinner.** Ingen versionskontroll och ingen sammanslagning. Redigerar två samtidigt skriver den senare sparningen över den förra.

**8. Den som saknar `update` ser den ritade anteckningen i samma ruta, utan textfält.** Är anteckningen tom ritas ingen ruta.

**9. Anteckningen lämnar *Edit item*.** Formuläret skickar inte längre `notes` när ett item ändras. Annars hade formulärets gamla text skrivit över det som sparats av sig självt. När ett item skapas finns fältet kvar. `UpdateItemRequest` tar fortfarande emot `notes`, så `/api` och webbens PATCH fungerar som förut.

**10. Autosparningen loggas som en händelse per arbetspass, inte en per sparning.** En sparning som ändrar `notes` skriver `item.updated` med `meta.changed: ['notes']`, som i dag. Undantaget är när itemets senaste händelse redan är en sådan, av samma användare och yngre än tio minuter. Då skrivs ingen ny rad. Utan den regeln hade historiken fått en rad per paus i skrivandet. Precis som förut följer anteckningens text inte med i loggen.

## Konsekvenser

- Issue 324–325 i [[M42 Itemets anteckning]].
- En ny rutt, `containers.items.notes.update`, en ny Action, `UpdateItemNotes`, med logglagret i § 10, och `RenderItemNotes`.
- `Show.vue` får komponenten `ItemNotes.vue` och en ny ordning på översikten. Sidproppen får `notesHtml`.
- `ItemForm` skickar inte `notes` i redigeringsläget.
- Schemats och inkorgsuppgiftens `notes` ändras inte. De är fortfarande ren text.

## Alternativ

**En WYSIWYG-editor (TipTap eller ProseMirror).** Valdes bort för nu: ett större beroende, en verktygsrad, tangentbordet och mobilen att hantera, och fler tester. Lagras Markdown kan editorn läggas till senare utan att någon data flyttas.

**Ritning i klienten med `markdown-it` och `DOMPurify`.** Valdes bort: det är två nya npm-paket, och saneringen hade legat i webbläsaren. Servern har redan `league/commonmark`.

**Optimistisk låsning med `updated_at`.** Valdes bort av Tony. Den som sparar sist vinner.

**Spara med Inertias `router.patch`.** Valdes bort: ett sidbesök laddar om sidans props för varje paus i skrivandet, och utan `preserveState` hade fältet ritats om medan man skriver.
