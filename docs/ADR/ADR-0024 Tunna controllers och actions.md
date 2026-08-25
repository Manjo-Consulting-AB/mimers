# ADR-0024 Tunna controllers och actions

**Status:** Antagen 2026-08-25 · [[ADR-index]]

## Kontext

Efter M0 finns femton controllers, och ett mönster som ingen skrivit ner men som alla följer: en controllermetod validerar med en FormRequest, avgör behörighet, ropar på något som gör jobbet, och formar svaret. Ingen av dem är lång.

Det som gör jobbet har däremot fått **två namn för samma idé**. Under `app/Support/Auth/` ligger `TotpBroker`, `MagicLinkBroker` och `RecoveryCodeBroker` — `final`, statiska, tillståndslösa. Under `app/Actions/Auth/` ligger `CreatesUserWithPersonalAccount` — en instansklass med `handle()` som löses upp av containern. Skillnaden är historisk, inte designad.

Frågan blev akut i issue 8. Den controllern behövde ingendera — ren CRUD där enda regeln är policyn — men issue 11 (cykelkontroll när en kategori flyttas), 14 (normalisering av item-relationer) och 15 (sökning som aldrig får läcka mellan containers) kommer alla be om ett samarbetsobjekt. Utan ett skrivet beslut väljer varje agent själv, och M1 får båda formerna om vartannat.

Samtidigt kom en näraliggande fråga: ska basklassen `App\Http\Controllers\Controller` bära Laravels `AuthorizesRequests`-trait, så att controllers kan skriva `$this->authorize()`?

## Beslut

**Controllern är ett tunt skal.** Fyra steg, i den ordningen: FormRequest validerar, `Gate::authorize()` avgör behörighet, ett samarbetsobjekt utför, en Resource formar svaret. Domänregler bor aldrig i controllern, och behörighetsregler aldrig heller — de senare hör till en policy, se [[ADR-0003 Åtkomstmodell]].

**Klassiska resurscontrollers med Laravels verb** — `index`, `store`, `show`, `update`, `destroy`. En invokable controller används bara när det verkligen finns exakt en handling, som `VerifyEmailController`. Många metoder är inget problem när ingen av dem är lång.

**Behörighet anropas med `Gate::authorize()`, inte `$this->authorize()`.** `AuthorizesRequests`-traiten läggs inte till i basklassen. `$this->authorize()` är syntaktiskt socker som internt gör exakt samma anrop och kastar samma `AuthorizationException`; fasadanropet fungerar dessutom oförändrat från ett jobb, en Action eller ett konsolkommando, medan traiten binder auktoriseringen till att koden råkar bo i en controller.

**Nya samarbetsobjekt skrivs som `app/Actions/<Domän>/<VerbSubstantiv>` med en `handle()`-metod**, injicerad i konstruktorn eller metoden. Instansklass, inte statisk.

**En Action skrivs när skrivningen bär en regel som är värd ett eget test.** Bär den ingen — ren CRUD där policyn är hela regeln — skrivs den rakt i controllern. `ContainerController` i issue 8 är exemplet: en Action där hade varit ceremoni.

**De statiska brokerna under `app/Support/Auth/` står kvar oförändrade.** De skrivs inte om till Actions.

## Motivering

Instansformen är den som går att byta ut. En Action kan mockas i test, injiceras med sina egna beroenden och anropas från ett jobb eller ett kommando lika enkelt som från en controller. Statiska anrop kan inget av det, och kostnaden syns först när något ska testas isolerat eller köras från en annan ingång — vilket är precis vad M3 (schemalagda uppgifter) och M5 (notiser) kommer kräva.

Att ändå lämna brokerna ifred är inte inkonsekvens utan prioritering. De fungerar, de har tester, och de är statiska av ett skäl: `LoginRequest` anropar `TotpBroker` och `RecoveryCodeBroker` från en FormRequest där konstruktorinjektion är omständlig. Att skriva om fungerande kod för namnkonsekvensens skull är churn utan mottagare.

Tröskeln "värd ett eget test" är den enda som går att tillämpa utan att tveka. Alternativet — en Action per skrivning — ger en klass som bara vidarebefordrar `$request->validated()` till `Model::create()`, och den klassen gör koden svårare att följa, inte lättare.

## Konsekvenser

- **`app/Actions/` växer, `app/Support/` gör det inte.** `app/Support/` förblir platsen för infrastruktur som inte är domänlogik — `ApiError`, `ValidationErrorMapper`, `LoginRateLimiter`.
- **Issue 11, 14 och 15 får varsin Action.** Cykelkontroll, relationsnormalisering och åtkomstfiltrerad sökning bär alla en regel som ska ha ett eget test.
- **Issue 9, 10, 12 och 13 gör det förmodligen inte.** Åtkomstmodellen är en policy, och kategorier, taggar och item är CRUD.
- **Ingen ändring i befintlig kod.** Den här ADR:n beskriver hur nytt skrivs, inte hur gammalt görs om.
- **Basklassen `Controller` förblir tom.** Ser en agent `$this->authorize()` i ett exempel utifrån är det fel i det här projektet.

## Alternativ

**Actions överallt, en per skrivning.** Full konsekvens, ingen tröskel att tolka. Valdes bort — de flesta CRUD-skrivningar får då en klass som inte gör något, och läsaren måste öppna två filer för att se att inget händer i den ena.

**Statiska brokers även för nytt.** Följer den största befintliga gruppen och är bekvämt att anropa. Valdes bort — går inte att byta ut i test och inte att ge egna beroenden, vilket börjar kosta i M3 och M5.

**`AuthorizesRequests` i basklassen.** Ger den kortare `$this->authorize()` som Laravels egen dokumentation visar. Valdes bort — identisk funktion, men binder auktoriseringen till controllerlagret och gör basklassen till en plats där traits samlas.

**Single Action Controllers rakt igenom.** Varje endpoint en egen invokable klass. Valdes bort — femton controllers blir femtio filer utan att någon av dem blir tunnare, och de resursverb Laravel redan har namnger handlingen lika tydligt som ett klassnamn skulle.
