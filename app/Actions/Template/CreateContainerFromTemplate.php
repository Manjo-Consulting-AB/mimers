<?php

namespace App\Actions\Template;

use App\Actions\Container\CreateContainer;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skapar en container ur en mall — eller ur ett innehåll i samma form, som
 * frontendens inbyggda förslag — se [[ADR-0089 Egna fält på items]]
 * § Riktning 6 och issue 421 § 3.
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

    /**
     * @param  array<string, mixed>|null  $content
     */
    public function handle(
        User $creator,
        Account $account,
        string $name,
        ?string $kind,
        ?string $description,
        ?string $icon,
        ?string $iconColor,
        ?ContainerTemplate $template,
        ?array $content,
    ): Container {
        return DB::transaction(function () use ($creator, $account, $name, $kind, $description, $icon, $iconColor, $template, $content): Container {
            $container = $this->createContainer->handle(
                $creator,
                $account,
                $name,
                $kind,
                $description,
                $icon,
                $iconColor,
            );

            $this->applyTemplate->handle($container, $template, $content, $creator);

            return $container;
        });
    }
}
