<?php

namespace App\Http\Requests\Inbox;

use App\Http\Requests\TargetItemRequest;

/**
 * Kroppen för `POST /inbox/attachments/process` —
 * `{"target": "<item-ulid>", "attachments": ["<ulid>", …]}`. M27 · issue 245,
 * se [[ADR-0054 Inboxen]] § 6.
 *
 * **Målet är ett item och kan ligga var som helst** där användaren har
 * `create`, precis som för en flytt: ULID:n slås upp globalt av
 * App\Http\Requests\TargetItemRequest, som den här klassen ärver — 404 för en
 * ULID som saknas eller är mjukraderad.
 *
 * **Bilagorna är ULID:er och prövas i actionen, inte här.** Att en ULID finns
 * och ligger i användarens EGEN inbox är en fråga om var raden står, och
 * svaret är 422 `attachment.not_in_inbox` för hela satsen (ADR-0054 § 6) —
 * samma svar oavsett om bilagan saknas, ligger på ett riktigt item eller i
 * någon annans inbox. `listan` får inte vara tom och får inte bära dubbletter:
 * en dubblett hade räknat samma bilaga två gånger i kvotsumman.
 */
class ProcessInboxAttachmentsRequest extends TargetItemRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target' => ['required', 'string'],
            'attachments' => ['required', 'array', 'min:1'],
            'attachments.*' => ['required', 'string', 'distinct'],
        ];
    }
}
