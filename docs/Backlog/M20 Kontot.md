# M20 · Kontot

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-24, efter retron för M18. Här samlas tre luckor som stod i [[Att sortera efter mockuparna]] § Ännu inte issues under *Luckorna i kontot*. En användare kan i dag inte byta lösenord, inte byta e-postadress och inte se en inbjudan när hon loggar in. [[ADR-0011 Autentisering]] och issue 80 gäller oförändrade: **tvåfaktorn får inte gå att kringgå** genom någon av de nya vägarna.

**Alla tre är `risk_class: elevated`**, eftersom de rör autentisering (129, 130) eller behörighet (131).

**Säkerhetsloggen väntar på dem.** Issue 113 skrev att *"byte av lösenord och e-post finns inte i produkten än; de loggas här när de byggs"*. De två handlingarna får var sin konstant på `SecurityLog`. Samma `meta`-regel gäller: aldrig ett lösenord, en kod, ett token eller en e-postadress.

**Detta ingår inte:** `/api`, eftersom mobilappen inte finns än och webben är den enda klienten, och en återställning av glömt lösenord. Magic link är den vägen in, se [[ADR-0011 Autentisering]] § Motivering.

---

### 129. Lösenordet går att byta
Säkerhetssidan i kontoinställningarna får ett formulär för att byta lösenord. **Har användaren inget lösenord**, för att hon bara använt magic link (`password_hash` är `NULL`), sätter samma formulär ett.

**Återautentiseringen:** finns ett lösenord måste det nuvarande anges. Har användaren tvåfaktor påslagen krävs dessutom en giltig kod, eller en återställningskod, i samma inskick. Det gäller också när inget lösenord finns. Det nya lösenordet valideras med samma regel som registreringen (`Password::defaults()`), och formuläret har samma takgräns som inloggningen.

**Efter bytet:** användarens övriga webbsessioner loggas ut, och sessionen som gjorde bytet får ett nytt id. Alla Sanctum-token tas bort. Säkerhetsloggen får raden `auth.password_changed`. Användaren får ett mejl om att lösenordet har ändrats, som en Laravel-notis i `app/Notifications/` i samma form som `MagicLinkNotification`. Det är ett transaktionellt mejl och inte en rad i `notification`.

**Läs:** [[ADR-0011 Autentisering]], [[ADR-0043 Tre loggar]] § Säkerhetsloggen, `app/Http/Controllers/Settings/SecurityController.php`, `app/Http/Controllers/Auth/TotpController.php` (hur en kod prövas), `app/Http/Requests/Auth/RegisterRequest.php` (lösenordsregeln), `app/Notifications/MagicLinkNotification.php` (förlagan för mejlet)
**Klart när:** en användare med lösenord kan byta det genom att ange det nuvarande; ett fel nuvarande lösenord ger ett valideringsfel och ändrar ingenting; en användare utan lösenord kan sätta ett; med tvåfaktor påslagen misslyckas bytet utan giltig kod, både med och utan befintligt lösenord; en återställningskod godtas och förbrukas; övriga sessioner och alla token är ogiltiga efteråt; säkerhetsloggen har exakt en rad `auth.password_changed` utan lösenord eller kod i `meta`; mejlet skickas; formuläret har inloggningens takgräns; strängarna ligger i `lang/en/ui.php`; hela testsviten är grön.
**Beror på:** -

### 130. E-postadressen går att byta
Profilsidan visar i dag adressen men ändrar den inte (`ProfileController` § Beslut 3). Den regeln faller här, men **adressen byts aldrig i samma steg som den begärs.**

**Flödet:**

1. Användaren anger den nya adressen och sitt nuvarande lösenord. Har hon tvåfaktor påslagen krävs också en kod. Har hon inget lösenord måste hon sätta ett först (issue 129). En magic link räcker inte, för den visar bara att hon når den adress hon redan har.
2. En ny tabell, `email_change`, får raden: `user_id`, `new_email`, `token_hash`, `expires_at` (en timme) och `confirmed_at`. Den har samma form som `magic_link_token`: tokenet lagras som hash, är engångs och går ut. En ny begäran ogiltigförklarar en tidigare obekräftad.
3. Den nya adressen får en bekräftelselänk. Den gamla adressen får ett meddelande om att ett byte har begärts, utan länk och utan den nya adressen.
4. Först när länken öppnas, av en inloggad användare som är samma användare, byts `user.email`. Då sätts `email_verified_at` till nu, och obegagnade magic link-token för den gamla adressen tas bort. **Unikheten prövas både vid begäran och vid bekräftelse.** En adress som tagits under tiden ger ett fel och ändrar ingenting.

