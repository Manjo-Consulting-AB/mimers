<?php

namespace App\Support\Files;

use App\Models\Attachment;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Svaret som bär en bilagas byten — mekanismen från [[ADR-0019 Filleverans]],
 * delad mellan de två rutter som levererar: `files.download` på appdomänen
 * (issue 19a) och `files.deliver` på filoriginet (issue 61a).
 *
 * **Två grenar, samma huvuden.** Är `config('files.internal_redirect')` satt
 * svarar appen 200 med tom kropp och `X-LiteSpeed-Location`, och LiteSpeed
 * läser filen med sendfile() — PHP skickar noll bytes och håller ingen process
 * upptagen under överföringen. Lokalt och i testsviten (config false) strömmar
 * appen filen själv med Storage::response(). Båda grenarna sätter samma
 * huvuden: en miljöskillnad får aldrig bli en säkerhetsskillnad (issue 19a
 * § Beslut 6).
 *
 * **Fyra huvuden, varav två är säkerhetsbärande** (issue 61a § Beslut 6):
 *
 * - `Content-Type` explicit ur `stored_file.mime_type`. LiteSpeed sätter
 *   INTE typen efter filens innehåll vid intern omdirigering — utan den här
 *   headern följer PHP:s `text/html; charset=UTF-8` med hela vägen ut, se
 *   [[ADR-0019 Filleverans]] § Verifierat på servern.
 * - `Content-Disposition` byggd av Symfonys hjälpare, aldrig för hand.
 * - `X-Content-Type-Options: nosniff` — utan den får webbläsaren gissa typen,
 *   och gissningen kan landa i att köra skript.
 * - `Content-Security-Policy: default-src 'none'; sandbox; frame-ancestors
 *   <appens origin>` — stänger av allt en levererad HTML- eller SVG-fil skulle
 *   kunna dra in, och säger att bara appen får rama in en PDF, vilket är vad
 *   61b behöver för att visa den.
 *
 * `Content-Disposition` får **aldrig** komma ur querysträngen (§ Beslut 5):
 * ett `?disposition=inline` som klienten väljer är en väg att få en godtycklig
 * fil renderad, och signaturen hade skyddat den valda dispositionen, inte den
 * rätta. Den kommer därför ur serverns lagrade MIME-typ och ur vilket origin
 * anropet kom in genom — `$inline` nedan — och ingenting annat.
 */
final class AttachmentDelivery
{
    /**
     * @param  mixed  $variant  det råa värdet ur querysträngen. Allt utom
     *                          `null` och strängarna `thumb`/`medium` är 404 —
     *                          `?variant[]=thumb` når hit som en array och
     *                          avvisas, den tysta återgången till originalet
     *                          finns inte.
     * @param  bool  $inline  true bara på filoriginet, där en egen origin finns
     *                        att rendera i. Tillåt-listan i
     *                        `config('files.inline_mime_types')` avgör sedan om
     *                        typen får använda den.
     */
    public static function make(Attachment $attachment, mixed $variant = null, bool $inline = false): Response
    {
        $storedFile = $attachment->storedFile;
        $storagePath = self::storagePath($storedFile, $variant);

        $disposition = $inline && self::isInlineAllowed($storedFile->mime_type)
            ? HeaderUtils::DISPOSITION_INLINE
            : HeaderUtils::DISPOSITION_ATTACHMENT;

        $headers = [
            'Content-Type' => $storedFile->mime_type,
            'Content-Disposition' => self::contentDisposition($disposition, $attachment->filename),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors ".FileOrigin::appOrigin(),
        ];

        if (config('files.internal_redirect')) {
            // 200 med tom kropp, inte noContent (204): Symfony tar bort
            // Content-Type ur ett 204-svar när det förbereds, och LiteSpeed
            // sätter inte typen själv vid intern omdirigering — då hade typen
            // aldrig nått webbservern. Med 200 följer headern med, och
            // LiteSpeed ersätter kroppen med filens byten.
            return response()->make('', 200, $headers + [
                'X-LiteSpeed-Location' => '/_protected/'.$storagePath,
            ]);
        }

        return Storage::disk('files')->response($storagePath, $attachment->filename, $headers);
    }

    /**
     * Sökvägen till de byten som ska levereras: originalets, eller den
     * efterfrågade variantens.
     *
     * Ett okänt variant-värde är 404, inte 422 — det här är en webbrutt utan
     * valideringshölje (issue 19a § Beslut 5) — och en variant som saknas är
     * också 404, aldrig en tyst återgång till originalet.
     *
     * Publik därför att präglingen av en signerad länk behöver samma
     * validering utan att bry sig om svaret: en osignerad kontroll av att
     * varianten finns, innan länken alls skapas.
     */
    public static function storagePath(StoredFile $storedFile, mixed $variant): string
    {
        if ($variant === null) {
            return $storedFile->storage_path;
        }

        if (! is_string($variant) || ! in_array($variant, ['thumb', 'medium'], true)) {
            abort(404);
        }

        $derivative = $storedFile->derivatives()->where('variant', $variant)->first();

        if ($derivative === null) {
            abort(404);
        }

        return $derivative->storage_path;
    }

    /**
     * Tillåt-listan för `inline`, se issue 61a § Beslut 5.
     *
     * `image/svg+xml` står med flit **inte** i listan: en SVG är ett dokument
     * som kan bära skript, och den ska levereras som `attachment` även på
     * filoriginet. Allt som inte står här är `attachment`.
     */
    private static function isInlineAllowed(string $mimeType): bool
    {
        return in_array($mimeType, config('files.inline_mime_types'), true);
    }

    /**
     * Content-Disposition byggs alltid av Symfonys hjälpare (issue 19a
     * § Beslut 4): `$filename` i `filename*=UTF-8''…` och en ASCII-fallback i
     * `filename=`. Ett namn med citattecken, semikolon eller å-ä-ö sätts
     * aldrig ihop för hand — en oescapad rad i en header är en
     * headerinjektion.
     *
     * Blir fallbacken tom (namn helt utan ASCII-tecken, t.ex. `写真`) eller
     * innehåller den tecken utanför det skrivbara ASCII-intervallet kastar
     * Symfonys hjälpare InvalidArgumentException. Den kan inte användas som
     * `filename=`, men `filename*=UTF-8''…` bär fortfarande det riktiga
     * namnet — fallbacken faller tillbaka på `download`, inget går förlorat.
     */
    private static function contentDisposition(string $disposition, string $filename): string
    {
        $fallback = str_replace('%', '', Str::ascii($filename));

        if (! preg_match('/^[\x20-\x7e]+$/', $fallback)) {
            $fallback = 'download';
        }

        return HeaderUtils::makeDisposition($disposition, $filename, $fallback);
    }
}
