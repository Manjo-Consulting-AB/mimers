# Planer och kvoter

Vad varje plan tillåter, hur förbrukning räknas, och vad som händer när någon slutar betala. Läs [[Datamodell – översikt]] först.

Beslut bakom detta: [[ADR-0014 Prismodell]], [[ADR-0086 Pro, Pro+ och grundarpris]], [[ADR-0009 Kvoter och livscykel]], [[ADR-0081 All lagrad data räknas mot kvoten]].

## Varför det här byggs i MVP fast betalning inte gör det

Begränsningarna måste sitta i API:et, aldrig i klienten — webbfrontenden, B2B-integrationer och kommande mobilappar delar samma backend, och den som skriver en egen klient ska inte kunna kringgå kvoten. Att retroaktivt införa kvoter i ett system som aldrig räknat förbrukning är dessutom obehagligt: du vet inte vad som redan finns.

Rättighetslagret byggs alltså nu. Betalflödet kopplas på när det finns någon att fakturera.

## plan

| Kolumn | Typ |
|---|---|
| id | |
| code | VARCHAR(40) UNIQUE — `free`, `pro`, `pro_plus`, `pro_founder`, `pro_plus_founder`, `broker`, `yard`, `charter` |
| name | VARCHAR(100) |
| price_amount | BIGINT — minsta valutaenhet |
| price_currency | CHAR(3) |
| billing_period | VARCHAR(10) — `year` \| `month` |
| limits | JSON |
| is_public | BOOLEAN |

`limits` innehåller nycklarna: `containers`, `storage_bytes`, `max_file_bytes`, `shared_users_per_container`, `webhooks`, `pdf_binder`, `ownership_transfer`, `loan_reminders`, `cost_reports`.

Ett nytt B2B-erbjudande ska vara en ny rad i den här tabellen, inte ny kod. Det är hela poängen med att lägga gränserna i JSON.

## Gränserna i MVP

| | Free | Pro | Pro+ |
|---|---|---|---|
| Listpris per år | 0 | 39 € | 69 € |
| Grundarpris per år | — | 19 € | 39 € |
| Containers | 1 | obegränsat | obegränsat |
| Utrymme | 1 GB | 10 GB | 50 GB |
| Max filstorlek | 10 MB | 64 MB | 64 MB |
| Delade användare per container | 1 | obegränsat | obegränsat |
| Påminnelser och ICS | ja | ja | ja |
| Export | ja | ja | ja |
| Registrera kostnader | ja | ja | ja |
| Kostnadsrapporter | nej | ja | ja |
| Webhooks | nej | ja | ja |
| PDF-pärm | nej | ja | ja |
| Ägarbyte | nej | ja | ja |
| Utlåningspåminnelser | nej | ja | ja |

Pro-taket på 64 MB är satt av servern, inte av prismodellen: `upload_max_filesize` hos inleed står på 64M, se [[Pipeline]] § Uppladdningsgränser. Det tekniska taket i `config/files.php` är samma tal, och `max_file_bytes` ska aldrig ligga över det — en plangräns som aldrig hinner slå i blir ett HTTP-fel istället för ett förklarat kvotmeddelande. Byter vi hostingplattform kan talet justeras om kunderna efterfrågar det.

Grundarpriserna är egna rader, `pro_founder` och `pro_plus_founder`, med `is_public = false` och samma `limits` som `pro` och `pro_plus`. Ett pris byts genom en ny rad, aldrig genom att en befintlig skrivs om, så att en löpande prenumeration behåller sitt pris. Påminnelser och export är fria på alla nivåer. Se [[ADR-0086 Pro, Pro+ och grundarpris]].

B2B-nivåerna finns i [[ADR-0014 Prismodell]] och byggs inte i MVP.

## subscription

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| account_id | FK | |
| plan_id | FK | |
| status | VARCHAR(20) | `active` \| `past_due` \| `cancelled` |
| current_period_end | TIMESTAMP | En `active`-rad bär sin plan bara före detta ögonblick; därefter gäller Free. `past_due` bär sin plan oavsett. Se [[ADR-0055 Inbjudningskoder och stängd registrering]] § Konsekvenser. |
| grace_until | TIMESTAMP NULL | Sätts vid nedgradering, se nedan |
| source | VARCHAR(20) | `paid` \| `transfer_bonus` \| `voucher` \| `internal` — vad som gav kontot dess nuvarande plan. Bara `paid` ger bonus vid ägarbyte, se [[ADR-0093 Ägarbytets bonus till nya kunder]] |
| external_ref | VARCHAR(191) NULL | Merchant of record — tomt i MVP |

