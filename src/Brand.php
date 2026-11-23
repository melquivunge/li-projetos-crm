<?php
declare(strict_types=1);
namespace App;

/** Company identity (name, logo, colour, "about" texts). Stored in `settings`; defaults = LI Projetos, so nothing changes until edited. */
final class Brand
{
    public const DEFAULT_COLOR = '#866628';
    public const DEFAULTS = [
        'company_name' => 'LI Projetos',
        'company_tagline' => 'Arquitetura e interiores',
        'company_region' => 'Atendimento online no DF e em Goiânia',
        'company_registry' => 'Registro no CAU/DF',
        'company_whatsapp' => '+55 (61) 99241-4304',
        'company_instagram' => 'li.projetos',
        'company_about' => 'A LI Projetos é um estúdio de arquitetura e design de interiores com atendimento 100% online, para o Distrito Federal e Goiânia. Do primeiro traço ao detalhamento executivo, com reuniões por videochamada no seu horário.',
        'company_facts' => "10+ | anos de estúdio\n50+ | projetos entregues\n15.000 | m² projetados",
        'company_people' => "Iata de Almeida | Interiores e plantas\nLarissa de Almeida | Arquitetura e projeto executivo",
        'brand_color' => self::DEFAULT_COLOR,
        'brand_logo' => '',        // file name in storage/brand, for light backgrounds
        'brand_logo_light' => '',  // for dark backgrounds
    ];
    public const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    private static ?array $cache = null;

    public static function get(string $k): string
    {
        self::$cache ??= Db::q("SELECT k, v FROM settings WHERE k LIKE 'company\\_%' OR k LIKE 'brand\\_%'")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $v = self::$cache[$k] ?? '';
        return $v !== '' ? $v : (self::DEFAULTS[$k] ?? '');
    }

    public static function set(string $k, string $v): void
    {
        Db::q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        self::$cache = null;
    }

    public static function name(): string { return self::get('company_name'); }

    /** One line for footers: "LI Projetos · Arquitetura e interiores · Atendimento online…". */
    public static function footer(bool $withRegistry = false): string
    {
        return implode(' · ', array_filter([self::name(), self::get('company_tagline'), self::get('company_region'), $withRegistry ? self::get('company_registry') : '']));
    }

    /** Pure: "valor | rótulo" lines → [[valor, rótulo], …]. */
    public static function pairs(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $p = array_map('trim', explode('|', $line, 2));
            if ($p[0] !== '') $out[] = [$p[0], $p[1] ?? ''];
        }
        return $out;
    }

    /** Pure: initials of a name ("Iata de Almeida" → "IA"). */
    public static function initials(string $name): string
    {
        $w = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: [], fn($x) => mb_strlen($x) > 2));
        if (!$w) return mb_strtoupper(mb_substr(trim($name), 0, 1));
        return mb_strtoupper(mb_substr($w[0], 0, 1) . (count($w) > 1 ? mb_substr(end($w), 0, 1) : ''));
    }

    // ---- Logo ----

    public static function logoFile(bool $onDark): ?string
    {
        $f = self::get($onDark ? 'brand_logo_light' : 'brand_logo');
        if ($f === '' || !preg_match('/^logo-(dark|light)-\d+\.(png|jpg|webp)$/', $f)) return null;
        $path = storage_path('brand/') . $f;
        return is_file($path) ? $path : null;
    }

    /** URL of the logo for a light (default) or dark background. Falls back to the other variant, then to the built-in one. */
    public static function logoUrl(bool $onDark = false): string
    {
        foreach ([$onDark, !$onDark] as $v) if (self::logoFile($v)) return '/marca/logo/' . self::get($v ? 'brand_logo_light' : 'brand_logo');
        return $onDark ? '/assets/logo-li-projetos-light.svg' : '/assets/logo-li-projetos.svg';
    }

    /** data: URI for the PDF (dompdf can't fetch our routes). */
    public static function logoDataUri(bool $onDark): string
    {
        foreach ([$onDark, !$onDark] as $v) if ($p = self::logoFile($v)) return 'data:' . (string) (new \finfo(FILEINFO_MIME_TYPE))->file($p) . ';base64,' . base64_encode((string) file_get_contents($p));
        return 'data:image/svg+xml;base64,' . base64_encode((string) file_get_contents(__DIR__ . '/../public/assets/' . ($onDark ? 'logo-li-projetos-light.svg' : 'logo-li-projetos.svg')));
    }

    // ---- Colour (pure) ----

    public static function validColor(string $hex): ?string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', trim($hex)) ? strtoupper(trim($hex)) : null;
    }

    private static function rgb(string $hex): array { return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))]; }

    private static function hex(array $c): string { return sprintf('#%02X%02X%02X', ...array_map(fn($v) => (int) round(max(0, min(255, $v))), $c)); }

    /** Pure: mix $a towards $b by $t (0..1). */
    public static function mix(string $a, string $b, float $t): string
    {
        [$x, $y] = [self::rgb($a), self::rgb($b)];
        return self::hex([$x[0] + ($y[0] - $x[0]) * $t, $x[1] + ($y[1] - $x[1]) * $t, $x[2] + ($y[2] - $x[2]) * $t]);
    }

    /** Pure: WCAG contrast ratio between two colours. */
    public static function contrast(string $a, string $b): float
    {
        $l = function (string $h): float {
            $c = array_map(function ($v) { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; }, self::rgb($h));
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$x, $y] = [$l($a), $l($b)];
        return round((max($x, $y) + 0.05) / (min($x, $y) + 0.05), 2);
    }

    /** Pure: the tokens derived from one brand colour. */
    public static function palette(string $c): array
    {
        return [
            'accent' => $c,
            'accent_strong' => self::mix($c, '#000000', 0.18),
            'accent_soft' => self::mix($c, '#FFFFFF', 0.85),
            'accent_light' => self::mix($c, '#FFFFFF', 0.35),   // brand theme rail highlight, PDF rule
            'dark_accent' => self::mix($c, '#FFFFFF', 0.4),     // readable on the dark theme
            'dark_strong' => self::mix($c, '#FFFFFF', 0.55),
            'dark_soft' => self::mix($c, '#141311', 0.8),
        ];
    }

    /** CSS overriding the colour tokens; empty when the default colour is kept. */
    public static function css(): string
    {
        $c = self::validColor(self::get('brand_color')) ?? self::DEFAULT_COLOR;
        if ($c === self::DEFAULT_COLOR) return '';
        $p = self::palette($c);
        return ":root, [data-theme=\"brand\"], [data-theme=\"light\"] { --accent: {$p['accent']}; --accent-strong: {$p['accent_strong']}; --accent-soft: {$p['accent_soft']}; --focus: {$p['accent']}; --chart: {$p['accent']}; --chart-hover: {$p['accent_strong']}; }\n"
             . "[data-theme=\"brand\"] { --rail-active-bg: {$p['accent_light']}; --focus: {$p['accent_light']}; }\n"
             . "[data-theme=\"dark\"] { --accent: {$p['dark_accent']}; --accent-strong: {$p['dark_strong']}; --accent-soft: {$p['dark_soft']}; --rail-active-bg: {$p['dark_accent']}; --primary: {$p['dark_accent']}; --primary-hover: {$p['dark_strong']}; --focus: {$p['dark_accent']}; --chart: {$p['dark_accent']}; }\n";
    }
}
