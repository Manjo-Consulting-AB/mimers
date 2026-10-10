# M31 · Flytande navigering

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-10. Webben ska kännas snabbare utan att arkitekturen byts: skalet står kvar mellan sidorna, nästa sida är ofta redan hämtad och avbockningen syns direkt. Besluten står i [[ADR-0056 Flytande navigering]].

**Ordningen är 273 → 274 → 275 och 276 → 277 → 279. 278 står fritt.** Varje issue står i läget `spårad`.

---

### 273. Skalet står kvar, sidorna i AppLayout

GitHub #832. De 20 sidor som ligger direkt i `AppLayout` deklarerar layouten med `defineOptions` i stället för att lägga den i mallen, och `AppLayout` stänger sina menyer när `page.url` byts.

**Läs:** [[ADR-0021 Frontendteknik]], `resources/js/layouts/AppLayout.vue`

**Klart när:** ingen av de 20 sidorna bär `<AppLayout>` i mallen; plusknappens mål följer med som layoutprop; menyerna stängs vid navigering; hela testsviten är grön.

**Beror på:** —

### 274. Skalet står kvar, inställningarnas sidor

GitHub #833. `SettingsLayout` slutar rita `AppLayout`, och de 8 inställningssidorna deklarerar `[AppLayout, SettingsLayout]`.

**Läs:** `resources/js/layouts/SettingsLayout.vue`

**Klart när:** ingen inställningssida bär `<SettingsLayout>` i mallen; `SettingsLayout` ritar inte `AppLayout`; sektionsmenyn stängs vid navigering; hela testsviten är grön.

**Beror på:** 273

### 275. Skalet står kvar, containerns sidor

GitHub #834. `ContainerLayout` och `ContainerSettingsLayout` slutar rita en annan layout, och containerns 20 sidor deklarerar sin kedja och skickar exakt de props de skickar i dag.

**Läs:** `resources/js/layouts/ContainerLayout.vue`, `resources/js/layouts/ContainerSettingsLayout.vue`, [[ADR-0048 Mobilen och plusknappen]] § 2

**Klart när:** ingen sida bär en layout i mallen; ingen layout ritar en annan; plusknappen står där den stod; hela testsviten är grön.

**Beror på:** 273, 274

### 276. En förhämtning av itemsidan skriver inget besök

GitHub #835. `ItemController::show()` hoppar över `RecordRecentVisit` när förfrågan bär `Purpose: prefetch`.

**Läs:** [[ADR-0049 Nyligen besökta]]

**Klart när:** en förhämtning skriver ingen rad och flyttar inte `visited_at`; ett vanligt besök skriver som förut; hela testsviten är grön.

**Beror på:** —

### 277. Förhämtning och cache i webbläsaren

GitHub #836. `cacheFor: ['30s', '5m']`, `router.flushAll()` efter varje visit som inte är GET, förhämtning vid hovring i sidopanelen, containerlistan och mobilens flikrad, och när sidan laddats i flikraderna på `/tasks` och i containern.

**Läs:** [[ADR-0056 Flytande navigering]]

**Klart när:** inställningen och tömningen står i `app.js`; de utpekade länkarna förhämtar; itemsidan förhämtas inte från en annan sida; hela testsviten är grön.

**Beror på:** 276

### 278. Avbockningen syns direkt

GitHub #837. `TodoRow.vue` visar den fyllda bocken i knappen medan svaret är på väg, och tar bort den igen vid fel.

**Läs:** `resources/js/components/TodoRow.vue`

**Klart när:** bocken syns när knappen trycks; den försvinner vid fel; knappen och fokus står kvar; hela testsviten är grön.

**Beror på:** —

### 279. Mjuka övergångar mellan sidorna

GitHub #838. `visitOptions` slår på View Transitions för sidbyten men inte för filter, med 150 ms och ingen animation vid `prefers-reduced-motion`.

**Läs:** `resources/js/app.js`, `resources/css/app.css`

**Klart när:** ett sidbyte tonar över; ett filter tonar inte; ingen animation vid mindre rörelse; hela testsviten är grön.

**Beror på:** 277
