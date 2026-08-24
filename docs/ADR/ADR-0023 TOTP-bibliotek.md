# ADR-0023 TOTP-bibliotek

**Status:** Antagen 2026-08-24 · [[ADR-index]]

Fattat inför issue 6a, som blockerades av att valet inte var gjort. Kompletterar [[ADR-0011 Autentisering]], som beslutade *att* TOTP ska finnas men inte *hur*.

## Kontext

[[ADR-0011 Autentisering]] gör TOTP-tvåfaktor tillgänglig, och motiverar den särskilt för B2B: ett varv sitter på hundra kunders dokumentation. Laravel har ingen inbyggd TOTP-implementation — varken ramverket eller Sanctum genererar hemligheter eller verifierar engångskoder.

Något måste alltså in utifrån, och [[AGENTS.md]] § Nya beroenden säger att ett beroende är ett arkitekturbeslut som hör i en ADR, inte i en implementationsissue.

TOTP är dessutom precis den sorts kod man inte skriver själv. Algoritmen är kort nog att se enkel ut — HMAC över en tidsstämpel — och de detaljer som avgör om den är säker syns inte i ett test som bara kontrollerar att rätt kod accepteras: konstanttidsjämförelse, hur stort tidsfönster som tillåts åt varje håll, och base32-avkodning som inte kraschar på skräpindata.

## Beslut

**`pragmarx/google2fa` som TOTP-bibliotek.**

**Ingen QR-generering på servern.** Applikationen exponerar en `otpauth://`-URI; klienten renderar QR-koden. `bacon/bacon-qr-code`, som Fortify drar in för samma ändamål, behövs därmed inte.

**Inte Laravel Fortify.** Vi tar biblioteket Fortify använder, inte Fortify självt.

## Motivering

Det är det bibliotek Laravels eget Fortify bygger sin tvåfaktor på — `laravel/fortify` v1.38 kräver `pragmarx/google2fa ^9.0`. Det är den bästa tillgängliga signalen om att det håller i en Laravel-kontext, och den betyder att biblioteket granskas av fler ögon än sina egna användares.

Ytan är liten och beroendekedjan kort: MIT-licens, och ett enda transitivt beroende i `paragonie/constant_time_encoding` — som i sig är poängen, eftersom det är det som gör base32-hanteringen konstanttidig.

Fortify självt valdes bort trots att det löser mer. Det tar över registrering, inloggning, lösenordsåterställning och e-postverifiering — allt det som redan är byggt och testat i issue 4, med kolumnnamn och kontoskapande som är våra egna (`password_hash` i stället för `password`, ett `account` plus en `account_user`-rad vid varje registrering). Att lägga Fortify ovanpå det vore att byta fungerande kod mot ett ramverk med egna antaganden, för att få en enda funktion. Dessutom drar Fortify in `laravel/passkeys`, som ingen har efterfrågat.

QR-generering på servern skulle lägga till ett bildbibliotek för att lösa något klienten gör bättre ändå — och `otpauth://`-URI:n är standardformatet varje autentiseringsapp läser.

## Konsekvenser

- `composer require pragmarx/google2fa` i issue 6a. Inga andra paket följer med utom `paragonie/constant_time_encoding`.
- **Tidsfönstret är en egen avvägning**, inte en defaultinställning att låta ligga. Biblioteket tillåter att man accepterar koder några tidssteg bakåt och framåt för att kompensera klockdrift. Fönstret ska sättas medvetet och motiveras i issue 6a — större fönster betyder fler giltiga koder samtidigt.
- **Att koden är rätt räcker inte.** Se issue 6b: en förbrukad tidslucka får inte gå att spela upp igen, och det är applikationens ansvar, inte bibliotekets.
- Hemligheten lagras krypterad i `user.totp_secret`, enligt [[Konton och åtkomst]] § user. Biblioteket rör inte lagringen.
- Byts biblioteket senare påverkas bara hemlighetsgenerering och kodverifiering — lagring, inloggningsflöde och återställningskoder är våra egna.

## Alternativ

**`spomky-labs/otphp`.** Välunderhållet, bredare i funktionsomfång, hanterar även HOTP. Valdes bort — vi behöver inte HOTP, och det saknar den koppling till Laravels eget ekosystem som gör `google2fa` till det säkrare valet vid en framtida ramverksuppgradering.

**Laravel Fortify.** Ger TOTP, återställningskoder och inloggningsflödet färdigt, alltså större delen av 6a, 6b och 6c. Valdes bort — se motiveringen ovan: det skulle ersätta fungerande kod från issue 4 och 7 med egna antaganden, och dra in `laravel/passkeys` på köpet. Värt att ompröva om vi någon gång bygger om autentiseringen från grunden.

**Skriva TOTP själva.** RFC 6238 är kort och implementationen ryms på en skärm. Valdes bort — de detaljer som avgör säkerheten syns inte i ett test som bara kontrollerar att rätt kod accepteras, och en egen implementation får aldrig den granskning ett brett använt bibliotek får gratis.
