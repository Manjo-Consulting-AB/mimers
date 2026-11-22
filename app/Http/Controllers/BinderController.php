<?php

namespace App\Http\Controllers;

use App\Actions\Security\RecordSecurityEvent;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\StoreBinderRequest;
use App\Jobs\BuildContainerExport;
use App\Models\Container;
use App\Models\Export;
use App\Models\Item;
use App\Models\SecurityLog;
use App\Support\Frontend\ApiErrorTranslator;
use App\Support\Plan\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Beställningen av en PDF-pärm på exportsidan, se issue 446 ·
 * [[ADR-0091 PDF-pärmen]]. Sidan och listan ägs av ExportController; den här
 * kontrollern har en enda metod.
 *
 * Grinden är `view` på containern, som exporten — och dessutom planen:
 * `pdf_binder` är en Pro-funktion, och en gratiscontainer nekas med
 * `plan.feature_unavailable` (403) även om någon postar förbi vyn.
 *
 * **Pärmen om ett item** (issue 447): `storeForItem()` är samma beställning
 * för ett enda item. Grinden är `view` på itemet (som i sin tur kräver att
 * beställaren når itemet) plus planens `pdf_binder`. Svaret går tillbaka till
 * itemets sida, där dialogen visar att pärmen skapas och länkar till
 * exportsidan; där står raden som *PDF binder · {item}*.
 *
 * Raden skapas av ExportController::createExport(), så dubblettspärren
 * (`export.already_running`, per container och beställare) är densamma för ZIP
 * och pärm och blir samma formulärfel på nyckeln `export`.
 */
class BinderController extends Controller
{
    public function store(
        StoreBinderRequest $request,
        Container $container,
        ExportController $exports,
        Entitlements $entitlements,
        ApiErrorTranslator $translator,
        RecordSecurityEvent $recordSecurityEvent,
    ): RedirectResponse {
        Gate::authorize('view', $container);

        $container->loadMissing('account');
        $entitlements->assertFeature($container->account, 'pdf_binder');

        $this->order($request, $container, null, $exports, $translator, $recordSecurityEvent);

        return redirect()
            ->route('containers.export', $container)
            ->with('status', 'export-requested');
    }

    public function storeForItem(
        StoreBinderRequest $request,
        Container $container,
        Item $item,
        ExportController $exports,
        Entitlements $entitlements,
        ApiErrorTranslator $translator,
        RecordSecurityEvent $recordSecurityEvent,
    ): RedirectResponse {
        Gate::authorize('view', $item);

        $container->loadMissing('account');
        $entitlements->assertFeature($container->account, 'pdf_binder');

        $this->order($request, $container, $item, $exports, $translator, $recordSecurityEvent);

        return back()->with('status', 'export-requested');
    }

    /**
     * Skapar raden, loggar och köar bygget. Dubblettspärren blir ett
     * formulärfel på nyckeln `export`.
     */
    private function order(
        StoreBinderRequest $request,
        Container $container,
        ?Item $item,
        ExportController $exports,
        ApiErrorTranslator $translator,
        RecordSecurityEvent $recordSecurityEvent,
    ): void {
        try {
            $export = $exports->createExport($request, $container, Export::KIND_BINDER, $request->options(), $item);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['export' => $translator->message($e)]);
        }

        $recordSecurityEvent->handle(
            action: SecurityLog::ACTION_EXPORT_REQUESTED,
            account: $container->account,
            user: $request->user(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            meta: ['export' => $export->ulid],
        );

        BuildContainerExport::dispatch($export);
    }
}
