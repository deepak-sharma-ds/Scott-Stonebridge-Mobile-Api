<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The Customer History assembled for one delivery: the text block for the
 * prompt, plus the audit facts recorded on the delivery (which earlier
 * deliveries were included, how many a limit dropped, approximate tokens).
 * The block text itself is never stored.
 */
final readonly class CustomerHistory
{
    /**
     * @param  list<int>  $includedIds  oldest first, as they appear in the block
     */
    public function __construct(
        public string $block = '',
        public array $includedIds = [],
        public int $droppedCount = 0,
        public int $tokens = 0,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /** True when at least one earlier delivery is in the block. */
    public function hasEntries(): bool
    {
        return $this->includedIds !== [];
    }

    /** True when there is anything worth recording (included or dropped entries). */
    public function isAuditable(): bool
    {
        return $this->hasEntries() || $this->droppedCount > 0;
    }

    /**
     * The audit columns for the delivery's update; all null when the customer
     * had no qualifying history.
     *
     * @return array{history_included_ids:?string,history_dropped_count:?int,history_tokens:?int}
     */
    public function auditAttributes(): array
    {
        if (! $this->isAuditable()) {
            return ['history_included_ids' => null, 'history_dropped_count' => null, 'history_tokens' => null];
        }

        return [
            'history_included_ids' => json_encode($this->includedIds),
            'history_dropped_count' => $this->droppedCount,
            'history_tokens' => $this->tokens,
        ];
    }
}
