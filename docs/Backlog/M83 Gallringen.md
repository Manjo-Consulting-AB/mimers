# M83 · Gallringen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-23 ur #1598, efter en genomgång av [[Registerförteckning]] inför integritetspolicyn. Tre tabeller behåller personuppgifter längre än de behövs, och förteckningen kan därför inte ange någon frist för dem. Policyn kan inte lova en tid som förteckningen inte har.

**Ordningen:** en issue. Den är skapad i GitHub (#1597) och sammanfattad här. GitHub-issuen är bindande.

---

### 473. Gallra kontoändringarna och de utgångna sessionerna varje natt

GitHub #1597. `email_change` och `password_change` blir obrukbara efter en timme, men raden finns kvar tills personen raderas. I `email_change` står den nya adressen i klartext. `sessions` bär `ip_address` och `user_agent`, och Laravel raderar en utgången session bara när sessionslotteriet slår till (`lottery => [2, 100]`), så hur länge raden ligger kvar beror på trafiken.

Två nattliga jobb i samma form som `PrunesExpiredMagicLinkTokens`:

- `prune-account-changes` tar bort rader i `email_change` och `password_change` som är bekräftade eller utgångna. Inget svar ändras, för `ConfirmEmailChange` och `ConfirmPasswordChange` svarar redan `404` på en sådan rad, precis som på ett okänt token.
- `prune-sessions` tar bort sessioner vars `last_activity` är äldre än `session.lifetime`. Lotteriet står kvar.

[[Registerförteckning]] får fasta frister för båda tabellerna och en ny rad för inloggningssessioner, med avtal som rättslig grund (art. 6.1 b). `sessions.ip_address` stryks ur *Kvar att katalogisera*.

`user_deletion` har samma form men gallras inte. Raden är bevis på att begäran gjordes, bär bara `user_id` och raderas med personen. Det är ett beslut i förteckningen, och issuen ändrar det inte.

**Läs:** [[Registerförteckning]], [[Konton och åtkomst]] § email_change och § password_change, `app/Console/PrunesExpiredMagicLinkTokens.php`, `routes/console.php` (blocket för `prune-magic-link-tokens`), `tests/Feature/Auth/GallraMagicLinkTokensTest.php`, `config/session.php`
**Klart när:** utgångna och bekräftade rader i `email_change` och `password_change` raderas, och en obekräftad rad som inte har gått ut står kvar och går fortfarande att lösa in; en session äldre än `session.lifetime` raderas och en inom livslängden står kvar; sessionsjobbet gör ingenting när `session.driver` inte är `database`; schemaläggaren har båda jobben, dagliga.
**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated
**Beror på:** —
