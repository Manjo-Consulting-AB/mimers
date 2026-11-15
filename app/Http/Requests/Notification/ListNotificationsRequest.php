<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /notifications och GET /api/notifications, se [[ADR-0077 Notislistan i
 * appen]] § 2 och 3 samt M60 · issue 384.
 *
 * **Bara två fält, och båda är frivilliga.** `cursor` är markören från föregående
 * sida — en ogenomskinlig sträng som actionen tolkar, och en trasig markör ger
 * första sidan i stället för ett fel (App\Actions\Notification\
 * ListInAppNotifications). `limit` är sidans storlek; taket på 50 ligger i
 * actionen, så ett för stort värde klipps i stället för att avvisas — "högst
 * 50 per sida" är ett tak, inte ett valideringsfel.
 *
 * Ingen `authorize()`-logik: rutterna ligger bakom inloggning, och frågan bär
 * ingen annan persons data än anroparens egen.
 */
final class ListNotificationsRequest extends FormRequest
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
            'cursor' => ['sometimes', 'nullable', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * Markören ur querysträngen, eller `null` för första sidan.
     */
    public function cursor(): ?string
    {
        $cursor = $this->query('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    /**
     * Sidans storlek. Förvalet är 20 (§ 2); actionen klipper vid 50.
     */
    public function limit(int $default = 20): int
    {
        return $this->integer('limit', $default);
    }
}
