<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Auth, Audit, Clients, Db, Notify};

final class ProfileController
{
    public const THEMES = ['brand' => 'LI Projetos', 'light' => 'Claro', 'dark' => 'Escuro'];
    private const AVATAR_DIR = __DIR__ . '/../../storage/avatars/';

    public static function show(): void
    {
        $u = Auth::require();
        $full = Db::q('SELECT id, name, email, phone, job_title, bio, avatar, theme, role, created_at FROM users WHERE id = ?', [$u['id']])->fetch();
        view('profile', ['p' => $full, 'errors' => []]);
    }

    /** Pure: validates profile fields. @return array{0: array, 1: array<string,string>} */
    public static function validate(array $in): array
    {
        $d = [
            'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 120),
            'job_title' => mb_substr(trim((string) ($in['job_title'] ?? '')), 0, 80) ?: null,
            'bio' => mb_substr(trim((string) ($in['bio'] ?? '')), 0, 500) ?: null,
        ];
        $errors = [];
        if (mb_strlen($d['name']) < 2) $errors['name'] = 'Informe o seu nome.';
        $phone = trim((string) ($in['phone'] ?? ''));
        $d['phone'] = $phone === '' ? null : Clients::normalizeWhatsapp($phone);
        if ($phone !== '' && $d['phone'] === null) $errors['phone'] = 'Telefone inválido. Use DDD + número.';
        return [$d, $errors];
    }

    public static function save(): void
    {
        $u = Auth::require();
        [$d, $errors] = self::validate($_POST);
        $file = $_FILES['avatar'] ?? null;
        $newAvatar = null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newAvatar = self::storeAvatar($file, (int) $u['id'], $err);
            if ($newAvatar === null) $errors['avatar'] = $err;
        }
        if ($errors) {
            http_response_code(422);
            view('profile', ['p' => array_merge(Db::q('SELECT * FROM users WHERE id = ?', [$u['id']])->fetch(), $_POST), 'errors' => $errors]);
            return;
        }
        Db::q('UPDATE users SET name = ?, phone = ?, job_title = ?, bio = ? WHERE id = ?', [$d['name'], $d['phone'], $d['job_title'], $d['bio'], $u['id']]);
        if ($newAvatar) self::replaceAvatar((int) $u['id'], $newAvatar);
        if (!empty($_POST['remove_avatar'])) self::replaceAvatar((int) $u['id'], null);
        Audit::log('profile.update', 'user', (int) $u['id']);
        flash('Perfil atualizado.');
        redirect('/perfil');
    }

    /** Re-encodes the upload to a 256×256 JPEG (drops metadata and anything hidden in the file). */
    private static function storeAvatar(array $f, int $uid, ?string &$err): ?string
    {
        $err = null;
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $err = 'Não foi possível receber a imagem.'; return null; }
        if ($f['size'] > 3 * 1024 * 1024) { $err = 'A foto pode ter no máximo 3 MB.'; return null; }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) { $err = 'Envie uma foto JPG, PNG ou WebP.'; return null; }
        $src = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
        if (!$src) { $err = 'Imagem inválida.'; return null; }
        $w = imagesx($src); $h = imagesy($src); $s = min($w, $h);
        $dst = imagecreatetruecolor(256, 256);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $s, 2), intdiv($h - $s, 2), 256, 256, $s, $s);
        if (!is_dir(self::AVATAR_DIR)) mkdir(self::AVATAR_DIR, 0750, true);
        $name = $uid . '-' . bin2hex(random_bytes(8)) . '.jpg';
        imagejpeg($dst, self::AVATAR_DIR . $name, 85);
        return $name;
    }

    private static function replaceAvatar(int $uid, ?string $name): void
    {
        $old = Db::q('SELECT avatar FROM users WHERE id = ?', [$uid])->fetchColumn();
        Db::q('UPDATE users SET avatar = ? WHERE id = ?', [$name, $uid]);
        if ($old && preg_match('/^\d+-[a-f0-9]{16}\.jpg$/', $old) && is_file(self::AVATAR_DIR . $old)) unlink(self::AVATAR_DIR . $old);
    }

    /** Avatars live outside public/; only signed-in users can fetch them. */
    public static function avatar(string $id): void
    {
        Auth::require();
        $name = Db::q('SELECT avatar FROM users WHERE id = ?', [(int) $id])->fetchColumn();
        if (!$name || !preg_match('/^\d+-[a-f0-9]{16}\.jpg$/', $name) || !is_file(self::AVATAR_DIR . $name)) abort(404);
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, max-age=86400');
        readfile(self::AVATAR_DIR . $name);
    }

    public static function password(): void
    {
        $u = Auth::require();
        $hash = (string) Db::q('SELECT password_hash FROM users WHERE id = ?', [$u['id']])->fetchColumn();
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $hash)) { flash('Senha atual incorreta.', 'danger'); redirect('/perfil'); }
        if (strlen($new) < 10 || strlen($new) > 200) { flash('A nova senha precisa ter entre 10 e 200 caracteres.', 'danger'); redirect('/perfil'); }
        if ($new !== (string) ($_POST['confirm_password'] ?? '')) { flash('A confirmação não bate com a nova senha.', 'danger'); redirect('/perfil'); }
        Db::q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        Audit::log('profile.password', 'user', (int) $u['id']);
        flash('Senha alterada.');
        redirect('/perfil');
    }

    public static function theme(): void
    {
        $u = Auth::require();
        $t = (string) ($_POST['theme'] ?? '');
        if (isset(self::THEMES[$t])) Db::q('UPDATE users SET theme = ? WHERE id = ?', [$t, $u['id']]);
        $back = (string) ($_POST['back'] ?? '/');
        redirect(str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/');
    }

    // ---- Notifications ----

    public static function notifications(): void
    {
        $u = Auth::require();
        view('notifications', ['items' => Notify::latest((int) $u['id'], 100)]);
    }

    public static function openNotification(string $id): void
    {
        $u = Auth::require();
        $n = Db::q('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [(int) $id, $u['id']])->fetch() ?: abort(404);
        Db::q('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ?', [$n['id']]);
        redirect($n['link'] ?: '/');
    }

    /** Live counters for the open page (polled every minute by app.js). */
    public static function pulse(): void
    {
        $u = Auth::require();
        $t = TaskController::counts((int) $u['id']);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(['unread' => Notify::unreadCount((int) $u['id']), 'late' => $t['late'], 'today' => $t['today']]);
    }

    public static function readAll(): void
    {
        $u = Auth::require();
        Db::q('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$u['id']]);
        redirect('/notificacoes');
    }
}
