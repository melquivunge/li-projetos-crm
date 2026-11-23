<?php
declare(strict_types=1);
namespace App;

final class Csrf
{
    public static function token(): string
    {
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function check(): void
    {
        $sent = (string) ($_POST['_csrf'] ?? '');
        if (!isset($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $sent)) {
            abort(403, 'Sessão expirada ou formulário inválido. Recarregue a página e tente de novo.');
        }
    }
}
