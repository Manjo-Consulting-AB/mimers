# Registerförteckning

Vilka personuppgifter systemet behandlar, var de bor, varför de finns, hur länge de behålls och på vilken rättslig grund. Förteckningen är den art. 30-översikt som [[ADR-0017 Missbruksvektorer]] § Konsekvenser kräver för de mätvärden som är personuppgiftsnära.

**Förteckningen är ofullständig.** Den fylls på när en uppgift får sin gallringsfrist — att katalogisera hela systemets personuppgifter är inte en enskild issues uppgift, och en halv förteckning som säger att den är halv är användbar medan en som låtsas vara hel är farlig. Kvar att katalogisera:

- `user.email`
- `user.name`
- `loan.borrower_email`
- `sessions.ip_address`
- `audit_log.meta`

| Uppgift | Var den bor | Varför | Gallring | Rättslig grund |
|---|---|---|---|---|
| Registrerings-IP | `account.registration_ip` | Upptäcka missbruk av gratisnivån, [[ADR-0017 Missbruksvektorer]] § 1–2 | 90 dygn efter `account.created_at`, nollställs av `prune-registration-ips` | Berättigat intresse, art. 6.1 f |
| Rättslig spärr | `legal_hold` | Kontots innehåll är bevis och får inte gallras medan en anmälan enligt DSA art. 16 eller en myndighetsbegäran utreds, [[ADR-0043 Tre loggar]] § Den rättsliga spärren | Raden tas aldrig bort; spärren hävs genom att `lifted_at` sätts, och raden blir kvar som historik | Berättigat intresse, art. 6.1 f |
| Dolt tips | `dismissed_tip` | Vilka informationstips personen redan sett och kryssat bort, så att informationsytan visar nästa i stället för samma igen. Raden är personens och följer henne mellan webbläsare, [[M19 Dashboarden]] § 128 | Ingen egen frist: raden följer personen och ska bort när hon raderas. Personraderingen finns inte än — `DeleteAccount` raderar kontot men aldrig användarraden — och tabellen står därför här så att den kommer med när den byggs | Berättigat intresse, art. 6.1 f |
| Begärt adressbyte | `email_change` | Den nya adressen, och beviset att någon som når den har bekräftat bytet innan `user.email` skrivs om. Adressen lagras i klartext — den är bytets subjekt och måste gå att jämföra och skicka till — medan länken bara finns som SHA-256 (`token_hash`). Raden binds till personen med `user_id`, [[M20 Kontot]] § 130 | En timme: `expires_at` gör raden obrukbar, och en bekräftad rad är förbrukad. **Ingen automatisk gallring finns än** — `PrunesExpiredMagicLinkTokens` rör bara `magic_link_token`, och den här tabellen får sin egen den dag raden blir ett problem | Berättigat intresse, art. 6.1 f |
| Begärt lösenordsbyte | `password_change` | Det nya lösenordet, och beviset att någon som når kontots adress har bekräftat bytet innan `user.password_hash` skrivs om. Lösenordet lagras **bara som hash** (`Hash::make()`, samma kolumnbredd som `user.password_hash`) och länken bara som SHA-256 (`token_hash`) — klartexten finns uteslutande i mejlet och når aldrig databasen. Raden binds till personen med `user_id`, [[M20 Kontot]] § 140 | En timme: `expires_at` gör raden obrukbar, och en bekräftad rad är förbrukad. **Ingen automatisk gallring finns än**, samma läge som `email_change`: raden får sin frist den dag den blir ett problem | Berättigat intresse, art. 6.1 f |
| Säkerhetslogg | `security_log` | Upptäcka missbruk och intrång: inloggningar och misslyckade inloggningar, inlösta magic links, tvåfaktor som slås på eller av, nya återställningskoder, skickade inbjudningar, beställda och hämtade exporter, webhooks som skapas och tas bort, tömd lagring, nedladdningar ur en container användaren inte äger, och den rättsliga spärrens båda kommandon. Användarens id, `ip_group` (en pseudonym för IP-adressen) och ett tolkat enhetsnamn. **Ingen rå IP-adress, ingen rå webbläsarsträng, inget lösenord, ingen kod, ingen token och ingen e-postadress**, [[ADR-0043 Tre loggar]] § Säkerhetsloggen | 12 månader efter `created_at`, gallras av logggallringen (issue 115) | Berättigat intresse, art. 6.1 f |
