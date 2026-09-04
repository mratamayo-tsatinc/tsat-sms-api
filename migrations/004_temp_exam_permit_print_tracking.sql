-- ============================================================================
-- Manual phpMyAdmin change — Temporary Permit print tracking
-- ============================================================================
-- Run by hand in phpMyAdmin's SQL tab, same convention as Phase 1 of
-- exampermit-implementation-plan_v4.md. Do NOT wrap this in an automated
-- migration script.
--
-- Purpose: the 80mm thermal "Temporary Permit" print path currently shares
-- no counter or audit trail with the A4 wide-format permit — printing it
-- was invisible to printCount and to the audit log. This adds a parallel
-- counter (tempPrintCount / lastTempPrintedBy / lastTempPrintedAt) that
-- mirrors the existing printCount / lastPrintedBy / lastPrintedAt trio
-- exactly, so the two print formats are tracked independently on the same
-- permit row.
--
-- Safe on an existing, populated table: DEFAULT 0 / NULL means every
-- existing row backfills cleanly with no data migration needed.
-- ============================================================================

-- 1. Back up first (phpMyAdmin Export -> Quick, SQL format) before running
--    the ALTER below, same as every other schema change in this project.

-- 2. Add the three new columns, positioned right after the existing
--    printCount/lastPrintedBy/lastPrintedAt trio so the table reads as two
--    parallel counter blocks rather than scattered fields.
ALTER TABLE tblExamPermits
  ADD COLUMN tempPrintCount    INT UNSIGNED NOT NULL DEFAULT 0 AFTER printCount,
  ADD COLUMN lastTempPrintedBy VARCHAR(100) NULL          AFTER tempPrintCount,
  ADD COLUMN lastTempPrintedAt VARCHAR(30)  NULL          AFTER lastTempPrintedBy;

-- 3. Spot-check.
DESCRIBE tblExamPermits;
SELECT permitID, printCount, lastPrintedBy, lastPrintedAt,
       tempPrintCount, lastTempPrintedBy, lastTempPrintedAt
FROM tblExamPermits
ORDER BY permitID DESC
LIMIT 10;

-- Confirm: tempPrintCount = 0 and lastTempPrintedBy/lastTempPrintedAt are
-- NULL on every pre-existing row; column order matches the ADD COLUMN
-- ... AFTER placement above; no other column or row was touched.

-- ============================================================================
-- 4. Afterward (separate step, Phase-2-style): manually update
--    sql/schema.sql's tblExamPermits DDL block to add these same three
--    columns, matching live SHOW CREATE TABLE output exactly — this file
--    does not do that for you, and application code should not touch
--    sql/schema.sql automatically per the plan's own constraints.
-- ============================================================================

-- ============================================================================
-- 5. Canonical enum addition (code-level, not a table row) — for reference
--    only, nothing to run here. actionType (Tier 1, hardcoded) gains two
--    new values used by ExamPermitController::tempPrintStatus():
--      TEMP_PRINT    — first print of the Temporary Permit for a permit row
--      TEMP_REPRINT  — any subsequent print of the Temporary Permit
--    These do NOT go into ref_lookup_values — actionType is explicitly a
--    Tier-1 canonical enum per "Lookup Values vs. Canonical Enums" in
--    exampermit-implementation-plan_v4.md. Update the plan's own "Canonical
--    Enums And Constants" table and the frontend's ACTION_TYPES_SEEN array
--    (exampermit.html) to include them — see accompanying notes.
-- ============================================================================
