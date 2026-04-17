<?php

declare(strict_types=1);

namespace Editorio\Modules\AI\Contracts;

interface AIProviderInterface
{
    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     */
    public function generate(array $messages, array $options = []): string;
}

