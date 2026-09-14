<script setup>
import CategoryRow from './CategoryRow.vue';

/*
 * Trädet, ritat av en komponent som renderar sig SJÄLV — se issue 56a
 * § Beslut 2.
 *
 * Ingen handskriven utplattning per nivå: `<ul>`/`<li>` och en rekursiv
 * komponent räcker, och fem nivåer är taket (`Category::MAX_DEPTH`). Ingen
 * virtualisering — trädet är litet per definition.
 *
 * Komponenten refererar sig själv vid namn i mallen. Det fungerar i en
 * `<script setup>`-SFC: filnamnet ÄR komponentens namn, och därför får filen
 * inte döpas om utan att även mallen ändras.
 *
 * `categories` är hela den platta listan och skickas vidare oförändrad — varje
 * rad behöver den för sin föräldraväljare.
 */
defineProps({
    nodes: { type: Array, required: true },
    containerUlid: { type: String, required: true },
    categories: { type: Array, required: true },
});
</script>

<template>
    <ul class="flex flex-col gap-2">
        <li v-for="node in nodes" :key="node.ulid" class="flex flex-col gap-2">
            <CategoryRow :container-ulid="containerUlid" :category="node" :categories="categories" />

            <CategoryTree
                v-if="node.children.length > 0"
                class="ml-6 border-l border-slate-200 pl-4"
                :nodes="node.children"
                :container-ulid="containerUlid"
                :categories="categories"
            />
        </li>
    </ul>
</template>
