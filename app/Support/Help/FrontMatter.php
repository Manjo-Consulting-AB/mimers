<?php

namespace App\Support\Help;

/**
 * Delar en markdownfil i front matter och kropp — M80 · issue 460.
 *
 * Hjälpbiblioteket läser filerna och valideringen prövar dem, och båda måste
 * hitta front mattern på exakt samma sätt. Regeln bor därför här och ingen
 * annanstans, så de två inte kan glida isär.
 */
final class FrontMatter
{
    private const PATTERN = '/\A---\R(.*?)\R---\R?(.*)\z/s';

    /**
     * Front mattern och kroppen, eller null när filen saknar front matter.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function split(string $content): ?array
    {
        if (preg_match(self::PATTERN, $content, $matches) !== 1) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }
}
