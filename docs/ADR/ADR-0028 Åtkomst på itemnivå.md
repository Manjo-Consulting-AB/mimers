# ADR-0028 Åtkomst på itemnivå

**Status:** Antagen 2026-09-05 · [[ADR-index]]

## Kontext

[[ADR-0003 Åtkomstmodell]] löser fyra verkliga relationer till en container — sambon, servicevarvet, mäklaren, charterkunden — och alla på containernivå. `container_access` bär `level` (`read`|`write`) och gäller hela pärmen. Den som får läsa ser allt; den som får skriva ändrar allt.

Två fall som inte går att uttrycka så:

- **Båtägaren som vill dela motorn med verkstaden**, men inte inköpspriser, försäkringsbrev eller vad som helst annat som råkar ligga i samma container.
- **Kunden i ett projekt** som ska läsa det som delats och kunna *lägga till* dokument på ett item — utan att kunna ändra det ägaren redan lagt in.

Dagens enda utvägar är att dela hela containern, eller att bryta ut delar i egna containers. Det senare är ingen lösning: det bryter pärmen i bitar, kostar mot containerkvoten och gör `item_link` mellan båten och motorn omöjlig, eftersom items hör till exakt en container.

Modellen har dessutom redan en gång behövt peka ut enskilda items: `ownership_transfer.excluded_item_ids` är en platt lista över det säljaren behåller. Granulariteten finns alltså, men bara som engångsfiltrering vid överlåtelse.

**Utgångspunkten i koden är inte den dokumentet beskriver.** [[Konton och åtkomst]] § Behörighetsregler säger att `write` får "skapa och ändra items, filer, scheman", och nämner inte radering. Implementationen från issue 13a lade `DELETE` under samma grind som `PATCH` — `ItemController::destroy()` och `AttachmentController::destroy()` gör båda `Gate::authorize('update', $container)` — för att slippa en femte policymetod. I praktiken betyder `write` alltså skapa **plus** ändra **plus** radera.

## Beslut

**Åtkomst kan ges på ett enskilt item, inte bara på en container.** Enheten är itemet, platt — container → item är en direktrelation, och motorn och masten är syskon i samma pärm. Delning får inte förutsätta att användaren har byggt en hierarki hon aldrig ombetts skapa.

**`level` blir en ladder om fyra steg:**

```
read  <  create  <  write  <  delete
```

Ordnad efter hur mycket *befintlig* information nivån kan skada: `read` rör ingenting, `create` lägger bara till, `write` ändrar det som redan står där, `delete` tar bort det. `create` utan `write` är kundfallet ovan och är hela skälet till att pinnen finns.

**Omfånget bärs av `container_access` genom en ny kolumn:**

| Kolumn | Typ | Not |
|---|---|---|
| item_id | FK → item NULL | `NULL` = hela containern, som i dag |

Ingen ny tabell. `revoked_at`, `expires_at`, `granted_by_user_id` och `kind` betyder detsamma som förut, och förvaltningsvyn hämtar fortfarande allt från ett ställe. En mottagare kan ha flera rader mot samma container — typiskt en per item, med olika nivå. Att samma mottagare inte får två giltiga rader för samma `(container, item)` upprätthålls i applikationslagret; MariaDB kan inte uttrycka villkoret som UNIQUE när `revoked_at` ska ingå.

**Fyra regler avgör vad en mottagare når:**

1. **Arv går nedåt** längs `item_link` med relation `parent`/`child`, transitivt och utan djuptak. Delas motorn följer impellern med, och impellerns egna barn därefter. Cykelkontrollen som redan krävs för `parent`/`child` gör slutningen säker.
2. **`sibling` ärver aldrig.** Relationen är symmetrisk, så arv där hade spridit sig åt båda håll utan gräns — motor `sibling` mast hade delat masten på köpet.
3. **Aldrig uppåt.** Den som får impellern får inte motorn. Annars läcker varje delning uppåt till hela pärmen.
4. **Högsta nivån vinner.** Behörigheten på ett item är max av alla grants som når det, direkta som ärvda. `read` på motorn och `write` på impellern ger RW på impellern och R på motorn — max är ordningsoberoende, så resultatet blir detsamma oavsett i vilken ordning grants skapades.

