# M78 · Hjälpsidan

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-22 efter Tonys FAQ-utkast. `/help` går från platshållare till vanliga frågor i nio kategorier.

**Ordningen:** 458 står ensam. Issuen är skapad i GitHub (#1541) och sammanfattad här. GitHub-issuen är bindande.

---

### 458. Hjälpsidan med vanliga frågor

GitHub #1541. Texterna flyttas från `docs/Design/mimers-faq-utkast/en/` till `lang/en/help/` och skrivs inte om. `LoadHelpArticles` ritar markdown på servern med `html_input => 'strip'`, och varje fråga får ett ankare ur `Str::slug`. Sidan `Help.vue` är det andra undantaget från regeln om `v-html`, efter itemets anteckning. Menyn får en rad *Help* ovanför *Trash* med den nya ikonrollen `help`.

**Axlar:** `ambiguity` low · `blast_radius` cross-module (skalets meny och ikonlistan) · `risk_class` none

**Beror på:** att utkasten är committade.

**Kvar efter milstolpen:** formuläret för intresseanmälan som *register your interest here* i `01-getting-started.md` väntar på, och videorna där utkastet hade `<!-- video -->`.
