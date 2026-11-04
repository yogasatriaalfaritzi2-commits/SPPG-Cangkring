<?php
/**
 * Navigasi halaman umum.
 * Variabel: $result (array paginate), $basePath (string), $query (array filter).
 */
$total = (int) ($result['total'] ?? 0);
$page = (int) ($result['page'] ?? 1);
$pages = (int) ($result['pages'] ?? 1);
$perPage = (int) ($result['per_page'] ?? 10);

$from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$to = min($total, $page * $perPage);

$start = max(1, min($page - 2, $pages - 4));
$end = min($pages, $start + 4);

$pageLink = static function (int $target) use ($basePath, $query): string {
    return query_url($basePath, array_merge($query, ['page' => $target]));
};
?>
<div class="pagination-bar">
    <p class="pagination-summary">
        Menampilkan <?= e((string) $from) ?>&ndash;<?= e((string) $to) ?> dari <?= e((string) $total) ?> data
    </p>

    <?php if ($pages > 1): ?>
        <nav aria-label="Navigasi halaman">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e($pageLink(max(1, $page - 1))) ?>">
                        <i class="bi bi-chevron-left" aria-hidden="true"></i><span class="visually-hidden">Sebelumnya</span>
                    </a>
                </li>
                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= e($pageLink($i)) ?>" <?= $i === $page ? 'aria-current="page"' : '' ?>><?= e((string) $i) ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= e($pageLink(min($pages, $page + 1))) ?>">
                        <i class="bi bi-chevron-right" aria-hidden="true"></i><span class="visually-hidden">Berikutnya</span>
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
</div>
