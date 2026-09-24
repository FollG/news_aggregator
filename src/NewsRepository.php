<?php

declare(strict_types=1);

namespace App;

use PDO;

final class NewsRepository
{
    private const PAGE_SIZE = 20;
    public function __construct(private readonly PDO $db) {}

    /** @return list<array{id:int,name:string,slug:string,news_count:int}> */
    public function categories(): array // нужно делать дтошки/вошки вместо массивов, но для тестового - избыточно
    {
        return $this->db->query(
            'SELECT c.id, c.name, c.slug, COUNT(nc.news_id) AS news_count
             FROM categories c JOIN news_categories nc ON nc.category_id = c.id
             GROUP BY c.id, c.name, c.slug HAVING news_count > 0
             ORDER BY c.name'
        )->fetchAll();
    }

    public function page(array $filter): array
    {
        $where = [];
        $params = [];
        $join = ' JOIN sources s ON s.id = n.source_id';

        if ($filter['category'] !== null) {
            $join .= ' JOIN news_categories filter_nc ON filter_nc.news_id = n.id 
            JOIN categories filter_c ON filter_c.id = filter_nc.category_id';
            $where[] = 'filter_c.slug = :category';
            $params['category'] = $filter['category'];
        }

        if ($filter['from'] !== null) {
            $where[] = 'n.published_at >= :from AND n.published_at < :until';
            $params['from'] = $filter['from'];
            $params['until'] = $filter['until'];
        }

        if ($filter['before'] !== null) {
            $where[] = '(n.published_at < :before_at_lt OR (n.published_at = :before_at_eq AND n.id < :before_id))';
            $params['before_at_lt'] = $filter['before']['publishedAt'];
            $params['before_at_eq'] = $filter['before']['publishedAt'];
            $params['before_id'] = $filter['before']['id'];
        }

        $sql = 'SELECT n.id, n.title, n.url, n.summary, n.published_at, s.name AS source_name FROM news n' . $join
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY n.published_at DESC, n.id DESC LIMIT :limit';

        $statement = $this->db->prepare($sql);

        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value,
                $name === 'before_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $statement->bindValue(':limit', self::PAGE_SIZE + 1, PDO::PARAM_INT);
        $statement->execute();
        $items = $statement->fetchAll();
        $hasMore = count($items) > self::PAGE_SIZE;

        if ($hasMore) {
            array_pop($items);
        }

        $namesByNews = $this->categoryNames(array_map(static fn (array $item): int => (int) $item['id'], $items));

        foreach ($items as &$item) {
            $item['category_names'] = $namesByNews[(int) $item['id']] ?? [];
        }

        unset($item);
        $last = $hasMore ? $items[array_key_last($items)] : null;

        return ['items' => $items, 'next' => $last === null ? null : ['publishedAt' => $last['published_at'],
            'id' => (int) $last['id']]];
    }

    /** @param list<int> $newsIds @return array<int, list<string>> */
    private function categoryNames(array $newsIds): array
    {
        if ($newsIds === []) return [];

        $placeholders = implode(',', array_fill(0, count($newsIds), '?'));
        $statement = $this->db->prepare("SELECT nc.news_id, c.name FROM news_categories nc 
            JOIN categories c ON c.id = nc.category_id WHERE nc.news_id IN ({$placeholders}) ORDER BY c.name");

        foreach ($newsIds as $position => $id) {
            $statement->bindValue($position + 1, $id, PDO::PARAM_INT);
        }

        $statement->execute();
        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $result[(int) $row['news_id']][] = $row['name'];
        }

        return $result;
    }
}
