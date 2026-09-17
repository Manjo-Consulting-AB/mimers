# Att sortera efter mockuparna

Del av [[Backlog]]. **Det här är ingen milstolpe.** Det är en hållplats för arbete som är identifierat men ännu inte inplacerat, i väntan på genomgången av mockuparna.

Listan kommer ur genomgången av MVP:n 2026-09-17. Tre av punkterna kan visa sig vara överflödiga när designen är känd, och minst en kan visa sig vara större än den ser ut — därför ligger de här i stället för i en milstolpe som påstår sig veta ordningen.

**En post lämnar den här filen när den blir en issue i en milstolpe.** Står något kvar här som redan är byggt blir filen värdelös, precis som [[Tankar]] § Öppet.

---

### 83. Den aktiva containern gör ingenting
Att "göra en container aktiv" har en hel mekanism bakom sig och ingen synlig verkan. `App\Support\Frontend\ActiveContainer` äger en sessionsnyckel som sätts på fyra ställen — när en container skapas, när en inbjudan antas, när ett ägarbyte tas emot, och av `ActiveContainerController` bakom `PUT /containers/{container}/active`. `HandleInertiaRequests` delar ut ULID:t som propen `activeContainer`. Sedan tar det slut: den enda konsumenten är `resources/js/pages/Containers/Index.vue`, som sätter en markering på raden. `ContainerLayout` läser den avsiktligt inte, och `AppLayout` har ingen containerväljare.

Användaren möter alltså en knapp som ändrar en markering i en lista och ingenting annat. Att inte förstå vad den gör är rätt slutsats.

**Frågan är inte om den ska bort, utan om den ska få en uppgift.** Mekanismen är precis det en containerväljare i navigeringen skulle vila på — issue 51 § Beslut 4 införde den för det ändamålet, och issue 55 valde bort väljaren med hänvisning till att `/containers` är ytan där man byter. Har mockuparna en väljare i navigeringen är borttagningen en återuppbyggnad om två veckor. Har de det inte, är städningen självklar.

**Därför ligger den här och inte i en milstolpe.** Avgörs vid mockupgenomgången, byggs inte före den.

Städningen, om det blir den, rör `App\Support\Frontend\ActiveContainer`, `App\Http\Controllers\ActiveContainerController`, rutten `containers.active`, den delade propen i `HandleInertiaRequests`, anropen till `set()` i `ContainerController`, `InvitationResponseController` och `OwnershipTransferController`, markeringen i `Containers/Index.vue` — och fem testfiler som påstår något om beteendet: `AktivContainerTest`, `DeladePropsTest`, `ContainervyerTest`, `ContainerpapperskorgTest` och `InbjudanMottagareTest`. Det är `blast_radius: cross-module`: den delade propen är något andra issues byggt på.

**Läs:** `app/Support/Frontend/ActiveContainer.php`, issue 51 § Beslut 4 och issue 55 i [[M10 Webbfrontend]]
**Beror på:** mockupgenomgången

---

## Ännu inte issues

**Luckorna i kontot.** Byta lösenord. Byta e-post — egen issue och `risk_class: elevated`, för med tvingande tvåfaktor blir ett e-postbyte utan kodkrav en väg runt andra faktorn, samma klass av hål som issue 80 stängde. Inbjudningar syns i dag bara som mejl och inte när användaren loggar in.

**Verifieringarna.** Att en uppladdning bara lagras en gång (dedup och referensräkning) och att filer inte går att nå obehörigt ska bevisas av bestående tester, inte av en genomgång per release. Båda ytorna är `risk_class: elevated` enligt [[AGENTS.md]] § De tre axlarna.

**Kalenderfeedens namn** hämtas från URL:en i stället för produktnamnet och containerns namn.

**Notiser vid uppgift.** När skickas de? Frågan är först en uppslagning i [[Notiser]] och blir en issue bara om svaret och beteendet går isär.

**Delsträngssök kräver MariaDB i CI.** `LIKE '%ord%'` är dagens beteende via databasdrivaren; FULLTEXT-grenen i [[ADR-0012 Sök]] är avstängd tills sviten kan köras mot MariaDB. Den frågan står redan i [[Tankar]] § Öppet, rest i issue 2 och halvt besvarad 2026-09-03 — den behöver inte resas igen, den behöver avgöras.

**Designsystemet och genomgången vy för vy.** Väntar på mockuparna per definition. Tokens och kärnkomponenter före sidor, annars blir varje vy ett frihandsjobb.

**Miniatyrer i itemlistan** står redan som öppen fråga i [[Tankar]] § Öppet, rest när 61b skrevs. Den avgörs av designen och behöver inget eget spår här.
