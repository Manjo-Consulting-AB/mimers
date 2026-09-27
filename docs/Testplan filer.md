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

### Skrivna i issue 148

Proven R1–R8 bor i `Attachment/ReferensrakningLivscykelTest`, ett prov per
rad. Proven prövar invarianten genom de vägar som leder fram till den —
papperskorgen, containergallringen, kontoraderingen, nedgraderingen och den
fysiska raderingen — och rör ingen produktionskod.

R7 är invariantprovet: en fast följd av operationer över två konton, med en
kontroll av `reference_count` mot antalet ogallrade `attachment`-rader för
varje `stored_file` efter varje steg. Följden är fast, inte slumpad, så att
ett rött prov går att köra om.

| Prov | Vad det bevisar |
|---|---|
| R1 · *samma bytes från två olika konton delar stored_file…* | Invariant 1 över kontogränsen, och invariant 4: båda kontona räknar hela `byte_size`. |
| R2 · *samma bytes i två containers delar stored_file…* | Invariant 1 över containergränsen. Gallras den ena står bytena kvar och räknaren är ett. |
| R3 · *återställning ur papperskorgen rör inte räknaren…* | Invariant 2: varken en återställd bilaga eller ett återställt item med bilagor rör räknaren. |
| R4 · *containergallringen minskar räknaren en gång per bilaga…* | `PurgeContainer` minskar räknaren en gång per bilaga, också för bilagor på mjukraderade items i containern. |
| R5 · *kontoraderingen lämnar räknaren rätt…* | `DeleteAccount` lämnar räknaren rätt för bytes som ett annat konto också refererar. |
| R6 · *nedgraderingens radering går genom papperskorgen…* | `EnforcesDowngrades` går genom `TrashAttachment`; räknaren minskar först vid gallringen. |
| R7 · *invarianten håller efter varje steg i en fast följd över två konton* | Invariant 2 som helhet, efter varje steg. Provet fångar en väg ingen har tänkt på. |
| R8 · *när bytena raderas fysiskt försvinner miniatyrens rad och fil* | Invariant 5: derivatens rader och filer följer sina byten hela vägen till den fysiska raderingen. |

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

### Skrivna i issue 149

Proven A1–A8 bor i `Attachment/FilatkomstTest`, ett prov per rad. Proven
prövar invarianten genom de vägar in som inte var bevisade — varianten, den
utgångna åtkomsten, det lämnade kontot, den mjukraderade containern på varje
yta, länken som överlever en återkallelse, vad ett nekat svar bär, listorna
och resursernas fält — och rör ingen produktionskod.

Omfånget i proven är en itemgrant på ett item, och det item som ligger
utanför är ett annat på toppnivå i samma container. Fixturen är platt med
flit: att slutningen går nedåt genom förälderkanter prövas av
`Omfang/ItemgrindTest` och `Omfang/OmfangsupplosningTest`, och den frågan
ställs inte om här.

| Prov | Vad det bevisar |
|---|---|
| A1 · *variant=thumb på ett item utanför omfånget ger 403 och präglar ingen länk* | Invariant 1 för varianten: miniatyren prövas mot samma omfång som originalet, och präglingen ligger efter grinden. Miniatyren finns på båda bilagorna, så 403:an kommer av omfånget och inte av en saknad variant. |
| A2 · *en utgången tidsbegränsad åtkomst nekas, både vid leveransen och vid präglingen* | Invariant 3 för den andra spärren: ett passerat `expires_at` nekar både när appdomänen strömmar bytena själv och när den präglar en länk. |
| A3 · *en användare vars account_user-rad tagits bort når inte längre kontots bilagor* | Invariant 3: medlemskapet ÄR behörigheten. Raden borttagen ger 403, och ingen grant finns kvar som andra väg in. |
| A4 · *en bilaga i en mjukraderad container ger 404 för varianten, för token och i listan* | Invariant 2 på varje yta: varianten, API-klientens token och bilagelistan nekar alla när containern ligger i papperskorgen. |
| A5 · *en länk som präglats före en återkallelse fungerar till den går ut* | Invariant 4 och gränsen för den: signaturen är den enda grinden på filoriginet, så en länk som präglats före en återkallelse lever till den går ut — högst femton minuter. Provet dokumenterar gränsen. |
| A6 · *403 och 404 läcker varken filnamn, typ eller storlek* | Invariant 5 i det nekade svaret: varken kroppen eller rubrikerna bär filnamn, typ, storlek, hash eller sökväg. |
| A7 · *ingen av listorna tar med en bilaga på ett item utanför omfånget* | Invariant 1 i listorna: itemets bilagelista i webben och `/api`, exporten och lagringsvyn. Exporten beställs av en itemgrant-innehavare — grinden är containerns `view`, som en itemgrant passerar — och bär ändå bara beställarens omfång. |
| A8 · *ingen resurs som bär en bilaga innehåller content_hash eller storage_path* | Invariant 5 i resurserna: `StorageEntryResource` i lagringsvyn och bilagelistan i webben (samma `AttachmentResource`). `/api`:s bilagelista är redan bevisad av `Attachment/BilagelistaTest` och skrivs inte om. |

Två gränser står kvar med flit, och proven dokumenterar dem i stället för att
kräva dem ändrade: att 403 och 404 skiljer sig åt — en ULID går inte att
gissa — och att en redan präglad länk överlever en återkallelse under sin
livstid.

---

## Utanför planen

- **Dedup mellan uppladdningar som pågår samtidigt.** Två samtidiga uppladdningar av samma bytes kan kapplöpa om `stored_file`-raden. Det kräver en MariaDB-svit för att provas ärligt och väntar på den, se [[Tankar]] § Öppet om testsviten.
- **Filoriginet i produktion.** Att `files.mimers.app` verkligen svarar 403 på en direkt sökväg bevisas av `deploy/verifiera-filleverans.sh` vid utrullningen, inte av sviten.
