<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import UiListRow from './UiListRow.vue';
import { notificationMessage } from './notificationPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useRelativeDate } from '../composables/useRelativeDate.js';

/*
 * Notisklockan i sidhuvudet, se issue 127 och [[M19 Dashboarden]] § 127.
 *
 * **Klockan är ingen kanal** ([[ADR-0010 Notisarkitektur]] § Beslut). Den
 * läser `notification` som tabellen redan är: raderna med användarens
 * `user_id`, de tjugo senaste, nyast först. Den skapar ingen
 * `notification_delivery`-rad, ritar ingen kanal och påverkas inte av
 * preferenser eller tysta timmar — den som öppnar klockan har redan fått sina
 * mejl, och det här är kvittot på att hon sett dem.
 *
 * **Siffran och listan kommer olika vägar, och det är hela konstruktionen.**
 * `unreadNotificationCount` är en delad prop som följer med varje sida och
 * kostar EN fråga (App\Http\Middleware\HandleInertiaRequests); `notifications`
 * är en OPTIONAL prop, och en vanlig sidladdning rör den aldrig. Listan
 * hämtas först när klockan öppnas, med `router.reload({ only: [...] })` — en
 * partiell omladdning av just den nyckeln, som är precis vad en optional prop
 * svarar på. Klockan är därför gratis på varje sida man inte öppnar den på.
 *
 * **Att öppna klockan ÄR att läsa den.** Första öppningen postar till
 * `/notifications/read`, som sätter användarens `notifications_read_at` till
 * nu — siffran nollställs på servern och inte i den här komponenten. Posten
 * följs av en vanlig omladdning (svaret är en omdirigering, mönstret från
 * issue 51 § Beslut 5), och eftersom en sådan renderar om sidan fullt tappar
 * den den optionala listan: därför hämtas den en gång till i `onSuccess`.
 * `preserveState` håller panelen öppen under båda anropen.
 *
 * **Frågan ställs en gång per sidladdning.** `loaded` är det enda tillståndet
 * vid sidan av `isOpen`, och den finns för att en stängd och åter öppnad
 * panel inte ska fråga servern igen om samma lista. En ny navigering ger en
 * ny komponent och ett nytt svar.
 *
 * **Raden är `UiListRow`** (issue 99) och meningen byggs i
 * resources/js/components/notificationPresentation.js ur radens `payload` —
 * ingen sträng står här, och ingen färdig text kommer från servern
 * ([[Notiser]] § notification: payloaden bär data, aldrig text).
 *
 * **Länken kommer färdig i proppen, eller inte alls.** Adressen till ett item
 * bär två ULID:n som bara servern känner (issue 51 § Beslut 7), och
 * `url` är `null` för de typer som inte har någon sida — en kvotvarning och
 * en inaktivitetsvarning gäller kontot, och kontosidan finns inte i M19. En
 * rad utan mål är text och inte en död länk.
 *
 * **Inbjudningarna står överst och kommer ur en annan tabell** (issue 131).
 * `pendingInvitations` är den andra optionala proppen, läst ur `invitation`
 * och inte ur `notification` — ingen notisrad skrivs för en inbjudan. Raden
 * säger samma sak som listan på `/invitations` och länkar dit, för det är där
 * hon svarar; klockan visar den bara. Ordningen är inte kosmetisk: en
 * inbjudan väntar på ett svar och en notis är ett kvitto på något som redan
 * hänt, och den som öppnar klockan ska mötas av det först som kräver något av
 * henne.
 *
 * **Siffran räknar båda** (HandleInertiaRequests), men öppningen nollställer
 * bara notiserna. En inbjudan är obesvarad till dess att den besvarats, så
 * brickan faller med notiserna och står kvar med inbjudningarna — se
 * App\Http\Controllers\NotificationInboxController.
 */
const { t } = useTranslations();
const { dueDate, eventDate } = useRelativeDate();
const page = usePage();

const isOpen = ref(false);
const loaded = ref(false);

/*
 * Vänteläget runt skrivningen (GenomgangTest): en knapp som ser likadan ut
 * medan svaret är på väg är en användare som trycker igen, och två POST:ar mot
 * samma tidsstämpel är två onödiga anrop. Flaggan stängs i `onFinish` och
 * alltså även när svaret blev ett fel.
 */
const busy = ref(false);

const unread = computed(() => page.props.unreadNotificationCount ?? 0);

