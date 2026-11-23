<?php
declare(strict_types=1);
namespace App;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $h): void { $this->routes['GET'][$path] = $h; }
    public function post(string $path, callable $h): void { $this->routes['POST'][$path] = $h; }

    public function dispatch(string $method, string $uri): void
    {
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/') ?: '/';
        foreach ($this->routes[$method] ?? [] as $pattern => $h) {
            // {token} = 64 hex chars (public proposal links); any other {param} = numeric id
            $regex = '#^' . preg_replace_callback('#\{(\w+)\}#', fn($p) => "(?P<{$p[1]}>" . ($p[1] === 'token' ? '[a-f0-9]{64}' : '[0-9]+') . ')', $pattern) . '$#';
            if (preg_match($regex, $path, $m)) {
                // Every browser POST needs a CSRF token; /api/* has its own key + origin checks
                if ($method === 'POST' && !str_starts_with($path, '/api/')) Csrf::check();
                $h(...array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY));
                return;
            }
        }
        abort(404);
    }
}
