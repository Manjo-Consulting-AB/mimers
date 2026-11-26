# ADR-0100 Agentregler

**Status:** Antagen 2026-11-26 · Gör ett undantag från [[ADR-0087 MCP-server för agenter]] § Riktning 9 · [[ADR-index]]

Tonys beslut 2026-11-26 om regler till agenten, ur en agents återkoppling från arbete i systemet.

## Kontext

- Behörigheterna (RWX per grupp, [[ADR-0087 MCP-server för agenter]] § Riktning 6) säger *vad* en agent får göra, inte *hur*. En användare kan inte säga *skapa aldrig kategorier* eller *skriv anteckningar på svenska*.
- Agenter hämtar sällan inställningar självmant.
- Serverns instruktioner och [[ADR-0087 MCP-server för agenter]] § Riktning 9 säger att all text i Mimers är användardata, så en försiktig agent ignorerar regler som står i ett vanligt fält.
- Text kan komma in utifrån (mallar, delade containrar, e-post), och reglerna får inte kunna skrivas om av den vägen.

## Beslut

*Tony 2026-11-26:*

1. Det finns två fält för agentregler: **användarens**, som följer användarens agenter överallt, och **containerns**, som gäller varje agent som når containern.
2. **Bara containerns ägare** (en medlem i ägarkontot) får skriva containerns regler. Ingen inbjuden får göra det, oavsett åtkomstnivå.
3. **En agent har ingen skrivrätt till reglerna.** De skrivs bara i appen, av en människa. Det finns inget MCP-verktyg och ingen REST-väg som ändrar dem.

*Utformad av Claude, godkänd av Tony 2026-11-26:*

- Reglerna kan bara snäva in vad agenten gör. Behörigheterna (RWX per grupp) och förbuden i [[ADR-0087 MCP-server för agenter]] § Riktning 7 och 10 kontrolleras på servern som förut, vad reglerna än säger.
- Reglerna levereras utan att agenten ber om dem: användarens i serverns `instructions` och i `containers_list`, containerns i `containers_list` per container och i `containers_tree`.
- Reglerna är det enda undantaget från att all text i Mimers är användardata. De bärs i ett eget fält, `agent_rules`, som säger vem som skrivit dem: `user` (den som anslöt agenten) eller `container_owner` (som kan vara någon annan). Krockar de går användarens regler före.
- Varje ändring sparar en revision med hela texten, vem och när. Containerns ändringar syns i containerns historik.
- Ren text, högst 2 000 tecken per fält. Tomt fält är `NULL`.
- Reglerna följer inte med en mall och nollställs vid ägarbyte (revisionerna står kvar hos containern).

Lagringen står i [[Konton och åtkomst]] § user, § container och § agent_rules_revision.

## Alternativ som valts bort

- **Regler på kontot i stället för användaren:** ett konto har exakt en användare ([[Konton och åtkomst]] § user), och reglerna gäller den som ansluter agenten.
- **Att även `write`- eller `delete`-mottagare skriver containerns regler:** reglerna styr andras agenter, och det är ägarens beslut som åtkomsten. Tony valde bara ägaren.
- **Att agenten får skriva med historik och en markering i appen:** en skrivväg för agenten är en väg för prompt injection att skriva om reglerna, och en markering i efterhand hindrar inget. Tony valde ingen skrivrätt.
- **En egen endpoint `agent_rules_get`:** agenter anropar den inte utan att någon säger åt dem.
- **Att reglerna aldrig följer med en mall:** en mall ska ge en färdig uppsättning, och efter [[ADR-0101 Mallarna]] kan en mall bara komma från kontot självt eller från Mimers (Tony 2026-11-26).
- **Leverans vid agentens första anrop mot en container:** servern är tillståndslös per anrop och vet inte vad som är först. `revision` i svaren fyller samma behov.

## Konsekvenser

- Issue 512–514 och 517 i [[M95 Agentregler och mallarna]].
- Den fasta texten i `MimersServer::$instructions` ändras (citerad i #1731).
- [[ADR-0087 MCP-server för agenter]] § Riktning 9:s *Innehåll märks som data* gäller allt utom fältet `agent_rules`.
