# M6 · Resten av MVP

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

### 76. Utlåning
`loan` med låntagare, förfallodatum och återlämning. Påminnelser via notiskärnan **till den som lånat ut, aldrig till låntagaren** — `borrower_email` är en kontaktuppgift i vyn, inte en mottagaradress.
**Läs:** [[Items och organisation]] § loan, [[ADR-0017 Missbruksvektorer]] § 7
**Klart när:** ett test visar att en förfallen utlåning genererar en notis till utlånaren och noll utskick till `borrower_email`.
**Beror på:** 13, 34
**Byggd som:** 76a tabellen, modellen och CRUD-ytan, 76b generatorn, mallen och Pro-grinden `loan_reminders`
**Numret var 38 fram till 2026-09-07.** Det delades med *Byt e-postleverantör till Mailgun* i [[M5 Notiser]], som är byggd som 38a/38b och behåller numret. Se [[Tankar]] § Avgjort och flyttat.

### 39. Ägarbyte
`ownership_transfer`. Accept i **en transaktion** enligt dokumentet, inklusive plankontroll hos mottagaren, flytt av förbrukning, undantagna items och kvarhållen åtkomst.
**Läs:** [[Konton och åtkomst]] § ownership_transfer, [[Planer och kvoter]] § Ägarbyte och kvot
**Klart när:** ett gratiskonto **inte** kan ta emot en container som spränger dess kvot, och mottagaren får tolv månader Pro.
**Beror på:** 27, 9
**Byggd som:** 39a tabellen, initieringsytan och notisen, 39b acceptflödets transaktion (container, förbrukning, åtkomster, plan) plus de undantagna itemen

### 40. Revisionslogg
`audit_log` för ägarbyten, återkallade åtkomster och andra känsliga händelser.
**Läs:** [[Konton och åtkomst]] § audit_log
**Beror på:** 39

### 41. Export
Fullständig export av en container med metadata och filer. **Fri på alla nivåer.**
**Läs:** [[ADR-0014 Prismodell]], [[Planer och kvoter]]
**Klart när:** exporten fungerar även för ett `read_only`-konto — den behövs som mest då.
**Beror på:** 16
**Byggd som:** 41a `export`-tabellen, det köade jobbet och ZIP-artefakten (`container.json`, `filer/`, läsbar `index.html`), 41b nedladdningsrutten och den nattliga gallringen
