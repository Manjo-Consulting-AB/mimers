<?php

namespace App\Actions\Access;

use App\Actions\Notification\NotifyAccessLeft;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;

/**
 * Mottagaren lämnar en container själv (issue 1529 · M75): ALLA hennes
 * giltiga rader på containern återkallas — den container-breda och raderna
 * för enskilda items — och ägaren får `access.left` i klockan.
 *
 * Varje rad går genom RevokeContainerAccess med personen själv som `$actor`,
 * så loggraden `access.revoked` får `user_id` = mottagaren och
 * betalningsflytten (ADR-0081 § 4) sker som vid en vanlig återkallelse.
 * `agent_level` sitter på raden, så hennes agenter tappar containern i samma
 * skrivning.
 *
 * **Ingen `Gate::authorize()`.** Anroparen prövar
 * `ContainerPolicy::leave()`. **Ingen retur:** en andra körning hittar inga
 * giltiga rader, skriver ingen logg och skapar ingen notis.
 */
class LeaveContainer
{
    public function __construct(
        private readonly RevokeContainerAccess $revokeContainerAccess,
        private readonly NotifyAccessLeft $notifyAccessLeft,
    ) {}

    public function handle(User $user, Container $container): void
    {
        $rows = ContainerAccess::query()
            ->where('container_id', $container->id)
            ->where('grantee_type', 'user')
            ->where('grantee_id', $user->id)
            ->valid()
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $row) {
            $this->revokeContainerAccess->handle($user, $container, $row);
        }

        $this->notifyAccessLeft->handle($container, $user);
    }

    /**
     * Har personen lämnat (eller förlorat) sin åtkomst tidigare? Då är ett
     * andra anrop inget fel utan en no-op, medan en person som aldrig haft
     * någon rad får 403. Ägarkontots medlemmar räknas aldrig.
     */
    public function hasLeft(User $user, Container $container): bool
    {
        if ($container->account->users()->whereKey($user->id)->exists()) {
            return false;
        }

        return ContainerAccess::query()
            ->where('container_id', $container->id)
            ->where('grantee_type', 'user')
            ->where('grantee_id', $user->id)
            ->exists();
    }
}
