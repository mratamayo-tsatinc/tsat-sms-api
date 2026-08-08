<?php

namespace App\Controllers\Ecr;

use App\Services\EcrAttendanceMirrorService;

/**
 * App\Controllers\Ecr\EcrAttendanceMirrorController
 *
 * Read/write endpoints for the ECR Attendance mirror
 * (tblEcrAttendanceMeetings, tblEcrAttendanceSummary). Same
 * try/catch-everything, { ok: bool } envelope, and no-Drive-knowledge
 * contract as EcrMirrorController.
 */
class EcrAttendanceMirrorController
{
    /**
     * GET /api/ecr/mirror/attendance?teacherClassLoadID=...
     * { ok:true, meetings: [...], summary: [...] }
     * Reads only the latest synced snapshot per offering — see
     * EcrAttendanceMirrorService::getMeetings()/getSummary().
     */
    public function attendance()
    {
        try {
            $teacherClassLoadID = trim($_GET['teacherClassLoadID'] ?? '');
            if ($teacherClassLoadID === '') {
                $this->_validationError('teacherClassLoadID is required.', ['teacherClassLoadID' => 'Required.']);
                return;
            }

            $service = new EcrAttendanceMirrorService();
            echo json_encode([
                'ok'       => true,
                'meetings' => $service->getMeetings($teacherClassLoadID),
                'summary'  => $service->getSummary($teacherClassLoadID),
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/ecr/mirror/attendance-stats?academicYear=...&semester=...
     * { ok:true, stats: { [teacherClassLoadID]: {meetingCount,
     *   studentCount, attendanceRate, lastSyncedAt, periods: { PRELIM|
     *   MIDTERM|SEMIFINALS|FINALS|TERM: {meetingCount, studentCount,
     *   raw:{P,L,A,E}, rates:{P,L,A,E}, lastSyncedAt} }} } }
     * Powers the ECR list's collapsed meeting-count/present-rate line,
     * its expandable per-period, per-status (Present/Late/Absent/
     * Excused) breakdown table, and the "Attendance: Synced Xh ago" /
     * "Attendance: Never synced" indicator shown in both the flat list
     * and the offering detail/roster modal (ecr.html's
     * ecrAttendanceLastSyncedHtml() / updateEcrAttendanceLastSyncedText()).
     * One bulk call for the whole term — see the plan §2 /
     * EcrAttendanceMirrorService::getAttendanceStats()'s docblock for why
     * this isn't a per-offering endpoint. An offering with no key has
     * never had an attendance sync run; a period missing from an
     * offering's periods{} hasn't happened yet. Reads only the latest
     * synced snapshot per offering.
     */
    public function attendanceStats()
    {
        try {
            $academicYear = trim($_GET['academicYear'] ?? '');
            $semester     = trim($_GET['semester'] ?? '');
            if ($academicYear === '' || $semester === '') {
                $this->_validationError('academicYear and semester are required.', [
                    'academicYear' => 'Required.',
                    'semester'     => 'Required.',
                ]);
                return;
            }

            $stats = (new EcrAttendanceMirrorService())->getAttendanceStats($academicYear, $semester);
            echo json_encode(['ok' => true, 'stats' => $stats]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/ecr/mirror/attendance/sync-bulk   (UPSERT — one offering)
     * Body: { teacherClassLoadID, meetings: [ {studentNumber, period,
     *         meetingNo, meetingDate, status}, ... ], summary: [
     *         {studentNumber, period, present, late, absent, excused,
     *         total}, ... ], syncedBy }
     * NO-DELETE: every row found is upserted and stamped with one shared
     * syncedAt timestamp; nothing is ever deleted from either mirror
     * table. A row from a prior sync that isn't part of THIS sync keeps
     * its old timestamp and drops out of subsequent reads (GET
     * /api/ecr/mirror/attendance, attendance-stats) without being
     * removed from the table. Empty arrays are valid (a genuinely empty
     * ATTE sheet) — every previously-synced row for this offering simply
     * becomes unreachable via reads, same net effect as a clear, without
     * an actual delete.
     */
    public function syncAttendanceBulk()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherClassLoadID = trim((string)($body['teacherClassLoadID'] ?? ''));
            if ($teacherClassLoadID === '') {
                $this->_validationError('teacherClassLoadID is required.', ['teacherClassLoadID' => 'Required.']);
                return;
            }

            $meetingsIn = $body['meetings'] ?? [];
            $summaryIn = $body['summary'] ?? [];
            if (!is_array($meetingsIn) || !is_array($summaryIn)) {
                $this->_validationError('meetings and summary must be arrays.', [
                    'meetings' => 'Must be an array.',
                    'summary'  => 'Must be an array.',
                ]);
                return;
            }

            $syncedBy = $body['syncedBy'] ?? null;

            $written = (new EcrAttendanceMirrorService())->upsertAttendanceBulk(
                $teacherClassLoadID, $meetingsIn, $summaryIn, $syncedBy
            );

            echo json_encode([
                'ok'              => true,
                'meetingsWritten' => $written['meetingsWritten'],
                'summaryWritten'  => $written['summaryWritten'],
                'syncedAt'        => $written['syncedAt'],
                'message'         => $written['meetingsWritten'] . ' meeting record(s) and ' .
                                      $written['summaryWritten'] . ' summary row(s) synced.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers — copied verbatim from EcrMirrorController for identical
    // envelope/error behavior (deliberately not shared/extracted, same
    // "reimplement, don't share" convention used across this module).
    // ═══════════════════════════════════════════════════════════════

    private function _readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function _validationError(string $message, array $fields = []): void
    {
        http_response_code(400);
        $error = ['code' => 'VALIDATION_ERROR', 'message' => $message];
        if ($fields) $error['fields'] = $fields;
        echo json_encode(['ok' => false, 'error' => $error]);
    }

    private function _serverError(\Throwable $e): void
    {
        error_log('[Ecr][EcrAttendanceMirrorController] ' . get_class($e) . ': ' . $e->getMessage()
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
