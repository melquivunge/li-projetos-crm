<?php
declare(strict_types=1);
namespace App;

/** In-app notifications (the bell). One row per recipient. */
final class Notify
{
    /** Partners (admins) + the client's owner, deduplicated. $link must be an internal path. */
    public static function clientTeam(?int $ownerId, string $body, string $link): void
    {
        $ids = Db::q("SELECT id FROM users WHERE active = 1 AND (role = 'admin' OR id = ?)", [$ownerId ?? 0])->fetchAll(\PDO::FETCH_COLUMN);
        foreach (array_unique(array_map('intval', $ids)) as $uid) self::user($uid, $body, $link);
    }

    public static function user(int $userId, string $body, string $link): void
    {
        if (!str_starts_with($link, '/') || str_starts_with($link, '//')) $link = '/';
        Db::q('INSERT INTO notifications (user_id, body, link) VALUES (?,?,?)', [$userId, mb_substr($body, 0, 255), mb_substr($link, 0, 120)]);
    }

    /** Same body+link to the same person at most once in $days days (cron reminders run hourly). Returns true if sent. */
    public static function once(int $userId, string $body, string $link, int $days = 30): bool
    {
        $body = mb_substr($body, 0, 255);
        if (Db::q('SELECT 1 FROM notifications WHERE user_id = ? AND body = ? AND link = ? AND created_at > NOW() - INTERVAL ? DAY', [$userId, $body, $link, $days])->fetch()) return false;
        self::user($userId, $body, $link);
        return true;
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Db::q('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId])->fetchColumn();
    }

    public static function latest(int $userId, int $limit = 8): array
    {
        return Db::q('SELECT * FROM notifications WHERE user_id = ? ORDER BY read_at IS NULL DESC, id DESC LIMIT ' . max(1, min(100, $limit)), [$userId])->fetchAll();
    }
}
