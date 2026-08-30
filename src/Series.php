<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class Series
{
    public static function all(): array
    {
        $stmt = Database::connection()->query('SELECT * FROM series ORDER BY name');
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM series WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findOrCreate(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Le nom de la série est requis');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM series WHERE name = ?');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        return self::create(['name' => $name]);
    }

    public static function create(array $fields): int
    {
        if (trim((string) ($fields['name'] ?? '')) === '') {
            throw new InvalidArgumentException('Le nom est requis');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO series (name, type, description, cover_path) VALUES (:name, :type, :description, :cover_path)'
        );
        $stmt->execute([
            ':name' => $fields['name'],
            ':type' => $fields['type'] ?? null,
            ':description' => $fields['description'] ?? null,
            ':cover_path' => $fields['cover_path'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, array $fields): void
    {
        $sets = [];
        $params = [':id' => $id];
        foreach (['name', 'type', 'description', 'cover_path'] as $c) {
            if (array_key_exists($c, $fields)) {
                $sets[] = "$c = :$c";
                $params[":$c"] = $fields[$c];
            }
        }
        if (!$sets) {
            return;
        }
        $stmt = Database::connection()->prepare('UPDATE series SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM series WHERE id = ?');
        $stmt->execute([$id]);
    }
}
