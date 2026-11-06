<?php
declare(strict_types=1);

namespace App\Models;

/**
 * Perhitungan gizi, biaya, dan kebutuhan bahan dijalankan di sisi server.
 *
 * Semua satuan lebih dahulu disetarakan ke satuan dasar bahan. Bila satuan tidak
 * dapat disetarakan, perhitungan berhenti dan dilaporkan sebagai masalah, bukan
 * diubah menjadi angka nol. Nilai gizi memakai rumus
 * (jumlah bagian yang dapat dimakan / dasar penyajian angka) x nilai gizi sehingga
 * faktor bagian yang dapat dimakan ikut terlihat dan tidak tersembunyi.
 */
final class NutritionCalculator
{
    /** @var array<string, float> satuan massa => faktor ke gram */
    private const MASS = [
        'mcg' => 0.000001,
        'mg' => 0.001,
        'gram' => 1.0,
        'g' => 1.0,
        'gr' => 1.0,
        'ons' => 28.3495,
        'kg' => 1000.0,
        'kilogram' => 1000.0,
    ];

    /** @var array<string, float> satuan volume => faktor ke mililiter */
    private const VOLUME = [
        'ml' => 1.0,
        'mililiter' => 1.0,
        'cc' => 1.0,
        'l' => 1000.0,
        'liter' => 1000.0,
        'sdt' => 5.0,
        'sendok teh' => 5.0,
        'sdm' => 15.0,
        'sendok makan' => 15.0,
    ];

    /**
     * Setarakan satu jumlah ke satuan dasar bahan.
     *
     * @param array<string, mixed> $ingredient
     * @return array{0: float|null, 1: string|null} jumlah dalam satuan dasar dan catatan konversi
     */
    public static function toBase(float $quantity, string $unit, array $ingredient): array
    {
        $unit = self::normalize($unit);
        $base = self::normalize((string) ($ingredient['base_unit'] ?? ''));
        $purchase = self::normalize((string) ($ingredient['purchase_unit'] ?? ''));
        $factor = (float) ($ingredient['conversion_factor'] ?? 0);

        if ($base === '') {
            return [null, 'Satuan dasar bahan belum diisi pada katalog bahan.'];
        }

        if ($unit === '') {
            return [null, sprintf('Satuan jumlah bahan belum diisi. Gunakan %s.', $base)];
        }

        if ($unit === $base) {
            return [round($quantity, 4), null];
        }

        if ($purchase !== '' && $unit === $purchase && $factor > 0) {
            return [
                round($quantity * $factor, 4),
                sprintf('1 %s = %s %s', $purchase, self::decimal($factor), $base),
            ];
        }

        if (isset(self::MASS[$unit]) && isset(self::MASS[$base])) {
            return [
                round($quantity * (self::MASS[$unit] / self::MASS[$base]), 4),
                sprintf('%s dihitung sebagai satuan massa terhadap %s.', $unit, $base),
            ];
        }

        if (isset(self::VOLUME[$unit]) && isset(self::VOLUME[$base])) {
            return [
                round($quantity * (self::VOLUME[$unit] / self::VOLUME[$base]), 4),
                sprintf('%s dihitung sebagai satuan volume terhadap %s.', $unit, $base),
            ];
        }

        return [
            null,
            sprintf(
                'Satuan "%s" tidak dapat disetarakan dengan satuan dasar "%s". Perbaiki satuan atau isi faktor konversi bahan.',
                $unit,
                $base
            ),
        ];
    }

    /**
     * Nilai gizi satu bahan untuk sejumlah tertentu pada satuan dasar bahan.
     *
     * @param array<string, mixed> $ingredient
     * @param array<string, array<string, mixed>> $nutrients nutrient_code => baris nilai gizi
     * @return array{0: array<string, float>, 1: array<int, string>, 2: bool}
     */
    public static function nutrients(float $baseQuantity, array $ingredient, array $nutrients): array
    {
        $values = [];
        $issues = [];
        $estimated = false;

        $edible = (float) ($ingredient['edible_factor'] ?? 0);
        $edibleQuantity = ($edible > 0 && $edible < 1) ? $baseQuantity * $edible : $baseQuantity;
        $base = self::normalize((string) ($ingredient['base_unit'] ?? 'gram'));

        foreach ($nutrients as $row) {
            $code = (string) $row['nutrient_code'];
            $amount = (float) $row['nutrient_value'];
            $referenceAmount = (float) $row['reference_amount'];
            $referenceUnit = self::normalize((string) ($row['reference_unit'] ?? 'gram'));

            if ($referenceAmount <= 0) {
                $issues[] = sprintf('Dasar penyajian angka gizi "%s" tidak valid.', $code);
                continue;
            }

            $scaled = self::convertReference($edibleQuantity, $base, $referenceUnit);
            if ($scaled === null) {
                $issues[] = sprintf(
                    'Angka gizi "%s" memakai dasar %s sedangkan satuan bahan %s, tidak dapat disetarakan.',
                    $code,
                    $referenceUnit,
                    $base
                );
                continue;
            }

            $values[$code] = ($values[$code] ?? 0.0) + ($scaled / $referenceAmount) * $amount;

            if ((string) ($row['value_source'] ?? 'referensi') === 'estimasi') {
                $estimated = true;
            }
        }

        return [$values, $issues, $estimated];
    }

