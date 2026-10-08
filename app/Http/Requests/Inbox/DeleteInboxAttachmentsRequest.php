<?php

namespace App\Http\Requests\Inbox;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Kroppen för `POST /inbox/attachments/delete` — `{"attachments": ["<ulid>",
 * …]}`. M28 · issue 775, se [[ADR-0054 Inboxen]] § 2 och § 6.
 *
 * **Samma kroppsform som App\Http\Requests\Inbox\ProcessInboxAttachmentsRequest,
 * utan `target`.** Raderingen har inget mål: bilagorna försvinner ur
 * användarens EGEN inbox och ingenstans hän. Att kroppen ändå är en ULID-lista
 * är det som gör att webbens *Delete selected* följer samma väg som *Move
 * selected…* — en rad, en kryssruta, en sats.
 *
 * **Bilagorna prövas i kontrollern, inte här.** Att en ULID finns och ligger i
 * användarens egen inbox är en fråga om var raden står, och svaret är 422
 * `attachment.not_in_inbox` för hela satsen — samma svar som bearbetningen ger
 * (ADR-0054 § 6). `listan` får inte vara tom och får inte bära dubbletter:
 * annars hade samma bilaga kunnat träffas två gånger i samma transaktion.
 */
class DeleteInboxAttachmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attachments' => ['required', 'array', 'min:1'],
            'attachments.*' => ['required', 'string', 'distinct'],
        ];
    }
}
