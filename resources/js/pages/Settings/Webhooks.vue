<script setup>
import { Head, router } from '@inertiajs/vue3';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import SecretOnce from '../../components/SecretOnce.vue';
import WebhookEndpointForm from '../../components/WebhookEndpointForm.vue';
import WebhookEndpointRow from '../../components/WebhookEndpointRow.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Kontots webhooks, se issue 65b § Beslut 1, 3, 5 och 8.
 *
 * **Kontonivån, inte personen.** En webhook hör till kontot — kontot äger
 * URL:en, betalar för funktionen och är det vars plan grinden läser — och
 * sidan har därför en kontoväljare i stället för en lista över användarens
 * egna endpoints. Sidan är den andra i inställningarna som arbetar mot ETT
 * konto i taget, efter 53c:s kontosida.
 *
 * **Väljaren listar ALLA konton användaren är med i** (Beslut 1). Att
 * utelämna ett konto ur listan vore att dölja en knapp, och
 * behörighetskontroller görs i policies (M10 § ingressen). Servern prövar
 * `manageWebhooks` mot det VALDA kontot, och en `member` som väljer ett konto
 * hon inte får förvalta får 403 — inte en tyst tom lista.
 *
 * **Kontot följer med i varje anrop.** Ingen `{account}` finns i sökvägen
 * (Beslut 1), så PATCH bär det i kroppen, DELETE i querysträngen och POST i
 * formuläret. Väljaren byter konto med en GET mot samma sida och `?account=`.
 *
 * **Hemligheten visas EN gång**, i SecretOnce, precis som kalenderadressen:
 * App\Http\Resources\WebhookEndpointResource bär den aldrig, och `secret` är
 * satt bara i visningen direkt efter ett skapande (Beslut 3). Meningen säger
 * vad den används till — HMAC-SHA256 över kroppen, så mottagaren kan verifiera
 * att leveransen kommer från oss — och att den inte går att se igen.
 *
 * **Planen är en mening och inte en dold knapp** (Beslut 5). `planNotice` är
 * serverns mening om vilken plan som krävs, och formuläret ritas ändå: ett
 * konto utan funktionen ser ytan, förstår vad den kostar och får serverns
 * svar när det försöker. Att dölja ytan vore att dölja en funktion man kan
 * köpa.
 *
 * Listan visar kontots endpoints som de kom ur servern; att en avstängd rad
 * förklarar VEM som stängde av den är WebhookEndpointRow, och flaggan räknas
 * på servern (Beslut 8).
 *
 * **Raden kan redigeras på plats** (Beslut 3). Sidan skickar händelsetyperna
 * vidare till raden av samma skäl som till skapandeformuläret — listan kommer
 * ur App\Models\WebhookEndpoint::EVENT_TYPES och skrivs inte av i JavaScript —
 * och raden avgör själv om den visar sig eller sitt formulär. Ett redigeringsläge
 * i taget behövs inte: formulären får sina id:n per endpoint.
 */
const props = defineProps({
    /* Användarens konton, `{ulid, name}`, sorterade på namn. */
    accounts: { type: Array, required: true },

    /* Det valda kontot, `{ulid, name}`. */
    account: { type: Object, required: true },

    /*
     * Kontots endpoints ur App\Http\Resources\WebhookEndpointResource, med
     * `disabledBySystem` lagd bredvid av kontrollern.
     */
    endpoints: { type: Array, required: true },

    /* Typerna ur App\Models\WebhookEndpoint::EVENT_TYPES. */
    eventTypes: { type: Array, required: true },

    /* Serverns mening om planen, eller null när funktionen är öppen. */
    planNotice: { type: String, default: null },

    /* Klartexthemligheten ur flashen — bara satt direkt efter ett skapande. */
    secret: { type: String, default: null },
});

const { t } = useTranslations();

/*
 * Kontobytet. En GET mot samma sida med `?account=` — servern faller tillbaka
 * på ett förval om ULID:n inte är användarens, så en handskriven adress kan
 * inte peka ut någon annans konto. Ingen `preserveState`: formuläret ska
 * börja om när kontot byts, för det tillhör det gamla kontot.
 */
function selectAccount(event) {
    router.get('/settings/webhooks', { account: event.target.value }, { preserveScroll: true });
}
</script>

<template>
    <SettingsLayout>
        <Head :title="t('webhook.title')" />

        <h1 class="text-2xl font-semibold">{{ t('webhook.heading') }}</h1>
        <p class="mt-2 text-sm text-slate-700">{{ t('webhook.intro') }}</p>

        <!-- Väljaren ritas bara när det finns något att välja mellan: ett
             enkelt kontokonto har inget val, och en nedfällbar lista med ett
             alternativ är en fråga utan svar. -->
        <div v-if="props.accounts.length > 1" class="mt-6 flex flex-col gap-1">
            <label for="account" class="text-sm font-medium text-slate-800">{{ t('webhook.account_label') }}</label>

            <select
                id="account"
                :value="props.account.ulid"
                class="self-start rounded border border-slate-300 bg-white px-3 py-2"
                @change="selectAccount"
            >
                <option v-for="option in props.accounts" :key="option.ulid" :value="option.ulid">
                    {{ option.name }}
                </option>
            </select>
        </div>

        <p v-if="props.planNotice" class="mt-6 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            {{ props.planNotice }}
        </p>

        <SecretOnce
            v-if="props.secret"
            class="mt-8"
            :label="t('webhook.secret_label')"
            :value="props.secret"
            :description="t('webhook.secret_description')"
            :once="t('webhook.secret_once')"
            :copy-label="t('webhook.copy')"
            :copied-label="t('webhook.copied')"
        />

        <section class="mt-8">
            <h2 class="text-lg font-semibold">{{ t('webhook.create_heading') }}</h2>

            <WebhookEndpointForm
                class="mt-2"
                :account-ulid="props.account.ulid"
                :types="props.eventTypes"
            />
        </section>

        <section class="mt-10">
            <h2 class="text-lg font-semibold">{{ t('webhook.list_heading') }}</h2>

            <p v-if="props.endpoints.length === 0" class="mt-2 text-sm text-slate-700">
                {{ t('webhook.empty') }}
            </p>

            <ul v-else class="mt-2 flex flex-col gap-2">
                <WebhookEndpointRow
                    v-for="endpoint in props.endpoints"
                    :key="endpoint.ulid"
                    :endpoint="endpoint"
                    :account-ulid="props.account.ulid"
                    :types="props.eventTypes"
                />
            </ul>
        </section>
    </SettingsLayout>
</template>
