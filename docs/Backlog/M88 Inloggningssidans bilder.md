# M88 · Inloggningssidans bilder

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-24. Förlagan `docs/Design/frontpage.png` har ett foto bakom vänsterkolumnen på `/login`, och issue 203 § Beslut 6 lät bli att rita det eftersom det inte fanns någon bild. Tony har lagt fram tre: en båt, en husvagn och en stuga. **Sidan visar en av dem, slumpad vid varje sidladdning** — inget bildspel och aldrig alla tre på en gång (Tony 2026-11-24).

**Bilderna ligger i repot innan issuen plockas upp**, beskurna till 4:5 runt motivet och konverterade till WebP i samma docs-PR som den här filen: `public/images/login/boat.webp`, `caravan.webp` och `cabin.webp`. Originalen var en JPEG på 2048 × 1124, en PNG på 1672 × 941 och en JPEG på 5250 × 3500 och 12,7 MB; tillsammans väger de tre WebP-filerna omkring 435 kB. Issuen är skapad i GitHub (#1652) och sammanfattad här. GitHub-issuen är bindande.

---

### 490. En slumpad bild bakom vänsterkolumnen på /login

GitHub #1652. En av de tre bilderna väljs med `Math.random()` en gång i `setup` och ligger bakom texten i vänsterkolumnen, med `object-cover` och en mörk slöja i `bg-shell`. Den byts inte medan sidan står öppen: ingen timer, ingen övertoning, ingen paus. Sidan renderas inte på servern, så slumpen på klienten hamnar inte i otakt med någon hydrering, och ingen prop eller controller ändras. Bilden är dekoration (`alt=""`, `aria-hidden`) och har `loading="lazy"`, så att en telefon, där kolumnen är dold, inte hämtar den. Högerkolumnen och `ui.php` rörs inte.

**Läs:** `resources/js/pages/Auth/Login.vue`, `docs/Design/frontpage.png`, `tests/Feature/Frontend/InloggningssidaTest.php`, `resources/js/components/AppHero.vue`
**Klart när:** `Login.vue` har de tre sökvägarna i en konstant och väljer en med `Math.random()` i `setup`; vänsterkolumnen ritar exakt en `<img>` med `alt=""`, `aria-hidden="true"`, `loading="lazy"` och `object-cover`; ingen `setInterval`, `setTimeout` eller övertoning; texten ritas ovanpå; testet prövar det och att filerna finns; docblocket säger inte längre att fotot inte ritas; bygget och sviten är gröna.
**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none
**Beror på:** —
