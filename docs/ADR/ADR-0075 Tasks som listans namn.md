# ADR-0075 Tasks som listans namn

**Status:** Antagen 2026-11-14 · Ersätter [[ADR-0050 Desktopdesignen]] § Uppföljning 2026-10-17 § 4 · Ersätter raden *Uppgifter och underhåll* i ordtabellen i [[ADR-0068 Itemets sida och skapandet]] § 1 · Återställer [[ADR-0032 Produktens ord]] · [[ADR-index]]

Tonys beslut 2026-11-14: listan heter *Tasks* i varje text användaren ser, aldrig *To do*.

## Kontext

*To do* står i huvudmenyn, på containerns flik, på `/tasks` och på itemets flik, men *task* står i över hundra andra texter: notiserna, statusraderna, *New task*, felmeddelandena och exporten. Samma lista har alltså två namn. GTD-modellen som [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] och [[ADR-0054 Inboxen]] bygger på säger *Tasks*, och produkten har använt båda orden för samma sak sedan [[ADR-0050 Desktopdesignen]] § 4 (2026-10-17) bytte till *To do*.

## Beslut

Varje användarsynlig text säger *Tasks* (på svenska *Uppgifter*). Bytet gäller bara texterna. Identifierare står kvar: `TodoController`, `ListTodo`, `TodoRow`, `TodoEntryResource`, katalognycklarna `todo.*` och rutten `/api/todo`. Nycklarna står still och byter bara värde, som [[ADR-0032 Produktens ord]] föreskriver. *Nothing to do right now.* (`todo.nothing`) är vanlig engelska och inte listans namn, så den står kvar.

## Konsekvenser

- Åtta värden i `lang/en/ui.php` och fem prov ändras (GitHub #1223).
- Identifierarna och ordet skiljer sig nu åt, och det är avsiktligt. Ett namnbyte i koden är inte beslutat.
- Andra förekomster av *To do* i valvet, som milstolpefilerna M52–M58, brödtexten i [[ADR-0074 Registrering i vyn]] och [[Tankar]], ändras inte. De beskriver vad som gällde när de skrevs.
