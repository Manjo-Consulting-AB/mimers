<?php

namespace App\Support\Help\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * Ritar en video som en ruta med en länk — M80 · issue 463,
 * [[ADR-0094 Hjälpcentret]] § 4.
 *
 * Ingen `iframe` och ingen bild från YouTube: `data-youtube-id` och den vanliga
 * länken är allt som ritas. Utan JavaScript är rutan en fungerande länk, och
 * 464 gör den till en spelknapp som först vid klick laddar
 * `youtube-nocookie.com`. Länktexten är markdownens egen och ritas av
 * barnrenderaren, så fetstil och kursiv fungerar som i en vanlig länk.
 */
final class HelpVideoRenderer implements NodeRendererInterface
{
    /**
     * @param  HelpVideo  $node
     *
     * {@inheritDoc}
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        HelpVideo::assertInstanceOf($node);

        $id = $node->videoId();
        $separator = $childRenderer->getInnerSeparator();

        $start = new HtmlElement(
            'a',
            [
                'href' => 'https://www.youtube.com/watch?v='.$id,
                'class' => 'help-video-start',
            ],
            $childRenderer->renderNodes($node->children()),
        );

        return new HtmlElement(
            'figure',
            [
                'class' => 'help-video',
                'data-youtube-id' => $id,
            ],
            $separator.$start.$separator,
        );
    }
}
