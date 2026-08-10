<?php

namespace App\Controllers\ExamPermit;

use App\Services\ExamPermitReferenceDataService;
use App\Services\ExamPermitWorkflowService;
use App\Services\ExamPermitPolicyAdminService;

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

    /**
     * POST /api/exam-permit/generate
     * Body: { studentNumber, academicYear, semester, period, actorEmail }
     */
    public function generate()
    {
        try {
            $body = $this->_readJsonBody();
            $result = (new ExamPermitWorkflowService())->generatePermit($body);

            if (!$result['ok']) {
                $code = (string)($result['code'] ?? 'SERVER_ERROR');
                $status = ($code === 'VALIDATION_ERROR') ? 400 : (($code === 'GATE_DENIED' || $code === 'NOT_REGISTERED_THIS_TERM') ? 409 : 500);
                http_response_code($status);
                echo json_encode([
                    'ok' => false,
                    'error' => [
                        'code' => $code,
                        'message' => (string)($result['message'] ?? 'Unable to generate permit.'),
                    ],
                    'gate' => $result['gate'] ?? null,
                ]);
                return;
            }

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/exam-permit/print-status
     * Body: { permitID, actorEmail }
     */
    public function updatePrintStatus()
    {
        try {
            $body = $this->_readJsonBody();
            $result = (new ExamPermitWorkflowService())->updatePrintStatus($body);

            if (!$result['ok']) {
                $code = (string)($result['code'] ?? 'SERVER_ERROR');
                $status = ($code === 'VALIDATION_ERROR') ? 400 : (($code === 'PERMIT_NOT_FOUND') ? 404 : (($code === 'POLICY_CHANGED' || $code === 'NOT_REGISTERED_THIS_TERM') ? 409 : 500));
                http_response_code($status);
                echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => (string)($result['message'] ?? 'Unable to update print status.')]]);
                return;
            }

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/exam-permit/latest-issued?studentNumber=...&academicYear=...&semester=...&period=optional
     */
    public function latestIssued()
    {
        try {
            $result = (new ExamPermitWorkflowService())->latestIssued([
                'studentNumber' => $_GET['studentNumber'] ?? '',
                'academicYear' => $_GET['academicYear'] ?? '',
                'semester' => $_GET['semester'] ?? '',
                'period' => $_GET['period'] ?? '',
            ]);

            if (!$result['ok']) {
                $code = (string)($result['code'] ?? 'SERVER_ERROR');
                http_response_code($code === 'VALIDATION_ERROR' ? 400 : 500);
                echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => (string)($result['message'] ?? 'Unable to load latest permit.')]]);
                return;
            }

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/exam-permit/moodle-eligibility?studentNumber=...&academicYear=...&semester=...&period=...
     */
    public function moodleEligibility()
    {
        try {
            $result = (new ExamPermitWorkflowService())->moodleEligibility([
                'studentNumber' => $_GET['studentNumber'] ?? '',
                'academicYear' => $_GET['academicYear'] ?? '',
                'semester' => $_GET['semester'] ?? '',
                'period' => $_GET['period'] ?? '',
            ]);

            if (!$result['ok']) {
                $code = (string)($result['code'] ?? 'SERVER_ERROR');
                http_response_code($code === 'VALIDATION_ERROR' ? 400 : 500);
                echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => (string)($result['message'] ?? 'Unable to evaluate Moodle eligibility.')]]);
                return;
            }

            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/exam-permit/policy-admin/bootstrap?academicYear=optional&semester=optional
     */
    public function policyAdminBootstrap()
    {
        try {
            $result = (new ExamPermitPolicyAdminService())->bootstrap([
                'academicYear' => $_GET['academicYear'] ?? '',
                'semester' => $_GET['semester'] ?? '',
            ]);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/exam-permit/policies
     */
    public function policies()
    {
        try {
            $result = (new ExamPermitPolicyAdminService())->listPolicies();
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/exam-permit/policy-admin/audit?policyID=optional&limit=optional
     */
    public function policyAuditTrail()
    {
        try {
            $result = (new ExamPermitPolicyAdminService())->policyAuditTrail([
                'policyID' => $_GET['policyID'] ?? '',
                'limit' => $_GET['limit'] ?? 40,
            ]);
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/exam-permit/policies/save
     */
    public function savePolicy()
    {
        try {
            $body = $this->_readJsonBody();
            $result = (new ExamPermitPolicyAdminService())->savePolicy($body);
            if (!$result['ok']) {
                $code = (string)($result['code'] ?? 'SERVER_ERROR');
                http_response_code($code === 'VALIDATION_ERROR' ? 400 : 500);
                echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => (string)($result['message'] ?? 'Unable to save policy.')]]);
                return;
            }
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/exam-permit/policies/enable
     */
    public function setPolicyEnabled()
    {
        try {
            $body = $this->_readJsonBody();
            $result = (new ExamPermitPolicyAdminService())->setPolicyEnabled($body);
            if (!$result['ok']) {
                $code = (string)($result['code'] ?? 'SERVER_ERROR');
                http_response_code($code === 'VALIDATION_ERROR' ? 400 : 500);
                echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => (string)($result['message'] ?? 'Unable to update policy status.')]]);
                return;
            }
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/exam-permit/policies/reorder-groups
     */
    public function reorderPolicyGroups()
    {
        try {
            $body = $this->_readJsonBody();
            $result = (new ExamPermitPolicyAdminService())->reorderGroups($body);
            if (!$result['ok']) {
                $code = (string)($result['code'] ?? 'SERVER_ERROR');
                http_response_code($code === 'VALIDATION_ERROR' ? 400 : 500);
                echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => (string)($result['message'] ?? 'Unable to reorder groups.')]]);
                return;
            }
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/exam-permit/policies/reorder-rules
     */
    public function reorderPolicyRules()
    {
        try {
            $body = $this->_readJsonBody();
            $result = (new ExamPermitPolicyAdminService())->reorderRules($body);
            if (!$result['ok']) {
                $code = (string)($result['code'] ?? 'SERVER_ERROR');
                http_response_code($code === 'VALIDATION_ERROR' ? 400 : 500);
                echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => (string)($result['message'] ?? 'Unable to reorder rules.')]]);
                return;
            }
            echo json_encode($result);
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

    private function _readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function _serverError(\Throwable $e): void
    {
        error_log('[ExamPermit][ExamPermitController] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'An unexpected error occurred.']]);
    }
}
