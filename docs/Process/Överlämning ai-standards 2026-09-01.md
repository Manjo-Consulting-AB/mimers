# Överlämning: ändringar i ai-standards efter ADR-0026

**Till:** den som har skrivrätt i `Manjo-Consulting-AB/ai-standards`
**Från:** mimers, 2026-09-01
**Bakgrund:** [[ADR-0026 Implementering och granskning efter riskaxlar]]

Sex punkter. **Punkt 1 och 2 är tidskritiska av samma skäl**: `sync/manifest.yml` skickar två filer till mimers som `type: file`, alltså hela filer där lokala ändringar skrivs över. Nästa synk som rör dem återställer arbete som redan är mergat i mimers.

Punkt 3–5 följer av ADR-0026. Punkt 6 är ett förslag, inte ett krav.

Den här filen är en engångsöverlämning. **Radera den ur mimers när ändringarna är mergade i ai-standards** — ett dokument som beskriver ett arbete som redan är gjort är en fälla för nästa läsare.

---

## 1. `scripts/session-usage.py` är efter mimers kopia — och skriver över den

**Brådskande, och oberoende av ADR-0026.**

Mimers `.claude/hooks/session-usage.py` fick tre rättningar 2026-08-31, i PR #88 och #90. Ai-standards källfil har ingen av dem. Eftersom posten är `type: file` återinför nästa synk alla tre buggarna i mimers:

| Rättning | Vad som går sönder igen |
|---|---|
| Loggsökvägen via `git rev-parse --git-common-dir` | Sessioner i en worktree skriver till worktreens egen, gitignorerade `.claude/usage.jsonl` och försvinner med den. Det var förklaringen till att alla femton överlevande rader sa `"branch": "main"` — precis de rader som inte kan svara på vad en issue kostade |
| `GRATIS_MODELLER = frozenset({"<synthetic>"})` | `cost_usd` ger `null` så snart harnessets egna nollradsmeddelanden finns i bucketen. Tre av femton rader nollades så, och det var de tre dyraste sessionerna |
| `PRICES["deepseek-v4-flash"] = (0.14, 0.28)` plus `opriced_models()` | Varje Deepseek-session får `cost_usd: null` utan diagnos. ADR-0025 § Konsekvenser förutsåg exakt det och namngav `opriced`-fältet två veckor innan det byggdes |

**Åtgärd:** kopiera mimers version rakt av till `scripts/session-usage.py`. Den är den nyare, och skillnaden är 66 rader som alla går åt samma håll.

```bash
# från en checkout av båda repona
cp mimers/.claude/hooks/session-usage.py ai-standards/scripts/session-usage.py
```

**Kontrollera efteråt** att kommentarernas sökvägshänvisningar fortfarande stämmer: filen refererar `docs/Process/Lärdomar.md` och `ADR-0025`, vilket är mimers-sökvägar. I ai-standards är de kontextlösa. Antingen generalisera dem till "repots lärdomslogg", eller låt dem stå som spårbarhet — men välj medvetet.

**Läxan bakom punkten, värd en rad i lärdomsloggen:** en `type: file`-post är en enkelriktad kanal, och ingenting varnar när mottagarrepot hunnit före. Två PR:er förbättrade en synkad fil utan att någon märkte att förbättringen låg i fel riktning. En `--check`-körning i synken som larmar när målet skiljer sig från källan på annat sätt än att vara identiskt skulle ha fångat det.

---

## 2. `templates/ISSUE_TEMPLATE/agent_task.yml` — annars återställs mallen

Mimers har redan ändrat sin kopia (commit `6e44aa1`). Utan samma ändring här skriver nästa synk tillbaka den gamla routningen.

**Fältet `modell` blir `granskning`.** Ersätt hela blocket:

```yaml
  - type: dropdown
    id: modell
    attributes:
      label: Modell
      description: >
        Alla tre axlarna låga ger Deepseek. Någon axel förhöjd ger Sonnet.
        Stämmer inte valet med axlarna är någondera fel satt.
      options:
        - Deepseek (alla axlar låga)
        - Sonnet (någon axel förhöjd)
    validations:
      required: true
```

med:

```yaml
  - type: dropdown
    id: granskning
    attributes:
      label: Granskning
      description: >
        Deepseek implementerar varje issue. Axlarna avgör vem som granskar PR:en.
        Stämmer inte valet med axlarna är någondera fel satt.
      options:
        - Ingen modellgranskning (alla axlar låga)
        - Sonnet, läser diffen (någon axel förhöjd, risk_class none)
        - Opus, läser issuens läslista (risk_class elevated)
    validations:
      required: true
```

