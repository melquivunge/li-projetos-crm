<?php
declare(strict_types=1);

function env(string $key, ?string $default = null): ?string
{
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : (string) $v;
}

/** Escape for HTML output. Use for every dynamic value in views. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function view(string $name, array $data = [], string $layout = 'layout'): void
{
    extract($data, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/../views/' . $name . '.php';
    $content = ob_get_clean();
    if ($layout === '') { echo $content; return; }
    require __DIR__ . '/../views/' . $layout . '.php';
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    view('error', ['code' => $code, 'message' => $message ?: ($code === 403 ? 'Acesso negado.' : 'Página não encontrada.')]);
    exit;
}

function flash(?string $msg = null, string $type = 'success'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(App\Csrf::token()) . '">';
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function money(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}

/** Round avatar: the uploaded photo, or initials on the brand colour. */
function avatar(array $u, int $size = 36): string
{
    $cls = 'avatar avatar-' . $size;
    if (!empty($u['avatar'])) return '<img class="' . $cls . '" src="/avatar/' . (int) $u['id'] . '" alt="" width="' . $size . '" height="' . $size . '">';
    $parts = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\s]+/u', ' ', (string) ($u['name'] ?? '')))) ?: ['?'];
    $parts = array_values(array_filter($parts, fn($w) => $w !== '' && !in_array(mb_strtolower($w), ['de', 'da', 'do', 'das', 'dos', 'e', 'demo'], true))) ?: ['?'];
    $ini = mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    return '<span class="' . $cls . ' avatar-initials" aria-hidden="true">' . e($ini) . '</span>';
}

/** Inline SVG icon from one consistent 24px, 1.75-stroke set. */
function icon(string $name, string $cls = 'icon'): string
{
    static $p = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h5v-6h4v6h5V9.5"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.3-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14.8c1.9.7 3.2 2.4 3.5 5.2"/>',
        'bell' => '<path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15Z"/><path d="M10 21h4"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'tag' => '<path d="M3 12V4h8l10 10-8 8Z"/><circle cx="7.5" cy="8.5" r="1.25"/>',
        'shield' => '<path d="M12 3 4.5 6v6c0 4.4 3.2 7.9 7.5 9 4.3-1.1 7.5-4.6 7.5-9V6Z"/><path d="m9 12 2 2 4-4"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4 4-6.5 8-6.5s7.2 2.5 8 6.5"/>',
        'logout' => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5M5 12h11"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/>',
        'palette' => '<path d="M12 3a9 9 0 0 0 0 18c1.1 0 1.6-.8 1.6-1.6 0-1.4-1.4-1.6-1.4-3 0-.9.7-1.4 1.6-1.4H17a4 4 0 0 0 4-4C21 6.6 17 3 12 3Z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="15" cy="7.5" r="1"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'inbox' => '<path d="M3 13h5l1.5 3h5L16 13h5"/><path d="M5 5h14l2 8v6H3v-6Z"/>',
        'trend' => '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'whatsapp' => '<path d="M4 20l1.3-4A8 8 0 1 1 8 18.7Z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 1c-1-.5-1.5-1-2-2l1-1-1-2Z"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        'file' => '<path d="M14 3H6.5A1.5 1.5 0 0 0 5 4.5v15A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V8Z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'folder' => '<path d="M3.5 7.5a2 2 0 0 1 2-2h4l2 2.5h7a2 2 0 0 1 2 2v7.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>',
        'columns' => '<rect x="3.5" y="4" width="5" height="16" rx="1.5"/><rect x="9.5" y="4" width="5" height="11" rx="1.5"/><rect x="15.5" y="4" width="5" height="7" rx="1.5"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'coins' => '<ellipse cx="9" cy="7" rx="5.5" ry="2.5"/><path d="M3.5 7v4c0 1.4 2.5 2.5 5.5 2.5s5.5-1.1 5.5-2.5V7"/><path d="M9.5 16.4c.6.1 1.3.1 2 .1 3 0 5.5-1.1 5.5-2.5"/><path d="M14.5 10.6c3 .1 6 1.2 6 2.4v4c0 1.4-2.5 2.5-5.5 2.5-2 0-3.8-.5-4.8-1.3"/>',
    ];
    return '<svg class="' . e($cls) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($p[$name] ?? '') . '</svg>';
}

/** "hoje", "amanhã", "há 3 dias", "em 5 dias" + a tone for the chip. @return array{0:string,1:string} */
function due(?string $date): array
{
    if (!$date) return ['', ''];
    $d = (int) round((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
    if ($d < 0) return [$d === -1 ? 'ontem' : 'há ' . -$d . ' dias', 'late'];
    if ($d === 0) return ['hoje', 'today'];
    return [$d === 1 ? 'amanhã' : ($d < 7 ? 'em ' . $d . ' dias' : date('d/m', strtotime($date))), 'soon'];
}

/** Whole days since a timestamp. */
function days_since(?string $ts): int
{
    return $ts ? max(0, intdiv(time() - strtotime($ts), 86400)) : 0;
}

function area(mixed $m2): string
{
    return $m2 ? rtrim(rtrim(number_format((float) $m2, 2, ',', ''), '0'), ',') . ' m²' : '';
}
