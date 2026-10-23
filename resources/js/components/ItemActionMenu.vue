<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import UiIcon from './UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * ⋯-menyn i itemets huvud, se issue 331 · [[ADR-0068 Itemets sida och
 * skapandet]] § 3: *New item under this one* och sist *Move to trash*.
 *
 * **Var och en bara med sin rättighet.** Anroparen skickar `canCreate` och
 * `canDelete` ur `can`-flaggorna, och en rad utan sin flagga ritas inte. Är
 * båda falska är menyn tom och **ingen ⋯-knapp ritas alls** — en knapp som
 * öppnar ingenting är en knapp som ger felkod i efterhand.
 *
 * **Samma mönster som CreateMenu** på de punkter som gäller en meny: knappen
 * är menyns utlösare, menyn stängs med Escape och fokus går då tillbaka till
 * knappen, och ett val stänger menyn. Ytan är däremot en liten meny under
 * knappen och inget ark: den har bara två rader, och CreateMenus ark är en
 * `<dialog>` med rubrik och stängknapp för fyra. Därför bär ytan
 * `role="menu"` och raderna `role="menuitem"`, vilket arket inte gör.
 *
 * **Raden *Move to trash* raderar ingenting.** Den lämnar över till anroparen
 * med `trash`, och anroparen öppnar bekräftelsedialogen (issue 330). ⋯-knappen
 * följer med som dialogens `trigger`: raden försvinner när menyn stängs, och
 * fokus ska tillbaka på något som finns kvar.
 *
 * Klick utanför stänger menyn. Pil upp och ned flyttar mellan raderna, som
 * rollen `menu` lovar.
 */
defineProps({
    /* Sant med `can.create`: raden *New item under this one*. */
    canCreate: { type: Boolean, default: false },
    /* Sant med `can.delete`: raden *Move to trash*. */
    canDelete: { type: Boolean, default: false },
    /* Adressen raden *New item under this one* leder till. */
    createHref: { type: String, required: true },
});

const emit = defineEmits(['trash']);

const { t } = useTranslations();

const open = ref(false);
const root = ref(null);
const toggle = ref(null);
const menu = ref(null);

async function openMenu() {
    open.value = true;

    await nextTick();

    menu.value?.querySelector('[role="menuitem"]')?.focus();
}

function close(returnFocus = true) {
    if (! open.value) {
        return;
    }

    open.value = false;

    if (returnFocus) {
        toggle.value?.focus();
    }
}

function onToggle() {
    if (open.value) {
        close();

        return;
    }

    openMenu();
}

function onKeydown(event) {
    if (event.key === 'Escape' && open.value) {
        event.stopPropagation();
        close();

        return;
    }

    if (! open.value || (event.key !== 'ArrowDown' && event.key !== 'ArrowUp')) {
        return;
    }

    const rows = Array.from(menu.value?.querySelectorAll('[role="menuitem"]') ?? []);
    const at = rows.indexOf(document.activeElement);
    const step = event.key === 'ArrowDown' ? 1 : -1;

    event.preventDefault();
    rows[(at + step + rows.length) % rows.length]?.focus();
}

function onOutside(event) {
    if (open.value && root.value && ! root.value.contains(event.target)) {
        close(false);
    }
}

function trash() {
    const trigger = toggle.value;

    close(false);
    emit('trash', trigger);
}

onMounted(() => document.addEventListener('click', onOutside));
onBeforeUnmount(() => document.removeEventListener('click', onOutside));
</script>

<template>
    <div v-if="canCreate || canDelete" ref="root" class="relative" @keydown="onKeydown">
        <button
            ref="toggle"
            type="button"
            aria-haspopup="menu"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-label="t('item.menu.label')"
            class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-control border border-border text-ink-muted outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            @click="onToggle"
        >
            <UiIcon name="more" :size="20" class="h-5 w-5" />
        </button>

        <div
            v-if="open"
            ref="menu"
            role="menu"
            :aria-label="t('item.menu.label')"
            class="absolute right-0 z-10 mt-2 flex w-64 flex-col rounded-card border border-border bg-surface p-1"
        >
            <Link
                v-if="canCreate"
                :href="createHref"
                role="menuitem"
                class="flex min-h-11 items-center rounded-control px-3 text-body text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus"
                @click="close(false)"
            >
                {{ t('item.links.create_child.action') }}
            </Link>

            <button
                v-if="canDelete"
                type="button"
                role="menuitem"
                class="flex min-h-11 items-center rounded-control px-3 text-left text-body text-danger outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus"
                @click="trash"
            >
                {{ t('item.menu.trash') }}
            </button>
        </div>
    </div>
</template>