Betalning sker via en **merchant of record**, Paddle eller Lemon Squeezy, inte via Stripe direkt. De blir säljare gentemot kunden och hanterar EU-momsen. Se [[ADR-0014 Prismodell]].

## usage_counter

Förbrukning per konto. Uppdateras **transaktionellt** vid varje uppladdning, varje skrivning som ändrar en räknad rads storlek, och varje radering — aldrig räknat om vid behov, då blir det fel så fort en beräkning avbryts.

| Kolumn | Typ | Not |
|---|---|---|
| account_id | FK UNIQUE | |
| storage_bytes | BIGINT UNSIGNED | Filer: summan av `byte_size` för kontots bilagor |
| metadata_bytes | BIGINT UNSIGNED | Text: summan av kontots räknade rader, se nedan |
| container_count | INT UNSIGNED | |
| updated_at | | |

**Kvoten prövas mot summan** `storage_bytes + metadata_bytes`, och planens gräns `storage_bytes` i `limits` gäller summan. Kolumnerna hålls isär så att lagringsvyn kan visa filer och text var för sig, och så att avstämningen kan rätta dem var för sig.

Ett avstämningsjobb bör räkna om summorna nattetid och larma vid avvikelse. Räknare driver alltid isär till slut.

**Bytena belastar det uppladdande kontot** via `attachment.billed_account_id`, inte containerns ägare. Se [[Filer och lagring]].

**Så räknas en rad i `metadata_bytes`:** antalet byte (UTF-8) i radens fritextkolumner plus ett fast påslag på **256 byte per rad**. De räknade tabellerna är `container`, `category`, `tag`, `item`, `item_version`, `schedule`, `cost_entry` och `loan`. `schedule_occurrence` räknar bara `completion_note`, utan påslag. Systemets egna tabeller räknas inte (notiser, loggar, besök, favoriter), och inte heller kopplingsrader utan text (`item_tag`, `item_link`, beroenden).

Raden belastar kontot som skapade den, via radens `billed_account_id` — utom containerraden, som belastar ägaren (`container.account_id`), och utom `item_version`, som belastar containerns ägare genom `billed_account_id` satt vid skapandet ([[ADR-0088 Versionshistorik för items]] Riktning 3). Slängda rader räknas tills de raderas för gott, precis som bilagor.

Kvoten mäter logisk storlek — vad användaren upplever sig lagra. Faktisk diskförbrukning är lägre tack vare dedup. Fakturera på det första, kapacitetsplanera på det andra.

## Kontrollpunkter

Rättigheter kontrolleras server-side vid: skapande av container, uppladdning av fil (både styckstorlek och totalkvot), **skapande av en räknad rad och ändring som gör den större** (totalkvot), inbjudan av användare, registrering av webhook, generering av PDF-pärm, initiering av ägarbyte, och **anrop mot kostnadsrapporten**.

En räknad rad som skulle ta summan över gränsen nekas med `quota.storage_exceeded`. Att krympa, slänga eller radera nekas aldrig. Se [[ADR-0081 All lagrad data räknas mot kvoten]] § 5.

Kostnadsrapporten är den enda kontrollpunkten som inte rör en gräns utan en funktion: att *registrera* kostnader är fritt, att få dem summerade kräver Pro. Kontrollen måste därför sitta på rapportendpointen, inte på skrivningen. Se [[ADR-0016 Kostnadsregistrering]].

Vid nekande returneras en maskinläsbar felkod med vilken gräns som slog i — klienten formulerar meddelandet. Se [[ADR-0013 Språk och i18n]].

## Nedgradering

Det centrala beslutet: **bilagor raderas först, items sist.** Items, och kostnadsraderna med dem, raderas bara när bilagorna inte räcker för att komma ner till gränsen, och bara i kontots egna containrar. Kvittona kan försvinna med bilagorna, siffrorna står kvar.

