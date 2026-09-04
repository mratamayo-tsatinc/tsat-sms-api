-- ---------------------------------------------------------------------
-- Migration 003: Exam Permit schema + seed data
-- ---------------------------------------------------------------------
-- IMPORTANT:
--   This file is intentionally kept in the migrations folder for manual
--   execution in phpMyAdmin / MySQL workbench. The implementation plan
--   explicitly states Phase 1 is manual-only and that application code
--   should assume the schema already exists.
-- ---------------------------------------------------------------------

-- 1) Exam Permit tables
CREATE TABLE IF NOT EXISTS tblExamPermits (
  permitID            VARCHAR(20)  NOT NULL PRIMARY KEY,
  studentNumber       VARCHAR(20)  NOT NULL,
  registrationNumber  VARCHAR(15)  NOT NULL,
  academicYear        VARCHAR(15)  NOT NULL,
  semester            VARCHAR(20)  NOT NULL,
  period              VARCHAR(20)  NOT NULL,
  status              VARCHAR(20)  NOT NULL,
  gateSource          VARCHAR(20)  NULL,
  gatePolicyID        VARCHAR(20)  NULL,
  gateWatchlistID     VARCHAR(20)  NULL,
  gateDecision        VARCHAR(20)  NULL,
  gateSummary         TEXT,
  generatedBy         VARCHAR(100) NULL,
  generatedAt         VARCHAR(30)  NULL,
  lastPrintedBy       VARCHAR(100) NULL,
  lastPrintedAt       VARCHAR(30)  NULL,
  printCount          INT UNSIGNED NOT NULL DEFAULT 0,
  moodleActivatedBy   VARCHAR(100) NULL,
  moodleActivatedAt   VARCHAR(30)  NULL
) ROW_FORMAT=DYNAMIC;

ALTER TABLE tblExamPermits
  ADD INDEX idx_exampermit_student_term (studentNumber, academicYear, semester, period),
  ADD INDEX idx_exampermit_registration (registrationNumber),
  ADD INDEX idx_exampermit_status (status),
  ADD INDEX idx_exampermit_policy (gatePolicyID),
  ADD INDEX idx_exampermit_watchlist (gateWatchlistID);

CREATE TABLE IF NOT EXISTS tblExamPermitAudit (
  auditID             VARCHAR(20)  NOT NULL PRIMARY KEY,
  permitID            VARCHAR(20)  NULL,
  studentNumber       VARCHAR(20)  NULL,
  registrationNumber  VARCHAR(15)  NULL,
  academicYear        VARCHAR(15)  NULL,
  semester            VARCHAR(20)  NULL,
  period              VARCHAR(20)  NULL,
  actionType          VARCHAR(40)  NOT NULL,
  outcome             VARCHAR(20)  NOT NULL,
  actorEmail          VARCHAR(100) NULL,
  actorName           VARCHAR(255) NULL,
  detail              TEXT,
  createdAt           VARCHAR(30)  NULL
) ROW_FORMAT=DYNAMIC;

ALTER TABLE tblExamPermitAudit
  ADD INDEX idx_exampermitaudit_permit (permitID),
  ADD INDEX idx_exampermitaudit_student_term (studentNumber, academicYear, semester, period),
  ADD INDEX idx_exampermitaudit_action (actionType),
  ADD INDEX idx_exampermitaudit_created (createdAt);

CREATE TABLE IF NOT EXISTS tblExamPermitPolicies (
  policyID            VARCHAR(20)  NOT NULL PRIMARY KEY,
  policyName          VARCHAR(150) NOT NULL,
  description         TEXT,
  activeAcademicYear  VARCHAR(15)  NULL,
  activeSemester      VARCHAR(20)  NULL,
  appliesToPeriods    VARCHAR(255) NULL,
  scopeType           VARCHAR(30)  NOT NULL,
  studentNumber       VARCHAR(20)  NULL,
  programID           VARCHAR(10)  NULL,
  yearLevel           VARCHAR(20)  NULL,
  classCode           VARCHAR(50)  NULL,
  priorityOrder       INT UNSIGNED NOT NULL DEFAULT 1,
  isEnabled           TINYINT(1)   NOT NULL DEFAULT 1,
  createdBy           VARCHAR(100) NULL,
  dateCreated         VARCHAR(30)  NULL,
  modifiedBy          VARCHAR(100) NULL,
  lastModified        VARCHAR(30)  NULL
) ROW_FORMAT=DYNAMIC;

