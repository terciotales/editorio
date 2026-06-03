<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector\Contracts;

interface FeedAdapterInterface
{
    public function supports(string $content_type, string $body): bool;

    public function get_format(): string;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $body, array $source, array $meta = []): array;
}

