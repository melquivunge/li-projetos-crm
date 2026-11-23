<?php
declare(strict_types=1);
namespace App;

final class Auth
{
    public const MAX_ATTEMPTS = 5;   // per e-mail; IP limit is 3x
    public const WINDOW_MIN = 15;
    private static ?array $cache = null;

    public static function user(): ?array
    {
        if (empty($_SESSION['uid'])) return null;
        if (self::$cache === null || self::$cache['id'] !== $_SESSION['uid']) {
            self::$cache = Db::q('SELECT id,name,email,role,job_title,avatar,theme,session_version FROM users WHERE id=? AND active=1', [$_SESSION['uid']])->fetch() ?: null;
            // A password change, deactivation or role change bumps session_version: every other open session ends here
            if (self::$cache && (int) self::$cache['session_version'] !== (int) ($_SESSION['sv'] ?? 0)) self::$cache = null;
            if (self::$cache === null) unset($_SESSION['uid'], $_SESSION['sv']);
        }
        return self::$cache;
    }

    public const ROLES = ['admin' => 'Admin (sócia)', 'comercial' => 'Comercial', 'gestor' => 'Gestor de projetos', 'designer' => 'Designer / Arquiteto', 'financeiro' => 'Financeiro'];
    /** Permission map. One person may hold several roles; permissions are the union. */
    private const PERMS = [
        'comercial' => ['crm', 'proposals', 'tasks'],
        'gestor' => ['crm', 'tasks', 'projects'],
        'designer' => ['tasks', 'projects'],
        'financeiro' => ['tasks', 'finance'],
    ];

    /** Pure: the union of permissions of a set of roles ('admin' can do everything). */
    public static function permsFor(array $roles): array
    {
        if (in_array('admin', $roles, true)) return ['*'];
        return array_values(array_unique(array_merge([], ...array_map(fn($r) => self::PERMS[$r] ?? [], $roles))));
    }

    public static function roles(?int $userId = null): array
    {
        $u = $userId ? Db::q('SELECT id, role FROM users WHERE id = ?', [$userId])->fetch() : self::user();
        if (!$u) return [];
        $r = Db::q('SELECT role FROM user_roles WHERE user_id = ?', [$u['id']])->fetchAll(\PDO::FETCH_COLUMN);
        return $r; // no explicit role = no access (migration 018 gave legacy accounts their roles)
    }

    public static function can(string $perm): bool
    {
        static $cache = [];
        $id = self::id();
        if (!$id) return false;
        $p = $cache[$id] ??= self::permsFor(self::roles());
        return in_array('*', $p, true) || in_array($perm, $p, true);
    }

    public static function requirePerm(string $perm): array
    {
        $u = self::require();
        if (!self::can($perm)) abort(403);
        return $u;
    }

    public static function id(): ?int { return self::user()['id'] ?? null; }
    public static function isAdmin(): bool { return (self::user()['role'] ?? '') === 'admin'; }

    public static function require(): array
    {
        $u = self::user();
        if (!$u) redirect('/login');
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::require();
        if ($u['role'] !== 'admin') abort(403);
        return $u;
    }

    public static function tooManyAttempts(string $email, string $ip): bool
    {
        $row = Db::q('SELECT COALESCE(SUM(email = ?),0) AS by_email, COALESCE(SUM(ip = ?),0) AS by_ip
            FROM login_attempts WHERE attempted_at > (NOW() - INTERVAL ' . self::WINDOW_MIN . ' MINUTE)', [$email, $ip])->fetch();
        return (int) $row['by_email'] >= self::MAX_ATTEMPTS || (int) $row['by_ip'] >= self::MAX_ATTEMPTS * 3;
    }

    /** Returns null on success, or an error message. */
    public static function attempt(string $email, string $password): ?string
    {
        $email = mb_strtolower(trim($email));
        $ip = client_ip();
        if (self::tooManyAttempts($email, $ip)) {
            return 'Muitas tentativas. Aguarde ' . self::WINDOW_MIN . ' minutos e tente de novo.';
        }
        $u = Db::q('SELECT id, password_hash, session_version FROM users WHERE email=? AND active=1', [$email])->fetch();
        // Same cost whether or not the user exists
        $hash = $u['password_hash'] ?? password_hash('dummy-password-for-timing', PASSWORD_DEFAULT);
        if (!password_verify($password, $hash) || !$u) {
            Db::q('INSERT INTO login_attempts (email, ip) VALUES (?,?)', [$email, $ip]);
            return 'E-mail ou senha incorretos.';
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        $_SESSION['sv'] = (int) $u['session_version'];
        self::$cache = null;
        Db::q('DELETE FROM login_attempts WHERE email=?', [$email]);
        Audit::log('login');
        return null;
    }

    /** Ends every session of this user (all devices). Returns the new version so the caller can keep its own session. */
    public static function bumpSession(int $userId): int
    {
        Db::q('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
        return (int) Db::q('SELECT session_version FROM users WHERE id = ?', [$userId])->fetchColumn();
    }

    public static function logout(): void
    {
        Audit::log('logout');
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /** Team members only see their own clients; admins see all. */
    public static function canSeeClient(array $client): bool
    {
        return self::isAdmin() || (int) ($client['owner_id'] ?? 0) === self::id();
    }
}
