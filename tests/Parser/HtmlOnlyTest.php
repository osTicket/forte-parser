<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Lexer\Tokens\TokenType;
use Forte\Parser\ParserOptions;
use Forte\Rewriting\NodePath;
use PHPUnit\Framework\Assert;

it('preserves literal template-looking attribute values', function (string $value, string $quote): void {
    $source = '<div title='.$quote.$value.$quote.'><img src="cid:fixture"></div>';
    $document = Document::parse($source, ParserOptions::make()->htmlOnly());
    $attribute = $document->firstElement('div')?->attribute('title');

    expect($attribute)->not->toBeNull()
        ->and($attribute?->valueText())->toBe($value)
        ->and($attribute?->isStatic())->toBeTrue()
        ->and($attribute?->value()?->isSimple())->toBeTrue()
        ->and($document->render())->toBe($source)
        ->and($document->firstElement('img'))->not->toBeNull();
})->with([
    ['{{ x }}', '"'], ['{{', '"'], ['{!! x !!}', "'"], ['@if($x)', '"'],
    ['<?php x ?>', '"'], ['{value}', ''], ['({value})', ''], ['{{x}}', ''], ['@if(x)', ''],
]);

it('keeps literal attribute names in the existing ordered inventory', function (string $name): void {
    $source = '<div '.$name.'="value"></div>';
    $document = Document::parse($source, ParserOptions::make()->htmlOnly());
    $attributes = $document->firstElement('div')?->attributes()->all() ?? [];

    expect($attributes)->toHaveCount(1)
        ->and($attributes[0]->nameText())->toBe($name)
        ->and($attributes[0]->isStatic())->toBeTrue()
        ->and($document->render())->toBe($source);
})->with(['{value}', '({value})', '{{x}}', '@foo(x)', 'data-{{x}}', ':src', '::src', ':$src', '@click.prevent', '...props']);

it('exposes duplicate source attributes and their original value positions', function (string $opening, string $closing): void {
    $source = $opening."<IMG SRC = 'cid:first' src=\"cid:second\">".$closing;
    $document = Document::parse($source, ParserOptions::make()->htmlOnly());
    $attributes = $document->firstElement('IMG')?->attributes()->all() ?? [];

    expect($attributes)->toHaveCount(2)
        ->and(array_map(fn ($attribute): string => $attribute->nameText(), $attributes))->toBe(['SRC', 'src']);

    foreach ($attributes as $attribute) {
        $value = $attribute->value();
        expect($value)->not->toBeNull();
        if ($value === null) {
            throw new LogicException('Expected a quoted source value.');
        }
        $position = $value->jsonSerialize();
        Assert::assertIsArray($position['start']);
        Assert::assertIsArray($position['end']);
        $start = $position['start']['offset'];
        $end = $position['end']['offset'];
        Assert::assertIsInt($start);
        Assert::assertIsInt($end);
        expect(substr($source, $start, $end - $start))->toBe($value->quote().$attribute->valueText().$value->quote());
    }

    expect($document->render())->toBe($source);
})->with([
    ['', ''],
    ['<!--[if mso]>', '<![endif]-->'],
    ['<!--[if gte mso 9]>', '<![endif]-->'],
    ['<!--[if !mso]><!-->', '<!--<![endif]-->'],
]);

it('does not hide real images enclosed by literal braces', function (): void {
    $source = '<p>{{ text <img src="cid:fixture"> }}</p>';
    $document = Document::parse($source, ParserOptions::make()->htmlOnly());

    expect($document->hasErrors())->toBeFalse()
        ->and($document->firstElement('img')?->attribute('src')?->valueText())->toBe('cid:fixture')
        ->and($document->render())->toBe($source);
});

it('keeps custom element names ordinary through existing document rewrites', function (string $name): void {
    $source = '<'.$name.' title="{{ literal }}">{{ text }}</'.$name.'>';
    $document = Document::parse($source, ParserOptions::make()->htmlOnly());
    $rewritten = $document->rewriteWith(function (NodePath $path) use ($name): void {
        if ($path->isTag($name)) {
            $path->setAttribute('role', 'status');
        }
    });
    // Rewrites materialize attribute changes in rendered source. Inspect that
    // output through the existing parser rather than synthetic attribute nodes.
    expect($rewritten->firstElement($name)?->isComponent())->toBeFalse()
        ->and($rewritten->render())->toBe('<'.$name.' title="{{ literal }}" role="status">{{ text }}</'.$name.'>');
    $element = Document::parse($rewritten->render(), ParserOptions::make()->htmlOnly())->firstElement($name);

    expect($element)->toBeInstanceOf(ElementNode::class)
        ->and($element?->isComponent())->toBeFalse()
        ->and($element?->attribute('title')?->valueText())->toBe('{{ literal }}')
        ->and($element?->attribute('role')?->valueText())->toBe('status')
        ->and($rewritten->render())->toContain('{{ text }}');
})->with(['x-alert', 'livewire:alert', 'flux:alert']);

