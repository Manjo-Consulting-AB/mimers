<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Plansidan, se issue 66a § Beslut 1 och 2.
 *
 * **Kontot väljs i sidan**, som på 53c:s kontosida och 65b:s webhookar: en
 * sida som byter konto ska inte behöva byta URL. Servern prövar
 * `AccountPolicy::viewStorage` mot DET valda kontot, så en medlem i tre konton
 * ser tre olika svar och får 403 — inte en tom sida — för ett konto hon inte
 * är med i.
 *
 * **Kontots namn står alltid på sidan**, oavsett om väljaren ritas: siffrorna
 * är kontots egna, och en sida som visar förbrukning utan att säga för vilket
 * konto är en sida man läser fel (Beslut 1). Väljaren ritas bara när det finns
 * något att välja mellan — en nedfällbar lista med ett alternativ är en fråga
 * utan svar (samma val som Webhooks.vue).
 *
 * **Ingen siffra räknas i den här filen.** Gränserna, förbrukningen,
 * procenten och nedgraderingens tal kommer ur
 * App\Actions\Plan\ReadPlanUsage, och bytena är redan formaterade av servern
 * med Number::fileSize() (Beslut 3, 4 och 8). Klienten formaterar ingenting
 * och räknar ingenting: en egen formel här skulle glida isär från den servern
 * nekar en uppladdning med.
 *
 * **Ingen sträng står heller här.** Varje mening kommer ur `t()` med en nyckel
 * under `plan.*` — plannamnet per `code` inräknat (Beslut 9) — och de två
 * dagformuleringarna ur 62a:s `trash.expires.day`/`trash.expires.days`, som
 * issuen pekar ut: `t()` har ingen pluralisering (issue 52 § Beslut 4), så
 * talet väljer nyckel och meningen är densamma om samma sak.
 */
const props = defineProps({
    /* Användarens konton, `{ulid, name}`, sorterade på namn. */
    accounts: { type: Array, required: true },

    /* Det valda kontot, `{ulid, name}`. */
    account: { type: Object, required: true },

    /* Planen: `{code, price, period}`, där `price` är färdigformaterad. */
    plan: { type: Object, required: true },

    /*
     * Kontots status: `{code, reason, graceDaysLeft}`. `reason` är null för ett
     * `active` konto, och `graceDaysLeft` är null när det inte finns någon
     * frist att räkna på.
     */
    status: { type: Object, required: true },

    /*
     * Förbrukningen: `{containers, storage, maxFile, sharedUsersPerContainer}`.
     * Varje gräns som kan vara obegränsad är `null` och aldrig noll.
     */
    usage: { type: Object, required: true },

    /* Funktionstabellen, `[{key, included}]`, i serverns ordning. */
    features: { type: Array, required: true },

    /*
     * Nedgraderingens pris, eller null när allt ryms i Free (Beslut 7).
     * `attachmentsToRemove` är antalet bilagor som faktiskt skulle raderas,
     * nyast först — inte ett teoretiskt minimum.
     */
    downgrade: { type: Object, default: null },
});

const { t } = useTranslations();

/* Vänteläget på kontobytet: en GET mot samma sida är en ny sidvisning. */
const pending = ref(false);

/*
 * Kontobytet. En GET mot samma sida med `?account=` — servern faller tillbaka
 * på ett förval om ULID:n inte är användarens, så en handskriven adress kan
 * inte peka ut någon annans konto.
 */
