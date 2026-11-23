<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Audit, Auth, Brand, Clients, Db, Files, Finance, Portal};

/** Client portal (/portal): the client sees only their own projects. Invites are sent by the team from the client page. */
final class PortalController
{
    // ---- Team: invite / revoke ----

    public static function invite(string $id): void
    {
        Auth::requirePerm('crm');
        $c = Clients::findOrFail((int) $id);
        $back = '/clientes/' . $c['id'];
        if (($_POST['action'] ?? '') === 'revoke') {
            Db::q('UPDATE client_users SET active = 0, invite_hash = NULL, session_version = session_version + 1 WHERE client_id = ?', [$c['id']]);
            // Delivery links sent earlier stop working too (F04): each gets a new token
            foreach (Db::q('SELECT d.id FROM deliverables d JOIN projects p ON p.id = d.project_id WHERE p.client_id = ? AND d.public_token IS NOT NULL', [$c['id']])->fetchAll(\PDO::FETCH_COLUMN) as $did)
                Db::q('UPDATE deliverables SET public_token = ? WHERE id = ?', [bin2hex(random_bytes(32)), $did]);
            Audit::log('portal.revoke', 'client', (int) $c['id']);
            flash('Acesso ao portal desativado.');
            redirect($back);
        }
        if (!$c['email']) { flash('Cadastre o e-mail do cliente: é o login do portal.', 'danger'); redirect($back); }
        $token = Portal::invite((int) $c['id'], $c['email']);
        if (!$token) { flash('Este e-mail já é o login de outro cliente no portal.', 'danger'); redirect($back); }
        $url = rtrim((string) env('APP_URL', ''), '/') . '/portal/convite/' . $token;
        Audit::log('portal.invite', 'client', (int) $c['id']);
        Clients::event((int) $c['id'], 'sistema', 'Convite para o portal do cliente enviado (vale ' . Portal::INVITE_DAYS . ' dias).');
        if (!$c['whatsapp']) { flash('Convite criado. Copie o link e mande ao cliente: ' . $url); redirect($back); }
        $text = 'Olá, ' . explode(' ', trim($c['name']))[0] . '! Aqui é da ' . Brand::name() . '. No portal do cliente você acompanha as fases do seu projeto, os arquivos, as aprovações e os pagamentos. Crie a sua senha por este link (vale ' . Portal::INVITE_DAYS . " dias):\n" . $url;
        redirect('https://wa.me/' . ltrim($c['whatsapp'], '+') . '?text=' . rawurlencode($text));
    }

    // ---- Client: login, invite, home, files ----

    public static function loginForm(): void
    {
        if (Portal::user()) redirect('/portal');
        view('portal_login', ['error' => null, 'email' => ''], 'layout_guest');
    }

    public static function login(): void
    {
        $email = (string) ($_POST['email'] ?? '');
        $pass = (string) ($_POST['password'] ?? '');
        $err = !filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '' || strlen($pass) > 200 ? 'Informe e-mail e senha válidos.' : Portal::attempt($email, $pass);
        if ($err) { http_response_code(401); view('portal_login', ['error' => $err, 'email' => $email], 'layout_guest'); return; }
        redirect('/portal');
    }

    public static function logout(): void
    {
        Portal::logout();
        redirect('/portal/login');
    }

    public static function inviteForm(string $token): void
    {
        $u = Portal::byInvite($token);
        if (!$u) http_response_code(404);
        view('portal_invite', ['u' => $u, 'token' => $token, 'error' => null], 'layout_guest');
    }

    public static function acceptInvite(string $token): void
    {
        $u = Portal::byInvite($token) ?? abort(404);
        $pass = (string) ($_POST['password'] ?? '');
        $error = strlen($pass) < 10 || strlen($pass) > 200 ? 'A senha precisa ter entre 10 e 200 caracteres.'
            : (!hash_equals($pass, (string) ($_POST['password2'] ?? '')) ? 'As duas senhas não são iguais.' : null);
        if ($error) { http_response_code(422); view('portal_invite', compact('u', 'token', 'error'), 'layout_guest'); return; }
        Db::q('UPDATE client_users SET password_hash = ?, invite_hash = NULL, invite_expires = NULL, session_version = session_version + 1 WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
        Clients::event((int) $u['client_id'], 'sistema', 'Cliente criou a senha do portal.');
        Portal::start((int) $u['id']);
        redirect('/portal');
    }

    public static function home(): void
    {
        $me = Portal::require();
        $projects = Db::q("SELECT * FROM projects WHERE client_id = ? AND status <> 'cancelado' ORDER BY status = 'concluido', id DESC", [$me['client_id']])->fetchAll();
        $cards = [];
        foreach ($projects as $p) {
            $pid = (int) $p['id'];
            $deliv = Db::q('SELECT * FROM deliverables WHERE project_id = ? AND sent_at IS NOT NULL ORDER BY sent_at DESC', [$pid])->fetchAll();
            $files = [];
            if ($deliv) {
                $in = implode(',', array_map(fn($d) => (int) $d['id'], $deliv));
                foreach (Db::q("SELECT * FROM files WHERE owner_type = 'deliverable' AND owner_id IN ($in) AND visible_to_client = 1 ORDER BY id DESC")->fetchAll() as $f)
                    $files[$f['owner_id']][$f['name']] ??= $f; // newest version of each name
            }
            $cards[] = [
                'p' => $p,
                'paid' => Finance::projectPaidUp($p),
                'phases' => Db::q('SELECT * FROM project_phases WHERE project_id = ? ORDER BY sort, id', [$pid])->fetchAll(),
                'deliv' => $deliv,
                'files' => $files,
                'inst' => Db::q("SELECT * FROM installments WHERE project_id = ? AND status <> 'cancelada' ORDER BY due_date, number", [$pid])->fetchAll(),
                'costs' => Db::q("SELECT r.*, d.public_token FROM revision_requests r JOIN deliverables d ON d.id = r.deliverable_id WHERE r.project_id = ? AND r.status = 'aguardando_aprovacao' AND r.extra_cost > 0 AND d.sent_at IS NOT NULL", [$pid])->fetchAll(),
            ];
        }
        header('X-Robots-Tag: noindex');
        view('portal_home', ['me' => $me, 'cards' => $cards, 'pix' => [Finance::setting('pix_key'), Finance::setting('pix_name')]], 'layout_guest');
    }

    /** Only files of deliverables already sent to this client, marked visible. */
    public static function file(string $id): void
    {
        $me = Portal::require();
        $f = Db::q("SELECT f.*, p.id AS project_id, p.contract_value FROM files f JOIN deliverables d ON f.owner_type = 'deliverable' AND d.id = f.owner_id JOIN projects p ON p.id = d.project_id
            WHERE f.id = ? AND p.client_id = ? AND f.visible_to_client = 1 AND d.sent_at IS NOT NULL", [(int) $id, $me['client_id']])->fetch() ?: abort(404);
        if (!Finance::projectPaidUp(['id' => $f['project_id'], 'contract_value' => $f['contract_value']])) Files::sendPreview($f);
        Files::send($f, !empty($_GET['baixar']));
    }
}
