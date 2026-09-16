<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import TransferForm from '../../components/TransferForm.vue';
import { formatDate } from '../../components/accessPresentation.js';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Pärmens ägarbytessida, se issue 67b § Beslut 1, 2, 3 och 4.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource.
 *
 * **Raden `transfer` i navigationen ligger sist**, se containerSections.js —
 * ett ägarbyte är den mest konsekvensrika handlingen i produkten och inte
 * något man gör ofta.
 *
 * **Planen är en mening och inte en dold knapp** (Beslut 3). `planNotice` är
 * `null` när kontots plan har funktionen, och annars samma mening som POST
 * svarar med i fältfelet `plan`. Formuläret ritas ändå: att dölja ytan vore
 * att dölja funktionen man ska kunna köpa, och en grind som inte syns är en
 * grind ingen förstår. Länken till plansidan står BREDVID rutan, inte i
 * meningen — strängen levereras också av `/api`s felhölje, och markup i en
 * översättningssträng blir escapad text eller `v-html` hos någon annan.
 *
 * **Pågående och avslutade i två listor** (Beslut 4). Historiken står kvar:
 * en överlåtelse raderas aldrig, och en avvisad rad är vad avsändaren behöver
 * se för att förstå att hon måste skicka en ny. En utgången rad ligger i den
 * ÖVRE listan — den går fortfarande att dra tillbaka, och kontrollern tillåter
 * det (kolumnen står kvar på `pending`, utgången härleds) — medan statusen
 * skrivs ut som utgången.
 *
 * `can.transfer` ritar formuläret och är presentation. Grinden är policyn:
 * POST och DELETE auktoriserar med `Gate::authorize()` oavsett vad sidan
 * visade, och en `read_only`-ägare ser listan utan formulär.
 */
const props = defineProps({
    container: { type: Object, required: true },
    transfers: { type: Array, required: true },
    items: { type: Array, required: true },
    retainLevels: { type: Array, required: true },
    planNotice: { type: String, default: null },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

/*
 * En överlåtelse som fortfarande väntar — `pending`, eller samma rad efter att
 * tiden gått ut. `status` härleds av App\Http\Resources\OwnershipTransferResource
 * och läses aldrig ur kolumnen, så `expired` betyder "pending i databasen, för
 * gammal för att gälla" och raden går att dra tillbaka ändå.
 */
const isOpen = (transfer) => transfer.status === 'pending' || transfer.status === 'expired';

const openTransfers = computed(() => props.transfers.filter(isOpen));
const pastTransfers = computed(() => props.transfers.filter((transfer) => !isOpen(transfer)));

/* Mottagaren: kontots namn när raden pekar på ett konto, annars adressen. */
const recipient = (transfer) => transfer.to_account === null ? transfer.to_email : transfer.recipient_name;

const excludedLabel = (transfer) => transfer.excluded_items.length === 0
    ? t('transfer.row.excluded_none')
    : t('transfer.row.excluded', { count: transfer.excluded_items.length });

const retainedLabel = (transfer) => transfer.retain_access_level === null
    ? t('transfer.row.retain_none')
    : t('transfer.row.retain', { level: t(`sharing.level.${transfer.retain_access_level}.label`) });

/* Plansidan väljer konto i sidan och följer med som `?account=` (66a). */
const planHref = computed(() => `/settings/plan?account=${props.container.account}`);
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('transfer.title')" />

        <h1 class="text-2xl font-semibold">{{ t('transfer.heading') }}</h1>
        <p class="mt-2 text-sm text-slate-700">{{ t('transfer.intro') }}</p>

        <div
            v-if="planNotice"
            class="mt-6 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
        >
            <p>{{ planNotice }}</p>
            <Link :href="planHref" class="mt-2 inline-block underline">{{ t('transfer.plan_link') }}</Link>
        </div>

        <!--
            Domänfelet ur en postning — pärmen har redan en överlåtelse som
            väntar — blir en ruta ovanför formuläret och inte en rå felkod:
            App\Support\Frontend\ApiErrorTranslator formulerar meningen ur
            `lang/`, och nyckeln är `transfer` eftersom felet gäller pärmens
            tillstånd och inte ett fält.
        -->
        <p
            v-if="page.props.errors.transfer"
            id="transfer-error"
            role="alert"
            tabindex="-1"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 outline-none"
        >
            {{ page.props.errors.transfer }}
        </p>

        <TransferForm
            v-if="can.transfer"
            :container-ulid="container.ulid"
            :items="items"
            :retain-levels="retainLevels"
        />

        <section class="mt-10">
            <h2 class="text-lg font-semibold">{{ t('transfer.open.heading') }}</h2>

            <p v-if="openTransfers.length === 0" class="mt-2 text-sm text-slate-600">
                {{ t('transfer.open.empty') }}
            </p>

            <ul v-else class="mt-4 flex flex-col gap-3">
                <li
                    v-for="transfer in openTransfers"
                    :key="transfer.ulid"
                    class="flex flex-col gap-1 rounded border border-slate-300 bg-white px-4 py-3 text-sm"
                >
                    <span class="font-medium text-slate-800">{{ recipient(transfer) }}</span>
                    <span class="text-slate-700">{{ t(`transfer.status.${transfer.status}`) }}</span>
                    <span class="text-slate-700">{{ excludedLabel(transfer) }}</span>
                    <span class="text-slate-700">{{ retainedLabel(transfer) }}</span>
                    <span class="text-xs text-slate-600">
                        {{ t('transfer.row.created', { date: formatDate(transfer.created_at, page.props.locale) }) }}
                    </span>
                    <span class="text-xs text-slate-600">
                        {{ t('transfer.row.expires', { date: formatDate(transfer.expires_at, page.props.locale) }) }}
                    </span>

                    <Link
                        v-if="can.transfer"
                        :href="`/containers/${container.ulid}/transfer/${transfer.ulid}`"
                        method="delete"
                        as="button"
                        preserve-scroll
                        class="self-start text-sm text-red-700 underline"
                    >
                        {{ t('transfer.row.revoke') }}
                    </Link>
                </li>
            </ul>
        </section>

        <section v-if="pastTransfers.length > 0" class="mt-10">
            <h2 class="text-lg font-semibold">{{ t('transfer.history.heading') }}</h2>

            <ul class="mt-4 flex flex-col gap-2">
                <li
                    v-for="transfer in pastTransfers"
                    :key="transfer.ulid"
                    class="flex flex-col gap-1 rounded border border-slate-200 bg-slate-100 px-4 py-2 text-sm"
                >
                    <span class="text-slate-700">{{ recipient(transfer) }}</span>
                    <span class="text-slate-700">{{ t(`transfer.status.${transfer.status}`) }}</span>
                    <span class="text-xs text-slate-600">
                        {{ t('transfer.row.created', { date: formatDate(transfer.created_at, page.props.locale) }) }}
                    </span>
                    <span v-if="transfer.accepted_at" class="text-xs text-slate-600">
                        {{ t('transfer.row.accepted', { date: formatDate(transfer.accepted_at, page.props.locale) }) }}
                    </span>
                </li>
            </ul>
        </section>
    </ContainerLayout>
</template>