function selectAccount(event) {
    router.get('/settings/plan', { account: event.target.value }, {
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

/*
 * Antalet dagar av fristen. Två nycklar och inte tre: 62a:s `today` hör till
 * papperskorgens sista dygn, och fristeräkningen här är `grace_until` minus
 * serverns nu — ett tal som blir noll när dagen är inne.
 */
function graceLabel(days) {
    return days === 1
        ? t('trash.expires.day')
        : t('trash.expires.days', { days });
}
</script>

<template>
    <SettingsLayout>
        <Head :title="t('plan.title')" />

        <h1 class="text-2xl font-semibold">{{ t('plan.heading') }}</h1>
        <p class="mt-2 text-sm text-slate-700">{{ t('plan.intro') }}</p>

        <div v-if="props.accounts.length > 1" class="mt-6 flex flex-col gap-1">
            <label for="account" class="text-sm font-medium text-slate-800">{{ t('plan.account_label') }}</label>

            <select
                id="account"
                :value="props.account.ulid"
                :disabled="pending"
                class="self-start rounded border border-slate-300 bg-white px-3 py-2"
                @change="selectAccount"
            >
                <option v-for="option in props.accounts" :key="option.ulid" :value="option.ulid">
                    {{ option.name }}
                </option>
            </select>
        </div>

        <!-- Kontots status överst, inte nedgrävd (Beslut 5). Rutan ritas bara
             när det finns en orsak: ett `active` konto har ingenting att säga
             om sin status, och "aktivt" är ingen nyhet. -->
        <section
            v-if="props.status.reason"
            class="mt-6 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
        >
            <p class="font-medium">{{ t(`plan.status.${props.status.reason}`) }}</p>
            <p v-if="props.status.graceDaysLeft !== null" class="mt-1">
                {{ t('plan.status.grace') }} {{ graceLabel(props.status.graceDaysLeft) }}
            </p>
        </section>

        <section class="mt-8 rounded border border-slate-200 bg-white p-4 md:p-6">
            <h2 class="text-lg font-semibold">{{ t('plan.current_heading') }}</h2>

            <dl class="mt-4 flex flex-col gap-1 text-sm">
                <div class="flex flex-col gap-1 md:flex-row md:gap-2">
                    <dt class="shrink-0 text-slate-600 md:w-40">{{ t('plan.account_label') }}</dt>
                    <dd>{{ props.account.name }}</dd>
                </div>
                <div class="flex flex-col gap-1 md:flex-row md:gap-2">
                    <dt class="shrink-0 text-slate-600 md:w-40">{{ t('plan.name_label') }}</dt>
                    <dd>{{ t(`plan.names.${props.plan.code}`) }}</dd>
                </div>
                <div class="flex flex-col gap-1 md:flex-row md:gap-2">
                    <dt class="shrink-0 text-slate-600 md:w-40">{{ t('plan.price_label') }}</dt>
                    <dd>{{ props.plan.price }} {{ t(`plan.period.${props.plan.period}`) }}</dd>
                </div>
            </dl>
        </section>

        <section class="mt-8">
            <h2 class="text-lg font-semibold">{{ t('plan.usage_heading') }}</h2>

            <dl class="mt-4 flex flex-col gap-4 text-sm">
                <div>
                    <dt class="text-slate-600">{{ t('plan.limits.containers') }}</dt>
                    <dd class="mt-1">
                        {{ props.usage.containers.limit === null
                            ? t('plan.of_unlimited', { used: props.usage.containers.used })
                            : t('plan.of', { used: props.usage.containers.used, limit: props.usage.containers.limit }) }}
                    </dd>
                </div>

                <!-- Lagringsutrymmet: förbrukat av taket, i läsbara byten, med
                     stapel. Stapeln ritas bara mot ett tak som finns — `null`
                     är obegränsat och ska aldrig se ut som ett fullt utrymme
                     (Beslut 4). -->
                <div>
                    <dt class="text-slate-600">{{ t('plan.limits.storage_bytes') }}</dt>
                    <dd class="mt-1">
                        {{ props.usage.storage.limitBytes === null
                            ? t('plan.of_unlimited', { used: props.usage.storage.usedLabel })
                            : t('plan.of', { used: props.usage.storage.usedLabel, limit: props.usage.storage.limitLabel }) }}

                        <div
                            v-if="props.usage.storage.percent !== null"
                            class="mt-2 h-2 w-full max-w-sm overflow-hidden rounded bg-slate-200"
                            role="img"
                            :aria-label="t('plan.storage_bar', { percent: props.usage.storage.percent })"
                        >
                            <div
                                class="h-full bg-slate-700"
                                :style="{ width: `${props.usage.storage.percent}%` }"
                            ></div>
                        </div>
                    </dd>
                </div>

                <div>
                    <dt class="text-slate-600">{{ t('plan.limits.max_file_bytes') }}</dt>
                    <dd class="mt-1">{{ props.usage.maxFile.limitLabel ?? t('plan.unlimited') }}</dd>
                </div>

                <div>
                    <dt class="text-slate-600">{{ t('plan.limits.shared_users_per_container') }}</dt>
                    <dd class="mt-1">
                        {{ props.usage.sharedUsersPerContainer.limit === null
                            ? t('plan.unlimited')
                            : t('plan.per_container', { limit: props.usage.sharedUsersPerContainer.limit }) }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="mt-8">
            <h2 class="text-lg font-semibold">{{ t('plan.features_heading') }}</h2>

            <dl class="mt-4 flex flex-col gap-2 text-sm">
                <div
                    v-for="feature in props.features"
                    :key="feature.key"
                    class="flex items-baseline justify-between gap-4"
                >
                    <dt class="text-slate-600">{{ t(`plan.features.${feature.key}`) }}</dt>
                    <dd>{{ feature.included ? t('plan.included') : t('plan.not_included') }}</dd>
                </div>
            </dl>
        </section>

        <!-- Nedgraderingen förklaras INNAN den sker (Beslut 6). Stegen är
             dokumentets fem, och det viktigaste står med lika tydligt: items
             raderas aldrig, kostnadsrader raderas aldrig. Den som förstår det
             innan hon hamnar i läget blir kvar som kund. -->
        <section class="mt-8 rounded border border-slate-200 bg-white p-4 md:p-6">
            <h2 class="text-lg font-semibold">{{ t('plan.downgrade.heading') }}</h2>
            <p class="mt-2 text-sm text-slate-700">{{ t('plan.downgrade.intro') }}</p>

            <ol class="mt-4 flex list-decimal flex-col gap-2 pl-5 text-sm text-slate-700">
                <li>{{ t('plan.downgrade.steps.freeze') }}</li>
                <li>{{ t('plan.downgrade.steps.choose') }}</li>
                <li>{{ t('plan.downgrade.steps.grace') }}</li>
                <li>{{ t('plan.downgrade.steps.purge') }}</li>
                <li>{{ t('plan.downgrade.steps.restore') }}</li>
            </ol>

            <div class="mt-4 flex flex-col gap-1 text-sm text-slate-900">
                <p>{{ t('plan.downgrade.kept.items') }}</p>
                <p>{{ t('plan.downgrade.kept.costs') }}</p>
            </div>

            <!-- Förhandsvisningen är konkret (Beslut 7): hur mycket över, och
                 hur många bilagor som skulle gå nyast först. Under gränsen
                 står bara att allt ryms — inga siffror om radering. Länken går
                 till 66b:s städningsyta, där användaren väljer i stället. -->
            <div
                v-if="props.downgrade"
                class="mt-6 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
            >
                <p>{{ t('plan.downgrade.preview.over', {
                    over: props.downgrade.overLabel,
                    free: props.downgrade.freeStorageLabel,
                }) }}</p>
                <p class="mt-1">
                    {{ props.downgrade.attachmentsToRemove === 1
                        ? t('plan.downgrade.preview.remove_one')
                        : t('plan.downgrade.preview.remove_many', { count: props.downgrade.attachmentsToRemove }) }}
                </p>

                <a href="/settings/storage" class="mt-3 inline-block underline">
                    {{ t('plan.downgrade.preview.cleanup_link') }}
                </a>
            </div>

            <p v-else class="mt-6 text-sm text-slate-700">{{ t('plan.downgrade.preview.fits') }}</p>
        </section>
    </SettingsLayout>
</template>
