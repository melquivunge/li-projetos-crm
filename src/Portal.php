<?php
declare(strict_types=1);
namespace App;

/**
 * Client portal login. Its own session cookie (li_portal, path /portal, set in bootstrap),
 * so a client never shares a session with the team. Same attempt limits as the team login.
 */
final class Portal
{
    public const INVITE_DAYS = 7;
    private static ?array $cache = null;

    /** Pure: key in login_attempts, so a client and a team member with the same e-mail don't lock each other out. */
    public static function attemptKey(string $email): string { return mb_substr('portal:' . mb_strtolower(trim($email)), 0, 190); }

    /** Pure: an invite works only before it expires. */
    public static function inviteValid(?string $expires, int $now): bool { return $expires !== null && strtotime($expires) > $now; }

    public static function user(): ?array
    {
        if (empty($_SESSION['cuid'])) return null;
        self::$cache ??= Db::q('SELECT cu.id, cu.client_id, cu.email, cu.session_version, c.name FROM client_users cu JOIN clients c ON c.id = cu.client_id
            WHERE cu.id = ? AND cu.active = 1 AND cu.password_hash IS NOT NULL', [$_SESSION['cuid']])->fetch() ?: null;
        if (self::$cache && (int) self::$cache['session_version'] !== (int) ($_SESSION['csv'] ?? 0)) self::$cache = null; // revoked or re-invited
        if (!self::$cache) unset($_SESSION['cuid'], $_SESSION['csv']);
        return self::$cache;
    }

    public static function require(): array { return self::user() ?? redirect('/portal/login'); }

    public static function attempt(string $email, string $password): ?string
    {
        $key = self::attemptKey($email);
        if (Auth::tooManyAttempts($key, client_ip())) return 'Muitas tentativas. Aguarde ' . Auth::WINDOW_MIN . ' minutos e tente de novo.';
        $u = Db::q('SELECT id, password_hash FROM client_users WHERE email = ? AND active = 1 AND password_hash IS NOT NULL', [mb_strtolower(trim($email))])->fetch();
        $hash = $u['password_hash'] ?? password_hash('dummy-password-for-timing', PASSWORD_DEFAULT);
        if (!password_verify($password, $hash) || !$u) {
            Db::q('INSERT INTO login_attempts (email, ip) VALUES (?,?)', [$key, client_ip()]);
            return 'E-mail ou senha incorretos.';
        }
        Db::q('DELETE FROM login_attempts WHERE email = ?', [$key]);
        self::start((int) $u['id']);
        return null;
    }

    public static function start(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION['cuid'] = $id;
        $_SESSION['csv'] = (int) Db::q('SELECT session_version FROM client_users WHERE id = ?', [$id])->fetchColumn();
        self::$cache = null;
        Db::q('UPDATE client_users SET last_login_at = NOW() WHERE id = ?', [$id]);
    }

    public static function logout(): void { $_SESSION = []; session_regenerate_id(true); }

    /** Creates or renews the client's access and returns the invite token (only its hash is stored). Null if the e-mail belongs to another client. */
    public static function invite(int $clientId, string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        $other = Db::q('SELECT client_id FROM client_users WHERE email = ? AND client_id <> ?', [$email, $clientId])->fetchColumn();
        if ($other) return null;
        $token = bin2hex(random_bytes(32));
        Db::q('INSERT INTO client_users (client_id, email, invite_hash, invite_expires) VALUES (?,?,?, NOW() + INTERVAL ' . self::INVITE_DAYS . ' DAY)
            ON DUPLICATE KEY UPDATE email = VALUES(email), invite_hash = VALUES(invite_hash), invite_expires = VALUES(invite_expires), active = 1, session_version = session_version + 1',
            [$clientId, $email, hash('sha256', $token)]);
        return $token;
    }

    public static function byInvite(string $token): ?array
    {
        $u = Db::q('SELECT cu.*, c.name FROM client_users cu JOIN clients c ON c.id = cu.client_id WHERE cu.invite_hash = ? AND cu.active = 1', [hash('sha256', $token)])->fetch();
        return $u && self::inviteValid($u['invite_expires'], time()) ? $u : null;
    }
}
