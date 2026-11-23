<?php

namespace App\Actions\Help;

use Illuminate\Support\Str;
use League\CommonMark\Extension\Table\TableExtension;

/**
 * Läser hjälpsidans texter ur en katalog med markdownfiler — M78 · issue 458.
 *
 * En fil är en kategori: första raden `# Kategori`, text fram till första
 * `##` är kategorins ingress, och varje `## Fråga` inleder en fråga vars svar
 * är texten fram till nästa `##`. Filerna har inga kodblock, så en rad som
 * börjar med `## ` är alltid en fråga. Filerna sorteras på filnamnet, och
 * `slug` är namnet utan `NN-` och `.md`.
 *
 * **Markdown ritas på servern** med `html_input: strip`, så `<!-- video -->` och
 * all annan rå HTML försvinner, och med `allow_unsafe_links: false`, så en
 * `javascript:`-adress aldrig blir en `href`. Samma princip som
 * App\Actions\Item\RenderItemNotes, [[ADR-0066 Itemets anteckning]] § 2.
 * `TableExtension` behövs för pristabellen. Ingen cache: det är nio små filer.
 */
class LoadHelpArticles
{
    /**
     * @return list<array{slug: string, title: string, intro_html: ?string, questions: list<array{anchor: string, question: string, answer_html: string}>}>
     */
    public function handle(string $directory): array
    {
        $files = glob(rtrim($directory, '/').'/*.md') ?: [];
        sort($files);

        $categories = [];

        foreach ($files as $file) {
            $categories[] = $this->category($file);
        }

        return $categories;
    }

    /**
     * @return array{slug: string, title: string, intro_html: ?string, questions: list<array{anchor: string, question: string, answer_html: string}>}
     */
    private function category(string $file): array
    {
        $slug = preg_replace('/^\d+-/', '', basename($file, '.md')) ?? basename($file, '.md');
        $lines = preg_split('/\R/', (string) file_get_contents($file)) ?: [];

        $title = $slug;
        $intro = [];
        $blocks = [];
        $current = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, '## ')) {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = ['question' => trim(substr($line, 3)), 'body' => []];
            } elseif ($current === null && str_starts_with($line, '# ')) {
                $title = trim(substr($line, 2));
            } elseif ($current === null) {
                $intro[] = $line;
            } else {
                $current['body'][] = $line;
            }
        }

        if ($current !== null) {
            $blocks[] = $current;
        }

        $questions = [];

        foreach ($blocks as $block) {
            $questions[] = [
                'anchor' => Str::slug($block['question']),
                'question' => $block['question'],
                'answer_html' => $this->render(implode("\n", $block['body'])) ?? '',
            ];
        }

        return [
            'slug' => $slug,
            'title' => $title,
            'intro_html' => $this->render(implode("\n", $intro)),
            'questions' => $questions,
        ];
    }

    private function render(string $markdown): ?string
    {
        if (trim($markdown) === '') {
            return null;
        }

        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ], [new TableExtension]);
    }
}
