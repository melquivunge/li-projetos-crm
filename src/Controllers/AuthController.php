<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Auth;

final class AuthController
{
    public static function form(): void
    {
        if (Auth::user()) redirect('/');
        view('login', ['error' => null, 'email' => ''], 'layout_guest');
    }

    public static function login(): void
    {
        $email = (string) ($_POST['email'] ?? '');
        $pass = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '' || strlen($pass) > 200) {
            http_response_code(422);
            view('login', ['error' => 'Informe e-mail e senha válidos.', 'email' => $email], 'layout_guest');
            return;
        }
        $err = Auth::attempt($email, $pass);
        if ($err) { http_response_code(401); view('login', ['error' => $err, 'email' => $email], 'layout_guest'); return; }
        redirect('/');
    }

    public static function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }
}
