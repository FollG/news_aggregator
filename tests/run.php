#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\NewsRequest;
use App\RssImporter;

require dirname(__DIR__) . '/bootstrap.php';

function expect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$moscow = new DateTimeZone('Europe/Moscow');
$filter = NewsRequest::parse(['date' => '2026-09-24'], $moscow);
expect($filter['from'] === '2026-09-23 21:00:00', 'Calendar date must convert from Moscow to UTC.');
expect(NewsRequest::parse(['date' => ['bad']], $moscow)['date'] === null, 'Array query input must not throw.');
$token = NewsRequest::encodeCursor(['publishedAt' => '2026-09-24 09:00:00', 'id' => 42]);
expect(NewsRequest::parse(['before' => $token], $moscow)['before']['id'] === 42, 'Cursor must round-trip.');

$rss = <<<'XML'
<rss version="2.0"><channel><item><title>  Заголовок  </title><link>https://example.test/news</link><guid>x</guid><pubDate>Thu, 24 Sep 2026 12:00:00 +0300</pubDate><description><![CDATA[<b>Текст</b>]]></description><category>Мир</category></item></channel></rss>
XML;
$reflection = new ReflectionClass(RssImporter::class);
$importer = $reflection->newInstanceWithoutConstructor();
$parse = $reflection->getMethod('parse');
$items = $parse->invoke($importer, $rss);
expect(count($items) === 1 && $items[0]['publishedAt'] === '2026-09-24 09:00:00', 'RSS date must be parsed into UTC.');
expect($items[0]['summary'] === 'Текст', 'RSS HTML must be reduced to text.');

fwrite(STDOUT, "OK\n");
