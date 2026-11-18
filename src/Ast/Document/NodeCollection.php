<?php

declare(strict_types=1);

namespace Forte\Ast\Document;

use Forte\Ast\BladeCommentNode;
use Forte\Ast\Components\ComponentNode;
use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\CommentNode;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Ast\TextNode;
use Illuminate\Support\Collection;

/**
 * @template TKey of array-key
 * @template TValue of Node
 *
 * @extends Collection<TKey, TValue>
 */
class NodeCollection extends Collection
{
    /**
     * Filter the collection to nodes that are instances of the given class.
     *
     * @template TNode of Node
     *
     * @param  class-string<TNode>  $className
     * @return static<TKey, TNode>
     */
    public function ofType(string $className): static
    {
        return $this->whereInstanceOf($className);
    }

    /**
     * Get a collection of directive nodes.
     *
     * @return static<TKey, DirectiveNode>
     */
    public function directives(): static
    {
        return $this->ofType(DirectiveNode::class);
    }

    /**
     * Get a collection of block directive nodes.
     *
     * @return static<TKey, DirectiveBlockNode>
     */
    public function blockDirectives(): static
    {
        return $this->ofType(DirectiveBlockNode::class);
    }

    /**
     * Get a collection of echo nodes.
     *
     * @return static<TKey, EchoNode>
     */
    public function echoes(): static
    {
        return $this->ofType(EchoNode::class);
    }

    /**
     * Get a collection of element nodes.
     *
     * @return static<TKey, ElementNode>
     */
    public function elements(): static
    {
        return $this->ofType(ElementNode::class);
    }

    /**
     * Get a collection of comment nodes.
     */
    public function comments(): static
    {
        return $this->filter(fn ($n) => $n instanceof CommentNode || $n instanceof BladeCommentNode);
    }

    /**
     * Get a collection of PHP block nodes.
     *
     * @return static<TKey, PhpBlockNode>
     */
    public function phpBlocks(): static
    {
        return $this->ofType(PhpBlockNode::class);
    }

    /**
     * Get a collection of text nodes.
     *
     * @return static<TKey, TextNode>
     */
    public function text(): static
    {
        return $this->ofType(TextNode::class);
    }

    /**
     * Get a collection of component nodes.
     *
     * @return static<TKey, ComponentNode>
     */
    public function components(): static
    {
        return $this->ofType(ComponentNode::class);
    }

    /**
     * Filter nodes that cover the given 1-based line number.
     *
     * @param  int  $line  1-based line number
     */
    public function onLine(int $line): static
    {
        return $this->filter(function (Node $node) use ($line) {
            $start = $node->startLine();
            $end = $node->endLine();

            return $start <= $line && $end >= $line;
        });
    }

    /**
     * Filter nodes that start on the given 1-based line number.
     *
     * @param  int  $line  1-based line number
     */
    public function startingOnLine(int $line): static
    {
        return $this->filter(fn (Node $n) => $n->startLine() === $line);
    }

    /**
     * Filter nodes that end on the given 1-based line number.
     *
     * @param  int  $line  1-based line number
     */
    public function endingOnLine(int $line): static
    {
        return $this->filter(fn (Node $n) => $n->endLine() === $line);
    }

    /**
     * Filter nodes whose character range contains the given offset.
     *
     * @param  int  $offset  Zero-based offset
     */
    public function containingOffset(int $offset): static
    {
        return $this->filter(function (Node $n) use ($offset) {
            $start = $n->startOffset();
            $end = $n->endOffset();

            return $start <= $offset && $offset < $end;
        });
    }

    /**
     * Filter nodes fully contained between the given start and end offsets.
     *
     * @param  int  $startOffset  Zero-based start offset (inclusive)
     * @param  int  $endOffset  Zero-based end offset (exclusive)
     */
    public function betweenOffsets(int $startOffset, int $endOffset): static
    {
        return $this->filter(fn (Node $n) => $n->startOffset() >= $startOffset
            && $n->endOffset() <= $endOffset);
    }

    /**
     * Get a collection of only root nodes (nodes without parents).
     */
    public function roots(): static
    {
        return $this->filter(fn (Node $n) => $n->isRoot());
    }

    /**
     * Get a collection of leaf nodes (nodes without children).
     */
    public function leaves(): static
    {
        return $this->filter(fn (Node $n) => ! $n->hasChildren());
    }

    /**
     * Get a collection of nodes that have children.
     */
    public function withChildren(): static
    {
        return $this->filter(fn (Node $n) => $n->hasChildren());
    }

    /**
     * Get a collection of directives with the provided name.
     *
     * @param  string  $name  Directive name, without @.
     */
    public function whereDirectiveName(string $name): static
    {
        return $this->filter(fn ($n) => ($n instanceof DirectiveNode || $n instanceof DirectiveBlockNode) && $n->is($name));
    }

    /**
     * Get a collection of elements that match the provided tag name.
     *
     * @param  string  $tag  Tag name to match
     */
    public function whereElementIs(string $tag): static
    {
        return $this->filter(fn ($n) => $n instanceof ElementNode
            && ! $n instanceof ComponentNode
            && $n->is($tag));
    }

    /**
     * Get all items in the collection as a re-indexed array.
     *
     * @return array<int, TValue>
     */
    public function all(): array
    {
        return array_values(parent::all());
    }

    /**
     * Render all nodes in the collection and concatenate the output.
     */
    public function render(): string
    {
        return $this->map(fn (Node $n) => $n->render())->implode('');
    }
}
