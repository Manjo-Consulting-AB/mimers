<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import StorageCleanupSection from '../../components/StorageCleanupSection.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { formatByteSize } from '../../components/attachmentPresentation.js';

defineOptions({ layout: [AppLayout, SettingsLayout] });

/*
 * Lagringsytan, se issue 66b § Beslut 1–9 och [[Planer och kvoter]]
 * § Nedgradering.
 *
 * **Kontot väljs i sidan**, som på plansidan (66a) och kontosidan (53c): en
 * sida som byter konto ska inte behöva byta URL, och en ULID som pekar på
 * någon annans konto blir 403 — inte en tom sida. Rensningen har kontot i
 * ruttens sökväg, av det skäl som
 * App\Http\Controllers\Settings\StorageController anger.
 *
 * **Kontots namn står alltid på sidan**, oavsett om väljaren ritas: listan och
 * siffrorna är kontots egna, och en sida som visar bilagor utan att säga för
 * vilket konto är en sida man läser fel. Väljaren ritas bara när det finns
 * något att välja mellan (samma val som Plan.vue och Webhooks.vue).
 *
 * **Förbrukningen på sidan är serverns tal** (Beslut 4 och 8): `usage` kommer
 * ur App\Actions\Plan\ReadPlanUsage — samma handling som plansidan läser — och
 * bytena är formaterade av servern. Klienten räknar bara på urvalet, och den
 * siffran står i urvalslistan.
 *
 * **Bekräftelsen på att en rensning hände bär serverns två tal** (Beslut 8):
 * `removed` är flashen ur rensningen och är `null` vid varje annan visning.
 * Meningen är inte `flash.status`, för den bär en kod och inga parametrar
 * (issue 51 § Beslut 5) — antalet borttagna och förbrukningen efteråt är två
 * tal, och båda är serverns.
 *
 * **Ingen sträng står i den här filen** (Beslut 9).
 */
const props = defineProps({
    /* Användarens konton, `{ulid, name}`, sorterade på namn. */
    accounts: { type: Array, required: true },

    /* Det valda kontot, `{ulid, name}`. */
    account: { type: Object, required: true },

    /*
     * Kontots levande bilagor, störst först, ur StorageEntryResource med
     * `inTrash` lagt bredvid.
     */
    attachments: { type: Array, required: true },

    /*
     * Lagringsutrymmet, `{usedBytes, limitBytes, usedLabel, limitLabel,
     * percent}`. `limitBytes` är `null` för ett tak som inte finns.
     */
    usage: { type: Object, required: true },

    /*
     * Kontots tak för versionshistoriken, `{max, limit}` (issue 411 ·
     * [[ADR-0088 Versionshistorik för items]] Riktning 1). `max` är det
     * sparade värdet och `limit` den övre gränsen ur serverns config — vyn
     * ritar valen ur serverns tal, aldrig ur en egen kopia.
     */
    versioning: { type: Object, required: true },

    /*
     * Rensningens sammanfattning, `{removed, storageLabel}`, eller null när
     * sidan visas av någon annan anledning än direkt efter en rensning.
     */
    removed: { type: Object, default: null },
});

const { t } = useTranslations();

/* Vänteläget på kontobytet: en GET mot samma sida är en ny sidvisning. */
const pending = ref(false);

/*
 * Kontobytet. En GET mot samma sida med `?account=` — servern faller tillbaka
 * på ett förval om ULID:n inte är användarens, och grinden prövas mot det
 * valda kontot.
 */