ALTER TABLE tblExamPermitPolicies
  ADD INDEX idx_exampermitpolicy_enabled (isEnabled),
  ADD INDEX idx_exampermitpolicy_term (activeAcademicYear, activeSemester),
  ADD INDEX idx_exampermitpolicy_type (scopeType),
  ADD INDEX idx_exampermitpolicy_student (studentNumber),
  ADD INDEX idx_exampermitpolicy_program_year (programID, yearLevel),
  ADD INDEX idx_exampermitpolicy_class (classCode);

CREATE TABLE IF NOT EXISTS tblExamPermitPolicyRules (
  policyRuleID        VARCHAR(20)  NOT NULL PRIMARY KEY,
  policyID            VARCHAR(20)  NOT NULL,
  ruleType            VARCHAR(40)  NOT NULL,
  ruleLabel           VARCHAR(150) NOT NULL,
  feeID               VARCHAR(20)  NULL,
  thresholdValue      DECIMAL(12,2) NULL,
  parameterText       TEXT,
  isNegated           TINYINT(1)   NOT NULL DEFAULT 0,
  sortOrder           INT UNSIGNED NOT NULL,
  isEnabled           TINYINT(1)   NOT NULL DEFAULT 1,
  createdBy           VARCHAR(100) NULL,
  dateCreated         VARCHAR(30)  NULL,
  modifiedBy          VARCHAR(100) NULL,
  lastModified        VARCHAR(30)  NULL
) ROW_FORMAT=DYNAMIC;

ALTER TABLE tblExamPermitPolicyRules
  ADD INDEX idx_exampermitpolicyrule_policy (policyID),
  ADD INDEX idx_exampermitpolicyrule_sort (policyID, sortOrder),
  ADD INDEX idx_exampermitpolicyrule_type (ruleType),
  ADD INDEX idx_exampermitpolicyrule_fee (feeID),
  ADD INDEX idx_exampermitpolicyrule_enabled (isEnabled);

CREATE TABLE IF NOT EXISTS tblExamPermitWatchlist (
  watchlistID   VARCHAR(20)  NOT NULL PRIMARY KEY,
  studentNumber VARCHAR(20)  NOT NULL,
  listType      VARCHAR(10)  NOT NULL,
  reason        TEXT         NOT NULL,
  academicYear  VARCHAR(15)  NOT NULL,
  semester      VARCHAR(20)  NOT NULL,
  period        VARCHAR(20)  NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'ACTIVE',
  addedBy       VARCHAR(100) NULL,
  dateAdded     VARCHAR(30)  NULL,
  removedBy     VARCHAR(100) NULL,
  dateRemoved   VARCHAR(30)  NULL
) ROW_FORMAT=DYNAMIC;

ALTER TABLE tblExamPermitWatchlist
  ADD INDEX idx_exampermitwatchlist_student_term (studentNumber, academicYear, semester, period),
  ADD INDEX idx_exampermitwatchlist_type (listType),
  ADD INDEX idx_exampermitwatchlist_status (status);

-- 2) Seed tblIDGenerator
INSERT INTO tblIDGenerator (TableName, NextNo)
VALUES
  ('tblExamPermits', 1),
  ('tblExamPermitAudit', 1),
  ('tblExamPermitPolicies', 1),
  ('tblExamPermitPolicyRules', 1),
  ('tblExamPermitWatchlist', 1)
ON DUPLICATE KEY UPDATE NextNo = NextNo;

