<?php
declare(strict_types=1);
namespace App;

/** Pure proposal math. Rows are price_items records. Covered by tests/run.php. */
final class Pricing
{
    /** Line total: per-m² items respect the minimum (e.g. R$ 3.500); fixed/hourly are price × qty. */
    public static function line(array $item, float $qty): float
    {
        $total = round((float) $item['price'] * $qty, 2);
        if ($item['unit'] === 'm2' && $item['min_total'] !== null) $total = max($total, (float) $item['min_total']);
        return $total;
    }

    /** Preliminary study tier for an area: the smallest tier that fits, or null above the last one ("sob consulta"). */
    public static function studyFor(array $items, float $area): ?array
    {
        $tiers = array_values(array_filter($items, fn($i) => $i['max_area'] !== null && (int) $i['credit_rank'] === 2));
        usort($tiers, fn($a, $b) => (float) $a['max_area'] <=> (float) $b['max_area']);
        foreach ($tiers as $t) if (ceil($area) <= (float) $t['max_area']) return $t;
        return null;
    }

    /**
     * Credit for steps the client already paid: only the highest step counts (rank, then price),
     * and only against a full project (per-m² line). Never more than the project lines.
     */
    public static function credit(array $paidItems, float $projectSubtotal): float
    {
        if (!$paidItems || $projectSubtotal <= 0) return 0.0;
        usort($paidItems, fn($a, $b) => [(int) $b['credit_rank'], (float) $b['price']] <=> [(int) $a['credit_rank'], (float) $a['price']]);
        return min((float) $paidItems[0]['price'], $projectSubtotal);
    }

    /**
     * @param array $lines [[item, qty], ...]
     * @return array{lines: array, subtotal: float, credit: float, total: float}
     */
    public static function quote(array $lines, array $paidItems): array
    {
        $out = []; $subtotal = 0.0; $project = 0.0;
        foreach ($lines as [$item, $qty]) {
            $t = self::line($item, $qty);
            $out[] = ['label' => $item['label'], 'quantity' => $qty, 'unit_price' => (float) $item['price'], 'line_total' => $t];
            $subtotal += $t;
            if ($item['unit'] === 'm2') $project += $t;
        }
        $credit = self::credit($paidItems, $project);
        return ['lines' => $out, 'subtotal' => round($subtotal, 2), 'credit' => $credit, 'total' => round($subtotal - $credit, 2)];
    }
}
