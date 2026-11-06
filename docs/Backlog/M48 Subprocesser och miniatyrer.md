# M48 · Subprocesser och miniatyrer

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-06, efter att kontot höjts till PHP 8.5 och `proc_open` öppnats i inleeds panel. Det här ingår: köarbetaren får en egen process med dagens väg som reserv, och HEIC, HEIF och TIFF får miniatyrer. Besluten står i [[ADR-0070 Subprocesser hos inleed]] och [[ADR-0071 Miniatyrer med Imagick]].

**Ordningen:** 347 och 348 står fritt från varandra.

**Releasen:** en gemensam när båda är stängda. **Innan 348 rullas ut** måste `imagick` vara aktiverat för PHP 8.5 i inleeds panel. Annars får de tre typerna inga derivat, vilket är en försämring och inte ett fel.

---

### 347. Köarbetaren i egen process

GitHub #1018. **Läget:** `drain-queue` kör `queue:work` inne i schemaläggarens process ([[ADR-0031 Köarbetaren körs av schemaläggaren]]), så en jobbtimeout tar med sig resten av körningen och kön delar minnestak med allt annat.

**Lösningen:** posten blir `Schedule::command('queue:work', ...)->runInBackground()` när `proc_open` finns, och dagens `Schedule::call` med en varning i loggen när den inte gör det. `retro-fakta` skriver ut `disable_functions`.

**Läs:** [[ADR-0070 Subprocesser hos inleed]], [[ADR-0031 Köarbetaren körs av schemaläggaren]], [[ADR-0029 Agentens läsåtkomst till servern]]

**Klart när:** båda lägena registrerar `drain-queue` med samma flaggor och lås; sync hoppas över i båda; `retro-fakta` visar `disable_functions`; hela testsviten är grön.

**Beror på:** —

### 348. Miniatyrer av HEIC och TIFF med Imagick

GitHub #1019. **Läget:** `GenerateImageDerivatives` ritar bara med GD, så en bild från en iPhone (HEIC) och en TIFF visas utan miniatyr och kan inte bli omslag.

**Lösningen:** de tre typerna läses med Imagick med uttryckligt format och resursgränser, och skrivs som JPEG i båda varianterna. Inget PDF och ingen delegat.

**Läs:** [[ADR-0071 Miniatyrer med Imagick]], [[Filer och lagring]] § image_derivative

**Klart när:** TIFF och HEIC får båda varianterna som JPEG, även när originalet är litet; EXIF-orienteringen följer med; en PDF förklädd till TIFF ger inga derivat; GD-typerna är oförändrade; hela testsviten är grön.

**Beror på:** —
