<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector\Adapter;

use Editorio\Modules\Collector\Contracts\FeedAdapterInterface;

final class JsonFeedAdapter implements FeedAdapterInterface
{
    public function supports(string $content_type, string $body): bool
    {
        $content_type = strtolower($content_type);
        $trimmed = ltrim($body);

        return str_contains($content_type, 'json') || str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
    }

    public function get_format(): string
    {
        return 'json';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $body, array $source, array $meta = []): array
    {
        $data = json_decode($body, true);
        if (! is_array($data)) {
            return [];
        }

        $items = $data['items'] ?? [];
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized[] = [
                'external_id' => (string) ($item['id'] ?? $item['url'] ?? $item['external_url'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
                'content_url' => (string) ($item['url'] ?? $item['external_url'] ?? ''),
                'summary' => (string) ($item['summary'] ?? $item['content_text'] ?? ''),
                'published_at' => (string) ($item['date_published'] ?? $item['date_modified'] ?? ''),
                'raw_payload' => [
                    'title' => (string) ($item['title'] ?? ''),
                    'summary' => (string) ($item['summary'] ?? $item['content_text'] ?? ''),
                ],
            ];
        }

        return $normalized;
    }
}
