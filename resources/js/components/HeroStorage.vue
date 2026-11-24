<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';
import { formatByteSize } from './attachmentPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Hjältens lagringskort, se issue 476 · [[ADR-0090 Dashboardens hjälte]]
 * § Tillägg 2026-11-24 · [[ADR-0081 All lagrad data räknas mot kvoten]].
 *
 * **Kortet svarar på frågan *hur mycket utrymme använder jag?*** — förbrukat
 * av taket, en vågrät stapel och *Files* och *Text* var för sig. Vägen vidare
 * — *vad tar utrymmet, och vad kan jag göra åt det?* — är `/settings/storage`,
 * och hela kortet är en länk dit. Kortet ersätter ingenting av den sidan: den
 * svarar fortfarande på den andra frågan.
 *
 * **Talen är serverns** (`storageQuota`, App\Http\Middleware\
 * HandleInertiaRequests). `usedBytes` är `fileBytes + textBytes` ur
 * `usage_counter`, och `barPercent` kommer färdigt med två decimaler — 17 MB
 * av 10 GB är 0,17 och avrundas inte till 0. Klienten räknar ingenting: den
 * formaterar bytena med `formatByteSize()` och ritar bredden som den fick
 * den. Ingen ny summering och ingen ny begäran.
 *
 * **Stapeln har ingen minsta bredd.** En liten andel får synas knappt eller
 * inte alls, och siffrorna bär beskedet. Den är `aria-hidden`, för
 * huvudraden säger samma sak en gång.
 *
 * **Färgen går mot varning vid 80 % och mot fel vid 100 %**, med samma toner
 * som `StorageQuota` — den läser `percent` och inte `barPercent`, så tonen är
 * den samma som i toppradens modul. Ett obegränsat tak (`limitBytes` är
 * `null`) ger `barPercent: null`, och då ritas ingen stapel alls.
 *
 * **Hela kortet är en länk**, inte bara stapeln, med ett eget tillgängligt
 * namn ur `quota.card.open` och en synlig fokusring. Pilen är diskret och
 * `aria-hidden` — `UiIcon` sätter det själv — och hover-markeringen är samma
 * skugga som brickorna bär.
 *
 * **Ytan är hjältens glas** i formen `on-image`
 * ([[ADR-0057 Ikoner och typsnitt]] § 6), som brickorna och `HeroCosts`.
 * Utan `tone` ritas kortet på den ljusa ytan, där dashboarden ritar det under
 * bandet på en telefon.
 */
const props = defineProps({
    /* `on-image` i hjälten, `null` på ytan — samma former som `UiStat`. */
    tone: { type: String, default: null },
});

const { t } = useTranslations();
const page = usePage();

/* Den delade proppen — kontokontexten utanför en container (issue 476). */
const quota = computed(() => page.props.storageQuota ?? null);

const used = computed(() => (quota.value === null ? '' : formatByteSize(quota.value.usedBytes) ?? ''));

const limit = computed(() => {
    if (quota.value === null || quota.value.limitBytes === null) {
        return null;
    }

    return formatByteSize(quota.value.limitBytes);
});

const label = computed(() => (limit.value === null
    ? t('quota.used_unlimited', { used: used.value })
    : t('quota.used_of', { used: used.value, limit: limit.value })));

/* Samma tre toner som `StorageQuota`, lästa ur den hela procentsatsen. */
const barTone = computed(() => {
    const percent = quota.value?.percent ?? 0;

    if (percent >= 100) {
        return 'bg-danger';
    }

    return percent >= 80 ? 'bg-warning' : 'bg-accent';
});

const TONES = {
    surface: {
        box: 'border-border bg-surface',
        heading: 'text-ink',
        body: 'text-ink',
        subtle: 'text-ink-subtle',
        track: 'bg-surface-sunken',
    },
    'on-image': {
        box: 'border-glass-border bg-glass',
        heading: 'text-ink-on-accent',
        body: 'text-ink-on-accent',
        subtle: 'text-ink-on-accent',
        track: 'bg-ink-on-accent/25',
    },
};

const toneClasses = computed(() => TONES[props.tone] ?? TONES.surface);
</script>

<template>
    <Link
        v-if="quota"
        href="/settings/storage"
        class="flex min-w-40 flex-col gap-1 rounded-card border px-4 py-3 outline-none hover:shadow-sm focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
        :class="toneClasses.box"
        :aria-label="t('quota.card.open')"
    >
        <span class="flex items-center justify-between gap-4">
            <span class="text-title font-semibold" :class="toneClasses.heading">
                {{ t('quota.card.heading') }}
            </span>

            <UiIcon name="arrow-right" :size="16" class="shrink-0" :class="toneClasses.subtle" />
        </span>

        <span class="text-body font-semibold" :class="toneClasses.body">{{ label }}</span>

        <!--
            Bredden är den faktiska andelen och inget golv: en liten andel
            ritas knappt eller inte alls, och raden ovanför bär beskedet.
            `aria-hidden` för att `label` säger samma sak.
        -->
        <span
            v-if="quota.barPercent !== null"
            class="h-2 w-full overflow-hidden rounded-pill"
            :class="toneClasses.track"
            aria-hidden="true"
        >
            <span
                class="block h-2 rounded-pill"
                :class="barTone"
                :style="{ width: `${quota.barPercent}%` }"
            ></span>
        </span>

        <!-- *Files* och *Text* var för sig, formaterade med samma funktion
             som kvotmeningen: ingen egen formatering och ingen summa. -->
        <span class="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-meta">
            <span class="flex items-baseline gap-1">
                <span :class="toneClasses.subtle">{{ t('quota.files') }}</span>
                <span class="font-semibold" :class="toneClasses.body">{{ formatByteSize(quota.fileBytes) }}</span>
            </span>

            <span class="flex items-baseline gap-1">
                <span :class="toneClasses.subtle">{{ t('quota.text') }}</span>
                <span class="font-semibold" :class="toneClasses.body">{{ formatByteSize(quota.textBytes) }}</span>
            </span>
        </span>
    </Link>
</template>
