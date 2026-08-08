<?php

namespace App\Controllers;

use App\Core\Database;

// ─────────────────────────────────────────────────────────────────────────
// AccountsController
//
// Reference/lookup data (fees, fee templates, sections, programs, student
// typeahead) is served by aliasing routes in api.php directly to
// EnrollmentController's existing methods — those tables are shared with
// no module-specific logic in ReferenceDataService.
//
// This controller only holds logic that is genuinely Accounts-specific:
//   - filters(): returns all distinct AY/semester combinations, not just
//     the active term, since Accounts can view any term.
//   - search(): full-detail search including registrations, assessments,
//     and payment history.
//   - createAssessment(), createReceipt(), createReceiptDetail(): direct
//     write endpoints for Accounts transactions.
//
// EnrollmentController::mirror() handles write operations dispatched from
// the mirror route — no separate AccountsController::mirror() is needed.
// Student name formatting is provided by ReferenceDataService::buildStudentFullName().
// ─────────────────────────────────────────────────────────────────────────
class AccountsController
{
    // ─────────────────────────────────────────────────────────────────────
    // GET /api/accounts/search
    //   ?q=<search text>          (required)
    //   &ay=<academicYear>        (optional — blank means ALL years)
    //   &sem=<semester>           (optional — blank means ALL semesters)
    //
    // Searches tblStudents by studentNumber, studentID, lastName, firstName,
    // middleName, or full-name concatenations (case-insensitive substring).
    // For each matched student, returns all registrations with assessment and
    // payment detail. Results can be filtered to a specific term or all terms.
    // ─────────────────────────────────────────────────────────────────────
    public function search()
    {
        $q   = trim($_GET['q']   ?? '');
        $ay  = trim($_GET['ay']  ?? '');
        $sem = trim($_GET['sem'] ?? '');

        if ($q === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Search text is required.']);
            return;
        }

        $db = Database::getConnection();
        $referenceData = new \App\Services\ReferenceDataService();

        $like = '%' . $q . '%';
        $stmt = $db->prepare("
            SELECT * FROM tblStudents
            WHERE studentNumber LIKE :q1
               OR studentID      LIKE :q2
               OR lastName       LIKE :q3
               OR firstName      LIKE :q4
               OR middleName     LIKE :q5
               OR CONCAT(lastName, ', ', firstName) LIKE :q6
               OR CONCAT(firstName, ' ', middleName, ' ', lastName) LIKE :q7
            ORDER BY lastName, firstName
            LIMIT 50
        ");
        $stmt->execute([
            ':q1' => $like, ':q2' => $like, ':q3' => $like,
            ':q4' => $like, ':q5' => $like, ':q6' => $like, ':q7' => $like,
        ]);
        $studentRows = $stmt->fetchAll();

        $students = [];
        $totalRegistrations = 0;

        foreach ($studentRows as $student) {
            $studentNumber = (string)($student['studentNumber'] ?? '');

            $sql = "SELECT * FROM tblRegistrations WHERE studentNumber = ?";
            $params = [$studentNumber];
            if ($ay !== '')  { $sql .= " AND academicYear = ?"; $params[] = $ay; }
            if ($sem !== '') { $sql .= " AND semester = ?";     $params[] = $sem; }
            // Sort by academic term chronology (academicYear, then semester),
            // NOT by dateCreated — dateCreated is when the row was inserted
            // into the DB, which does not track term order (backfilled/
            // corrected registrations can be created out of sequence).
            // "1ST SEMESTER" sorts before "2ND SEMESTER" alphabetically,
            // matching the standard two-term convention; dateCreated is kept
            // only as a tiebreaker for the rare case of two registrations
            // sharing the same academicYear + semester.
            $sql .= " ORDER BY academicYear ASC, semester ASC, dateCreated ASC";

            $regStmt = $db->prepare($sql);
            $regStmt->execute($params);
            $regRows = $regStmt->fetchAll();

            if (empty($regRows)) continue; // match GAS: students with zero matching registrations are dropped entirely

            $groupedRegistrations = [];
            foreach ($regRows as $reg) {
                $groupedRegistrations[] = $this->_buildAccountRegistrationSummary($db, $reg);
                $totalRegistrations++;
            }

            $students[] = [
                'studentNumber' => $studentNumber,
                'studentID'     => (string)($student['studentID'] ?? ''),
                // Shared name builder — see ReferenceDataService::buildStudentFullName().
                'fullName'      => $referenceData->buildStudentFullName($student),
                'emailAddress'  => (string)($student['emailAddress'] ?? ''),
                'contactNumber' => (string)($student['contactNumber'] ?? ''),
                'registrations' => $groupedRegistrations,
            ];
        }

        echo json_encode([
            'ok'                 => true,
            'totalStudents'      => count($students),
            'totalRegistrations' => $totalRegistrations,
            'students'           => $students,
        ]);
    }

    // Builds the registration summary object for a single registration:
    // program/section labels, assessment lines, payment receipts, payment
    // allocations, and mismatch warnings.
    // Inactive assessment lines are still returned but excluded from totals.
    // The per-assessment paid field is named "paid" (not "paidAmount").
    private function _buildAccountRegistrationSummary($db, array $reg): array
    {
        $registrationNumber = (string)($reg['RegistrationNumber'] ?? '');

        $pStmt = $db->prepare("SELECT programCode, programDescription FROM tblPrograms WHERE programID = ?");
        $pStmt->execute([(string)($reg['programID'] ?? '')]);
        $program = $pStmt->fetch() ?: [];

        $sStmt = $db->prepare("SELECT sectionName FROM tblSections WHERE sectionID = ?");
        $sStmt->execute([(string)($reg['sectionID'] ?? '')]);
        $section = $sStmt->fetch() ?: [];

        $aStmt = $db->prepare("
            SELECT a.*, f.feeCode, f.feeDescription
            FROM tblAssessments a
            LEFT JOIN tblFees f ON f.feeID = a.feeID
            WHERE a.registrationNumber = ?
        ");
        $aStmt->execute([$registrationNumber]);
        $assessmentRows = $aStmt->fetchAll();

        $assessmentLines    = [];
        $paymentAllocations = [];
        $totalAssessed      = 0.0;
        $totalAllocatedPaid = 0.0;
        $warnings           = [];

        foreach ($assessmentRows as $assessment) {
            $amount   = (float)($assessment['amount'] ?? 0);
            $cash     = (float)($assessment['cash']   ?? 0);
            $isActive = ((int)($assessment['isActive'] ?? 1)) === 1;

            $dStmt = $db->prepare("SELECT * FROM tblPaymentDetails WHERE AssessmentID = ?");
            $dStmt->execute([(string)($assessment['assessmentID'] ?? '')]);
            $details = $dStmt->fetchAll();

            $paidForAssessment = 0.0;
            foreach ($details as $detail) {
                $detailAmount = (float)($detail['Amount'] ?? 0);
                $paidForAssessment += $detailAmount;
                $paymentAllocations[] = [
                    'paymentDetailID' => (string)($detail['paymentDetailID'] ?? ''),
                    'ORNumber'        => (string)($detail['ORNumber'] ?? ''),
                    'assessmentID'    => (string)($assessment['assessmentID'] ?? ''),
                    'feeCode'         => (string)($assessment['feeCode'] ?? ''),
                    'feeDescription'  => (string)($assessment['feeDescription'] ?? ''),
                    'amount'          => number_format($detailAmount, 2, '.', ''),
                ];
            }

            // Accounts can display a registration from any term.
            // Inactive lines are still returned for visibility but excluded from totals.
            if ($isActive) {
                $totalAssessed      += $amount;
                $totalAllocatedPaid += $paidForAssessment;
            }

            $assessmentLines[] = [
                'assessmentID'   => (string)($assessment['assessmentID'] ?? ''),
                'feeID'          => (string)($assessment['feeID'] ?? ''),
                'feeCode'        => (string)($assessment['feeCode'] ?? ''),
                'feeDescription' => (string)($assessment['feeDescription'] ?? ''),
                'amount'         => number_format($amount, 2, '.', ''),
                'cash'           => number_format($cash, 2, '.', ''),
                'note'           => (string)($assessment['note'] ?? ''),
                'paid'           => number_format($paidForAssessment, 2, '.', ''),
                'balance'        => number_format($amount - $paidForAssessment, 2, '.', ''),
                'isActive'       => $isActive,
            ];
        }

        $payStmt = $db->prepare("SELECT * FROM tblPayments WHERE registrationNumber = ?");
        $payStmt->execute([$registrationNumber]);
        $paymentRows = $payStmt->fetchAll();

        $paymentReceipts  = [];
        $listedReceiptOrs = [];
        $totalReceiptPaid = 0.0;

        foreach ($paymentRows as $payment) {
            $orNumber = (string)($payment['ORNumber'] ?? '');
            if ($orNumber !== '') $listedReceiptOrs[strtolower($orNumber)] = true;

            $odStmt = $db->prepare("SELECT Amount FROM tblPaymentDetails WHERE ORNumber = ?");
            $odStmt->execute([$orNumber]);
            $allocatedForReceipt = 0.0;
            foreach ($odStmt->fetchAll() as $od) $allocatedForReceipt += (float)($od['Amount'] ?? 0);

            $receiptPaid = (float)($payment['AmountPaid'] ?? 0);
            $totalReceiptPaid += $receiptPaid;
            if (abs($receiptPaid - $allocatedForReceipt) > 0.009) {
                $warnings[] = 'OR ' . $orNumber . ' AmountPaid does not match payment detail allocations.';
            }

            $allocationsForOr = array_values(array_filter(
                $paymentAllocations,
                fn($a) => strtolower($a['ORNumber']) === strtolower($orNumber)
            ));

            $paymentReceipts[] = [
                'paymentID'       => (string)($payment['paymentID'] ?? ''),
                'ORNumber'        => $orNumber,
                'amountPaid'      => number_format($receiptPaid, 2, '.', ''),
                'allocatedAmount' => number_format($allocatedForReceipt, 2, '.', ''),
                'paymentDate'     => $this->_buildPaymentDateDisplay($payment),
                'allocations'     => $allocationsForOr,
            ];
        }

        $unlisted = array_values(array_filter($paymentAllocations, function ($a) use ($listedReceiptOrs) {
            $key = strtolower((string)$a['ORNumber']);
            return $key === '' || !isset($listedReceiptOrs[$key]);
        }));
        if (count($unlisted) > 0) {
            $warnings[] = count($unlisted) . ' payment detail allocation(s) contribute to Allocated Paid but do not belong to the listed payment receipts.';
        }
        if (abs($totalReceiptPaid - $totalAllocatedPaid) > 0.009) {
            $warnings[] = 'Total receipt payments do not match allocated payment details for this registration.';
        }

        return [
            'registrationNumber'     => $registrationNumber,
            'academicYear'           => (string)($reg['academicYear'] ?? ''),
            'semester'               => (string)($reg['semester'] ?? ''),
            'yearLevel'              => (string)($reg['yearLevel'] ?? ''),
            'programCode'            => (string)($program['programCode'] ?? ''),
            'programDescription'     => (string)($program['programDescription'] ?? ''),
            'sectionName'            => (string)($section['sectionName'] ?? ''),
            'totalAssessed'          => number_format($totalAssessed, 2, '.', ''),
            'totalPaid'              => number_format($totalAllocatedPaid, 2, '.', ''),
            'totalReceiptPaid'       => number_format($totalReceiptPaid, 2, '.', ''),
            'balance'                => number_format($totalAssessed - $totalAllocatedPaid, 2, '.', ''),
            'hasPaymentMismatch'     => abs($totalReceiptPaid - $totalAllocatedPaid) > 0.009,
            'warnings'               => $warnings,
            'assessments'            => $assessmentLines,
            'paymentAllocations'     => $paymentAllocations,
            'unlistedPaymentDetails' => $unlisted,
            'payments'               => $paymentReceipts,
        ];
    }

    // Builds the payment date display string from stored month/day/year
    // columns. Falls back to dateCreated only when all three are blank.
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
    // POST /api/accounts/assessments
    // Body: { registrationNumber, feeID, amount, note, createdBy }
    //
    // Creates a new assessment line on an existing registration.
    // No duplicate-active-fee guard — the same fee may appear multiple times.
    // Response "patch" shape is used by accounts.html's applyAccountActionPatch().
    // ─────────────────────────────────────────────────────────────────────
    public function createAssessment()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $registrationNumber = trim((string)($input['registrationNumber'] ?? ''));
        $feeID               = trim((string)($input['feeID'] ?? ''));
        $amount              = (float)($input['amount'] ?? 0);
        $note                = trim((string)($input['note'] ?? ''));
        $createdBy           = trim((string)($input['createdBy'] ?? ''));

        if ($registrationNumber === '') { http_response_code(400); echo json_encode(['error' => 'Registration number is required.']); return; }
        if ($feeID === '')              { http_response_code(400); echo json_encode(['error' => 'Fee is required.']); return; }
        if ($amount <= 0)               { http_response_code(422); echo json_encode(['error' => 'Assessment amount must be greater than zero.']); return; }

        $db = Database::getConnection();

        $regStmt = $db->prepare("SELECT RegistrationNumber FROM tblRegistrations WHERE RegistrationNumber = ?");
        $regStmt->execute([$registrationNumber]);
        if (!$regStmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Registration number was not found: ' . $registrationNumber]); return; }

        $feeStmt = $db->prepare("SELECT feeCode, feeDescription FROM tblFees WHERE feeID = ?");
        $feeStmt->execute([$feeID]);
        $fee = $feeStmt->fetch();
        if (!$fee) { http_response_code(404); echo json_encode(['error' => 'Fee was not found: ' . $feeID]); return; }

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
            echo json_encode(['error' => 'Unable to create assessment: ' . $e->getMessage()]);
            return;
        }

        echo json_encode([
            'ok'           => true,
            'assessmentID' => $assessmentID,
            'message'      => 'Assessment line added' . ($createdBy !== '' ? ' by ' . $createdBy : '') . '.',
            'patch' => [
                'type'               => 'assessment',
                'registrationNumber' => $registrationNumber,
                'newLine' => [
                    'assessmentID'   => $assessmentID,
                    'feeID'          => $feeID,
                    'feeCode'        => (string)($fee['feeCode'] ?? ''),
                    'feeDescription' => (string)($fee['feeDescription'] ?? ''),
                    'amount'         => $amountStr,
                    'cash'           => $amountStr,
                    'note'           => $note,
                    'paid'           => '0.00',
                    'balance'        => $amountStr,
                    'isActive'       => true,
                ],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/accounts/receipts
    // Body: { registrationNumber, ORNumber, amountPaid, paymentDate, createdBy }
    //   paymentDate: "YYYY-MM-DD" (matches accounts.html's <input type="date">)
    //
    // Creates a payment receipt for an existing registration.
    // OR number format: OR followed by exactly 10 digits. Duplicate OR numbers
    // are rejected with 409.
    // ─────────────────────────────────────────────────────────────────────
    public function createReceipt()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $registrationNumber = trim((string)($input['registrationNumber'] ?? ''));
        $orNumber            = trim((string)($input['ORNumber'] ?? $input['orNumber'] ?? ''));
        $amountPaid          = (float)($input['amountPaid'] ?? 0);
        $paymentDateRaw      = trim((string)($input['paymentDate'] ?? ''));
        $createdBy           = trim((string)($input['createdBy'] ?? ''));

        if ($registrationNumber === '')          { http_response_code(400); echo json_encode(['error' => 'Registration number is required.']); return; }
        if ($orNumber === '')                    { http_response_code(400); echo json_encode(['error' => 'OR number is required.']); return; }
        if (!preg_match('/^OR\d{10}$/', $orNumber)) { http_response_code(422); echo json_encode(['error' => 'OR number must use the format OR followed by 10 digits.']); return; }
        if ($amountPaid <= 0)                    { http_response_code(422); echo json_encode(['error' => 'Receipt amount must be greater than zero.']); return; }

        $db = Database::getConnection();

        $regStmt = $db->prepare("SELECT RegistrationNumber FROM tblRegistrations WHERE RegistrationNumber = ?");
        $regStmt->execute([$registrationNumber]);
        if (!$regStmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Registration number was not found: ' . $registrationNumber]); return; }

        $dupStmt = $db->prepare("SELECT paymentID FROM tblPayments WHERE ORNumber = ?");
        $dupStmt->execute([$orNumber]);
        if ($dupStmt->fetch()) { http_response_code(409); echo json_encode(['error' => 'OR number already exists: ' . $orNumber]); return; }

        try {
            $paymentDate = $this->_parsePaymentDate($paymentDateRaw);
        } catch (\Exception $e) {
            http_response_code(422);
            echo json_encode(['error' => $e->getMessage()]);
            return;
        }

        $amountStr = number_format($amountPaid, 2, '.', '');
        $now = date('Y-m-d H:i:s');

        try {
            $db->beginTransaction();
            $seq = \App\Models\SequenceGenerator::reserveIdBlock($db, 'tblPayments', 1);
            $paymentID = \App\Models\SequenceGenerator::formatId('PAY', $seq['firstNo'], 8);

            $stmt = $db->prepare("
                INSERT INTO tblPayments
                    (paymentID, ORNumber, registrationNumber, AmountPaid,
                     PaymentMonthNumber, PaymentMonth, PaymentDay, PaymentYear,
                     dateCreated, createdBy)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $paymentID, $orNumber, $registrationNumber, $amountStr,
                $paymentDate['monthNumber'], $paymentDate['monthName'],
                $paymentDate['day'], $paymentDate['year'],
                $now, $createdBy,
            ]);
            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Unable to create receipt: ' . $e->getMessage()]);
            return;
        }

        $paymentDateDisplay = trim(implode(' ', array_filter([
            $paymentDate['monthName'], (string)$paymentDate['day'], (string)$paymentDate['year'],
        ], fn($p) => $p !== '')));

        echo json_encode([
            'ok'        => true,
            'paymentID' => $paymentID,
            'ORNumber'  => $orNumber,
            'message'   => 'Payment receipt added.',
            'patch' => [
                'type'               => 'receipt',
                'registrationNumber' => $registrationNumber,
                'newReceipt' => [
                    'paymentID'       => $paymentID,
                    'ORNumber'        => $orNumber,
                    'amountPaid'      => $amountStr,
                    'allocatedAmount' => '0.00',
                    'paymentDate'     => $paymentDateDisplay,
                    'allocations'     => [],
                ],
            ],
        ]);
    }

    // Parses a payment date string. Accepts "YYYY-MM-DD"; falls back to today if blank.
    // Returns month number, month name, day, and year as separate fields.
    private function _parsePaymentDate(string $raw): array
    {
        if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $date = \DateTime::createFromFormat('Y-m-d', $raw);
        } elseif ($raw !== '') {
            $date = new \DateTime($raw);
        } else {
            $date = new \DateTime();
        }
        if (!$date) { throw new \Exception('Payment date is invalid.'); }

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

    // ─────────────────────────────────────────────────────────────────────
    // POST /api/accounts/receipt-details
    // Body: { registrationNumber, ORNumber, assessmentID, amount, createdBy }
    //
    // Allocates a portion of a receipt toward a specific assessment line.
    // Three cross-checks enforced:
    //   1. OR number and assessment must belong to the same registration.
    //   2. Allocation must not exceed the receipt's remaining unallocated amount.
    //   3. Allocation must not exceed the assessment's remaining balance.
    // ─────────────────────────────────────────────────────────────────────
    public function createReceiptDetail()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $registrationNumber = trim((string)($input['registrationNumber'] ?? ''));
        $orNumber            = trim((string)($input['ORNumber'] ?? $input['orNumber'] ?? ''));
        $assessmentID        = trim((string)($input['assessmentID'] ?? $input['AssessmentID'] ?? ''));
        $amount              = (float)($input['amount'] ?? 0);
        $createdBy           = trim((string)($input['createdBy'] ?? ''));

        if ($orNumber === '')     { http_response_code(400); echo json_encode(['error' => 'OR number is required.']); return; }
        if ($assessmentID === '') { http_response_code(400); echo json_encode(['error' => 'Assessment line is required.']); return; }
        if ($amount <= 0)         { http_response_code(422); echo json_encode(['error' => 'Receipt detail amount must be greater than zero.']); return; }

        $db = Database::getConnection();

        $payStmt = $db->prepare("SELECT * FROM tblPayments WHERE ORNumber = ?");
        $payStmt->execute([$orNumber]);
        $payment = $payStmt->fetch();
        if (!$payment) { http_response_code(404); echo json_encode(['error' => 'OR number was not found: ' . $orNumber]); return; }

        $asmStmt = $db->prepare("SELECT * FROM tblAssessments WHERE assessmentID = ?");
        $asmStmt->execute([$assessmentID]);
        $assessment = $asmStmt->fetch();
        if (!$assessment) { http_response_code(404); echo json_encode(['error' => 'Assessment ID was not found: ' . $assessmentID]); return; }

        $paymentReg    = (string)($payment['registrationNumber'] ?? '');
        $assessmentReg = (string)($assessment['registrationNumber'] ?? '');

        if ($registrationNumber !== '' && strcasecmp($paymentReg, $registrationNumber) !== 0) {
            http_response_code(422); echo json_encode(['error' => 'The selected OR number does not belong to this registration.']); return;
        }
        if ($registrationNumber !== '' && strcasecmp($assessmentReg, $registrationNumber) !== 0) {
            http_response_code(422); echo json_encode(['error' => 'The selected assessment line does not belong to this registration.']); return;
        }
        if (strcasecmp($paymentReg, $assessmentReg) !== 0) {
            http_response_code(422); echo json_encode(['error' => 'The selected OR number and assessment line belong to different registrations.']); return;
        }

        $allocStmt = $db->prepare("SELECT COALESCE(SUM(Amount),0) AS total FROM tblPaymentDetails WHERE ORNumber = ?");
        $allocStmt->execute([$orNumber]);
        $alreadyAllocatedForReceipt = (float)($allocStmt->fetch()['total'] ?? 0);

        $paidStmt = $db->prepare("SELECT COALESCE(SUM(Amount),0) AS total FROM tblPaymentDetails WHERE AssessmentID = ?");
        $paidStmt->execute([$assessmentID]);
        $alreadyPaidForAssessment = (float)($paidStmt->fetch()['total'] ?? 0);

        $receiptRemaining    = (float)($payment['AmountPaid'] ?? 0) - $alreadyAllocatedForReceipt;
        $assessmentRemaining = (float)($assessment['amount']  ?? 0) - $alreadyPaidForAssessment;

        if ($amount - $receiptRemaining > 0.009) {
            http_response_code(422);
            echo json_encode(['error' => 'Allocation exceeds receipt remaining amount. Remaining receipt amount: ' . number_format($receiptRemaining, 2, '.', '') . '.']);
            return;
        }
        if ($amount - $assessmentRemaining > 0.009) {
            http_response_code(422);
            echo json_encode(['error' => 'Allocation exceeds selected assessment balance. Assessment balance: ' . number_format($assessmentRemaining, 2, '.', '') . '.']);
            return;
        }

        $feeStmt = $db->prepare("SELECT feeCode, feeDescription FROM tblFees WHERE feeID = ?");
        $feeStmt->execute([(string)($assessment['feeID'] ?? '')]);
        $fee = $feeStmt->fetch() ?: [];

        $amountStr = number_format($amount, 2, '.', '');
        $now = date('Y-m-d H:i:s');

        try {
            $db->beginTransaction();
            $seq = \App\Models\SequenceGenerator::reserveIdBlock($db, 'tblPaymentDetails', 1);
            $paymentDetailID = \App\Models\SequenceGenerator::formatId('PD', $seq['firstNo'], 8);

            $stmt = $db->prepare("
                INSERT INTO tblPaymentDetails (paymentDetailID, ORNumber, AssessmentID, Amount, createdBy, dateCreated)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$paymentDetailID, $orNumber, $assessmentID, $amountStr, $createdBy, $now]);
            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Unable to create receipt detail: ' . $e->getMessage()]);
            return;
        }

        echo json_encode([
            'ok'              => true,
            'paymentDetailID' => $paymentDetailID,
            'message'         => 'Receipt detail added.',
            'patch' => [
                'type'               => 'detail',
                'registrationNumber' => $assessmentReg,
                'orNumber'           => $orNumber,
                'assessmentID'       => $assessmentID,
                'amount'             => $amountStr,
                'newAllocation' => [
                    'paymentDetailID' => $paymentDetailID,
                    'ORNumber'        => $orNumber,
                    'assessmentID'    => $assessmentID,
                    'feeCode'         => (string)($fee['feeCode'] ?? ''),
                    'feeDescription'  => (string)($fee['feeDescription'] ?? ''),
                    'amount'          => $amountStr,
                ],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/accounts/filters
    //
    // Returns every distinct academicYear/semester combination from tblRegistrations,
    // for populating the AY/Semester dropdowns on the Accounts module.
    // Not restricted to the active term — Accounts can view any term.
    // ─────────────────────────────────────────────────────────────────────
    public function filters()
    {
        $db = Database::getConnection();

        $rows = $db->query("
            SELECT DISTINCT academicYear, semester
            FROM tblRegistrations
            WHERE academicYear IS NOT NULL AND academicYear <> ''
        ")->fetchAll();

        $years = [];
        $sems  = [];
        foreach ($rows as $row) {
            $ay  = (string)($row['academicYear'] ?? '');
            $sem = (string)($row['semester'] ?? '');
            if ($ay !== '')  { $years[$ay]  = true; }
            if ($sem !== '') { $sems[$sem] = true; }
        }

        $years = array_keys($years);
        $sems  = array_keys($sems);
        sort($years);
        sort($sems);

        echo json_encode([
            'ok'            => true,
            'academicYears' => $years,
            'semesters'     => $sems,
        ]);
    }
}
