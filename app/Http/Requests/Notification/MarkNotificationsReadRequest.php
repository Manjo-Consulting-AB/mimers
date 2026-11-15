<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /notifications/read — markeringen av de visade notiserna i webben,
 * se [[ADR-0077 Notislistan i appen]] § 3 och M60 · issue 384.
 *
 * Kroppen är `{"ulids": ["...", "..."]}`. Fältet är `sometimes` av ett skäl
 * som står i kontrollern: innan ytan i 385 finns postar den gamla klockan
 * samma rutt utan kropp, och den skrivningen ska fortsätta fungera. En
 * ULID som inte är personens egen avvisas inte här — actionen markerar bara
 * personens egna rader, så en främmande ULID är en tyst no-op. API:ets
 * `{ulid}/read` svarar däremot 404, eftersom den rutten pekar ut en enda rad.
 */
final class MarkNotificationsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'ulids' => ['sometimes', 'array'],
            'ulids.*' => ['string', 'ulid'],
        ];
    }
}