**Itemets beroenden följer itemet.** `attachment`, `cost_entry`, `schedule` med förekomster och `loan` hänger alla direkt på `item_id` och omfattas av samma nivå. Det är den enda inheritance som behövs utöver regel 1.

**`delete` betyder mjukradering.** Nivån flyttar till papperskorgen och får återställa inom sitt eget omfång. Fysisk gallring förblir ägarkontots ensak. Enligt [[ADR-0008 Soft delete och papperskorg]] är retentionen 30 dagar och bilagornas referensräknare minskas först när de lämnar papperskorgen — en mottagare med `delete` kan alltså inte förstöra något.

**`create` får skapa både inuti itemet och nya barn-items.** Verkstaden ska kunna lägga in "impellerbyte 2026" som eget item, inte bara som en bilaga. Följden — att mottagaren därmed utvidgar sitt eget omfång — accepteras, eftersom hon bara når det hon själv skapat.

**`invitation` speglar omfånget.** Tabellen får samma `item_id` och samma ladder i `level`. Utan det kan itemåtkomst bara ges till någon som redan har konto, och kundfallet — där mottagaren typiskt är ny — faller.

**Att ändra `item_link` kräver `write` i båda ändar.** Annars kan en mottagare länka in ett item hon inte får se, eller flytta ut ett hon fått.

**Migreringen bevarar beteendet exakt:**

| I dag | Efter |
|---|---|
| `read` | `read` |
| `write` | **`delete`** |
| — | `create` (ny, utan innehavare) |
| — | `write` (ny, utan innehavare) |

Befintliga `write`-innehavare kan i dag radera items och bilagor. De får `delete` och behåller precis det. Ingen förlorar något, och de två mellanpinnarna börjar tomma.

[[ADR-0003 Åtkomstmodell]] står kvar oförändrad. De fyra åtkomstformerna — `member`, `managed`, `guest`, ägare — är en annan axel än nivån, och `kind` avgör fortfarande aldrig behörighet.

## Motivering

Ladder framför oberoende CRUD-flaggor: max-regeln kräver en linjär ordning för att vara ordningsoberoende, och fyra oberoende flaggor ger sexton kombinationer som ingen ägare kan överblicka i en delningsdialog. De kombinationer som skulle gå förlorade — "ändra men inte skapa", "radera men inte ändra" — motsvarar inget verkligt behov.

Item framför kategori eller subträd som omfångsenhet: kategorier är valfria och `item_link` likaså. Ett omfång byggt på struktur hade tvingat ägaren att organisera om pärmen innan hon kunde dela motorn.

Kolumn i `container_access` framför egen tabell: åtkomsterna ska förbli läsbara på ett ställe, och återkallande, utgång och revisionslogg fungerar oförändrat. Tabellnamnet blir därmed något missvisande — den bär numera även itemåtkomster — men ett byte mitt i MVP kostar mer i följdändringar än namnet är värt.

## Konsekvenser

**Läckageytan är arbetet, inte grant-modellen.** Hela systemet är byggt på att den som ser containern ser allt i den. Följande måste filtreras per omfång innan lansering:

- **Itemlistning och fritextsök** — FULLTEXT-indexet är containerbrett, så träffar måste efterfiltreras och paginering räknas efter filtreringen, inte före.
- **Taggmoln och kategoriträd** — antal räknas per omfång. En tagg med noll synliga träffar får inte visas; den avslöjar att det finns något där.
- **`item_link`** — en länk till ett item utanför omfånget döljs helt, inte som ett namnlöst spöke.
- **Papperskorgen** (issue 20a) — i dag en containervy, annars ett fönster in i allt som någon gång raderats.
- **Kostnadsrapporten** (M8) och **todo-listan** (M3) — summerar och listar bara synliga items.
- **Notisgeneratorerna** (M5) — en omfångsbegränsad mottagare får inte notiser om items hon inte når, och uppgiftsnotisen går bara till den som kan bocka av uppgiften. Se uppföljningen nedan.
- **Exporten** (issue 41).

