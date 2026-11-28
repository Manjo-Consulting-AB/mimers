### Milstolpe
M74 Tvåfaktor som krav för delade containrar

### Mål
Ett ägarbyte kan inte lämna en container som kräver tvåfaktor till någon utan tvåfaktor, och kravet följer med containern.

Beslutet står i ADR-0092 Tvåfaktor som krav för delade containrar (valvissue #1524).

Gör så här:

- **Begäran** (`StoreOwnershipTransferRequest`): kräver containern tvåfaktor (`Container::requiresTwoFactor()`) och har mottagaren ett konto utan bekräftad tvåfaktor nekas begäran med `transfer.recipient_two_factor_required`. Saknar mottagaren konto ännu (inbjudan per e-post) prövas det vid accepten.
- **Accepten** (`AcceptOwnershipTransfer`): samma prövning, eftersom mottagaren kan ha stängt av tvåfaktorn emellan. Nekas med samma kod, och sidan för ägarbytet säger att tvåfaktor behövs.
- **Kravet följer med:** kom kravet bara från det gamla kontots flagga sätts `container.require_two_factor = true` vid bytet, så att containern inte tappar skyddet. Den nya ägaren kan slå av det (450).
- Engelska meningar för felkoden i `lang/en/ui.php`.

### Läs
`app/Http/Requests/OwnershipTransfer/StoreOwnershipTransferRequest.php`, `app/Actions/OwnershipTransfer/AcceptOwnershipTransfer.php` (docblockarna)

### In scope

```shell
app/Http/Requests/OwnershipTransfer/**
app/Actions/OwnershipTransfer/**
app/Http/Controllers/OwnershipTransferController.php
resources/js/pages/Transfers/**
lang/en/ui.php
tests/Feature/Agarbyte/**
tests/Feature/Frontend/SprakTest.php
```

### Omfångsläge
spårad

### Out of scope

```shell
app/Actions/Access/**
app/Policies/**
database/**
docs/**
```

### ambiguity
low

### blast_radius
contained

### risk_class
elevated

### Granskning
Sonnet

### Klart när
- [ ] Ett ägarbyte av en container som kräver tvåfaktor till ett konto utan tvåfaktor nekas vid begäran och vid accepten.
- [ ] Efter bytet har containern `require_two_factor = true` när kravet kom från det gamla kontot.
- [ ] Hela sviten, pint och phpstan är gröna.

### Beror på
448

### Delmoment
- [ ] En session
