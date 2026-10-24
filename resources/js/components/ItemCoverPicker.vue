<script setup>
import { nextTick, ref } from 'vue';
import UiIcon from './UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Omslagsväljaren i redigeringen, se issue 342 och [[ADR-0068 Itemets sida och
 * skapandet]] § 12.
 *
 * **Ett rutnät av miniatyrer, och rutan *No cover* först.** `images` är
 * itemets bilder ur ItemController::imageOptions() — äldst först, och vyn
 * sorterar dem aldrig om. Värdet är detsamma som när väljaren var en
 * `<select>`: den valda bildens ULID, eller `null` för *No cover*.
 *
 * **Rutnätet är en radiogrupp.** Ett val i taget: den valda rutan är den enda
 * som står i tabbordningen (rörlig tabindex), och piltangenterna flyttar både
 * valet och fokus, som en vanlig radiogrupp gör. Valet syns med mer än färg —
 * en tjockare ram och en bockmarkering — och `aria-checked` bär det för
 * skärmläsaren.
 *
 * **`<img>` ritas bara när bilden har en miniatyr** (`hasThumb`). En bild utan
 * miniatyr — nyss uppladdad, derivaten genereras i ett köat jobb — ritas som en
 * ruta med filnamnet och aldrig som en trasig bild: `?variant=thumb` mot en
 * bilaga utan derivat är 404.
 */
const props = defineProps({
    label: { type: String, required: true },
    images: { type: Array, required: true },
    /* Den valda bildens ULID, eller null för *No cover*. */
    modelValue: { type: String, default: null },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useTranslations();

const tiles = ref([]);

/* *No cover* är första alternativet, därefter bilderna i serverns ordning. */
function options() {
    return [null, ...props.images.map((image) => image.ulid)];
}

function select(value) {
    emit('update:modelValue', value);
}

/* Piltangenterna flyttar valet och fokus; Home och End hoppar till ändarna. */
function onKeydown(event, index) {
    const last = options().length - 1;
    let next = null;

    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
        next = index === last ? 0 : index + 1;
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
        next = index === 0 ? last : index - 1;
    } else if (event.key === 'Home') {
        next = 0;
    } else if (event.key === 'End') {
        next = last;
    }

    if (next === null) {
        return;
    }

    event.preventDefault();
    select(options()[next]);
    nextTick(() => tiles.value[next]?.focus());
}

function isChecked(value) {
    return (props.modelValue ?? null) === value;
}

const tileBase = 'relative flex h-20 w-20 items-center justify-center overflow-hidden rounded-control bg-surface text-center text-meta text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2';
</script>

<template>
    <div class="flex flex-col gap-1">
        <p :id="'cover-label'" class="text-body font-medium text-ink">{{ label }}</p>

        <div
            role="radiogroup"
            aria-labelledby="cover-label"
            :aria-describedby="error ? 'cover-error' : undefined"
            class="flex flex-wrap gap-2"
        >
            <button
                :ref="(el) => { tiles[0] = el; }"
                type="button"
                role="radio"
                :aria-checked="isChecked(null) ? 'true' : 'false'"
                :tabindex="isChecked(null) ? 0 : -1"
                :class="[tileBase, isChecked(null) ? 'border-2 border-accent' : 'border-2 border-border']"
                @click="select(null)"
                @keydown="onKeydown($event, 0)"
            >
                <span class="px-1">{{ t('item.form.cover_none') }}</span>
                <UiIcon v-if="isChecked(null)" name="check" :size="16" class="absolute right-1 top-1 h-4 w-4 text-accent" />
            </button>

            <button
                v-for="(image, index) in images"
                :key="image.ulid"
                :ref="(el) => { tiles[index + 1] = el; }"
                type="button"
                role="radio"
                :aria-checked="isChecked(image.ulid) ? 'true' : 'false'"
                :aria-label="image.filename"
                :tabindex="isChecked(image.ulid) ? 0 : -1"
                :class="[tileBase, isChecked(image.ulid) ? 'border-2 border-accent' : 'border-2 border-border']"
                @click="select(image.ulid)"
                @keydown="onKeydown($event, index + 1)"
            >
                <img
                    v-if="image.hasThumb"
                    :src="`/files/${image.ulid}?variant=thumb`"
                    alt=""
                    class="h-full w-full object-cover"
                >
                <span v-else class="break-all px-1">{{ image.filename }}</span>
                <UiIcon v-if="isChecked(image.ulid)" name="check" :size="16" class="absolute right-1 top-1 h-4 w-4 rounded-full bg-surface text-accent" />
            </button>
        </div>

        <p
            v-if="error"
            id="cover-error"
            tabindex="-1"
            class="text-body text-danger outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2"
        >
            {{ error }}
        </p>
    </div>
</template>
