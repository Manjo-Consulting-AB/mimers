# M96 · Länkar i fälten

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-26. Efter milstolpen är en adress i ett textfält en länk. Besluten står i tillägget 2026-11-26 i [[ADR-0089 Egna fält på items]]:

- **Tonys beslut 2026-11-26:** En URL i ett item ska vara klickbar, i ett fält och i anteckningen.
- **Tonys beslut 2026-11-26:** Länken öppnas i en ny flik, inte i det fönster användaren står i.
- **Tonys beslut 2026-11-26:** En adress blir en länk var den än står i värdet, inte bara när hela värdet är en URL.

Anteckningen uppfyller redan alla tre ([[ADR-0066 Itemets anteckning]] § 2). Det som saknas är textfälten.

**Ordningen:** 518. Issuen är skapad i GitHub (#1741) och sammanfattad här. GitHub-issuen är bindande.

---

### 518. Länkar i textfälten på itemets vy

GitHub #1741. Efteråt är en adress i ett textfält en länk på itemets vy, med `http://`, `https://` eller `www.` i början, och den öppnas i en ny flik.

Bara typen `text` får länkar. Klienten delar värdet i segment och ritar dem utan `v-html`. Skiljetecken i slutet hör inte till adressen, och `www.` får `https://` i `href`. Värdet lagras och skickas som text, så API:et, MCP och exporterna ändras inte. Anteckningen ändras inte, den får bara ett prov.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** -