**Och motiveringen under `risk_class`.** Ersätt raden:

```
        Vid tvekan: elevated. Det som kostar är inte den dyrare modellen, det är felet.
```

med:

```
        Vid tvekan: elevated. Det köper en djupare granskning, inte en dyrare
        implementerare - och felet kostar mer än båda.
```

**Texten ovan är ordagrant den som ligger i mimers.** En tidigare version hänvisade till `Se ADR-0026.` i båda beskrivningarna; den hänvisningen är borttagen just för att ADR-numret är mimers-lokalt och betyder ingenting i ett annat repo. Applicerar du blocken rakt av blir de två filerna identiska och nästa synk en no-op — vilket är hela poängen med att inte låta en synkad fil avvika lokalt.

---

## 3. `model-routing.md` — rolltabellen mappar inte längre

Det här är kärnan i ADR-0026. `Implementerare, liten` och `Implementerare, stor` finns inte längre som skilda roller: implementeraren är en, och det är granskarrollen som delas i tre.

**Ersätt § Axlarna:s tabell och stycket under den:**

```markdown
| Utfall | Roll | Uppgift |
|---|---|---|
| Alla tre axlarna låga | **Implementerare, liten** | skriver koden |
| Någon axel förhöjd (`high` / `cross-module` / `elevated`) | **Implementerare, stor** | skriver koden |
| — | **Arkitekt** | skriver issuen, granskar PR:en |
```

**med:**

```markdown
| Utfall | Roll | Uppgift |
|---|---|---|
| Alla axelprofiler | **Implementerare** | skriver koden |
| Alla tre axlarna låga | — | ingen modellgranskning; grindarna räcker |
| Någon axel förhöjd, `risk_class: none` | **Granskare** | läser diffen |
| `risk_class: elevated` | **Arkitekt** | granskar mot issuens läslista |
| — | **Arkitekt** | skriver issuen |

Axlarna routar **granskningen**, inte implementeraren. Skälet är att testerna skrivs av
samma modell som koden: missförstår implementeraren en domänregel blir koden, testet och
PR-beskrivningen fel på samma sätt, konsekvent, och grindarna kan inte skilja den historien
från den sanna. Det enda som bryter slingan är en granskare som läser källdokumenten i
stället för PR:ens berättelse om sig själv — en granskningsuppgift, inte en
implementationsuppgift. En dyrare implementerare köper mindre än en dyrare granskare för
samma pengar, eftersom implementeraren sitter inuti slingan.

`risk_class: elevated`-granskningen får issuens `Läs`-lista som indata och prövar
betydelsen mot källdokumenten. Den ska uttryckligen inte godta PR-beskrivningens egen
redogörelse för vad ändringen gör.

Granskningen körs i en färsk session per PR, aldrig i en långlivad orkestrerarsession: en
granskare vars kontext bär trettio tidigare diffar är både dyrare per PR och sämre på den
trettionde.
```

**Ersätt modelltabellen:**

```markdown
| Roll | Modell | Modell-id |
|---|---|---|
| Arkitekt | Claude Opus 5 | `claude-opus-5` |
| Implementerare, stor | Claude Sonnet 5 | `claude-sonnet-5` |
| Implementerare, liten | Deepseek | `deepseek-v4-flash` |
| Mekanisk avläsning | Deepseek | `deepseek-v4-flash` |
```

**med:**

```markdown
| Roll | Modell | Modell-id |
|---|---|---|
| Arkitekt | Claude Opus 5 | `claude-opus-5` |
| Granskare | Claude Sonnet 5 | `claude-sonnet-5` |
| Implementerare | Deepseek | `deepseek-v4-flash` |
| Mekanisk avläsning | Deepseek | `deepseek-v4-flash` |
```

**Lägg till ett nytt avsnitt, § Omtag och eskalering**, efter § Mekanisk avläsning:

