<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import NotificationPanel from './NotificationPanel.vue';
import UiIcon from './UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Klockan i toppraden, se M60 · issue 385 och [[ADR-0077 Notislistan i
 * appen]] § 2, 3 och 7. Den ersätter issue 127:s klocka, som läste
 * `notification` direkt och mätte mot `notifications_read_at`; den här läser
 * notislistan i appen och får sitt antal ur den delade proppen
 * `notifications.unread` (App\Http\Middleware\HandleInertiaRequests), så
 * siffran följer med varje sida utan ett extra anrop.
 *
 * **Två former, en klocka.** `variant` skiljer sidhuvudets klocka från fliken
 * i bottenraden, precis som förut: sidhuvudet är `hidden md:block` och
 * flikraden är `md:hidden`, så formen följer brytpunkten. Över `md:` är
 * klockan en knapp som öppnar panelen; under `md:` är den en länk till
 * `/notifications`.
 *
 * **Panelen hämtar först när den öppnas.** NotificationPanel gör anropet mot
 * `GET /notifications/panel`, och en stängd klocka kostar ingenting utöver
 * den delade siffran. Panelen monteras bara medan den är öppen.
 *
 * **Klockan äger stängningen och fokus.** Escape, ett klick utanför och ett
 * klick på klockan stänger panelen, och fokus går tillbaka till klockan —
 * den är det element som öppnade ytan. Hanteraren sitter på rotens `@keydown`
 * (inte `@keydown.esc`, som GenomgangTest förbjuder) och lyssnaren för klick
 * utanför sätts vid monteringen, samma mönster som
 * resources/js/components/AttachmentRowActions.vue.
 *
 * **Läsningen postas av klockan.** Panelen skickar de olästa ULID:n den
 * visade, och klockan postar dem till `POST /notifications/read` (§ 3) och
 * hämtar om `notifications` med en partiell omladdning, så att märket följer
 * med. Panelen behåller sitt eget svar, så markeringen syns först nästa gång
 * listan öppnas — den som just öppnade panelen ska hinna se vilka rader som
 * var nya.
 *
 * **Siffran döljs vid noll och visar 9+ över nio.** En nolla i en bricka är
 * en siffra som säger "ingenting"; den tomma ytan säger det redan. Namnet för
 * en skärmläsare säger antalet, till exempel *Notifications, 3 unread*.
 */
const props = defineProps({
    /*
     * `header` (förval) är sidhuvudets klocka med panelen; `tab` är fliken i
     * bottenraden, som är en länk till sidan.
     */
    variant: { type: String, default: 'header' },
});

const isTab = computed(() => props.variant === 'tab');

/*
 * Panelen har ett id per form: båda klockorna sitter i DOM:en samtidigt —
 * sidhuvudet döljs under `md:` med CSS och inte med `v-if` — och två element
 * med samma id hade gett `aria-controls` två mål.
 */
const panelId = computed(() => (isTab.value ? 'notification-panel-tab' : 'notification-panel'));

const { t } = useTranslations();
const page = usePage();

const unread = computed(() => page.props.notifications?.unread ?? 0);

const badge = computed(() => (unread.value > 9 ? '9+' : String(unread.value)));

const label = computed(() => (unread.value > 0
    ? t('notifications.list.bell_unread', { count: unread.value })
    : t('inbox.label')));

const isOpen = ref(false);
const marking = ref(false);
const root = ref(null);
const bellButton = ref(null);

function toggle() {
    if (isOpen.value) {
        close();

        return;
    }

    isOpen.value = true;
}

function close() {
    isOpen.value = false;
    bellButton.value?.focus();
}

function onKeydown(event) {
    if (event.key !== 'Escape' || ! isOpen.value) {
        return;
    }

    close();
    event.preventDefault();
    event.stopPropagation();
}

function onDocumentClick(event) {
    if (isOpen.value && root.value && ! root.value.contains(event.target)) {
        isOpen.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));

/* Panelen stängs vid navigering, samma skäl och samma klocka som menyerna. */
watch(() => page.url, () => {
    isOpen.value = false;
});

/*
 * De visade raderna markeras som lästa. `marking` stänger klockan medan
 * skrivningen är på väg (GenomgangTest), och den partiella omladdningen
 * hämtar bara märket — listan på /notifications behåller sina rader.
 */
function markShown(ulids) {
    if (ulids.length === 0) {
        return;
    }

    router.post('/notifications/read', { ulids }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { marking.value = true; },
        onFinish: () => { marking.value = false; },
        onSuccess: () => router.reload({ only: ['notifications'] }),
    });
}
</script>

<template>
    <div ref="root" class="relative" @keydown="onKeydown">
        <!--
            Sidhuvudets klocka: en knapp med ikonen `notifications` (Lucides
            `bell`, se tests/Feature/Frontend/IkonerTest.php). Träffytan är
            44 px, och brickan hänger på ikonens hörn.
        -->
        <button
            v-if="! isTab"
            ref="bellButton"
            type="button"
            class="relative inline-flex min-h-11 min-w-11 items-center justify-center rounded border border-slate-300 text-slate-700 disabled:opacity-60"
            :aria-label="label"
            :aria-controls="panelId"
            :aria-expanded="isOpen"
            :disabled="marking"
            @click="toggle"
        >
            <span class="relative inline-flex">
                <UiIcon name="notifications" :size="20" class="h-5 w-5" />

                <span
                    v-if="unread > 0"
                    class="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-pill bg-danger px-1 text-meta font-semibold text-ink-on-danger"
                >
                    {{ badge }}
                </span>
            </span>
        </button>

        <!--
            Under `md:` är klockan en länk till sidan (ADR-0077 § 2): panelen
            är för bred för en telefon, och historiken finns på
            `/notifications`. Träffytan och flikformen är desamma som förut.
        -->
        <Link
            v-else
            href="/notifications"
            prefetch
            class="flex min-h-11 min-w-11 w-full flex-col items-center justify-center gap-0.5 px-2 py-1 text-meta text-ink-muted"
            :aria-label="label"
        >
            <span class="relative inline-flex">
                <UiIcon name="notifications" :size="20" class="h-5 w-5" />

                <span
                    v-if="unread > 0"
                    class="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-pill bg-danger px-1 text-meta font-semibold text-ink-on-danger"
                >
                    {{ badge }}
                </span>
            </span>

            <span>{{ t('notifications.list.bell') }}</span>
        </Link>

        <NotificationPanel
            v-if="! isTab && isOpen"
            :panel-id="panelId"
            @shown="markShown"
        />
    </div>
</template>
