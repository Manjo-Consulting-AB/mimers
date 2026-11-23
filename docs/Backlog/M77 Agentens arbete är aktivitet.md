# M77 · Agentens arbete är aktivitet

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-22 efter en genomgång som visade att `last_active_at` bara skrivs av webben och REST-API:et. MCP-rutterna ligger utanför båda middlewaregrupperna, så en agents arbete räknades inte som aktivitet. Tony beslutade samma dag att det ska räknas; beslutet står i [[ADR-0009 Kvoter och livscykel]] § Uppföljning 2026-11-22.

---

### 457. Agentens arbete räknas som aktivitet på kontot

GitHub #1539. Ett verktygsanrop från användarens agent via MCP skriver `last_active_at`, genom `App\Actions\User\RecordActivity` och med samma dygnsspärr som webben, när anropet har passerat spärrarna. Anrop från en pausad agent, från ett konto utan plan med MCP, anrop som stoppas av rate limit och protokollanrop som `tools/list` räknas inte.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** —
