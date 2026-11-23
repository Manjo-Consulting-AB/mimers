<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';
import { useRelativeDate } from '../composables/useRelativeDate.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En rad i notislistan i appen, se M60 · issue 385 och [[ADR-0077
 * Notislistan i appen]] § 2 och 3. Panelen och sidan ritar samma rad: en
 * ikon per typ, meningen, den relativa tiden och markeringen för en oläst
 * rad.
 *
 * **Meningen byggs i klienten ur typen.** Servern skickar `type` och
 * `payload` — data, aldrig text ([[Notiser]] § notification). Nyckeln är
 * `notifications.list.type.<type>`, och punkten i typnamnet är samma punkt
 * som skiljer katalogens led: `transfer.requested` slås upp som
 * `notifications.list.type.transfer.requested`. En typ utan mening får
 * `notifications.list.fallback` och aldrig sin egen nyckel — translate.js
 * returnerar nyckeln när uppslaget misslyckas, och en nyckel i en rad är ett
 * fel ingen upptäcker.
 *
 * **Skälet väljer meningen för `container.deleted`.** Payloaden bär
 * `reason` (`owner_deleted` eller `account_inactive`), samma två skäl som
 * mejlet i lang/en/notiser.php skiljer på. Ett okänt skäl faller tillbaka på
 * den neutrala reservtexten, samma svar som en okänd typ får: raden får
 * aldrig påstå att ägaren raderade containern när payloaden inte säger det
 * (granskningen av #1315).
 *
 * **Länken finns bara när målet finns.** `target` byggs på servern
 * (App\Support\Notification\NotificationTarget) och är `null` för
 * `container.deleted` och de andra typerna utan sida. En rad utan mål är ren
 * text — ingen död länk och ingen hover — medan en rad med mål är en enda
 * klickbar yta på minst 44 px.
 *
 * **Tiden skrivs av datumregeln** (resources/js/composables/useRelativeDate.js,
 * issue 104) och aldrig av den här filen: `created_at` är ett ögonblick, och
 * `eventDate()` äger orden.
 *
 * **Markeringen är radens egen.** `read_at === null` är oläst; en liten prick
 * och en tyngre text säger det. Serverns svar ligger kvar i raden efter att
 * den markerats som läst (§ 3), så markeringen syns till nästa gång listan
 * öppnas.
 */
const props = defineProps({
    /*
     * En rad ur InAppNotificationResource: `ulid`, `type`, `payload`,
     * `target`, `created_at` och `read_at`.
     */
    notification: { type: Object, required: true },
});

const { t } = useTranslations();
const { eventDate, dueDate } = useRelativeDate();

/*
 * Ikonen per typ. De två uppgiftstyperna delar en ikon, och en typ som inte
 * står i listan får klockan — samma neutrala svar som meningen får.
 */
const ICONS = {
    'task.due': 'task',
    'task.overdue': 'task',
    'loan.due': 'loan',
    'quota.warning': 'warning',
    'transfer.requested': 'share',
    'account.inactive': 'warning',
    'container.deleted': 'trash',
    'agent.deleted': 'trash',
    'agent.access_lost': 'warning',
    'account.downgrade_warning': 'warning',
    'binder.ready': 'export',
    'access.left': 'share',
};

const icon = computed(() => ICONS[props.notification.type] ?? 'notifications');

const unread = computed(() => props.notification.read_at === null);

const time = computed(() => eventDate(props.notification.created_at).text);

/*
 * Skälen som `container.deleted` har en mening för. Payloaden bär
 * `reason`, och ett värde utanför listan får reservtexten — inte
 * `owner_deleted`, som hade påstått något om containern som payloaden inte
 * säger.
 */
const DELETED_REASONS = ['owner_deleted', 'account_inactive'];

/*
 * Meningen. `container.deleted` väljer skäl först, eftersom nyckeln då har en
 * nivå till. Övriga typer — och ett okänt skäl — slås upp direkt på
 * typnamnet, och ett uppslag som inte gav en mening faller tillbaka på
 * reservtexten. Jämförelsen med nyckeln är det som skiljer ett riktigt svar
 * från translate.js svar på en saknad nyckel.
 */
const message = computed(() => {
    const row = props.notification;
    const payload = row.payload ?? {};

    if (row.type === 'container.deleted' && DELETED_REASONS.includes(payload.reason)) {
        return t(`notifications.list.type.container.deleted.${payload.reason}`, {
            container: payload.container,
        });
    }

    /*
     * Issue 406 · Riktning 8 och 14: en rad per tillfälle, med antalet. En
     * raderad container bär sitt innehåll med sig till papperskorgen, så
     * raden säger också hur många items den innehöll — en mening per container,
     * sammanfogade efter huvudsatsen.
     */
    if (row.type === 'agent.deleted') {
        const base = t(payload.total === 1
            ? 'notifications.list.type.agent.deleted_one'
            : 'notifications.list.type.agent.deleted', {
            agent: payload.agent,
            total: payload.total,
        });

        const containers = (payload.containers ?? []).map((container) => t(
            'notifications.list.type.agent.container',
            { container: container.name, items: container.items },
        ));

        return [base, ...containers].join(' ');
    }

    /*
     * Issue 1457 · nedgraderingsvarningen. Datumet är fristens slut och
     * skrivs av datumregeln (useRelativeDate): relativt inom 30 dagar,
     * absolut bortom. `downgrade_no_date` används för ett konto utan frist.
     *
     * MCP-meningen läggs bara till när kontot har en giltig agentanslutning
     * — samma `has_agents` som mejlet väljer sin variant ur
     * ([[ADR-0087 MCP-server för agenter]] Riktning 11).
     */
    if (row.type === 'account.downgrade_warning') {
        const deadline = payload.grace_until ? dueDate(payload.grace_until).text : null;
        const base = t(
            deadline === null
                ? 'notifications.list.type.account.downgrade_no_date'
                : 'notifications.list.type.account.downgrade_warning',
            { date: deadline },
        );
        const mcp = payload.has_agents
            ? t('notifications.list.type.account.downgrade_mcp')
            : '';

        return [base, mcp].filter(Boolean).join(' ');
    }

    /*
     * Issue 1518: pärmen är klar. En itempärm nämner itemet, en containerpärm
     * containern.
     */
    if (row.type === 'binder.ready') {
        return payload.item
            ? t('notifications.list.type.binder.ready_item', { item: payload.item })
            : t('notifications.list.type.binder.ready', { container: payload.container });
    }

    const key = `notifications.list.type.${row.type}`;
    const text = t(key, {
        container: payload.container,
        percent: payload.percent,
        owner: payload.owner,
        person: payload.person,
    });

    return text === key ? t('notifications.list.fallback') : text;
});
</script>

<template>
    <li class="flex items-start gap-3 px-1 py-1" :class="unread ? 'font-medium' : ''">
        <span
            class="mt-3.5 size-2 shrink-0 rounded-pill"
            :class="unread ? 'bg-accent' : 'bg-transparent'"
            aria-hidden="true"
        ></span>

        <!--
            En rad med mål är en enda länk. Klasserna bär träffytan: hela
            raden är klickbar, och 44 px är måttet ur issue 68a § Beslut 3.
        -->
        <Link
            v-if="notification.target"
            :href="notification.target"
            class="flex min-h-11 min-w-0 flex-1 items-center gap-3 text-ink outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
        >
            <UiIcon :name="icon" :size="20" class="size-5 shrink-0" />

            <span class="min-w-0 flex-1">{{ message }}</span>

            <time :datetime="notification.created_at" class="shrink-0 text-meta font-normal text-ink-muted">
                {{ time }}
            </time>
        </Link>

        <!--
            Utan mål är raden ren text: ingen länk, ingen hover och ingenting
            att trycka på. Den ritas ändå med samma höjd som en länkad rad, så
            listan inte hoppar mellan raderna.
        -->
        <div v-else class="flex min-h-11 min-w-0 flex-1 items-center gap-3 text-ink">
            <UiIcon :name="icon" :size="20" class="size-5 shrink-0" />

            <span class="min-w-0 flex-1">{{ message }}</span>

            <time :datetime="notification.created_at" class="shrink-0 text-meta font-normal text-ink-muted">
                {{ time }}
            </time>
        </div>
    </li>
</template>
