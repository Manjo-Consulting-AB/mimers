import { ref } from 'vue';

/*
 * Felet från bilden vid skapandet (issue 341 · [[ADR-0068 Itemets sida och
 * skapandet]] § 11).
 *
 * Bilden laddas upp när itemet redan finns, och ett nekat anrop (till exempel
 * med `quota.storage_exceeded`) ska synas på itemets Documents-flik. Formuläret
 * har då hunnit avmonteras, så felet bärs av en modulnivå-ref som både
 * ItemForm och Show läser. `item` är itemets ULID, så att felet bara ritas på
 * det item det gäller; `message` är serverns mening ur fältfelet, eller null
 * när anropet inte gav något (nätverksfel).
 */
const failure = ref(null);

export function useCreationUpload() {
    return { failure };
}
