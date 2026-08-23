# M9 · Missbruksskydd

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-08-04. Se [[ADR-0017 Missbruksvektorer]]. Principen där är att mäta före att spärra — de två första issuena är undantagen, eftersom de är billiga nu och obehagliga att införa retroaktivt.

### 48. Tak för utestående inbjudningar
Ett konto får ha högst N inbjudningar i `pending` samtidigt. Nekande ger felkod med gällande gräns, inte en färdig mening. Taket ligger i `plan.limits` som alla andra gränser, inte som konstant i koden.
**Läs:** [[Konton och åtkomst]] § invitation, [[ADR-0017 Missbruksvektorer]] § 5
**Klart när:** ett konto som fyllt taket nekas fler inbjudningar tills några accepterats, avvisats eller löpt ut — och en accepterad inbjudan frigör en plats.
**Beror på:** 10, 7

### 49. Ägarbytesbonusen en gång per mottagande konto
De tolv månaderna Pro ges vid **första** mottagna ägarbytet, inte vid varje. Kontrollen sitter i acceptflödets transaktion i issue 39, inte som ett separat jobb efteråt.
**Läs:** [[Konton och åtkomst]] § ownership_transfer, [[ADR-0017 Missbruksvektorer]] § 4
**Klart när:** ett konto som redan konsumerat bonusen tar emot en andra container utan att få ytterligare Pro-tid, och själva ägarbytet går igenom oförändrat.
**Beror på:** 39

### 50. Nattlig missbruksrapport
**Inte MVP** — bygg efter issue 26, den läser samma räknare. Fyra tal per vecka: nya gratiskonton, andel konton som aldrig laddat upp något, lagring per gratiskonto, utskickade mejl per konto. Plus de fyra listningarna i ADR:n: konton per registrerings-IP, konton med `managed`-åtkomst till fler än fem containers, containers skapade i kluster från samma IP, `stored_file` med hög `reference_count` över orelaterade konton.
Rapporten **larmar inte och spärrar ingenting** — den är underlag för att sätta trösklar som idag är gissningar.
**Läs:** [[ADR-0017 Missbruksvektorer]], [[Planer och kvoter]] § usage_counter
**Klart när:** rapporten kan köras på produktionsdata utan att skriva något, och registrerings-IP har en gallringsfrist och står i registerförteckningen.
**Beror på:** 26, 29
