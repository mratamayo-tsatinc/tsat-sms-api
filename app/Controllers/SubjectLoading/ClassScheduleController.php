<?php

namespace App\Controllers\SubjectLoading;

use App\Services\ScheduleReferenceDataService;

/**
 * App\Controllers\SubjectLoading\ClassScheduleController
 *
 * Phase 1 of the schedule-consumption implementation plan: read-only.
 * New file under App\Controllers\SubjectLoading — does not extend, alias,
 * or modify any existing controller (§0.2 convention followed by every
 * other file in this module). Composes the shared, module-agnostic
 * ScheduleReferenceDataService rather than owning any query logic itself,
 * mirroring LoadingController's relationship to
 * SubjectLoadingReferenceDataService.
 *
 * Every method follows the §5.0 response envelope: `ok` is always present,
 * on both branches. Every method wraps its entire body in try/catch per
 * §6 — no exception is ever allowed to propagate to index.php.
 *
 * Only forOffering() ships in Phase 1. Phase 7 (documented in the plan,
 * not built) would add create/update/status/attach/detach methods to this
 * same file for write access — not a new controller.
 */
class ClassScheduleController
{
    /**
     * GET /api/subject-loading/class-schedule/for-offering
     *   ?teacherClassLoadID=... (required)
     *
     * Every active schedule slot (room + day + time) attached to one
     * Subject Offering. A slot shared with another offering (confirmed
     * intentional — combined/merged class sessions, plan §2.3) is
     * returned exactly like any other slot; no special-casing needed.
     */
    public function forOffering()
    {
        try {
            $teacherClassLoadID = trim($_GET['teacherClassLoadID'] ?? '');
            if ($teacherClassLoadID === '') {
                $this->_validationError(
                    'teacherClassLoadID is required.',
                    ['teacherClassLoadID' => 'Required.']
                );
                return;
            }

            $service = new ScheduleReferenceDataService();

            if (!$service->teacherClassLoadExists($teacherClassLoadID)) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Subject Offering was not found: ' . $teacherClassLoadID,
                ]]);
                return;
            }

            $schedule = $service->getScheduleForOffering($teacherClassLoadID);

            echo json_encode([
                'ok' => true,
                'teacherClassLoadID' => $teacherClassLoadID,
                'schedule' => $schedule,
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    // -------------------------------------------------------------
    // Helpers (local to this controller, per §0.2/§6 — mirrors every
    // other Subject Loading controller's helper shape exactly).
    // -------------------------------------------------------------

    private function _validationError(string $message, array $fields = []): void
    {
        http_response_code(400);
        $error = ['code' => 'VALIDATION_ERROR', 'message' => $message];
        if ($fields) $error['fields'] = $fields;
        echo json_encode(['ok' => false, 'error' => $error]);
    }

    private function _serverError(\Throwable $e): void
    {
        error_log('[SubjectLoading][ClassScheduleController] ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());

        http_response_code(500);
        $payload = ['ok' => false, 'error' => [
            'code' => 'SERVER_ERROR',
            'message' => 'An unexpected error occurred.',
        ]];

        if (($_GET['debug'] ?? '') === '1') {
            $payload['debug'] = [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ];
        }

        echo json_encode($payload);
    }
}
