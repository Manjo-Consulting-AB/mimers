<?php

namespace App\Support\Help\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * Ritar en faktaruta som `<aside>` — M80 · issue 463,
 * [[ADR-0094 Hjälpcentret]] § 4.
 *
 * Rubrikens ord kommer ur `lang/en/ui.php` och escapas: den är visserligen
 * appens egen text, men renderaren ska inte behöva lita på det. Innehållet är
 * de block som citatet hade, ritade av barnrenderaren.
 */
final class HelpCalloutRenderer implements NodeRendererInterface
{
    /**
     * @param  HelpCallout  $node
     *
     * {@inheritDoc}
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        HelpCallout::assertInstanceOf($node);

        $kind = $node->kind();
        $separator = $childRenderer->getInnerSeparator();
        $title = '<p class="help-callout-title">'.e(__('ui.help.callout.'.$kind)).'</p>';

        return new HtmlElement(
            'aside',
            [
                'class' => 'help-callout help-callout-'.$kind,
                'role' => 'note',
            ],
            $separator.$title.$separator.$childRenderer->renderNodes($node->children()).$separator,
        );
    }
}
