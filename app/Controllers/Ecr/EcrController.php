<?php

namespace App\Controllers\Ecr;

use App\Services\EcrReferenceDataService;

/**
 * ecr.html talks directly to the existing, shared subject-loading/teachers,
 * subject-loading/teacher-subject-loads, subject-loading/teacher-class-loads,
 * and subject-loading/class-roster endpoints — not proxied through this
 * controller. bootstrap() is the only endpoint this module needs.
 */
class EcrController
{
    /**
     * GET /api/ecr/bootstrap
     * {ok: true, activeTerm, settings}
     *   activeTerm — {academicYear, semester, compactTermCode}, from
     *     EcrReferenceDataService::getActiveTerm().
     *   settings   — {ecrRootFolderId, ecrCollegeTemplateId, ecrShsTemplateId},
     *     from EcrReferenceDataService::getEcrSettings().
     */
    public function bootstrap()
    {
        try {
            $service = new EcrReferenceDataService();

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

            $settings = $service->getEcrSettings();

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
