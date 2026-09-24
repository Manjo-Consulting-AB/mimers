# Att sortera efter mockuparna

Del av [[Backlog]]. **Det här är ingen milstolpe.** Det är en hållplats för arbete som är identifierat men ännu inte inplacerat, i väntan på genomgången av mockuparna.

Listan kommer ur genomgången av MVP:n 2026-09-17. Första omgången mockuper gicks igenom 2026-09-17 och 18; det som avgjordes där har flyttat till [[M14 Besluten ur mockupgenomgången]], och det som står kvar här väntar fortfarande på designen. Flera av dem kan visa sig vara överflödiga när den är känd, och minst en kan visa sig vara större än den ser ut — därför ligger de här i stället för i en milstolpe som påstår sig veta ordningen.

**En post lämnar den här filen när den blir en issue i en milstolpe** — eller, för ett beslut, när det står i en ADR. Står något kvar här som redan är byggt blir filen värdelös, precis som [[Tankar]] § Öppet.

**Designern lämnade fyra bilder 2026-09-22.** De ligger i `docs/Design/` och är den första designkällan valvet haft. Det de avgjorde — tokens, kärnkomponenterna, och fem ställen där bilden säger emot ett tidigare beslut — står i [[ADR-0042 Designsystemet]], och arbetet har issues i [[M17 Designsystemet]]. **Bilderna är nyare än besluten men inte mer genomtänkta:** vädret, framdriftsstapeln, leverantören och artikelnumret är strukna i ADR:en, och den globala navigeringen är fortfarande avvisad av [[ADR-0041 Itemets vy]].

---

## Avgjort vid mockupgenomgången

Tre beslut fattades 2026-09-17 och är utskrivna:

- [[ADR-0035 Relationen mellan objekt]] — `sibling` heter `related`. Tre relationer, inte fyra. Namnbytet går i databasen, inte bara i etiketten, och ska ligga **efter** omskrivningen av `lang/` i [[M13 Omskrivningen]].
- [[ADR-0036 Containerns art]] — `kind` blir fritt med autocomplete, CHECK-villkoret utgår, navigeringen grupperar vid minst två. Kategorimallarna tappar sin nyckel och hör därmed ihop med mallvalet i [[ADR-0033 Produktens omfång]].
- [[ADR-0037 Valutans arv]] — konto → container → rad, med omval på varje nivå. Ett ändrat förval rör aldrig gamla poster.
- [[ADR-0038 Gränsen för Pro i kostnaderna]] — en fast summering är fri, allt frågbart är Pro. Ersätter en rad i [[ADR-0016 Kostnadsregistrering]].

De fyra har issues i [[M14 Besluten ur mockupgenomgången]] — 83 till 87.

Containermockupen gicks igenom 2026-09-18 och gav två till, som ännu inte har issues:

- [[ADR-0039 Containerns översikt]] — containerns förstasida blir en översikt och itemlistan en flik. Varje tal räknar det användaren själv når. Containern får en beskrivning. Ersätter issue 57a § Beslut 1 och exportens placering i issue 67c § Beslut 1.
- [[ADR-0040 Underträdets summor]] — status och kostnad räknas över itemet och dess ättlingar. Donuten grupperar per item och drar samma data på varje nivå. Ingen kategorikolumn på `cost_entry`.

Den del av de två som inte väntar på designen har issues i [[M15 Containerns översikt]] — 88 till 92. Resten står kvar nedan.

Itemmockuparna gicks igenom 2026-09-18 och gav en till:

- [[ADR-0041 Itemets vy]] — itemet bor i containern och inte i en global navigering. Strukturens rötter är de items användaren når som saknar nåbar förälder, ett item får förekomma på flera ställen, och den aktuella platsen står i querysträngen. Anteckningen är itemets egen text, omslagsbilden en vald bilaga med en regel som gör valet frivilligt. **Rättar tårtbitarnas indelning i [[ADR-0040 Underträdets summor]]**: de är de items som bär kostnadsraderna, inte underträdets toppnivå, eftersom ett item under två föräldrar annars hamnar i två bitar.

