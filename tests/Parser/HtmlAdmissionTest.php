<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Lexer\Lexer;
use Forte\Lexer\Tokens\Token;
use Forte\Lexer\Tokens\TokenType;
use Forte\Parser\ParserOptions;

it('keeps bracketed ordinary comments inert in HTML-only mode', function (string $comment): void {
    $source = $comment.'<img src="active">';
    $result = (new Lexer($source, htmlOnly: true))->tokenize();
    $document = Document::parse($source, ParserOptions::make()->htmlOnly());
    $types = array_column($result->tokens, 'type');

    expect($result->errors)->toBeEmpty()
        ->and($types)->toContain(TokenType::CommentStart)
        ->and($types)->not->toContain(TokenType::ConditionalCommentStart, TokenType::ConditionalCommentEnd)
        ->and($document->hasErrors())->toBeFalse()
        ->and($document->getElements())->toHaveCount(1)
        ->and($document->firstElement('img')?->attribute('src')?->valueText())->toBe('active')
        ->and(Token::reconstructFromTokens($result->tokens, $source))->toBe($source);
})->with([
    '<!--[build note]-->',
    '<!--[note]><img src="inert"> -->',
    '<!--[iffy]><img src="inert"> -->',
    '<!--<![build note]-->',
    '<!--<![endifx]><img src="inert"> -->',
]);

it('still exposes genuine unsupported conditional markers for consumer rejection', function (string $source): void {
    $result = (new Lexer($source, htmlOnly: true))->tokenize();

    expect(array_column($result->tokens, 'type'))->toContain(TokenType::ConditionalCommentStart, TokenType::ConditionalCommentEnd)
        ->and(Token::reconstructFromTokens($result->tokens, $source))->toBe($source);
})->with([
    '<!--[if IE]><img src="one"><![endif]-->',
    '<!--[IF mso]><img src="one"><![endif]-->',
    "<!--[if\tmso]><img src=\"one\"><![endif]-->",
]);

it('leaves default Blade comment recognition unchanged', function (): void {
    $comment = (new Lexer('<!--[note]><img src="one"><![endif]-->'))->tokenize();

    expect(array_column($comment->tokens, 'type'))->toContain(TokenType::ConditionalCommentStart, TokenType::ConditionalCommentEnd);
});

it('retains every incremental prefix of the corrected HTML boundaries', function (string $source): void {
    for ($length = 1; $length <= strlen($source); $length++) {
        $prefix = substr($source, 0, $length);
        $result = (new Lexer($prefix, htmlOnly: true, maxTokens: 200))->tokenize();
        expect(Token::reconstructFromTokens($result->tokens, $prefix))->toBe($prefix);
    }
})->with([
    '<!--[build note]--><img src="active">',
    '<!--<![build note]--><img src="active">',
    '<!--[if mso]><img src="active"><![endif]-->',
    '<!--[if !mso]><!--><img src="active"><!--<![endif]-->',
    '<style type="text/css"/><img src="active">',
    '<style>text</STYLE / ><img src="active">',
]);

it('reports self-closing raw-text markup through existing HTML diagnostics', function (string $source): void {
    $result = (new Lexer($source, htmlOnly: true))->tokenize();
    $document = Document::parse($source, ParserOptions::make()->htmlOnly());

    expect($result->errors)->not->toBeEmpty()
        ->and($document->hasErrors())->toBeTrue()
        ->and(Token::reconstructFromTokens($result->tokens, $source))->toBe($source);
})->with([
    '<style/><img src="one">',
    '<textarea/><img src="one">',
    '<script type="text/plain" /><img src="one">',
    '<TITLE / ><img src="one">',
    '<style>.x{}</style/><img src="one">',
    '<style>.x{}</STYLE / ><img src="one">',
    '<textarea>text</textarea/><img src="one">',
]);

it('leaves default Blade raw-text recognition unchanged', function (): void {
    $result = (new Lexer('<textarea/><img src="one">'))->tokenize();

    expect($result->errors)->toBeEmpty()
        ->and(array_column($result->tokens, 'type'))->not->toContain(TokenType::SyntheticClose);
});
