<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

final class RssImporter
{
    private ?PDOStatement $newsUpsert = null;
    private ?PDOStatement $deleteCategories = null;
    private ?PDOStatement $categoryUpsert = null;
    private ?PDOStatement $attachCategory = null;

    public function __construct(private readonly PDO $db, private readonly ?Cache $cache) {}

    /** @param array<string, string> $config @return array{status:string,items:int}
     * @throws Throwable
     */
    public function import(array $config): array
    {
        $worker = new WorkerStatus($this->db, 'rss-importer');
        $worker->started();

        if (!$this->db->query("SELECT GET_LOCK('news_aggregator_rss_import', 0)")->fetchColumn()) {
            throw new RuntimeException('Another RSS import is already running.');
        }

        try {
            $source = $this->source($config['RSS_SOURCE_NAME'], $config['RSS_URL']);
            $runId = $this->startRun((int) $source['id']);

            try {
                $response = $this->download($config['RSS_URL'], $source['etag'], $source['last_modified']);

                if ($response['status'] === 304) {
                    $this->finishRun($runId, 'not_modified', 0);
                    $worker->succeeded();
                    return ['status' => 'not_modified', 'items' => 0];
                }

                if ($response['status'] < 200 || $response['status'] >= 300) {
                    throw new RuntimeException('RSS server returned HTTP ' . $response['status']);
                }

                $items = $this->parse($response['body']);
                $this->db->beginTransaction();

                try {
                    $changed = false;
                    foreach ($items as $item) $changed = $this->storeItem((int) $source['id'], $item) || $changed;
                    $this->updateValidators((int) $source['id'], $response['etag'], $response['lastModified']);
                    $this->db->commit();
                } catch (Throwable $exception) {
                    $this->db->rollBack();
                    throw $exception;
                }

                $this->finishRun($runId, 'success', count($items));

                if ($changed) $this->cache?->bumpVersion('news');

                $worker->succeeded();

                return ['status' => 'success', 'items' => count($items)];
            } catch (Throwable $exception) {
                $this->finishRun($runId, 'failed', 0, $exception->getMessage());
                $worker->failed($exception->getMessage());

                throw $exception;
            }
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('news_aggregator_rss_import')");
        }
    }

    private function source(string $name, string $url): array
    {
        $upsert = $this->db->prepare('INSERT INTO sources (name, feed_url, feed_url_hash) 
            VALUES (:name, :url, :url_hash)
             ON DUPLICATE KEY UPDATE name = VALUES(name), feed_url = VALUES(feed_url), id = LAST_INSERT_ID(id)');

        $upsert->execute(['name' => $name, 'url' => $url, 'url_hash' => hash('sha256', $url)]);
        $id = (int) $this->db->lastInsertId();
        $statement = $this->db->prepare('SELECT id, name, etag, last_modified FROM sources WHERE id = :id');
        $statement->execute(['id' => $id]);
        $source = $statement->fetch();

        if ($source === false) {
            throw new RuntimeException('Could not initialize RSS source.');
        }

        return $source;
    }

    private function startRun(int $sourceId): int
    {
        $statement = $this->db->prepare("INSERT INTO import_runs (source_id, status) 
                                                    VALUES (:source_id, 'running')");
        $statement->execute(['source_id' => $sourceId]);

        return (int) $this->db->lastInsertId();
    }

    private function finishRun(int $runId, string $status, int $items, ?string $error = null): void
    {
        $statement = $this->db->prepare('UPDATE import_runs SET status = :status,
                                                    items_seen = :items,
                                                    error_message = :error,
                                                    finished_at = UTC_TIMESTAMP()
                                                WHERE id = :id');
        $statement->execute([
            'status' => $status,
            'items' => $items,
            'error' => $error === null ? null : mb_substr($error, 0, 4000),
            'id' => $runId]);
    }

    private function download(string $url, ?string $etag, ?string $lastModified): array
    {
        $lastError = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = $this->downloadOnce($url, $etag, $lastModified);

                if ($response['status'] !== 429 && $response['status'] < 500) return $response;

                $lastError = new RuntimeException('RSS server returned HTTP ' . $response['status']);
            } catch (RuntimeException $exception) {
                $lastError = $exception;
            }

            if ($attempt < 2) usleep((int) (250_000 * (2 ** $attempt)));
        }

        throw $lastError ?? new RuntimeException('RSS download failed.');
    }

    private function downloadOnce(string $url, ?string $etag, ?string $lastModified): array
    {
        $headers = ['Accept: application/rss+xml, application/xml;q=0.9, */*;q=0.1',
            'User-Agent: NewsAggregator/1.0 (+local)'];

        if ($etag !== null) $headers[] = 'If-None-Match: ' . $etag;

        if ($lastModified !== null) $headers[] = 'If-Modified-Since: ' . $lastModified;

        $curl = curl_init($url);

        if ($curl === false) throw new RuntimeException('Unable to initialize HTTP client.');

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);

        curl_close($curl);

        if ($raw === false) throw new RuntimeException('RSS download failed: ' . $error);

        if (strlen($raw) > 8 * 1024 * 1024) throw new RuntimeException('RSS response is too large.');

        $headerBlock = substr($raw, 0, $headerSize);

        preg_match('/^ETag:\s*(.+)$/mi', $headerBlock, $etagMatch);
        preg_match('/^Last-Modified:\s*(.+)$/mi', $headerBlock, $modifiedMatch);

        return [
            'status' => $status,
            'body' => substr($raw, $headerSize),
            'etag' => isset($etagMatch[1]) ? trim($etagMatch[1]) : null,
            'lastModified' => isset($modifiedMatch[1]) ? trim($modifiedMatch[1]) : null];
    }

