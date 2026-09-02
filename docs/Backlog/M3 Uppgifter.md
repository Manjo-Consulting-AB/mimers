# M3 · Uppgifter

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

### 21. Scheman
`schedule`, noll eller flera per item. Båda återkommandetyperna, `lead_days`.
**Läs:** [[Scheman och uppgifter]], [[ADR-0005 Schema och förekomst]]
**Beror på:** 13

### 22. Förekomster och avslut
`schedule_occurrence`. Avslutsflödets fem steg i **en transaktion**. `overdue` härleds, lagras aldrig.
**Läs:** [[Scheman och uppgifter]] § Flödet när en uppgift markeras klar
**Klart när:** test visar att `fixed` räknar från kalendern och `interval` från `completed_at`, och att exakt en öppen förekomst finns per aktivt schema.
**Beror på:** 21
**Byggd som:** 22a tabellen, beräkningen av nästa förfall och den öppna förekomsten, 22b avslutsflödets fem steg

### 23. Beroenden mellan uppgifter
Beroenden på förekomstnivå, ärvda från schemanivå när en ny förekomst skapas. Cykelkontroll på båda nivåerna.
**Läs:** [[Scheman och uppgifter]] § occurrence_dependency
**Klart när:** en förekomst med öppna beroenden inte kan stängas, och en cykel avvisas med felkod.
**Beror på:** 22
**Byggd som:** 23a `schedule_dependency` med cykelkontroll, 23b `occurrence_dependency` med arv och spärr i avslutsflödet

### 24. Todo-listan
Endpoint som läser förekomster över alla åtkomliga containers, filtrerad enligt dokumentet.
**Läs:** [[Scheman och uppgifter]] § Todo-listan
**Beror på:** 23
