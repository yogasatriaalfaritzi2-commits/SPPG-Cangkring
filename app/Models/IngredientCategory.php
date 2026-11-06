<?php
declare(strict_types=1);

namespace App\Models;

/** Kelompok bahan makanan, dipakai pula oleh aturan rekomendasi menu. */
final class IngredientCategory extends CatalogCategory
{
    protected static string $table = 'ingredient_categories';

    protected static function usageSubqueries(): array
    {
        return ['ingredient_count' => 'SELECT COUNT(*) FROM food_ingredients f WHERE f.category_id = t.id'];
    }
}
