# M49 · Kartan fyller bredden

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-10, efter Tonys genomgång av containerkartan i v0.27.0: kartan stod som en smal ö mitt i panelen. Besluten står i [[ADR-0072 Kartan fyller bredden]].

**Releasen:** när 349 är stängd.

---

### 349. Kartan fyller bredden

GitHub #1028. **Läget:** rutnätet är låst till `md:max-w-[52.5rem]` med fyra kort på `w-48` per rad, så kartan lämnar en stor del av panelen tom, och fler barn ger fler rader i stället för bredare rader.

**Lösningen:** korten får tre fasta storlekar efter antalet barn per rad, barnen står på högst två jämna rader med upp till sex per rad, och vyn väljer uppställning ur kartans uppmätta ram. Servern ändras inte.

**Läs:** [[ADR-0072 Kartan fyller bredden]], [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 2

**Klart när:** uppställningen följer tabellen i ADR-0072 § 2 när ramen räcker och väljer fler kort per rad före större kort när den inte gör det; `md:max-w-[52.5rem]` är borta; den smala formen med `compact` är oförändrad; hela testsviten är grön.

**Beror på:** —
