# M75 · Lämna en container

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-22 efter att en testare frågat om en inbjuden kan stänga sin åtkomst själv. Tony beslutade samma dag att mottagaren ska kunna lämna själv, att ägaren får en notis bara i klockan, och att regel 3 ändras så att den tillåter det. Beslutet står som tillägget 2026-11-22 i [[ADR-0003 Åtkomstmodell]].

---

### 452. En mottagare kan lämna en container

GitHub #1529. `ContainerPolicy::leave()` släpper igenom den som har en giltig rad på containern och inte tillhör ägarkontot, även när kontot är `read_only`. `LeaveContainer` återkallar alla personens rader, loggar `access.revoked` med mottagaren som aktör, och ägarkontots ägare får notisen `access.left` bara i klockan. Delningssidan får knappen *Leave container*, och rutterna är `POST /containers/{container}/leave` och `POST /api/containers/{container}/leave`.

**Läs:** [[Konton och åtkomst]] § Behörighetsregler, [[ADR-0003 Åtkomstmodell]] § Tillägg 2026-11-22: mottagaren kan lämna, [[Notiser]]

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** —
