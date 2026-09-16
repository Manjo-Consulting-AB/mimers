<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import WebhookEventTypeField from './WebhookEventTypeField.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Formuläret som registrerar en webhook-endpoint, se issue 65b § Beslut 3, 5
 * och 7.
 *
 * **Två fält, båda validerade av servern.** Adressen av
 * StoreWebhookEndpointRequest och händelsetyperna av samma FormRequest —
 * ingen klientregel, ingen egen kontroll av privata IP-intervall, `localhost`
 * eller metadatatjänster (Beslut 7). Servern äger SSRF-frågan, och en
 * klientkontroll som säger något annat än servern är en bugg som ser ut som
 * ett fel hos användaren. Serverns `webhook.unsafe_url` blir ett fältfel på
 * `url` och serverns `validation.min` ett fältfel på `event_types`.
 *
 * **Planfelet hamnar på `plan` och ritas som en ruta** (Beslut 5). Det
 * handlar inte om vad användaren skrev utan om kontots plan, och nyckeln är
 * därför en formulärnyckel och inte ett fältnamn — samma val som `quota` i
 * App\Http\Controllers\ContainerController::store(). Meningen är serverns:
 * App\Http\Controllers\WebhookEndpointController formulerar den en gång och
 * skickar den både som `planNotice` till sidan och som fältfel hit, så de två
 * kan inte glida isär.
 *
 * **`account` skickas med i kroppen** och är inte ett formulärfält: `/api`
 * tar kontot ur rutten, webben ur det valda kontot (Beslut 1).
 * UpdateWebhookEndpointRequest och StoreWebhookEndpointRequest validerar
 * ingendera nyckeln, så den följer med utan att någon regel ser den.
 *
 * Formuläret ritas även för ett konto utan funktionen — sidan bär förklaringen
 * och servern svaret. Att dölja knappen vore att dölja en funktion man kan
 * köpa (Beslut 5), och en dold knapp är ingen behörighetskontroll (M10
 * § ingressen).
 */
const props = defineProps({
    /* Kontots ULID — det konto POST gäller och den vars plan grinden läser. */
    accountUlid: { type: String, required: true },

    /* Typerna ur App\Models\WebhookEndpoint::EVENT_TYPES. */
    types: { type: Array, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    account: props.accountUlid,
    url: '',
    event_types: [],
});

const planError = computed(() => form.errors.plan ?? null);

function submit() {
    form.post('/settings/webhooks', { onError: focusFirstError });
}
</script>

<template>
    <form class="flex flex-col gap-4 rounded border border-slate-200 bg-white p-4" @submit.prevent="submit">
        <FormField
            v-slot="{ describedBy }"
            :label="t('webhook.url_label')"
            id="url"
            :error="form.errors.url"
        >
            <input
                id="url"
                v-model="form.url"
                :aria-describedby="describedBy"
                type="url"
                name="url"
                required
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

        <p class="text-sm text-slate-600">{{ t('webhook.url_hint') }}</p>

        <WebhookEventTypeField
            v-model="form.event_types"
            :types="props.types"
            :error="form.errors.event_types"
        />

        <p v-if="planError" role="alert" tabindex="-1" class="text-sm text-red-700 outline-none">
            {{ planError }}
        </p>

        <button
            type="submit"
            :disabled="form.processing"
            class="self-start rounded bg-blue-700 px-4 py-2 font-medium text-white disabled:opacity-50"
        >
            {{ t('webhook.create') }}
        </button>
    </form>
</template>
