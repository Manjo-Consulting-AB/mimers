# M44 · Fynd efter v0.24.1

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-19, efter Tonys fynd på v0.24.1. Det här ingår: en uppgiftslista i itemets anteckning ritas med både punkt och checkbox. Inget av det kräver ett nytt beslut.

**Ordningen:** 328 står fritt.

**Releasen:** ingen egen. 328 rullas ut tillsammans med [[M43 Trädet som förval]], när båda är stängda.

---

### 328. Uppgiftslistan i anteckningen utan punkt

GitHub #971. **Felet:** `RenderItemNotes` ritar `- [ ] Test` som `<ul><li><input disabled type="checkbox"> Test</li></ul>` (issue 324). Ritningen är rätt. Men typografin från issue 325 ger varje `ul` i `.item-notes-content` `list-style: disc`, så raden får en punkt före checkboxen. league/commonmark sätter ingen klass som `task-list-item` på raden, och det finns ingen regel som tar bort punkten.

**Lösningen:** en regel i `resources/css/app.css` för en listrad vars första barn är en checkbox: `.item-notes-content li:has(> input[type="checkbox"])` får `list-style: none` och en negativ vänstermarginal lika med listans `padding-left`, så att checkboxen står där punkten annars hade stått. En vanlig punktlista och en numrerad lista ritas som förut, och en lista som blandar rader med och utan checkbox behåller punkten på raderna utan. Ritningen i `RenderItemNotes` rörs inte.

**Läs:** `resources/css/app.css` (`.item-notes-content`), `tests/Feature/Frontend/AnteckningsrutaTest.php` (provet om typografin)

**Klart när:** en rad med checkbox i anteckningen ritas utan punkt, med checkboxen i punktens kolumn; en vanlig punktlista och en numrerad lista ritas som förut; `RenderItemNotes` är oförändrad; hela testsviten är grön.

**Beror på:** —
