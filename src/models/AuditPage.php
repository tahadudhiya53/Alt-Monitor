<?php

namespace Tahadudhiya\AltMonitor\models;

/**
 * One page of a list.
 *
 * @template T
 */
final class AuditPage
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public function pageCount(): int
    {
        return max(1, (int)ceil($this->total / $this->perPage));
    }

    public function first(): int
    {
        return $this->items === [] ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function last(): int
    {
        return $this->first() + count($this->items) - ($this->items === [] ? 0 : 1);
    }
}
