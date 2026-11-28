### Milstolpe

M76 Ägarbytets bonus till nya kunder · backlog-issue 455

### Mål

Texterna om ägarbytet lovar bara den bonus som regeln i 454 faktiskt ger. Avsändaren får veta före sändningen vem som får tolv månader Pro, och ser på det skickade ägarbytet om det gäller just det. Mottagaren ser raden om Pro bara när den gäller hen.

Beslutet står i ADR-0093 Ägarbytets bonus till nya kunder (valvissue #1536).

Gör så här:
- **`transfer.form.notice`** i `lang/en/ui.php`: den sista satsen, *and the recipient gets twelve months of Pro on their first received ownership transfer*, ersätts med en egen mening: *A recipient who doesn't have an account yet gets twelve months of Pro, if you pay for Pro yourself — up to two transfers a year.* Kommentaren i `TransferForm.vue` följer med.
- **`transfer.card.pro`** blir *Twelve months of Pro are included.* `IncomingTransferCard.vue` visar raden bara när `transfer.grants_bonus` är sant. `OwnershipTransferController` (webben, raden som bygger kortet, i dag med `from_account_name`) skickar `grants_bonus` från raden.
- **Avsändarens lista** på `Containers/Transfers.vue`: ett väntande ägarbyte med `grants_bonus` får raden `transfer.outgoing.pro`, *The recipient gets twelve months of Pro.* Fältet kommer från `OwnershipTransferResource`, som får det i 454.
- `lang/en/ui.php` är den enda språkfilen. Nya nycklar läggs där.

### Läs

[[ADR-0093 Ägarbytets bonus till nya kunder]]

### In scope

```shell
lang/en/ui.php
resources/js/components/TransferForm.vue
resources/js/components/IncomingTransferCard.vue
resources/js/pages/Containers/Transfers.vue
app/Http/Controllers/OwnershipTransferController.php
tests/Feature/Frontend/AgarbytesvyTest.php
tests/Feature/Frontend/SprakTest.php
```

### Omfångsläge

spårad

### Out of scope

```shell
app/Actions/**
app/Http/Resources/**
database/**
docs/**
```

### ambiguity

low

### blast_radius

contained

### risk_class

none

### Granskning

Sonnet

### Klart när

- [ ] Formulärets text säger att en mottagare utan konto får tolv månader Pro när avsändaren betalar för Pro, högst två gånger om året, och lovar ingenting till befintliga konton.
- [ ] Mottagarens kort visar raden om Pro när `grants_bonus` är sant och saknar den annars.
- [ ] Avsändarens väntande ägarbyte visar raden om Pro bara när `grants_bonus` är sant.
- [ ] `npm run build`, hela sviten, pint och phpstan är gröna.

### Beror på

454

### Delmoment

- [ ] En session

