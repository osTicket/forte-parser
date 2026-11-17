<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Exceptions\ParserException;
use Forte\Exceptions\TokenLimitExceeded;
use Forte\Parser\ParserOptions;

it('leaves token budgets unset by default', function (): void {
    expect(ParserOptions::make()->getTokenLimit())->toBeNull()
        ->and(ParserOptions::defaults()->getTokenLimit())->toBeNull();
});

it('merges only explicit token budgets', function (?int $initial, ?int $incoming, ?int $expected): void {
    $options = ParserOptions::make();
    $other = ParserOptions::defaults();
    if ($initial !== null) {
        $options->tokenLimit($initial);
    }
    if ($incoming !== null) {
        $other->tokenLimit($incoming);
    }

    expect($options->merge($other))->toBe($options)
        ->and($options->getTokenLimit())->toBe($expected)
        ->and($other->getTokenLimit())->toBe($incoming);
})->with([
    [null, null, null],
    [null, 65_536, 65_536],
    [65_536, null, 65_536],
    [65_536, 32_768, 32_768],
    [32_768, 65_536, 65_536],
]);

it('rejects invalid option budgets without changing the previous value', function (int $limit): void {
    $options = ParserOptions::make()->tokenLimit(10);
    expect(fn () => $options->tokenLimit($limit))->toThrow(InvalidArgumentException::class)
        ->and($options->getTokenLimit())->toBe(10);
})->with([[0], [-1]]);

it('keeps the budget independent from mode and depth configuration', function (): void {
    $options = ParserOptions::make()->htmlOnly()->tokenLimit(3)->depthLimits(elements: 12);
    $options->merge(ParserOptions::make()->htmlOnly(false)->depthLimits(elements: 15));

    expect($options->isHtmlOnly())->toBeFalse()
        ->and($options->getTokenLimit())->toBe(3)
        ->and($options->getDepthLimits()['elements'])->toBe(15)
        ->and(fn () => Document::parse('<br><br>', $options))->toThrow(TokenLimitExceeded::class);
});

it('forwards the budget before Document tree construction in either mode', function (bool $htmlOnly): void {
    $source = '<br>';
    $unbounded = Document::parse($source, ParserOptions::make()->htmlOnly($htmlOnly));
    $options = ParserOptions::make()->htmlOnly($htmlOnly)->tokenLimit(3);
    $bounded = Document::parse($source, $options);

    expect($bounded->getTokens())->toBe($unbounded->getTokens())
        ->and($bounded->render())->toBe($source)
        ->and(fn () => Document::parse('<br><br>', $options))->toThrow(TokenLimitExceeded::class);
})->with([[false], [true]]);

it('preserves Blade token interpretation when an adequate budget is supplied', function (): void {
    $source = '@if ($ready){{ $name }}<x-alert :value="$name" />@endif';
    $original = Document::parse($source);
    $bounded = Document::parse($source, ParserOptions::make()->tokenLimit(count($original->getTokenRecords())));

    expect($bounded->getTokens())->toBe($original->getTokens())
        ->and($bounded->render())->toBe($original->render())
        ->and($bounded->findComponentByName('x-alert')?->isComponent())->toBeTrue();
});

it('reports the token budget and byte offset through the existing parser exception family', function (): void {
    $exception = new TokenLimitExceeded(3, 4);
    expect($exception)->toBeInstanceOf(ParserException::class)
        ->and($exception->limit)->toBe(3)
        ->and($exception->offset)->toBe(4)
        ->and($exception->getMessage())->toBe('Maximum token count (3) exceeded at byte offset 4.');
});
