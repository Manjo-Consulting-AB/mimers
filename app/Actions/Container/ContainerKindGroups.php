<?php

namespace App\Actions\Container;

use App\Models\Container;
use Illuminate\Support\Collection;

/**
 * Grupperingsregeln ur [[ADR-0036 Containerns art]], på ett ställe.
 *
 * **En art med minst två containrar får en egen grupp, resten ligger i en
 * hög.** Utan regeln får den som äger en båt, ett hus och en bil tre rubriker
 * med ett objekt under varje, och en navigering där varje rubrik bär ett
 * objekt ser ut som ett fel i programmet.
 *
 * **Klassen finns för att två listningar behöver samma regel.** Dashboardens
 * kort (App\Actions\Container\ListContainerSummaries, issue 124) och skalets
 * sidopanel (App\Actions\Container\ListShellContainers, issue 169) grupperar
 * containrarna på samma sätt men bär olika rader — korten har tal och bild,
 * panelen har namn och ULID. Hade regeln stått i båda hade de kunnat glida
 * isär, och en container hade hamnat under en rubrik i den ena ytan och i
 * högen i den andra utan att något prov blev rött.
 *
 * **Raden byggs av anroparen**, genom `$row`: den här filen känner bara till
 * `kind` och ordningen. Ingen `ContainerResource` och ingen form på raden
 * bor här — de två ytorna svarar med var sin form, och det är hela skälet att
 * de är två listningar.
 *
 * **Ordningen är högen först, sedan arterna i bokstavsordning.** Högen ritas
 * först som i mockupen (`docs/Design/main.jpeg`), och inom varje grupp står
 * raderna i frågans ordning — namn stigande — så att en container står på
 * samma plats i gruppen som i listan.
 *
 * **`kind` i svaret är `null` för högen och artens sträng för de andra.**
 * Fältet är fritt ([[ADR-0036 Containerns art]]) och har ingen
 * översättningsnyckel: vyn skriver arten ordagrant och formulerar själv vad
 * högen heter. En tom sträng från äldre data är inget värde att gruppera på
 * och hamnar därför i högen.
 */
final class ContainerKindGroups
{
    /**
     * @param  Collection<int, Container>  $containers  i frågans ordning
     * @param  callable(Container): array<string, mixed>  $row
     * @return list<array{kind: string|null, containers: list<array<string, mixed>>}>
     */
    public static function byKind(Collection $containers, callable $row): array
    {
        $antalPerArt = [];

        foreach ($containers as $container) {
            // En NÄRVAROKONTROLL och ingen jämförelse: fältet är fritt, och
            // regeln om att ingen grenar på VILKEN art det är står i
            // App\Models\Container:s klasskommentar (issue 84).
            if (! $container->kind) {
                continue;
            }

            $antalPerArt[$container->kind] = ($antalPerArt[$container->kind] ?? 0) + 1;
        }

        /** @var list<string> $egnaArter */
        $egnaArter = array_keys(array_filter($antalPerArt, fn (int $antal): bool => $antal >= 2));
        sort($egnaArter);

        $hog = [];
        $perArt = [];

        foreach ($containers as $container) {
            if (in_array($container->kind, $egnaArter, true)) {
                $perArt[$container->kind][] = $row($container);

                continue;
            }

            $hog[] = $row($container);
        }

        $grupper = [];

        if ($hog !== []) {
            $grupper[] = ['kind' => null, 'containers' => $hog];
        }

        foreach ($egnaArter as $art) {
            $grupper[] = ['kind' => $art, 'containers' => $perArt[$art]];
        }

        return $grupper;
    }
}
