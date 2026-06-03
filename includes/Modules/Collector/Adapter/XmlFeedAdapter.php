<?php

declare(strict_types=1);

namespace Editorio\Modules\Collector\Adapter;

use Editorio\Modules\Collector\Contracts\FeedAdapterInterface;
use SimpleXMLElement;

final class XmlFeedAdapter implements FeedAdapterInterface
{
    public function supports(string $content_type, string $body): bool
    {
        $content_type = strtolower($content_type);
        $trimmed = ltrim($body);

        return (str_contains($content_type, 'xml') || str_contains($content_type, 'rss') || str_starts_with($trimmed, '<'))
            && ! str_contains($content_type, 'json');
    }

    public function get_format(): string
    {
        return 'xml';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function parse(string $body, array $source, array $meta = []): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            return [];
        }

        $root = strtolower($xml->getName());

        if ($root === 'feed') {
            return $this->parse_atom($xml, $source, $meta);
        }

        return $this->parse_rss($xml, $source, $meta);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parse_rss(SimpleXMLElement $xml, array $source, array $meta): array
    {
        $channel = isset($xml->channel) ? $xml->channel : $xml;
        $items = [];

        foreach ($channel->item as $item) {
            $ht_ns = $item->children('ht', true);

            $title = trim((string) $item->title);
            $link = trim((string) $item->link);
            $guid = trim((string) $item->guid);
            $summary = trim((string) $item->description);
            $published_at = trim((string) $item->pubDate);

            $news_items = [];
            if (isset($ht_ns->news_item)) {
                foreach ($ht_ns->news_item as $news_item) {
                    $news_title = trim((string) $news_item->children('ht', true)->news_item_title);
                    $news_url = trim((string) $news_item->children('ht', true)->news_item_url);
                    $news_snippet = trim((string) $news_item->children('ht', true)->news_item_snippet);

                    $news_items[] = [
                        'external_id' => $news_url !== '' ? $news_url : hash('sha256', $title . '|' . $news_title),
                        'title' => $news_title !== '' ? $news_title : $title,
                        'content_url' => $news_url !== '' ? $news_url : $link,
                        'summary' => $news_snippet !== '' ? $news_snippet : $summary,
                        'published_at' => $published_at,
                        'raw_payload' => [
                            'title' => $news_title !== '' ? $news_title : $title,
                            'summary' => $news_snippet !== '' ? $news_snippet : $summary,
                        ],
                    ];
                }
            }

            if ($news_items !== []) {
                $items = array_merge($items, $news_items);
                continue;
            }

            $items[] = [
                'external_id' => $guid !== '' ? $guid : $link,
                'title' => $title,
                'content_url' => $link,
                'summary' => $summary,
                'published_at' => $published_at,
                'raw_payload' => [
                    'title' => $title,
                    'summary' => $summary,
                ],
            ];
        }

        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parse_atom(SimpleXMLElement $xml, array $source, array $meta): array
    {
        $items = [];

        foreach ($xml->entry as $entry) {
            $title = trim((string) $entry->title);
            $summary = trim((string) $entry->summary);
            $published_at = trim((string) $entry->published);
            if ($published_at === '') {
                $published_at = trim((string) $entry->updated);
            }

            $link = '';
            foreach ($entry->link as $link_node) {
                $rel = trim((string) ($link_node['rel'] ?? ''));
                if ($rel === '' || $rel === 'alternate') {
                    $link = trim((string) ($link_node['href'] ?? ''));
                    break;
                }
            }

            $external_id = trim((string) $entry->id);
            if ($external_id === '') {
                $external_id = $link;
            }

            $items[] = [
                'external_id' => $external_id,
                'title' => $title,
                'content_url' => $link,
                'summary' => $summary,
                'published_at' => $published_at,
                'raw_payload' => [
                    'title' => $title,
                    'summary' => $summary,
                ],
            ];
        }

        return $items;
    }
}