it('preserves explicit mode selection when merging options', function (): void {
    $options = ParserOptions::defaults()->htmlOnly();
    $options->merge(ParserOptions::defaults());
    expect($options->isHtmlOnly())->toBeTrue();

    $options->merge(ParserOptions::make()->htmlOnly(false));
    expect($options->isHtmlOnly())->toBeFalse()
        ->and(ParserOptions::defaults()->isHtmlOnly())->toBeFalse();
});

it('keeps configured registries intact when returning to Blade mode', function (): void {
    $options = ParserOptions::defaults()->withAllDirectives()->withComponentPrefix('custom:')->htmlOnly();

    expect($options->getDirectives()->acceptsAllDirectives())->toBeFalse()
        ->and($options->getComponentManager()->getPrefixes())->toBeEmpty();

    $options->htmlOnly(false);
    expect($options->getDirectives()->acceptsAllDirectives())->toBeTrue()
        ->and($options->getComponentManager()->getPrefixes())->toContain('x-', 'livewire:', 'flux:', 'custom:');

    $document = Document::parse('@unknown {{ $name }}<custom:alert />', $options);
    expect(array_column($document->getTokenRecords(), 'type'))->toContain(TokenType::Directive, TokenType::EchoStart)
        ->and($document->findComponentByName('custom:alert')?->isComponent())->toBeTrue();
});

it('preserves literal tag identities without hiding images', function (string $name): void {
    $source = '<'.$name.'><img src="cid:visible"></'.$name.'>';
    $document = Document::parse($source, ParserOptions::make()->htmlOnly());

    expect($document->firstElement($name)?->tagNameText())->toBe($name)
        ->and($document->firstElement($name)?->attributes()->all())->toBeEmpty()
        ->and($document->firstElement('img')?->attribute('src')?->valueText())->toBe('cid:visible')
        ->and($document->hasErrors())->toBeFalse()
        ->and(array_column($document->getTokenRecords(), 'type'))->not->toContain(TokenType::SyntheticClose)
        ->and($document->render())->toBe($source);
})->with(['style{{literal}}', 'script{!!literal!!}', 'textarea{{literal}}', 'title{{literal}}', 'img{{literal}}', 'custom-{{literal}}']);

it('separates unquoted values at every HTML whitespace boundary', function (string $separator): void {
    $source = '<img title=hello'.$separator.'src="cid:first" src="cid:second">';
    $document = Document::parse($source, ParserOptions::make()->htmlOnly());
    $attributes = $document->firstElement('img')?->attributes()->all() ?? [];

    expect(array_map(fn ($attribute): string => $attribute->nameText(), $attributes))->toBe(['title', 'src', 'src'])
        ->and(array_map(fn ($attribute): ?string => $attribute->valueText(), $attributes))->toBe(['hello', 'cid:first', 'cid:second'])
        ->and($document->hasErrors())->toBeFalse()
        ->and($document->render())->toBe($source);
})->with([' ', "\t", "\n", "\r", "\f"]);

it('preserves component registration while HTML-only parsing is active', function (bool $merge): void {
    $options = ParserOptions::make()->htmlOnly();
    if ($merge) {
        $options->merge(ParserOptions::make()->withComponentPrefix('custom:')->htmlOnly());
    } else {
        $options->withComponentPrefix('custom:');
    }
    $source = '<custom:alert />';

    expect(Document::parse($source, $options)->firstElement('custom:alert')?->isComponent())->toBeFalse()
        ->and($options->getComponentManager()->getPrefixes())->toBeEmpty();

    $options->htmlOnly(false);
    expect($options->getComponentManager()->getPrefixes())->toContain('custom:')
        ->and(Document::parse($source, $options)->findComponentByName('custom:alert')?->isComponent())->toBeTrue();

    $options->htmlOnly();
    expect(Document::parse($source, $options)->firstElement('custom:alert')?->isComponent())->toBeFalse();
})->with([[false], [true]]);

it('preserves both component managers when a merge enables HTML-only parsing', function (): void {
    $options = ParserOptions::defaults()->withComponentPrefix('first:');
    $options->merge(ParserOptions::defaults()->withComponentPrefix('second:')->htmlOnly());

    expect($options->isHtmlOnly())->toBeTrue()
        ->and($options->getComponentManager()->getPrefixes())->toBeEmpty();

    $options->htmlOnly(false);
    expect($options->getComponentManager()->getPrefixes())->toContain('first:', 'second:', 'x-', 'livewire:', 'flux:');
});
