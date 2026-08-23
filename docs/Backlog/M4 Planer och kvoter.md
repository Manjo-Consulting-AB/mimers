# M4 · Planer och kvoter

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

### 25. Planer och rättigheter
`plan` med gränser i JSON, `subscription`. Gränserna för free och pro enligt dokumentet. Inget betalflöde.
**Läs:** [[Planer och kvoter]], [[ADR-0014 Prismodell]]
**Beror på:** 3

### 26. Förbrukningsräkning
`usage_counter`, uppdaterad **transaktionellt** vid uppladdning och radering. Nattligt avstämningsjobb som larmar vid avvikelse.
**Läs:** [[Planer och kvoter]] § usage_counter, [[Filer och lagring]] § Kvot kontra faktisk lagring
**Klart när:** bytena belastar `attachment.billed_account_id` — det uppladdande kontot — och test visar att en varvsuppladdning inte fyller kundens gratiskvot.
**Beror på:** 16, 25

### 27. Kontrollpunkter för rättigheter
Kontroll vid skapande av container, uppladdning (styck och totalt), inbjudan, webhook, PDF-pärm och ägarbyte. Nekande ger felkod med vilken gräns som slog i.
**Läs:** [[Planer och kvoter]] § Kontrollpunkter
**Klart när:** kontrollerna sitter server-side och kan inte kringgås av en egen klient.
**Beror på:** 26

### 28. Nedgradering
Femstegsförloppet: read_only, användarens urval sorterat på storlek, tre månaders frist, automatisk radering av **bilagor nyast först**, återgång till active.
**Läs:** [[Planer och kvoter]] § Nedgradering, [[ADR-0009 Kvoter och livscykel]]
**Klart när:** test visar att **inga items raderas** — bara bilagor.
**Beror på:** 27

### 29. Kontolivscykel
12 / 15 / 18 månader. De tre undantagen: radering går via containern med ägarskap erbjudet aktiva medlemmar, aktiv prenumeration undantar, aktivitet räknas som API-anrop.
**Läs:** [[Planer och kvoter]] § Kontolivscykel, [[ADR-0009 Kvoter och livscykel]]
**Klart när:** ett konto med en delad container som har aktiva medlemmar kan **inte** raderas utan att ägarskapet först erbjudits.
**Beror på:** 27
