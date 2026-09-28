<?php

namespace App\Http\Requests\Container;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /containers/{container}/cover — containerns bild, se [[ADR-0047
 * Containerns bild]] § Beslut och [[M23 Mobilen och kartan]] § 159.
 * multipart/form-data, en fil per anrop i fältet `file`.
 *
 * **Formen är StoreAttachmentRequest:s, och `account` står här av samma skäl
 * som där.** Kontot som betalar för bytena är det UPPLADDANDE kontot
 * ([[ADR-0047 Containerns bild]] § Beslut: *"`billed_account_id` är det
 * uppladdande kontot, och bytena räknas mot dess kvot"*, och samma regel i
 * [[ADR-0003 Åtkomstmodell]], [[Filer och lagring]] § attachment och [[Planer
 * och kvoter]]). Det är inte containerns ägarkonto: en container-bred
 * `write`-mottagare som är främmande för ägarkontot skulle annars belasta
 * ägarkontots kvot med sina egna byten, vilket är exakt den vektor
 * [[ADR-0017 Missbruksvektorer]] stänger.
 *
 * Arket har fortfarande TRE rader och ingen kontoväljare (ADR-0047 § Beslut,
 * sista stycket). Fältet är alltså inte ett val någon gör i vyn:
 * resources/js/components/ContainerCoverSheet.vue skickar samma förval som
 * itemets bilageuppladdning (resources/js/components/ItemAttachmentSection.vue)
 * — containerns ägarkonto när användaren är medlem i det, annars hennes eget
 * första konto — och fältet ligger dolt. Servern prövar medlemskapet och nekar
 * ett konto användaren inte är medlem i, precis som
 * App\Http\Controllers\AttachmentController gör för en itembilaga.
 *
 * `account` är ett obligatoriskt konto-ULID, och bara `active`-konton duger
 * som betalkonto — samma `exists`-regel som StoreAttachmentRequest, av samma
 * skäl: ett `read_only`- eller `closed`-konto ska inte kunna belastas för nya
 * byten. Ett konto som finns men som användaren inte är medlem i är ett
 * behörighetsfel (403) som kontrollern kastar, inte här.
 *
 * **`file` valideras för närvaro och storlek.** Taket är en TEKNISK spärr
 * (issue 16a § Beslut 9), inte en plangräns — `max` räknar kilobyte, därav
 * divisionen. `config('files.max_upload_bytes')` defaultar till 64 MiB, samma
 * tal som upload_max_filesize i public/.htaccess.
 *
 * **Slaget prövas inte här.** Att filen är en bild är App\Actions\Attachment\
 * StoreAttachment::handleForContainer():s krav, och det ställs på den SNIFFADE
 * MIME-typen efter att filen tagits emot (StoreAttachment § Beslut 5). En
 * `mimes:`-regel här hade varit en andra sanning om vad filen är, byggd på
 * filändelsen.
 */
class ContainerCoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörigheten i
        // kontrollern, inte här — samma uppdelning som StoreAttachmentRequest.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) (config('files.max_upload_bytes') / 1024)],
            'account' => ['required', 'string', Rule::exists('account', 'ulid')->where(fn ($query) => $query->where('status', 'active'))],
        ];
    }
}
