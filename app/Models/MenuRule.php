<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;
use RuntimeException;

/**
 * Aturan rekomendasi menu. Aturan disimpan sebagai data sehingga administrator atau
 * ahli gizi dapat mengubah ambang, pesan, dan tingkatannya tanpa mengubah kode
 * aplikasi. Mesin penilaian hanya membaca aturan aktif dan mengembalikan temuan
 * yang menjelaskan alasannya; keputusan akhir tetap berada pada ahli gizi.
 */
final class MenuRule extends Model
{
    protected static string $table = 'menu_rules';

    public const SCOPE_MENU = 'menu';
    public const SCOPE_WEEK = 'mingguan';

    /** @var array<int, string> */
    public const TYPES = ['kategori_min', 'kategori_max', 'gizi_min', 'gizi_maks', 'variasi_min', 'biaya_maks'];

    /** @var array<int, string> */
    public const SEVERITIES = ['info', 'peringatan', 'harus'];

    /** @return array<int, array<string, mixed>> */
    public static function allOrdered(): array
    {
        return self::fetchAll(
            'SELECT * FROM menu_rules ORDER BY applies_to ASC, rule_type ASC, rule_name ASC'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function active(string $appliesTo): array
    {
        return self::fetchAll(
            'SELECT * FROM menu_rules WHERE is_active = 1 AND applies_to = :scope
             ORDER BY FIELD(rule_severity, \'harus\', \'peringatan\', \'info\'), rule_name ASC',
            ['scope' => $appliesTo]
        );
    }

    public static function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) AS total FROM menu_rules WHERE rule_code = :code';
        $params = ['code' => $code];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        return (int) (self::fetchOne($sql, $params)['total'] ?? 0) > 0;
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        Database::execute(
            'INSERT INTO menu_rules (
                rule_code, rule_name, rule_type, applies_codes, threshold_value, applies_to,
                message, rule_severity, is_active, created_by, updated_by, created_at, updated_at
             ) VALUES (
                :rule_code, :rule_name, :rule_type, :applies_codes, :threshold_value, :applies_to,
                :message, :rule_severity, :is_active, :created_by, :updated_by, NOW(), NOW()
             )',
            self::values($data)
        );

        return (int) Database::lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public static function update(int $id, array $data): void
    {
        $values = self::values($data);
        unset($values['created_by']);

        Database::execute(
            'UPDATE menu_rules SET
                rule_code = :rule_code, rule_name = :rule_name, rule_type = :rule_type,
                applies_codes = :applies_codes, threshold_value = :threshold_value,
                applies_to = :applies_to, message = :message, rule_severity = :rule_severity,
                is_active = :is_active, updated_by = :updated_by, updated_at = NOW()
             WHERE id = :id',
            $values + ['id' => $id]
        );
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM menu_rules WHERE id = :id', ['id' => $id]);
    }

    /**
     * Nilai satu menu terhadap aturan tingkat menu.
     *
     * @param array<int, array<string, mixed>> $rules
     * @param array<string, mixed> $computation hasil NutritionCalculator::menu()
     * @return array<int, array<string, mixed>>
     */
    public static function assess(array $rules, array $computation): array
    {
        $findings = [];

        foreach ($rules as $rule) {
            $codes = self::codes((string) ($rule['applies_codes'] ?? ''));
            $threshold = $rule['threshold_value'] === null ? null : (float) $rule['threshold_value'];
            $type = (string) $rule['rule_type'];
            $value = null;
            $passed = true;

            switch ($type) {
                case 'kategori_min':
                    $value = self::categoryCount($computation, $codes);
                    $passed = $threshold === null || $value >= $threshold;
                    break;

                case 'kategori_max':
                    $value = self::categoryCount($computation, $codes);
                    $passed = $threshold === null || $value <= $threshold;
                    break;

                case 'gizi_min':
                    // Kode disimpan huruf besar, angka hasil perhitungan memakai kode huruf kecil.
                    $code = mb_strtolower((string) ($codes[0] ?? ''));
                    $value = isset($computation['per_serving'][$code])
                        ? (float) $computation['per_serving'][$code]
                        : null;
                    $passed = $value !== null && $threshold !== null && $value >= $threshold;
                    break;

                case 'gizi_maks':
                    $code = mb_strtolower((string) ($codes[0] ?? ''));
                    $value = isset($computation['per_serving'][$code])
                        ? (float) $computation['per_serving'][$code]
                        : null;
                    $passed = $value === null || $threshold === null || $value <= $threshold;
                    break;

                case 'biaya_maks':
                    $value = $computation['cost_per_serving'] === null
                        ? null
                        : (float) $computation['cost_per_serving'];
                    $passed = $value === null || $threshold === null || $value <= $threshold;
                    break;

                default:
                    // Aturan mingguan tidak dinilai pada satu menu.
                    continue 2;
            }

            $findings[] = [
                'rule_code' => (string) $rule['rule_code'],
                'rule_name' => (string) $rule['rule_name'],
                'rule_type' => $type,
                'severity' => (string) $rule['rule_severity'],
                'message' => (string) $rule['message'],
                'threshold' => $threshold,
                'value' => $value,
                'passed' => $passed,
            ];
        }

        return $findings;
    }

    /**
     * Nilai rencana mingguan: variasi bahan dan keterulangan menu.
     *
     * @param array<int, array<string, mixed>> $rules
     * @param array<int, array<string, mixed>> $computations satu entri per jadwal
     * @return array<int, array<string, mixed>>
     */
    public static function assessWeek(array $rules, array $computations): array
    {
        $ingredients = [];
        $menus = [];
        $findings = [];

        foreach ($computations as $item) {
            $computation = $item['computation'] ?? [];
            foreach ($computation['materials'] ?? [] as $material) {
                $ingredients[(int) $material['ingredient_id']] = true;
            }
            $menus[] = (int) ($item['menu_id'] ?? 0);
        }

        $distinct = count($ingredients);
        $repeat = count($menus) - count(array_unique(array_filter($menus)));

        foreach ($rules as $rule) {
            $threshold = $rule['threshold_value'] === null ? null : (float) $rule['threshold_value'];
            $type = (string) $rule['rule_type'];

            if ($type === 'variasi_min') {
                $findings[] = [
                    'rule_code' => (string) $rule['rule_code'],
                    'rule_name' => (string) $rule['rule_name'],
                    'rule_type' => $type,
                    'severity' => (string) $rule['rule_severity'],
                    'message' => (string) $rule['message'],
                    'threshold' => $threshold,
                    'value' => $distinct,
                    'passed' => $threshold === null || $distinct >= $threshold,
                ];
            }
        }

        if ($menus !== []) {
            $findings[] = [
                'rule_code' => 'OBS_KETERULANGAN',
                'rule_name' => 'Pengamatan keterulangan menu mingguan',
                'rule_type' => 'pengamatan',
                'severity' => 'info',
                'message' => $repeat > 0
                    ? 'Rencana ini memakai ' . $repeat . ' penempatan menu yang sama. Tidak masalah bila disengaja.'
                    : 'Tidak ada menu yang berulang pada rencana ini.',
                'threshold' => null,
                'value' => $repeat,
                'passed' => true,
            ];
        }

        return $findings;
    }

    /**
     * Susun saran menu mingguan berbasis aturan. Menu yang sama tidak ditempatkan dua kali
     * bila masih ada pilihan lain, dan saran disusun agar temuan 'harus' tidak muncul.
     *
     * @param array<int, array<string, mixed>> $candidates menu berstatus disetujui beserta perhitungannya
     * @param array<int, array<string, mixed>> $rules
     * @param array<int, string> $dates tanggal pelayanan
     * @return array<int, array<string, mixed>>
     */
    public static function recommend(array $candidates, array $rules, array $dates): array
    {
        $ranked = [];
        foreach ($candidates as $candidate) {
            $findings = self::assess($rules, $candidate['computation'] ?? []);
            $blocking = 0;
            $warnings = 0;
            foreach ($findings as $finding) {
                if ($finding['passed']) {
                    continue;
                }
                if ($finding['severity'] === 'harus') {
                    $blocking++;
                } else {
                    $warnings++;
                }
            }

            $ranked[] = [
                'menu' => $candidate,
                'blocking' => $blocking,
                'warnings' => $warnings,
                'findings' => $findings,
                'category' => (string) ($candidate['category_name'] ?? ''),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => [$a['blocking'], $a['warnings']]
            <=> [$b['blocking'], $b['warnings']]);

        $picks = [];
        $used = [];
        $lastCategory = '';

        foreach ($dates as $date) {
            $choice = null;
            foreach ($ranked as $index => $option) {
                if (isset($used[$index])) {
                    continue;
                }
                if ($option['category'] === $lastCategory && count($ranked) - count($used) > 1) {
                    continue;
                }
                $choice = ['index' => $index, 'option' => $option];
                break;
            }

            if ($choice === null) {
                foreach ($ranked as $index => $option) {
                    if ($option['blocking'] === 0) {
                        $choice = ['index' => $index, 'option' => $option];
                        break;
                    }
                }
            }

            if ($choice === null && $ranked !== []) {
                $choice = ['index' => array_key_first($ranked), 'option' => $ranked[array_key_first($ranked)]];
            }

            if ($choice === null) {
                break;
            }

            $used[$choice['index']] = true;
            $lastCategory = $choice['option']['category'];
            $picks[] = [
                'date' => $date,
                'menu' => $choice['option']['menu'],
                'findings' => $choice['option']['findings'],
                'blocking' => $choice['option']['blocking'],
                'warnings' => $choice['option']['warnings'],
            ];
        }

        return $picks;
    }

    /** Batas biaya per porsi dari aturan biaya_maks aktif, bila ada. */
    public static function costCeiling(): ?float
    {
        $row = self::fetchOne(
            "SELECT threshold_value FROM menu_rules
             WHERE is_active = 1 AND rule_type = 'biaya_maks' AND threshold_value IS NOT NULL
             ORDER BY updated_at DESC, id DESC LIMIT 1"
        );

        return $row === null ? null : (float) $row['threshold_value'];
    }

    public static function nextCode(): string
    {
        for ($sequence = 1; $sequence <= 999; $sequence++) {
            $candidate = sprintf('RULE-%03d', $sequence);
            if (!self::codeExists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Kode aturan rekomendasi sudah habis.');
    }

    /** @return array<int, string> */
    public static function codes(string $list): array
    {
        $codes = [];
        foreach (explode(',', $list) as $item) {
            $item = mb_strtoupper(trim($item));
            if ($item !== '') {
                $codes[] = $item;
            }
        }

        return array_values(array_unique($codes));
    }

    /** @param array<string, mixed> $computation @param array<int, string> $codes */
    private static function categoryCount(array $computation, array $codes): int
    {
        if ($codes === []) {
            return 0;
        }

        $count = 0;
        foreach ($computation['ingredients'] ?? [] as $row) {
            if (in_array(mb_strtoupper((string) ($row['category_code'] ?? '')), $codes, true)) {
                $count++;
            }
        }

        return $count;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function values(array $data): array
    {
        $threshold = $data['threshold_value'] ?? '';
        $codes = $data['applies_codes'] ?? '';

        if (is_array($codes)) {
            $codes = implode(',', $codes);
        }

        $codes = (string) $codes;
        $appliesTo = (string) ($data['applies_to'] ?? self::SCOPE_MENU);
        $severity = (string) ($data['rule_severity'] ?? 'peringatan');

        return [
            'rule_code' => mb_strtoupper(trim((string) $data['rule_code'])),
            'rule_name' => (string) $data['rule_name'],
            'rule_type' => in_array((string) $data['rule_type'], self::TYPES, true)
                ? (string) $data['rule_type']
                : 'kategori_min',
            'applies_codes' => $codes === '' ? null : mb_substr(mb_strtoupper($codes), 0, 255),
            'threshold_value' => $threshold === '' ? null : round((float) $threshold, 4),
            'applies_to' => $appliesTo === self::SCOPE_WEEK ? self::SCOPE_WEEK : self::SCOPE_MENU,
            'message' => mb_substr((string) $data['message'], 0, 255),
            'rule_severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'peringatan',
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'created_by' => $data['created_by'] ?? null,
            'updated_by' => $data['updated_by'] ?? null,
        ];
    }
}
