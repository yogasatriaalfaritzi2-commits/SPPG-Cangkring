<?php
declare(strict_types=1);

namespace App\Models;

/** Kategori menu beserta slot waktunya. Kategori tertentu wajib diverifikasi ahli gizi. */
final class MenuCategory extends CatalogCategory
{
    /** @var array<string, string> */
    public const SLOTS = [
        'sarapan' => 'Sarapan',
        'makan_pagi' => 'Makan pagi',
        'makan_siang' => 'Makan siang',
        'makan_sore' => 'Makan sore',
    ];

    protected static string $table = 'menu_categories';

    protected static array $extraColumns = [
        'meal_slot' => 'string',
        'requires_verification' => 'bool',
    ];

    protected static function usageSubqueries(): array
    {
        return ['menu_count' => 'SELECT COUNT(*) FROM menus m WHERE m.category_id = t.id'];
    }

    public static function slotExists(string $slot): bool
    {
        return isset(self::SLOTS[$slot]);
    }
}
