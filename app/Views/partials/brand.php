<?php
/**
 * Lockup merek: lambang resmi BGN + wordmark "SPPG Cangkring".
 *
 * @var string $brandTone   'inverted' (latar navy) atau 'light' (latar terang)
 * @var int    $brandEmblem tinggi lambang dalam piksel
 */
$brandTone = $brandTone ?? 'light';
$brandEmblem = $brandEmblem ?? 40;
$brandClass = $brandTone === 'inverted' ? 'brand-mark brand-inverted' : 'brand-mark';
?>
<span class="<?= e($brandClass) ?>">
    <img src="<?= asset('images/logo-bgn-emblem.png') ?>" alt="" width="<?= (int) $brandEmblem ?>" height="<?= (int) $brandEmblem ?>">
    <span class="brand-word"><span>SPPG</span><span>Cangkring</span></span>
</span>
