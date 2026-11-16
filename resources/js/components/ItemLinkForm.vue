<script setup>
import { onMounted } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import UiButton from './UiButton.vue';
import UiSelect from './UiSelect.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Formuläret för att lägga till en relation — utbrutet ur `ItemLinkSection`
 * när relationsfliken togs bort (issue 1339 · [[ADR-0082 Kartan är
 * relationernas yta]] § 3). Det öppnas över den fulla kartan med itemet som
 * mitt (`?add=relation`) och ritar motpart, riktning och meningen om vad
 * *Related* delar (issue 58 § Beslut 8).
 *
 * **Riktningen börjar TOM och inte på ett förvalt värde.** `parent` och
 * `child` bär behörighet nedåt ([[ADR-0028 Åtkomst på itemnivå]]), och att
 * välja riktning åt användaren vore att tysta utvidga en delning. En tom
 * riktning fastnar i StoreItemLinkRequests `Rule::in` och blir ett fältfel.
 *
 * **Riktningen läses från MOTPARTENS sida:** *A parent item* betyder att
 * motparten ligger över det här itemet. Servern vänder på värdet innan det
 * når `LinkItems` (App\Http\Controllers\ItemLinkController).
 *
 * **Meningen om *Related* räknar ingenting.** Ingen fråga om vilka grants som
 * finns och ingen siffra — den hör till delningsvyn (issue 55a), och en andra
 * räknare här vore en andra sanning om omfånget.
 *
 * Fältets `id` är fältets NAMN (`item` och `relation`), samma nycklar som
 * StoreItemLinkRequest lägger sina fel på — FormFields kontrakt, se
 * resources/js/pages/Auth/useErrorFocus.js. `item_link.cycle` hamnar på
 * `relation`; de tre övriga domänfelen på `item`.
 *
 * Urvalet kommer färdigt från servern: bara motparter i omfånget som hon får
 * ändra och som inte redan är kopplade. Filtret här är artighet; grinden är
 * ItemLinkController::store().
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    /* `{ulid, name}` — items hon får ändra och som inte redan är kopplade. */
    counterparts: { type: Array, required: true },
});

const emit = defineEmits(['cancel']);

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    item: '',
    relation: '',
});

onMounted(() => {
    document.getElementById('item')?.focus();
});

function submit() {
    form.post(`/containers/${props.containerUlid}/items/${props.itemUlid}/links`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: focusFirstError,
    });
}
</script>

<template>
    <div>
        <p v-if="counterparts.length === 0" class="text-sm text-ink-muted">
            {{ t('item.links.no_counterparts') }}
        </p>

        <form v-else class="flex max-w-lg flex-col gap-4" @submit.prevent="submit">
            <FormField
                v-slot="{ describedBy }"
                :label="t('item.links.counterpart')"
                id="item"
                :error="form.errors.item"
            >
                <UiSelect
                    id="item"
                    v-model="form.item"
                    :described-by="describedBy"
                    name="item"
                    required
                    class="self-start"
                >
                    <option value="">{{ t('item.links.counterpart_none') }}</option>
                    <option v-for="candidate in counterparts" :key="candidate.ulid" :value="candidate.ulid">
                        {{ candidate.name }}
                    </option>
                </UiSelect>
            </FormField>

            <FormField
                v-slot="{ describedBy }"
                :label="t('item.links.relation.label')"
                id="relation"
                :error="form.errors.relation"
            >
                <UiSelect
                    id="relation"
                    v-model="form.relation"
                    :described-by="describedBy"
                    name="relation"
                    required
                    class="self-start"
                >
                    <option value="">{{ t('item.links.relation.none') }}</option>
                    <option value="parent">{{ t('item.links.relation.parent') }}</option>
                    <option value="child">{{ t('item.links.relation.child') }}</option>
                    <option value="related">{{ t('item.links.relation.related') }}</option>
                </UiSelect>

                <p class="text-sm text-ink-muted">{{ t('item.links.relation_note') }}</p>
            </FormField>

            <div class="flex flex-wrap items-center gap-3">
                <UiButton type="submit" :pending="form.processing">
                    {{ form.processing ? t('common.pending.default') : t('item.links.submit') }}
                </UiButton>

                <button
                    type="button"
                    class="inline-flex min-h-11 items-center rounded-control px-2 text-sm text-ink-muted hover:underline"
                    @click="emit('cancel')"
                >
                    {{ t('confirm.cancel') }}
                </button>
            </div>
        </form>
    </div>
</template>