För en vanlig användare är texten liten — raden "impellerbyte utfört 2024-06-12" tar några hundra byte — och filerna är det som kostar, så det är bilagorna som går. Strippar man bilagorna behåller användaren hela sin logg, ser att posten står kvar med "manual saknas", och har en konkret anledning att komma tillbaka. Items raderas sist för att det är dem som gör att någon betalar igen. Att de alls kan raderas är för att ett skript annars kunde fylla databasen gratis. Se [[ADR-0009 Kvoter och livscykel]] och [[ADR-0081 All lagrad data räknas mot kvoten]] § 6.

Förloppet:

1. Betalningen förfaller eller prenumerationen sägs upp. Kontot sätts till `read_only`. Ägarna till andra containrar där kontot betalar för innehåll får en notis, så att de hinner städa före steg 4.
2. Användaren får en lista över sina bilagor sorterad på storlek och väljer själv vad som ska bort. Hon vet vilka fyrtio semesterbilder som kan gå och vilken besiktningsrapport som inte kan det. Utrymme frigörs först när bilagan raderas för gott i lagringsvyn — en slängd bilaga räknas i kvoten tills den gallras.
3. Frist på **tre månader** att betala eller exportera.
4. Sker inget:
   - **Andra containrar.** Innehåll som kontot betalar för i andra containrar raderas inte. Bilagor och räknade rader flyttas till respektive containers ägarkonto, utan kvotprövning, som vid kontoradering (se § Kontolivscykel).
   - **Egna containrar.** Innehåll tas bort i den här ordningen tills kontot ligger på eller under gränsen: slängda bilagor (äldst slängd först), levande bilagor (**nyast först**), slängda items (äldst slängd först), levande items (nyast först). Allt raderas för gott. Inget går till papperskorgen.
5. Kontot återgår till `active` på gratisnivån.

Varningar går ut vid steg 1, samt en månad och en vecka innan steg 4.

## Kontolivscykel

| Tid sedan `last_active_at` | Händelse |
|---|---|
| 12 mån | Påminnelse om att logga in |
| 15 mån | Kontot stängs (`closed`), data behålls |
| 18 mån | Kontot raderas |

Tre villkor som **måste** kontrolleras innan något raderas:

**Delade containrar spärrar inte.** Ett inaktivt konto raderas efter 18 månader med sina containrar, oavsett medlemmar. Skälet är GDPR: används kontot inte av ägaren får vi inte behålla det hur länge som helst. Medlemmarna förlorar åtkomsten och får notisen `container.deleted`. Se [[ADR-0045 Radering av konto och person]] § Uppföljning 2026-11-14.

**Aktiv prenumeration undantar alltid.** Hur sällan någon än loggar in.

**Aktivitet räknas som aktivitet i webben eller API:et**, inte bara inloggning.

**Innehåll i någon annans container hindrar inte raderingen.** Ett item, en kostnadsrad eller en avbockad förekomst i en främmande container står kvar, med författarkolumnen mot kontot nollställd. Bilagor och räknade rader kontot betalar för i en främmande container får **containerns ägarkonto** som `billed_account_id`, och bytena flyttar mellan kontonas `usage_counter` i samma transaktion. Kvoten kontrolleras inte vid flytten: hamnar ägarkontot över sin gräns gäller samma regel som för en vanlig överskriden kvot — nya uppladdningar och växande skrivningar nekas med `quota.storage_exceeded`, ingen nedgradering startar. Samma flytt sker vid nedgradering och när kontot förlorar sin sista giltiga åtkomst till containern. Se [[ADR-0045 Radering av konto och person]] § Beslut 1 och 2 och [[ADR-0081 All lagrad data räknas mot kvoten]] § 4.

Skicka fler än en varning. Radering av flera års dokumentation efter ett enda mejl som fastnade i skräpposten blir en riktigt dålig historia.

## Ägarbyte och kvot

Kvoten prövas mot mottagarens nuvarande plan vid accept, utom när ägarbytet ger bonusen: då prövas den mot Pro. Ryms inte containern nekas överlåtelsen med felkod.

Bonusen är tolv månader Pro till en mottagare som saknade konto när ägarbytet skickades, från en avsändare med betald Pro, högst två per avsändare på tolv månader. Ingen förlängning för befintliga konton. Motiveringen står i [[ADR-0093 Ägarbytets bonus till nya kunder]] och upprepas inte.
