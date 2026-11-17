# M65 · Pro+ och grundarpris

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-18, efter Tonys beslut om tre konsumentnivåer. Pro får 10 GB för 39 €, Pro+ 50 GB för 69 €, och grundarpriset är 19 respektive 39 €. Beslutet och motiveringen står i [[ADR-0086 Pro, Pro+ och grundarpris]].

En issue. Betalflödet, grundarårets datum och sidan om vart pengarna går ingår inte. De byggs när betalflödet byggs, se [[Tankar]].

**Releasen:** en, när 399 är mergad. Issuen skrevs direkt i GitHub (#1383) och är sammanfattad här.

---

### 399. Planraderna och bonusen enligt ADR-0086

GitHub #1383. **Läget:** `plan` har två rader, `free` och `pro`, där `pro` har 25 GB och 4900 EUR. Bonusen vid ägarbyte, `AcceptOwnershipTransfer::beviljaPro()`, gör om varje rad som inte är en aktiv `pro` till `pro`. Med Pro+ skulle det sänka planen.

**Lösningen:** en ny migration sänker `pro` till 10 GB och 3900. Den lägger till `pro_plus` (50 GB, 6900, publik), `pro_founder` (10 GB, 1900, inte publik) och `pro_plus_founder` (50 GB, 3900, inte publik), med `limits` lästa ur `pro`-raden så att `pending_invitations` följer med ([[ADR-0086 Pro, Pro+ och grundarpris]] § 1, 2 och 4). Inga konton flyttas. `plan.names` får de tre nya koderna. Bonusen förlänger en aktiv rad på en betald plan med ett år på samma plan, och ger annars Pro som i dag (§ 7). Spärren för en bonus per konto och kvotprövningen mot den nuvarande planen rörs inte.

**Läs:** [[ADR-0086 Pro, Pro+ och grundarpris]], [[Planer och kvoter]] § plan, § Gränserna i MVP och § Ägarbyte och kvot

**Axlar:** `ambiguity` low · `blast_radius` cross-module (planraderna läses av kvoten överallt, och ägarbytet bygger på bonusen) · `risk_class` elevated (skriver `subscription` och ändrar kvoter)

**Klart när:** `pro` har 10 GB och 3900, och de tre nya raderna finns med lagring, pris och `is_public` enligt ADR:en och samma `limits` som `pro` utom `storage_bytes`; `down()` återställer `pro` och tar bort raderna; planvyn visar rätt namn för alla fyra betalda koder; en aktiv rad på `pro_plus`, `pro_founder` eller `pro_plus_founder` förlängs ett år på samma plan vid ägarbyte; ingen rad, `free`, `cancelled` eller `past_due` ger Pro som i dag, en gång per konto; ett Pro-konto nekas en container som tar det över 10 GB med `quota.storage_exceeded`, och ett Pro+-konto tar emot den; hela sviten är grön.

**Beror på:** ingenting
