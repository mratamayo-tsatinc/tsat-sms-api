<?php

namespace App\Services;

use App\Core\Database;

/**
 * App\Services\EcrReferenceDataService
 *
 * New file — WLP & ECR Migration Plan, Phase 2.2. Sibling of
 * WlpReferenceDataService.php — same composition-vs-reimplementation
 * split, same "no HTTP knowledge, plain arrays" convention.
 *
 * No new "class" logic here or anywhere else in this plan: ECR rides on
 * tblTeacherClassLoads exactly as the existing, shared
 * subject-loading/teacher-class-loads and subject-loading/offerings/search
 * endpoints already expose it (each row already carries
 * teacherClassLoadID, programCode, yearLevel, sectionName, classCode,
 * enrolledCount) — Phase 3 aliases those directly for /api/ecr/*, no new
 * code needed.
 */
class EcrReferenceDataService
{
    // ECR's tblAppSettings keys (Phase 0.2).
    private const SETTING_KEYS = ['ecrRootFolderId', 'ecrCollegeTemplateId', 'ecrShsTemplateId'];

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
     * configured — callers (EcrController::bootstrap(), Phase 3) are
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
     * Returns ['ecrRootFolderId' => ..., 'ecrCollegeTemplateId' => ...,
     * 'ecrShsTemplateId' => ...] read from tblAppSettings (Phase 0.2
     * rows). Any key with no row yet comes back as an empty string
     * rather than being omitted, so callers can always safely index
     * every key without an isset() check.
     */
    public function getEcrSettings(): array
    {
        return $this->_getAppSettingValues(self::SETTING_KEYS);
    }

    // Independently written per §0.3 — same shape as
    // WlpReferenceDataService::_getAppSettingValues(), deliberately not
    // extracted into a shared helper (this class and its WLP sibling are
    // each self-contained, matching SubjectLoadingReferenceDataService's
    // own "reimplement, don't share" convention for module-specific reads).
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
