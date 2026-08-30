# ADR-0022 Testramverk och statisk analys

**Status:** Antagen 2026-08-23 · [[ADR-index]]

Fattat under issue 1, som lämnade valet öppet med formuleringen "Pest eller PHPUnit". Kompletterar [[ADR-0001 Stack]] på den punkten.

## Kontext

[[AGENTS.md]] gör testerna till den bärande granskningsmekanismen: *varje "Klart när"-punkt i en issue ska motsvaras av ett test, annars mergas inte PR:en.* Tony granskar men knackar inte, och tre olika modeller skriver var sin del av backloggens 69 issues. Testsviten är alltså inte en bikostnad utan den yta där arbetet faktiskt kontrolleras.

Det gör valet av testramverk till samma sorts avvägning som [[ADR-0001 Stack]] gjorde en gång: vad som ger minst spretighet när delegerad implementation skrivs av modeller, inte vad som är elegantast.

Invändningen mot Pest var att tunnare träningsdata ger sämre genererad kod. Den är värd att ta på allvar, och den mättes.

## Beslut

**Pest 5 som testramverk. Larastan på nivå 5 som statisk analys.**

Testerna ligger under `tests/Feature` bundna till Laravels `TestCase`, och under `tests/Unit` mot ren PHPUnit utan ramverk. Bindningen sker i `tests/Pest.php`.

**Använd Pests globala hjälpfunktioner — `get()`, `post()`, `actingAs()`, `assertDatabaseHas()` — inte `$this->`.** PHPStan kan inte härleda vad `$this` är bundet till inne i en Pest-closure och rapporterar `method.notFound` på varje anrop. Funktionerna har riktiga returtyper. Det är därför `tests/` kan ligga kvar bland de analyserade sökvägarna utan en enda undertryckning, och den regeln är hela skälet till att skriva ned den här punkten.

**`->not` går inte att kedja under analysen.** PHPStan ser `Expectation::$not` som en odefinierad property. Skriv den positiva formen i stället — `expect(count($x))->toBeGreaterThan(0)` i stället för `->not->toBeEmpty()`. Samma skäl som ovan: regeln finns för att slippa undertryckningar, inte för att analysen har rätt i sak.

**Nivå 5, inte högre.** Den fångar riktiga fel utan att kräva docblock-ceremoni i all modellkod. Avsikten är att höja när modellkonventionerna från issue 2 satt sig — men det ska då vara ett eget beslut med en egen städning, inte något som smyger sig på.

**Ingen baseline-fil, inga `@phpstan-ignore`.** En analys man vant sig vid att kringgå är ingen analys.

## Motivering

Nedladdningssiffror från Packagist 2026-08-23:

| | Totalt | Per månad | Per dag |
|---|---|---|---|
| `phpunit/phpunit` | 983 787 818 | 16 212 094 | 264 446 |
| `pestphp/pest` | 81 820 661 | 5 588 600 | 127 355 |
| `laravel/framework` | 566 418 940 | 12 808 808 | 258 209 |

Tre avläsningar avgjorde:

**Pest drar alltid in PHPUnit.** Pest är PHPUnit med ett annat skal, så varje siffra i PHPUnit-raden innehåller varje Pest-installation. Ren PHPUnit per dag är 264k − 127k ≈ 137k mot Pests 127k. Förhållandet 12:1 i totalen är ett tjugoårigt bakkatalog, inte dagens ekosystem.

**Ungefär hälften av alla Laravel-installationer drar Pest** — 127k mot 258k per dag. Det är den enda skiva som är relevant; vi skriver inte generisk PHP.

**Den exponerade ytan är tunn.** I ett Laravel-test är det mesta ramverkets eget API — `actingAs`, `assertDatabaseHas`, `RefreshDatabase`, `postJson`. Identiskt oavsett ramverk. Valet styr omslaget runt testet, inte innehållet.

Kvar blir två skäl som pekar åt samma håll:

**Reversibiliteten är enkelriktad åt vårt håll.** Klassbaserade PHPUnit-test körs oförändrat i en Pest-svit. Visar det sig att en modell producerar bättre PHPUnit-klasser skriver den PHPUnit-klasser, i samma svit och samma `php artisan test`, utan migration. Det omvända går inte.

**Versionsdriften är lägre, inte högre.** [[ADR-0021 Frontendteknik]] valde bort Livewire med argumentet att versioner blandas friskt i modellernas träningsdata, vilket är fel egenskap när tre modeller skriver var sin del. Samma test tillämpat här faller mot PHPUnit: metadataformatet gick från docblock (`@test`, `@dataProvider`) till attribut (`#[Test]`, `#[DataProvider]`), och de två blandas ständigt. Pests `it()`/`expect()` har inte haft någon motsvarande brytning genom v1–v5.

Larastan framför ren PHPStan är mindre av ett vägval. Utan Eloquent-förståelse larmar analysen falskt på nästan varje modell, och varje framtida issue får tysta bruset med ignore-rader — vilket är precis den vana som gör analysen värdelös. Larastan installeras i ungefär samma takt som Pest, 128k per dag.

## Konsekvenser

- **`tests/` analyseras av PHPStan.** Det är poängen med funktionsregeln ovan. En issue som återinför `$this->get(...)` bryter `composer analyse`.
- **Testsviten kräver att frontenden är byggd.** Ett test läser `public/build/manifest.json` för att bevisa att artefakten produceras, så CI kör `npm run build` före testerna. Lokalt gäller `composer setup` eller `npm run build` en gång.
- **`phpunit/phpunit` står inte i `composer.json`.** Pest äger versionen — Pest 5 kräver PHPUnit 13, och skelettets pinning på `^12.5.12` var det första som sprack. Två ställen att uppdatera är ett ställe för mycket.
- **Nivå 5 är en startpunkt, inte ett tak.** Höjningen hör hemma i en egen issue efter issue 2.
- **`composer lint`, `composer analyse` och `composer test`** är namnen som gäller, i CI och lokalt. `composer fix` rättar formateringen i stället för att larma.
- **`parseModelCastsMethod: true` i `phpstan.neon`.** Larastan läser annars aldrig modellernas `casts()`, oavsett docblock ovanför metoden — en castad kolumn typas som `string` i stället för `Carbon`. Flaggan löser hela problemet utan kodändringar i modellerna, se issue 63.

## Alternativ

**PHPUnit 12 rakt av.** Störst korpus, följer med skelettet, noll nya paket. Valdes bort på reversibiliteten och på att metadatadriften mellan docblock och attribut är den drift som faktiskt biter i den här arbetsmodellen. Skillnaden är inte stor, och beslutet är värt att ompröva om Pest-syntax visar sig ge fler misslyckade PR:er än den sparar rader.

**Larastan på nivå 8.** Nära maximal stringens. Valdes bort för nu — den tvingar typade properties och `@var`-kommentarer överallt från dag ett, vilket gör att PR:er fastnar på analys i stället för på logik innan modellkonventionerna ens är skrivna.

**Ren PHPStan utan Larastan.** Ett paket mindre. Valdes bort — falska larm på Eloquent som varje issue måste tysta är brus som döljer riktiga fel.