    private function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $feed = simplexml_load_string($xml, SimpleXMLElement::class,
                LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);

            if (!$feed instanceof SimpleXMLElement || !isset($feed->channel->item)) {
                throw new RuntimeException('Malformed RSS XML.');
            }

            $items = [];

            foreach ($feed->channel->item as $node) {
                $title = $this->text($node->title ?? null, 1024);
                $url = $this->validUrl($this->text($node->link ?? null, 2048));

                if ($title === '' || $url === null) continue;

                $externalId = $this->text($node->guid ?? null, 512) ?: hash('sha256', $url);
                $date = $this->rssDate($this->text($node->pubDate ?? null, 255));

                if ($date === null) continue;

                $categories = [];

                foreach ($node->category as $category) {
                    $name = $this->text($category, 255);

                    if ($name !== '') $categories[$name] = $name;
                }

                $summary = $this->text($node->description ?? null, 15000);
                $hash = hash('sha256',
                    implode("\n", [$title, $url, $summary, $date->format(DATE_ATOM),
                    implode('|', $categories)]));

                $items[] = [
                    'externalId' => $externalId,
                    'title' => $title,
                    'url' => $url,
                    'summary' => $summary,
                    'publishedAt' => $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'categories' => array_values($categories),
                    'hash' => $hash
                ];
            }

            if ($items === []) {
                throw new RuntimeException('RSS has no valid items; source format may have changed.');
            }

            return $items;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function storeItem(int $sourceId, array $item): bool
    {
        $this->newsUpsert ??= $this->db->prepare(
            'INSERT INTO news (source_id, external_id, title, url, summary, published_at, content_hash)
             VALUES (:source, :external_id, :title, :url, :summary, :published_at, :hash)
             ON DUPLICATE KEY UPDATE
               id = LAST_INSERT_ID(id),
               title = IF(content_hash <> VALUES(content_hash), VALUES(title), title),
               url = IF(content_hash <> VALUES(content_hash), VALUES(url), url),
               summary = IF(content_hash <> VALUES(content_hash), VALUES(summary), summary),
               published_at = IF(content_hash <> VALUES(content_hash), VALUES(published_at), published_at),
               updated_at = IF(content_hash <> VALUES(content_hash), UTC_TIMESTAMP(), updated_at),
               content_hash = VALUES(content_hash)'
        );

        $this->newsUpsert->execute([
            'source' => $sourceId,
            'external_id' => $item['externalId'],
            'title' => $item['title'],
            'url' => $item['url'],
            'summary' => $item['summary'],
            'published_at' => $item['publishedAt'],
            'hash' => $item['hash']]);

        $newsId = (int) $this->db->lastInsertId();

        if ($this->newsUpsert->rowCount() === 0) return false;

        $this->deleteCategories ??= $this->db->prepare('DELETE FROM news_categories WHERE news_id = :news_id');
        $this->deleteCategories->execute(['news_id' => $newsId]);

        foreach ($item['categories'] as $name) {
            $categoryId = $this->category($name);
            $this->attachCategory ??= $this->db->prepare('INSERT IGNORE INTO news_categories (news_id, category_id) VALUES (:news_id, :category_id)');
            $this->attachCategory->execute(['news_id' => $newsId, 'category_id' => $categoryId]);
        }

        return true;
    }

    private function category(string $name): int
    {
        $slug = $this->slug($name);
        $this->categoryUpsert ??= $this->db->prepare('INSERT INTO categories (name, slug) VALUES (:name, :slug) ON DUPLICATE KEY UPDATE name = VALUES(name), id = LAST_INSERT_ID(id)');
        $this->categoryUpsert->execute(['name' => $name, 'slug' => $slug]);

        return (int) $this->db->lastInsertId();
    }

    private function updateValidators(int $sourceId, ?string $etag, ?string $lastModified): void
    {
        $statement = $this->db->prepare('UPDATE sources SET etag = :etag, last_modified = :last_modified WHERE id = :id');
        $statement->execute(['etag' => $etag, 'last_modified' => $lastModified, 'id' => $sourceId]);
    }

    private function text(?SimpleXMLElement $value, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ',
            html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');

        return mb_substr($text, 0, $limit);
    }

    private function validUrl(string $url): ?string
    {
        return filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }

    private function rssDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(DATE_RSS, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) ? null : $date;
    }

    private function slug(string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^\pL\pN]+/u', '-', $name), '-'));

        return mb_substr($base !== '' ? $base : 'category', 0, 220) . '-' . substr(hash('sha256', $name), 0, 12);
    }
}
