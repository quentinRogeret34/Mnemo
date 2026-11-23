<?php

namespace App\Notion;

use App\Connector\CredentialResolver;
use App\Entity\Connector;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the pages an internal Notion integration was given access to, through the REST API.
 * Sharing a page with the integration shares its sub-pages too: that is what scopes the import.
 */
final class NotionClient
{
    private const VERSION = '2022-06-28';
    private const MAX_PAGES = 500;
    private const MAX_BLOCKS = 2000;
    private const MAX_DEPTH = 6;
    private const HEADINGS = ['heading_1' => '##', 'heading_2' => '###', 'heading_3' => '####'];
    private const LIST_ITEMS = ['bulleted_list_item', 'numbered_list_item', 'to_do', 'toggle'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CredentialResolver $credentials,
    ) {
    }

    public function describe(Connector $connector): string
    {
        $bot = $this->request($connector, 'GET', 'users/me');

        return trim('Notion '.($bot['bot']['workspace_name'] ?? '').' · '.($bot['name'] ?? ''), ' ·');
    }

    /**
     * Pages edited since the given time, most recently edited first. Archived pages are left out.
     *
     * @return iterable<array{id: string, title: string, url: string, edited: string}>
     */
    public function pages(Connector $connector, ?string $since = null, int $limit = self::MAX_PAGES): iterable
    {
        $cursor = null;
        $count = 0;
        do {
            $page = $this->request($connector, 'POST', 'search', ['json' => array_filter([
                'filter' => ['property' => 'object', 'value' => 'page'],
                'sort' => ['direction' => 'descending', 'timestamp' => 'last_edited_time'],
                'page_size' => 100,
                'start_cursor' => $cursor,
            ])]);
            foreach ($page['results'] ?? [] as $result) {
                // Notion rounds the edit time to the minute: a page edited in the same minute as the cursor is read again.
                if (null !== $since && $result['last_edited_time'] < $since) {
                    return;
                }
                if (($result['archived'] ?? false) || ($result['in_trash'] ?? false)) {
                    continue;
                }
                yield ['id' => $result['id'], 'title' => $this->title($result), 'url' => $result['url'] ?? '', 'edited' => $result['last_edited_time']];
                if (++$count >= $limit) {
                    return;
                }
            }
            $cursor = ($page['has_more'] ?? false) ? $page['next_cursor'] : null;
        } while (null !== $cursor);
    }

    public function markdown(Connector $connector, string $pageId): string
    {
        $blocks = 0;

        return trim($this->render($connector, $pageId, 0, $blocks));
    }

