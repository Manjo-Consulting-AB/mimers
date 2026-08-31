# M1 · Kärnmodell

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

### 8. Container
CRUD. `account_id` som ägare, `kind` för presentation, `template_source_id` förberedd men oanvänd.
**Läs:** [[Konton och åtkomst]], [[ADR-0002 Konto äger container]]
**Beror på:** 3

### 9. Åtkomstmodell och behörighetspolicy
`container_access` med alla fyra formerna. **En** central policy — behörighetslogik får inte spridas i controllers.
**Läs:** [[Konton och åtkomst]] § Behörighetsregler, [[ADR-0003 Åtkomstmodell]]
**Klart när:** de fem reglerna i behörighetsavsnittet har varsitt test, inklusive att `write` inte får radera containern eller hantera åtkomster, och att `read_only`-konto nekas allt skrivande.
**Beror på:** 8
**Byggd som:** 9a åtkomstmodell och policy, 9b åtkomstytan (bevilja, lista, återkalla), 9c deltagarlista

### 10. Inbjudningar
Inbjudan till e-postadress utan konto. Pending tills accepterad, avvisad eller utgången. Token lagras som hash.
**Läs:** [[Konton och åtkomst]], [[ADR-0003 Åtkomstmodell]]
**Klart när:** mottagaren måste skapa konto och verifiera e-post för att acceptera.
**Beror på:** 9
**Byggd som:** 10a tabell och avsändaryta, 10b mejl, accept och avvisande

### 11. Kategorier
Hierarki per container. Djupbegränsning och **cykelkontroll vid flytt**.
**Läs:** [[Items och organisation]], [[ADR-0004 Fria taggar och kategorier]]
**Klart när:** en kategori inte kan få sin egen ättling som förälder.
**Beror på:** 8

### 12. Taggar
Platt lista per container, unikt namn per container.
**Läs:** [[Items och organisation]]
**Beror på:** 8

### 13. Item
CRUD med alla fält, högst en kategori, flera taggar. Index enligt dokumentet.
**Läs:** [[Items och organisation]]
**Beror på:** 11, 12
**Byggd som:** 13a tabellen, modellen och CRUD-ytan, 13b item och taggar (`item_tag`)

### 14. Relationer mellan items
`item_link` med parent/child/sibling. Relationen lagras **en gång** och motsatsen härleds. Sibling normaliseras till lägst id först. Cykelkontroll för parent/child.
**Läs:** [[Items och organisation]]
**Beror på:** 13

### 15. Sök och filtrering
Scout med databasdrivern. FULLTEXT-index, filtrering på tagg (flera med OCH), kategori inklusive underkategorier, fritext.
**Läs:** [[ADR-0012 Sök]], [[Items och organisation]] § Sök och filtrering
**Klart när:** ett test visar att sökning **aldrig** returnerar items från containers användaren saknar åtkomst till.
**Beror på:** 13
**Byggd som:** 15a filtrering på tagg och kategori, 15b fritextsök över containers