```markdown
## Omtag och eskalering

Ett omtag routas på **feltyp**, inte på ordning. Det som gör ett omtag värdelöst är inte
kostnaden — den är försumbar — utan att samma modell reproducerar samma missförstånd.

| Vad granskningen fann | Utfall |
|---|---|
| Rött CI eller röd grind | Omtag hos implementeraren, utan modellgranskning: felet är redan maskinläsbart |
| Regelbrott mot repots arbetsregler | Omtag hos implementeraren |
| Saknat test för en "Klart när"-punkt | Omtag hos implementeraren |
| Missförstånd av domänen | Eskalera ett steg |
| Issuen säger emot sig själv, eller svaret står inte i läslistan | Tillbaka till den som skrev issuen |

Granskaren avger ett strukturerat utfall — `godkänd`, `omtag`, `eskalera`, `issue-fel` —
så att routningen blir ett skript och inte ännu en modell som läser allt.

**Hård spärr: högst två varv hos implementeraren, ett hos granskaren, sedan stannar
uppgiften hos en människa.** Ett tredje varv på samma nivå betyder nästan alltid att issuen
är fel skriven.
```

---

## 4. `agents/reviewer.md` — granskningsnivåerna

Filen säger i dag: *"Granskningens nivå följer issuens axlar. Alla axlar låga: kontrollera grindarna och läs diffen. Någon axel förhöjd: läs diffen rad för rad och tänk igenom vad som kan gå fel i produktion."*

Två lägen, och de mappar inte mot de tre i ADR-0026. **Ersätt stycket med:**

```markdown
Granskningens nivå följer issuens axlar, och nivåerna är tre. Alla axlar låga: PR:en når
dig inte alls — grindarna och den mekaniska avläsningen räcker. Någon axel förhöjd med
`risk_class: none`: läs diffen rad för rad och tänk igenom vad som kan gå fel i
produktion. `risk_class: elevated`: läs issuens läslista först, och pröva ändringens
betydelse mot källdokumenten.

**På `elevated` är källdokumenten din enda oberoende referens.** Testerna är skrivna av
samma modell som koden, så ett missförstånd av en domänregel blir konsekvent i kod, test
och PR-beskrivning. Godta därför inte PR:ens egen redogörelse för vad ändringen gör, och
läs inte kraven ur testerna — läs dem ur de dokument issuen pekade ut, och pröva koden mot
dem.
```

**Lägg också till en punkt i § Grindarna, i ordning**, som punkt 0 före den nuvarande ettan:

```markdown
0. **Grindarna har kört.** En grind som svarar "ej tillämplig" ser i loggen ut som en grind
   som godkände. Läs annoteringarna, inte bara exitkoden. (Mimers omfångsrutegrind hoppade
   över sig själv tyst i två dagar innan någon läste loggen.)
```

---

## 5. `agents/issue-author.md` — motiveringen under axlarna

Rad 22 bär samma mening som issue-mallen. **Ersätt:**

```
- `risk_class: elevated` för autentisering, behörighet, pengar, kvoter, radering och filleverans. Vid tvekan: `elevated`. Det som kostar är inte den dyrare modellen, det är felet.
```

**med:**

```
- `risk_class: elevated` för autentisering, behörighet, pengar, kvoter, radering och filleverans. Vid tvekan: `elevated`. Det köper en djupare granskning, inte en dyrare implementerare — och felet kostar mer än båda.
```

---

## 6. `agents/retro.md` — förslag, inte krav

Bevismängden (punkt 1–7) saknar det tal ADR-0026 hänger sin stoppregel på. **Förslag: lägg till som punkt 8:**

```markdown
8. **Eskaleringsfrekvens per axelprofil.** Hur ofta en granskning gav `eskalera` i stället
   för `godkänd` eller `omtag`, grupperat på axelprofil. Det är den enda siffra som säger
   om implementeraren är rätt vald för en grupp — kostnad per issue säger det inte, för en
   billig session som eskalerar är inte billig.
```

Motivet: ADR-0026 säger att implementationen flyttas tillbaka till en större modell för `elevated` om gruppen eskalerar oftare än 30 %. Utan siffran i bevismängden blir det en regel ingen mäter.

**Notera att detta är en första träff.** Tvåträffsregeln gäller — men den gäller observationer som blir regler, och det här är en metrik som ett antaget beslut redan förutsätter. Bedöm själv.

---

## Kontrollerat, ingen ändring behövs

- **`agents/agent-core.md`** nämner inte modellval någonstans. Axeldefinitionerna på rad 13 gäller oförändrat. Blocket synkas in i mimers `AGENTS.md` mellan markörerna, och mimers modellstycke ligger **utanför** blocket — det är redan uppdaterat lokalt och rör inte det synkade innehållet.
- **`templates/pull_request_template.md`** ingår inte i mimers manifestpost; mimers äger sin egen PR-mall.
