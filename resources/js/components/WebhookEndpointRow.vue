<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import WebhookEndpointForm from './WebhookEndpointForm.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En rad i kontots webhooklista, se issue 65b § Beslut 3, 6, 7 och 8.
 *
 * **Adressen visas, hemligheten aldrig.** `url` är endpointens adress och står
 * i listan sedan 37a; `secret` bärs inte av
 * App\Http\Resources\WebhookEndpointResource och finns bara i klartext i
 * svaret på skapandet (Beslut 3). Raden här ritar därför ingen hemlighet alls.
 *
 * **Raden har två lägen, och redigeringen bor i den.** *Redigera* byter ut
 * raden mot WebhookEndpointForm i redigeringsläge — samma formulär som
 * skapandet, men PATCH och utan hemlighet (Beslut 3). Formuläret står kvar
 * till `saved` eller *Avbryt*: en PATCH svarar `back()`, och `preserveState`
 * är satt för icke-GET, så utan kvittensen hade raden blivit stående i
 * redigeringsläget efter en lyckad ändring.
 *
 * **En avstängd rad säger VEM som stängde av den** (Beslut 8). Systemet
 * stänger av en endpoint när `consecutive_failures` nått taket
 * (App\Console\DeliversWebhooks § registerEndpointFailure), och en rad som
 * bara såg avstängd ut hade sett ut som om användaren själv gjort det. Flaggan
 * `disabledBySystem` räknas på servern — modellen bär räknaren, och tröskeln
 * bor i config/notiser.php — och läses här, aldrig om i vyn.
 *
 * Båda fallen får en knapp som slår PÅ endpointen igen. Återaktiveringen
 * nollställer räknaren på servern (37a § Beslut 3): utan det hade endpointen
 * stängts av igen efter ett enda fel.
 *
 * **Raderingen är hård** och bekräftelsen är webbläsarens egen dialog med
 * serverns mening ur lang/ — samma mönster som schemat, taggen och bilagan.
 * Meningen säger att endpointen tas bort och att hemligheten därmed är borta,
 * för en ny endpoint är den enda vägen till en ny hemlighet. Det är därför
 * redigeringen finns: den som bara vill ändra adressen eller lägga till en
 * händelsetyp ska inte behöva rotera hemligheten för det.
 *
 * `account` följer med i båda anropen: PATCH bär det i kroppen och DELETE i
 * querysträngen. Webben har ingen `{account}` i sökvägen (Beslut 1), och
 * kontrollern prövar grinden mot det kontot — utan fältet hade anropet mötts
 * av 404. Samma fält skickar redigeringsformuläret.
 */
const props = defineProps({
    /* En rad ur App\Http\Resources\WebhookEndpointResource, plus
       `disabledBySystem` som kontrollern lägger bredvid (Beslut 8). */
    endpoint: { type: Object, required: true },

    /* Kontot raden hör till — det POST/PATCH/DELETE gäller. */
    accountUlid: { type: String, required: true },

    /* Typerna ur App\Models\WebhookEndpoint::EVENT_TYPES, till redigeringen. */
    types: { type: Array, required: true },
});

const { t } = useTranslations();

const editing = ref(false);

const url = computed(() => `/settings/webhooks/${props.endpoint.ulid}`);

const eventTypes = computed(() => props.endpoint.event_types
    .map((type) => t(`webhook.event_type.${type}.label`))
    .join(', '));

function toggle() {
    router.patch(url.value, {
        account: props.accountUlid,
        is_active: ! props.endpoint.is_active,
    }, {
        preserveScroll: true,
    });
}

function destroy() {
    if (! window.confirm(t('webhook.destroy_confirm'))) {
        return;
    }

    router.delete(`${url.value}?account=${props.accountUlid}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <li
        class="flex flex-col gap-2 rounded border border-slate-300 bg-white px-4 py-3"
        :class="editing || endpoint.is_active ? null : 'text-slate-500'"
    >
        <template v-if="editing">
            <WebhookEndpointForm
                :account-ulid="props.accountUlid"
                :types="props.types"
                :endpoint="props.endpoint"
                @saved="editing = false"
            />

            <button
                type="button"
                class="self-start text-sm font-medium text-slate-700 hover:underline"
                @click="editing = false"
            >
                {{ t('webhook.cancel') }}
            </button>
        </template>

        <template v-else>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="break-all font-medium">{{ endpoint.url }}</span>

                <span
                    v-if="! endpoint.is_active"
                    class="rounded bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700"
                >
                    {{ t('webhook.inactive_badge') }}
                </span>
            </div>

            <p class="text-sm text-slate-600">{{ eventTypes }}</p>

            <p v-if="endpoint.disabledBySystem" class="text-sm text-amber-800">
                {{ t('webhook.disabled_by_system') }}
            </p>

            <p v-else-if="! endpoint.is_active" class="text-sm">
                {{ t('webhook.disabled_by_user') }}
            </p>

            <div class="flex flex-wrap gap-4 text-sm">
                <button
                    type="button"
                    class="font-medium text-blue-700 hover:underline"
                    @click="editing = true"
                >
                    {{ t('webhook.edit') }}
                </button>

                <button
                    type="button"
                    class="font-medium text-blue-700 hover:underline"
                    @click="toggle"
                >
                    {{ endpoint.is_active ? t('webhook.deactivate') : t('webhook.activate') }}
                </button>

                <button
                    type="button"
                    class="font-medium text-red-700 hover:underline"
                    @click="destroy"
                >
                    {{ t('webhook.destroy') }}
                </button>
            </div>
        </template>
    </li>
</template>
