# Planer och kvoter

Vad varje plan tillåter, hur förbrukning räknas, och vad som händer när någon slutar betala. Läs [[Datamodell – översikt]] först.

Beslut bakom detta: [[ADR-0014 Prismodell]], [[ADR-0009 Kvoter och livscykel]].

## Varför det här byggs i MVP fast betalning inte gör det

Begränsningarna måste sitta i API:et, aldrig i klienten — webbfrontenden, B2B-integrationer och kommande mobilappar delar samma backend, och den som skriver en egen klient ska inte kunna kringgå kvoten. Att retroaktivt införa kvoter i ett system som aldrig räknat förbrukning är dessutom obehagligt: du vet inte vad som redan finns.

Rättighetslagret byggs alltså nu. Betalflödet kopplas på när det finns någon att fakturera.

## plan

| Kolumn | Typ |
|---|---|
| id | |
| code | VARCHAR(40) UNIQUE — `free`, `pro`, `broker`, `yard`, `charter` |
| name | VARCHAR(100) |
| price_amount | BIGINT — minsta valutaenhet |
| price_currency | CHAR(3) |
| billing_period | VARCHAR(10) — `year` \| `month` |
| limits | JSON |
| is_public | BOOLEAN |

`limits` innehåller nycklarna: `containers`, `storage_bytes`, `max_file_bytes`, `shared_users_per_container`, `webhooks`, `pdf_binder`, `ownership_transfer`, `loan_reminders`, `cost_reports`.

Ett nytt B2B-erbjudande ska vara en ny rad i den här tabellen, inte ny kod. Det är hela poängen med att lägga gränserna i JSON.

## Gränserna i MVP

| | Free | Pro |
|---|---|---|
| Containers | 1 | obegränsat |
| Utrymme | 1 GB | 25 GB |
| Max filstorlek | 10 MB | 100 MB |
| Delade användare per container | 1 | obegränsat |
| Påminnelser och ICS | ja | ja |
| Export | ja | ja |
| Registrera kostnader | ja | ja |
| Kostnadsrapporter | nej | ja |
| Webhooks | nej | ja |
| PDF-pärm | nej | ja |
| Ägarbyte | nej | ja |
| Utlåningspåminnelser | nej | ja |

Pro ligger på **39–49 €/år**. Påminnelser och export är medvetet fria: påminnelserna skapar vanan, exporten skapar förtroendet. Se [[ADR-0014 Prismodell]].

B2B-nivåerna finns i [[ADR-0014 Prismodell]] och byggs inte i MVP.

## subscription

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| account_id | FK | |
| plan_id | FK | |
| status | VARCHAR(20) | `active` \| `past_due` \| `cancelled` |
| current_period_end | TIMESTAMP | |
| grace_until | TIMESTAMP NULL | Sätts vid nedgradering, se nedan |
| external_ref | VARCHAR(191) NULL | Merchant of record — tomt i MVP |

Betalning sker via en **merchant of record**, Paddle eller Lemon Squeezy, inte via Stripe direkt. De blir säljare gentemot kunden och hanterar EU-momsen. Se [[ADR-0014 Prismodell]].

## usage_counter

Förbrukning per konto. Uppdateras **transaktionellt** vid varje uppladdning och radering — aldrig räknat om vid behov, då blir det fel så fort en beräkning avbryts.

| Kolumn | Typ |
|---|---|
| account_id | FK UNIQUE |
| storage_bytes | BIGINT UNSIGNED |
| container_count | INT UNSIGNED |
| updated_at | |

Ett avstämningsjobb bör räkna om summorna nattetid och larma vid avvikelse. Räknare driver alltid isär till slut.

**Bytena belastar det uppladdande kontot** via `attachment.billed_account_id`, inte containerns ägare. Se [[Filer och lagring]].

Kvoten mäter logisk storlek — vad användaren upplever sig lagra. Faktisk diskförbrukning är lägre tack vare dedup. Fakturera på det första, kapacitetsplanera på det andra.

## Kontrollpunkter

Rättigheter kontrolleras server-side vid: skapande av container, uppladdning av fil (både styckstorlek och totalkvot), inbjudan av användare, registrering av webhook, generering av PDF-pärm, initiering av ägarbyte, och **anrop mot kostnadsrapporten**.

Kostnadsrapporten är den enda kontrollpunkten som inte rör en gräns utan en funktion: att *registrera* kostnader är fritt, att få dem summerade kräver Pro. Kontrollen måste därför sitta på rapportendpointen, inte på skrivningen. Se [[ADR-0016 Kostnadsregistrering]].

Vid nekande returneras en maskinläsbar felkod med vilken gräns som slog i — klienten formulerar meddelandet. Se [[ADR-0013 Språk och i18n]].

## Nedgradering

Det centrala beslutet: **items raderas aldrig, bara bilagor.** Detsamma gäller kostnadsrader — de är metadata och räknas inte mot kvoten. Kvittona kan försvinna med bilagorna, siffrorna står kvar.

Metadatan är mikroskopisk — raden "impellerbyte utfört 2024-06-12" tar några hundra byte. Filerna är det som kostar. Strippar man bara bilagorna behåller användaren hela sin logg, ser att posten står kvar med "manual saknas", och har en konkret anledning att komma tillbaka. Raderar man items förstör man det som gör att någon betalar igen. Se [[ADR-0009 Kvoter och livscykel]].

Förloppet:

1. Betalningen förfaller eller prenumerationen sägs upp. Kontot sätts till `read_only`.
2. Användaren får en lista över sina bilagor sorterad på storlek och väljer själv vad som ska bort. Hon vet vilka fyrtio semesterbilder som kan gå och vilken besiktningsrapport som inte kan det.
3. Frist på **tre månader** att betala eller exportera.
4. Sker inget: bilagor raderas automatiskt, **nyast först**, tills kontot ligger under gränsen. Items står kvar.
5. Kontot återgår till `active` på gratisnivån.

Varningar går ut vid steg 1, samt en månad och en vecka innan steg 4.

## Kontolivscykel

| Tid sedan `last_active_at` | Händelse |
|---|---|
| 12 mån | Påminnelse om att logga in |
| 15 mån | Kontot stängs (`closed`), data behålls |
| 18 mån | Kontot raderas |

Tre villkor som **måste** kontrolleras innan något raderas:

**Radering går via containern, inte via kontot.** Har containern aktiva medlemmar ska ägarskapet erbjudas dem först — annars försvinner en pärm som någon annan använder dagligen.

**Aktiv prenumeration undantar alltid.** Hur sällan någon än loggar in.

**Aktivitet räknas som API-anrop från vilken klient som helst**, inte bara inloggning.

Skicka fler än en varning. Radering av flera års dokumentation efter ett enda mejl som fastnade i skräpposten blir en riktigt dålig historia.

## Ägarbyte och kvot

Vid accept av ett ägarbyte kontrolleras mottagarens plan. Ryms inte containern nekas överlåtelsen med felkod — ett gratiskonto ska inte kunna ärva en pärm på åtta gigabyte.

Mottagaren får **tolv månader Pro** som del av överlämningen. Båtaffären blir därmed kundanskaffningskanal: säljaren betalar redan, köparen ärver en välfylld pärm hon inte vill förlora, och när året går ut har hon ett års egen historik i den.
