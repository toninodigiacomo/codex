<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class Items
{
    private const DETAIL_TABLES = [
        'comic' => ['comic_details', [
            'writer', 'penciller', 'inker', 'colorist', 'letterer',
            'cover_artist', 'editor', 'genre', 'characters', 'age_rating',
        ]],
        'ebook' => ['ebook_details', ['author', 'isbn', 'language']],
        'magazine' => ['magazine_details', ['issue_date', 'frequency']],
    ];

    private const BASE_FIELDS = [
        'title', 'path', 'format', 'cover_path', 'publisher',
        'library_id', 'series_id', 'issue_number', 'synopsis',
    ];

    private const SORTABLE = ['title', 'added_at', 'issue_number'];

    public static function create(string $type, array $fields): int
    {
        if (!in_array($type, ['comic', 'ebook', 'magazine', 'other'], true)) {
            throw new InvalidArgumentException("Type d'item invalide : $type");
        }
        if (trim((string) ($fields['title'] ?? '')) === '') {
            throw new InvalidArgumentException('Le titre est requis');
        }
        if (trim((string) ($fields['path'] ?? '')) === '') {
            throw new InvalidArgumentException('Le chemin du fichier est requis');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $cols = self::BASE_FIELDS;
            $stmt = $pdo->prepare(
                'INSERT INTO items (type, ' . implode(', ', $cols) . ')
                 VALUES (:type, ' . implode(', ', array_map(fn($c) => ":$c", $cols)) . ')'
            );
            $params = [':type' => $type];
            foreach ($cols as $c) {
                $params[":$c"] = $fields[$c] ?? null;
            }
            $stmt->execute($params);
            $id = (int) $pdo->lastInsertId();

            self::upsertDetails($pdo, $type, $id, $fields);

            $pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function update(int $id, array $fields): void
    {
        $pdo = Database::connection();
        $existing = self::find($id);
        if (!$existing) {
            throw new RuntimeException("Item #$id introuvable");
        }

        $pdo->beginTransaction();
        try {
            $sets = [];
            $params = [':id' => $id];
            foreach (self::BASE_FIELDS as $c) {
                if (array_key_exists($c, $fields)) {
                    $sets[] = "$c = :$c";
                    $params[":$c"] = $fields[$c];
                }
            }
            if ($sets) {
                $stmt = $pdo->prepare('UPDATE items SET ' . implode(', ', $sets) . ' WHERE id = :id');
                $stmt->execute($params);
            }

            self::upsertDetails($pdo, $existing['type'], $id, $fields);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM items WHERE id = ?');
        $stmt->execute([$id]);
    }

    public static function find(int $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT items.*, libraries.name AS library_name, series.name AS series_name
             FROM items
             LEFT JOIN libraries ON libraries.id = items.library_id
             LEFT JOIN series ON series.id = items.series_id
             WHERE items.id = ?'
        );
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) {
            return null;
        }
        $item['details'] = self::fetchDetails($pdo, $item['type'], $id);
        $item['tags'] = self::tagsFor($pdo, $id);
        return $item;
    }

    /**
     * @param array{type?:string,library_id?:int,series_id?:int,tag_id?:int,query?:string} $filters
     * @return array{items: array<int, array>, total: int}
     */
    public static function search(
        array $filters = [],
        string $sort = 'title',
        string $dir = 'ASC',
        int $limit = 50,
        int $offset = 0
    ): array {
        $pdo = Database::connection();
        [$where, $params] = self::buildWhere($filters);
        $sortCol = in_array($sort, self::SORTABLE, true) ? "items.$sort" : 'items.title';
        $dirSql = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM items$whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT items.*, libraries.name AS library_name, series.name AS series_name
             FROM items
             LEFT JOIN libraries ON libraries.id = items.library_id
             LEFT JOIN series ON series.id = items.series_id
             $whereSql
             ORDER BY $sortCol $dirSql, items.title ASC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public static function setTags(int $itemId, array $tagIds): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM item_tags WHERE item_id = ?')->execute([$itemId]);
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO item_tags (item_id, tag_id) VALUES (?, ?)');
            foreach (array_unique($tagIds) as $tagId) {
                $stmt->execute([$itemId, $tagId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function tagsFor(PDO $pdo, int $itemId): array
    {
        $stmt = $pdo->prepare(
            'SELECT tags.* FROM tags
             JOIN item_tags ON item_tags.tag_id = tags.id
             WHERE item_tags.item_id = ?
             ORDER BY tags.name'
        );
        $stmt->execute([$itemId]);
        return $stmt->fetchAll();
    }

    private static function fetchDetails(PDO $pdo, string $type, int $itemId): ?array
    {
        if (!isset(self::DETAIL_TABLES[$type])) {
            return null;
        }
        [$table] = self::DETAIL_TABLES[$type];
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE item_id = ?");
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function upsertDetails(PDO $pdo, string $type, int $itemId, array $fields): void
    {
        if (!isset(self::DETAIL_TABLES[$type])) {
            return;
        }
        [$table, $cols] = self::DETAIL_TABLES[$type];
        $present = array_values(array_filter($cols, fn($c) => array_key_exists($c, $fields)));
        if (!$present) {
            return;
        }
        $colList = implode(', ', $present);
        $placeholders = implode(', ', array_map(fn($c) => ":$c", $present));
        $updateList = implode(', ', array_map(fn($c) => "$c = excluded.$c", $present));
        $sql = "INSERT INTO $table (item_id, $colList) VALUES (:item_id, $placeholders)
                ON CONFLICT(item_id) DO UPDATE SET $updateList";
        $stmt = $pdo->prepare($sql);
        $params = [':item_id' => $itemId];
        foreach ($present as $c) {
            $params[":$c"] = $fields[$c];
        }
        $stmt->execute($params);
    }

    private static function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['type'])) {
            $where[] = 'items.type = :type';
            $params[':type'] = $filters['type'];
        }
        if (!empty($filters['library_id'])) {
            $where[] = 'items.library_id = :library_id';
            $params[':library_id'] = $filters['library_id'];
        }
        if (!empty($filters['library_ids']) && is_array($filters['library_ids'])) {
            $placeholders = [];
            foreach (array_values($filters['library_ids']) as $i => $libId) {
                $key = ":lib$i";
                $placeholders[] = $key;
                $params[$key] = (int) $libId;
            }
            $where[] = 'items.library_id IN (' . implode(',', $placeholders) . ')';
        }
        if (!empty($filters['series_id'])) {
            $where[] = 'items.series_id = :series_id';
            $params[':series_id'] = $filters['series_id'];
        }
        if (!empty($filters['tag_id'])) {
            $where[] = 'items.id IN (SELECT item_id FROM item_tags WHERE tag_id = :tag_id)';
            $params[':tag_id'] = $filters['tag_id'];
        }
        if (!empty($filters['query'])) {
            $where[] = '(items.title LIKE :q OR items.publisher LIKE :q)';
            $params[':q'] = '%' . $filters['query'] . '%';
        }
        return [$where, $params];
    }
}
