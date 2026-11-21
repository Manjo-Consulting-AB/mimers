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
 *
 * `deleteErrorUlid` och `delete` färdas genom rekursionen på samma sätt: raden
 * som nekades kan sitta på vilken nivå som helst, och sidan äger tillståndet.
 *
 * `counts` färdas likadant (issue 150): varje rad behöver sitt eget tal för
 * raderingsfrågan, och raden kan sitta på vilken nivå som helst.
 *
 * **Draget färdas också genom rekursionen, men ägs av sidan** (issue 1484):
 * `draggingUlid` är raden som dras, `moveErrorUlid` raden vars flytt nekades,
 * och `disabled` säger att en flytt skrivs just nu. Raden säger `dragstart`
 * och `drop`; trädet bara skickar vidare, för målet kan sitta på vilken nivå
 * som helst och bara sidan har hela listan.
 */
defineProps({
    nodes: { type: Array, required: true },
    containerUlid: { type: String, required: true },
    categories: { type: Array, required: true },
    counts: { type: Object, required: true },
    deleteErrorUlid: { type: String, default: null },
    /* ULID:n för den rad som dras just nu, eller null. */
    draggingUlid: { type: String, default: null },
    /* ULID:n för den rad vars flytt senast nekades av servern, eller null. */
    moveErrorUlid: { type: String, default: null },
    /* Sant medan en flytt skrivs: radernas knappar vilar då. */
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['delete', 'dragstart', 'dragend', 'drop', 'submit']);
</script>

<template>
    <ul class="flex flex-col gap-2">
        <li v-for="node in nodes" :key="node.ulid" class="flex flex-col gap-2">
            <CategoryRow
                :container-ulid="containerUlid"
                :category="node"
                :categories="categories"
                :counts="counts"
                :delete-error-ulid="deleteErrorUlid"
                :dragging-ulid="draggingUlid"
                :move-error-ulid="moveErrorUlid"
                :disabled="disabled"
                @delete="emit('delete', $event)"
                @dragstart="emit('dragstart', $event)"
                @dragend="emit('dragend')"
                @drop="emit('drop', $event)"
                @submit="emit('submit', $event)"
            />

            <CategoryTree
                v-if="node.children.length > 0"
                class="ml-6 border-l border-slate-200 pl-4"
                :nodes="node.children"
                :container-ulid="containerUlid"
                :categories="categories"
                :counts="counts"
                :delete-error-ulid="deleteErrorUlid"
                :dragging-ulid="draggingUlid"
                :move-error-ulid="moveErrorUlid"
                :disabled="disabled"
                @delete="emit('delete', $event)"
                @dragstart="emit('dragstart', $event)"
                @dragend="emit('dragend')"
                @drop="emit('drop', $event)"
                @submit="emit('submit', $event)"
            />
        </li>
    </ul>
</template>
