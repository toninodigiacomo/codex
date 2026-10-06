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
        // No series spelled exactly like this — but it may be an old spelling
        // of one that was merged into another (see merge()).
        $stmt = $pdo->prepare('SELECT series_id FROM series_aliases WHERE alias = ?');
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

    /**
     * Folds series $fromId into $intoId: every item of the first now points at
     * the second, whatever description/cover/type the second lacks is taken
     * from the first, and the first is deleted. Its name is kept as an alias
     * (and any alias already pointing at it is re-pointed) so that re-reading
     * a file whose ComicInfo.xml still carries the old spelling lands on
     * $intoId rather than quietly re-creating the series that was just merged.
     * All or nothing — a failure part-way leaves both series untouched.
     * @return array{moved: int}
     */
    public static function merge(int $fromId, int $intoId): array
    {
        if ($fromId === $intoId) {
            throw new InvalidArgumentException('Impossible de fusionner une série avec elle-même');
        }
        $from = self::find($fromId);
        $into = self::find($intoId);
        if ($from === null || $into === null) {
            throw new InvalidArgumentException('Série introuvable');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE items SET series_id = ? WHERE series_id = ?');
            $stmt->execute([$intoId, $fromId]);
            $moved = $stmt->rowCount();

            foreach (['type', 'description', 'cover_path'] as $column) {
                $missing = $into[$column] === null || trim((string) $into[$column]) === '';
                $available = $from[$column] !== null && trim((string) $from[$column]) !== '';
                if ($missing && $available) {
                    $pdo->prepare("UPDATE series SET $column = ? WHERE id = ?")->execute([$from[$column], $intoId]);
                }
            }

            $pdo->prepare('UPDATE series_aliases SET series_id = ? WHERE series_id = ?')->execute([$intoId, $fromId]);
            $pdo->prepare('INSERT OR REPLACE INTO series_aliases (alias, series_id) VALUES (?, ?)')->execute([$from['name'], $intoId]);
            $pdo->prepare('DELETE FROM series WHERE id = ?')->execute([$fromId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['moved' => $moved];
    }

    /** Deletes a series only if no item points at it — for clearing out empty ones; a series that still has fiches is merged instead. */
    public static function deleteIfEmpty(int $id): void
    {
        if (self::find($id) === null) {
            throw new InvalidArgumentException('Série introuvable');
        }
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM items WHERE series_id = ?');
        $stmt->execute([$id]);
        $n = (int) $stmt->fetchColumn();
        if ($n > 0) {
            throw new InvalidArgumentException("Cette série contient encore $n fiche(s) : fusionne-la plutôt que de la supprimer");
        }
        self::delete($id);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM series WHERE id = ?');
        $stmt->execute([$id]);
    }
}
