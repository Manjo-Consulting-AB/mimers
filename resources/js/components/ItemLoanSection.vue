<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { formatDateOnly } from './itemPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Utlåningssektionen på itemets detaljvy — se issue 67a § Beslut 2–8.
 *
 * **Den öppna utlåningen överst, historiken under** (§ Beslut 2).
 * `returned_at IS NULL` är den öppna, och vilken rad det är kommer
 * färdigräknad från servern: `openLoan` är den, `loanHistory` är resten. Den
 * här filen sorterar ingenting — ordningen är serverns, samma som `/api` ger
 * (App\Http\Controllers\ItemController::show()).
 *
 * **Systemet mejlar aldrig låntagaren** (§ Beslut 4, [[ADR-0017
 * Missbruksvektorer]] § 7). Det är en regel och inte en detalj, och ytan gör
 * den synlig: adressen visas som text med en kopiera-knapp och en förklarande
 * rad — `email_note` — som säger att den aldrig används för utskick och att
 * påminnelsen går till den som lånat ut. Ingen `mailto:`-länk som förifyller
 * ett meddelande från produkten, ingen påminnelseknapp och ingen delning. Ett
 * e-postfält utan förklaring ser ut som ett fält som skickar mejl, och den
 * här sektionen får inte se ut så.
 *
 * **Återlämning är en knapp** (§ Beslut 3). *Tillbaka idag* sätter dagens
 * datum och skickar samma PATCH som formuläret bredvid — den vanligaste
 * handlingen kostar ett klick, och den som vill sätta ett annat datum skriver
 * det i datumfältet. Båda går genom SAMMA formulär, så fältfelet hamnar på
 * samma ställe oavsett vilken väg användaren tog: `after_or_equal:lent_at`
 * gäller, och ett datum före utlåningen blir ett fältfel på `returned_at`.
 *
 * **Dagens datum kommer från servern** (`today`-propen), precis som
 * försenad-flaggan: en telefon med fel klocka ska inte kunna registrera en
 * återlämning före utlåningen och få ett fältfel hon inte förstår.
 *
 * **Försenad är härledd** (§ Beslut 5) och läses ur `openLoanOverdue`. Den
 * här filen jämför aldrig `due_at` mot sin egen klocka — samma regel som
 * `overdue` i issue 63b § Beslut 3, och samma skäl: en klient med fel datum
 * ska inte kunna färga en utlåning röd.
 *
 * **Att ta bort raden är inte att återlämna** (§ Beslut 7). Knapparna står
 * bredvid varandra, och bekräftelsen säger att raden försvinner medan prylen
 * är kvar som utlånad — de två får inte gå att förväxla.
 *
 * **Fyra ytor, fyra pinnar** (§ Beslut 6). `can.create` ritar
 * utlåningsformuläret, `can.update` ritar *Tillbaka idag* och datumfältet,
 * `can.delete` ritar ta bort-knappen på både den öppna raden och
 * historikraderna. Alla är presentation: grinden i
 * App\Http\Controllers\LoanController prövas på nytt i varje skrivning, och
 * en `read`-mottagare ser sektionen men ingen skrivyta och får 403 om hon
 * postar ändå.
 *
 * **Formuläret ritas inte medan itemet är utlånat.** Servern tillåter högst
 * en öppen utlåning per item (issue 76 § Beslut 4) och avvisar en andra med
 * `loan.already_open`; att rita ett formulär som alltid nekas vore en fälla.
 *
 * **Ingen svensk sträng i den här filen** (§ Beslut 8): varje ord kommer ur
 * `lang/{locale}/ui.php` genom `t()`, och datumen formateras av
 * formatDateOnly() i itemPresentation.js — DATE-kolumner byggs i lokal tid och
 * räknas aldrig om till en annan tidszon (issue 57a § Beslut 8).
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /*
     * Den öppna utlåningen ur App\Http\Resources\LoanResource, eller `null`
     * när itemet inte är utlånat — servern har delat listan (§ Beslut 2).
     */
    openLoan: { type: Object, default: null },
    /*
     * Är den öppna utlåningen försenad? Räknat på serverns datum (§ Beslut 5).
     * Flaggan kommer ur detaljvyns props och räknas aldrig här.
     */
    openLoanOverdue: { type: Boolean, required: true },
    /* De avslutade utlåningarna, i samma ordning som `/api` ger dem. */
    loanHistory: { type: Array, required: true },
    /* Serverns datum, `Y-m-d` — *Tillbaka idag* sätter det här värdet. */
    today: { type: String, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();
const page = usePage();

const locale = computed(() => page.props.locale);

/* ULID:n för den adress som just kopierades, eller `null` — knappens ord. */
const copied = ref(null);

/*
 * Kopieringen. Adressen ligger redan som text i sektionen; knappen sparar ett
 * markeringsvarv, och `copied` säger att den gjorde det. Ingen `mailto:` —
 * kopian går till urklipp, och vad användaren gör med den är hennes beslut.
 */
function copy(loan) {
    if (! navigator.clipboard) {
        return;
    }

    navigator.clipboard.writeText(loan.borrower_email).then(() => {
        copied.value = loan.ulid;
    });
}

/*
 * Utlåningsformuläret. `lent_at` är förvalt till dagens datum (§ Beslut 3) —
 * serverns, ur `today`-propen — och datumen är `<input type="date">`, som
 * skickar `Y-m-d`, exakt vad `date`-regeln i den delade StoreLoanRequest tar
 * emot.
 */
const loanForm = useForm({
    borrower_name: '',
    borrower_email: '',
    lent_at: props.today,
    due_at: '',
    returned_at: '',
    note: '',
});

/*
 * Återlämningen. ETT formulär för båda vägarna: knappen sätter dagens datum i
 * fältet och skickar, datumfältet skickar sitt eget. Felet hamnar därför på
 * samma ställe hur användaren än gjorde det.
 */
const returnForm = useForm({
    returned_at: '',
});

function loanUrl(loan) {
    return `/containers/${props.containerUlid}/items/${props.itemUlid}/loans/${loan.ulid}`;
}

function submit() {
    loanForm.post(`/containers/${props.containerUlid}/items/${props.itemUlid}/loans`, {
        // Sidan är lång, och ett hopp till toppen efter en skriven rad tappar
        // läsarens plats.
        preserveScroll: true,
        onSuccess: () => loanForm.reset(),
        onError: focusFirstError,
    });
}

function submitReturn() {
    returnForm.patch(loanUrl(props.openLoan), {
        preserveScroll: true,
        onError: focusFirstError,
    });
}

function returnToday() {
    returnForm.returned_at = props.today;
    submitReturn();
}

/*
 * Raderingen. Bekräftelsen är webbläsarens egen dialog med serverns mening ur
 * `lang/` — ingen modal komponent och ingen sträng i JavaScript, samma mönster
 * som detaljvyns radering och upp-knytningen i ItemLinkSection.
 *
 * `router.delete` och inte en <Link method="delete">: bekräftelsen måste kunna
 * AVBRYTA navigeringen.
 *
 * `pending` är radens vänteläge (issue 68a § Beslut 4 och 5): knappen är
 * stängd och byter ord medan servern svarar. Flaggan bär den anropade radens
 * ULID och inte en boolean — den öppna raden och historikraderna ritas ur
 * samma komponent, och bara knappen man tryckte på ska gå i vänteläge (Beslut
 * 4).
 */
const pending = ref(null);

function destroy(loan) {
    if (! window.confirm(t('item.loan.destroy_confirm'))) {
        return;
    }

    router.delete(loanUrl(loan), {
        preserveScroll: true,
        onStart: () => { pending.value = loan.ulid; },
        onFinish: () => { pending.value = null; },
    });
}
</script>

<template>
    <section class="mt-10">
        <h2 class="text-lg font-semibold">{{ t('item.loan.heading') }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ t('item.loan.description') }}</p>

        <!-- Den öppna utlåningen överst (§ Beslut 2). -->
        <div v-if="openLoan" class="mt-4 flex flex-col gap-2 rounded border border-slate-300 bg-white px-4 py-3">
            <p class="font-medium text-slate-900">
                {{ t('item.loan.borrowed_by', { name: openLoan.borrower_name }) }}
            </p>

            <p class="text-sm text-slate-600">
                {{ t('item.loan.lent_at', { date: formatDateOnly(openLoan.lent_at, locale) }) }}
            </p>

            <p v-if="openLoan.due_at" class="text-sm text-slate-600">
                {{ t('item.loan.due_at', { date: formatDateOnly(openLoan.due_at, locale) }) }}
            </p>
            <p v-else class="text-sm text-slate-600">{{ t('item.loan.no_due_at') }}</p>

            <!-- Försenad (§ Beslut 5): flaggan är serverns, aldrig klientens
                 jämförelse. -->
            <p v-if="openLoanOverdue" class="text-sm font-medium text-red-700">
                {{ t('item.loan.overdue') }}
            </p>

            <p v-if="openLoan.note" class="whitespace-pre-line text-sm text-slate-700">{{ openLoan.note }}</p>

            <!--
                Kontaktuppgiften (§ Beslut 4): text, ett sätt att kopiera, och
                meningen som säger varför adressen finns. Ingen `mailto:`, ingen
                påminnelseknapp, ingen delning — systemet mejlar aldrig
                låntagaren.
            -->
            <template v-if="openLoan.borrower_email">
                <p class="text-sm font-medium text-slate-800">{{ t('item.loan.contact') }}</p>

                <p class="flex flex-wrap items-center gap-3 text-slate-900">
                    <span>{{ openLoan.borrower_email }}</span>

                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center text-sm font-medium text-blue-700 hover:underline"
                        @click="copy(openLoan)"
                    >
                        {{ copied === openLoan.ulid ? t('item.loan.copied') : t('item.loan.copy') }}
                    </button>
                </p>

                <p class="text-sm text-slate-600">{{ t('item.loan.email_note') }}</p>
            </template>

            <div v-if="can.update || can.delete" class="mt-2 flex flex-wrap items-center gap-3">
                <button
                    v-if="can.update"
                    type="button"
                    :disabled="returnForm.processing"
                    class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                    @click="returnToday"
                >
                    {{ t('item.loan.return_today') }}
                </button>

                <button
                    v-if="can.delete"
                    type="button"
                    :disabled="pending === openLoan.ulid"
                    class="inline-flex min-h-11 items-center text-sm text-red-700 hover:underline"
                    @click="destroy(openLoan)"
                >
                    {{ pending === openLoan.ulid ? t('common.pending.default') : t('item.loan.destroy') }}
                </button>
            </div>

            <!-- Det egna återlämningsdatumet (§ Beslut 3). -->
            <form v-if="can.update" class="mt-2 flex max-w-lg flex-col gap-3" @submit.prevent="submitReturn">
                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.loan.return_date')"
                    id="returned_at"
                    :error="returnForm.errors.returned_at"
                >
                    <input
                        id="returned_at"
                        v-model="returnForm.returned_at"
                        :aria-describedby="describedBy"
                        type="date"
                        name="returned_at"
                        :min="openLoan.lent_at"
                        required
                        class="self-start rounded border border-slate-300 bg-white px-3 py-2"
                    >
                </FormField>

                <button
                    type="submit"
                    :disabled="returnForm.processing"
                    class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                >
                    {{ returnForm.processing ? t('common.pending.default') : t('item.loan.return_submit') }}
                </button>
            </form>
        </div>

        <p v-else class="mt-4 text-sm text-slate-600">{{ t('item.loan.not_lent') }}</p>

        <!--
            Utlåningsformuläret (§ Beslut 3 och 6). Det ritas bara när itemet
            inte redan är utlånat: servern tillåter högst en öppen utlåning per
            item, och ett formulär som alltid nekas är en fälla.
        -->
        <template v-if="can.create && !openLoan">
            <h3 class="mt-8 text-base font-semibold">{{ t('item.loan.form_heading') }}</h3>

            <form class="mt-4 flex max-w-lg flex-col gap-4" @submit.prevent="submit">
                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.loan.form_name')"
                    id="borrower_name"
                    :error="loanForm.errors.borrower_name"
                >
                    <input
                        id="borrower_name"
                        v-model="loanForm.borrower_name"
                        :aria-describedby="describedBy"
                        type="text"
                        name="borrower_name"
                        required
                        class="rounded border border-slate-300 bg-white px-3 py-2"
                    >
                </FormField>

                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.loan.form_email')"
                    id="borrower_email"
                    :error="loanForm.errors.borrower_email"
                >
                    <input
                        id="borrower_email"
                        v-model="loanForm.borrower_email"
                        :aria-describedby="describedBy"
                        type="email"
                        name="borrower_email"
                        class="rounded border border-slate-300 bg-white px-3 py-2"
                    >

                    <!-- Fältet ser annars ut som ett fält som skickar mejl
                         (§ Beslut 4). -->
                    <p class="text-sm text-slate-600">{{ t('item.loan.email_note') }}</p>
                </FormField>

                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.loan.form_lent_at')"
                    id="lent_at"
                    :error="loanForm.errors.lent_at"
                >
                    <input
                        id="lent_at"
                        v-model="loanForm.lent_at"
                        :aria-describedby="describedBy"
                        type="date"
                        name="lent_at"
                        required
                        class="rounded border border-slate-300 bg-white px-3 py-2"
                    >
                </FormField>

                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.loan.form_due_at')"
                    id="due_at"
                    :error="loanForm.errors.due_at"
                >
                    <input
                        id="due_at"
                        v-model="loanForm.due_at"
                        :aria-describedby="describedBy"
                        type="date"
                        name="due_at"
                        :min="loanForm.lent_at"
                        class="rounded border border-slate-300 bg-white px-3 py-2"
                    >
                </FormField>

                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.loan.form_returned_at')"
                    id="returned_at_new"
                    :error="loanForm.errors.returned_at"
                >
                    <input
                        id="returned_at_new"
                        v-model="loanForm.returned_at"
                        :aria-describedby="describedBy"
                        type="date"
                        name="returned_at"
                        :min="loanForm.lent_at"
                        class="rounded border border-slate-300 bg-white px-3 py-2"
                    >
                </FormField>

                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.loan.form_note')"
                    id="note"
                    :error="loanForm.errors.note"
                >
                    <textarea
                        id="note"
                        v-model="loanForm.note"
                        :aria-describedby="describedBy"
                        name="note"
                        rows="3"
                        class="rounded border border-slate-300 bg-white px-3 py-2"
                    />
                </FormField>

                <button
                    type="submit"
                    :disabled="loanForm.processing"
                    class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                >
                    {{ loanForm.processing ? t('common.pending.default') : t('item.loan.form_submit') }}
                </button>
            </form>
        </template>

        <!--
            Historiken (§ Beslut 2). Rubriken och listan ritas bara när det
            finns en rad: är itemet aldrig utlånat räcker `not_lent` ovan, och
            en andra mening om att historiken är tom säger samma sak en gång
            till.
        -->
        <template v-if="loanHistory.length > 0">
            <h3 class="mt-8 text-base font-semibold">{{ t('item.loan.history_heading') }}</h3>

            <ul class="mt-2 flex flex-col gap-2">
                <li
                    v-for="loan in loanHistory"
                    :key="loan.ulid"
                    class="flex flex-col gap-1 rounded border border-slate-300 bg-white px-4 py-2"
                >
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="font-medium text-slate-900">{{ loan.borrower_name }}</span>

                        <span class="text-sm text-slate-600">
                            {{ t('item.loan.lent_at', { date: formatDateOnly(loan.lent_at, locale) }) }}
                        </span>

                        <span class="text-sm text-slate-600">
                            {{ t('item.loan.returned_at', { date: formatDateOnly(loan.returned_at, locale) }) }}
                        </span>

                        <span v-if="loan.borrower_email" class="text-sm text-slate-600">{{ loan.borrower_email }}</span>

                        <button
                            v-if="can.delete"
                            type="button"
                            :disabled="pending === loan.ulid"
                            class="inline-flex min-h-11 items-center text-sm text-red-700 hover:underline"
                            @click="destroy(loan)"
                        >
                            {{ pending === loan.ulid ? t('common.pending.default') : t('item.loan.destroy') }}
                        </button>
                    </div>

                    <p v-if="loan.note" class="whitespace-pre-line text-sm text-slate-700">{{ loan.note }}</p>
                </li>
            </ul>
        </template>
    </section>
</template>
