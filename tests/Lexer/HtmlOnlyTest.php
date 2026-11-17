<?php

declare(strict_types=1);

use Forte\Lexer\Lexer;
use Forte\Lexer\Tokens\Token;
use Forte\Lexer\Tokens\TokenType;
use Forte\Parser\Directives\Directives;

it('keeps template-looking HTML text literal without hiding later tags', function (string $text): void {
    $source = '<p>'.$text.'</p><img src="cid:fixture">';
    $result = (new Lexer($source, htmlOnly: true))->tokenize();
    $types = array_column($result->tokens, 'type');

    expect(Token::reconstructFromTokens($result->tokens, $source))->toBe($source)
        ->and($result->errors)->toBeEmpty();

    foreach ([
        TokenType::EchoStart, TokenType::RawEchoStart, TokenType::TripleEchoStart,
        TokenType::BladeCommentStart, TokenType::Directive, TokenType::VerbatimStart,
        TokenType::PhpBlockStart, TokenType::PhpTagStart,
    ] as $type) {
        expect($types)->not->toContain($type);
    }

    $names = array_map(
        fn (array $token): string => Token::text($token, $source),
        array_values(array_filter($result->tokens, fn (array $token): bool => $token['type'] === TokenType::TagName)),
    );

    expect($names)->toContain('img');
})->with([
    'echo' => '{{ $name }}',
    'unfinished echo' => '{{',
    'raw echo' => '{!! $name !!}',
    'triple echo' => '{{{ $name }}}',
    'comment' => '{{-- <img src="cid:inside"> --}}',
    'unfinished comment' => '{{--',
    'directive' => '@if ($ready) content @endif',
    'PHP directive' => '@php content @endphp',
    'verbatim' => '@verbatim content @endverbatim',
    'escaped echo' => '@{{ $name }}',
]);

it('preserves raw text closing boundaries in HTML-only mode', function (string $tag): void {
    $source = '<'.$tag.'>{{ @if ($x) <img src="cid:inert"></'.$tag.'><img src="cid:active">';
    $result = (new Lexer($source, htmlOnly: true))->tokenize();
    $names = array_map(
        fn (array $token): string => Token::text($token, $source),
        array_values(array_filter($result->tokens, fn (array $token): bool => $token['type'] === TokenType::TagName)),
    );

    expect(Token::reconstructFromTokens($result->tokens, $source))->toBe($source)
        ->and($names)->toBe([$tag, $tag, 'img']);
})->with(['style', 'script', 'textarea', 'title']);

it('uses the existing bogus-comment boundary for PHP-looking HTML', function (string $opening): void {
    $source = $opening.' ignored > <img src="cid:active"> ?>';
    $result = (new Lexer($source, htmlOnly: true))->tokenize();
    $types = array_column($result->tokens, 'type');

    expect(Token::reconstructFromTokens($result->tokens, $source))->toBe($source)
        ->and($types)->toContain(TokenType::BogusComment, TokenType::TagName)
        ->and($types)->not->toContain(TokenType::PhpTagStart, TokenType::PhpContent);
})->with(['<?php', '<?=']);

it('keeps conditional markup and ordinary comments in the existing token stream', function (): void {
    $source = '<!-- ordinary <img src="inert"> --><!--[if mso]><img src="cid:active"><![endif]-->';
    $result = (new Lexer($source, htmlOnly: true))->tokenize();
    $types = array_column($result->tokens, 'type');

    expect(Token::reconstructFromTokens($result->tokens, $source))->toBe($source)
        ->and($types)->toContain(TokenType::CommentStart, TokenType::ConditionalCommentStart, TokenType::ConditionalCommentEnd)
        ->and(count(array_filter($types, fn (int $type): bool => $type === TokenType::TagName)))->toBe(1);
});

it('retains incomplete-attribute evidence instead of swallowing it as a template', function (): void {
    $source = '<img alt="{{ unfinished';
    $result = (new Lexer($source, htmlOnly: true))->tokenize();

    expect(Token::reconstructFromTokens($result->tokens, $source))->toBe($source)
        ->and(array_column($result->tokens, 'type'))->toContain(TokenType::SyntheticClose);
});

it('keeps every incremental HTML prefix reconstructable', function (string $source): void {
    for ($length = 1; $length <= strlen($source); $length++) {
        $prefix = substr($source, 0, $length);
        $result = (new Lexer($prefix, htmlOnly: true))->tokenize();

        expect(Token::reconstructFromTokens($result->tokens, $prefix))->toBe($prefix);
    }
})->with([
    '<div data-{{x}}="{{ text"><img src="cid:fixture"></div>',
    '<div :value="@if ($x)" {...attrs} foo=({bar})></div>',
    '<!--[if mso]><img src="cid:fixture"><![endif]-->',
    '<?php x > <img src="cid:fixture"> ?>',
    '<style>{{</style><img src="cid:fixture">',
]);

it('keeps Blade as the default lexer mode', function (): void {
    $source = '@if ($ready){{ $name }}<x-alert :value="$value" />@endif';
    $implicit = (new Lexer($source))->tokenize();
    $explicit = (new Lexer($source, htmlOnly: false))->tokenize();

    expect($implicit->tokens)->toBe($explicit->tokens)
        ->and($implicit->errors)->toEqual($explicit->errors)
        ->and(array_column($implicit->tokens, 'type'))->toContain(TokenType::Directive, TokenType::EchoStart, TokenType::BoundAttribute);
});

it('does not allow supplied directives to restore template parsing in HTML mode', function (): void {
    $source = '@custom (x) {{ text }}';
    $result = (new Lexer($source, Directives::acceptAll(), htmlOnly: true))->tokenize();

    expect(Token::reconstructFromTokens($result->tokens, $source))->toBe($source)
        ->and(array_column($result->tokens, 'type'))->not->toContain(TokenType::Directive, TokenType::EchoStart);
});
