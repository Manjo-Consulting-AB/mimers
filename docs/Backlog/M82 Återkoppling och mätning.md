# M82 · Återkoppling och mätning

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-23 ur #1568. Milstolpen bygger [[ADR-0094 Hjälpcentret]]: återkoppling på artiklarna, anonym mätning av vad som läses och söks, och en nattlig rapport i loggen i stället för en adminsida.

**Ordningen:** 470 väntar på 467 och 468, 471 väntar på 464, och 472 väntar på 470 och 471. Issuerna är skapade i GitHub (#1584–#1586) och sammanfattade här. GitHub-issuen är bindande.

---

### 470. Hjälpens mätning och sökfrågor utan träff

GitHub #1584. Hjälpen räknar det som behövs för att se var texterna brister, utan användare, konto eller IP: artikelvisningar, sökningar, klick på sökträffar, öppnade och okända sammanhang per dag, och sökfrågor som gav noll träffar — avidentifierade. Två migrationer, `CountHelpEvent` och `PrunesHelpData`, med frister och tak i `config/help.php`. Ett fel i mätningen loggas och sväljs; den får aldrig fälla sidan.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 467, 468

### 471. Was this helpful? på hjälpartiklarna

GitHub #1585. Varje artikelsida frågar *Was this helpful?*. Ja och Nej sparas direkt för alla. Efter Nej kan man välja en orsak, som också sparas direkt, och den som är inloggad kan skriva en kommentar och spara den med *Save*. Raderna gallras efter 90 dagar och raderas med användaren. Strypningen gäller per IP och per användare, och det finns ingen ärendehantering och inget svar till användaren.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 464

### 472. Den nattliga hjälprapporten och help:feedback

GitHub #1586. En nattlig hjälprapport i loggen som visar var hjälpen brister: mest lästa artiklar, sökningar utan träff, artiklar med flest Nej, öppnade och okända sammanhang. Plus `php artisan help:feedback` som visar de senaste kommentarerna. Ingen adminsida, och rapporten läser bara — den skriver ingenting i databasen och larmar inte.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 470, 471

**Kvar efter milstolpen:** en adminsida (se [[Tankar]]), videorna och nya guider. Det är innehåll och inte kod.
