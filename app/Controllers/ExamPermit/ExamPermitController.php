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
    // Mirrors TEMP_PERMIT_QR_PASSPHRASE / TEMP_PERMIT_QR_SALT in
    // exampermit.html and ep-temp.html EXACTLY. As those files already
    // note, this is not a security boundary (anyone can read it from the
    // client source) — it only keeps the payload out of the plain QR URL.
    // Changing it here without changing it in both HTML files breaks
    // decryption everywhere at once.
    private const TEMP_PERMIT_QR_PASSPHRASE = 'EP-TEMP-7f3ac91b-52ea-4bd0-9c31-88b6fd201a44';
    private const TEMP_PERMIT_QR_SALT = 'exam-permit-temp-qr-salt-v1';

    // Origins allowed to call verifyTemporaryPermit() cross-origin. The
    // verification page (ep-temp.html) is hosted separately from this
    // API (see TEMP_PERMIT_VIEW_URL in exampermit.html), so — unlike
    // every other endpoint in this app, which is only ever called from
    // the same origin — the browser enforces CORS here. Keep in sync
    // with wherever ep-temp.html is actually deployed.
    private const ALLOWED_VERIFY_ORIGINS = [
        'https://mratamayo-tsatinc.github.io',
    ];

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
     * GET /api/exam-permit/verify?token=...
     * { ok:true, student, subjects, permitType, printedAt }
     *
     * Public, unauthenticated, cross-origin endpoint for the Temporary
     * Exam Permit's QR-verification page (ep-temp.html). `token` is the
     * SAME encrypted `d` query-string value already embedded in the
     * printed QR code — nothing new is encoded into the QR itself. This
     * endpoint decrypts that token server-side (see
     * _decryptTempPermitToken()) to recover studentNumber/academicYear/
     * semester/period, then reuses the normal permit() lookup to return
     * the subjects + attendance list that the QR payload deliberately
     * leaves out (to keep the printed code small enough for an 80mm
     * thermal printer — see exampermit.html's buildTempPermitShareUrl()).
     *
     * Trust model note: whoever holds the token (i.e. whoever can scan
     * or read the QR code) can already see the student's name, student
     * number, registration number, and class via ep-temp.html's local
     * decryption — this endpoint doesn't expose anything to a NEW
     * audience, it just lets that same audience also see subjects/
     * attendance without bloating the QR. It intentionally does NOT
     * accept a raw studentNumber/term — only an already-encrypted token —
     * so it can't be used to enumerate arbitrary students.
     */
    public function verifyTemporaryPermit()
    {
        $this->_applyVerifyCors();

        try {
            $token = trim($_GET['token'] ?? '');
            if ($token === '') {
                $this->_validationError('token is required.');
                return;
            }

            $payload = $this->_decryptTempPermitToken($token);
            $sn  = trim((string)($payload['sn']  ?? ''));
            $ay  = trim((string)($payload['ay']  ?? ''));
            $sem = trim((string)($payload['sem'] ?? ''));
            $p   = trim((string)($payload['p']   ?? ''));

            if ($payload === null || $sn === '' || $ay === '' || $sem === '' || $p === '') {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => ['code' => 'INVALID_TOKEN', 'message' => 'This verification code could not be read.']]);
                return;
            }

            $result = (new ExamPermitReferenceDataService())->getStudentExamPermit($sn, $ay, $sem, $p);

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
                // Echoed back from the token itself (unix seconds), same
                // "Printed" timestamp ep-temp.html already renders from
                // its own local decryption — included here too so a
                // caller that only hits this endpoint still has it.
                'printedAt'  => isset($payload['t']) ? (int)$payload['t'] : null,
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    // GET-only, no custom request headers on the client side (see
    // ep-temp.html's fetchFullPermitDetails()), so browsers treat this as
    // a "simple" CORS request — no OPTIONS preflight to handle, just the
    // Access-Control-Allow-Origin response header below.
    private function _applyVerifyCors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && in_array($origin, self::ALLOWED_VERIFY_ORIGINS, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
    }

    // Mirrors exampermit.html's encryptTempPermitPayload()/
    // deriveTempPermitQrKey() (WebCrypto AES-256-GCM, PBKDF2-SHA256,
    // 100000 iterations) so the server can read the SAME token embedded
    // in the printed QR without the app ever changing what's encoded in
    // it. WebCrypto appends its 16-byte GCM auth tag to the END of the
    // ciphertext; OpenSSL wants the tag passed separately, hence the
    // substr() split below. Returns null on any malformed/undecryptable
    // token — callers must treat null as "invalid code", never throw.
    private function _decryptTempPermitToken(string $token): ?array
    {
        $combined = $this->_base64UrlDecode($token);
        if ($combined === false || strlen($combined) < 12 + 16) return null;

        $iv               = substr($combined, 0, 12);
        $ciphertextAndTag = substr($combined, 12);
        $tag              = substr($ciphertextAndTag, -16);
        $ciphertext       = substr($ciphertextAndTag, 0, -16);

        $key = hash_pbkdf2('sha256', self::TEMP_PERMIT_QR_PASSPHRASE, self::TEMP_PERMIT_QR_SALT, 100000, 32, true);

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) return null;

        $decoded = json_decode($plaintext, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function _base64UrlDecode(string $data)
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) $data .= str_repeat('=', 4 - $pad);
        return base64_decode($data, true);
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