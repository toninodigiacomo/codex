<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class Libraries
{
    public static function all(): array
    {
        $stmt = Database::connection()->query('SELECT * FROM libraries ORDER BY name');
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM libraries WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $name, string $path, string $type = 'comic'): int
    {
        if (trim($name) === '' || trim($path) === '') {
            throw new InvalidArgumentException('Le nom et le chemin sont requis');
        }
        if (!in_array($type, ['comic', 'ebook', 'magazine', 'other'], true)) {
            throw new InvalidArgumentException('Type de bibliothèque invalide');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO libraries (name, path, type) VALUES (?, ?, ?)');
        $stmt->execute([$name, $path, $type]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $fields): void
    {
        $sets = [];
        $params = [':id' => $id];
        foreach (['name', 'path'] as $c) {
            if (array_key_exists($c, $fields) && trim((string) $fields[$c]) !== '') {
                $sets[] = "$c = :$c";
                $params[":$c"] = trim((string) $fields[$c]);
            }
        }
        if (array_key_exists('type', $fields)) {
            $type = (string) $fields['type'];
            if (!in_array($type, ['comic', 'ebook', 'magazine', 'other'], true)) {
                throw new InvalidArgumentException('Type de bibliothèque invalide');
            }
            $sets[] = 'type = :type';
            $params[':type'] = $type;
        }
        if (!$sets) {
            return;
        }
        $stmt = Database::connection()->prepare('UPDATE libraries SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM libraries WHERE id = ?');
        $stmt->execute([$id]);
    }
}
