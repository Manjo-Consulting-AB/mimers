<?php

namespace App\Support\Notification;

use App\Models\Container;
use App\Models\Item;
use App\Models\Loan;
use App\Models\Notification;
use App\Models\ScheduleOccurrence;

/**
 * Adressen en notisrad pekar på, eller `null` när det inte finns någon sida.
 *
 * **Ett enda uppslag.** Klassen flyttades hit ur HandleInertiaRequests i
 * issue 383 ([[ADR-0077 Notislistan i appen]] § 3): klockans lista och
 * `in_app`-listan ska peka på samma ställe, och två kopior av `match`-satsen
 * hade glidit isär den dag en typ fick ett mål. HandleInertiaRequests och
 * App\Actions\Notification\ListInAppNotifications använder båda den här
 * klassen; ingen av dem stavar en rutt själv.
 *
 * **Bara de tre typer som har ett mål länkar** (issue 127 § Beslut). En
 * kvotvarning, en inaktivitetsvarning och ett raderat containerbesked gäller
 * KONTOT eller något som inte finns kvar, och ritas som text. Adressen byggs
 * här och inte i JavaScript: den bär två ULID:n som bara servern känner
 * (issue 51 § Beslut 7).
 *
 * Klassen är en injicerbar stödklass, samma form som
 * App\Support\Notification\NotificationPreferences.
 */
final class NotificationTarget
{
    /**
     * Adressen en rad pekar på, eller `null` när det inte finns någon sida.
     */
    public function for(Notification $notification): ?string
    {
        return match ($notification->type) {
            Notification::TYPE_TASK_DUE, Notification::TYPE_TASK_OVERDUE => $this->taskTarget($notification),
            Notification::TYPE_LOAN_DUE => $this->loanTarget($notification),
            Notification::TYPE_TRANSFER_REQUESTED => route('transfers.index', [], false),
            // Issue 406 § 4: notisen om en agents radering länkar till
            // papperskorgen, där hela tillfället kan återställas
            // ([[ADR-0087 MCP-server för agenter]] Riktning 8 och 14).
            Notification::TYPE_AGENT_DELETED => route('trash.index', [], false),
            // Issue 409 och uppföljningen: åtkomsten är kvar och gäller igen
            // när ägaren uppgraderar, så raden leder till sidan där kontot ser
            // och hanterar sina anslutna agenter ([[ADR-0087 MCP-server för
            // agenter]] Riktning 11 och 12).
            Notification::TYPE_AGENT_ACCESS_LOST => route('settings.agents', [], false),
            // Issue 412 · [[ADR-0088 Versionshistorik för items]] Riktning 3:
            // ägaren får veta att lagringen är full och ska kunna göra något
            // åt det, så raden länkar till lagringsytan. Sidan väljer konto
            // själv (valfritt `?account=`), så ingen ULID behövs.
            Notification::TYPE_VERSIONING_SKIPPED => route('settings.storage', [], false),
            // Issue 1457 · [[Planer och kvoter]] § Nedgradering: raden säger
            // vad som händer i steg 4, och planvyn är ytan där kontot kan
            // uppgradera eller se fristen.
            Notification::TYPE_ACCOUNT_DOWNGRADE_WARNING => route('settings.plan', [], false),
            // Issue 1518 · [[ADR-0091 PDF-pärmen]]: pärmen hämtas på
            // containerns exportsida, där raden också visar status.
            Notification::TYPE_BINDER_READY => $this->binderTarget($notification),
            // Issue 1701 · [[ADR-0098 Kontoexporten]]: filen hämtas från
            // exportlistan på profilen. Sidan och dess rutt byggs i issue 507
            // och finns inte än, så raden saknar länk tills dess.
            Notification::TYPE_ACCOUNT_EXPORT_READY => null,
            // Issue 1529: ägaren ser på delningssidan vem som finns kvar.
            Notification::TYPE_ACCESS_LEFT => $this->leftTarget($notification),
            default => null,
        };
    }

    /**
     * Containerns delningssida, eller `null` när containern inte längre finns.
     */
    private function leftTarget(Notification $notification): ?string
    {
        $container = $notification->container;

        return $container === null
            ? null
            : route('containers.sharing', [$container], false);
    }

    /**
     * Pärmen till containerns exportsida, eller `null` när containern inte
     * längre finns (mjukraderad faller bort genom SoftDeletes).
     */
    private function binderTarget(Notification $notification): ?string
    {
        $container = $notification->container;

        return $container === null
            ? null
            : route('containers.export', [$container], false);
    }

    /**
     * Uppgiften till sitt item: förekomsten är radens subject, och vägen går
     * genom schemat.
     */
    private function taskTarget(Notification $notification): ?string
    {
        $occurrence = $notification->subject;

        return $occurrence instanceof ScheduleOccurrence
            ? $this->itemTarget($occurrence->schedule?->item, $notification->container)
            : null;
    }

    /**
     * Lånet till sitt item: lånet är radens subject (issue 127 § Beslut).
     */
    private function loanTarget(Notification $notification): ?string
    {
        $loan = $notification->subject;

        return $loan instanceof Loan
            ? $this->itemTarget($loan->item, $notification->container)
            : null;
    }

    /**
     * En itemväg, eller `null` när målet inte längre finns.
     *
     * Ett mjukraderat item — eller en container i papperskorgen — faller bort
     * genom SoftDeletes' globala scope och ger `null`: raden blir en text i
     * stället för en länk till en 404. Samma svar som händelseloggen ger en
     * gallrad rad, och av samma skäl.
     */
    private function itemTarget(?Item $item, ?Container $container): ?string
    {
        if ($item === null || $container === null) {
            return null;
        }

        return route('containers.items.show', [$container, $item], false);
    }
}