/*
 * `undefined` och `[]` betyder olika saker: den första att listan inte är
 * hämtad än, den andra att den är hämtad och tom. Panelen ritar därför
 * varken listan eller tomtillståndet förrän proppen finns — "inget nytt" om
 * en lista som är på väg hade varit ett svar komponenten inte har.
 *
 * De två listorna är två proppar och hämtas i samma partiella omladdning
 * (issue 131). `loaded` nedan är därför SANN först när båda finns: en panel
 * som visade inbjudningarna medan notiserna var på väg hade sagt "inget nytt"
 * om en lista den ännu inte fått.
 */
const rows = computed(() => page.props.notifications);

const invitations = computed(() => page.props.pendingInvitations);

/* `loaded` ovan är "frågan är ställd"; den här är "svaret är här". */
const bothLoaded = computed(() => rows.value !== undefined && invitations.value !== undefined);

const empty = computed(() => bothLoaded.value
    && rows.value.length === 0
    && invitations.value.length === 0);

/*
 * Meningen: typen ger nyckeln, payloaden ger värdena, och datumet skrivs av
 * datumregeln (issue 104) och inte av den här filen.
 */
function message(row) {
    return notificationMessage(t, row, (value) => dueDate(value).text);
}

function toggle() {
    isOpen.value = !isOpen.value;

    if (!isOpen.value || loaded.value) {
        return;
    }

    loaded.value = true;

    if (unread.value === 0) {
        router.reload({ only: ['notifications', 'pendingInvitations'] });

        return;
    }

    router.post('/notifications/read', {}, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { busy.value = true; },
        onFinish: () => { busy.value = false; },
        // Svaret är en omdirigering och renderar om sidan fullt (mönstret
        // från issue 51 § Beslut 5). Den renderingen bär inte de optionala
        // listorna, så de hämtas en gång till — annars hade panelen tömts i
        // samma stund som siffran nollställdes. Inbjudningarna hade fallit
        // bort med dem, fast de inte nollställs av skrivningen.
        onSuccess: () => router.reload({ only: ['notifications', 'pendingInvitations'] }),
    });
}
</script>

<template>
    <div class="relative">
        <!--
            Knappen bär ingen text: etiketten är `inbox.label`, och
            siffran ritas bara när det finns något att räkna. En nolla i en
            bricka är en siffra som säger "ingenting", och det säger den
            tomma ytan redan.
        -->
        <button
            type="button"
            class="relative inline-flex min-h-11 min-w-11 items-center justify-center rounded border border-slate-300 text-slate-700 disabled:opacity-60"
            :aria-label="t('inbox.label')"
            aria-controls="notification-list"
            :aria-expanded="isOpen"
            :disabled="busy"
            @click="toggle"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
                class="h-5 w-5"
                aria-hidden="true"
            >
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>

            <span
                v-if="unread > 0"
                class="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-pill bg-danger px-1 text-meta font-semibold text-ink-on-danger"
            >
                {{ unread }}
            </span>
        </button>

        <section
            v-if="isOpen"
            id="notification-list"
            class="absolute right-0 z-10 mt-1 w-80 max-w-[calc(100vw-2rem)] rounded-card border border-border bg-surface p-4 shadow-lg"
        >
            <h2 class="text-title font-semibold text-ink">{{ t('inbox.label') }}</h2>

            <p v-if="empty" class="mt-2 text-slate-700">
                {{ t('inbox.empty') }}
            </p>

            <ul v-else-if="bothLoaded" class="mt-2 flex flex-col divide-y divide-slate-200">
                <!--
                    Inbjudningarna först (issue 131): de väntar på ett svar,
                    notiserna är kvitton på något som redan hänt. Raden bär
                    ingen tid — det som betyder något för en inbjudan är att
                    den väntar, och hur länge den gör det står på /invitations.
                -->
                <UiListRow v-for="invitation in invitations" :key="invitation.ulid">
                    <template #title>
                        <Link :href="invitation.url" class="flex min-h-11 items-center hover:underline">
                            {{ t('inbox.invitation.received', { inviter: invitation.inviter, container: invitation.container }) }}
                        </Link>
                    </template>
                </UiListRow>

                <UiListRow v-for="row in rows" :key="row.ulid">
                    <template #title>
                        <Link
                            v-if="row.url"
                            :href="row.url"
                            class="flex min-h-11 items-center hover:underline"
                        >
                            {{ message(row) }}
                        </Link>

                        <span v-else class="flex min-h-11 items-center">{{ message(row) }}</span>
                    </template>

                    <template #meta>
                        <time :datetime="row.created_at">{{ eventDate(row.created_at).text }}</time>
                    </template>
                </UiListRow>
            </ul>
        </section>
    </div>
</template>
