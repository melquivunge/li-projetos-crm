<?php
declare(strict_types=1);
namespace App;

/** Briefing questions are kept as simple text: one per line, "Label | type | option;option". */
final class Briefings
{
    public const TYPES = ['text', 'textarea', 'number', 'select', 'titulo', 'roteiro'];
    /** 'titulo' (section heading) and 'roteiro' (what to say, from the meeting script) are shown, never answered. */
    public const DISPLAY_ONLY = ['titulo', 'roteiro'];
    public const KINDS = ['servico' => 'Briefing', 'reuniao' => 'Reunião inicial'];
    public const MEETING_TEMPLATE = 'Reunião inicial';
    public const STATUS = ['rascunho' => 'Rascunho', 'enviado' => 'Enviado ao cliente', 'respondido' => 'Respondido', 'validado' => 'Validado pelo cliente'];

    /** Pure: parses the question text into [key, label, type, options]. Keys are stable per label. */
    public static function parse(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if (($parts[0] ?? '') === '') continue;
            $type = in_array($parts[1] ?? '', self::TYPES, true) ? $parts[1] : 'text';
            $opts = $type === 'select' ? array_values(array_filter(array_map('trim', explode(';', $parts[2] ?? '')))) : [];
            if ($type === 'select' && !$opts) $type = 'text';
            $out[] = ['key' => substr(md5(mb_strtolower($parts[0])), 0, 10), 'label' => mb_substr($parts[0], 0, $type === 'roteiro' ? 800 : 200), 'type' => $type, 'options' => $opts];
        }
        return $out;
    }

    /** Pure: keeps only answers to known questions, trimmed; selects must match an option. */
    public static function cleanAnswers(array $questions, array $in): array
    {
        $out = [];
        foreach ($questions as $q) {
            if (in_array($q['type'], self::DISPLAY_ONLY, true)) continue;
            $v = trim((string) ($in[$q['key']] ?? ''));
            if ($q['type'] === 'select' && $v !== '' && !in_array($v, $q['options'], true)) $v = '';
            if ($q['type'] === 'number') $v = preg_replace('/[^\d.,]/', '', $v) ?? '';
            $out[$q['key']] = mb_substr($v, 0, $q['type'] === 'textarea' ? 4000 : 300);
        }
        return $out;
    }

    public static function answered(array $questions, array $answers): int
    {
        return count(array_filter($questions, fn($q) => !in_array($q['type'], self::DISPLAY_ONLY, true) && ($answers[$q['key']] ?? '') !== ''));
    }

    /** Pure: questions that take an answer (headings and script lines don't count). */
    public static function inputs(array $questions): array
    {
        return array_values(array_filter($questions, fn($q) => !in_array($q['type'], self::DISPLAY_ONLY, true)));
    }

    public static function load(int $id): array
    {
        $b = Db::q('SELECT * FROM briefings WHERE id = ?', [$id])->fetch() ?: abort(404);
        $c = Clients::findOrFail((int) $b['client_id']);
        return [$b, $c];
    }
}
