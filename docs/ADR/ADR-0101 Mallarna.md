# ADR-0101 Mallarna

**Status:** Antagen 2026-11-26 · Ersätter delar av [[ADR-0089 Egna fält på items]] § Riktning 6 och 8, [[ADR-0004 Fria taggar och kategorier]] § Konsekvenser och [[ADR-0021 Frontendteknik]] § Konsekvenser · [[ADR-index]]

Tonys beslut 2026-11-26 om mallarna. Skälet är [[ADR-0100 Agentregler]]: med regler i mallen hade varje väg att lägga en mall på en befintlig container varit en väg förbi beslutet att bara ägaren skriver containerns regler.

## Kontext

- Det finns tre vägar att lägga en mall på en befintlig container: webben och `/api` (`POST /containers/{id}/template`), MCP-verktyget `containers_apply_template` och förslagskortet på kategorisidan (`POST /containers/{id}/categories/preset`).
- `ApplyTemplate` kräver bara `update` och hämtar mallen ur den klickandes konto. En mottagare med skrivrätt kan alltså lägga sin egen mall i ägarens container.
- Mimers förslag ligger i `resources/js/data/categoryPresets.js` och når servern som fritt `template_content`.
- Inget av det var farligt så länge en mall bara bar kategorier och fält, eftersom användaren kan skapa dem för hand. Med agentregler i mallen ([[ADR-0100 Agentregler]]) blir varje väg en väg förbi regeln att bara ägaren skriver containerns regler.

## Beslut

*Tony 2026-11-26:*

1. Det finns bara två sorters mallar: **användarens sparade**, under kontot på servern, och **Mimers**, i koden under versionshantering. Ingen annan mall kan användas.
2. **Klienten skickar aldrig en malls innehåll.** Den skickar en ULID för en sparad mall eller en nyckel för en av Mimers mallar, och servern hämtar innehållet själv. `template_content` tas bort.
3. En sparad mall kan bara skapas ur en befintlig container, och innehållet kan aldrig ändras efteråt, bara namnet. (Gäller redan, skrivs in som beslut.)
4. **En mall läggs bara på när en container skapas.** Vägarna att lägga en mall på en befintlig container tas bort: `POST /containers/{id}/template` i webben och i `/api`, MCP-verktyget `containers_apply_template` och förslagskortet på kategorisidan (`POST /containers/{id}/categories/preset`). MCP är i beta till 1.0.0, så [[ADR-0087 MCP-server för agenter]] § Riktning 15 gäller inte och verktyget tas bort direkt.
5. En sparad mall bär containerns agentregler. Vid sparandet finns kryssrutan *Include agent rules*, förvald på. Mimers mallar kan bära regler, men ingen gör det ännu.

Detta ersätter [[ADR-0089 Egna fält på items]] § Riktning 6 och 8 där de tillåter en mall på en befintlig container, och [[ADR-0004 Fria taggar och kategorier]] § Konsekvenser och [[ADR-0021 Frontendteknik]] § Konsekvenser där de lägger kategoriförslagen i frontenden.

## Alternativ som valts bort

- **Att bara stänga vägen för reglerna och låta kategorier och fält läggas på i efterhand:** mindre arbete, men regeln *mallen tar med reglerna, utom när…* är svår att förklara, och mottagarens väg in i ägarens container står kvar.
- **Att behålla Mimers förslag i klienten, som [[ADR-0004 Fria taggar och kategorier]] och [[ADR-0021 Frontendteknik]] ville:** då måste servern ta emot innehåll den inte själv har, och det är den väg beslutet stänger. Att servern nu vet vad orden betyder är priset.
- **En avvecklingstid för `containers_apply_template`:** MCP är i beta till 1.0.0.

## Konsekvenser

- Issue 515–517 i [[M95 Agentregler och mallarna]].
- Om Mimers nuvarande mallar ska finnas kvar är öppet, se [[Tankar]].
