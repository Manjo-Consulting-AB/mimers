<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Template\DeleteTemplate;
use App\Actions\Template\RenameTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Template\UpdateTemplateRequest;
use App\Http\Resources\ContainerTemplateResource;
use App\Models\ContainerTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kontots mallar — listan där de döps om och tas bort, se issue 422 och
 * [[ADR-0089 Egna fält på items]] § Riktning 6.
 *
 * **Mallarna ägs av kontot och bara kontots medlemmar når dem.** Listningen
 * använder samma fråga som `/api`:s index —
 * App\Models\ContainerTemplate::scopeMemberOf() — och uppslagen för omdöpning
 * och borttagning gör detsamma. `{template}` binds därför INTE som en modell:
 * en implicit bindning hade löst upp vilken ULID som helst och läckt att
 * någon annans mall finns. Här blir en mall utanför användarens konton 404,
 * aldrig 403.
 *
 * **Ingen behörighetslogik bor i actionerna.** De är verktyget; frågan ställs
 * här, samma linje som App\Http\Controllers\Api\ContainerTemplateController.
 * Att döpa om och ta bort kräver inget mer än medlemskap — det är samma regel
 * som på `/api` (issue 421 § 4).
 */
class TemplateController extends Controller
{
    /**
     * GET /settings/templates — mallarna i de konton användaren är medlem i,
     * sorterade på namn. Mjukraderade syns inte.
     */
    public function index(Request $request): Response
    {
        $templates = ContainerTemplate::query()
            ->memberOf($request->user())
            ->with('account')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return Inertia::render('Settings/Templates', [
            'templates' => ContainerTemplateResource::collection($templates)->resolve($request),
        ]);
    }

    /**
     * PATCH /settings/templates/{template} — döper om mallen, 302 tillbaka.
     */
    public function update(
        UpdateTemplateRequest $request,
        string $containerTemplate,
        RenameTemplate $renameTemplate,
    ): RedirectResponse {
        $renameTemplate->handle(
            $this->find($request, $containerTemplate),
            (string) $request->validated('name'),
        );

        return back()->with('status', 'template-renamed');
    }

    /**
     * DELETE /settings/templates/{template} — mjuk radering, 302 tillbaka.
     * Mallen försvinner ur väljarna eftersom det globala SoftDeletes-scopet
     * håller den borta från både listningen och `StoreContainerRequest`.
     */
    public function destroy(
        Request $request,
        string $containerTemplate,
        DeleteTemplate $deleteTemplate,
    ): RedirectResponse {
        $deleteTemplate->handle($this->find($request, $containerTemplate));

        return back()->with('status', 'template-deleted');
    }

    /**
     * Mallen med ULID:n, inom användarens konton. En mall utanför dem — eller
     * en mjukraderad — är 404.
     */
    private function find(Request $request, string $ulid): ContainerTemplate
    {
        return ContainerTemplate::query()
            ->memberOf($request->user())
            ->where('ulid', $ulid)
            ->firstOrFail();
    }
}
