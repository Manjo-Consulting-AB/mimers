# Testplan filer

Vad som ska vara bevisat om filerna innan testarna kommer in, och vilka prov som bevisar det. Tillbaka till [[00 Index]].

Skriven 2026-09-26 för [[M22 Redo för testare]]. Två ytor är `risk_class: elevated` och ska bevisas av bestående tester, inte av en genomgång per release: **att en uppladdning lagras en gång och att bytena försvinner först när ingen längre refererar dem**, och **att en fil inte går att nå obehörigt**. Reglerna står i [[Filer och lagring]], [[ADR-0006 Innehållsadresserad lagring]], [[ADR-0008 Soft delete och papperskorg]] och [[ADR-0019 Filleverans]].

Planen utgår från sviten som den ser ut i dag. Mycket är redan bevisat. Tabellerna nedan säger var, så att ingen skriver om ett prov som finns. Luckorna är det som blir issues, 148 och 149.

**Hittar ett nytt prov ett fel ändras inte produktionskoden i samma PR.** Provet skrivs, markeras `->todo()` med en rad om vad det visar, och felet beskrivs under `## Frågor och antaganden`. Det blir en egen issue. En testplan som lagar det den hittar blir en omskrivning som ingen har granskat som en sådan.

---

## Del 1 · Dedup och referensräkning

### Invarianterna

1. **En uppsättning bytes lagras en gång**, oavsett filnamn, uppladdare, konto eller container.
2. **`stored_file.reference_count` är antalet `attachment`-rader som pekar på filen och ännu inte gallrats.** En mjukraderad bilaga räknas, eftersom den kan återställas.
3. **`purge_after` sätts när räknaren når noll och nollställs när den lämnar noll.** Bytena raderas tidigast när `purge_after` passerat.
4. **Kvoten räknar det användaren upplever**, alltså summan av `byte_size` för det betalande kontots levande bilagor, oberoende av dedup.
5. **Derivaten följer originalet.** De räknas inte mot kvoten och raderas med sin `stored_file`.

### Redan bevisat

| Invariant | Prov |
|---|---|
| 1, samma innehåll ger en `stored_file` | `Attachment/UppladdningTest` — *samma innehåll två gånger…*, *…under två olika filnamn…*, *olika innehåll ger två…* |
| 2, räknaren vid gallring av en bilaga | `Attachment/ReferensrakningTest` — hela filen |
| 3, `purge_after` och den fysiska raderingen | `Attachment/ReferensrakningTest`, `Attachment/FysiskRaderingTest` |
| 2, ett gallrat item tar med sig sina bilagor | `Trash/GallringTest` — *ett gallrat item tar med sig sina bilagor* |
| 4, förbrukningen | `Kvot/ForbrukningTest`, `Kvot/AvstamningTest` |

### Luckor, issue 148

- **R1. Samma bytes från två olika konton** delar `stored_file`, och båda kontonas förbrukning räknar hela `byte_size`.
- **R2. Samma bytes i två olika containers** delar `stored_file`. Gallras den ena containern står bytena kvar och räknaren är ett.
- **R3. Återställning ur papperskorgen** ändrar inte räknaren, varken för en bilaga eller för ett item med bilagor.
- **R4. Containergallringen** (`PurgeContainer`) minskar räknaren en gång per bilaga, också för bilagor på mjukraderade items i containern.
- **R5. Kontoraderingen** (`DeleteAccount`) lämnar räknaren rätt för bytes som ett annat konto också refererar.
- **R6. Nedgraderingens radering** (`EnforcesDowngrades`) går genom samma väg som en vanlig radering och minskar räknaren därefter.
- **R7. Invarianten 2 som helhet:** efter en blandad följd av uppladdningar, raderingar, återställningar och gallringar över två konton är `reference_count` lika med antalet ogallrade `attachment`-rader, för varje `stored_file`. Ett prov, inte ett per väg: det är provet som fångar en väg ingen har tänkt på.
- **R8. Derivaten:** när bytena raderas fysiskt försvinner också miniatyrens rad och fil.

---

## Del 2 · Obehörig åtkomst

### Invarianterna

1. **Varje väg till bytena prövar samma behörighet** som itemet: åtkomst till containern, och sedan [[ADR-0028 Åtkomst på itemnivå]], omfånget.
2. **Mjukraderat innehåll levereras inte**, varken bilagan, itemet eller containern.
3. **En återkallad eller utgången åtkomst slutar fungera** vid nästa begäran.
4. **Filoriginet levererar bara via en giltig signatur**, och den signerade länken präglas först efter behörighetsprovet.
5. **Lagringssökvägen och hashen läcker aldrig**, varken i svar, filnamn eller felmeddelanden.

### Redan bevisat

| Invariant | Prov |
|---|---|
| 1, containeråtkomst, `read`-deltagare, token och session | `Attachment/NedladdningTest` |
| 1, omfånget på itemnivå | `Omfang/ItemgrindTest` — *nedladdning av en bilaga på ett item utanför omfånget ger 403* |
| 2 | `Attachment/NedladdningTest`, `Filleverans/FiloriginTest` |
| 3, återkallad åtkomst | `Attachment/NedladdningTest` — *en återkallad åtkomst slutar fungera omedelbart* |
| 4 | `Filleverans/FiloriginTest` — signatur, utgång, manipulation, variant |
| 5, i uppladdningens svar | `Attachment/UppladdningTest` — *svaret bär aldrig content_hash…* |
| Exportens nedladdning | `Export/ExportNedladdningTest` |
| Webbrotens skydd på servern | `FilleveransUtrullningTest` |

### Luckor, issue 149

- **A1. Omfånget gäller varianten också:** `variant=thumb` på ett item utanför omfånget ger 403, och ingen signerad länk präglas.
- **A2. En utgången tidsbegränsad åtkomst** (`container_access.expires_at` passerat) nekas, både vid nedladdning och vid präglingen.
- **A3. Ett konto som lämnats:** en användare vars `account_user`-rad är borttagen når inte längre bilagor i kontots containers.
- **A4. En bilaga i en mjukraderad container** ger 404 också för `variant=thumb`, och också via `/api`.
- **A5. En signerad länk efter återkallelse:** en länk som präglats före en återkallelse fungerar tills den går ut, högst femton minuter. Provet dokumenterar gränsen, så att den som ändrar den ser det.
- **A6. 403 och 404 läcker inget:** svaret på en bilaga man inte når innehåller varken filnamn, typ eller storlek. Att 403 och 404 skiljer sig åt, och därmed avslöjar att en ULID finns, är accepterat: en ULID går inte att gissa.
- **A7. Bilagelistorna läcker inte:** varken itemets bilagelista, lagringsvyn (`/settings/storage`) eller exporten tar med en bilaga på ett item utanför omfånget.
- **A8. Invarianten 5 i alla svar:** ingen resurs som bär en bilaga (`AttachmentResource`, `StorageEntryResource`, bilagelistan i webben) innehåller `content_hash` eller `storage_path`.

---

## Utanför planen

- **Dedup mellan uppladdningar som pågår samtidigt.** Två samtidiga uppladdningar av samma bytes kan kapplöpa om `stored_file`-raden. Det kräver en MariaDB-svit för att provas ärligt och väntar på den, se [[Tankar]] § Öppet om testsviten.
- **Filoriginet i produktion.** Att `files.mimers.app` verkligen svarar 403 på en direkt sökväg bevisas av `deploy/verifiera-filleverans.sh` vid utrullningen, inte av sviten.
