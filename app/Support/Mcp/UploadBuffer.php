<?php

namespace App\Support\Mcp;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * En temporär fil som blir en `UploadedFile` åt StoreAttachment, se issue 405.
 *
 * Båda uppladdningsvägarna i MCP — den base64-kodade i verktygsanropet och
 * den signerade adressens `PUT` — har byten i minnet eller i en ström, inte i
 * PHPs uppladdningsmekanism. StoreAttachment tar en `Illuminate\Http\
 * UploadedFile`, så bytena skrivs först till en temporär fil; den försvinner
 * i `discard()` oavsett hur anropet slutar.
 *
 * `UploadedFile` skapas med `$test = true`: filen är inte uppladdad genom
 * PHPs `is_uploaded_file`, och `isValid()` ska inte avvisa den. Hashen och
 * MIME-typen räknas ändå på filens faktiska innehåll i StoreAttachment.
 */
final class UploadBuffer
{
    private string $path;

    /** @var resource|null */
    private $handle;

    private int $bytes = 0;

    public function __construct(private readonly string $filename)
    {
        $path = tempnam(sys_get_temp_dir(), 'mcp-upload-');

        if ($path === false) {
            throw new RuntimeException('Kunde inte skapa en temporär fil för uppladdningen.');
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Kunde inte öppna den temporära filen för uppladdningen.');
        }

        $this->path = $path;
        $this->handle = $handle;
    }

    public function write(string $bytes): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('Den temporära filen är redan stängd.');
        }

        fwrite($this->handle, $bytes);
        $this->bytes += strlen($bytes);
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * Stäng filen och lämna den som StoreAttachment tar emot. Anropas en
     * gång, efter att alla byten skrivits.
     */
    public function uploadedFile(): UploadedFile
    {
        $this->close();

        return new UploadedFile($this->path, $this->filename, null, null, true);
    }

    /**
     * Ta bort den temporära filen. Tål att anropas både före och efter
     * uploadedFile().
     */
    public function discard(): void
    {
        $this->close();

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    private function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