    private function render(Connector $connector, string $blockId, int $depth, int &$blocks): string
    {
        $markdown = '';
        $previous = null;
        $number = 0;
        foreach ($this->children($connector, $blockId, $blocks) as $block) {
            $type = $block['type'];
            $number = 'numbered_list_item' === $type ? ($previous === $type ? $number + 1 : 1) : 0;
            $text = $this->block($connector, $block, $depth, $number, $blocks);
            if ('' === $text) {
                continue;
            }
            $tight = \in_array($type, self::LIST_ITEMS, true) && \in_array($previous, self::LIST_ITEMS, true);
            $markdown .= ('' === $markdown ? '' : ($tight ? "\n" : "\n\n")).$text;
            $previous = $type;
        }

        return $markdown;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function block(Connector $connector, array $block, int $depth, int $number, int &$blocks): string
    {
        $type = $block['type'];
        $data = $block[$type] ?? [];
        $text = $this->richText($data['rich_text'] ?? []);
        $children = ($block['has_children'] ?? false) && $depth < self::MAX_DEPTH && !\in_array($type, ['child_page', 'child_database', 'table'], true)
            ? $this->render($connector, $block['id'], $depth + 1, $blocks)
            : '';

        $line = match ($type) {
            'paragraph' => $text,
            'heading_1', 'heading_2', 'heading_3' => self::HEADINGS[$type].' '.$text,
            'bulleted_list_item', 'toggle' => '- '.$text,
            'numbered_list_item' => $number.'. '.$text,
            'to_do' => '- ['.(($data['checked'] ?? false) ? 'x' : ' ').'] '.$text,
            'quote' => '> '.$text,
            'callout' => '> '.trim(($data['icon']['emoji'] ?? '').' '.$text),
            'code' => '```'.($data['language'] ?? '')."\n".$this->plainText($data['rich_text'] ?? [])."\n```",
            'equation' => '$$'.($data['expression'] ?? '').'$$',
            'divider' => '---',
            'child_page', 'child_database' => '**'.($data['title'] ?? '').'**',
            'bookmark', 'embed', 'link_preview' => isset($data['url']) ? '<'.$data['url'].'>' : '',
            'image', 'file', 'pdf', 'video' => $this->file($data),
            'table' => $this->table($connector, $block, $blocks),
            default => '',
        };

        if ('' === $children) {
            return $line;
        }
        if (\in_array($type, self::LIST_ITEMS, true) || \in_array($type, ['quote', 'callout'], true)) {
            $prefix = \in_array($type, ['quote', 'callout'], true) ? '> ' : '  ';

            return $line."\n".preg_replace('/^/m', $prefix, $children);
        }

        return '' === $line ? $children : $line."\n\n".$children;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function table(Connector $connector, array $block, int &$blocks): string
    {
        $rows = [];
        foreach ($this->children($connector, $block['id'], $blocks) as $row) {
            $rows[] = '| '.implode(' | ', array_map(fn (array $cell): string => str_replace('|', '\|', $this->richText($cell)), $row['table_row']['cells'] ?? [])).' |';
        }
        if ([] === $rows) {
            return '';
        }
        $columns = (int) ($block['table']['table_width'] ?? 1);
        array_splice($rows, 1, 0, ['|'.str_repeat(' --- |', max(1, $columns))]);

        return implode("\n", $rows);
    }

    /**
     * Files hosted by Notion come with a link that expires within the hour: only the caption is kept.
     *
     * @param array<string, mixed> $data
     */
    private function file(array $data): string
    {
        $caption = $this->plainText($data['caption'] ?? []);
        $url = $data['external']['url'] ?? null;

        return null === $url ? ('' === $caption ? '' : '_'.$caption.'_') : '['.('' === $caption ? $url : $caption).']('.$url.')';
    }

    /**
     * @param list<array<string, mixed>> $richText
     */
    private function richText(array $richText): string
    {
        return implode('', array_map(static function (array $part): string {
            $text = $part['plain_text'] ?? '';
            if ('' === trim($text)) {
                return $text;
            }
            $annotations = $part['annotations'] ?? [];
            if ($annotations['code'] ?? false) {
                $text = '`'.$text.'`';
            }
            if ($annotations['bold'] ?? false) {
                $text = '**'.$text.'**';
            }
            if ($annotations['italic'] ?? false) {
                $text = '_'.$text.'_';
            }
            if ($annotations['strikethrough'] ?? false) {
                $text = '~~'.$text.'~~';
            }

            return isset($part['href']) ? '['.$text.']('.$part['href'].')' : $text;
        }, $richText));
    }

    /**
     * @param list<array<string, mixed>> $richText
     */
    private function plainText(array $richText): string
    {
        return implode('', array_column($richText, 'plain_text'));
    }

    /**
     * @param array<string, mixed> $page
     */
    private function title(array $page): string
    {
        foreach ($page['properties'] ?? [] as $property) {
            if ('title' === ($property['type'] ?? null)) {
                $title = trim($this->plainText($property['title'] ?? []));

                return '' === $title ? 'Sans titre' : $title;
            }
        }

        return 'Sans titre';
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function children(Connector $connector, string $blockId, int &$blocks): iterable
    {
        $cursor = null;
        do {
            $page = $this->request($connector, 'GET', 'blocks/'.$blockId.'/children', ['query' => array_filter(['page_size' => 100, 'start_cursor' => $cursor])]);
            foreach ($page['results'] ?? [] as $block) {
                if (++$blocks > self::MAX_BLOCKS) {
                    return;
                }
                yield $block;
            }
            $cursor = ($page['has_more'] ?? false) ? $page['next_cursor'] : null;
        } while (null !== $cursor);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(Connector $connector, string $method, string $path, array $options = []): array
    {
        // Notion allows three requests per second on average and answers 429 with Retry-After beyond.
        $client = new RetryableHttpClient($this->httpClient->withOptions([
            'base_uri' => rtrim($connector->getEndpoint(), '/').'/',
            'headers' => $this->credentials->resolve($connector->getCredentials()) + ['Notion-Version' => self::VERSION, 'Accept' => 'application/json'],
            'timeout' => 30,
        ]), maxRetries: 4);
        $response = $client->request($method, $path, $options);
        if ($response->getStatusCode() >= 400) {
            $raw = $response->getContent(false);
            $error = json_validate($raw) ? json_decode($raw, true) : null;
            $message = \is_array($error) ? ($error['message'] ?? $error['code'] ?? 'erreur inconnue') : mb_substr(trim($raw), 0, 200);
            throw new \RuntimeException(\sprintf('Notion a répondu %d : %s', $response->getStatusCode(), '' !== $message ? $message : 'erreur inconnue'));
        }

        return $response->toArray();
    }
}
