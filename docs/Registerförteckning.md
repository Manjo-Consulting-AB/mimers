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
