# Mimers

Ägaren av en båt, husvagn, stuga eller bil samlar all dokumentation om objektet på ett ställe: prylar, manualer, kvitton, serienummer, servicehistorik och återkommande underhåll. En blandning av Evernote och OmniFocus, byggd för en nisch.

Namnet syftar på Mimer i nordisk mytologi, som vaktar brunnen där visdomen finns. Produkten bor på `mimers.app`.

## Var börjar jag

**[docs/00 Index.md](docs/00%20Index.md)** — startpunkten. Läs inte allt: varje issue i backloggen pekar ut exakt vilka dokument som behövs för just den uppgiften.

| Jag vill… | Läs |
|---|---|
| förstå produkten på fem minuter | [Översikt](docs/%C3%96versikt.md) |
| bygga eller ändra en tabell | rätt fil under [docs/Datamodell/](docs/Datamodell) |
| veta *varför* något är som det är | [ADR-index](docs/ADR/ADR-index.md) |
| ta nästa arbetsuppgift | [Backlog](docs/Backlog.md) |
| sätta upp eller ändra CI och deploy | [Pipeline](docs/Deploy/Pipeline.md) |
| implementera en issue | **[AGENTS.md](AGENTS.md)** |

## Teknik

PHP 8.3 + Laravel på delad hosting hos inleed, MariaDB 10.6, minutcron. Webbfrontenden byggs med Inertia och Vue i samma Laravel-app. API:et är produkten och innehåller aldrig användarvänd text, bara maskinläsbara felkoder.

Se [ADR-0001 Stack](docs/ADR/ADR-0001%20Stack.md), [ADR-0021 Frontendteknik](docs/ADR/ADR-0021%20Frontendteknik.md) och [ADR-0013 Språk och i18n](docs/ADR/ADR-0013%20Spr%C3%A5k%20och%20i18n.md).

## Arbetssätt

Gren per issue, PR mot `main`, tagg till produktion. Ingen pushar direkt till `main`. Merge till `main` rullar ut på staging automatiskt; produktion kräver en release och ett godkännande.

Konventionerna står i [AGENTS.md](AGENTS.md), motiveringen i [ADR-0018](docs/ADR/ADR-0018%20Utvecklingsprocess%20och%20deploy.md).
