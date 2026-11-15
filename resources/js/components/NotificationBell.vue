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
 *
 * **Inbjudningarna räknas i märket och ritas i panelen** (granskningen av
 * #1315 · [[M20 Kontot]] § 131 och [[M22 Redo för testare]]). En inbjudan är
 * ingen notis — den skrivs aldrig till `notification` och kommer därför inte
 * med i `in_app` — men den väntar på ett svar och ska synas på klockan.
 * `pendingInvitations` är en OPTIONAL propp (Inertia::optional), så en vanlig
 * sidladdning bär den inte. Klockan äger därför en lokal kopia som överlever
 * att proppen försvinner vid en full omladdning, och hämtar proppen med en
 * partiell omladdning när den saknas: vid monteringen och vid varje
 * navigering. Panelen får kopian som propp, så att den och märket alltid
 * räknar samma rader.
 *
 * Summan är `notifications.unread + pendingInvitations.length`, och
 * inbjudningarna skickas aldrig till `POST /notifications/read` — de
 * försvinner först när de besvarats eller gått ut.
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

/*
 * Inbjudningarna. Proppen är optional, så den lokala kopian är det som
 * märket och panelen läser: en full omladdning tappar proppen, och kopian
 * behåller då raderna till nästa partiella svar. `loadInvitations()` hämtar
 * den när den saknas.
 */
const pendingInvitations = ref(page.props.pendingInvitations ?? []);

watch(
    () => page.props.pendingInvitations,
    (value) => {
        if (value !== undefined) {
            pendingInvitations.value = value;
        }
    },
);

const unread = computed(() => (page.props.notifications?.unread ?? 0) + pendingInvitations.value.length);

const badge = computed(() => (unread.value > 9 ? '9+' : String(unread.value)));

const label = computed(() => (unread.value > 0
    ? t('notifications.list.bell_unread', { count: unread.value })
    : t('inbox.label')));

const isOpen = ref(false);
const marking = ref(false);
const root = ref(null);
const bellButton = ref(null);

function toggle() {
    /*
     * Klicket ignoreras medan läsningen postas. Knappen är `aria-disabled` och
     * inte `disabled`: en inaktiverad knapp tappar fokus till `body`, och då
     * når Escape inte rotens lyssnare och fokus går inte tillbaka till klockan
     * (granskningen av #1315). Skrivningen är dessutom idempotent — actionen
     * rör bara olästa rader — så ett dubbeltryck hade varit ofarligt.
     */
    if (marking.value) {
        return;
    }

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

/*
 * Inbjudningarna är en optional propp och följer därför inte med en vanlig
 * sidladdning. Klockan hämtar den med en partiell omladdning när den saknas —
 * vid monteringen och efter en navigering — så att märket räknar dem på varje
 * sida. Flikformen (under `md:`) äger ingen panel och hämtar ingenting: den
 * länkar till sidan, och sidhuvudets klocka sitter i DOM:en även där.
 */
function loadInvitations() {
    if (isTab.value || page.props.pendingInvitations !== undefined) {
        return;
    }

    router.reload({ only: ['pendingInvitations'] });
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    loadInvitations();
});
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));

/* Panelen stängs vid navigering, samma skäl och samma klocka som menyerna. */
watch(() => page.url, () => {
    isOpen.value = false;
    loadInvitations();
});

/*
 * De visade raderna markeras som lästa. `marking` uttalar vänteläget på
 * klockan med `aria-disabled` och `:pending` och får `toggle` att ignorera
 * klicket medan skrivningen är på väg — knappen behåller fokus, till skillnad
 * från en `disabled` knapp, som tappar det till `body` (granskningen av
 * #1315). Den partiella omladdningen hämtar bara märket — listan på
 * /notifications behåller sina rader.
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

            Vänteläget står i `aria-disabled` och i `:pending`, men aldrig i
            `disabled`: en inaktiverad knapp lämnar fokus till `body`, och då
            når Escape inte rotens lyssnare och fokus går inte tillbaka till
            klockan (§ 1). Stängningen gör `toggle`, som ignorerar klicket
            medan flaggan är satt.
        -->
        <button
            v-if="! isTab"
            ref="bellButton"
            type="button"
            class="relative inline-flex min-h-11 min-w-11 items-center justify-center rounded border border-slate-300 text-slate-700 aria-disabled:opacity-60"
            :aria-label="label"
            :aria-controls="panelId"
            :aria-expanded="isOpen"
            :aria-disabled="marking"
            :pending="marking"
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
            :pending-invitations="pendingInvitations"
            @shown="markShown"
        />
    </div>
</template>