Den del som inte väntar på designen har issues i [[M16 Itemets vy]] — 93 till 96.

**Skalen**, som inte är ett beslut utan en läsning av mockuparna: trepanelsvyn är vad användaren ser när ett objekt öppnas, dashboarden är vad som möter henne efter inloggning, containervyn ligger mellan dem.

---

## Dashboarden — avgjort 2026-09-18

**Har lämnat listan 2026-09-24.** Punkterna från genomgången av dashboardmockupen har issues i [[M19 Dashboarden]] — 122 till 128. Två av dem stämde inte när de skrevs in där. Händelsepanelen visar läsregeln över alla användarens konton och inte bara hennes egna rader, som [[ADR-0043 Tre loggar]] § Konsekvenser beslutade. Inbjudningarna fanns inte *"redan"* bakom klockan: `invitation.received` skrivs av ingen kod, och vägen dit är issue 131 i [[M20 Kontot]].

**Dessa är kvar utan datakälla:** containerns foto och undertitel, kortens framdriftsstapel. Vädret är struket.

---

## Containervyn — avgjort 2026-09-18

Genomgången av containermockupen mot datamodellen. Det som blev beslut står i [[ADR-0039 Containerns översikt]] och [[ADR-0040 Underträdets summor]]; punkterna nedan är avgjorda men har varken ADR eller issue.

**Väntar på designen:** dokumentfliken och bildpanelen. Flikradens indelning och exportens flytt har sedan 2026-09-22 issue 101 i [[M17 Designsystemet]]; containerns hjältebild kräver ett datamodellbeslut och inte en vy-issue, se [[ADR-0042 Designsystemet]] § Bildernas avvikelser. Översiktens skelett, beskrivningen, ättlingsupplösningen, kostnadsnedbrytningen och OK-statusen ligger i [[M15 Containerns översikt]].

**Navigeringens gruppering är ADR-0036:s regel, oberoende uppfunnen.** Mockupens sidomeny har *Mina containers* med fyra arter à en container och *Projekt* med tre. Det är exakt vad *gruppera vid minst två* ger: bara en art når två, resten ligger löst i en hög som behöver ett namn. Namnet på högen är det enda ADR-0036 lämnade öppet, och mockupen svarade.

**Dessa är kvar utan datakälla:** containerns hjältebild. `attachment.item_id` är `NOT NULL`, så en container kan inte äga en fil. Det krävs antingen en nullbar `container_id` på `attachment` eller ett eget fält, och frågan är densamma som dashboardkortens foto — den avgörs en gång, inte två.

**Detta finns däremot redan:** `attachment.kind` är `image`, `document` eller `other`, så dokumentfliken och bildpanelen behöver inget nytt fält. Historikfliken är indexerad på `(container_id, created_at)`, vilket är precis dess fråga. Informationsrutan är dashboardens, med samma fyra krav.

**"Lägg till i denna container" nämner en anteckning.** Något sådant finns inte; `item.description` är det närmaste. Antingen stryks ordet eller så är det en egen fråga.

---

## Itemvyn — avgjort 2026-09-18

Genomgången av de två itemmockuparna mot datamodellen. Det som blev beslut står i [[ADR-0041 Itemets vy]]; punkterna nedan är avgjorda men har varken ADR eller issue.

**Väntar på designen:** fokuskartan och itemets kostnadsflik. Omslagsbilden, strukturupplösningen och förekomsterna ligger i [[M16 Itemets vy]]; flikraden och trepanelslayouten har sedan 2026-09-22 issue 102 och 103 i [[M17 Designsystemet]].

