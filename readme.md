# Forte Parser

Forte Parser is osTicket's maintained fork of [Forte](https://github.com/fortephp/forte), originally created by John Koster. It provides a Laravel Blade parser and AST toolkit, with opt-in HTML-only parsing and configurable token and depth limits.

The Composer package is `osticket/forte-parser`. The PHP namespace remains `Forte\`, and Blade parsing remains the default. Existing parser APIs and Laravel integration are preserved. The fork's HTML-only extensions reuse the same lexer, parser and document model.

## Installation

Install from the maintained repository using a release that declares the new package name:

```sh
composer config repositories.forte-parser '{"type":"vcs","url":"https://github.com/osTicket/forte-parser","only":["osticket/forte-parser"]}'
composer require osticket/forte-parser
```

The first release under the new name is planned as `1.2.2`. Existing `v1.2.0` and `v1.2.1` tags retain their original `fortephp/forte` identity. The renamed package cannot be installed alongside `fortephp/forte`, because both expose the same PHP classes. When migrating, replace the old requirement and update any package-name-based configuration together; namespace imports do not change.

### Parser-only use in Laravel

The package continues to advertise `Forte\ServiceProvider` for applications using its Laravel integration. Applications using only the parser should disable discovery by the new Composer name, preserving any other exclusions:

```json
{
    "extra": {
        "laravel": {
            "dont-discover": [
                "osticket/forte-parser"
            ]
        }
    }
}
```

Rebuild Laravel's package manifest after changing this setting. Parser-only consumers can use `Forte\Ast\Document\Document` directly without registering the provider.

## HTML-only parsing

Use HTML-only mode when template-looking text must remain literal. It does not execute Blade or PHP, and it does not replace HTML sanitization or application authorization. Set resource limits appropriate to the input:

```php
use Forte\Ast\Document\Document;
use Forte\Parser\ParserOptions;

$options = ParserOptions::make()
    ->htmlOnly()
    ->tokenLimit(10000)
    ->depthLimits(elements: 128);

$document = Document::parse('<p>{{ literal }}</p><img src="cid:example">', $options);
```

The maintained extensions cover literal template syntax, conditional comments, HTML raw-text boundaries and bounded tokenization. Blade mode remains available through the existing options and entry points. Applications remain responsible for validating the parsed document and deciding which content and image sources they accept.

## Quick Start

```php
use Forte\Facades\Forte;
use Forte\Rewriting\NodePath;

// Parse a Blade template into a document
$doc = Forte::parse('<div class="mt-4">Hello, {{ $name }}!</div>');

// Query with XPath
$divs = $doc->xpath('//div[@class]')->get();

// Rewrite (returns a new document, original is unchanged)
$newDoc = $doc->rewriteWith(function (NodePath $path) {
    if ($path->isTag('div')) {
        $path->removeClass('mt-4');
        $path->addClass('container');
    }
});

echo $newDoc->render(); // <div class="container">Hello, {{ $name }}!</div>
```

## Core Features

### Parsing

Forte's three-phase pipeline (lexer, tree builder, document) handles valid and malformed input alike, recovering gracefully with diagnostics.

```php
$doc = Forte::parse($bladeTemplate);
$doc = Forte::parseFile('resources/views/welcome.blade.php');
```

### Immutable Documents

Every mutation returns a new `Document` instance. Safe to hold references, chain transformations, and compare before/after states.

### Tree Traversal and Querying

Walk the tree, find nodes by predicate, or query with XPath 1.0. Blade constructs map to namespaced elements (`forte:if`, `forte:echo`) for XPath queries.

```php
use Forte\Ast\TraversalOptions;

// Lazy, fluent collections with optional name filters
$forms = $doc->queryElements('form');
$conditionals = $doc->queryBlockDirectives(['if', 'unless']);
$components = $doc->queryComponents(['x-alert', 'livewire:*']);

// Fast exact lookups and convenient existence checks
$navigation = $doc->elementById('primary-navigation');
$hasHead = $doc->hasElement('head');

// Attribute semantics live on Attribute; ElementNode provides null-safe proxies
$form = $doc->firstElement('form');
$method = $form?->attribute('method');
$isDynamic = $method?->isDynamic() ?? false;
$staticMethod = $form?->staticAttributeValueLower('method');
$classes = $form?->attributeTokens('class') ?? [];

// Navigate relationships without rebuilding ancestor/descendant loops
$owner = $navigation?->closestElement('header');
$links = $navigation?->descendantElements('a');

// Include attribute/tag-name internals when a deep semantic scan is needed
$allEchoes = $doc->allEchoes(TraversalOptions::deep());

// XPath
$doc->xpath('//forte:if')->get();
```

The original `getElements()`, `findElementsByName()`, magic collection properties,
and other released query methods remain available. The `query*` methods add
Laravel-style lazy collections without changing those existing contracts.

### Rewriting

The visitor pattern with queued operations prevents excessive intermediate documents. Use `NodePath` for all mutations, including CSS class helpers, attribute manipulation, and structural changes.

```php
$doc->rewriteWith(function (NodePath $path) {
    if ($path->isTag('center')) {
        $path->renameTag('div');
        $path->addClass('text-center');
    }
});
```

### Components and Directives

First-class support for Blade components (`<x-alert>`), slots (`<x-slot:header>`), and all directive types (standalone `@csrf`, blocks `@if...@endif`).

```php
$component = $doc->findComponentByName('x-alert');
$directive = $doc->find(fn ($n) => $n->isDirectiveNamed('foreach'));
```

### Node Builders

Construct synthetic nodes for replacements and insertions with a fluent API.

```php
use Forte\Rewriting\Builders\Builder;

Builder::element('div')->class('wrapper')->text('Hello');
Builder::directive('if', '($show)');
Builder::echo('$name');
```

## Maintenance and upstream

Report issues with this maintained fork at [osTicket/forte-parser](https://github.com/osTicket/forte-parser/issues). Releases and source are maintained in that repository. General fixes can still be contributed to [upstream Forte](https://github.com/fortephp/forte); this fork releases its extensions independently.

[Upstream documentation](https://fortephp.com) describes the original Blade parser and AST APIs. Fork-specific behavior and installation are documented here.

## License

Forte Parser retains Forte's [MIT license](license.md) and original copyright notice. Upstream authorship and contribution history are preserved.
