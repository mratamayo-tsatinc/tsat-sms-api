<?php

namespace App\Controllers;

use App\Core\Database;

// ─────────────────────────────────────────────────────────────────────────
// CashierController
//
// Fee lookup is served by aliasing api/cashier/fees to EnrollmentController::fees()
// in api.php — tblFees is a shared table with no Cashier-specific logic.
//
// students() exists as Cashier-specific logic (not an alias) because it is
// scoped to the active academic term — Enrollment's and Accounts' /students
// endpoints search all students regardless of term. The caller supplies
// academicYear/semester as explicit query parameters.
//
// balances() returns outstanding assessment balances for a student in the
// active term. Only returns assessments with a remaining balance (outstanding-
// only view, unlike Accounts' full ledger).
//
// createPayment() processes a complete payment transaction: inserts one
// tblPayments row and N tblPaymentDetails rows in a single transaction.
// Returns the complete payment response including OR number, student name,
// payment date, line allocations, and change amount. Duplicate OR numbers are
// rejected with 409 before any writes occur.
// ─────────────────────────────────────────────────────────────────────────
class CashierController
{
    // ─────────────────────────────────────────────────────────────────────
    // GET /api/cashier/students
    //   ?academicYear=2026-2027   (required)
    //   &semester=1ST%20SEMESTER  (required)
    //   &q=<search text>          (required — min 2 characters)
    //   &limit=<n>                (optional — default 20, hard cap 50)
    //
    // Prefix-match typeahead scoped to students with a registration in the
    // given academicYear/semester. INNER JOINs tblRegistrations so only
    // students enrolled in the specified term are returned.
    //
    // Auth: unauthenticated,
    // posture as the Enrollment/Accounts read endpoints.
    // ─────────────────────────────────────────────────────────────────────
    public function students()
    {
        $ay  = trim($_GET['academicYear'] ?? '');
        $sem = trim($_GET['semester']     ?? '');

        if ($ay === '' || $sem === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'academicYear and semester are required.']);
            return;
        }

        $q = trim($_GET['q'] ?? '');

        if (mb_strlen($q) < 2) {
            echo json_encode(['ok' => true, 'query' => $q, 'suggestions' => []]);
            return;
        }

        $limit = (int)($_GET['limit'] ?? 20);
        if ($limit < 1)  $limit = 1;
        if ($limit > 50) $limit = 50;   // hard cap regardless of client request

        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT DISTINCT
                s.studentNumber, s.studentID,
                s.lastName, s.firstName, s.middleName, s.middleInitial, s.nameExtension
            FROM tblStudents s
            INNER JOIN tblRegistrations r ON r.studentNumber = s.studentNumber
            WHERE r.academicYear = :ay
              AND r.semester     = :sem
              AND (
                    s.studentNumber LIKE :q1
                 OR s.lastName      LIKE :q2
                 OR s.firstName     LIKE :q3
                 OR CONCAT(s.firstName, ' ', s.lastName) LIKE :q4
				 OR CONCAT(s.lastName, ', ', s.firstName) LIKE :q5
              )
            ORDER BY s.lastName, s.firstName
            LIMIT :lim
        ");

        // Prefix-only — no leading '%' — keeps the studentNumber PK
        // and (lastName, firstName) index usable.
        $prefix = $q . '%';
        $stmt->bindValue(':ay',  $ay,     \PDO::PARAM_STR);
        $stmt->bindValue(':sem', $sem,    \PDO::PARAM_STR);
        $stmt->bindValue(':q1',  $prefix, \PDO::PARAM_STR);
        $stmt->bindValue(':q2',  $prefix, \PDO::PARAM_STR);
        $stmt->bindValue(':q3',  $prefix, \PDO::PARAM_STR);
        $stmt->bindValue(':q4',  $prefix, \PDO::PARAM_STR);
		$stmt->bindValue(':q5',  $prefix, \PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit,  \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $suggestions = [];
        foreach ($rows as $row) {
            $name          = $this->_studentDisplayName($row);
            $studentNumber = (string)($row['studentNumber'] ?? '');
            $studentID     = (string)($row['studentID'] ?? '');
            $suggestions[] = [
                'studentNumber' => $studentNumber,
                'studentID'     => $studentID,
                'name'          => $name,
                'naturalName'   => $name,
                'label'         => $name . ' (' . $studentNumber . ')',
            ];
        }

        echo json_encode([
            'ok'           => true,
            'query'        => $q,
            'academicYear' => $ay,
            'semester'     => $sem,
            'suggestions'  => $suggestions,
        ]);
    }

    // Builds "[Last name] [Ext], [First name] [Middle]" display name.
    // Shared format used by Enrollment, Accounts, and Cashier typeahead labels.
    private function _studentDisplayName(array $row): string
    {
        $lastName      = trim((string)($row['lastName'] ?? ''));
        $nameExtension = trim((string)($row['nameExtension'] ?? ''));
        $firstName     = trim((string)($row['firstName'] ?? ''));
        $middleName    = trim((string)($row['middleName'] ?? '')) ?: trim((string)($row['middleInitial'] ?? ''));

        $lastPart  = implode(' ', array_filter([$lastName, $nameExtension], fn($p) => $p !== ''));
        $firstPart = implode(' ', array_filter([$firstName, $middleName], fn($p) => $p !== ''));

        return implode(', ', array_filter([$lastPart, $firstPart], fn($p) => $p !== ''));
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/cashier/balances?studentNumber=<sn>
    //
    // Returns outstanding assessment balances for a student in the active
    // term. Only active assessments with a remaining balance are returned.
    //
    // "No active-term registration" is a normal HTTP 200 response with
    // ok:false and code:NO_ACTIVE_REGISTRATION, not an HTTP error.
    //
    // Registration tie-break: ORDER BY dateCreated DESC LIMIT 1.
    // ─────────────────────────────────────────────────────────────────────
    public function balances()
    {
        $studentNumber = trim($_GET['studentNumber'] ?? '');
        if ($studentNumber === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Student number is required.']);
            return;
        }

        $db = Database::getConnection();
        $referenceData = new \App\Services\ReferenceDataService();

        try {
            $term = $referenceData->getActiveTerm();
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            return;
        }
        $ay = $term['academicYear'];
        $sem = $term['semester'];

        $sStmt = $db->prepare("SELECT * FROM tblStudents WHERE studentNumber = ?");
        $sStmt->execute([$studentNumber]);
        $student = $sStmt->fetch();
        if (!$student) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Student was not found: ' . $studentNumber]);
            return;
        }

        $regStmt = $db->prepare("
            SELECT * FROM tblRegistrations
            WHERE studentNumber = ? AND academicYear = ? AND semester = ?
            ORDER BY dateCreated DESC LIMIT 1
        ");
        $regStmt->execute([$studentNumber, $ay, $sem]);
        $reg = $regStmt->fetch();

        $studentName = $referenceData->buildStudentFullName($student);

        // Structured non-error — HTTP 200. See §0.-1.G.
        if (!$reg) {
            echo json_encode([
                'ok'            => false,
                'code'          => 'NO_ACTIVE_REGISTRATION',
                'studentNumber' => $studentNumber,
                'studentName'   => $studentName,
                'academicYear'  => $ay,
                'semester'      => $sem,
                'message'       => 'No enrollment found for ' . $ay . ' / ' . $sem . '.',
            ]);
            return;
        }

        $registrationNumber = (string)($reg['RegistrationNumber'] ?? '');

        // Paid-so-far per assessment, summed across ALL payment details
        // regardless of OR — assessments are already
        // term-scoped via their registrationNumber, so no extra date
        // filter is needed here).
        $paidStmt = $db->prepare("
            SELECT AssessmentID, COALESCE(SUM(Amount), 0) AS paid
            FROM tblPaymentDetails
            WHERE AssessmentID IN (
                SELECT assessmentID FROM tblAssessments WHERE registrationNumber = ?
            )
            GROUP BY AssessmentID
        ");
        $paidStmt->execute([$registrationNumber]);
        $paidByAssessment = [];
        foreach ($paidStmt->fetchAll() as $row) {
            $paidByAssessment[(string)$row['AssessmentID']] = (float)$row['paid'];
        }

        $aStmt = $db->prepare("
            SELECT a.*, f.feeCode, f.feeDescription
            FROM tblAssessments a
            LEFT JOIN tblFees f ON f.feeID = a.feeID
            WHERE a.registrationNumber = ?
        ");
        $aStmt->execute([$registrationNumber]);
        $assessmentRows = $aStmt->fetchAll();

        $assessments = [];
        $existingFeeIDs = [];
        $totalAssessed = 0.0;
        $totalPaid = 0.0;

        foreach ($assessmentRows as $row) {
            // Blank/missing isActive defaults to active — same default used by balances().
            $isActive = ((int)($row['isActive'] ?? 1)) === 1;
            if (!$isActive) continue;

            $feeKey = (string)($row['feeID'] ?? '');
            if ($feeKey !== '') $existingFeeIDs[$feeKey] = true; // locks the fee even if fully paid

            $amount = (float)($row['amount'] ?? 0);
            $paid   = $paidByAssessment[(string)($row['assessmentID'] ?? '')] ?? 0.0;
            $balance = $amount - $paid;

            if ($balance <= 0.009) continue; // outstanding-only, unlike Accounts' full ledger

            $totalAssessed += $amount;
            $totalPaid     += $paid;

            $assessments[] = [
                'assessmentID'   => (string)($row['assessmentID'] ?? ''),
                'feeID'          => $feeKey,
                'feeCode'        => (string)($row['feeCode'] ?? ''),
                'feeDescription' => (string)($row['feeDescription'] ?? ''),
                'amount'         => number_format($amount, 2, '.', ''),
                'paidAmount'     => number_format($paid, 2, '.', ''), // NOT "paid" — see §0.-1.E
                'balance'        => number_format($balance, 2, '.', ''),
                'isActive'       => true,
            ];
        }

        echo json_encode([
            'ok'                 => true,
            'studentNumber'      => $studentNumber,
            'studentName'        => $studentName,
            'registrationNumber' => $registrationNumber,
            'academicYear'       => $ay,
            'semester'           => $sem,
            'programID'          => (string)($reg['programID'] ?? ($student['programID'] ?? '')),
            'sectionID'          => (string)($reg['sectionID'] ?? ''),
            'yearLevel'          => (string)($reg['yearLevel'] ?? ''),
            'totalAssessed'      => number_format($totalAssessed, 2, '.', ''),
            'totalPaid'          => number_format($totalPaid, 2, '.', ''),
            'outstandingBalance' => number_format($totalAssessed - $totalPaid, 2, '.', ''),
            'assessments'        => $assessments,
            // strval() is required here, not cosmetic: PHP silently casts
            // array keys that look numeric to int, so array_keys() alone
            // would return e.g. 2 instead of "2" even though $feeKey was
            // always a string when it was assigned above. The plan's field
            // shape (§0.-1.E) declares existingFeeIDs as string[] — match
            // it exactly rather than relying on cashier.html's
            // _buildFeeIdLookup_() to paper over the mismatch via
            // String(id) coercion.
            'existingFeeIDs'     => array_map('strval', array_keys($existingFeeIDs)),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/cashier/assessments
    // Body: { studentNumber, registrationNumber, feeID, amount, note, createdBy }
    //
    // Adds a new assessment line to an active-term registration.
    // Three guards: registration must belong to active term and given student;
    // fee must exist and not be disabled; fee must not already have an active
    // assessment on this registration.
    // ─────────────────────────────────────────────────────────────────────
    public function createAssessment()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentNumber      = trim((string)($input['studentNumber'] ?? ''));
        $registrationNumber = trim((string)($input['registrationNumber'] ?? ''));
        $feeID              = trim((string)($input['feeID'] ?? ''));
        $amount             = (float)($input['amount'] ?? 0);
        $note               = trim((string)($input['note'] ?? ''));
        $createdBy          = trim((string)($input['createdBy'] ?? ''));

        if ($studentNumber === '')      { http_response_code(400); echo json_encode(['error' => 'Student number is required.']); return; }
        if ($registrationNumber === '') { http_response_code(400); echo json_encode(['error' => 'Registration number is required.']); return; }
        if ($feeID === '')              { http_response_code(400); echo json_encode(['error' => 'Fee is required.']); return; }
        if ($amount <= 0)               { http_response_code(422); echo json_encode(['error' => 'Assessment amount must be greater than zero.']); return; }

        $db = Database::getConnection();
        $referenceData = new \App\Services\ReferenceDataService();

        try {
            $term = $referenceData->getActiveTerm();
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            return;
        }

        $regStmt = $db->prepare("SELECT * FROM tblRegistrations WHERE RegistrationNumber = ?");
        $regStmt->execute([$registrationNumber]);
        $reg = $regStmt->fetch();
        if (!$reg) { http_response_code(404); echo json_encode(['error' => 'Registration number was not found: ' . $registrationNumber]); return; }
        if ((string)($reg['academicYear'] ?? '') !== $term['academicYear'] || (string)($reg['semester'] ?? '') !== $term['semester']) {
            http_response_code(422);
            echo json_encode(['error' => 'Registration does not belong to the active term (' . $term['academicYear'] . ' / ' . $term['semester'] . ').']);
            return;
        }
        if (strcasecmp((string)($reg['studentNumber'] ?? ''), $studentNumber) !== 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Registration number does not belong to student: ' . $studentNumber]);
            return;
        }

        $feeStmt = $db->prepare("SELECT feeCode, feeDescription, feeIsDisabled FROM tblFees WHERE feeID = ?");
        $feeStmt->execute([$feeID]);
        $fee = $feeStmt->fetch();
        if (!$fee) { http_response_code(404); echo json_encode(['error' => 'Fee was not found: ' . $feeID]); return; }
        if ((int)($fee['feeIsDisabled'] ?? 0) === 1) {
            http_response_code(422);
            echo json_encode(['error' => 'Fee is disabled and cannot be assessed: ' . $feeID]);
            return;
        }

        $dupStmt = $db->prepare("SELECT assessmentID FROM tblAssessments WHERE registrationNumber = ? AND feeID = ? AND isActive = 1");
        $dupStmt->execute([$registrationNumber, $feeID]);
		
        if ($dupStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'This fee is already assessed on the current registration: ' . ((string)($fee['feeCode'] ?? '') ?: $feeID) . '.']);
            return;
        }

        $amountStr = number_format($amount, 2, '.', '');
        $now = date('Y-m-d H:i:s');

        try {
            $db->beginTransaction();
            $seq = \App\Models\SequenceGenerator::reserveIdBlock($db, 'tblAssessments', 1);
            $assessmentID = \App\Models\SequenceGenerator::formatId('ASM', $seq['firstNo'], 8);

            $stmt = $db->prepare("
                INSERT INTO tblAssessments
                    (assessmentID, registrationNumber, feeID, amount, cash, note, isActive, createdBy, dateCreated, modifiedBy, lastModified)
                VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, '', '')
            ");
            $stmt->execute([$assessmentID, $registrationNumber, $feeID, $amountStr, $amountStr, $note, $createdBy, $now]);
            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Unable to add fee: ' . $e->getMessage()]);
            return;
        }

        // Response shape matches injectAddedFeeRow()'s expected structure.
        echo json_encode([
            'ok'                 => true,
            'assessmentID'       => $assessmentID,
            'registrationNumber' => $registrationNumber,
            'feeID'              => $feeID,
            'feeCode'            => (string)($fee['feeCode'] ?? ''),
            'feeDescription'     => (string)($fee['feeDescription'] ?? ''),
            'amount'             => $amountStr,
            'paidAmount'         => '0.00',
            'balance'            => $amountStr,
            'note'               => $note,
            'message'            => 'Assessment line added.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/cashier/payment-methods
    //
    // Returns the payment methods currently offered to the cashier — the
    // join of ref_lookup_values (PAYMENT_METHOD, active) against
    // tblPaymentMethodAccounts (active). A method with no active mapping
    // (e.g. CHECK, until a settlement account is assigned) is simply
    // absent from this list. Read-only; no admin UI backs this in this phase.
    // ─────────────────────────────────────────────────────────────────────
    public function paymentMethods()
    {
        $referenceData = new \App\Services\ReferenceDataService();
        $methodAccounts = $referenceData->getActivePaymentMethodAccounts();

        $methods = [];
        foreach ($methodAccounts as $code => $details) {
            $methods[] = [
                'code'  => $code,
                'label' => $details['label'],
            ];
        }

        echo json_encode(['ok' => true, 'methods' => $methods]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/cashier/payments
    // ─────────────────────────────────────────────────────────────────────

    // Resolves a user's display name from tblUsers by email address.
    // Degrades gracefully to the raw email on any failure — a lookup failure
    // must never block a payment or receipt from rendering.
    private function _resolveUserDisplayName($db, string $rawCreatedBy): string
    {
        if ($rawCreatedBy === '') return $rawCreatedBy;
        try {
            $stmt = $db->prepare("SELECT fullName FROM tblUsers WHERE email = ? LIMIT 1");
            $stmt->execute([$rawCreatedBy]);
            $row = $stmt->fetch();
            $fullName = trim((string)($row['fullName'] ?? ''));
            return $fullName !== '' ? $fullName : $rawCreatedBy;
        } catch (\Exception $e) {
            return $rawCreatedBy; // tblUsers missing/misconfigured — degrade, never throw
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/cashier/payments
    // Body: {
    //   studentNumber, registrationNumber, amountTendered, paymentDate,
    //   lines: [{ assessmentID, allocatedAmount }], localORNumber, createdBy
    // }
    //
    // Processes a complete payment transaction for the active term.
    // OR number prefers client-supplied localORNumber (^OR\d{10}$);
    // falls back to server-side generation when missing or malformed.
    // ─────────────────────────────────────────────────────────────────────
    public function createPayment()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentNumber      = trim((string)($input['studentNumber'] ?? ''));
        $registrationNumber = trim((string)($input['registrationNumber'] ?? ''));
        $amountTendered     = (float)($input['amountTendered'] ?? 0);
        $paymentDateRaw     = trim((string)($input['paymentDate'] ?? ''));
        $lines              = is_array($input['lines'] ?? null) ? $input['lines'] : [];
        $localORNumber      = trim((string)($input['localORNumber'] ?? ''));
        $createdBy          = trim((string)($input['createdBy'] ?? ''));
        $paymentReferenceInput = trim((string)($input['paymentReference'] ?? ''));

        // ── Basic validation ─────────────────────────────────────────────────────────
        if ($studentNumber === '')      { http_response_code(400); echo json_encode(['error' => 'Student number is required.']); return; }
        if ($registrationNumber === '') { http_response_code(400); echo json_encode(['error' => 'Registration number is required.']); return; }
        if ($amountTendered <= 0)       { http_response_code(422); echo json_encode(['error' => 'Amount tendered must be greater than zero.']); return; }
        if (empty($lines))              { http_response_code(400); echo json_encode(['error' => 'At least one payment line is required.']); return; }

        $totalAllocated = 0.0;
        foreach ($lines as $line) {
            $amt = (float)($line['allocatedAmount'] ?? 0);
            if ($amt <= 0) { http_response_code(422); echo json_encode(['error' => 'Each allocated amount must be greater than zero.']); return; }
            $totalAllocated += $amt;
        }
        if ($amountTendered - $totalAllocated < -0.009) {
            http_response_code(422);
            echo json_encode(['error' => 'Amount tendered (' . number_format($amountTendered, 2) . ') is less than total allocated (' . number_format($totalAllocated, 2) . ').']);
            return;
        }

        $db = Database::getConnection();
        $referenceData = new \App\Services\ReferenceDataService();

        try {
            $term = $referenceData->getActiveTerm();
            $paymentDate = $this->_parseCashierPaymentDate($paymentDateRaw);
        } catch (\Exception $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
            return;
        }

        // ── Payment method / settlement account resolution (blank input = CASH) ──
        // getActivePaymentMethodAccounts() degrades to [] if the payment-method
        // tables haven't been migrated yet. CASH must never be blocked by that —
        // only non-cash methods require a resolved mapping to proceed.
        $paymentMethodCode = $referenceData->resolvePaymentMethodCode($input['paymentMethod'] ?? '');
        $methodAccounts = $referenceData->getActivePaymentMethodAccounts();
        $settlementAccountCode = $methodAccounts[$paymentMethodCode]['settlementAccountCode'] ?? null;

        if ($paymentMethodCode === 'CASH') {
            // Cash never carries a reference number, even if the client sent one.
            $paymentReferenceToStore = null;
        } else {
            if (!isset($methodAccounts[$paymentMethodCode])) {
                http_response_code(422);
                echo json_encode(['error' => 'Payment method is not available: ' . $paymentMethodCode]);
                return;
            }
            if ($paymentReferenceInput === '') {
                http_response_code(422);
                echo json_encode(['error' => 'A reference number is required for ' . $methodAccounts[$paymentMethodCode]['label'] . ' payments.']);
                return;
            }
            // Duplicate reference check — reject before any writes, mirrors the OR-number guard below.
            try {
                $dupRefStmt = $db->prepare("SELECT paymentID FROM tblPayments WHERE paymentReference = ?");
                $dupRefStmt->execute([$paymentReferenceInput]);
            } catch (\PDOException $e) {
                // tblPayments.paymentReference doesn't exist yet (migration not applied) —
                // reject cleanly rather than crash; CASH is unaffected by this branch.
                http_response_code(422);
                echo json_encode(['error' => 'Payment method is not available: ' . $paymentMethodCode]);
                return;
            }
            if ($dupRefStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'Reference number already used on another payment: ' . $paymentReferenceInput]);
                return;
            }
            $paymentReferenceToStore = $paymentReferenceInput;
        }

        $sStmt = $db->prepare("SELECT * FROM tblStudents WHERE studentNumber = ?");
        $sStmt->execute([$studentNumber]);
        $student = $sStmt->fetch();
        if (!$student) { http_response_code(404); echo json_encode(['error' => 'Student was not found: ' . $studentNumber]); return; }

        $regStmt = $db->prepare("SELECT * FROM tblRegistrations WHERE RegistrationNumber = ?");
        $regStmt->execute([$registrationNumber]);
        $reg = $regStmt->fetch();
        if (!$reg) { http_response_code(404); echo json_encode(['error' => 'Registration number was not found: ' . $registrationNumber]); return; }
        if ((string)($reg['academicYear'] ?? '') !== $term['academicYear'] || (string)($reg['semester'] ?? '') !== $term['semester']) {
            http_response_code(422);
            echo json_encode(['error' => 'Registration does not belong to the active term (' . $term['academicYear'] . ' / ' . $term['semester'] . ').']);
            return;
        }
        if (strcasecmp((string)($reg['studentNumber'] ?? ''), $studentNumber) !== 0) {
            http_response_code(422);
            echo json_encode(['error' => 'Registration number does not belong to student: ' . $studentNumber]);
            return;
        }

        // ── Validate each line against server-computed remaining balance ──────
        $validatedLines = [];
        foreach ($lines as $line) {
            $assessmentID = trim((string)($line['assessmentID'] ?? ''));
            $lineAllocated = (float)($line['allocatedAmount'] ?? 0);
            if ($assessmentID === '') { http_response_code(400); echo json_encode(['error' => 'Assessment ID is required on each payment line.']); return; }

            $aStmt = $db->prepare("
                SELECT a.*, f.feeCode, f.feeDescription
                FROM tblAssessments a LEFT JOIN tblFees f ON f.feeID = a.feeID
                WHERE a.assessmentID = ?
            ");
            $aStmt->execute([$assessmentID]);
            $assessment = $aStmt->fetch();
            if (!$assessment) { http_response_code(404); echo json_encode(['error' => 'Assessment ID was not found: ' . $assessmentID]); return; }
            if (strcasecmp((string)($assessment['registrationNumber'] ?? ''), $registrationNumber) !== 0) {
                http_response_code(422);
                echo json_encode(['error' => 'Assessment ' . $assessmentID . ' does not belong to registration ' . $registrationNumber . '.']);
                return;
            }
            // Blank/missing isActive defaults to active.
            if (((int)($assessment['isActive'] ?? 1)) !== 1) {
                http_response_code(422);
                echo json_encode(['error' => 'Assessment ' . $assessmentID . ' is not active.']);
                return;
            }

            $paidStmt = $db->prepare("SELECT COALESCE(SUM(Amount),0) AS paid FROM tblPaymentDetails WHERE AssessmentID = ?");
            $paidStmt->execute([$assessmentID]);
            $alreadyPaid = (float)($paidStmt->fetch()['paid'] ?? 0);
            $serverBalance = (float)($assessment['amount'] ?? 0) - $alreadyPaid;

            if ($lineAllocated - $serverBalance > 0.009) {
                http_response_code(422);
                echo json_encode(['error' => 'Allocated amount for ' . ((string)($assessment['feeCode'] ?? '') ?: $assessmentID) . ' (' . number_format($lineAllocated, 2) . ') exceeds balance (' . number_format($serverBalance, 2) . ').']);
                return;
            }

            $validatedLines[] = [
                'assessmentID'   => $assessmentID,
                'feeID'          => (string)($assessment['feeID'] ?? ''),
                'feeCode'        => (string)($assessment['feeCode'] ?? ''),
                'feeDescription' => (string)($assessment['feeDescription'] ?? ''),
                'allocatedAmount'=> $lineAllocated,
            ];
        }

        // ── Resolve OR number ──────────────────────────────────────────
        $useClientOR = (bool)preg_match('/^OR\d{10}$/', $localORNumber);
        $orNumber = $useClientOR ? $localORNumber : $this->_generateNextORNumber($db);

        // Duplicate OR check — reject before any writes.
        $dupStmt = $db->prepare("SELECT paymentID FROM tblPayments WHERE ORNumber = ?");
        $dupStmt->execute([$orNumber]);
        if ($dupStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'OR number already exists: ' . $orNumber . '. This usually means two cashier stations have overlapping OR ranges — reconfigure and retry.']);
            return;
        }

        $amountAllocatedStr = number_format($totalAllocated, 2, '.', '');
        $now = date('Y-m-d H:i:s');
        $detailRows = [];

        try {
            $db->beginTransaction();

            $paySeq = \App\Models\SequenceGenerator::reserveIdBlock($db, 'tblPayments', 1);
            $paymentID = \App\Models\SequenceGenerator::formatId('PAY', $paySeq['firstNo'], 8);

            try {
                $payStmt = $db->prepare("
                    INSERT INTO tblPayments
                        (paymentID, ORNumber, registrationNumber, AmountPaid,
                         PaymentMonthNumber, PaymentMonth, PaymentDay, PaymentYear,
                         dateCreated, createdBy, paymentMethod, settlementAccount, paymentReference)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $payStmt->execute([
                    $paymentID, $orNumber, $registrationNumber, $amountAllocatedStr,
                    $paymentDate['monthNumber'], $paymentDate['monthName'],
                    $paymentDate['day'], $paymentDate['year'], $now, $createdBy,
                    $paymentMethodCode, $settlementAccountCode, $paymentReferenceToStore,
                ]);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '42S22') throw $e; // rethrow anything but "unknown column"
                // tblPayments hasn't been migrated yet — fall back to the
                // original 10-column insert so Cash payments keep working.
                $payStmt = $db->prepare("
                    INSERT INTO tblPayments
                        (paymentID, ORNumber, registrationNumber, AmountPaid,
                         PaymentMonthNumber, PaymentMonth, PaymentDay, PaymentYear,
                         dateCreated, createdBy)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $payStmt->execute([
                    $paymentID, $orNumber, $registrationNumber, $amountAllocatedStr,
                    $paymentDate['monthNumber'], $paymentDate['monthName'],
                    $paymentDate['day'], $paymentDate['year'], $now, $createdBy,
                ]);
                $paymentMethodCode = null; $settlementAccountCode = null; $paymentReferenceToStore = null;
            }

            // Reserve all payment detail IDs as one block so concurrent
            // transactions never collide even when N > 1.
            $detailSeq = \App\Models\SequenceGenerator::reserveIdBlock($db, 'tblPaymentDetails', count($validatedLines));
            $detailStmt = $db->prepare("
                INSERT INTO tblPaymentDetails (paymentDetailID, ORNumber, AssessmentID, Amount, createdBy, dateCreated)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach ($validatedLines as $i => $vLine) {
                $pdID = \App\Models\SequenceGenerator::formatId('PD', $detailSeq['firstNo'] + $i, 8);
                $amtStr = number_format($vLine['allocatedAmount'], 2, '.', '');
                $detailStmt->execute([$pdID, $orNumber, $vLine['assessmentID'], $amtStr, $createdBy, $now]);

                $detailRows[] = [
                    'paymentDetailID' => $pdID,
                    'assessmentID'    => $vLine['assessmentID'],
                    'feeCode'         => $vLine['feeCode'],
                    'feeDescription'  => $vLine['feeDescription'],
                    'allocatedAmount' => $amtStr,
                ];
            }

            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Unable to process payment: ' . $e->getMessage() . ($useClientOR ? ' (Local OR ' . $orNumber . ' was pre-assigned client-side — notify admin if this number must be voided.)' : '')]);
            return;
        }

        $paymentDateDisplay = trim(implode(' ', array_filter([
            $paymentDate['monthName'], (string)$paymentDate['day'], (string)$paymentDate['year'],
        ], fn($p) => $p !== '')));

        $change = $amountTendered - $totalAllocated;
        $createdByDisplayName = $this->_resolveUserDisplayName($db, $createdBy);
        // Falls back to plain "Cash" labels when the insert fell back to the
        // legacy schema (paymentMethodCode/settlementAccountCode are null then).
        $paymentMethodLabelOut = $paymentMethodCode !== null
            ? ($methodAccounts[$paymentMethodCode]['label'] ?? $paymentMethodCode)
            : 'Cash';
        $settlementAccountNameOut = $paymentMethodCode !== null
            ? ($methodAccounts[$paymentMethodCode]['settlementAccountName'] ?? '')
            : '';

        // Complete response object with all fields used by cashier.html's
        // payment success handler, receipt renderer, and DOM update.
        echo json_encode([
            'ok'                   => true,
            'paymentID'            => $paymentID,
            'ORNumber'             => $orNumber,
            'studentNumber'        => $studentNumber,
            'studentName'          => $referenceData->buildStudentFullName($student),
            'registrationNumber'   => $registrationNumber,
            'academicYear'         => $term['academicYear'],
            'semester'             => $term['semester'],
            'amountTendered'       => number_format($amountTendered, 2, '.', ''),
            'totalAllocated'       => $amountAllocatedStr,
            'change'               => number_format($change < 0 ? 0 : $change, 2, '.', ''),
            'paymentDate'          => $paymentDateDisplay,
            'createdBy'            => $createdBy,
            'createdByDisplayName' => $createdByDisplayName,
            'paymentMethod'        => $paymentMethodCode,
            'paymentMethodLabel'   => $paymentMethodLabelOut,
            'settlementAccount'    => $settlementAccountCode,
            'settlementAccountName'=> $settlementAccountNameOut,
            'paymentReference'     => $paymentReferenceToStore,
            'lines'                => $detailRows,
            'message'              => 'Payment recorded. OR: ' . $orNumber,
        ]);
    }

    // Parses a payment date string. Accepts "YYYY-MM-DD"; falls back to today if blank.
    // Returns month number, month name, day, and year as separate fields.
    private function _parseCashierPaymentDate(string $raw): array
    {
        if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $date = \DateTime::createFromFormat('Y-m-d', $raw);
        } elseif ($raw !== '') {
            $date = new \DateTime($raw);
        } else {
            $date = new \DateTime();
        }
        if (!$date) throw new \Exception('Payment date is invalid.');

        $monthNames = ['January','February','March','April','May','June',
                       'July','August','September','October','November','December'];
        $monthIndex = (int)$date->format('n') - 1;

        return [
            'monthNumber' => $monthIndex + 1,
            'monthName'   => $monthNames[$monthIndex],
            'day'         => (int)$date->format('j'),
            'year'        => (int)$date->format('Y'),
        ];
    }

    // Fallback OR number generator used when no valid localORNumber is supplied.
    // Scans tblPayments to find the current numeric maximum and increments it.
    // NOTE: the NULL-ORNumber row from legacy data is safely handled —
    // preg_replace on null coerces to empty string and is skipped.
    private function _generateNextORNumber($db): string
    {
        $stmt = $db->query("SELECT ORNumber FROM tblPayments");
        $max = 0;
        foreach ($stmt->fetchAll() as $row) {
            $digits = preg_replace('/[^0-9]/', '', (string)($row['ORNumber'] ?? ''));
            if ($digits === '') continue;
            $num = (int)$digits;
            if ($num > $max) $max = $num;
        }
        return 'OR' . str_pad((string)($max + 1), 10, '0', STR_PAD_LEFT);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Payment history and OR lookup/reprint
    //
    // _resolveUserDisplayName() is defined in this controller and reused
    // ─────────────────────────────────────────────────────────────────────

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/cashier/payment-history?studentNumber=<sn>
    //
    // Returns payment records for the student's active-term registration,
    // ordered by OR number descending. "No active-term registration" is
    // an ok:true response with an empty records array.
    // ─────────────────────────────────────────────────────────────────────
    public function paymentHistory()
    {
        $studentNumber = trim($_GET['studentNumber'] ?? '');
        if ($studentNumber === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Student number is required.']);
            return;
        }

        $db = Database::getConnection();
        $referenceData = new \App\Services\ReferenceDataService();

        try {
            $term = $referenceData->getActiveTerm();
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            return;
        }

        $regStmt = $db->prepare("
            SELECT RegistrationNumber FROM tblRegistrations
            WHERE studentNumber = ? AND academicYear = ? AND semester = ?
            ORDER BY dateCreated DESC LIMIT 1
        ");
        $regStmt->execute([$studentNumber, $term['academicYear'], $term['semester']]);
        $reg = $regStmt->fetch();

        if (!$reg) {
            echo json_encode([
                'ok' => true, 'records' => [], 'count' => 0,
                'message' => 'No active-term registration found for student ' . $studentNumber . '.',
            ]);
            return;
        }

        $registrationNumber = (string)$reg['RegistrationNumber'];

        // COALESCE: falls back to AmountPaid only when no detail rows exist for
        // that OR (subquery returns NULL), not when the sum is genuinely zero.
        $payStmt = $db->prepare("
            SELECT p.*, COALESCE((SELECT SUM(Amount) FROM tblPaymentDetails WHERE ORNumber = p.ORNumber), p.AmountPaid) AS totalPaid
            FROM tblPayments p
            WHERE p.registrationNumber = ?
            ORDER BY p.ORNumber DESC
        ");
        $payStmt->execute([$registrationNumber]);

        $methodLabels = $referenceData->getPaymentMethodLabels();

        $records = [];
        foreach ($payStmt->fetchAll() as $row) {
            $methodCode = $referenceData->resolvePaymentMethodCode($row['paymentMethod'] ?? null);
            $records[] = [
                'ORNumber'           => (string)($row['ORNumber'] ?? ''),
                'paymentDate'        => $this->_buildPaymentDateDisplay($row),
                'totalPaid'          => number_format((float)($row['totalPaid'] ?? 0), 2, '.', ''),
                'paymentMethod'      => $methodCode,
                'paymentMethodLabel' => $methodLabels[$methodCode] ?? $methodCode,
                'paymentReference'   => (string)($row['paymentReference'] ?? ''),
            ];
        }

        echo json_encode([
            'ok' => true, 'records' => $records, 'count' => count($records),
            'message' => count($records) . ' payment record(s) found for student ' . $studentNumber . '.',
        ]);
    }

    // Builds a payment date display string from stored PaymentMonth, PaymentDay,
    // PaymentYear columns. Falls back to dateCreated when all three are blank.
    private function _buildPaymentDateDisplay(array $payment): string
    {
        $month = trim((string)($payment['PaymentMonth'] ?? ''));
        $day   = trim((string)($payment['PaymentDay']   ?? ''));
        $year  = trim((string)($payment['PaymentYear']  ?? ''));
        if ($month !== '' || $day !== '' || $year !== '') {
            return trim(implode(' ', array_filter([$month, $day, $year], fn($p) => $p !== '')));
        }
        return trim((string)($payment['dateCreated'] ?? ''));
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/cashier/receipt?orNumber=<OR...>
    //
    // Returns the full receipt detail for a given OR number.\n    // OR not found -> 404. OR found but registration missing/out-of-term\n    // -> 200 ok:false with code OUT_OF_TERM (not a 404).
    // ─────────────────────────────────────────────────────────────────────
    public function receipt()
    {
        $orNumber = trim($_GET['orNumber'] ?? $_GET['ORNumber'] ?? '');
        if ($orNumber === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'OR number is required.']);
            return;
        }

        $db = Database::getConnection();
        $referenceData = new \App\Services\ReferenceDataService();

        try {
            $term = $referenceData->getActiveTerm();
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            return;
        }

        $payStmt = $db->prepare("SELECT * FROM tblPayments WHERE ORNumber = ?");
        $payStmt->execute([$orNumber]);
        $payment = $payStmt->fetch();
        if (!$payment) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'OR number was not found: ' . $orNumber]);
            return;
        }

        $regStmt = $db->prepare("SELECT * FROM tblRegistrations WHERE RegistrationNumber = ?");
        $regStmt->execute([(string)($payment['registrationNumber'] ?? '')]);
        $reg = $regStmt->fetch();

        if (!$reg || (string)($reg['academicYear'] ?? '') !== $term['academicYear'] || (string)($reg['semester'] ?? '') !== $term['semester']) {
            // Structured non-error — HTTP 200. See plan §0.-1.G.
            echo json_encode([
                'ok' => false, 'code' => 'OUT_OF_TERM', 'ORNumber' => $orNumber,
                'message' => 'This OR belongs to a different academic term and cannot be retrieved.',
            ]);
            return;
        }

        $studentNumber = (string)($reg['studentNumber'] ?? '');
        $sStmt = $db->prepare("SELECT * FROM tblStudents WHERE studentNumber = ?");
        $sStmt->execute([$studentNumber]);
        $student = $sStmt->fetch() ?: [];

        $dStmt = $db->prepare("
            SELECT pd.*, a.feeID, f.feeCode, f.feeDescription
            FROM tblPaymentDetails pd
            LEFT JOIN tblAssessments a ON a.assessmentID = pd.AssessmentID
            LEFT JOIN tblFees f ON f.feeID = a.feeID
            WHERE pd.ORNumber = ?
        ");
        $dStmt->execute([$orNumber]);

        $lines = [];
        $totalAllocated = 0.0;
        foreach ($dStmt->fetchAll() as $row) {
            $amt = (float)($row['Amount'] ?? 0);
            $totalAllocated += $amt;
            $lines[] = [
                'paymentDetailID' => (string)($row['paymentDetailID'] ?? ''),
                'assessmentID'    => (string)($row['AssessmentID'] ?? ''),
                'feeCode'         => (string)($row['feeCode'] ?? ''),
                'feeDescription'  => (string)($row['feeDescription'] ?? ''),
                'allocatedAmount' => number_format($amt, 2, '.', ''),
            ];
        }

        $amountPaid = (float)($payment['AmountPaid'] ?? 0);
        $change = $amountPaid - $totalAllocated;
        $rawCreatedBy = (string)($payment['createdBy'] ?? '');

        // Blank/NULL paymentMethod is treated as CASH on every read path (standing rule).
        $methodCode = $referenceData->resolvePaymentMethodCode($payment['paymentMethod'] ?? null);
        $methodLabels = $referenceData->getPaymentMethodLabels();
        $settlementAccountNames = $referenceData->getSettlementAccountNames();
        $settlementAccountCode = (string)($payment['settlementAccount'] ?? '');

        echo json_encode([
            'ok'                    => true,
            'paymentID'             => (string)($payment['paymentID'] ?? ''),
            'ORNumber'              => $orNumber,
            'registrationNumber'    => (string)($payment['registrationNumber'] ?? ''),
            'studentNumber'         => $studentNumber,
            'studentName'           => $referenceData->buildStudentFullName($student),
            'academicYear'          => $term['academicYear'],
            'semester'              => $term['semester'],
            'amountTendered'        => number_format($amountPaid, 2, '.', ''),
            'totalAllocated'        => number_format($totalAllocated, 2, '.', ''),
            'change'                => number_format($change < 0 ? 0 : $change, 2, '.', ''),
            'paymentDate'           => $this->_buildPaymentDateDisplay($payment),
            'createdBy'             => $rawCreatedBy,
            'createdByDisplayName'  => $this->_resolveUserDisplayName($db, $rawCreatedBy),
            'paymentMethod'         => $methodCode,
            'paymentMethodLabel'    => $methodLabels[$methodCode] ?? $methodCode,
            'settlementAccount'     => $settlementAccountCode,
            'settlementAccountName' => $settlementAccountNames[$settlementAccountCode] ?? '',
            'paymentReference'      => (string)($payment['paymentReference'] ?? ''),
            'lines'                 => $lines,
            'message'               => 'Payment history retrieved for OR: ' . $orNumber,
        ]);
    }
}
