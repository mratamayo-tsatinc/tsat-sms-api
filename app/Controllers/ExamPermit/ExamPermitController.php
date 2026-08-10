<?php

namespace App\Controllers\ExamPermit;

use App\Services\ExamPermitReferenceDataService;

/**
 * Exam Permit module controller.
 * Uses the same try/catch and {ok:bool} envelope conventions as the rest
 * of the codebase for both read and write endpoints.
 */
class ExamPermitController
{
    /**
     * GET /api/exam-permit/bootstrap
     * { ok:true, activeTerm: {academicYear, semester, compactTermCode} }
     */
    public function bootstrap()
    {
        try {
            $service = new ExamPermitReferenceDataService();
            try {
                $term = $service->getActiveTerm();
            } catch (\Exception $e) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => ['code' => 'ACTIVE_TERM_UNSET', 'message' => $e->getMessage()]]);
                return;
            }
            echo json_encode(['ok' => true, 'activeTerm' => $term]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/exam-permit/students?academicYear=...&semester=...
     * { ok:true, students: [...] }
     * Full term roster, one bulk fetch — client filters by name/student
     * number/class in memory (same pattern as every other list section
     * in this codebase).
     */
    public function students()
    {
        try {
            $academicYear = trim($_GET['academicYear'] ?? '');
            $semester     = trim($_GET['semester'] ?? '');
            if ($academicYear === '' || $semester === '') {
                $this->_validationError('academicYear and semester are required.');
                return;
            }
            $rows = (new ExamPermitReferenceDataService())->getTermStudentRoster($academicYear, $semester);
            echo json_encode(['ok' => true, 'students' => $rows]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/exam-permit/permit?studentNumber=...&academicYear=...&semester=...&period=PRELIM|MIDTERM|SEMIFINALS|FINALS
     * { ok:true, student: {...}, subjects: [...], permitType: "PRELIM" }
     * Powers both the side-drawer detail view AND the printable permit —
     * same payload backs both.
     *
     * IMPORTANT: `period` selects which PERMIT TYPE is being generated
     * (echoed back as `permitType`, used only for the printed title). It
     * does NOT filter attendance — every subject's `attendance` figures
     * are always the ACCUMULATED (period='TERM') record, identical no
     * matter which of the four valid `period` values is passed. Still
     * required and validated so the officer can't print a mislabeled
     * permit and so the client always knows what title to print.
     */
    public function permit()
    {
        try {
            $studentNumber = trim($_GET['studentNumber'] ?? '');
            $academicYear  = trim($_GET['academicYear'] ?? '');
            $semester      = trim($_GET['semester'] ?? '');
            $period        = trim($_GET['period'] ?? '');

            if ($studentNumber === '' || $academicYear === '' || $semester === '' || $period === '') {
                $this->_validationError('studentNumber, academicYear, semester, and period are required.');
                return;
            }

            $result = (new ExamPermitReferenceDataService())->getStudentExamPermit($studentNumber, $academicYear, $semester, $period);

            if (!$result['ok']) {
                http_response_code($result['error'] === 'INVALID_PERIOD' ? 422 : 404);
                echo json_encode(['ok' => false, 'error' => ['code' => $result['error'], 'message' => $this->_errorMessage($result['error'])]]);
                return;
            }

            echo json_encode([
                'ok'         => true,
                'student'    => $result['student'],
                'subjects'   => $result['subjects'],
                'permitType' => $result['permitType'],
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    private function _errorMessage(?string $code): string
    {
        if ($code === 'INVALID_PERIOD') return 'period must be one of PRELIM, MIDTERM, SEMIFINALS, FINALS.';
        if ($code === 'NOT_REGISTERED_THIS_TERM') return 'Student has no registration for the active term.';
        return 'Unable to load exam permit.';
    }

    private function _validationError(string $message): void
    {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => $message]]);
    }

    private function _serverError(\Throwable $e): void
    {
        error_log('[ExamPermit][ExamPermitController] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'An unexpected error occurred.']]);
    }
}
