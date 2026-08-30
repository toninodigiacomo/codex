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

    public static function create(string $name, string $path): int
    {
        if (trim($name) === '' || trim($path) === '') {
            throw new InvalidArgumentException('Le nom et le chemin sont requis');
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO libraries (name, path) VALUES (?, ?)');
        $stmt->execute([$name, $path]);
        return (int) $pdo->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM libraries WHERE id = ?');
        $stmt->execute([$id]);
    }
}
