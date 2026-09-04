<?php

namespace App\Controllers\ExamPermit;

use App\Core\Database;
use App\Services\ExamPermitReferenceDataService;
use App\Services\ReferenceDataService;
use App\Services\ExamPermitAuditService;
use App\Services\ExamPermitGateService;
use App\Services\ExamPermitPolicyService;
use App\Services\ExamPermitWatchlistService;
use App\Models\SequenceGenerator;

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

    /**
     * GET /api/exam-permit/lookup-values?category=
     * Returns active ref_lookup_values entries for this module's categories.
     */
    public function lookupValues()
    {
        try {
            $allowed = ['EXAM_PERMIT_VOID_REASON', 'EXAM_PERMIT_LISTTYPE_LABEL', 'EXAM_PERMIT_PERIOD_LABEL'];
            $category = strtoupper(trim((string)($_GET['category'] ?? '')));

            if ($category !== '' && !in_array($category, $allowed, true)) {
                $this->_validationError('category must be one of EXAM_PERMIT_VOID_REASON, EXAM_PERMIT_LISTTYPE_LABEL, EXAM_PERMIT_PERIOD_LABEL.');
                return;
            }

            $cats = $category !== '' ? [$category] : $allowed;
            $values = (new ReferenceDataService())->getLookupValues($cats);

            if ($category !== '') {
                $values = [$category => $values[$category] ?? []];
            }

            echo json_encode(['ok' => true, 'values' => $values]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/exam-permit/latest-issued?studentNumber=...&academicYear=...&semester=...&period=...
     */
    public function latestIssued()
    {
        try {
            $studentNumber = trim((string)($_GET['studentNumber'] ?? ''));
            $academicYear  = trim((string)($_GET['academicYear'] ?? ''));
            $semester      = trim((string)($_GET['semester'] ?? ''));
            $period        = trim((string)($_GET['period'] ?? ''));

            if ($studentNumber === '' || $academicYear === '' || $semester === '' || $period === '') {
                $this->_validationError('studentNumber, academicYear, semester, and period are required.');
                return;
            }

            $db = Database::getConnection();
            $stmt = $db->prepare(
                "SELECT *
                 FROM tblExamPermits
                 WHERE studentNumber = :studentNumber
                   AND academicYear = :academicYear
                   AND semester = :semester
                   AND period = :period
                 ORDER BY generatedAt DESC, permitID DESC
                 LIMIT 1"
            );
            $stmt->execute([
                ':studentNumber' => $studentNumber,
                ':academicYear'  => $academicYear,
                ':semester'      => $semester,
                ':period'        => strtoupper($period),
            ]);

            $permit = $stmt->fetch();
            echo json_encode(['ok' => true, 'permit' => $permit ?: null]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/exam-permit/latest-issued-all?studentNumber=...&academicYear=...&semester=...
     * { ok:true, permits: { PRELIM: {...}|null, MIDTERM:..., SEMIFINALS:..., FINALS:... } }
     *
     * All-four-periods sibling of latestIssued() — the latest permit row
     * (any status: ISSUED or VOIDED) per period for ONE student, in a
     * single query. Powers the drawer header's always-visible 4-badge
     * strip (see exampermit.html renderPermitBadges()).
     */
    public function latestIssuedAll()
    {
        try {
            $studentNumber = trim((string)($_GET['studentNumber'] ?? ''));
            $academicYear  = trim((string)($_GET['academicYear'] ?? ''));
            $semester      = trim((string)($_GET['semester'] ?? ''));

            if ($studentNumber === '' || $academicYear === '' || $semester === '') {
                $this->_validationError('studentNumber, academicYear, and semester are required.');
                return;
            }

            $results = $this->_fetchLatestIssuedAllForStudents([$studentNumber], $academicYear, $semester);
            echo json_encode(['ok' => true, 'permits' => $results[$studentNumber]]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/exam-permit/latest-issued/bulk
     * Body: { studentNumbers: string[], academicYear, semester }
     * { ok:true, results: { <studentNumber>: {PRELIM:{...}|null, MIDTERM:..., SEMIFINALS:..., FINALS:...} } }
     *
     * Roster-wide sibling of latestIssuedAll() — one query covers every
     * requested student's latest permit row per period, same "bulk instead
     * of N single calls" pattern as MoodleController::examPermitStatusByEmails().
     * Powers the student-list row badges across the whole roster; the
     * frontend chunks/concurrency-limits this exactly like the existing
     * exam-permit-status/bulk Moodle call.
     */
    public function latestIssuedAllBulk()
    {
        try {
            $in = $this->_json();
            $studentNumbers = is_array($in['studentNumbers'] ?? null) ? $in['studentNumbers'] : [];
            $academicYear = trim((string)($in['academicYear'] ?? ''));
            $semester = trim((string)($in['semester'] ?? ''));

            $studentNumbers = array_values(array_unique(array_filter(array_map(
                function ($s) { return is_string($s) || is_numeric($s) ? trim((string)$s) : ''; },
                $studentNumbers
            ), function ($s) { return $s !== ''; })));

            if (empty($studentNumbers) || $academicYear === '' || $semester === '') {
                $this->_validationError('studentNumbers (non-empty array), academicYear, and semester are required.');
                return;
            }

            $results = $this->_fetchLatestIssuedAllForStudents($studentNumbers, $academicYear, $semester);
            echo json_encode(['ok' => true, 'results' => $results]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * Shared core for latestIssuedAll() (one student) and
     * latestIssuedAllBulk() (many) — one query, ordered so the first row
     * seen per studentNumber+period is already the latest (generatedAt
     * DESC, permitID DESC), deduped in PHP rather than with a correlated
     * subquery per period. Every requested student is guaranteed all four
     * period keys in the return, even when no permit row exists yet for
     * that period (null), so callers never need to special-case a missing
     * key.
     *
     * @return array<string,array{PRELIM:?array,MIDTERM:?array,SEMIFINALS:?array,FINALS:?array}>
     */
    private function _fetchLatestIssuedAllForStudents(array $studentNumbers, string $academicYear, string $semester): array
    {
        $out = [];
        foreach ($studentNumbers as $sn) {
            $out[$sn] = ['PRELIM' => null, 'MIDTERM' => null, 'SEMIFINALS' => null, 'FINALS' => null];
        }
        if (empty($studentNumbers)) return $out;

        $db = Database::getConnection();
        $placeholders = implode(',', array_fill(0, count($studentNumbers), '?'));
        $sql = "SELECT * FROM tblExamPermits
                WHERE studentNumber IN ($placeholders) AND academicYear = ? AND semester = ?
                ORDER BY studentNumber, period, generatedAt DESC, permitID DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($studentNumbers, [$academicYear, $semester]));

        $seen = []; // "studentNumber|period" -> true, keeps only the first (latest) row per pair
        while ($row = $stmt->fetch()) {
            $key = $row['studentNumber'] . '|' . $row['period'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            if (!isset($out[$row['studentNumber']])) {
                $out[$row['studentNumber']] = ['PRELIM' => null, 'MIDTERM' => null, 'SEMIFINALS' => null, 'FINALS' => null];
            }
            if (array_key_exists($row['period'], $out[$row['studentNumber']])) {
                $out[$row['studentNumber']][$row['period']] = $row;
            }
        }
        return $out;
    }

    /**
     * GET /api/exam-permit/moodle-eligibility?studentNumber=...&academicYear=...&semester=...&period=...
     * This is the same gate precondition used by the Moodle write endpoint. Since this install
     * does not yet include the full policy/watchlist tables from the later phase, the check resolves
     * to the minimal durable rule: a permit must already exist as ISSUED and be currently eligible.
     */
    public function moodleEligibility()
    {
        try {
            $studentNumber = trim((string)($_GET['studentNumber'] ?? ''));
            $academicYear  = trim((string)($_GET['academicYear'] ?? ''));
            $semester      = trim((string)($_GET['semester'] ?? ''));
            $period        = trim((string)($_GET['period'] ?? ''));

            if ($studentNumber === '' || $academicYear === '' || $semester === '' || $period === '') {
                $this->_validationError('studentNumber, academicYear, semester, and period are required.');
                return;
            }

            $eligibility = (new ExamPermitGateService())->checkMoodleEligibility($studentNumber, $academicYear, $semester, strtoupper($period));
            echo json_encode(array_merge(['ok' => true], $eligibility));
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/exam-permit/gate-preview?studentNumber=...&academicYear=...&semester=...&period=...
     * Read-only preview of what generate() would decide, WITHOUT creating a
     * permit and WITHOUT writing a GATE_EVALUATE audit row. Powers the
     * officer drawer's locked Generate Permit button + inline denial
     * reason (see "Frontend changes" for exampermit.html) so an officer
     * sees why generation is blocked before clicking, instead of only
     * after a failed POST /generate.
     *
     * Deliberately audit-silent: a preview fires on every drawer open and
     * every period switch, which is not itself a meaningful officer
     * action — the real GATE_EVALUATE row is still written exactly once
     * per actual generate() call, unchanged from before.
     *
     * Calls the identical ExamPermitGateService::evaluateGate() that
     * generate() calls, so the preview can never disagree with the real
     * decision made at click time (aside from an intervening watchlist/
     * policy change between preview and click, which generate() still
     * re-evaluates and enforces server-side regardless of what the
     * preview showed).
     */
    public function gatePreview()
    {
        try {
            $studentNumber = trim((string)($_GET['studentNumber'] ?? ''));
            $academicYear  = trim((string)($_GET['academicYear'] ?? ''));
            $semester      = trim((string)($_GET['semester'] ?? ''));
            $period        = strtoupper(trim((string)($_GET['period'] ?? '')));

            if ($studentNumber === '' || $academicYear === '' || $semester === '' || $period === '') {
                $this->_validationError('studentNumber, academicYear, semester, and period are required.');
                return;
            }

            $gate = (new ExamPermitGateService())->evaluateGate($studentNumber, $academicYear, $semester, $period);
            echo json_encode(['ok' => true, 'gate' => $gate]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    public function generate()
    {
        try {
            $in=$this->_json(); foreach(['studentNumber','academicYear','semester','period','actorEmail'] as $f) if(trim((string)($in[$f]??''))==='') return $this->_validationError($f.' is required.');
            $in['period']=strtoupper(trim($in['period']));
            $ref=new ExamPermitReferenceDataService(); $preview=$ref->getStudentExamPermit($in['studentNumber'],$in['academicYear'],$in['semester'],$in['period']);
            if(!$preview['ok']) return $this->_failContract(422,'VALIDATION_ERROR','Invalid student, term, or period.');
            $db=Database::getConnection(); $existing=$db->prepare("SELECT * FROM tblExamPermits WHERE studentNumber=:sn AND academicYear=:ay AND semester=:sem AND period=:period AND status='ISSUED' ORDER BY generatedAt DESC, permitID DESC LIMIT 1"); $existing->execute([':sn'=>$in['studentNumber'],':ay'=>$in['academicYear'],':sem'=>$in['semester'],':period'=>$in['period']]); if($row=$existing->fetch()){ return $this->_respondContract(['ok'=>true,'code'=>'ALREADY_ISSUED','permit'=>$row]); }
            $gate=(new ExamPermitGateService())->evaluateGate($in['studentNumber'],$in['academicYear'],$in['semester'],$in['period']); $audit=new ExamPermitAuditService(); $audit->writeAudit('GATE_EVALUATE',$gate['decision']==='ALLOW'?'ALLOW':'DENY',array_merge($in,['detail'=>$gate]));
            if($gate['decision']!=='ALLOW'){ $audit->writeAudit('GENERATE','DENY',array_merge($in,['detail'=>$gate['reason']])); return $this->_failContract(200,'GATE_DENIED',$gate['reason'],'GATE_DENIED'); }
            $db->beginTransaction(); $seq=SequenceGenerator::reserveIdBlock($db,'tblExamPermits',1); $id=SequenceGenerator::formatId('EPM',$seq['firstNo'],7); $db->prepare("INSERT INTO tblExamPermits (permitID,studentNumber,registrationNumber,academicYear,semester,period,status,gateSource,gatePolicyID,gateWatchlistID,gateDecision,gateSummary,generatedBy,generatedAt,printCount) VALUES (:id,:sn,:reg,:ay,:sem,:period,'ISSUED',:source,:policy,:watch,'ALLOW',:summary,:by,NOW(),0)")->execute([':id'=>$id,':sn'=>$in['studentNumber'],':reg'=>$preview['student']['registrationNumber'],':ay'=>$in['academicYear'],':sem'=>$in['semester'],':period'=>$in['period'],':source'=>$gate['source'],':policy'=>$gate['policyID'],':watch'=>$gate['watchlistID'],':summary'=>$gate['reason'],':by'=>$in['actorEmail']]); $audit->writeAudit('GENERATE','SUCCESS',array_merge($in,['permitID'=>$id,'detail'=>$gate])); $db->commit(); return $this->_respondContract(['ok'=>true,'code'=>'GENERATED','permit'=>array_merge(['permitID'=>$id,'studentNumber'=>$in['studentNumber'],'academicYear'=>$in['academicYear'],'semester'=>$in['semester'],'period'=>$in['period'],'status'=>'ISSUED','gateSource'=>$gate['source'],'gatePolicyID'=>$gate['policyID'],'gateWatchlistID'=>$gate['watchlistID'],'gateDecision'=>'ALLOW','printCount'=>0])]);
        } catch(\Throwable $e){ if(isset($db)&&$db->inTransaction())$db->rollBack(); return $this->_serverError($e); }
    }

    public function printStatus()
    {
        try {
            $in = $this->_json();
            if (trim((string)($in['permitID'] ?? '')) === '' || trim((string)($in['actorEmail'] ?? '')) === '') return $this->_validationError('permitID and actorEmail are required.');
            $db = Database::getConnection();
            $s = $db->prepare('SELECT * FROM tblExamPermits WHERE permitID=:id'); $s->execute([':id' => $in['permitID']]); $p = $s->fetch();
            if (!$p) return $this->_failContract(404, 'NOT_FOUND', 'Permit not found.');
            $wasReprint = (int)$p['printCount'] > 0;
            if ($this->_policyVersionChanged($p)) {
                (new ExamPermitAuditService())->writeAudit($wasReprint ? 'REPRINT' : 'PRINT', 'FAILED', ['permitID' => $p['permitID'], 'studentNumber' => $p['studentNumber'], 'period' => $p['period'], 'actorEmail' => $in['actorEmail'], 'detail' => 'POLICY_VERSION_CHANGED']);
                return $this->_failContract(200, 'POLICY_VERSION_CHANGED', 'The policy used to generate this permit has changed. Void and reissue instead of reprinting.', 'POLICY_VERSION_CHANGED');
            }
            $count = (int)$p['printCount'] + 1;
            $db->prepare('UPDATE tblExamPermits SET printCount=:count,lastPrintedBy=:by,lastPrintedAt=NOW() WHERE permitID=:id')->execute([':count' => $count, ':by' => $in['actorEmail'], ':id' => $in['permitID']]);
            (new ExamPermitAuditService())->writeAudit($wasReprint ? 'REPRINT' : 'PRINT', 'SUCCESS', ['permitID' => $p['permitID'], 'studentNumber' => $p['studentNumber'], 'period' => $p['period'], 'actorEmail' => $in['actorEmail'], 'detail' => $wasReprint ? ('Reprint #' . $count . '.') : 'First print.']);
            $p['printCount'] = $count; $p['lastPrintedBy'] = $in['actorEmail'];
            return $this->_respondContract(['ok' => true, 'permit' => $p]);
        } catch (\Throwable $e) { return $this->_serverError($e); }
    }

    /**
     * POST /api/exam-permit/temp-print-status
     * Twin of printStatus() for the 80mm thermal Temporary Permit: same
     * permit row, same POLICY_VERSION_CHANGED guard, but its own counter
     * (tempPrintCount / lastTempPrintedBy / lastTempPrintedAt) and its own
     * audit actionType (TEMP_PRINT / TEMP_REPRINT) so the two print
     * formats are distinguishable in the audit log and never share a
     * count. Does not touch printCount/lastPrintedBy/lastPrintedAt —
     * those remain exclusively the wide-format permit's counters.
     */
    public function tempPrintStatus()
    {
        try {
            $in = $this->_json();
            if (trim((string)($in['permitID'] ?? '')) === '' || trim((string)($in['actorEmail'] ?? '')) === '') return $this->_validationError('permitID and actorEmail are required.');
            $db = Database::getConnection();
            $s = $db->prepare('SELECT * FROM tblExamPermits WHERE permitID=:id'); $s->execute([':id' => $in['permitID']]); $p = $s->fetch();
            if (!$p) return $this->_failContract(404, 'NOT_FOUND', 'Permit not found.');
            $wasReprint = (int)$p['tempPrintCount'] > 0;
            if ($this->_policyVersionChanged($p)) {
                (new ExamPermitAuditService())->writeAudit($wasReprint ? 'TEMP_REPRINT' : 'TEMP_PRINT', 'FAILED', ['permitID' => $p['permitID'], 'studentNumber' => $p['studentNumber'], 'period' => $p['period'], 'actorEmail' => $in['actorEmail'], 'detail' => 'POLICY_VERSION_CHANGED']);
                return $this->_failContract(200, 'POLICY_VERSION_CHANGED', 'The policy used to generate this permit has changed. Void and reissue instead of reprinting.', 'POLICY_VERSION_CHANGED');
            }
            $count = (int)$p['tempPrintCount'] + 1;
            $db->prepare('UPDATE tblExamPermits SET tempPrintCount=:count,lastTempPrintedBy=:by,lastTempPrintedAt=NOW() WHERE permitID=:id')->execute([':count' => $count, ':by' => $in['actorEmail'], ':id' => $in['permitID']]);
            (new ExamPermitAuditService())->writeAudit($wasReprint ? 'TEMP_REPRINT' : 'TEMP_PRINT', 'SUCCESS', ['permitID' => $p['permitID'], 'studentNumber' => $p['studentNumber'], 'period' => $p['period'], 'actorEmail' => $in['actorEmail'], 'detail' => $wasReprint ? ('Temp reprint #' . $count . '.') : 'First temp print.']);
            $p['tempPrintCount'] = $count; $p['lastTempPrintedBy'] = $in['actorEmail'];
            return $this->_respondContract(['ok' => true, 'permit' => $p]);
        } catch (\Throwable $e) { return $this->_serverError($e); }
    }

    public function void()
    {
        try {
            $in = $this->_json();
            if (trim((string)($in['permitID'] ?? '')) === '' || trim((string)($in['voidReasonCode'] ?? '')) === '' || trim((string)($in['actorEmail'] ?? '')) === '') return $this->_failContract(400, 'INVALID_VOID_REASON', 'permitID, voidReasonCode and actorEmail are required.');
            $db = Database::getConnection();
            $s = $db->prepare("SELECT * FROM tblExamPermits WHERE permitID=:id AND status='ISSUED'"); $s->execute([':id' => $in['permitID']]); $p = $s->fetch();
            if (!$p || $p['gateSource'] !== 'POLICY') return $this->_failContract(409, 'VOID_NOT_ALLOWED', 'Only an issued policy-sourced permit may be voided.');
            $l = $db->prepare("SELECT label FROM ref_lookup_values WHERE category='EXAM_PERMIT_VOID_REASON' AND code=:code AND isActive=1"); $l->execute([':code' => $in['voidReasonCode']]); $label = $l->fetchColumn();
            if (!$label) return $this->_failContract(400, 'INVALID_VOID_REASON', 'The selected void reason is invalid.');
            if (!$this->_policyVersionChanged($p)) return $this->_failContract(409, 'VOID_NOT_ALLOWED', 'The policy has not changed since issuance.');
            $db->prepare("UPDATE tblExamPermits SET status='VOIDED' WHERE permitID=:id")->execute([':id' => $in['permitID']]);
            (new ExamPermitAuditService())->writeAudit('VOID', 'SUCCESS', ['permitID' => $p['permitID'], 'studentNumber' => $p['studentNumber'], 'period' => $p['period'], 'actorEmail' => $in['actorEmail'], 'detail' => ['policyID' => $p['gatePolicyID'], 'voidReason' => $label]]);
            return $this->_respondContract(['ok' => true, 'permit' => ['permitID' => $p['permitID'], 'status' => 'VOIDED']]);
        } catch (\Throwable $e) { return $this->_serverError($e); }
    }

    // Shared by printStatus() and void(): a policy-sourced permit's "version" is considered
    // changed if the gate now resolves to a different policy, or the same policy was modified
    // after this permit was generated. Watchlist-sourced permits never have a version to change.
    private function _policyVersionChanged(array $permit): bool
    {
        if (($permit['gateSource'] ?? '') !== 'POLICY') return false;
        $gate = (new ExamPermitGateService())->evaluateGate($permit['studentNumber'], $permit['academicYear'], $permit['semester'], $permit['period']);
        if (($gate['policyID'] ?? null) !== ($permit['gatePolicyID'] ?? null)) return true;
        if (empty($permit['gatePolicyID'])) return false;
        $s = Database::getConnection()->prepare('SELECT lastModified FROM tblExamPermitPolicies WHERE policyID=:id');
        $s->execute([':id' => $permit['gatePolicyID']]);
        $lastModified = $s->fetchColumn();
        return $lastModified && !empty($permit['generatedAt']) && strtotime($lastModified) > strtotime($permit['generatedAt']);
    }


    public function policies()
    {
        try {
            $db = Database::getConnection();
            $rows = $db->query('SELECT * FROM tblExamPermitPolicies ORDER BY priorityOrder DESC, policyID')->fetchAll();
            // Admin editor needs every rule regardless of isEnabled (so a disabled rule can be
            // re-enabled) — do not reuse ExamPermitPolicyService::rules(), which is gate-scoped
            // to isEnabled=1 only.
            $ruleStmt = $db->prepare('SELECT policyRuleID, ruleType, ruleLabel, feeID, thresholdValue, isNegated, isEnabled, sortOrder FROM tblExamPermitPolicyRules WHERE policyID = :id ORDER BY sortOrder, policyRuleID');
            foreach ($rows as &$r) {
                $r['appliesToPeriods'] = $r['appliesToPeriods'] ? explode(',', $r['appliesToPeriods']) : [];
                $r['scope'] = ['scopeType' => $r['scopeType'], 'studentNumber' => $r['studentNumber'], 'programID' => $r['programID'], 'yearLevel' => $r['yearLevel'], 'classCode' => $r['classCode'], 'priorityOrder' => (int)$r['priorityOrder']];
                $ruleStmt->execute([':id' => $r['policyID']]);
                $rules = $ruleStmt->fetchAll();
                foreach ($rules as &$rule) {
                    // PDO returns every column as a string; JS treats "0" as truthy, so these
                    // must be cast to real booleans/numbers before reaching the frontend.
                    $rule['isNegated'] = (bool)$rule['isNegated'];
                    $rule['isEnabled'] = (bool)$rule['isEnabled'];
                    $rule['thresholdValue'] = $rule['thresholdValue'] !== null ? (float)$rule['thresholdValue'] : null;
                }
                unset($rule);
                $r['rules'] = $rules;
                $r['isEnabled'] = (bool)$r['isEnabled'];
            }
            unset($r);
            return $this->_respondContract(['ok' => true, 'policies' => $rows]);
        } catch (\Throwable $e) { return $this->_serverError($e); }
    }
    public function policiesSave(){try{$in=$this->_json();foreach(['policyName','scope','actorEmail'] as $f)if(empty($in[$f]))return $this->_validationError($f.' is required.');$id=(new ExamPermitPolicyService())->save($in);(new ExamPermitAuditService())->writeAudit(empty($in['policyID'])?'POLICY_CREATE':'POLICY_UPDATE','SUCCESS',['permitID'=>$id,'actorEmail'=>$in['actorEmail'],'detail'=>$in['policyName']]);return $this->_respondContract(['ok'=>true,'policyID'=>$id,'message'=>'Policy saved.']);}catch(\InvalidArgumentException $e){return $this->_failContract(400,'RULE_TYPE_NOT_IMPLEMENTED',$e->getMessage());}catch(\Throwable $e){$this->_serverError($e);}}
    public function policiesEnable(){try{$in=$this->_json();$db=Database::getConnection();$s=$db->prepare('UPDATE tblExamPermitPolicies SET isEnabled=:enabled,modifiedBy=:by,lastModified=NOW() WHERE policyID=:id');$s->execute([':enabled'=>!empty($in['isEnabled'])?1:0,':by'=>$in['actorEmail']??null,':id'=>$in['policyID']??'']);if(!$s->rowCount())return $this->_failContract(404,'NOT_FOUND','Policy not found.');(new ExamPermitAuditService())->writeAudit(!empty($in['isEnabled'])?'POLICY_ENABLE':'POLICY_DISABLE','SUCCESS',['actorEmail'=>$in['actorEmail']??null,'detail'=>$in['policyID']]);return $this->_respondContract(['ok'=>true]);}catch(\Throwable $e){$this->_serverError($e);}}
    public function policyAdminBootstrap(){try{$in=$_GET;$term=(new ExamPermitReferenceDataService())->getActiveTerm();$ay=$term['academicYear'];$sem=$term['semester'];$students=(new ExamPermitReferenceDataService())->getTermStudentRoster($ay,$sem);$programs=(new ReferenceDataService())->getAllPrograms();$fees=(new ReferenceDataService())->getActiveFees();$classes=(new ReferenceDataService())->getAllSections();$years=[];foreach($students as $s)$years[$s['yearLevel']]=true;return $this->_respondContract(['ok'=>true,'activeTerm'=>$term,'programs'=>$programs,'yearLevels'=>array_keys($years),'classes'=>array_map(fn($x)=>['classCode'=>$x['label']],$classes),'fees'=>$fees,'students'=>$students]);}catch(\Throwable $e){$this->_serverError($e);}}
    public function policyAudit()
    {
        try {
            $limit = min(200, max(1, (int)($_GET['limit'] ?? 60)));
            $policyID = trim((string)($_GET['policyID'] ?? ''));
            $sql = 'SELECT * FROM tblExamPermitAudit';
            $params = [];
            if ($policyID !== '') { $sql .= ' WHERE detail LIKE :policy'; $params[':policy'] = '%' . $policyID . '%'; }
            $sql .= ' ORDER BY createdAt DESC LIMIT ' . $limit;
            $s = Database::getConnection()->prepare($sql);
            $s->execute($params);
            return $this->_respondContract(['ok' => true, 'rows' => $s->fetchAll()]);
        } catch (\Throwable $e) { return $this->_serverError($e); }
    }
    public function watchlist(){try{$in=$_GET;$entries=(new ExamPermitWatchlistService())->list($in['academicYear']??'', $in['semester']??'', $in['studentNumber']??null, $in['listType']??null, $in['status']??null);return $this->_respondContract(['ok'=>true,'entries'=>$entries]);}catch(\Throwable $e){$this->_serverError($e);}}
    public function watchlistAdd(){try{$in=$this->_json();if(trim((string)($in['reason']??''))===''||!in_array($in['listType']??'', ['BLACKLIST','WHITELIST'],true))return $this->_validationError('A valid listType and non-empty reason are required.');$roster=(new ExamPermitReferenceDataService())->getTermStudentRoster($in['academicYear'],$in['semester']);$valid=array_filter($roster,fn($s)=>$s['studentNumber']===$in['studentNumber']);if(!$valid)return $this->_failContract(400,'INVALID_STUDENT','Student is not registered in the active term.');$id=(new ExamPermitWatchlistService())->add($in);(new ExamPermitAuditService())->writeAudit('WATCHLIST_ADD','SUCCESS',['studentNumber'=>$in['studentNumber'],'actorEmail'=>$in['actorEmail']??null,'detail'=>['listType'=>$in['listType'],'reason'=>$in['reason']]]);return $this->_respondContract(['ok'=>true,'watchlistID'=>$id]);}catch(\Throwable $e){$this->_serverError($e);}}
    public function watchlistRemove(){try{$in=$this->_json();$ok=(new ExamPermitWatchlistService())->remove($in['watchlistID']??'', $in['actorEmail']??'');if(!$ok)return $this->_failContract(404,'NOT_FOUND','Active watchlist entry not found.');(new ExamPermitAuditService())->writeAudit('WATCHLIST_REMOVE','SUCCESS',['actorEmail'=>$in['actorEmail']??null,'detail'=>$in['watchlistID']]);return $this->_respondContract(['ok'=>true]);}catch(\Throwable $e){$this->_serverError($e);}}

    private function _json(): array { return json_decode(file_get_contents('php://input'),true) ?: []; }
    private function _respondContract(array $body): void { echo json_encode($body); }
    private function _failContract(int $status,string $code,string $message,?string $topCode=null): void { http_response_code($status); echo json_encode(['ok'=>false,'code'=>$topCode??$code,'error'=>['code'=>$code,'message'=>$message]]); }

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