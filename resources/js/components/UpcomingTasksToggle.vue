<script setup>
import { useForm } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Växeln för framtida uppgifter, se [[M21 Uppgifterna i vardagen]] § 134.
 *
 * **Samma komponent på båda ställena.** Den står i rubrikraden på `/tasks`
 * (resources/js/pages/Tasks/Index.vue) och på dashboardens uppgiftspanel
 * (resources/js/components/DashboardTasksPanel.vue), och en kopia på det ena
 * stället hade varit den andra sanningen om vad växeln gör — samma rutt,
 * samma fält, samma etikett.
 *
 * **Läget kommer från servern och aldrig från klienten.** `enabled` är
 * `user.show_upcoming_tasks`, och komponenten håller inget eget tillstånd:
 * den postar det motsatta värdet till `PUT /settings/tasks`, och rutten
 * svarar `back()` — sidan ritas om ur det sparade värdet. Ett `ref` här hade
 * varit en tredje plats som vet vad användaren valt, och den hade glidit
 * isär från databasen vid ett misslyckat anrop (samma linje som
 * `notifications_read_at` i issue 127: inget tillstånd i `localStorage`).
 *
 * **`role="switch"` med `aria-checked`** (issuens krav). En knapp med
 * `aria-pressed` hade lästs som "nedtryckt", inte som "på"; en switch är
 * kontrollen som beskriver ett tillstånd, och `aria-checked` är det tillstånd
 * skärmläsaren läser. Etiketten ligger i knappen och blir därför dess namn.
 *
 * Färgen är aldrig den enda bäraren: spåret byter färg, men det är
 * `aria-checked` som säger läget. Fokusringen är `--color-focus` och får
 * aldrig tas bort, som på UiButton och de tre andra kontrollerna.
 */
const props = defineProps({
    /* Sparat läge: visar listan även uppgifter som förfaller framåt? */
    enabled: { type: Boolean, required: true },
});

const { t } = useTranslations();

const form = useForm({ show_upcoming_tasks: props.enabled });

/*
 * Växlingen postar det MOTSATTA av det sparade läget och ingenting ur
 * `form.show_upcoming_tasks`: det fältet är en kopia av proppen och hade
 * kunnat vara inaktuellt efter en misslyckad skrivning. Proppen är serverns
 * svar, och den är därför den enda källan till vad nästa värde är.
 */
function toggle() {
    form.show_upcoming_tasks = ! props.enabled;

    form.put('/settings/tasks', { preserveScroll: true });
}
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="props.enabled ? 'true' : 'false'"
        :disabled="form.processing"
        class="inline-flex min-h-11 items-center gap-2 text-meta text-ink-muted outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
        @click="toggle"
    >
        <span
            aria-hidden="true"
            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors"
            :class="props.enabled ? 'bg-accent' : 'bg-border'"
        >
            <span
                class="inline-block size-3.5 rounded-full bg-surface transition-transform"
                :class="props.enabled ? 'translate-x-[1.25rem]' : 'translate-x-1'"
            />
        </span>

        <span>{{ t('todo.toggle') }}</span>
    </button>
</template>