-- 3) Seed global default policy
INSERT INTO tblExamPermitPolicies
  (policyID, policyName, description, activeAcademicYear, activeSemester, appliesToPeriods, scopeType, priorityOrder, isEnabled, createdBy, dateCreated)
VALUES
  ('EPP0000001', 'Global Default Policy', 'Fallback policy for all students when no more specific scope matches.', '2026-2027', '1ST SEMESTER', 'PRELIM,MIDTERM,SEMIFINALS,FINALS', 'GLOBAL', 1, 1, 'administrator@tsatinc.edu.ph', '2026-08-10 09:00:00')
ON DUPLICATE KEY UPDATE
  policyName = VALUES(policyName),
  description = VALUES(description),
  activeAcademicYear = VALUES(activeAcademicYear),
  activeSemester = VALUES(activeSemester),
  appliesToPeriods = VALUES(appliesToPeriods),
  scopeType = VALUES(scopeType),
  priorityOrder = VALUES(priorityOrder),
  isEnabled = VALUES(isEnabled),
  modifiedBy = 'administrator@tsatinc.edu.ph',
  lastModified = '2026-08-10 09:00:00';

INSERT INTO tblExamPermitPolicyRules
  (policyRuleID, policyID, ruleType, ruleLabel, feeID, thresholdValue, parameterText, isNegated, sortOrder, isEnabled, createdBy, dateCreated)
VALUES
  ('EPRL000001', 'EPP0000001', 'TOTAL_BALANCE_ZERO', 'No total outstanding balance', NULL, NULL, NULL, 0, 1, 1, 'administrator@tsatinc.edu.ph', '2026-08-10 09:00:00')
ON DUPLICATE KEY UPDATE
  policyID = VALUES(policyID),
  ruleType = VALUES(ruleType),
  ruleLabel = VALUES(ruleLabel),
  feeID = VALUES(feeID),
  thresholdValue = VALUES(thresholdValue),
  parameterText = VALUES(parameterText),
  isNegated = VALUES(isNegated),
  sortOrder = VALUES(sortOrder),
  isEnabled = VALUES(isEnabled),
  modifiedBy = 'administrator@tsatinc.edu.ph',
  lastModified = '2026-08-10 09:00:00';

-- PROMISSORY_NOTE_ABSENT is intentionally NOT seeded here — see the deferred
-- promissory-note rule in the implementation spec.

-- 4) Optional examples (commented out by default)
-- INSERT INTO tblExamPermitPolicies
--   (policyID, policyName, description, activeAcademicYear, activeSemester, appliesToPeriods, scopeType, classCode, priorityOrder, isEnabled, createdBy, dateCreated)
-- VALUES
--   ('EPP0000002', 'DIT3-LINUS Exam Permit Policy', 'Class-specific stricter rule set.', '2026-2027', '1ST SEMESTER', 'PRELIM,MIDTERM,SEMIFINALS,FINALS', 'CLASS', 'DIT3-LINUS', 10, 1, 'administrator@tsatinc.edu.ph', '2026-08-10 09:10:00');
--
-- INSERT INTO tblExamPermitPolicyRules
--   (policyRuleID, policyID, ruleType, ruleLabel, feeID, thresholdValue, parameterText, isNegated, sortOrder, isEnabled, createdBy, dateCreated)
-- VALUES
--   ('EPRL000002', 'EPP0000002', 'TOTAL_BALANCE_ZERO', 'No total outstanding balance', NULL, NULL, NULL, 0, 1, 1, 'administrator@tsatinc.edu.ph', '2026-08-10 09:10:00');
--
-- INSERT INTO tblExamPermitPolicyRules
--   (policyRuleID, policyID, ruleType, ruleLabel, feeID, thresholdValue, parameterText, isNegated, sortOrder, isEnabled, createdBy, dateCreated)
-- VALUES
--   ('EPRL000003', 'EPP0000002', 'FEE_PERCENT_AT_LEAST', 'Lab fee fully paid', 'LAB', 100.00, NULL, 0, 2, 1, 'administrator@tsatinc.edu.ph', '2026-08-10 09:10:00');
--
-- INSERT INTO tblExamPermitWatchlist
--   (watchlistID, studentNumber, listType, reason, academicYear, semester, period, status, addedBy, dateAdded)
-- VALUES
--   ('EPW0000001', '2024-00123', 'BLACKLIST', 'Pending disciplinary case, hold requested by OSA — see memo dated 2026-08-05.', '2026-2027', '1ST SEMESTER', NULL, 'ACTIVE', 'officer@tsatinc.edu.ph', '2026-08-10 09:20:00');
--
-- INSERT INTO tblExamPermitWatchlist
--   (watchlistID, studentNumber, listType, reason, academicYear, semester, period, status, addedBy, dateAdded)
-- VALUES
--   ('EPW0000002', '2024-00456', 'WHITELIST', 'Approved payment extension by finance office, verbal clearance for Prelim only.', '2026-2027', '1ST SEMESTER', 'PRELIM', 'ACTIVE', 'officer@tsatinc.edu.ph', '2026-08-10 09:25:00');

