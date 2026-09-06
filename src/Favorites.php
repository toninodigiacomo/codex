<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/** Per-user favorites — the star toggle on item.php, and the sidebar's Favoris filter (Items::buildWhere's favorites_for_user). */
final class Favorites
{
    public static function isFavorite(int $userId, int $itemId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND item_id = ?');
        $stmt->execute([$userId, $itemId]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return bool the new state after toggling — true if now a favorite, false if just removed */
    public static function toggle(int $userId, int $itemId): bool
    {
        if (self::isFavorite($userId, $itemId)) {
            $stmt = Database::connection()->prepare('DELETE FROM favorites WHERE user_id = ? AND item_id = ?');
            $stmt->execute([$userId, $itemId]);
            return false;
        }
        $stmt = Database::connection()->prepare('INSERT INTO favorites (user_id, item_id) VALUES (?, ?)');
        $stmt->execute([$userId, $itemId]);
        return true;
    }

    public static function countFor(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM favorites WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}
