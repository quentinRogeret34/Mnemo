<?php

namespace App\Pagination;

/**
 * One page of a list, with what the pager needs to link the others.
 *
 * @template T
 */
final readonly class Page
{
    public int $pages;

    /**
     * @param array<array-key, T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $number,
        public int $size,
    ) {
        $this->pages = max(1, (int) ceil($total / $size));
    }

    /**
     * @param list<array<string, mixed>> $rows each one carries the size of the whole list in "total"
     *
     * @return self<array<string, mixed>>
     */
    public static function ofRows(array $rows, int $number, int $size): self
    {
        return new self($rows, (int) ($rows[0]['total'] ?? 0), $number, $size);
    }

    public static function offset(int $number, int $size): int
    {
        return (max(1, $number) - 1) * $size;
    }
}
