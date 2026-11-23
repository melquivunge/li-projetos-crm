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

    public const UF = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
    public const PROPERTY_TYPES = ['apartamento' => 'Apartamento', 'casa' => 'Casa', 'comercial' => 'Comercial', 'outro' => 'Outro'];
    public const PROPERTY_STATUS = ['planta' => 'Na planta', 'obra' => 'Em obra', 'pronto' => 'Pronto', 'reforma' => 'Para reformar'];
    public const CONTACT_PREF = ['whatsapp' => 'WhatsApp', 'ligacao' => 'Ligação', 'email' => 'E-mail'];
    /** Profile columns saved by saveProfile() (the site API never touches them). */
    public const PROFILE = ['document', 'rg', 'address_zip', 'address_street', 'address_number', 'address_extra', 'address_district', 'address_state',
        'property_address', 'property_type', 'property_condo', 'property_status', 'birth_date', 'profession', 'partner_name', 'partner_whatsapp', 'contact_pref', 'contact_time', 'referral'];

    /** Pure: CPF (11 digits) or CNPJ (14) with valid check digits → formatted; anything else → null. */
    public static function document(string $raw): ?string
    {
        $d = preg_replace('/\D/', '', $raw);
        if (!in_array(strlen($d), [11, 14], true) || preg_match('/^(\d)\1+$/', $d)) return null;
        $digit = function (string $base, array $w): int { $s = 0; foreach ($w as $i => $k) $s += (int) $base[$i] * $k; $r = $s % 11; return $r < 2 ? 0 : 11 - $r; };
        if (strlen($d) === 11) {
            $a = $digit($d, range(10, 2)); $b = $digit($d, range(11, 2));
            return $d[9] == $a && $d[10] == $b ? substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9) : null;
        }
        $a = $digit($d, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]); $b = $digit($d, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        return $d[12] == $a && $d[13] == $b ? substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12) : null;
    }

    /** Pure: profile fields → [clean data, errors]. Empty stays null. */
    public static function validateProfile(array $in, string $today): array
    {
        $t = fn(string $k, int $max) => ($v = mb_substr(trim((string) ($in[$k] ?? '')), 0, $max)) === '' ? null : $v;
        $d = ['rg' => $t('rg', 20), 'address_street' => $t('address_street', 160), 'address_number' => $t('address_number', 20), 'address_extra' => $t('address_extra', 80),
            'address_district' => $t('address_district', 80), 'property_address' => $t('property_address', 255), 'property_condo' => $t('property_condo', 120),
            'profession' => $t('profession', 120), 'partner_name' => $t('partner_name', 160), 'contact_time' => $t('contact_time', 60), 'referral' => $t('referral', 160)];
        $e = [];
        $doc = $t('document', 30);
        $d['document'] = $doc === null ? null : self::document($doc);
        if ($doc !== null && !$d['document']) $e['document'] = 'CPF ou CNPJ inválido (confira os dígitos).';
        $zip = preg_replace('/\D/', '', (string) ($in['address_zip'] ?? ''));
        $d['address_zip'] = $zip === '' ? null : (strlen($zip) === 8 ? substr($zip, 0, 5) . '-' . substr($zip, 5) : null);
        if ($zip !== '' && !$d['address_zip']) $e['address_zip'] = 'CEP com 8 números.';
        $uf = strtoupper((string) $t('address_state', 2));
        $d['address_state'] = in_array($uf, self::UF, true) ? $uf : null;
        if ($uf !== '' && !$d['address_state']) $e['address_state'] = 'UF inválida.';
        foreach (['property_type' => self::PROPERTY_TYPES, 'property_status' => self::PROPERTY_STATUS, 'contact_pref' => self::CONTACT_PREF] as $k => $opts) $d[$k] = isset($opts[$in[$k] ?? '']) ? $in[$k] : null;
        $bd = (string) ($in['birth_date'] ?? '');
        $d['birth_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd) && strtotime($bd) && $bd >= '1900-01-01' && $bd < $today ? $bd : null;
        if ($bd !== '' && !$d['birth_date']) $e['birth_date'] = 'Data de nascimento inválida.';
        $pw = trim((string) ($in['partner_whatsapp'] ?? ''));
        $d['partner_whatsapp'] = $pw === '' ? null : self::normalizeWhatsapp($pw);
        if ($pw !== '' && !$d['partner_whatsapp']) $e['partner_whatsapp'] = 'WhatsApp inválido.';
        return [$d, $e];
    }

    public static function saveProfile(int $id, array $d): void
    {
        Db::q('UPDATE clients SET ' . implode(', ', array_map(fn($k) => "$k = ?", self::PROFILE)) . ' WHERE id = ?', [...array_map(fn($k) => $d[$k] ?? null, self::PROFILE), $id]);
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
