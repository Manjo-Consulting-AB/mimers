# M51 · Sårbarhetsskanning

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-12, i samtalet om dragpaketet i [[ADR-0073 Översiktens ordning]] § 6. CI i mimers skannar i dag varken beroenden eller vår egen kod: ingen `composer audit`, ingen `npm audit`, ingen Dependabot och ingen Semgrep. Tony beslutade att det ska till, oavsett dragpaketet. Inget av det kräver en ADR. Det är ett tillägg till CI, inte ett ändrat beslut.

**Releasen:** ingen egen. Ändringen gäller CI och rullas inte ut.

---

### 355. Sårbarhetsskanning i CI

GitHub #1117. **Läget:** `.github/workflows/ci.yml` bygger, lintar och testar, men en känd sårbarhet i ett Composer- eller npm-paket, eller ett osäkert mönster i vår egen kod, fäller inte bygget.

**Lösningen:**
- Ett steg i jobbet `test` i `ci.yml` kör `composer audit` och `npm audit --audit-level=high` och fäller bygget vid träff.
- `.github/dependabot.yml` bevakar `composer`, `npm` och `github-actions` en gång i veckan, med `open-pull-requests-limit: 2` per ekosystem. Dependabots PR:er bär ingen `review:approved` och mergas därför inte av kön.
- Ett steg kör Semgrep med regelpaketen för PHP, Laravel och JavaScript på varje PR. Det börjar som rapporterande och fäller bygget först när det befintliga bruset gåtts igenom.

**Inte `--omit=dev`.** Vue och de andra paketen som byggs in i `public/build/` står bland `devDependencies`, så de skulle annars inte granskas.

Skanningen läggs som steg i det befintliga jobbet och inte som egna jobb, så att varje körning inte startar en ny maskin ([[Pipeline]] § Minutbudgeten). Larmen för Dependabot slås på i repots inställningar. Det gör Tony.

**Läs:** [[Pipeline]] § `.github/workflows/ci.yml` och § Minutbudgeten

**Klart när:** en PR med ett Composer- eller npm-paket med en känd sårbarhet på nivån high fäller CI; `.github/dependabot.yml` finns och är giltig; Semgrep körs på varje PR och lämnar sina fynd i körningen; ett docs-only-bygge hoppar över skanningen som det hoppar över testerna.

**Beror på:** —
