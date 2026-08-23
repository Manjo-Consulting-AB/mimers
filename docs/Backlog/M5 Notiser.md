# M5 · Notiser

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

### 30. Notiskärna
`notification` och `notification_delivery`. Outbox, `dedupe_key`, unik `(notification_id, channel)`. `payload` innehåller data, aldrig text.
**Läs:** [[Notiser]], [[ADR-0010 Notisarkitektur]]
**Klart när:** samma logiska händelse skapad sextio gånger av minutcronen ger **en** leverans.
**Beror på:** 3

### 31. Preferenser och tysta timmar
`notification_preference` per typ och kanal. `available_at` sätts utifrån mottagarens tysta timmar och tidszon.
**Läs:** [[Notiser]] § Tysta timmar och tidszon
**Beror på:** 30

### 32. E-post via Postmark
Mallar på svenska och engelska, valda från mottagarens locale. SPF, DKIM, DMARC på `mimers.app`. Avregistreringslänk och inställningssida.
**Läs:** [[Notiser]] § E-post, [[ADR-0013 Språk och i18n]]
**Beror på:** 31

### 33. Studshantering
`email_suppression` matad av Postmarks webhook. Undertryckta adresser ger `suppressed` istället för leveransförsök, och kopplas till livscykeln.
**Läs:** [[Notiser]] § email_suppression
**Beror på:** 32, 29

### 34. Notisgeneratorer
Jobben i tabellen: uppgiftsnotiser var 15:e minut, utlåning dagligen, leverans varje minut, kvotvarningar dagligen, livscykel dagligen.
**Läs:** [[Notiser]] § Kön
**Beror på:** 24, 30

### 35. Veckosammanfattning
Standard för uppgiftspåminnelser. Samlar allt markerat `digest`.
**Läs:** [[Notiser]] § notification_preference
**Beror på:** 34

### 36. ICS-kalenderfeed
Hemlig prenumerationslänk per container och användare, återkallbar. Visar bara det användaren får se. Textinnehåll enligt locale.
**Läs:** [[Notiser]] § ICS-kalenderfeed
**Klart när:** feeden går att prenumerera på i Apple Calendar och Google Calendar, och en återkallad token slutar fungera.
**Beror på:** 24

### 37. Webhooks
`webhook_endpoint`, HMAC-SHA256-signatur med tidsstämpel, omförsök med backoff, automatisk inaktivering efter upprepade fel.
**Läs:** [[Notiser]] § Webhooks
**Klart när:** **SSRF-validering** avvisar privata IP-intervall, localhost och molnmetadata vid både registrering och varje leverans.
**Beror på:** 30, 27
