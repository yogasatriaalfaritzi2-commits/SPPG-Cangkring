<?php
$flashMessages = \App\Core\Session::getFlashes();
$flashTypes = ['success', 'danger', 'warning', 'info'];
$autoHideTypes = ['success', 'info'];
?>
<?php if ($flashMessages !== []): ?>
    <div class="toast-container app-toasts">
        <?php foreach ($flashMessages as $flash): ?>
            <?php
            $flashType = in_array($flash['type'], $flashTypes, true) ? $flash['type'] : 'info';
            $autoHide = in_array($flashType, $autoHideTypes, true);
            ?>
            <div class="toast app-toast text-bg-<?= e($flashType) ?> show" role="status"
                 aria-live="<?= $flashType === 'danger' ? 'assertive' : 'polite' ?>" aria-atomic="true"
                 data-toast-autohide="<?= $autoHide ? '1' : '0' ?>">
                <div class="toast-body">
                    <i class="bi <?= e(alert_icon($flashType)) ?> app-toast-icon" aria-hidden="true"></i>
                    <span><?= e($flash['message']) ?></span>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Tutup"></button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
