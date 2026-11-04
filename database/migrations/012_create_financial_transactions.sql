-- Tahap 4: transaksi keuangan proyek pembangunan.
-- Realisasi pengeluaran hanya dihitung dari transaksi berstatus approved (lihat model),
-- dan biaya pembangunan dipisahkan dari biaya operasional MBG melalui cost_center.

CREATE TABLE IF NOT EXISTS financial_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_code VARCHAR(30) NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    budget_plan_id BIGINT UNSIGNED NULL,
    budget_item_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    cost_center ENUM('construction', 'operations') NOT NULL DEFAULT 'construction',
    transaction_type ENUM('expense', 'income', 'refund', 'adjustment') NOT NULL DEFAULT 'expense',
    transaction_date DATE NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    payment_method ENUM('cash', 'transfer', 'budget', 'other') NOT NULL DEFAULT 'cash',
    recipient VARCHAR(150) NOT NULL,
    reference_number VARCHAR(80) NULL,
    status ENUM('draft', 'pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'draft',
    reversal_of BIGINT UNSIGNED NULL,
    recorded_by BIGINT UNSIGNED NULL,
    submitted_by BIGINT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    decision_note VARCHAR(500) NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancel_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transactions_code (transaction_code),
    UNIQUE KEY uq_transactions_reference (project_id, reference_number),
    KEY idx_transactions_project (project_id, status, transaction_date),
    KEY idx_transactions_category (category_id, status),
    KEY idx_transactions_cost_center (cost_center, status, transaction_date),
    KEY idx_transactions_type (transaction_type, status),
    KEY idx_transactions_recorder (recorded_by),
    KEY idx_transactions_approver (approved_by),
    KEY idx_transactions_reversal (reversal_of),
    KEY idx_transactions_plan (budget_plan_id),
    KEY idx_transactions_item (budget_item_id),
    CONSTRAINT fk_transactions_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_transactions_plan FOREIGN KEY (budget_plan_id) REFERENCES budget_plans (id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_item FOREIGN KEY (budget_item_id) REFERENCES budget_items (id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_category FOREIGN KEY (category_id) REFERENCES budget_categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_reversal FOREIGN KEY (reversal_of) REFERENCES financial_transactions (id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_recorder FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_submitter FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_transactions_canceller FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_transactions_amount CHECK (amount <> 0),
    CONSTRAINT chk_transactions_positive CHECK (transaction_type = 'adjustment' OR amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id BIGINT UNSIGNED NOT NULL,
    action ENUM('create', 'update', 'submit', 'approve', 'reject', 'cancel', 'correct') NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    note VARCHAR(500) NULL,
    amount_before DECIMAL(18,2) NULL,
    amount_after DECIMAL(18,2) NULL,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_transaction_approvals_tx (transaction_id, created_at),
    KEY idx_transaction_approvals_actor (actor_id),
    CONSTRAINT fk_transaction_approvals_tx FOREIGN KEY (transaction_id) REFERENCES financial_transactions (id) ON DELETE CASCADE,
    CONSTRAINT fk_transaction_approvals_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bukti transaksi memakai tabel dokumen proyek yang sudah ada agar penyimpanan,
-- validasi isi berkas, dan pembatasan aksesnya tidak diduplikasi.
ALTER TABLE project_documents
    MODIFY COLUMN category ENUM('initial', 'construction', 'result', 'supporting', 'minutes', 'contract', 'receipt') NOT NULL DEFAULT 'supporting';

ALTER TABLE project_documents ADD COLUMN financial_transaction_id BIGINT UNSIGNED NULL AFTER progress_report_id;

ALTER TABLE project_documents ADD KEY idx_documents_transaction (financial_transaction_id);

ALTER TABLE project_documents ADD CONSTRAINT fk_documents_transaction
    FOREIGN KEY (financial_transaction_id) REFERENCES financial_transactions (id) ON DELETE CASCADE;