    /**
     * Harga satu bahan untuk sejumlah tertentu pada satuan dasar bahan.
     *
     * @param array<string, mixed> $ingredient
     * @return array{0: float|null, 1: string|null}
     */
    public static function cost(float $baseQuantity, array $ingredient): array
    {
        $price = $ingredient['reference_price'] ?? null;

        if ($price === null || (float) $price <= 0) {
            return [null, 'Harga rujukan bahan belum diisi.'];
        }

        $basis = self::normalize((string) ($ingredient['price_basis'] ?? ''));
        $base = self::normalize((string) ($ingredient['base_unit'] ?? 'gram'));

        if ($basis === '') {
            $basis = $base;
        }

        [$basisInBase] = self::toBase(1.0, $basis, $ingredient);

        if ($basisInBase === null || $basisInBase <= 0) {
            return [null, sprintf(
                'Dasar harga "%s" tidak dapat disetarakan dengan satuan dasar "%s".',
                $basis,
                $base
            )];
        }

        return [round(($baseQuantity / $basisInBase) * (float) $price, 4), null];
    }

    /**
     * Hitung komposisi satu menu beserta kontribusi tiap bahan.
     *
     * @param array<int, array{ingredient: array<string, mixed>, quantity: float|string, unit: string}> $lines
     * @param array<int, array<string, array<string, mixed>>> $nutrientMap ingredient_id => nutrient_code => baris
     * @return array<string, mixed>
     */
    public static function menu(array $lines, array $nutrientMap): array
    {
        $totals = [];
        $costTotal = 0.0;
        $costKnown = false;
        $costMissing = 0;
        $rows = [];
        $issues = [];
        $missing = 0;
        $usesEstimate = false;

        foreach ($lines as $line) {
            $ingredient = $line['ingredient'];
            $id = (int) $ingredient['id'];
            $nutrients = $nutrientMap[$id] ?? [];
            $name = (string) $ingredient['ingredient_name'];
            $quantity = (float) $line['quantity'];

            [$baseQuantity, $unitIssue] = self::toBase($quantity, (string) $line['unit'], $ingredient);

            if ($baseQuantity === null) {
                $issues[] = $name . ': ' . ($unitIssue ?? 'Satuan tidak sesuai.');
                $rows[] = self::row($ingredient, $quantity, (string) $line['unit'], null, [], null, 'satuan');
                continue;
            }

            if ($nutrients === []) {
                $missing++;
                $issues[] = $name . ': data gizi belum tersedia.';
            } else {
                [$values, $valueIssues, $estimated] = self::nutrients($baseQuantity, $ingredient, $nutrients);
                foreach ($values as $code => $value) {
                    $totals[$code] = ($totals[$code] ?? 0.0) + $value;
                }
                foreach ($valueIssues as $issue) {
                    $issues[] = $name . ': ' . $issue;
                }
                $usesEstimate = $usesEstimate || $estimated;
            }

            [$cost, $costIssue] = self::cost($baseQuantity, $ingredient);
            if ($cost !== null) {
                $costTotal += $cost;
                $costKnown = true;
            } else {
                $costMissing++;
            }

            $rows[] = self::row($ingredient, $quantity, (string) $line['unit'], $baseQuantity, [], $cost, 'siap');
        }

        foreach ($rows as $index => $row) {
            if ($row['base_quantity'] === null) {
                continue;
            }
            $ingredient = $lines[$index]['ingredient'];
            [$values] = self::nutrients((float) $row['base_quantity'], $ingredient, $nutrientMap[$row['ingredient_id']] ?? []);
            $rows[$index]['nutrients'] = self::roundTotals($values);
        }

        $materials = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['base_quantity'] !== null
        ));

        $count = count($lines);
        $status = match (true) {
            $count === 0 || $missing === $count => 'belum_tersedia',
            $missing > 0 => 'sebagian',
            default => 'lengkap',
        };

        return [
            'per_serving' => self::roundTotals($totals),
            'cost_per_serving' => $costKnown ? round($costTotal, 2) : null,
            'cost_missing' => $costMissing,
            'ingredients' => $rows,
            'materials' => $materials,
            'missing' => $missing,
            'lines' => $count,
            'issues' => array_values(array_unique($issues)),
            'nutrition_status' => $status,
            'uses_estimate' => $usesEstimate,
        ];
    }

    /**
     * Baca hasil perhitungan lalu simpan sebagai ringkasan satu porsi untuk kolom
     * estimasi pada tabel menus.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function snapshot(array $result): array
    {
        $perServing = $result['per_serving'] ?? [];

        return [
            'estimated_energy_kcal' => isset($perServing['energy_kcal']) ? (float) $perServing['energy_kcal'] : null,
            'estimated_protein_g' => isset($perServing['protein_g']) ? (float) $perServing['protein_g'] : null,
            'estimated_carb_g' => isset($perServing['carb_g']) ? (float) $perServing['carb_g'] : null,
            'estimated_fat_g' => isset($perServing['fat_g']) ? (float) $perServing['fat_g'] : null,
            'estimated_fiber_g' => isset($perServing['fiber_g']) ? (float) $perServing['fiber_g'] : null,
            'estimated_cost_per_serving' => $result['cost_per_serving'],
            'nutrition_status' => $result['nutrition_status'],
        ];
    }

    /**
     * Kebutuhan bahan untuk sejumlah porsi. Angka ini yang dipakai modul pengadaan
     * dan gudang pada tahap berikutnya; perhitungan tidak menyentuh stok.
     *
     * @param array<int, array<string, mixed>> $materials hasil menu()['materials']
     * @param array<int, int> $portions ingredient_id => total porsi
     * @return array<int, array<string, mixed>>
     */
    public static function requirements(array $materials, array $portions): array
    {
        $needs = [];

        foreach ($materials as $material) {
            $id = (int) $material['ingredient_id'];
            $count = (int) ($portions[$id] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $perServing = (float) $material['base_quantity'];
            $total = $perServing * $count;
            $unit = (string) $material['base_unit'];
            $purchase = self::normalize((string) ($material['purchase_unit'] ?? ''));
            $factor = (float) ($material['conversion_factor'] ?? 0);

            $purchaseQuantity = $purchase !== '' && $purchase !== $unit && $factor > 0
                ? round($total / $factor, 3)
                : null;

            $cost = $material['cost_per_serving'] === null
                ? null
                : round((float) $material['cost_per_serving'] * $count, 2);

            $needs[$id] = [
                'ingredient_id' => $id,
                'ingredient_code' => $material['ingredient_code'],
                'ingredient_name' => $material['ingredient_name'],
                'category_name' => $material['category_name'],
                'category_code' => $material['category_code'],
                'allergen_info' => $material['allergen_info'],
                'portions' => $count,
                'per_serving_base' => round($perServing, 4),
                'base_quantity' => round($total, 3),
                'base_unit' => $unit,
                'purchase_unit' => $material['purchase_unit'],
                'conversion_factor' => $material['conversion_factor'] ?? null,
                'purchase_quantity' => $purchaseQuantity,
                'conversion_note' => $purchaseQuantity === null
                    ? 'Faktor konversi belum diisi, gunakan satuan dasar ' . $unit . '.'
                    : sprintf('1 %s = %s %s', $purchase, self::decimal($factor), $unit),
                'cost_estimate' => $cost,
                'price_basis' => $material['price_basis'],
            ];
        }

        ksort($needs);

        return array_values($needs);
    }

    public static function normalize(string $unit): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($unit)));
    }

    /** @param array<string, float> $totals @return array<string, float> */
    private static function roundTotals(array $totals): array
    {
        $rounded = [];
        foreach ($totals as $code => $value) {
            $rounded[$code] = $code === 'energy_kcal' ? round($value, 2) : round($value, 3);
        }

        return $rounded;
    }

    private static function convertReference(float $quantity, string $base, string $referenceUnit): ?float
    {
        if ($referenceUnit === $base) {
            return $quantity;
        }

        if (isset(self::MASS[$base]) && isset(self::MASS[$referenceUnit])) {
            return $quantity * (self::MASS[$base] / self::MASS[$referenceUnit]);
        }

        if (isset(self::VOLUME[$base]) && isset(self::VOLUME[$referenceUnit])) {
            return $quantity * (self::VOLUME[$base] / self::VOLUME[$referenceUnit]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $ingredient
     * @param array<string, float> $values
     * @return array<string, mixed>
     */
    private static function row(
        array $ingredient,
        float $quantity,
        string $unit,
        ?float $baseQuantity,
        array $values,
        ?float $cost,
        string $state
    ): array {
        return [
            'ingredient_id' => (int) $ingredient['id'],
            'ingredient_code' => (string) ($ingredient['ingredient_code'] ?? ''),
            'ingredient_name' => (string) ($ingredient['ingredient_name'] ?? ''),
            'category_name' => (string) ($ingredient['category_name'] ?? ''),
            'category_code' => (string) ($ingredient['category_code'] ?? ''),
            'allergen_info' => $ingredient['allergen_info'] ?? null,
            'quantity' => round($quantity, 4),
            'unit' => self::normalize($unit),
            'base_quantity' => $baseQuantity,
            'base_unit' => (string) ($ingredient['base_unit'] ?? ''),
            'purchase_unit' => $ingredient['purchase_unit'] ?? null,
            'conversion_factor' => $ingredient['conversion_factor'] ?? null,
            'edible_factor' => $ingredient['edible_factor'] ?? null,
            'price_basis' => $ingredient['price_basis'] ?? null,
            'nutrients' => $values,
            'cost_per_serving' => $cost,
            'state' => $state,
        ];
    }

    private static function decimal(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $text === '' ? '0' : $text;
    }
}
