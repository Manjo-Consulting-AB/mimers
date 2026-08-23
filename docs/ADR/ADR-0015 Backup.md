# ADR-0015 Backup

**Status:** Antagen 2026-08-03 · [[ADR-index]]

## Kontext

Hela produktlöftet är att kunden inte förlorar sina papper. Samtidigt är systemet inte lanserat och har inga kunder — processen ska finnas, men behöver inte hålla Fortnox-standard från början.

Filerna ligger hos inleed ([[ADR-0007 Fillagring hos inleed]]) och är innehållsadresserade, alltså **oföränderliga** ([[ADR-0006 Innehållsadresserad lagring]]).

## Beslut

Tre nivåer:

| Nivå | Frekvens | Var |
|---|---|---|
| Inleeds egen backup | dagligen | inleed |
| Databasdump | **dagligen** | egen server på företaget |
| Filsynk | veckovis | egen server på företaget |
| Arkiv | månadsvis | AWS Glacier eller motsvarande |

**Databasen dumpas logiskt**, aldrig rsyncas. **Filerna rsyncas**, aldrig med `--delete`.

Binlogs och point-in-time recovery skjuts upp tills kundvolymen motiverar det.

## Motivering

**Databas och filer kräver olika metoder.** En rsyncad levande MariaDB-datakatalog ger en trasig ögonblicksbild. `mariadb-dump --single-transaction` ger ett konsistent InnoDB-läge utan att låsa något.

**Filerna är däremot perfekta för rsync** just för att de är oföränderliga — inkrementell synk kostar bara det som tillkommit, och innehållsversionering behövs aldrig.

**`--delete` är förbjudet.** Hela poängen är att skydda mot buggen som raderar saker i produktion, och `--delete` propagerar den raderingen till backupen inom ett dygn. Låt backupen växa, rensa separat med lång fördröjning.

**Databasen går dagligen, filerna veckovis.** Asymmetrin är avsiktlig: databasen är liten och dyrbar, filerna stora och oföränderliga. En komprimerad dump är några hundra megabyte även med tusentals användare, så nattlig överföring är gratis i praktiken — och det kortar värsta fallet från en vecka till ett dygn om inleed någon gång inte går att nå.

**Off-site betyder annan leverantör.** Ligger produktion och backup hos inleed skyddar det mot diskhaveri men inte mot ett låst konto, en faktureringstvist eller ett leverantörshaveri.

**Glacier ensamt är en fälla.** Den vanligaste återläsningen är inte "allt är borta" utan "en kund raderade sin container i går". Det ärendet ska lösas på tio minuter, inte tolv timmar. Därför egen server som varm nivå och Glacier som katastrofnivå.

Och det viktigaste: **soft delete gör det tunga jobbet ändå.** Nästan varje förlorad-data-ärende löses från papperskorgen utan att backupen rörs. Det är därför den enkla strategin är försvarbar. Se [[ADR-0008 Soft delete och papperskorg]].

## Konsekvenser

- **Dra, skicka inte.** Egen server hämtar från inleed över SSH med begränsad nyckel, så att inga backup-credentials finns på produktionsservern. Skickas det till AWS istället: nycklar med `PutObject` men utan `DeleteObject`, och Object Lock påslaget.
- **Kryptera innan det lämnar värden.** Backupen innehåller allt. restic rekommenderas för dumparna — kryptering, dedup och integritetskontroll ingår, och trettio dagliga dumpar av en knappt förändrad databas blir i praktiken en kopia. Nyckeln får inte enbart finnas på produktionsservern.
- **Dead man's switch.** Jobbet pingar en tjänst efter lyckad körning; uteblir pingen går larm. Ett tyst havererat cronjobb är det vanligaste verkliga felet.
- **Testad återläsning kvartalsvis.** En backup som aldrig återlästs är en förhoppning. Lägg in det som en återkommande uppgift.
- **Skriv en återläsningsrunbook.** Den som återställer klockan två på natten kan mycket väl vara Haiku, eller Tony under press.
- Raderad användardata lever kvar i backuper. Retentionsfönstret måste dokumenteras i integritetspolicyn och raderingar tillämpas vid återläsning.

## Alternativ

**Binlogs för point-in-time recovery.** Ger minuters dataförlust istället för ett dygn. Uppskjutet — kräver stöd hos inleed och mer tillsyn än vad kundvolymen motiverar idag.

**Enbart inleeds egen backup.** Valdes bort — inte off-site.
