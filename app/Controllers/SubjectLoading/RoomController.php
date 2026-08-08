<?php

namespace App\Controllers\SubjectLoading;

use App\Services\ScheduleReferenceDataService;

/**
 * App\Controllers\SubjectLoading\RoomController
 *
 * Phase 2 of the schedule-consumption implementation plan: read-only.
 * New file under App\Controllers\SubjectLoading — does not extend, alias,
 * or modify any existing controller (§0.2 convention). Composes the
 * shared, module-agnostic ScheduleReferenceDataService rather than
 * running its own query (mirrors ClassScheduleController's composition
 * pattern from Phase 1); the JSON envelope/field-naming mirrors
 * SubjectController::list() (§5.4).
 *
 * Only list() ships in Phase 2. Phase 7 (documented in the plan, not
 * built) would add store()/update()/setActive() methods to this same
 * file for write access — not a new controller.
 */
class RoomController
{
    /**
     * GET /api/subject-loading/rooms
     *   ?activeOnly=1 (optional)
     *
     * Straight list of tblRooms, full row shape (§3 "policy-agnostic
     * payloads" — every field returned, not just what the current UI
     * happens to render). roomType is currently always NULL in live
     * data (plan §2 closing note) and is passed through as-is (null,
     * not coerced to '') rather than guessed at here.
     */
    public function list()
    {
        try {
            $activeOnly = $this->_truthy($_GET['activeOnly'] ?? null);

            $service = new ScheduleReferenceDataService();
            $rooms = $service->getAllRooms($activeOnly);

            echo json_encode(['ok' => true, 'rooms' => $rooms]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    // -------------------------------------------------------------
    // Helpers (local to this controller, per §0.2/§6 — mirrors every
    // other Subject Loading controller's helper shape exactly).
    // -------------------------------------------------------------

    private function _truthy($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        $s = strtolower(trim((string)$value));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    private function _serverError(\Throwable $e): void
    {
        error_log('[SubjectLoading][RoomController] ' . get_class($e) . ': ' . $e->getMessage()
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
