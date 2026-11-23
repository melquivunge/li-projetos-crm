<?php
declare(strict_types=1);
namespace App\Controllers;

use App\{Audit, Auth, Brand};

/** /empresa (admin): name, texts, colour and logo. /marca.css and /marca/logo/* are public (login page, client links). */
final class BrandController
{
    private const TEXTS = ['company_name' => 120, 'company_tagline' => 120, 'company_region' => 160, 'company_registry' => 120, 'company_whatsapp' => 40,
                           'company_instagram' => 60, 'company_about' => 1200, 'company_facts' => 400, 'company_people' => 600];
    private const LOGO_RE = '/^logo-(dark|light)-\d+\.(png|jpg|webp)$/';

    public static function show(): void
    {
        Auth::requireAdmin();
        $v = [];
        foreach (array_keys(Brand::DEFAULTS) as $k) $v[$k] = Brand::get($k);
        view('brand', ['v' => $v, 'contrast' => Brand::contrast($v['brand_color'], '#FFFDF9')]);
    }

    public static function save(): void
    {
        Auth::requireAdmin();
        foreach (self::TEXTS as $k => $max) {
            $val = mb_substr(trim(str_replace("\r", '', (string) ($_POST[$k] ?? ''))), 0, $max);
            Brand::set($k, $val === Brand::DEFAULTS[$k] ? '' : $val);   // empty = follow the default
        }
        $color = Brand::validColor((string) ($_POST['brand_color'] ?? ''));
        if ($color) Brand::set('brand_color', $color === Brand::DEFAULT_COLOR ? '' : $color);
        $errors = [];
        foreach (['brand_logo' => 'dark', 'brand_logo_light' => 'light'] as $key => $variant) {
            if (!empty($_POST["remove_$key"])) { self::drop($key); continue; }
            $f = $_FILES[$key] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $errors[] = 'Não foi possível receber o logo.'; continue; }
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            if (!isset(Brand::LOGO_TYPES[$mime])) { $errors[] = 'O logo precisa ser PNG, JPG ou WebP.'; continue; }
            if ($f['size'] > 2 * 1024 * 1024) { $errors[] = 'O logo pode ter no máximo 2 MB.'; continue; }
            $dir = storage_path('brand/');
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            $name = "logo-$variant-" . time() . '.' . Brand::LOGO_TYPES[$mime];
            if (!move_uploaded_file($f['tmp_name'], $dir . $name)) { $errors[] = 'Falha ao salvar o logo.'; continue; }
            self::drop($key);
            Brand::set($key, $name);
        }
        Audit::log('settings.brand');
        flash($errors ? implode(' ', $errors) : 'Dados da empresa salvos.', $errors ? 'danger' : 'success');
        redirect('/empresa');
    }

    private static function drop(string $key): void
    {
        $old = Brand::get($key);
        if ($old !== '' && preg_match(self::LOGO_RE, $old) && is_file(storage_path('brand/') . $old)) unlink(storage_path('brand/') . $old);
        Brand::set($key, '');
    }

    /** Public: the uploaded logo (name validated, content type from the real file). */
    public static function logo(string $file): void
    {
        $path = storage_path('brand/') . $file;
        if (!preg_match(self::LOGO_RE, $file) || !is_file($path)) abort(404);
        header('Content-Type: ' . (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=31536000, immutable'); // the name changes on every upload
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /** Public: PWA manifest (installable app on Android, iPhone and desktop). */
    public static function manifest(): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo json_encode([
            'name' => 'CRM ' . Brand::name(), 'short_name' => mb_substr(Brand::name(), 0, 12), 'lang' => 'pt-BR',
            'start_url' => '/', 'scope' => '/', 'display' => 'standalone', 'orientation' => 'any',
            'background_color' => '#F3EEE5', 'theme_color' => '#1A1918',
            'icons' => [
                ['src' => '/assets/app/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/app/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/app/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Tarefas', 'url' => '/tarefas'], ['name' => 'Funil', 'url' => '/funil'], ['name' => 'Projetos', 'url' => '/projetos'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Public: colour tokens for the chosen brand colour (empty when it is the default). */
    public static function css(): void
    {
        header('Content-Type: text/css; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo Brand::css();
        exit;
    }
}
