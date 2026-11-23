<?php
declare(strict_types=1);
namespace App;

/** Uploaded files: stored outside public/, served only through permission-checked routes. */
final class Files
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    public const MAX_PER_OWNER = 30;
    /** Allowed types by real content (finfo), not by the name the browser sent. */
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'application/pdf' => 'pdf', 'image/vnd.dwg' => 'dwg'];
    /** Shown in the browser; everything else (DWG, HEIC) is a download. */
    private const INLINE = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    private const PATH_RE = '#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z]+$#';

    /** Pure: validation of an upload. Returns an error message or null. */
    public static function check(string $mime, int $size): ?string
    {
        if ($size <= 0) return 'Arquivo vazio.';
        if ($size > self::MAX_BYTES) return 'Cada arquivo pode ter no máximo 15 MB.';
        if (!isset(self::TYPES[$mime])) return 'Envie fotos (JPG, PNG, WebP, HEIC), PDF ou DWG.';
        return null;
    }

    /** Pure: a display name safe to show and to put in a download header. */
    public static function cleanName(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', basename($name)) ?? 'arquivo';
        return mb_substr(trim($name, ' ._') ?: 'arquivo', 0, 180);
    }

    /** Normalizes PHP's multi-file array into a list. */
    public static function list(array $f): array
    {
        if (!isset($f['name'])) return [];
        if (!is_array($f['name'])) return [$f];
        $out = [];
        foreach ($f['name'] as $i => $n) $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
        return $out;
    }

    /** Stores each valid upload; returns [saved count, error messages]. */
    public static function store(array $uploads, string $ownerType, int $ownerId, int $clientId, ?int $userId, bool $fromClient): array
    {
        $saved = 0; $errors = [];
        $count = (int) Db::q('SELECT COUNT(*) FROM files WHERE owner_type = ? AND owner_id = ?', [$ownerType, $ownerId])->fetchColumn();
        foreach ($uploads as $u) {
            if (($u['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $name = self::cleanName((string) $u['name']);
            if ($u['error'] === UPLOAD_ERR_INI_SIZE || $u['error'] === UPLOAD_ERR_FORM_SIZE) { $errors[] = "$name: arquivo grande demais."; continue; }
            if ($u['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($u['tmp_name'])) { $errors[] = "$name: não foi possível receber."; continue; }
            if ($count >= self::MAX_PER_OWNER) { $errors[] = 'Limite de ' . self::MAX_PER_OWNER . ' arquivos atingido.'; break; }
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($u['tmp_name']);
            if ($err = self::check($mime, (int) $u['size'])) { $errors[] = "$name: $err"; continue; }
            $rel = date('Y/m/') . bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
            if (!is_dir(dirname(storage_path('files/') . $rel))) mkdir(dirname(storage_path('files/') . $rel), 0750, true);
            if (!move_uploaded_file($u['tmp_name'], storage_path('files/') . $rel)) { $errors[] = "$name: falha ao salvar."; continue; }
            $ver = 1 + (int) Db::q('SELECT COUNT(*) FROM files WHERE owner_type = ? AND owner_id = ? AND name = ?', [$ownerType, $ownerId, $name])->fetchColumn();
            Db::q('INSERT INTO files (owner_type, owner_id, client_id, name, mime, size, path, version, uploaded_by, from_client) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$ownerType, $ownerId, $clientId, $name, $mime, (int) $u['size'], $rel, $ver, $userId, $fromClient ? 1 : 0]);
            $saved++; $count++;
        }
        return [$saved, $errors];
    }

    public static function forOwner(string $type, int $id): array
    {
        return Db::q('SELECT * FROM files WHERE owner_type = ? AND owner_id = ? ORDER BY id DESC', [$type, $id])->fetchAll();
    }

    public static function send(array $f, bool $download = false): never
    {
        $full = storage_path('files/') . $f['path'];
        if (!preg_match(self::PATH_RE, $f['path']) || !is_file($full)) abort(404);
        $inline = !$download && in_array($f['mime'], self::INLINE, true);
        header('Content-Type: ' . $f['mime']);
        header('Content-Length: ' . filesize($full));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($f['name']));
        header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
        readfile($full);
        exit;
    }

    public const PREVIEWABLE = ['image/jpeg', 'image/png', 'image/webp'];
    public const LOCKED_MSG = 'Os arquivos originais são liberados quando o pagamento do projeto estiver completo.';

    /**
     * Anti-calote: while the project isn't fully paid the client gets a small JPEG with a tiled watermark;
     * PDF, DWG and HEIC are refused. The original never leaves the server.
     */
    public static function sendPreview(array $f): never
    {
        $full = storage_path('files/') . $f['path'];
        if (!preg_match(self::PATH_RE, $f['path']) || !is_file($full)) abort(404);
        if (!in_array($f['mime'], self::PREVIEWABLE, true)) abort(403, self::LOCKED_MSG);
        $src = @imagecreatefromstring((string) file_get_contents($full)) ?: abort(404);
        $w = imagesx($src); $h = imagesy($src); $k = min(1, 1000 / max($w, $h)); // ponytail: 1000px is enough to judge, too small to print
        $img = imagescale($src, max(1, (int) ($w * $k)), max(1, (int) ($h * $k)));
        imagedestroy($src);
        [$w, $h] = [imagesx($img), imagesy($img)];
        $text = 'PRÉVIA · ' . mb_strtoupper(Brand::name());
        $font = dirname(__DIR__) . '/vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';
        $ink = imagecolorallocatealpha($img, 255, 255, 255, 70); $shade = imagecolorallocatealpha($img, 0, 0, 0, 95);
        $size = max(14, (int) ($w / 28));
        for ($y = -$h; $y < $h * 2; $y += $size * 6) {
            for ($x = -$w; $x < $w * 2; $x += $size * 16) {
                if (is_file($font)) { imagettftext($img, $size, 30, $x + 2, $y + 2, $shade, $font, $text); imagettftext($img, $size, 30, $x, $y, $ink, $font, $text); }
                else imagestring($img, 5, $x, $y, $text, $ink);
            }
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        imagejpeg($img, null, 70);
        exit;
    }

    public static function remove(array $f): void
    {
        $full = storage_path('files/') . $f['path'];
        if (preg_match(self::PATH_RE, $f['path']) && is_file($full)) unlink($full);
        Db::q('DELETE FROM files WHERE id = ?', [$f['id']]);
    }
}
