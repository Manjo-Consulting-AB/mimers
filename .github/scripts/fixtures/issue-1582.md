### Milstolpe

M81 Sök och hjälp i sammanhanget · backlog-issue 468

### Mål

Appens vyer kan länka till hjälpen för det de visar utan att känna till någon artikeladress: `/help/context/{sammanhang}` slår upp artikeln vars `context` bär sammanhanget och skickar vidare. Komponenten `HelpLink` är det enda sättet att bygga en sådan länk, och ett prov fäller en PR där en vy använder ett sammanhang som inte finns i innehållet.

**Rutten** `help.context`: `GET /help/context/{context}`, `where('context', '[a-z][a-z_.]*[a-z]')`, utanför `auth` och `guest`, före `help.category`. `HelpCatalog::byContext(string $context): ?array{article: HelpArticle, anchor: ?string}` läser `context`-fälten (`container.sharing` eller `account.security#two-factor`; ankaret är del av värdet i front matter, inte av adressen). Träff ger 302 till `/help/articles/{id}` med `#ankare` om det finns. Okänt sammanhang ger 302 till `/help` och en rad i loggen på nivån `info` med sammanhanget. 470 räknar båda.

**Sammanhangen är inte notistyper.** `container.sharing` och de andra liknar notistypernas namn, och omfångslinten tror att de är det. De har ingenting med `app/Models/Notification.php` att göra; rör inga notisfiler.

**`resources/js/components/HelpLink.vue`.** Props: `context` (obligatorisk) och `label` (valfri, standard `help.link.default`, *Help*). Ritar en `<a :href="`/help/context/${context}`">` — vanlig länk, inte Inertias `Link`, för att servern ska få omdirigera — med ikonrollen `help` (finns sedan 458) och etiketten. `target="_blank"` och `rel="noopener"`, så att den som står mitt i en dialog inte tappar den; `aria-label` säger att länken öppnas i en ny flik (`help.link.new_tab`). Länken är minst 44 px som tryckyta. En variant `iconOnly` ritar bara ikonen med etiketten som `aria-label`.

**Provet** `tests/Feature/Hjalp/SammanhangTest.php`: läs alla `.vue` och `.js` under `resources/js/`, plocka ut varje `context="…"` på en `HelpLink` (statiska strängar; ett bundet `:context` är förbjudet och provet fäller det), och kräv att varje sammanhang finns i `HelpLibrary::catalog('en')`. Lägg en testfil i en fixtur så att provet visar att det fäller ett okänt sammanhang.

### Läs

[[ADR-0094 Hjälpcentret]] § 6. Hjälp i sammanhanget
[[ADR-0094 Hjälpcentret]] § 3. Adresserna

`resources/js/icons/index.js` (rollen `help`), `app/Support/Help/HelpCatalog.php`

### In scope

```shell
routes/web.php
app/Http/Controllers/HelpController.php
app/Support/Help/HelpCatalog.php
resources/js/components/HelpLink.vue
lang/en/ui.php
tests/Feature/Hjalp/SammanhangTest.php
tests/Feature/Hjalp/fixtures/**
```

### Omfångsläge

spårad

### Out of scope

```shell
resources/js/pages/**
lang/en/help/**
docs/**
package.json
```

Lägg inte in `HelpLink` i någon vy än. Det är 469.

### ambiguity

low

### blast_radius

cross-module

### risk_class

none

### Granskning

Sonnet

### Klart när

- [ ] `GET /help/context/container.sharing` ger 302 till `/help/articles/how-do-i-share-a-container`.
- [ ] Ett sammanhang med `#ankare` i front matter (testkatalog) ger 302 till artikeln med ankaret.
- [ ] `GET /help/context/finns.inte` ger 302 till `/help` och en loggrad med sammanhanget.
- [ ] `GET /help/context/<script>` och `/help/context/A.B` ger 404 (mönstret).
- [ ] `SammanhangTest` är grön mot dagens kod, och fäller fixturen med ett okänt sammanhang och fixturen med `:context`.
- [ ] Källkodsprov: `HelpLink.vue` ritar en `<a>` (inte `Link`) med `target="_blank"`, `rel="noopener"` och rollen `help`.
- [ ] `help.link.default` och `help.link.new_tab` finns i `lang/en/ui.php`.
- [ ] `GenomgangTest` är grön.
- [ ] Hela sviten, pint och phpstan är gröna, och bygget går igenom.

### Beror på

#1578

### Delmoment

- [ ] En session