-- 5) Seed lookup values for the exam permit module
INSERT INTO ref_lookup_values (category, code, label, sortOrder, isActive) VALUES
  ('EXAM_PERMIT_VOID_REASON', 'POLICY_CHANGED_AFTER_ISSUANCE', 'Policy Changed After Issuance', 1, 1),
  ('EXAM_PERMIT_VOID_REASON', 'ISSUED_IN_ERROR',               'Issued In Error',               2, 1),
  ('EXAM_PERMIT_VOID_REASON', 'DUPLICATE_PERMIT',             'Duplicate Permit',              3, 1),
  ('EXAM_PERMIT_VOID_REASON', 'STUDENT_RECORD_CORRECTION',    'Student Record Correction',     4, 1),
  ('EXAM_PERMIT_VOID_REASON', 'OTHER',                        'Other',                         5, 1)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  sortOrder = VALUES(sortOrder),
  isActive = VALUES(isActive);

INSERT INTO ref_lookup_values (category, code, label, sortOrder, isActive) VALUES
  ('EXAM_PERMIT_LISTTYPE_LABEL', 'BLACKLIST', 'Blacklist (Deny)', 1, 1),
  ('EXAM_PERMIT_LISTTYPE_LABEL', 'WHITELIST', 'Whitelist (Allow)', 2, 1)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  sortOrder = VALUES(sortOrder),
  isActive = VALUES(isActive);

INSERT INTO ref_lookup_values (category, code, label, sortOrder, isActive) VALUES
  ('EXAM_PERMIT_PERIOD_LABEL', 'PRELIM',     'Prelim',     1, 1),
  ('EXAM_PERMIT_PERIOD_LABEL', 'MIDTERM',    'Midterm',    2, 1),
  ('EXAM_PERMIT_PERIOD_LABEL', 'SEMIFINALS', 'Semifinals', 3, 1),
  ('EXAM_PERMIT_PERIOD_LABEL', 'FINALS',     'Finals',     4, 1)
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  sortOrder = VALUES(sortOrder),
  isActive = VALUES(isActive);

-- ---------------------------------------------------------------------
-- Manual verification queries
-- ---------------------------------------------------------------------
-- SELECT * FROM tblIDGenerator WHERE TableName LIKE 'tblExamPermit%';
-- SELECT * FROM tblExamPermitPolicies ORDER BY policyID;
-- SELECT * FROM tblExamPermitPolicyRules ORDER BY policyID, sortOrder;
-- SELECT * FROM tblExamPermitWatchlist ORDER BY academicYear, semester, studentNumber;
-- SELECT category, code, label, sortOrder, isActive
-- FROM ref_lookup_values
-- WHERE category IN (
--   'EXAM_PERMIT_VOID_REASON',
--   'EXAM_PERMIT_LISTTYPE_LABEL',
--   'EXAM_PERMIT_PERIOD_LABEL'
-- )
-- ORDER BY category, sortOrder;
