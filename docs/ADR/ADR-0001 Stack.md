# ADR-0001 Stack: PHP 8.3 + Laravel

**Status:** Antagen 2026-08-03 · [[ADR-index]]

## Kontext

Systemet ska bo på inleed.net: delad hosting med PHP 4.4–8.3, NodeJS 6–20.10 och MariaDB 10.6.14. Cron kan köras varje minut. Node går att köra via DirectAdmins Setup Node.js App eller en manuell ProxyPass mot `127.0.0.1:PORT`.

Tony skriver inte koden själv. Han agerar produktägare och låter arkitekten bryta ner arbetet i GitHub issues som Sonnet och Haiku implementerar.

## Beslut

**PHP 8.3 med Laravel.**

## Motivering

Det avgörande är arbetssättet, inte språket. När flera modeller implementerar var sin issue är ramverkets konventioner värda mer än teknisk elegans — de begränsar hur mycket separata implementationer kan driva isär. Med ett egenbyggt ramverk uppfinner varje uppgift sin egen struktur, och Tony får granska arkitekturbeslut i varje PR istället för bara logiken. Laravel är dessutom kraftigt representerat i modellernas träningsdata, vilket höjer kodkvaliteten mätbart.

Driftmässigt är PHP den infödda medborgaren på delad hosting. Det finns ingen process att hålla vid liv. Supportsvaret om Node beskriver en uppsättning som fungerar men uppenbarligen inte är förstahandsvägen — och påpekandet att man själv måste lösa loggning säger något om vilken insyn man får i en process som dör klockan tre på natten.

Laravel ger dessutom precis de delar systemet behöver: Sanctum för API-tokens, migrations, en kö som kan köra på databasen, en scheduler som hänger på minutcronen, och ett filsystemslager som byter lagringsbackend med en konfigurationsrad.

Att Tony aldrig kört ramverk förut väger lätt när han inte är den som knackar.

## Konsekvenser

- Kön körs på databasdrivern via cron, inte på en persistent worker.
- Inga långlivade processer betyder att Meilisearch och liknande får vänta till VPS. Se [[ADR-0012 Sök]].
- Beroendet av Laravels konventioner är avsiktligt. Issues bör referera till ramverkets begrepp så att implementatören inte hittar på egna.

## Alternativ

**Node 20 + TypeScript.** Delad typning med en framtida frontend och bättre passform för API-först. Valdes bort på grund av driftprofilen på delad hosting och för att ramverkskonventioner väger tyngre i den här arbetsmodellen.

**Eget ramverk i ren PHP.** Tonys tidigare erfarenhet. Valdes bort — utan konventioner blir delegerad implementation spretig.