Säkerhetsloggen får `auth.email_change_requested` och `auth.email_changed`, och ingen av raderna bär någon adress. **Väntande inbjudningar följer adressen och inte användaren.** En inbjudan till den gamla adressen går inte längre att acceptera, och det är rätt, se [[Konton och åtkomst]] § invitation.

**Läs:** [[ADR-0011 Autentisering]], [[Konton och åtkomst]] § user och § invitation, [[ADR-0043 Tre loggar]] § Säkerhetsloggen, `app/Http/Controllers/Settings/ProfileController.php` (docblocken, § Beslut 3), `app/Support/Auth/MagicLinkBroker.php` (förlagan för tokenet), `database/migrations/*_create_magic_link_token_table.php`
**Klart när:** en begäran utan rätt lösenord ändrar ingenting; med tvåfaktor påslagen krävs en giltig kod; en användare utan lösenord kan inte begära ett byte; en begäran ändrar inte `user.email`; länken byter adressen och sätter `email_verified_at`; länken fungerar en gång och inte efter en timme; en annan inloggad användare kan inte använda länken; en adress som tagits efter begäran ger ett fel vid bekräftelsen; den gamla adressen får ett meddelande utan den nya adressen; magic link-token för den gamla adressen fungerar inte efter bytet; säkerhetsloggen har de två raderna utan adress i `meta`; [[Konton och åtkomst]] har ett avsnitt `email_change`; [[Registerförteckning]] har en rad för tabellen; strängarna ligger i `lang/en/ui.php`; hela testsviten är grön.
**Beror på:** 129

### 131. Väntande inbjudningar syns för inloggade
En inbjudan når i dag bara mottagaren som mejl. `/invitations` visar en inbjudan först när tokenet ur mejlet ligger i sessionen (issue 55b § Beslut 2). **`Notification::TYPE_INVITATION_RECEIVED` finns som konstant men skrivs av ingen kod**, så notisklockan i issue 127 har ingenting att visa. Ett webhookabonnemang på typen tar aldrig emot något heller.

Issuen ger en inloggad användare med **verifierad** adress en lista över väntande inbjudningar till den adressen. En inbjudan är väntande när `status` är `pending` och `expires_at` ligger i framtiden. Listan visas på `/invitations` när inget token finns i sessionen, och klockan får en rad per väntande inbjudan. Accept och avvisande går då på inbjudans `ulid` i stället för på tokenet. **Kontrollerna är desamma som tokenvägens och formuleras inte om:** status, utgång, verifierad adress och adressjämförelse. Tokenvägen och den nya vägen delar en kontroll, och `AcceptInvitation` och `RejectInvitation` återanvänds orörda.

**Varför det räcker utan token:** tokenet bevisar att mottagaren når brevlådan. En verifierad adress som är lika med inbjudans bevisar samma sak. Därför krävs verifieringen, och en overifierad användare ser ingen lista.

En inbjudan som inte är användarens ger `404` och inte `403`, så att ett gissat `ulid` inte avslöjar att inbjudan finns. `invitation.received` skrivs fortfarande inte. Om konstanten ska bort eller börja skrivas är en egen fråga, som den här issuen skriver in i [[Tankar]].

**Läs:** [[Konton och åtkomst]] § invitation, [[ADR-0003 Åtkomstmodell]], `app/Http/Controllers/InvitationResponseController.php` (docblocken), `app/Http/Requests/Invitation/InvitationTokenRequest.php`, `app/Actions/Invitation/AcceptInvitation.php`, [[M19 Dashboarden]] § 127
**Klart när:** en inloggad, verifierad användare ser sina väntande inbjudningar på `/invitations` utan token; en utgången, återkallad eller besvarad inbjudan syns inte; en overifierad användare ser ingen lista; accept via `ulid` ger samma åtkomst som accept via token; en annan användares inbjudan ger `404` vid accept och avvisande; adressjämförelsen är skiftlägesokänslig på samma sätt som tokenvägens; tokenvägen fungerar oförändrad; klockan visar en rad per väntande inbjudan och länkar till `/invitations`; [[Tankar]] har frågan om `invitation.received`; strängarna ligger i `lang/en/ui.php`; hela testsviten är grön.
**Beror på:** 127
