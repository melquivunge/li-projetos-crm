<?php
declare(strict_types=1);
namespace App;

/** Client rules: validation, visibility scope and lookups. Pure parts are covered by tests/run.php. */
final class Clients
{
    public const STAGES = Opportunities::STAGES; // the client's stage mirrors its main opportunity
    public const SERVICES = ['Projetos de Arquitetura', 'Design de Interiores', 'Plantas Baixas', 'Consultoria', 'Detalhamento Executivo'];

    /** Brazilian mobile/landline → "+55DDDNUMBER", or null when it is not a valid number. */
    public static function normalizeWhatsapp(string $raw): ?string
    {
        $d = preg_replace('/\D/', '', $raw);
        if (strlen($d) === 10 || strlen($d) === 11) $d = '55' . $d;
        if (!preg_match('/^55[1-9][0-9]9?[0-9]{8}$/', $d)) return null;
        return '+' . $d;
    }

    /** @return array{0: array, 1: array<string,string>} [clean data, errors by field] */
    public static function validate(array $in, bool $requireContact = true): array
    {
        $t = fn(string $k, int $max) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
        $d = [
            'name' => $t('name', 160),
            'email' => mb_strtolower($t('email', 190)),
            'city' => $t('city', 120),
            'service' => $t('service', 80),
            'origin' => $t('origin', 160),
            'gclid' => $t('gclid', 255),
        ];
        $errors = [];
        if (mb_strlen($d['name']) < 2) $errors['name'] = 'Informe o nome (mínimo 2 letras).';
        $wa = trim((string) ($in['whatsapp'] ?? ''));
        $d['whatsapp'] = $wa === '' ? null : self::normalizeWhatsapp($wa);
        if ($wa !== '' && $d['whatsapp'] === null) $errors['whatsapp'] = 'WhatsApp inválido. Use DDD + número, ex.: (61) 99241-4304.';
        if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'E-mail inválido.';
        if ($requireContact && $d['whatsapp'] === null && $d['email'] === '' && !isset($errors['whatsapp'])) $errors['whatsapp'] = 'Informe WhatsApp ou e-mail.';
        if ($d['service'] !== '' && !in_array($d['service'], self::SERVICES, true)) $errors['service'] = 'Serviço inválido.';
        $area = str_replace(',', '.', trim((string) ($in['area_m2'] ?? '')));
        $d['area_m2'] = $area === '' ? null : (is_numeric($area) ? (float) $area : false);
        if ($d['area_m2'] === false || ($d['area_m2'] !== null && ($d['area_m2'] <= 0 || $d['area_m2'] > 99999))) { $errors['area_m2'] = 'Metragem inválida.'; $d['area_m2'] = null; }
        foreach (['email', 'city', 'service', 'origin', 'gclid'] as $k) if ($d[$k] === '') $d[$k] = null;
        return [$d, $errors];
    }

    /** SQL fragment limiting rows to what the current user may see. */
    public static function scope(string $alias = 'c'): array
    {
        return Auth::isAdmin() ? ['1=1', []] : ["$alias.owner_id = ?", [Auth::id()]];
    }

    /** Loads a client or aborts with 404/403. Every per-client route goes through here. */
    public static function findOrFail(int $id): array
    {
        $c = Db::q('SELECT c.*, u.name AS owner_name FROM clients c LEFT JOIN users u ON u.id = c.owner_id WHERE c.id = ?', [$id])->fetch();
        if (!$c) abort(404);
        if (!Auth::canSeeClient($c)) abort(403);
        return $c;
    }

    public static function event(int $clientId, string $type, string $body): void
    {
        Db::q('INSERT INTO client_events (client_id, user_id, type, body) VALUES (?,?,?,?)', [$clientId, Auth::id(), $type, $body]);
    }

    /** CSV cell safe against spreadsheet formula injection. */
    public static function csvCell(mixed $v): string
    {
        $s = (string) $v;
        if (preg_match('/^\+\d+$/', $s)) return $s; // a phone number, not a formula
        return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
    }
}
