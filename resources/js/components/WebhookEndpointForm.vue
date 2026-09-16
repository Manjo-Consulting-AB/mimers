<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import WebhookEventTypeField from './WebhookEventTypeField.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Formuläret som registrerar eller ändrar en webhook-endpoint, se issue 65b
 * § Beslut 3, 5, 6 och 7.
 *
 * **Samma formulär i två lägen.** `endpoint` är `null` när en ny endpoint
 * skapas och raden när en befintlig ändras — samma två fält, samma kryssrutor
 * och samma översättning av serverns fel, precis som ItemForm § Beslut 4. Den
 * enda skillnaden är metoden (POST mot PATCH) och knappens ord, och skälet att
 * ytan alls finns är HEMLIGHETEN: "ta bort och skapa ny" roterar `secret`, och
 * då måste mottagarsidan (n8n, Zapier, en egen mottagare) konfigureras om bara
 * för att en händelsetyp lades till eller för att mottagaren bytte värdnamn.
 * Det är en oproportionerlig kostnad för en ändring `PATCH` redan stöder, och
 * `UpdateWebhookEndpointRequest` finns (Beslut 3).
 *
 * **Hemligheten finns inte i redigeringsläget.** `PATCH` rör aldrig `secret`
 * och inget svar bär den: en ny hemlighet är en ny endpoint, så en redigering
 * visar ingen SecretOnce och rotar ingenting (Beslut 3).
 *
 * **Två fält, båda validerade av servern.** Adressen av
 * StoreWebhookEndpointRequest och UpdateWebhookEndpointRequest — ingen
 * klientregel, ingen egen kontroll av privata IP-intervall, `localhost` eller
 * metadatatjänster (Beslut 7). Servern äger SSRF-frågan, och en klientkontroll
 * som säger något annat än servern är en bugg som ser ut som ett fel hos
 * användaren. Serverns `webhook.unsafe_url` blir ett fältfel på `url` och
 * serverns `validation.min` ett fältfel på `event_types` — i båda lägena, för
 * båda FormRequests bär samma gränser.
 *
 * **Planfelet hamnar på `plan` och ritas som en ruta** (Beslut 5). Det
 * handlar inte om vad användaren skrev utan om kontots plan, och nyckeln är
 * därför en formulärnyckel och inte ett fältnamn — samma val som `quota` i
 * App\Http\Controllers\ContainerController::store(). Meningen är serverns:
 * App\Http\Controllers\WebhookEndpointController formulerar den en gång och
 * skickar den både som `planNotice` till sidan och som fältfel hit, så de två
 * kan inte glida isär. Grinden sitter på POST och PATCH, så redigeringsläget
 * möter samma mening (Beslut 5).
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
    /* Kontots ULID — det konto POST/PATCH gäller och den vars plan grinden läser. */
    accountUlid: { type: String, required: true },

    /* Typerna ur App\Models\WebhookEndpoint::EVENT_TYPES. */
    types: { type: Array, required: true },

    /* Endpointen som redigeras, eller null när en ny skapas. */
    endpoint: { type: Object, default: null },
});

const emit = defineEmits(['saved']);

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    account: props.accountUlid,
    url: props.endpoint?.url ?? '',
    event_types: props.endpoint?.event_types ?? [],
});

const planError = computed(() => form.errors.plan ?? null);

/*
 * Knappens etikett: väntetexten medan servern svarar, annars skapande- eller
 * sparandetexten beroende på läge — etiketten byter medan knappen väntar,
 * annars ser en stillastående knapp ut som en död sida (Beslut 4).
 */
const submitLabel = computed(() => (form.processing
    ? t('common.pending.default')
    : (props.endpoint === null ? t('webhook.create') : t('webhook.save'))));

/*
 * Formulärets id:n får ett suffix i redigeringsläget. Skapandeformuläret och
 * en rads redigeringsformulär kan stå på samma sida, och två element med samma
 * id hade gjort labelns `for` och fältets `aria-describedby` tvetydiga — den
 * som klickade på etiketten i det ena formuläret hade hamnat i det andra.
 */
const idSuffix = computed(() => props.endpoint?.ulid ?? '');

const fieldId = (name) => (idSuffix.value === '' ? name : `${name}-${idSuffix.value}`);

/*
 * Redigeringen svarar `back()` på servern, så en lyckad PATCH lämnar sidan
 * som den var — `preserveState` är satt för icke-GET, så raden hade blivit
 * kvar i redigeringsläget utan kvittensen. `saved` stänger den.
 */
function submit() {
    if (props.endpoint === null) {
        form.post('/settings/webhooks', { onError: focusFirstError });

        return;
    }

    form.patch(`/settings/webhooks/${props.endpoint.ulid}`, {
        onError: focusFirstError,
        onSuccess: () => emit('saved'),
    });
}
</script>

<template>
    <form class="flex flex-col gap-4 rounded border border-slate-200 bg-white p-4" @submit.prevent="submit">
        <FormField
            v-slot="{ describedBy }"
            :label="t('webhook.url_label')"
            :id="fieldId('url')"
            :error="form.errors.url"
        >
            <input
                :id="fieldId('url')"
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
            :id-suffix="idSuffix"
        />

        <p v-if="planError" role="alert" tabindex="-1" class="text-sm text-red-700 outline-none">
            {{ planError }}
        </p>

        <button
            type="submit"
            :disabled="form.processing"
            class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
        >
            {{ submitLabel }}
        </button>
    </form>
</template>
