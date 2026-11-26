<?php

namespace App\Support\Template;

/**
 * Mimers egna mallar, lästa ur resources/templates/{locale}.php
 * ([[ADR-0101 Mallarna]] § Beslut 1 och 2). Klienten väljer en mall med dess
 * `key`; servern hämtar innehållet här och tar aldrig emot det.
 *
 * Andra locales än de som har en fil faller tillbaka på `en` — samma tysta
 * reserv som `presetsFor()` hade i klienten.
 */
final class MimersTemplates
{
    public const FALLBACK_LOCALE = 'en';

    /**
     * @return list<MimersTemplate>
     */
    public function all(?string $locale = null): array
    {
        /** @var list<array{key: string, name: string, content: array<string, mixed>, agent_rules?: string|null}> $rows */
        $rows = require $this->path($locale ?? app()->getLocale());

        return array_map(
            fn (array $row): MimersTemplate => new MimersTemplate(
                $row['key'],
                $row['name'],
                $row['content'],
                $row['agent_rules'] ?? null,
            ),
            $rows,
        );
    }

    public function find(string $key, ?string $locale = null): ?MimersTemplate
    {
        foreach ($this->all($locale) as $template) {
            if ($template->key === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Nycklarna, för `Rule::in()`. Nycklarna är desamma i varje språk.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(fn (MimersTemplate $template): string => $template->key, $this->all(self::FALLBACK_LOCALE));
    }

    /**
     * Som Inertia-prop: `key`, `name` och `content` för förhandsvisningen.
     *
     * @return list<array{key: string, name: string, content: array<string, mixed>}>
     */
    public function forProp(?string $locale = null): array
    {
        return array_map(fn (MimersTemplate $template): array => $template->toArray(), $this->all($locale));
    }

    private function path(string $locale): string
    {
        $file = preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]+)?$/', $locale) === 1
            ? resource_path("templates/{$locale}.php")
            : null;

        return $file !== null && is_file($file)
            ? $file
            : resource_path('templates/'.self::FALLBACK_LOCALE.'.php');
    }
}
