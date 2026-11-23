<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Auth, Audit, Db};

final class UserController
{
    private static function list(): array
    {
        $rows = Db::q('SELECT id,name,email,role,active,avatar,created_at FROM users ORDER BY name')->fetchAll();
        foreach ($rows as &$r) $r['roles'] = Auth::roles((int) $r['id']);
        return $rows;
    }

    public static function index(): void
    {
        Auth::requireAdmin();
        view('users', ['users' => self::list(), 'errors' => []]);
    }

    public static function store(): void
    {
        Auth::requireAdmin();
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $roles = array_values(array_intersect(array_keys(Auth::ROLES), (array) ($_POST['roles'] ?? [])));
        $role = in_array('admin', $roles, true) ? 'admin' : 'equipe';
        $pass = (string) ($_POST['password'] ?? '');
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) $errors[] = 'Nome é obrigatório (até 120 caracteres).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail inválido.';
        if (strlen($pass) < 10 || strlen($pass) > 200) $errors[] = 'A senha precisa ter entre 10 e 200 caracteres.';
        if (!$roles) $errors[] = 'Escolha pelo menos um papel.';
        if (!$errors && Db::q('SELECT 1 FROM users WHERE email=?', [$email])->fetch()) $errors[] = 'Já existe um usuário com esse e-mail.';
        if ($errors) {
            http_response_code(422);
            view('users', ['users' => self::list(), 'errors' => $errors]);
            return;
        }
        Db::q('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)',
            [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
        $uid = (int) Db::pdo()->lastInsertId();
        foreach ($roles as $r) Db::q('INSERT INTO user_roles (user_id, role) VALUES (?,?)', [$uid, $r]);
        Audit::log('user.create', 'user', $uid);
        flash('Usuário criado.');
        redirect('/usuarios');
    }

    public static function toggle(string $id): void
    {
        $me = Auth::requireAdmin();
        if ((int) $id === $me['id']) { flash('Você não pode desativar a sua própria conta.', 'danger'); redirect('/usuarios'); }
        Db::q('UPDATE users SET active = 1 - active WHERE id=?', [(int) $id]);
        Auth::bumpSession((int) $id); // deactivating ends open sessions; reactivating doesn't revive old ones
        Audit::log('user.toggle', 'user', (int) $id);
        redirect('/usuarios');
    }

    public static function roles(string $id): void
    {
        $me = Auth::requireAdmin();
        $roles = array_values(array_intersect(array_keys(Auth::ROLES), (array) ($_POST['roles'] ?? [])));
        if (!$roles) { flash('Cada pessoa precisa de pelo menos um papel.', 'danger'); redirect('/usuarios'); }
        if ((int) $id === (int) $me['id'] && !in_array('admin', $roles, true)) { flash('Você não pode tirar o seu próprio acesso de admin.', 'danger'); redirect('/usuarios'); }
        $pdo = Db::pdo();
        $pdo->beginTransaction(); // no window where the account has no roles
        Db::q('DELETE FROM user_roles WHERE user_id = ?', [(int) $id]);
        foreach ($roles as $r) Db::q('INSERT INTO user_roles (user_id, role) VALUES (?,?)', [(int) $id, $r]);
        Db::q('UPDATE users SET role = ? WHERE id = ?', [in_array('admin', $roles, true) ? 'admin' : 'equipe', (int) $id]);
        $pdo->commit();
        $v = Auth::bumpSession((int) $id); // new permissions start from a fresh login
        if ((int) $id === (int) $me['id']) $_SESSION['sv'] = $v;
        Audit::log('user.roles', 'user', (int) $id);
        flash('Papéis atualizados.');
        redirect('/usuarios');
    }
}
