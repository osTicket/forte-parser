<?php

declare(strict_types=1);

namespace Forte\Exceptions;

/**
 * Report a token emission that would exceed the configured parser budget.
 *
 * Only the budget and source offset are retained; source content is not
 * included in the diagnostic. No partial parse result may be returned.
 */
final class TokenLimitExceeded extends ParserException
{
    /**
     * Identify the rejected emission without retaining its source text.
     *
     * @param  int  $limit  Maximum number of permitted token records.
     * @param  int  $offset  Starting byte offset of the rejected token.
     */
    public function __construct(
        public readonly int $limit,
        public readonly int $offset,
    ) {
        parent::__construct(sprintf(
            'Maximum token count (%d) exceeded at byte offset %d.',
            $limit,
            $offset,
        ));
    }
}
