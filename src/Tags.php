<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class Tags
{
    public static function all(): array
    {
        $stmt = Database::connection()->query('SELECT * FROM tags ORDER BY name');
        return $stmt->fetchAll();
    }

    public static function findOrCreate(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Le nom du tag est requis');
        }
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT id FROM tags WHERE name = ?');
        $stmt->execute([$name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $stmt = $pdo->prepare('INSERT INTO tags (name) VALUES (?)');
        $stmt->execute([$name]);
        return (int) $pdo->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM tags WHERE id = ?');
        $stmt->execute([$id]);
    }
}
