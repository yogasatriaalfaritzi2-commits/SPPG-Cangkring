<?php
declare(strict_types=1);

namespace App\Models;

/** Jenjang pendidikan sekolah penerima manfaat MBG. */
final class SchoolLevel extends CatalogCategory
{
    protected static string $table = 'school_levels';
    protected static string $codeColumn = 'level_code';
    protected static string $nameColumn = 'level_name';

    protected static function usageSubqueries(): array
    {
        return ['school_count' => 'SELECT COUNT(*) FROM schools s WHERE s.level_id = t.id'];
    }
}
