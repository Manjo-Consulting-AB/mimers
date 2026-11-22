# M72 · Kategoridrag, anteckningsfältet och pluralen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-22 efter Tonys genomgång. Kategorins drag markerar bara målet under markören och raden följer med markören, anteckningsfältet börjar tre rader högt, och agentens raderingsnotis säger *1 thing* i singular. PDF-pärmen för Pro och krav på 2FA för delade containrar kom i samma genomgång men diskuteras innan de skrivs in.

**Ordningen:** 440 väntar på 432, 442 väntar på 406 och 441 står fritt. Issuerna är skapade i GitHub (#1510–#1512) och sammanfattade här. GitHub-issuen är bindande.

---

### 440. Kategorins drag markerar målet under markören

GitHub #1510. När en kategori dras på `/containers/{ulid}/categories` markeras i dag alla giltiga mål med blått på en gång, och bara handtaget följer markören. Raden får ett eget `hovered`-läge så att ringen ritas bara på målet under markören (också för *Top level*), och dragbilden blir hela raden via `setDragImage` med raden dämpad på sin plats. Inget dragbibliotek, inget under `md:`, och anropet och felhanteringen ändras inte.

**Läs:** `CategoryRow.vue` och `Containers/Categories.vue` (docblocken om draget), `KategorivyTest` (proven om draghandtaget)

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 432

### 441. Anteckningsfältet är tre rader högt

GitHub #1511. Anteckningsfältet på itemets översikt öppnas som en enda rad. `ItemNotes.vue` får `rows="3"` och en minsta höjd på tre rader som `fit()` aldrig krymper under, och fältet växer med texten som i dag. Platshållaren i läsläget får samma minsta höjd, och `ItemForm.vue` ändras inte.

**Läs:** `ItemNotes.vue` (docblocken och `fit()`)

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** —

### 442. Agentens raderingsnotis följer antalet

GitHub #1512. Notisen säger *deleted 1 things* på fyra ställen: klockan och `/notifications`, inkorgens rad, mejlets ämne och mejlets text. Klienten får en `deleted_one`-nyckel som `NotificationRow.vue` och ett nytt `case 'agent.deleted'` i `notificationPresentation.js` väljer när `total` är 1, utan någon pluraliseringsmekanism i `translate.js`. Servern skriver om strängarna i Laravels pluralform och använder `trans_choice` i `NotificationMail` och mallen.

**Läs:** `NotificationRow.vue`, `notificationPresentation.js`, `translate.js` (docblocken), `NotificationMail.php`, `mail/notification.blade.php`

**Axlar:** `ambiguity` low · `blast_radius` cross-module (klockan, inkorgen och mejlet) · `risk_class` none

**Beror på:** 406
