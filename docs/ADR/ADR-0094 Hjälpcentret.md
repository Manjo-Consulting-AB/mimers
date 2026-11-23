# ADR-0094 Hjälpcentret

**Status:** Antagen 2026-11-23 · Bygger vidare på [[ADR-0066 Itemets anteckning]], [[ADR-0079 Övervakning]] och [[ADR-0034 Engelska vid lansering]] · [[ADR-index]]

Tonys beslut 2026-11-23 om hjälpcentret, efter feature request #1568. Kodarbetet ligger i [[M80 Kunskapsbasen]], [[M81 Sök och hjälp i sammanhanget]] och [[M82 Återkoppling och mätning]].

## Kontext

[[M78 Hjälpsidan]] (#1541) gav `/help` som en lång sida med 66 frågor i nio kategorifiler. Ankaret räknas ur frågans text, och det finns ingen sökning: den som inte vet vilken kategori svaret bor i måste läsa hela sidan. #1568 ber om en kunskapsbas i stället — sökbar, med längre artiklar vid sidan av frågorna, hjälp i sammanhanget, återkoppling på texterna och mätning av vad som faktiskt läses.

Tony svarade på arkitektens frågor 2026-11-23, och svaren är hans beslut:

1. Sökfrågor sparas bara när de gav noll träffar, avidentifierade och per dag.
2. Ingen adminsida nu — en nattlig rapport i loggen. Adminsidan blir en fråga i [[Tankar]].
3. Ja/Nej är öppet för alla, fritext bara för inloggade.
4. Bilder och GIF:ar ligger i repot, video är en YouTube-länk som laddas först vid klick.
5. Ingen serverrendering för sökmotorer — bara titel, beskrivning och kanonisk adress skrivs av servern.
6. `symfony/yaml` är godkänt som nytt composerpaket.

## Beslut

### 1. Innehållet är markdownfiler med front matter

Källan är `lang/{locale}/help/`: en `categories.yaml` (ordnad lista med `id`, `title`, `description` och valfri `intro` i markdown), en `synonyms.yaml` (grupper av ord som söks som samma ord) och en katalog `articles/` med **en fil per artikel, också för varje fråga**. Filnamnet är artikelns `id`.

Front matter läses med `symfony/yaml` (Tony godkände paketet 2026-11-23). Fälten är:

- **Obligatoriska:** `id` (lika med filnamnet, `^[a-z0-9]+(-[a-z0-9]+)*$`, högst 80 tecken), `title`, `type` (`faq`, `guide`, `reference`, `troubleshooting`), `category` (ett id ur `categories.yaml`) och `updated` (`YYYY-MM-DD`, skrivs för hand eftersom utrullningen saknar `.git`).
- **Valfria:** `description` (obligatorisk för alla typer utom `faq`, högst 200 tecken), `keywords`, `related` (högst sex id:n), `context` (se 6), `status` (`published` eller `draft`, standard `published`), `featured` (bool) och `order` (heltal, standard 100, lika ordnas på titel).

En ny typ är ett nytt värde i `config/help.php` och kräver ingen ny modell.

Ett test i CI validerar hela innehållet: unika id:n, att `related`, `category`, `context`-ankare, interna länkar och bilder finns, och att varje språks id:n är en delmängd av engelskans.

**Varför en fil per fråga:** en modell, en validering, och varje fråga får nyckelord, relaterat och sammanhang som en guide.

### 2. Språk

Språket ges av katalogen, inte av front matter. Saknas en artikel i användarens språk visas den engelska. Ett nytt språk är en katalog, som [[ADR-0034 Engelska vid lansering]] kräver.

### 3. Adresserna

- `/help` — startsidan. Ruttnamnet `info.help` står kvar.
- `/help/{kategori}` — kategorisidan, med frågorna som utfällbara rader. Varje fråga går att nå som `/help/{kategori}#{id}`.
- `/help/articles/{id}` — artikelsidan, för alla typer.
- `/help/search` — sökningen som JSON.
- `/help/context/{sammanhang}` — omdirigeringen, se 6.

Adressen bär `id`, och `id` byts aldrig, så titeln kan skrivas om fritt.

Avsteg från #1568: ingen separat `slug`. Språket står inte i adressen, och en översatt slug hade gett en delad länk som inte fungerar för en användare med ett annat språk.

Rubriker i artiklar får ankare ur `{#id}` eller ur rubrikens text. **De gamla ankarna:** frågornas id:n är exakt de gamla ankarna (`Str::slug` av frågan), och startsidan skickar `/help#{id}` vidare i webbläsaren — fragmentet når aldrig servern, så det kan inte göras som en omdirigering.

### 4. Rendering

Markdown ritas på servern som i [[ADR-0066 Itemets anteckning]] (`html_input: strip`, `allow_unsafe_links: false`), och sidorna använder `v-html` på samma villkor som `Help.vue` i dag.

Tillägg utan nya paket:

- tabeller,
- faktarutor i GitHubs form `> [!NOTE]`, `> [!TIP]` och `> [!WARNING]`,
- interna länkar `help:{id}` och `help:{id}#{ankare}`,
- bilder bara ur `public/help-media/` (png, jpg, gif, webp, inte svg, högst 5 MB, alt-text krävs),
- video som en länk till YouTube ensam i ett stycke.

Videon ritas som en ruta med titel och spelknapp. Först vid klick laddas `youtube-nocookie.com`, och ingen miniatyr hämtas från YouTube i förväg. Så kontaktas ingen tredjepart och sätts ingen kaka förrän användaren valt det, och [[ADR-0079 Övervakning]] § 4:s *ingen samtyckesbanner* håller.

### 5. Sökningen

Sökningen sker på servern, i PHP, över det inlästa innehållet. Inget sökpaket, ingen Scout-indexering, ingen klientsökning: innehållet är under hundra artiklar, och en PHP-sökning går att testa i Pest, vilket en klientsökning inte gör i ett repo utan JS-testkörare.

- Normalisering till gemener utan skiljetecken.
- Stoppord per språk i `config/help.php`.
- Synonymer ur `synonyms.yaml`.
- Prefixträff från tre tecken.
- Alla ord måste träffa — annars en markerad delträff.
- Viktning: titel före nyckelord före beskrivning före brödtext.

Svaret bär utdrag och träffarna som segment, så klienten markerar utan `v-html`.

### 6. Hjälp i sammanhanget

En vy länkar till `/help/context/{sammanhang}`, aldrig till en artikeladress. Sammanhanget, till exempel `container.sharing` eller `account.security#two-factor`, står i artikelns `context` och får förekomma en gång per språk. Okänt sammanhang går till `/help` och räknas.

Komponenten `HelpLink` är det enda sättet att länka, och ett test prövar att varje sammanhang i `resources/js/` finns i innehållet.

### 7. Återkopplingen

*Was this helpful?* står på artikelsidan. Ja och Nej sparas direkt för alla, orsaken ur en fast lista likaså, fritexten (högst 1000 tecken) bara för inloggade, med *Save*.

Tabellen heter `help_feedback`. Raden gallras efter 90 dagar och raderas med användaren. Strypning per IP och per användare. Ingen ärendehantering.

### 8. Mätningen

Som [[ADR-0079 Övervakning]] § 4, utan användare, konto och IP.

`help_event_daily` räknar artikelvisningar, sökningar, klick på sökträffar och öppnade sammanhang per dag. `help_search_gap_daily` sparar sökfrågor **som gav noll träffar**, normaliserade, utan e-postadresser och med sifferföljder ersatta av `#`, högst 60 tecken. Högst 1000 nya frågor per dag.

Frågorna gallras efter 90 dagar, händelserna efter 400.

### 9. Rapporten i stället för en adminsida

Det finns ingen adminroll. Ett nattjobb skriver hjälprapporten till loggen som missbruksrapporten, och `php artisan help:feedback` visar fritexterna. En sökfråga syns i rapporten först när den räknats fem gånger, samma tröskel som [[ADR-0079 Övervakning]].

### 10. Sökmotorer

Ingen serverrendering — det kräver en Node-process hos inleed. Servern skriver titel, beskrivning och kanonisk adress i `app.blade.php`. Full SEO tas upp igen vid flytten till egen server.

## Alternativ som valts bort

- **Kategorifilerna kvar, med frågor som rubriker.** Det var M78:s form, och den ger ingen sökbar struktur och inga längre artiklar.
- **Klientsökning med MiniSearch eller egen kod.** Ett nytt npm-paket eller kod som inte går att testa i repots testsvit.
- **Scout.** [[ADR-0012 Sök]] är byggt för items, och innehållet är för litet för ett index.
- **Inbäddad YouTube utan klick.** Tredjepart och kakor innan användaren valt det.
- **SSR för sökmotorerna.** Kräver en Node-process som det delade webbhotellet inte tillåter.
- **Adminsida nu.** Ingen adminroll finns, och loggen räcker till den som underhåller texterna. Frågan står i [[Tankar]].
- **Egen slug per språk.** En delad länk hade slutat fungera för en användare med ett annat språk.

## Konsekvenser

- Tretton kodissuer i [[M80 Kunskapsbasen]], [[M81 Sök och hjälp i sammanhanget]] och [[M82 Återkoppling och mätning]].
- `LoadHelpArticles` försvinner när `/help` läser ur `HelpLibrary`.
- Två nya tabeller för mätningen, `help_event_daily` och `help_search_gap_daily`, plus `help_feedback` för återkopplingen. [[Registerförteckning]] får två rader.
- `symfony/yaml` läggs till i `composer.json`, och `config/help.php` blir den nya platsen för typer, stoppord, frister och trösklar.
