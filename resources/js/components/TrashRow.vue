<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { formatDate } from './accessPresentation.js';
import { remainingLabel } from './trashPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En rad i papperskorgen, se issue 62a § Beslut 4, 6 och 9.
 *
 * **Raden visar BÅDA tiderna** (Beslut 4): när innehållet raderades och hur
 * länge det finns kvar. `expires_at` kommer ur `TrashEntryResource` — den
 * återstående tiden räknas alltså inte här, den formuleras här.
 *
 * **Vad raden är och vad den hör till.** `entry.label` är namnet
 * användaren känner igen saken på (itemets namn, filnamnet, kategorins namn,
 * taggens namn), `entry.context` är bilagans item eller underkategorins
 * förälder — utan den är "faktura.pdf" i en lista med tjugo poster
 * obrukbart. Båda kommer färdiga ur resursen; vyn hittar inte på något.
 *
 * **Återställningsknappen ritas ur `can_restore`** (Beslut 6), som
 * kontrollern räknar med samma grind som rutten prövar. Flaggan är
 * presentation: `POST` auktoriserar ändå, och en `read`-deltagare som
 * postar förbi vyn får 403.
 *
 * Ingen bekräftelseruta: återställningen är den ogörliga handlingens
 * motsats — den lägger tillbaka något i pärmen, och den går att ångra med
 * en ny radering.
 */
defineProps({
    containerUlid: { type: String, required: true },
    entry: { type: Object, required: true },
    canRestore: { type: Boolean, default: false },
});

const { t } = useTranslations();
const page = usePage();
</script>

<template>
    <li class="flex flex-col gap-1 rounded border border-slate-300 bg-white px-4 py-2 text-sm">
        <span class="font-medium text-slate-800">
            {{ entry.label }}
            <span class="font-normal text-slate-600">{{ t(`trash.type.${entry.type}`) }}</span>
        </span>

        <span v-if="entry.context" class="text-slate-700">{{ entry.context }}</span>

        <span class="text-xs text-slate-600">
            {{ t('trash.deleted_at', { date: formatDate(entry.deleted_at, page.props.locale) }) }}
        </span>

        <span class="text-xs text-slate-600">{{ remainingLabel(t, entry.expires_at) }}</span>

        <Link
            v-if="canRestore"
            :href="`/containers/${containerUlid}/trash/restore`"
            method="post"
            :data="{ type: entry.type, ulid: entry.ulid }"
            as="button"
            preserve-scroll
            class="self-start text-sm text-blue-700 underline"
        >
            {{ t('trash.restore') }}
        </Link>
    </li>
</template>
