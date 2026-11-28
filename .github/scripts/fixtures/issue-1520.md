### Milstolpe
M74 Tvåfaktor som krav för delade containrar

### Mål
En container kan kräva tvåfaktor av den som når den. Den här issuen bygger kravet i datamodellen och spärren i åtkomsten. Inställningarna kommer i 450 och märket i listorna i 449.

Beslutet står i ADR-0092 Tvåfaktor som krav för delade containrar (valvissue #1524). Sammanfattningen under *Beslut som redan är fattade* är bindande tills ADR:en är mergad.

Verifierat i koden: åtkomsten avgörs på tre ställen som var och en säger att den är den enda formuleringen av sin fråga — `ContainerPolicy::view()` (och de andra metoderna via `hasContainerAccess()`), `App\Actions\Access\ResolveItemScope` (regel 0–4) och `Container::scopeAccessibleBy()` (listor, sökning, `/tasks`, ICS-flödet, historiken, hjältens siffror, papperskorgen). `App\Support\Agent\ItemAudience` är den omvända frågan (vilka når ett item) och följer samma regler. Agenter går genom `ResolveAgentAccess`, som läser `ResolveItemScope`; API-token går genom policyn. Tvåfaktorn är bekräftad när `user.totp_confirmed_at` inte är null; `User` har ingen hjälpmetod för det i dag.

Gör så här:

- **Migration:** `account.require_two_factor` och `container.require_two_factor`, BOOLEAN NOT NULL DEFAULT false.
- **`Container::requiresTwoFactor(): bool`** — containerns egen flagga ELLER ägarkontots. **`User::hasConfirmedTwoFactor(): bool`** — `totp_confirmed_at !== null`.
- **Regeln:** en `container_access` (container-bred eller itemgrant) ger **ingenting** när containern kräver tvåfaktor och mottagaren saknar bekräftad tvåfaktor. Medlemmar i ägarkontot berörs inte (ägaren har alltid tvåfaktor när kravet är på, se 450). Inboxen berörs inte.
- **Var regeln sitter**, och ingen annanstans:
  - `ResolveItemScope`: en ny regel efter regel 1 — grants mot en container som kräver tvåfaktor ignoreras för en användare utan tvåfaktor. Memon per `{user}:{container}` gäller som förut.
  - `ContainerPolicy::hasContainerAccess()`: samma villkor.
  - `Container::scopeAccessibleBy()`: `orWhereHas('accesses', …)`-grenen kräver att containern inte kräver tvåfaktor (varken egen eller ägarkontots flagga) när `$user` saknar tvåfaktor. Lägg en valfri parameter `bool $includeLocked = false` som släpper igenom de låsta — bara listorna i 449 använder den.
  - `ItemAudience`: en mottagare utan tvåfaktor räknas inte till publiken för en container som kräver det.
- **Följden**, som ska ha prov men ingen egen kod: den låsta medlemmen får inga uppgiftsnotiser eller mejl om containern, containern syns inte i ICS-flödet, i sökningen, i `/history`, i `/tasks` eller i hjältens siffror, och hennes agenter och API-token når den inte (MCP-verktygen och `/api/containers`).
- **Omedelbart:** stänger medlemmen av sin tvåfaktor gäller spärren vid nästa begäran. Ingen notis, varken till henne eller ägaren.

### Beslut som redan är fattade
- Kravet gäller alla planer.
- En låst medlem ser bara containerns namn och märket (449). Inget annat innehåll, inga notiser, inget ICS.
- Agenter och API-token för en medlem utan tvåfaktor är spärrade på samma sätt som hon själv.

### Läs
`app/Actions/Access/ResolveItemScope.php` (docblocken), `app/Policies/ContainerPolicy.php` (`view`, `hasContainerAccess`), `app/Models/Container.php` (`scopeAccessibleBy`, `scopeListable`), `app/Support/Agent/ItemAudience.php` (docblocken)

[[ADR-0028 Åtkomst på itemnivå]]

### In scope

```shell
database/migrations/**
database/factories/**
app/Models/Container.php
app/Models/Account.php
app/Models/User.php
app/Actions/Access/ResolveItemScope.php
app/Policies/ContainerPolicy.php
app/Support/Agent/ItemAudience.php
tests/Feature/Omfang/**
tests/Feature/Container/**
tests/Feature/Mcp/**
tests/Feature/Notis/**
tests/Feature/Uppgift/**
tests/Feature/Revision/**
tests/Feature/Api/**
tests/Feature/Konto/Tvafaktorkrav*Test.php
```

### Omfångsläge
spårad

### Out of scope

```shell
resources/js/**
app/Http/Controllers/**
routes/**
lang/**
docs/**
```

### ambiguity
low

### blast_radius
cross-module

### risk_class
high

### Granskning
Sonnet

### Klart när
- [ ] En mottagare av `container_access` utan bekräftad tvåfaktor når ingenting i en container med `require_two_factor`, eller vars ägarkonto har det: 403 på containern och itemen, inga items i omfånget.
- [ ] Samma mottagare med tvåfaktor når containern som förut; ägaren påverkas aldrig.
- [ ] Prov för sökningen, `/tasks`, ICS-flödet, `/history`, uppgiftsnotiserna, en agent via MCP och `/api/containers`.
- [ ] Att stänga av tvåfaktorn spärrar vid nästa begäran.
- [ ] `accessibleBy(..., includeLocked: true)` släpper igenom den låsta containern.
- [ ] Hela sviten, pint och phpstan är gröna.

### Beror på
—

### Delmoment
- [ ] En session
