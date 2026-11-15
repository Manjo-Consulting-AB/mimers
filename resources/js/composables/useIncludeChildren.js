import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/*
 * Växeln *Include child items* — läget, vänteläget och navigeringen, delade
 * av flikarna som bär växeln ([[ADR-0068 Itemets sida och skapandet]]
 * § Uppföljning 2026-11-14 punkt 1).
 *
 * **Växeln postar ingen kropp.** Läget är ett värde i adressen: `children=0`
 * när den är av och ingen parameter alls när den är på, så ett avgränsat
 * läge är en adress man kan spara, dela och backa ur (issue 59a § Beslut 1).
 * Servern äger svaret, och fliken speglar `props.includeChildren` med
 * `watch` så att växeln följer servern efter en bakåtknapp eller en
 * omladdning.
 *
 * **Adressen är flikens egen.** `tabUrl()` bygger flikens adress med sitt
 * `?tab=`, och `router.get` lägger parametern ovanpå querysträngen — fliken
 * står kvar och `children=0` läggs till eller utelämnas. Flikradens länkar
 * byggs på annat håll och bär aldrig `children`, så växeln gäller per flik:
 * att stänga av den på *Documents* påverkar inte *Costs*.
 *
 * **Vänteläget stänger kontrollen medan svaret är på väg** (issue 68a
 * § Beslut 4). Flaggan bor här och ritas av `IncludeChildrenSwitch`, så två
 * flikar kan inte svara olika på frågan vad som händer medan servern tänker.
 *
 * `includeChildren` och `tabUrl` tas som getters, inte som värden: proppen
 * lever vidare genom sidans liv och en kopia hade gått isär från serverns
 * svar.
 */
export function useIncludeChildren(includeChildren, tabUrl) {
    /* Växelns läge, speglat ur proppen. */
    const onlyChildren = ref(includeChildren());

    /* Vänteläget för reglaget: en enda kontroll, en enda flagga (issue 68a). */
    const pending = ref(false);

    watch(includeChildren, (value) => {
        onlyChildren.value = value;
    });

    function apply() {
        router.get(tabUrl(), onlyChildren.value ? {} : { children: 0 }, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => { pending.value = true; },
            onFinish: () => { pending.value = false; },
        });
    }

    /*
     * Växlingen vrider läget och skickar det — två steg, som kryssrutan före
     * den gjorde med `v-model` och `@change`. Knappen är avstängd medan
     * svaret är på väg, så ett dubbelklick kan inte vrida läget två gånger.
     */
    function toggle() {
        onlyChildren.value = ! onlyChildren.value;

        apply();
    }

    return { onlyChildren, pending, toggle };
}
