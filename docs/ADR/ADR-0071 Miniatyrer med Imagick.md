# ADR-0071 Miniatyrer med Imagick

**Status:** Antagen 2026-11-06 · Kompletterar [[Filer och lagring]] § image_derivative och issue 18 § Beslut 4 · [[ADR-index]]

Tonys beslut 2026-11-06, efter att kontot höjts till PHP 8.5.

## Kontext

`GenerateImageDerivatives` ritar miniatyrer med GD för sex MIME-typer: jpeg, png, webp, gif, bmp och avif. Allt annat får inga derivat. Det gäller också HEIC, som är förvalet för bilder från en iPhone, och TIFF. En bilaga i något av de formaten visas därför utan bild, och kan inte bli omslag.

PHP 8.5 hos inleed har tillägget `imagick` tillgängligt i panelen. Avläst 2026-11-06: ImageMagick 7.1.2 med HEIC, HEIF, AVIF, TIFF, WEBP, JPEG och PDF. PDF läses genom Ghostscript, `/usr/bin/gs`.

## Beslut

1. **HEIC, HEIF och TIFF får derivat genom Imagick.** De sex typerna som GD redan hanterar ritas med GD som förut.
2. **Derivaten av de tre typerna skrivs som JPEG med kvalitet 82.** Formatet följer inte källan, som Beslut 4 i issue 18 annars säger, eftersom en webbläsare inte kan visa originalet.
3. **Båda varianterna skrivs alltid för de tre typerna**, även när originalet är mindre än målet. Bilden förstoras inte, men den kodas om. Att en rad saknas betyder annars "originalet duger", och här duger originalet inte.
4. **Inget PDF och inget annat format som går genom Ghostscript eller någon annan delegat.** ImageMagick startar då ett externt program, förbi `disable_functions`, på en fil som en användare har laddat upp. Formatet sätts uttryckligen innan bytena läses (`HEIC:`, `TIFF:`), så att en fil som utger sig för att vara något annat inte når en delegat.
5. **Imagick får resursgränser** innan den läser: minne, yta och bredd/höjd. En bild som slår i gränserna får inga derivat och loggas, som en trasig bild gör i dag.
6. **Saknas `imagick` får de tre typerna inga derivat.** Det är en försämring, inte ett fel, som när GD saknas.

## Motivering

HEIC är vanligt hos användarna, inte ett specialfall: varje iPhone sparar i det formatet om ingen har ändrat inställningen. En bild som laddas upp från telefonen ska kunna visas.

Imagick är ett tillägg och kör i PHP-processen. Det kräver inte `proc_open`, och beslutet hänger därför inte på [[ADR-0070 Subprocesser hos inleed]]. Undantaget är delegaterna, och det är skälet till punkt 4. ImageMagick har en lång historia av sårbarheter just i vägen från uppladdad fil till delegat.

## Konsekvenser

- `imagick` måste aktiveras för PHP 8.5 i inleeds panel innan issuen rullas ut, och läggas till i `extensions` i `ci.yml`.
- `GenerateImageDerivatives::supportsMime()` svarar ja för de tre nya typerna bara när `imagick` är laddat. Uppladdningen, omslaget och listornas ruta läser samma svar.
- Proven kan inte förutsätta att Ubuntu-löparens ImageMagick har HEIC. Ett prov som behöver formatet hoppas över när `Imagick::queryFormats('HEIC')` är tom, och TIFF prövas alltid.

## Alternativ

**vips.** Snabbare och snålare med minne, och finns också i panelen. Avfärdat för nu: tillägget är mindre spritt, och setup-php i CI har det inte som standard.

**Låt webbläsaren visa originalet.** Safari visar HEIC, de andra webbläsarna gör det inte. Avfärdat.

**PDF:s första sida som miniatyr.** Värdefullt för kvitton och manualer, men det kräver Ghostscript. Tas upp igen om det går att göra utan en delegat.