**Deltagarlistan** räknar en omfångsbegränsad mottagare som deltagare, men avslöjar inte vem som har vilket omfång — samma princip som redan gäller för nivåer och utgångsdatum.

**Grindarna skrivs om.** `destroy()` i `ItemController` och `AttachmentController` byter från `update` till en ny `delete`-gate, `store()` till `create`, och `update` blir smalare. `ContainerPolicy::hasContainerAccess()` tar i dag `list<'read'|'write'>` och matchar med `whereIn('level', …)`; den blir en jämförelse mot ladderns ordning. `ContainerPolicy::delete()` rör fortfarande bara containern och förblir ägarkontots.

**`read_only`-frysningen ligger oförändrad ovanpå.** Ett fryst konto nekas allt skrivande oavsett nivå, med de två undantagen i [[Konton och åtkomst]] § Behörighetsregler regel 4.

**Uppladdningskvoten är oförändrad.** `attachment.billed_account_id` är det uppladdande kontot, så en `create`-mottagare betalar för sina egna bilagor. Se [[ADR-0003 Åtkomstmodell]] och [[ADR-0017 Missbruksvektorer]].

**Frontenden visar två nivåer som standard.** Fyra val är för mycket för en ägare som bara delar med sambon; `create` och `delete` hör hemma bakom "avancerat". Se [[M10 Webbfrontend]].

**Uppföljning 2026-09-13 — uppgiftsnotisen följer `write`, inte åtkomsten.** Punkten om notisgeneratorerna sade bara vad en omfångsbegränsad mottagare inte får, och issue 75 visade att det inte räckte som regel. Generatorn hade sedan 34b en kontogrind som stängde ute varje delegerad mottagare: bara containers som mottagarens egna konton ÄGER kom med i frågan. Ett omfångsfilter ovanpå den grinden filtrerar en mängd som alltid är tom, och issuens krav att en delegat ska få notiser om sitt item gick därför inte att uppfylla utan att riva grinden.

Grinden är nu en nivåjämförelse i stället för en ägarjämförelse: mottagaren får notisen om hon når itemet på minst `write`. Skälet är att `complete()` och `skip()` går via `ItemPolicy::update()`, alltså `write` — en mottagare med `read` ser uppgiften i todo-listan men kan aldrig stänga den, och `task.due` följd av `task.overdue` till henne vore en återkommande uppmaning att göra något produkten inte låter henne göra. Hennes enda utväg vore att stänga av notistypen helt.

Följden är att 34b § Beslut 4 nu handlar om **läsrätt**, inte om ägarskap: gästen med `read` på hela charterbåten får fortfarande inte veta att impellern ska bytas, men en gäst med `write` får det. Hon är medförvaltare och inte åskådare, och hon är den som kan bocka av uppgiften. Ägarkontots medlemmar passerar grinden på regel 1, som ger dem `delete` på hela containern, så deras notiser är oförändrade.

## Alternativ

**Oberoende CRUD-flaggor per item.** Mer uttrycksfullt. Valdes bort — bryter max-regeln och ger sexton kombinationer utan verkliga användare.

**Omfång via kategori eller `item_link`-subträd.** Färre rader per delning. Valdes bort — förutsätter en struktur användaren inte är tvungen att ha byggt.

**Egen `item_access`-tabell.** Renare namngivning. Valdes bort — splittrar förvaltningsvyn, återkallandet och revisionsloggen på två ställen.

**Separat uppladdningslänk per item.** Löser kundfallet utan att röra läsmodellen. Valdes bort — `create`-nivån löser samma sak generellt, och en egen länkmekanism hade blivit en andra behörighetsväg vid sidan av `container_access`.

**Flera containers i stället för omfång.** Möjligt redan i dag. Valdes bort — bryter pärmen, kostar containerkvot och gör `item_link` mellan delarna omöjlig.
