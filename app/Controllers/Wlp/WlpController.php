<?php

namespace App\Controllers\Wlp;

use App\Services\WlpReferenceDataService;

/**
 * WLP & ECR Migration Plan, Phase 3.1.
 *
 * bootstrap() is the only new endpoint this module needs — it mirrors
 * SubjectLoading\LoadingController::bootstrap()'s shape and error
 * envelope exactly (§5.0): {ok:true, activeTerm, settings} on success,
 * the same ACTIVE_TERM_UNSET 500 envelope on failure. wlp.html (Phase 6)
 * talks to the existing, shared subject-loading/teachers and
 * subject-loading/teacher-subject-loads endpoints directly — those are
 * aliased in api.php (Phase 3.3), not proxied through this controller,
 * since TeacherController::list() / TeacherSubjectLoadController::list()
 * are already module-agnostic per their own doc-comments.
 */
class WlpController
{
    /**
     * GET /api/wlp/bootstrap
     * {ok: true, activeTerm, settings}
     *   activeTerm — {academicYear, semester, compactTermCode}, from
     *     WlpReferenceDataService::getActiveTerm() (Phase 2.1, itself a
     *     pure composition of SubjectLoadingReferenceDataService::
     *     getActiveTerm()).
     *   settings   — {wlpRootFolderId, wlpTemplateId, syllabusTemplateId},
     *     from WlpReferenceDataService::getWlpSettings() (Phase 0.2's
     *     tblAppSettings rows).
     */
    public function bootstrap()
    {
        try {
            $service = new WlpReferenceDataService();

            try {
                $term = $service->getActiveTerm();
            } catch (\Exception $e) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'ACTIVE_TERM_UNSET',
                    'message' => $e->getMessage(),
                ]]);
                return;
            }

            $settings = $service->getWlpSettings();

            echo json_encode([
                'ok' => true,
                'activeTerm' => $term,
                'settings' => $settings,
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    private function _serverError(\Throwable $e): void
    {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => [
            'code' => 'SERVER_ERROR',
            'message' => 'An unexpected error occurred.',
        ]]);
    }
}
