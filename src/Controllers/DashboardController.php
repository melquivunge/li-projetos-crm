<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Auth, Clients, Db};

final class DashboardController
{
    public const PERIODS = [7 => '7 dias', 30 => '30 dias', 90 => '90 dias', 365 => '12 meses'];

    /** Pure: median of a list of numbers, null when empty. */
    public static function median(array $xs): ?float
    {
        if (!$xs) return null;
        sort($xs);
        $n = count($xs);
        return $n % 2 ? (float) $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
    }

    /** Pure: one row per day in [from, to], zero-filled. */
    public static function fillDays(array $counts, string $from, string $to): array
    {
        $out = [];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) $out[date('Y-m-d', $d)] = (int) ($counts[date('Y-m-d', $d)] ?? 0);
        return $out;
    }

    public static function index(): void
    {
        $u = Auth::require();
        $days = (int) ($_GET['dias'] ?? 30);
        if (!isset(self::PERIODS[$days])) $days = 30;
        $owner = Auth::isAdmin() ? (int) ($_GET['owner'] ?? 0) : 0;
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $since = "$from 00:00:00";

        [$scope, $sp] = Clients::scope();
        if ($owner) { $scope .= ' AND c.owner_id = ?'; $sp[] = $owner; }
        $q = fn(string $sql, array $p = []) => Db::q($sql, array_merge($sp, $p));

        $byStage = $q("SELECT stage, COUNT(*) FROM clients c WHERE $scope GROUP BY stage")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $leads = (int) $q("SELECT COUNT(*) FROM clients c WHERE $scope AND c.created_at >= ?", [$since])->fetchColumn();
        $perDay = self::fillDays($q("SELECT DATE(created_at) d, COUNT(*) FROM clients c WHERE $scope AND c.created_at >= ? GROUP BY d", [$since])->fetchAll(\PDO::FETCH_KEY_PAIR), $from, date('Y-m-d'));
        $byOrigin = $q("SELECT COALESCE(NULLIF(origin, ''), 'sem origem') o, COUNT(*) n FROM clients c WHERE $scope AND c.created_at >= ? GROUP BY o ORDER BY n DESC LIMIT 8", [$since])->fetchAll(\PDO::FETCH_KEY_PAIR);
        $cohort = $q("SELECT stage, COUNT(*) FROM clients c WHERE $scope AND c.created_at >= ? GROUP BY stage", [$since])->fetchAll(\PDO::FETCH_KEY_PAIR);
        $firstContact = self::median(array_map('intval', $q("SELECT TIMESTAMPDIFF(MINUTE, created_at, first_contact_at) FROM clients c WHERE $scope AND c.created_at >= ? AND first_contact_at IS NOT NULL", [$since])->fetchAll(\PDO::FETCH_COLUMN)));

        $prop = $q("SELECT
              COUNT(CASE WHEN p.sent_at >= ? THEN 1 END) sent_n, COALESCE(SUM(CASE WHEN p.sent_at >= ? THEN p.total END), 0) sent_v,
              COUNT(CASE WHEN p.status = 'aceita' AND p.decided_at >= ? THEN 1 END) won_n, COALESCE(SUM(CASE WHEN p.status = 'aceita' AND p.decided_at >= ? THEN p.total END), 0) won_v
            FROM proposals p JOIN clients c ON c.id = p.client_id WHERE $scope", [$since, $since, $since, $since])->fetch();

        // Site clicks are site-wide (no owner), so only the partners see them
        $clicks = Auth::isAdmin()
            ? Db::q("SELECT event, COUNT(*) FROM site_events WHERE created_at >= ? AND event IN ('whatsapp_click','schedule_cta_click') GROUP BY event", [$since])->fetchAll(\PDO::FETCH_KEY_PAIR)
            : null;

        $byStage = Db::q("SELECT o.stage, COUNT(*) FROM opportunities o JOIN clients c ON c.id = o.client_id WHERE $scope GROUP BY o.stage", $sp)->fetchAll(\PDO::FETCH_KEY_PAIR);
        $myTasks = Db::q("SELECT t.*, c.name AS client_name FROM tasks t LEFT JOIN clients c ON c.id = t.client_id WHERE t.owner_id = ? AND t.done_at IS NULL AND t.due_at <= CURDATE() ORDER BY t.due_at, FIELD(t.priority,'alta','normal','baixa') LIMIT 6", [$u['id']])->fetchAll();
        view('dashboard', compact('u', 'days', 'owner', 'byStage', 'leads', 'perDay', 'byOrigin', 'cohort', 'firstContact', 'prop', 'clicks', 'myTasks') + [
            'user' => $u, 'owners' => Auth::isAdmin() ? Db::q('SELECT id, name FROM users WHERE active = 1 ORDER BY name')->fetchAll(\PDO::FETCH_KEY_PAIR) : [],
        ]);
    }
}
