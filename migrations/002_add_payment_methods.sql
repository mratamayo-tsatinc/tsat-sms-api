-- ─────────────────────────────────────────────────────────────────────────
-- Migration 002: Payment Method & Settlement Account for Cashier Module
-- See _MD FILES/cashier-payment-method-project-brief.md for full rationale.
-- Apply once, in order, against the live database.
-- ─────────────────────────────────────────────────────────────────────────

-- 1. New reference table — the school's own receiving accounts.
CREATE TABLE IF NOT EXISTS tblSettlementAccounts (
    settlementAccountCode VARCHAR(50)  NOT NULL,
    settlementAccountName VARCHAR(150) NOT NULL,
    isActive              TINYINT(1)   NOT NULL DEFAULT 1,
    sortOrder             INT(11)      NOT NULL DEFAULT 0,
    createdBy             VARCHAR(190) NULL,
    dateCreated            VARCHAR(40) NULL,
    modifiedBy            VARCHAR(190) NULL,
    lastModified           VARCHAR(40) NULL,
    PRIMARY KEY (settlementAccountCode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. New mapping table — one settlement account per payment method
--    (paymentMethodCode as PRIMARY KEY structurally enforces 1:1).
CREATE TABLE IF NOT EXISTS tblPaymentMethodAccounts (
    paymentMethodCode     VARCHAR(50) NOT NULL,
    settlementAccountCode VARCHAR(50) NOT NULL,
    isActive              TINYINT(1)  NOT NULL DEFAULT 1,
    PRIMARY KEY (paymentMethodCode),
    KEY idx_pma_settlementAccountCode (settlementAccountCode),
    CONSTRAINT fk_pma_settlementAccount FOREIGN KEY (settlementAccountCode)
        REFERENCES tblSettlementAccounts (settlementAccountCode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. tblPayments — three new nullable columns, snapshotted at write time.
--    paymentReference must stay NULL (never '') when not applicable so the
--    UNIQUE index below permits unlimited legacy/cash rows.
ALTER TABLE tblPayments
    ADD COLUMN paymentMethod     VARCHAR(50)  NULL AFTER createdBy,
    ADD COLUMN settlementAccount VARCHAR(50)  NULL AFTER paymentMethod,
    ADD COLUMN paymentReference  VARCHAR(190) NULL AFTER settlementAccount;

ALTER TABLE tblPayments ADD KEY idx_payments_paymentMethod (paymentMethod);
ALTER TABLE tblPayments ADD KEY idx_payments_settlementAccount (settlementAccount);
ALTER TABLE tblPayments ADD UNIQUE KEY uq_payments_paymentReference (paymentReference);

-- 4. Seed settlement accounts (§3.5 of the brief).
INSERT INTO tblSettlementAccounts (settlementAccountCode, settlementAccountName, isActive, sortOrder)
VALUES
    ('CASH_DRAWER_01',     'Cash Drawer',                1, 1),
    ('SCHOOL_GCASH_MAIN',  'School GCash Account',       1, 2),
    ('SCHOOL_GOTYME_MAIN', 'School GoTyme Bank Account', 1, 3)
ON DUPLICATE KEY UPDATE
    settlementAccountName = VALUES(settlementAccountName),
    isActive               = VALUES(isActive),
    sortOrder               = VALUES(sortOrder);

-- 5. Seed payment method → settlement account mapping.
--    CHECK is intentionally NOT mapped — it stays in ref_lookup_values but
--    will not appear to the cashier until a real settlement account is
--    assigned to it in a future change.
INSERT INTO tblPaymentMethodAccounts (paymentMethodCode, settlementAccountCode, isActive)
VALUES
    ('CASH',          'CASH_DRAWER_01',     1),
    ('GCASH',         'SCHOOL_GCASH_MAIN',  1),
    ('BANK_TRANSFER', 'SCHOOL_GOTYME_MAIN', 1)
ON DUPLICATE KEY UPDATE
    settlementAccountCode = VALUES(settlementAccountCode),
    isActive               = VALUES(isActive);
