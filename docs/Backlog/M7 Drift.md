# M7 · Drift

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

### 42. Backupscript
Daglig `mariadb-dump --single-transaction` till egen server, veckovis filsynk **utan `--delete`**, månatlig arkivkopia. Egen server **hämtar** över SSH; inga backup-credentials på produktionsservern. Krypterat, restic rekommenderat.
**Läs:** [[ADR-0015 Backup]]
**Beror på:** 1

### 43. Dead man's switch
Backupjobben pingar efter lyckad körning; utebliven ping ger larm. Samma för notiscronen.
**Läs:** [[ADR-0015 Backup]], [[Notiser]] § Kön
**Beror på:** 42

### 44. Återläsningsrunbook
Steg för steg, skriven för någon under press. Plus ett kvartalsvis återkommande återläsningstest.
**Läs:** [[ADR-0015 Backup]]
**Beror på:** 42
