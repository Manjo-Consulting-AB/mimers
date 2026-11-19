## Issue

Fristående · [[ADR-0074 Registrering i vyn]] § 7, avgjort av Tony 2026-11-18

Closes #1401

## Sammanfattning

`N` är appens första globala kortkommando, och det bor i skalet.

- **`resources/js/layouts/AppLayout.vue`** — `onKeydown` läggs på `window` i `onMounted` och tas bort i `onBeforeUnmount`. Hanteraren frågar **samma `create`-propp som plusknappen ritar ur** och har ingen egen logik för vad som skapas: `kind: 'inline'` → `request()` ur `useInlineCreate` (samma anrop som knappens klick), `kind: 'menu'` → `openCreateMenu()` med den synliga plusknappen som trigger, `href` → `router.visit(href)`. Ingen propp → ingenting.
- **Vakterna** (punkt 3): `defaultPrevented`, `isComposing`, `n`/`N` utan Ctrl/Meta/Alt (Shift tillåtet), fält (`INPUT`/`TEXTAREA`/`SELECT`/`isContentEditable`), och öppen dialog — skalets `menuOpen` eller `document.querySelector('dialog[open]')`, som täcker `UiDialog`, arken och mobilmenyn. Alla vakter returnerar före `preventDefault()`, som alltså bara stryker bokstaven när den inte betyder något annat.
- **`resources/js/components/CreateButton.vue`** — alla tre formerna av plusknappen (menyn, formuläret i vyn, länken) bär `:aria-keyshortcuts="shortcut"` och `:title="title"`, och märket `data-create-button` som skalet hittar den synliga knappen på. Två knappar ritas ur samma mål (sidhuvudet över `md:`, flikradens mitt under), och menyn ska fästas vid den som syns — `getClientRects()` väljer den.
- **`lang/en/ui.php`** — `create.shortcut` = `N` och `create.title` = `:label (:shortcut)`, så att knappens tooltip blir *Add (N)*; båda läses via `t()`.
- **`tests/Feature/Frontend/KortkommandoNTest.php`** (ny) — källkodsprov i stil med `PlusknappTest`: lyssnaren läggs och tas bort, alla tre vägarna, varje vakt, och att vakterna står *före* `preventDefault()`. Kommentarer rensas före läsning.

`MobileTabBar.vue`, `useInlineCreate.js` och `PlusknappTest.php` står i rutan men behövde inga ändringar: flikraden ritar samma `CreateButton` och ärver därför attributen, komposabeln exponerade redan `request()`, och inget befintligt prov räknar skalets lyssnare eller rader.

Punkt 1–4 följdes som skrivna. En avvikelse värd att nämna: enligt punkt 3 stryks bokstaven i "alla andra fall", alltså även på en sida utan plusknapp — `N` gör då ingenting, men `preventDefault()` anropas.

## Läslista

- `AGENTS.md` (hel)
- `docs/ADR/ADR-0074 Registrering i vyn.md` § Tillägg 2026-11-18
- `resources/js/layouts/AppLayout.vue` (hel)
- `resources/js/components/CreateButton.vue` (hel)
- `resources/js/composables/useInlineCreate.js` (hel)
- `resources/js/components/MobileTabBar.vue` (hel)
- `tests/Feature/Frontend/PlusknappTest.php` (hel) — förlagan för källkodsprovet
- `tests/Feature/Frontend/PlusknappPaFlikarnaTest.php` (hel) — `create.kind === 'inline'`
- `tests/Feature/Frontend/GenomgangTest.php` § overlay-regeln och `SprakTest` § skalets strängar (avsnitt) — för att veta vad som fäller skalet
- `resources/js/i18n/translate.js` (hel) — `:param`-syntaxen

## Acceptanskriterier

| Klart när | Test |
|---|---|
| `N` gör samma sak som plusknappen: formuläret i vyn, menyn eller länken | `KortkommandoNTest` § "gör samma sak som plusknappen: formuläret i vyn, menyn eller länken" (alla tre vägarna i hanteraren; målet per sida prövas i `PlusknappTest` och `PlusknappPaFlikarnaTest`) |
| `N` gör ingenting i ett fält, under IME, i en öppen dialog eller på en sida utan plusknapp | `KortkommandoNTest` § "ignorerar N i ett fält, under IME, med en dialog öppen och när händelsen redan är hanterad" + vakten `if (!props.create)` i samma prov |
| Plusknappen har `aria-keyshortcuts="N"` och en tooltip som nämner kortkommandot | `KortkommandoNTest` § "bär kortkommandot på plusknappen i både sidhuvudet och flikraden" (`Lang::get('ui.create.shortcut')`, `Lang::get('ui.create.title')`) |
| Lyssnaren läggs till och tas bort | `KortkommandoNTest` § "lyssnar på window medan skalet är monterat…" |
| Hela sviten är grön | `composer test` — 5 648 tester, 5 638 gröna, 10 överhoppade |

## Omfångsrutan

- [x] Alla ändrade filer ligger inom issuens `In scope`
- [x] Inget under `Out of scope` är rört
- [x] Ställning som ett `install`-kommando genererade men issuen inte bad om är borttagen

## Kontroller

- [x] `vendor/bin/pint --test` grön
- [x] `vendor/bin/phpstan analyse` grön
- [x] `php artisan test` grön (`composer test`)
- [x] `npm run build` går igenom
- [x] Inga nya composer- eller npm-paket
- [x] Migrationer är additiva (inga migrationer)
- [x] Nya tabeller följer konventionerna (inga nya tabeller)
- [x] Nya felsvar använder maskinläsbara koder (inga nya felsvar)

## Nya beroenden

Inga.

## Frågor och antaganden

- **Titelns form.** Issuen säger att `title` blir "den befintliga etiketten följd av kortkommandot". Jag lade formen i katalogen (`create.title` = `:label (:shortcut)`) och bokstaven i `create.shortcut`, så att etiketten *Add* bara står på ett ställe. Resultatet blir *Add (N)*.
- **`preventDefault()` på en sida utan plusknapp.** Punkt 3 säger att bokstaven stryks i alla fall som inte är de fyra undantagen, och punkt 2 säger att en sida utan propp gör "ingenting". Jag följde den bokstavliga ordningen: bokstaven stryks, ingen åtgärd sker.
- **Vilken knapp som är triggern.** Skalet ritar två plusknappar ur samma mål (sidhuvudet över `md:`, flikraden under). För att menyn ska fästas vid rätt en och lämna tillbaka fokus dit väljer `visibleCreateButton()` den som har en layoutbox (`getClientRects()`). Den hittas på märket `data-create-button`, som jag lade till i `CreateButton.vue` — ett attribut issuen inte bad om, men den enda stabila vägen från skalet till knappen utan att bygga en ny prop kedja.

## Processnotering

Källkodsprovet behövde en andra vända: knappvalet ligger i en hjälpfunktion utanför hanteraren, så det första provet letade efter märket i fel funktion — det hade varit billigare att läsa den färdiga hanterarens avgränsning innan provet skrevs.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
