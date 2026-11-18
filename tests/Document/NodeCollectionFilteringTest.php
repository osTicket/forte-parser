<?php

declare(strict_types=1);

namespace Forte\Tests\Document;

use Forte\Ast\Components\ComponentNode;
use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Document\NodeCollection;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Ast\TextNode;
use Forte\Parser\ParserOptions;
use PHPUnit\Framework\TestCase;

class NodeCollectionFilteringTest extends TestCase
{
    /**
     * Type shortcuts retain sparse and named keys and return the original nodes.
     */
    public function test_shortcuts_preserve_keys_and_node_identity(): void
    {
        $collection = $this->collection();
        $original = iterator_to_array($collection);
        $selections = [
            [$collection->directives(), DirectiveNode::class],
            [$collection->blockDirectives(), DirectiveBlockNode::class],
            [$collection->echoes(), EchoNode::class],
            [$collection->elements(), ElementNode::class],
            [$collection->phpBlocks(), PhpBlockNode::class],
            [$collection->text(), TextNode::class],
            [$collection->components(), ComponentNode::class],
        ];

        foreach ($selections as [$selection, $type]) {
            $expected = array_filter($original, fn (Node $node): bool => $node instanceof $type);

            self::assertNotEmpty($expected);
            self::assertSame($expected, iterator_to_array($selection));
            self::assertSame($expected, iterator_to_array($collection->ofType($type)));
        }

        self::assertSame($original, iterator_to_array($collection));
    }

    /**
     * Filtering keeps the concrete collection class, including empty selections.
     */
    public function test_filtering_preserves_subclasses_and_empty_results(): void
    {
        $collection = new FilteredNodeCollection($this->collection()->getIterator());
        $elements = $collection->ofType(ElementNode::class);
        $empty = $elements->ofType(EchoNode::class);

        self::assertInstanceOf(FilteredNodeCollection::class, $elements);
        self::assertInstanceOf(FilteredNodeCollection::class, $empty);
        self::assertNotEmpty($elements);
        self::assertCount(0, $empty);
        self::assertCount(0, $elements->echoes());
        self::assertSame(iterator_to_array($elements), iterator_to_array($elements->elements()));
    }

    /**
     * Build mixed node kinds with keys that cannot be mistaken for list offsets.
     *
     * @return NodeCollection<int|string, Node>
     */
    private function collection(): NodeCollection
    {
        $document = Document::parse(
            '@include("header") @if(true) yes @endif {{ $name }} <div></div> <x-card /> @php $a = 1; @endphp text',
            ParserOptions::make()->withAllDirectives()
        );
        $items = [];

        foreach ($document->getRootNodes() as $index => $node) {
            $key = $index % 2 === 0 ? $index + 10 : 'node-'.$index;
            $items[$key] = $node;
        }

        return new NodeCollection($items);
    }
}

/**
 * Exercise the inherited late-static collection result without overriding filtering.
 *
 * @extends NodeCollection<int|string, Node>
 */
class FilteredNodeCollection extends NodeCollection {}
