# M85 · Om Mimers

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-24 ur feature request #1594, nedbruten samma dag med Tonys svar. `/about` går från platshållaren *This page is coming soon.* (issue 202) till berättelsen om Mimers: varför tjänsten finns, vad den gör, namnet och vem som driver den. Tre beslut samma dag formar issuen:

- **Texten är markdown, inte nycklar.** Informationssidorna bär sin text i `lang/{locale}/pages/{sida}.md` och ritas på servern med samma `HelpMarkdown` som hjälpartiklarna ([[ADR-0094 Hjälpcentret]] § 4). `ui.php` används inte, och `Info.vue` bär ingen copy. `/privacy` och `/terms` följer samma form när deras texter skrivs (#1600).
- **Kontaktlänken pekar på en sida som inte finns.** *Get in touch* länkar till `/contact`, och kontaktsidan med formuläret är en senare issue. Både #1594 och #1600 förutsätter ett befintligt kontaktformulär, och det finns inget.
- **Kontoexporten är struken.** Meningen om att ta *a complete copy of your account* står inte i texten, eftersom kontoexport inte finns. Exporten gäller en container och PDF-pärmen.

**Ordningen:** 478 står ensam. Issuen är skapad i GitHub (#1617) och sammanfattad här. GitHub-issuen är bindande.

---

### 478. Om Mimers — berättelsen på /about

GitHub #1617. `lang/en/pages/about.md` bär texten ordagrant, utan `# About Mimers`: sidans `<h1>` står kvar i `Info.vue` ur `info.about.title`, och texten börjar med `## Structure on your terms.`. `app/Support/InfoPage.php` läser `lang_path("{$locale}/pages/{$page}.md")` med `en` som reserv och ritar den med `HelpMarkdown::toHtml()` — samma regler som hjälpartiklarna, alltså `html_input: strip`, rubrikankare och inga osäkra länkar. Saknas filen ger den `null`. Ruttstängningen för `about`, `privacy` och `terms` skickar `body` vid sidan av `page`; listan, adresserna och ruttnamnen är oförändrade, så `/privacy` och `/terms` ritar platshållaren som förut och #1600 kan lägga till `privacy.md` utan ny kod. `Info.vue` ritar `body` med `v-html` i ett block med `help-content`, läsbredd och centrering, och platshållaren när `body` saknas. *Get in touch* är en vanlig markdownlänk till `/contact`; issuen skapar ingen rutt för den och ingen e-postadress står på sidan.

**Läs:** [[ADR-0094 Hjälpcentret]] § 4, [[ADR-0066 Itemets anteckning]] § 3, `resources/js/pages/Info.vue`, blocket för informationssidorna i `routes/web.php` (issue 202), `toHtml()` i `app/Support/Help/HelpMarkdown.php`, konstruktorn och `catalog()` i `app/Support/Help/HelpLibrary.php`, `.help-content` i `resources/css/app.css`, `tests/Feature/Frontend/InfosidorTest.php`

**Klart när:** `GET /about` ger `Info` med `page = about` och en `body` med varje `##`-rubrik från *Structure on your terms.* till *Built by Manjo Consulting AB*, *Mímir* och *Mímisbrunnr* under *A name rooted in wisdom*, och *Get in touch* som länk till `/contact` utan `mailto:` eller `@`; ingen *copy of your account*, och exportmeningen nämner en container och PDF-pärmen; gäst och inloggad får `200` och samma `body`; `/privacy` och `/terms` ger `body = null` och ritar platshållaren med ruttnamnen oförändrade; `InfoPage` ger `null` utan fil, faller tillbaka på `en` för en annan locale och släpper inte igenom rå HTML; `Info.vue` bär ingen engelsk copy och ritar `body` med `v-html` i `help-content`; bygget och sviten är gröna.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** —
