# M70 · Dashboardens hjälte

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-21, efter testarnas fynd samma dag. Bygger [[ADR-0090 Dashboardens hjälte]]: dashboardens översta rad ritas i en hjälte med bild, som containerns. Personen har en egen hjältebild, och utan den ritas en inbyggd standardbild. Profilbildens redigerare delas av profilbilden, hjältebilden och containerns bild, och containerns bild får zoom.

**Ordningen:** 433, sedan 434, sedan 435. **435 väntar på standardbilden:** Tony lägger den i `public/images/dashboard-hero.jpg` innan issuen plockas upp. Issuerna är skapade i GitHub (#1485–#1487) och sammanfattade här. GitHub-issuen är bindande.

---

### 433. Utsnittsredigeraren delas och containerns bild får zoom

GitHub #1485. Redigeraren lyfts ur `Settings/Profile.vue` till `ImageCropEditor.vue`, med formen rund eller bred. `container.cover_zoom` läggs till. Containerns utsnitt sparas med zoom och ritas med fokuspunkt och zoom överallt, och `ContainerCoverSheet` använder redigeraren.

**Läs:** [[ADR-0090 Dashboardens hjälte]] § 4 och 5, [[ADR-0076 Profilbilden]] § 7, [[Konton och åtkomst]] § container

**Axlar:** `ambiguity` low · `blast_radius` cross-module (profilbilden och containerns bild på alla ytor) · `risk_class` none

**Beror på:** —

### 434. Personens hjältebild

GitHub #1486. `user.hero_*` och skrivvägen enligt profilbildens regler: bara bilder, en åt gången, rensas direkt, räknas mot personkontot och rensas av `DeleteUser`. Bara personen själv ser bilden (`UserPolicy::viewHero`). Avsnittet *Dashboard image* på `/settings/profile`.

**Läs:** [[ADR-0090 Dashboardens hjälte]] § 2 och 7, [[ADR-0076 Profilbilden]], [[Konton och åtkomst]] § user

**Axlar:** `ambiguity` low · `blast_radius` cross-module (filleveransen och personraderingen) · `risk_class` elevated (kvoter, radering och filleverans)

**Beror på:** 433

### 435. Dashboardens hjälte

GitHub #1487. Bildlagret i `ContainerHero` blir `HeroFrame`. `DashboardHero` ritar hälsningen, rubriken, brickorna och donuten i personens bild eller i standardbilden. Under `md:` är hjälten ett lågt band med siffrorna under.

**Läs:** [[ADR-0090 Dashboardens hjälte]], [[ADR-0050 Desktopdesignen]] § 2–3

**Axlar:** `ambiguity` high (uppställningen finns bara i mockupen) · `blast_radius` cross-module (containerns hjälte) · `risk_class` none

**Beror på:** 433, 434, standardbilden
