# CLAUDE.md

Karta över dokumentationen. **Vad du ska läsa** står här; **hur du ska arbeta** står i `AGENTS.md`. De två överlappar inte med flit — hittar du samma regel på båda ställena är det ett fel, säg till.

Repot innehåller Laravel-appen och dokumentationsvalvet under `docs/`. Appen sattes upp i issue 1; domänmodellen börjar i issue 2.

## Läsprotokollet

Dokumentationen är ~27 000 ord. Att läsa den i förväg är slöseri i både tid och krediter, och gör dig inte bättre informerad — bara mättad. Läs i den här ordningen och stanna när du har det du behöver:

1. **`AGENTS.md`** — kort, och gäller alltid.
2. **Din milstolpes fil** under `docs/Backlog/`. Slå upp numret i tabellen i [Backlog](docs/Backlog.md) och öppna **bara** den filen. Öppna aldrig alla.
3. **Dokumenten under `**Läs:**` i din issue.** Inget mer.

Står svaret inte där: använd tabellen nedan för att slå upp *ett* dokument. Hittar du det ändå inte — **fråga, gissa inte.**

## Slå upp under arbetets gång

Din issues **Läs**-lista är utgångspunkten. Tabellen här är för frågor som dyker upp medan du bygger.

| Frågan gäller… | Slå upp i |
|---|---|
| en tabell, kolumn eller index | rätt fil under `docs/Datamodell/` |
| konton, användare, behörigheter, inbjudningar | `Konton och åtkomst.md` |
| items, kategorier, taggar, relationer, utlåning | `Items och organisation.md` |
| återkommande underhåll, förekomster, todo | `Scheman och uppgifter.md` |
| uppladdning, hash, dedup, referensräkning | `Filer och lagring.md` |
| notiser, outbox, kanaler | `Notiser.md` |
| planer, kvoter, förbrukning, nedgradering | `Planer och kvoter.md` |
| **varför** ett beslut ser ut som det gör | `docs/ADR/ADR-index.md`, sedan rätt ADR |
| felkodsformatet i API:et | `AGENTS.md` § Felformat |
| vilket språk ett namn i koden ska ha | `AGENTS.md` § Språk i koden |
| CI, miljöer, utrullning, servern hos inleed | `docs/Deploy/Pipeline.md` |
| hur vi står oss mot ett annat verktyg | `docs/Konkurrens.md` |
| en fråga ingen har svarat på | `docs/Tankar.md` |

## Läs avsnitt, inte hela filer

Tre dokument är stora nog att det spelar roll: `Pipeline.md` (~2 500 ord), `M10 Webbfrontend.md` (~840) och `M0 Fundament.md` (~650). De flesta ADR:er ligger på 300–1 200 ord och läses hela.

Behöver du ett enskilt avsnitt — issuen skriver ofta ut det, som `[[Pipeline]] § Uppladdningsgränser` — så läs bara det:

```bash
grep -n '^#\{2,3\} ' "docs/Deploy/Pipeline.md"   # lista rubrikerna med radnummer
sed -n 'START,SLUTp' "docs/Deploy/Pipeline.md"   # läs från din rubrik till nästa
```

Skriv inte in radnummer från minnet — de flyttar sig vid varje redigering. Kör `grep` först, varje gång.

## Kartan över valvet

- `docs/00 Index.md` — startpunkt för en människa
- `docs/Översikt.md` — produkten på fem minuter
- `docs/Konkurrens.md` — hur vi står oss mot Obsidian, Evernote, Notion och de andra
- `docs/Backlog.md` — indextabell: issuenummer → milstolpefil
- `docs/Backlog/` — en fil per milstolpe, issues med läslista och acceptanskriterier
- `docs/ADR/` — 28 beslut, ett per fil, med kontext och konsekvenser
- `docs/Datamodell/` — vad systemet består av, uppdelat per domän
- `docs/Deploy/Pipeline.md` — CI, miljöer, utrullning, verifierade fakta om servern
- `docs/Tankar.md` — obesvarade frågor

Länkarna i dokumenten är Obsidian-wikilänkar: dubbla hakparenteser runt filnamnet utan sökväg och utan `.md`. De är namnbaserade, så en fil kan flyttas utan att länkar går sönder — men **byt inte namn** på en fil utan att söka igenom valvet först.
