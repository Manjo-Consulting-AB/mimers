# Tankar

Frågor **utan svar**. Så fort en punkt här är besvarad flyttar den till rätt dokument — datamodellen om den beskriver *vad*, en ADR om den beskriver *varför* — och stryks härifrån. Står något kvar som redan är avgjort blir filen värdelös som lista över vad som faktiskt återstår.

Öppna frågor som redan hör hemma i ett beslut bor där, inte här: S3-frågan och prispunkten i [[Översikt]] § Öppna frågor, och de fem sakerna att verifiera hos inleed i [[ADR-0018 Utvecklingsprocess och deploy]].

## Öppet

Inget just nu. Nya frågor läggs till här.

## Avgjort och flyttat

Punkterna nedan låg här som frågor och är besvarade. De står kvar som spår av var svaret hamnade, inget annat.

- Underkategorier, och om ett item kan tillhöra flera kategorier → [[Items och organisation]] § category. Kategorier är hierarkiska via `parent_id`, ett item tillhör högst en. Taggar är platta, medvetet.
- Fil-dedup via innehållshash och radering när sista referensen försvinner → [[Filer och lagring]] och [[ADR-0006 Innehållsadresserad lagring]].
- Utlåning med påminnelse → [[Items och organisation]] § loan. Påminnelsen går till den som lånat ut, aldrig till låntagaren; skälet står i [[ADR-0017 Missbruksvektorer]] § 7.
- Frontendteknik → [[ADR-0021 Frontendteknik]].
- GitHub-org och repo-struktur → [[Pipeline]] § Repo och organisation. Orgen bär bolagsnamnet, ett repo per app, koden i `Manjo-Consulting-AB/mimers`.
