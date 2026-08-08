<?php

namespace App\Services;

use App\Core\Database;

/**
 * App\Services\WlpReferenceDataService
 *
 * New file — WLP & ECR Migration Plan, Phase 2.1. Mirrors
 * SubjectLoadingReferenceDataService.php's own role/shape exactly:
 *   1. For reads that already exist as clean public methods elsewhere,
 *      this class COMPOSES them (getActiveTerm() below), it does not
 *      reimplement them.
 *   2. For the one piece of logic that's new to this module (reading the
 *      WLP-specific tblAppSettings rows added in Phase 0.2), this class
 *      implements a small, independently-written private helper
 *      (_getAppSettingValues()), per plan §0.3 — ReferenceDataService.php
 *      is not touched for this, since it's genuinely new query/behavior,
 *      not an addition to something that class already computes (unlike
 *      compactTermCode in Phase 0.1, decision F).
 *
 * Does NOT duplicate teacher/subject/Teacher-Subject-Load logic — those
 * are served as-is by the existing, shared, module-agnostic
 * subject-loading/teachers, subject-loading/subjects, and
 * subject-loading/teacher-subject-loads endpoints (Phase 3 aliases them
 * directly for /api/wlp/*, no new code needed).
 *
 * This class has no knowledge of HTTP — it returns plain arrays, same
 * convention as ReferenceDataService/SubjectLoadingReferenceDataService.
 * WlpController (Phase 3) is responsible for json_encode-ing responses.
 */
class WlpReferenceDataService
{
    // WLP's tblAppSettings keys (Phase 0.2) — kept as a class const so
    // getWlpSettings() and any future caller can't drift out of sync with
    // what's actually queried.
    private const SETTING_KEYS = ['wlpRootFolderId', 'wlpTemplateId', 'syllabusTemplateId'];

    // -----------------------------------------------------------------
    // Composition — calls the existing, unmodified
    // SubjectLoadingReferenceDataService's public method as-is. No edits
    // to that file, and no edits needed to pick up compactTermCode —
    // getActiveTerm() already returns it as of Phase 0 (decision F).
    // -----------------------------------------------------------------

    /**
     * Returns ['academicYear' => ..., 'semester' => ..., 'compactTermCode' => ...].
     * Throws \Exception (ACTIVE_TERM_UNSET, propagated from
     * ReferenceDataService::getActiveTerm()) if the active term isn't
     * configured — callers (WlpController::bootstrap(), Phase 3) are
     * responsible for translating that into the §5.0 error envelope.
     */
    public function getActiveTerm(): array
    {
        return (new SubjectLoadingReferenceDataService())->getActiveTerm();
    }

    // -----------------------------------------------------------------
    // New, independent logic for this module.
    // -----------------------------------------------------------------

    /**
     * Returns ['wlpRootFolderId' => ..., 'wlpTemplateId' => ...,
     * 'syllabusTemplateId' => ...] read from tblAppSettings (Phase 0.2
     * rows). Any key with no row yet (not-yet-configured) comes back as
     * an empty string rather than being omitted, so callers can always
     * safely index every key without an isset() check.
     */
    public function getWlpSettings(): array
    {
        return $this->_getAppSettingValues(self::SETTING_KEYS);
    }

    // Same SELECT settingKey, settingValue FROM tblAppSettings shape as
    // ReferenceDataService::getActiveTerm() — independently written per
    // §0.3, not extracted/shared, since this reads a different set of
    // keys for a different purpose (Drive IDs, not the active term) and
    // has no "extend an already-computed value" relationship to
    // ReferenceDataService the way compactTermCode did.
    private function _getAppSettingValues(array $keys): array
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT settingKey, settingValue FROM tblAppSettings");
        $rows = $stmt->fetchAll();

        $settings = [];
        foreach ($rows as $row) {
            $settings[strtolower((string)$row['settingKey'])] = (string)($row['settingValue'] ?? '');
        }

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = trim($settings[strtolower($key)] ?? '');
        }

        return $out;
    }
}
