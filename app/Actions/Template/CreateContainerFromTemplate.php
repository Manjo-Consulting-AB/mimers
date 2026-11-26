<?php

namespace App\Actions\Template;

use App\Actions\Container\CreateContainer;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Models\User;
use App\Support\Template\MimersTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Skapar en container ur en upplöst mall — användarens sparade
 * (App\Models\ContainerTemplate) eller en av Mimers
 * (App\Support\Template\MimersTemplate) — se [[ADR-0089 Egna fält på items]]
 * § Riktning 6 och [[ADR-0101 Mallarna]]. Innehållet kommer aldrig från
 * klienten; anroparen löser upp mallen på ULID eller nyckel.
 *
 * **Genom App\Actions\Container\CreateContainer, i SAMMA transaktion.**
 * Containertaket, förbrukningsräknaren, `container.created` och ägarrelationen
 * bor där och ska inte formuleras om. Kategorierna och fälten läggs in efter
 * containern medan transaktionen är öppen: en container utan sitt innehåll
 * vore en halv mall, och ett kast mitt i får därför containern att försvinna
 * med den (Laravel rullar tillbaka hela den yttre transaktionen).
 *
 * **Ingen `Gate::authorize()`.** Anroparen prövar
 * `ContainerPolicy::create()` för kontot, precis som för en vanlig container.
 */
final class CreateContainerFromTemplate
{
    public function __construct(
        private readonly CreateContainer $createContainer,
        private readonly ApplyTemplate $applyTemplate,
    ) {}

    public function handle(
        User $creator,
        Account $account,
        string $name,
        ?string $kind,
        ?string $description,
        ?string $icon,
        ?string $iconColor,
        ContainerTemplate|MimersTemplate $template,
    ): Container {
        return DB::transaction(function () use ($creator, $account, $name, $kind, $description, $icon, $iconColor, $template): Container {
            $container = $this->createContainer->handle(
                $creator,
                $account,
                $name,
                $kind,
                $description,
                $icon,
                $iconColor,
            );

            $this->applyTemplate->handle(
                $container,
                $template instanceof ContainerTemplate ? $template : null,
                $template instanceof MimersTemplate ? $template->content : null,
                $creator,
            );

            return $container;
        });
    }
}
