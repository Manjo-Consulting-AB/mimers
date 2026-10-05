<?php

namespace App\Actions\Inbox;

use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Svarar med användarens inbox-item, och skapar det om det saknas — se
 * [[ADR-0054 Inboxen]] § 1 och 2, och issue 243.
 *
 * **Inboxen är en container med ett enda item, båda dolda.** Containern ägs
 * av användarens personkonto och bär `inbox_user_id`; itemet heter `Inbox`.
 * Användaren ser aldrig någondera, bara *Inbox* — containern filtreras bort
 * ur varje containerlista (App\Models\Container::scopeListable()) och nås av
 * `inbox_user_id` och av ingen annan (App\Actions\Access\ResolveItemScope
 * regel 0).
 *
 * **Skapandet går förbi CreateContainer och CreateItem med flit.** Båda
 * räknar upp containertaket, skriver händelseloggen och prövar policyer, och
 * ingendera gäller en inbox: den räknas inte mot planens tak (ADR-0054 § 3),
 * ingen användare har bett om den, och det finns ingen grant att pröva.
 * Raderna skrivs därför direkt, och `usage_counter` rörs inte.
 *
 * **Det unika indexet på `container.inbox_user_id` är skyddet mot
 * dubbletter** (ADR-0054 § 1). Två samtidiga första anrop kolliderar i
 * stället för att skriva två inboxar: den ena hinner skriva, den andra får
 * en UniqueConstraintViolation och läser den befintliga raden. Containern
 * och itemet skapas i EN transaktion, så en krasch mellan dem lämnar ingen
 * halv inbox — och skulle en äldre rad ändå sakna sitt item, kompletteras
 * den i samma transaktion.
 *
 * **Personkontot slås upp på samma regel som App\Support\Account\
 * ActingAccount** — `type = 'personal'` bland användarens medlemskap, lägst
 * `id` vinner, med första kontot som reserv. Regeln kan inte återanvändas
 * därifrån: `ActingAccount::for()` tar en MÅLcontainer, och inboxens container
 * finns inte förrän kontot är valt. Se PR:ens "Frågor och antaganden".
 */
class ResolveInbox
{
    public function handle(User $user): Item
    {
        $item = $this->existing($user);

        if ($item !== null) {
            return $item;
        }

        try {
            return DB::transaction(function () use ($user): Item {
                $container = $this->containerFor($user) ?? $this->createContainer($user);

                return $this->itemFor($container) ?? $this->createItem($container, $user);
            });
        } catch (UniqueConstraintViolationException) {
            // Den andra förfrågan hann först. Dess rader är committade —
            // kollisionen uppstår först när den andra transaktionen släppt
            // sitt lås — så de går att läsa.
            return $this->existing($user)
                ?? throw new RuntimeException('Inboxen kunde inte läsas efter en krock på det unika indexet.');
        }
    }

    /**
     * Inbox-itemet om både containern och itemet finns, annars null.
     */
    private function existing(User $user): ?Item
    {
        $container = $this->containerFor($user);

        return $container === null ? null : $this->itemFor($container);
    }

    private function containerFor(User $user): ?Container
    {
        return Container::query()->where('inbox_user_id', $user->id)->first();
    }

    private function itemFor(Container $container): ?Item
    {
        return Item::query()->where('container_id', $container->id)->orderBy('id')->first();
    }

    private function createContainer(User $user): Container
    {
        $container = new Container(['name' => 'Inbox']);
        $container->account_id = $this->personalAccountId($user);
        $container->inbox_user_id = $user->id;
        $container->save();

        return $container;
    }

    private function createItem(Container $container, User $user): Item
    {
        $item = new Item(['name' => 'Inbox']);
        $item->container_id = $container->id;
        $item->created_by_user_id = $user->id;
        $item->created_by_account_id = $container->account_id;
        $item->save();

        return $item;
    }

    /**
     * Användarens personkonto. Se klassens docblock om varför regeln står
     * här i stället för i ActingAccount.
     */
    private function personalAccountId(User $user): int
    {
        $personal = $user->accounts()
            ->where('account.type', 'personal')
            ->orderBy('account.id')
            ->first();

        if ($personal !== null) {
            return $personal->id;
        }

        return $user->accounts()->orderBy('account.id')->firstOrFail()->id;
    }
}
