# M76 · Ägarbytets bonus till nya kunder

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-22 efter Tonys genomgång av vad ett ägarbyte gör. Milstolpen bygger [[ADR-0093 Ägarbytets bonus till nya kunder]]: bonusen ges bara till en mottagare som saknade konto, bara från betald Pro och högst två gånger per avsändare på tolv månader, och kvoten prövas mot Pro när bonusen ges.

**Ordningen:** 453, 454, sedan 455 och 456 i valfri ordning. Issuerna är skapade i GitHub (#1532–#1535) och sammanfattade här. GitHub-issuen är bindande.

---

### 453. Prenumerationen vet varifrån planen kom

GitHub #1532. `subscription` får kolumnen `source` (`paid` \| `transfer_bonus` \| `voucher` \| `internal`), ifylld för befintliga rader i samma migration utan att någon blir `paid`. Skrivarna `GrantInternalPro`, `RedeemVoucher` och `AcceptOwnershipTransfer` sätter källan uttryckligen. Ingen kod läser kolumnen än.

**Läs:** [[ADR-0093 Ägarbytets bonus till nya kunder]], [[Planer och kvoter]] § subscription

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 451

### 454. Ägarbytets bonus bara till nya kunder från betald Pro

GitHub #1533. Bonusen ges bara när mottagaren saknade konto vid erbjudandet, avsändarens plan är betald och avsändaren har färre än två bonusar på tolv månader. Beslutet sparas som `ownership_transfer.grants_bonus`, och accepten sätter `bonus_granted_at`. Ges bonusen prövas kvoten mot Pro, och en befintlig plan förlängs aldrig.

**Läs:** [[ADR-0093 Ägarbytets bonus till nya kunder]], [[Planer och kvoter]] § Ägarbyte och kvot, [[Konton och åtkomst]] § ownership_transfer

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 453

### 455. Texterna om ägarbytets bonus följer regeln

GitHub #1534. Formulärets notis, mottagarens kort och avsändarens lista lovar bara den bonus som regeln i 454 ger. Mottagaren ser raden om Pro bara när `grants_bonus` är satt, och avsändaren ser det på det väntande ägarbytet.

**Läs:** [[ADR-0093 Ägarbytets bonus till nya kunder]]

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 454

### 456. Utdelade bonusar per avsändare i missbruksrapporten

GitHub #1535. `ReportsAbuseSignals` får nyckeln `transfer_bonuses_per_sender`: avsändarkonton med antalet ägarbyten där bonusen gavs de senaste tolv månaderna, sorterat fallande, med `bonus_granted_at` som källa. Inga nya spärrar eller notiser.

**Läs:** [[ADR-0093 Ägarbytets bonus till nya kunder]], [[ADR-0017 Missbruksvektorer]] § Mätningen

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 454