function selectAccount(event) {
    router.get('/settings/storage', { account: event.target.value }, {
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

/*
 * Versionshistorikens tak (issue 411 · [[ADR-0088 Versionshistorik för
 * items]] Riktning 1). Valet sparas DIREKT — en väljare, inte text, så ingen
 * *Save* (Tonys regel 2026-11-14, #1245). Servern svarar med sidan igen och
 * `versioning`-proppen bär det sparade värdet.
 */
const versioningPending = ref(false);
const versioningStatus = ref(null);
const versioningError = ref(null);

function saveVersioning(event) {
    versioningStatus.value = null;
    versioningError.value = null;

    router.patch('/settings/storage/versioning', {
        account: props.account.ulid,
        versioning_max_versions: Number(event.target.value),
    }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { versioningPending.value = true; },
        onFinish: () => { versioningPending.value = false; },
        onSuccess: () => { versioningStatus.value = 'saved'; },
        onError: (errors) => { versioningError.value = errors.versioning_max_versions ?? null; },
    });
}
</script>

<template>
    <Head :title="t('storage.title')" />

    <h1 class="text-2xl font-semibold">{{ t('storage.heading') }}</h1>
    <p class="mt-2 text-sm text-slate-700">{{ t('storage.intro') }}</p>
    <p v-if="props.usage.textBytes > 0" class="mt-1 text-sm text-slate-700">{{ t('storage.text_note') }}</p>

    <div v-if="props.accounts.length > 1" class="mt-6 flex flex-col gap-1">
        <label for="account" class="text-sm font-medium text-slate-800">{{ t('storage.account_label') }}</label>

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

    <!-- Vad rensningen gjorde, med serverns tal (Beslut 8). Rutan ritas
         bara direkt efter en rensning, och den säger papperskorgen och de
         30 dagarna — aldrig "raderat permanent". -->
    <section
        v-if="props.removed"
        role="status"
        class="mt-6 rounded border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900"
    >
        <p>
            {{ props.removed.removed === 1
                ? t('storage.result.one')
                : props.removed.removed > 1
                    ? t('storage.result.many', { count: props.removed.removed })
                    : t('storage.result.none') }}
        </p>
        <p class="mt-1">{{ t('storage.result.usage', { used: props.removed.storageLabel }) }}</p>
    </section>

    <section class="mt-8 rounded border border-slate-200 bg-white p-4 md:p-6">
        <h2 class="text-lg font-semibold">{{ t('storage.usage_heading') }}</h2>

        <dl class="mt-4 flex flex-col gap-1 text-sm">
            <div class="flex flex-col gap-1 md:flex-row md:gap-2">
                <dt class="shrink-0 text-slate-600 md:w-40">{{ t('storage.account_label') }}</dt>
                <dd>{{ props.account.name }}</dd>
            </div>
            <div class="flex flex-col gap-1 md:flex-row md:gap-2">
                <dt class="shrink-0 text-slate-600 md:w-40">{{ t('plan.limits.storage_bytes') }}</dt>
                <dd>
                    {{ props.usage.limitBytes === null
                        ? t('plan.of_unlimited', { used: props.usage.usedLabel })
                        : t('plan.of', { used: props.usage.usedLabel, limit: props.usage.limitLabel }) }}
                    <span v-if="props.usage.textBytes > 0" class="mt-1 block text-xs text-slate-600">
                        {{ t('plan.storage_split', { files: formatByteSize(props.usage.fileBytes), text: formatByteSize(props.usage.textBytes) }) }}
                    </span>
                </dd>
            </div>
        </dl>
    </section>

    <!--
        Versionshistorikens tak (issue 411 · [[ADR-0088 Versionshistorik för
        items]] Riktning 1). Ett tal 0–`limit`; 0 är av. Valet sparas direkt —
        en väljare, inte text, så ingen *Save* (Tonys regel 2026-11-14,
        #1245) — och meningen säger att versionerna räknas mot kontots lagring.
    -->
    <section class="mt-8 rounded border border-slate-200 bg-white p-4 md:p-6">
        <h2 class="text-lg font-semibold">{{ t('storage.versioning.heading') }}</h2>

        <p class="mt-2 text-sm text-slate-700">{{ t('storage.versioning.description') }}</p>

        <div class="mt-4 flex flex-col gap-1">
            <label for="storage-versioning" class="text-sm font-medium text-slate-800">
                {{ t('storage.versioning.label') }}
            </label>

            <select
                id="storage-versioning"
                :value="String(props.versioning.max)"
                :disabled="versioningPending"
                class="self-start rounded border border-slate-300 bg-white px-3 py-2"
                @change="saveVersioning"
            >
                <option v-for="value in props.versioning.limit + 1" :key="value" :value="String(value - 1)">
                    {{ value - 1 }}
                </option>
            </select>
        </div>

        <p v-if="versioningError" role="alert" class="mt-2 text-sm text-danger">{{ versioningError }}</p>

        <p v-else-if="versioningStatus" role="status" class="mt-2 text-sm text-ink-subtle">
            {{ t('storage.versioning.saved') }}
        </p>
    </section>

    <StorageCleanupSection
        :account-ulid="props.account.ulid"
        :attachments="props.attachments"
        :used-bytes="props.usage.usedBytes"
    />
</template>
