<?php

declare(strict_types=1);

use Forte\Exceptions\TokenLimitExceeded;
use Forte\Extensions\AbstractExtension;
use Forte\Internal\TokenRecord;
use Forte\Lexer\Extension\LexerContext;
use Forte\Lexer\Lexer;
use Forte\Lexer\Tokens\TokenType;
use Forte\Lexer\Tokens\TokenTypeRegistry;
use PHPUnit\Framework\Assert;

it('allows exactly the configured number of tokens in either lexer path', function (bool $compact, bool $htmlOnly): void {
    $source = '<br>';
    $expected = (new Lexer($source, htmlOnly: $htmlOnly))->tokenize()->tokens;
    $lexer = new Lexer($source, htmlOnly: $htmlOnly, maxTokens: count($expected));
    $tokens = $compact
        ? array_map(fn (TokenRecord $token): array => $token->toArray(), $lexer->tokenizeCompact()['tokens'])
        : $lexer->tokenize()->tokens;

    expect($tokens)->toBe($expected);
})->with([[false], [true]])->with([[false], [true]]);

it('stops before scanning the remaining input when the token budget is exhausted', function (bool $compact): void {
    $source = str_repeat('<br>', 2000);
    $lexer = new Lexer($source, htmlOnly: true, maxTokens: 3);

    try {
        $compact ? $lexer->tokenizeCompact() : $lexer->tokenize();
        Assert::fail('The next token must exceed the configured budget.');
    } catch (TokenLimitExceeded $exception) {
        expect($exception->limit)->toBe(3)
            ->and($exception->offset)->toBe(4)
            ->and($lexer->position())->toBe(4);
    }
})->with([[false], [true]]);

it('retains the same failure even after a scanner reaches the end of input', function (bool $compact): void {
    $source = '<br>remaining text';
    $lexer = new Lexer($source, htmlOnly: true, maxTokens: 3);
    $failure = null;

    try {
        $compact ? $lexer->tokenizeCompact() : $lexer->tokenize();
        Assert::fail('The final text token must exceed the configured budget.');
    } catch (TokenLimitExceeded $exception) {
        $failure = $exception;
        expect($exception->offset)->toBe(4)
            ->and($lexer->position())->toBe(strlen($source));
    }

    foreach ([false, true] as $retryCompact) {
        try {
            $retryCompact ? $lexer->tokenizeCompact() : $lexer->tokenize();
            Assert::fail('A failed lexer must not return its accumulated prefix.');
        } catch (TokenLimitExceeded $exception) {
            expect($exception)->toBe($failure);
        }
    }

    try {
        $lexer->emitToken(TokenType::Text, 0, 0);
        Assert::fail('Direct emission must preserve the terminal failure.');
    } catch (TokenLimitExceeded $exception) {
        expect($exception)->toBe($failure);
    }
})->with([[false], [true]]);

it('counts synthetic recovery tokens in the same budget', function (): void {
    $source = '<div';
    $expected = (new Lexer($source, htmlOnly: true))->tokenize()->tokens;
    expect(array_column($expected, 'type'))->toContain(TokenType::SyntheticClose);

    $accepted = new Lexer($source, htmlOnly: true, maxTokens: count($expected));
    expect($accepted->tokenize()->tokens)->toBe($expected);

    $rejected = new Lexer($source, htmlOnly: true, maxTokens: count($expected) - 1);
    expect(fn () => $rejected->tokenize())->toThrow(TokenLimitExceeded::class);
});

it('accepts empty input and preserves successful repeated tokenization', function (): void {
    $lexer = new Lexer('', maxTokens: 1);
    expect($lexer->tokenize()->tokens)->toBeEmpty()
        ->and($lexer->tokenizeCompact()['tokens'])->toBeEmpty();

    $lexer = new Lexer('<br>', maxTokens: 3);
    $first = $lexer->tokenize()->tokens;
    expect($lexer->tokenize()->tokens)->toBe($first);
});

it('rejects non-positive direct lexer budgets', function (int $limit): void {
    expect(fn () => new Lexer('<br>', maxTokens: $limit))->toThrow(InvalidArgumentException::class);
})->with([[0], [-1]]);

it('stops extension dispatch at the first budget failure despite trailing input', function (bool $catchFailure, bool $handledAfterFailure, bool $compact): void {
    $extension = new class($catchFailure, $handledAfterFailure) extends AbstractExtension
    {
        public int $calls = 0;

        public function __construct(
            private readonly bool $catchFailure,
            private readonly bool $handledAfterFailure,
        ) {}

        public function id(): string
        {
            return 'token-budget-probe';
        }

        public function triggerCharacters(): string
        {
            return '#';
        }

        protected function doTokenize(LexerContext $ctx): bool
        {
            $this->calls++;
            if ($ctx->current() !== '#') {
                return false;
            }

            try {
                $ctx->emit(TokenType::Text, $ctx->position(), $ctx->position() + 1);
            } catch (TokenLimitExceeded $exception) {
                if (! $this->catchFailure) {
                    throw $exception;
                }
                $ctx->advance(1);

                return $this->handledAfterFailure;
            }
            $ctx->advance(1);

            return true;
        }

        protected function registerTypes(TokenTypeRegistry $registry): void {}
    };

    $accepted = new Lexer('##', maxTokens: 2);
    $accepted->registerExtension($extension, new TokenTypeRegistry);
    expect($accepted->tokenize()->tokens)->toHaveCount(2);

    $extension->calls = 0;
    $rejected = new Lexer(str_repeat('#', 32), maxTokens: 1);
    $rejected->registerExtension($extension, new TokenTypeRegistry);
    try {
        $compact ? $rejected->tokenizeCompact() : $rejected->tokenize();
        Assert::fail('The first rejected emission must terminate parsing.');
    } catch (TokenLimitExceeded $exception) {
        expect($exception->limit)->toBe(1)
            ->and($exception->offset)->toBe(1)
            ->and($rejected->position())->toBe($catchFailure ? 2 : 1)
            ->and($extension->calls)->toBe(2);
    }
})->with([[false, true], [true, true], [true, false]])->with([[false], [true]]);