**Fokuskartan behöver ingen ny fråga.** `ListItemLinks` ger redan motparterna med relationen sedd från itemet och med omfångsfiltret i samma fråga — en graf över närmaste relationer är den listan ritad som noder. Det som kostar är layouten. Teckenförklaringen ska vara tre sorter, *Parent · Child · Related*, enligt [[ADR-0035 Relationen mellan objekt]]; den ena mockupen säger fortfarande *Syskon*.

**Containerns hela karta är ett eget projekt.** En graf över hundratals noder är en layoutalgoritm, inte en vy, och den hör inte ihop med fokuskartan mer än till namnet.

**Itemets historikflik har det sämre än containerns.** `audit_log` är indexerad på `(container_id, created_at)` — precis containerfliken behöver — men det finns **inget index på `(subject_type, subject_id)`**, som är itemhistorikens fråga. Fliken vore alltså tom *och* en full scan. Den ritas inte, och indexet hör till händelseinstrumenteringen nedan.

**Strukna ur mockupen:** leverantör och artikelnummer i detaljrutan. Leverantören bor på `cost_entry`, där den redan är indexerad och har en autocomplete; artikelnumret finns inte, och `serial_number` är inte det — ett serienummer identifierar exemplaret, ett artikelnummer modellen. Skulle de behövas är det ett beslut, inte två fält.

**"Anteckning" är avgjord, men inte som ADR-0041 skrev.** ADR:en svarade att `item.description` *är* anteckningsfältet. Issue 96 (#406) skilde dem åt igen: `description` säger vad itemet **är**, anteckningen vad användaren **vet** om det, och kolumnen är nullbar text vid sidan av. Många daterade anteckningar per item vore fortfarande en tabell och ett nytt beslut.

---

## Ännu inte issues

**Händelseinstrumenteringen har lämnat listan 2026-09-23.** Besluten står i [[ADR-0043 Tre loggar]] och arbetet har issues i [[M18 Loggarna]] — 107 till 117. Dashboardens händelsepanel är issue 126 i [[M19 Dashboarden]].

**Anmälningsvägen enligt DSA.** Mimers är en värdtjänst, och artikel 16 kräver att vem som helst kan anmäla innehåll som den anser vara olagligt. Artikel 17 kräver att en användare vars innehåll begränsas får en motivering. Båda är egna ytor med egna flöden och hör ihop med den rättsliga spärren i [[ADR-0043 Tre loggar]]. Ingen av dem har en issue.

**Containerns karta.** En graf över containerns alla items och deras relationer. Datat finns; layouten över hundratals noder är arbetet, och den delar ingenting med fokuskartan på itemet utom namnet.

**Luckorna i kontot har lämnat listan 2026-09-24.** Lösenordet, e-postadressen och inbjudningarna har issues i [[M20 Kontot]] — 129 till 131.

**Verifieringarna.** Att en uppladdning bara lagras en gång (dedup och referensräkning) och att filer inte går att nå obehörigt ska bevisas av bestående tester, inte av en genomgång per release. Båda ytorna är `risk_class: elevated` enligt [[AGENTS.md]] § De tre axlarna.

**Kalenderfeedens namn** hämtas från URL:en i stället för produktnamnet och containerns namn.

**Notiser vid uppgift.** När skickas de? Frågan är först en uppslagning i [[Notiser]] och blir en issue bara om svaret och beteendet går isär.

**Delsträngssök kräver MariaDB i CI.** `LIKE '%ord%'` är dagens beteende via databasdrivaren; FULLTEXT-grenen i [[ADR-0012 Sök]] är avstängd tills sviten kan köras mot MariaDB. Den frågan står redan i [[Tankar]] § Öppet, rest i issue 2 och halvt besvarad 2026-09-03 — den behöver inte resas igen, den behöver avgöras.

**Miniatyrer i itemlistan** står redan som öppen fråga i [[Tankar]] § Öppet, rest när 61b skrevs. Den avgörs av designen och behöver inget eget spår här.
