<?php
declare(strict_types=1);

namespace App\Models;

/** Kelompok penerima manfaat: siswa, guru, petugas piket, dan lain-lain. */
final class BeneficiaryCategory extends CatalogCategory
{
    protected static string $table = 'beneficiary_categories';

    protected static function usageSubqueries(): array
    {
        return [
            'record_count' => 'SELECT COUNT(*) FROM beneficiaries b WHERE b.category_id = t.id',
            'individual_count' => 'SELECT COUNT(*) FROM beneficiary_individuals i WHERE i.category_id = t.id',
        ];
    }
}
