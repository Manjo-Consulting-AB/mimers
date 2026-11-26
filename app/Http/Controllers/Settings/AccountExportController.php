<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Export\RequestAccountExport;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Export;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Kontoexportens webbyta på profilsidan — issue 1702 ·
 * [[ADR-0098 Kontoexporten]]. Listan ritas av Settings\ProfileController;
 * här finns bara de två skrivande rutterna. Nedladdningen går genom den
 * befintliga `/exports/{export}/download`.
 *
 * **Objektet är personen själv.** Ingen kontoparameter och ingen policy: en
 * export syns, hämtas och raderas bara av den som beställde den
 * (`requested_by_user_id`). Takten sitter på rutten (`throttle:account-export`,
 * tre per dygn).
 */
class AccountExportController extends Controller
{
    /**
     * POST /settings/account-export — köar en kontoexport, 302 tillbaka till
     * profilen. En pågående export ger ett formulärfel på nyckeln `export`.
     */
    public function store(Request $request, RequestAccountExport $requestExport, ApiErrorTranslator $translator): RedirectResponse
    {
        try {
            $requestExport->handle($request->user(), $request->ip(), $request->userAgent());
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['export' => $translator->message($e)]);
        }

        return redirect()
            ->route('settings.profile')
            ->with('status', 'account-export-requested');
    }

    /**
     * DELETE /settings/account-exports/{export} — tar bort en egen kontoexport
     * och dess fil. En annans export, eller en containerexport, är 404. En
     * export som packas raderas inte: jobbet skriver filen efter oss.
     */
    public function destroy(Request $request, Export $export, ApiErrorTranslator $translator): RedirectResponse
    {
        abort_unless(
            $export->kind === Export::KIND_ACCOUNT
                && (int) $export->requested_by_user_id === (int) $request->user()->id,
            404,
        );

        try {
            DB::transaction(function () use ($export): void {
                $locked = Export::query()->whereKey($export->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    return;
                }

                if (in_array($locked->status, [Export::STATUS_PENDING, Export::STATUS_RUNNING], true)) {
                    throw ApiException::make('export.still_running', ['export' => $locked->ulid], 422);
                }

                if ($locked->storage_path !== null) {
                    Storage::disk('files')->delete($locked->storage_path);
                }

                $locked->delete();
            });
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['export' => $translator->message($e)]);
        }

        return redirect()
            ->route('settings.profile')
            ->with('status', 'account-export-deleted');
    }
}
