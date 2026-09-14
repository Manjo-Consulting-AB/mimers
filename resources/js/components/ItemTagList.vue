<script setup>
/*
 * Itemets taggar som färgprickar med namn — se issue 57a § Beslut 8.
 *
 * Delad mellan listan och detaljvyn: taggen ritas likadant på båda, och två
 * avskrifter av samma prick glider isär. Formen är TagRow:s prick — en
 * omålad prick när `color` är null, ingen påhittad standardfärg (issue 56a
 * § Beslut 8).
 *
 * Sorteringen är serverns: ItemResource sorterar `tags` på namn. Ingen
 * sortering här — två sorteringar av samma lista glider isär.
 *
 * Egen komponent och inte en v-for i varje sida, av samma skäl som TagRow:
 * ytan är densamma på två ställen och bara en av dem kan vara förlagan.
 */
defineProps({
    tags: { type: Array, required: true },
});
</script>

<template>
    <ul v-if="tags.length > 0" class="flex flex-wrap items-center gap-2">
        <li
            v-for="tag in tags"
            :key="tag.ulid"
            class="flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700"
        >
            <span
                aria-hidden="true"
                class="inline-block h-3 w-3 shrink-0 rounded-full border border-slate-300"
                :style="tag.color ? { backgroundColor: tag.color } : null"
            />
            <span>{{ tag.name }}</span>
        </li>
    </ul>
</template>
