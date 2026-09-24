<?php

declare(strict_types=1);

use App\Cache;
use App\Database;
use App\NewsRequest;
use App\NewsRepository;

$config = require dirname(__DIR__) . '/bootstrap.php';

function queryString(array $parameters): string
{
    return '?' . http_build_query(array_filter($parameters, static fn ($value): bool => $value !== null && $value !== ''));
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    $filter = NewsRequest::parse($_GET, new DateTimeZone($config['APP_TIMEZONE']));
    $repository = new NewsRepository(Database::connect($config));
    $cache = Cache::connect($config);
    $version = $cache?->version('news') ?? 1;
    $fingerprint = hash('sha256', json_encode($filter, JSON_THROW_ON_ERROR));
    $data = $cache
        ? $cache->remember("news:list:v{$version}:{$fingerprint}", (int) $config['CACHE_TTL'], fn () => ['page' => $repository->page($filter), 'categories' => $repository->categories()])
        : ['page' => $repository->page($filter), 'categories' => $repository->categories()];
} catch (Throwable $exception) {
    http_response_code(503);
    $error = $config['APP_ENV'] === 'development' ? $exception->getMessage() : 'Сервис временно недоступен.';
    require __DIR__ . '/error.php';
    exit;
}

$result = $data['page'];
$categories = $data['categories'];
$category = $filter['category'];
$date = $filter['date'];
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Новостной агрегатор</title>
  <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
<main class="container">
  <header><a class="brand" href="/">Новостной агрегатор</a><span>РИА Новости · RSS</span></header>
  <form class="filters" method="get" action="/">
    <label>Категория
      <select name="category">
        <option value="">Все категории</option>
        <?php foreach ($categories as $item): ?>
          <option value="<?= h($item['slug']) ?>" <?= $category === $item['slug'] ? 'selected' : '' ?>><?= h($item['name']) ?> (<?= (int) $item['news_count'] ?>)</option>
        <?php endforeach ?>
      </select>
    </label>
    <label>Дата <input type="date" name="date" value="<?= h($date) ?>"></label>
    <button type="submit">Показать</button>
    <?php if ($category !== null || $date !== null): ?><a class="reset" href="/">Сбросить</a><?php endif ?>
  </form>

  <p class="result-count">Показаны последние доступные новости<?= $date !== null ? ' за ' . h($date) : '' ?></p>
  <section class="news-list">
    <?php foreach ($result['items'] as $news): ?>
      <article class="news-card">
        <div class="meta"><time datetime="<?= h($news['published_at']) ?>"><?= h((new DateTimeImmutable($news['published_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($config['APP_TIMEZONE']))->format('d.m.Y H:i')) ?></time><span><?= h($news['source_name']) ?></span></div>
        <h2><a href="<?= h($news['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($news['title']) ?></a></h2>
        <?php if ($news['summary'] !== ''): ?><p><?= h($news['summary']) ?></p><?php endif ?>
        <?php if ($news['category_names'] !== []): ?><div class="tags"><?php foreach ($news['category_names'] as $name): ?><span><?= h($name) ?></span><?php endforeach ?></div><?php endif ?>
      </article>
    <?php endforeach ?>
    <?php if ($result['items'] === []): ?><p class="empty">Новостей по этому фильтру пока нет.</p><?php endif ?>
  </section>
  <?php if ($result['next'] !== null): ?>
    <nav class="pagination" aria-label="Страницы">
      <span>Для возврата используйте кнопку браузера</span>
      <a href="<?= h(queryString(['category' => $category, 'date' => $date, 'before' => NewsRequest::encodeCursor($result['next'])])) ?>">Старее →</a>
    </nav>
  <?php endif ?>
</main>
</body>
</html>
