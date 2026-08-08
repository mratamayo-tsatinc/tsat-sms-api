<?php

namespace App\Controllers;

use App\Core\Database;
use App\Services\ReferenceDataService;

class EnrollmentController
{
    // Operations this endpoint knows how to dispatch. Anything else is
    // rejected up front with a 400 before any DB work happens.
    //
    // receipt_create and receipt_detail_create are included so this same
    // dispatcher can handle Accounts and Cashier write mirrors without
    // forking into separate controller methods.
    private const KNOWN_MIRROR_OPERATIONS = [
        'registration_create',
        'registration_update',
        'assessment_create',
        'assessment_update',
        'assessment_deactivate',
        'assessment_batch_commit',
        'receipt_create',
        'receipt_detail_create',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/enrollment/active-term
    // Returns the active academicYear and semester from tblAppSettings.
    // Also aliased as the active-term endpoint for Accounts, Cashier, ID, and LMS.
    // ─────────────────────────────────────────────────────────────────────────
    public function activeTerm()
    {
        try {
            $term = (new ReferenceDataService())->getActiveTerm();
            echo json_encode(['ok' => true, 'academicYear' => $term['academicYear'], 'semester' => $term['semester']]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    public function bootstrap()
    {
        $db = Database::getConnection();

        $registrations = $db->query("SELECT * FROM tblRegistrations")->fetchAll();
        $sections      = $db->query("SELECT * FROM tblSections")->fetchAll();
        $programs      = $db->query("SELECT * FROM tblPrograms")->fetchAll();

        echo json_encode([
            'registrations' => $registrations,
            'sections'      => $sections,
            'programs'      => $programs,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/enrollment/mirror  (also aliased as POST /api/accounts/mirror
    //                               and POST /api/cashier/mirror)
    //
    // Dispatches to one of:
    //   registration_create / registration_update  — requires "registration"
    //   assessment_create / assessment_update /
    //   assessment_deactivate                       — requires "assessment"
    //   assessment_batch_commit                     — requires "assessments" (array)
    //   receipt_create                               — requires "payment"
    //   receipt_detail_create                        — requires "paymentDetail"
    //
    // Every branch uses the payload as sent by the caller, inserting or
    // updating rows in the corresponding table.
    // ─────────────────────────────────────────────────────────────────────────
    private function _studentHasActiveTermRegistration($db, string $studentNumber, string $ay, string $sem): bool
    {
        $stmt = $db->prepare("SELECT RegistrationNumber FROM tblRegistrations WHERE studentNumber = ? AND academicYear = ? AND semester = ? LIMIT 1");
        $stmt->execute([$studentNumber, $ay, $sem]);
        return (bool)$stmt->fetch();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/enrollment/registrations
    // Body: { studentNumber, yearLevel, sectionID, createdBy }
    //
    // Creates a new enrollment registration for the active term.
    // Atomically reserves a RegistrationNumber via SequenceGenerator::reserveIdBlock()
    // inside the same transaction as the INSERT so a failed insert rolls
    // the reservation back.
    // ─────────────────────────────────────────────────────────────────────────
    public function createRegistration()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentNumber = trim((string)($input['studentNumber'] ?? ''));
        $yearLevel     = trim((string)($input['yearLevel']     ?? ''));
        $sectionID     = trim((string)($input['sectionID']     ?? ''));
        $createdBy     = trim((string)($input['createdBy']     ?? ''));

        if ($studentNumber === '') { http_response_code(400); echo json_encode(['error' => 'Student number is required.']); return; }
        if ($yearLevel === '')     { http_response_code(400); echo json_encode(['error' => 'Year level is required.']);   return; }
        if ($sectionID === '')     { http_response_code(400); echo json_encode(['error' => 'Section is required.']);     return; }

        $db = Database::getConnection();

        try {
            $term = (new ReferenceDataService())->getActiveTerm();
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            return;
        }
        $ay = $term['academicYear'];
        $sem = $term['semester'];

        $sStmt = $db->prepare("SELECT programID FROM tblStudents WHERE studentNumber = ?");
        $sStmt->execute([$studentNumber]);
        $student = $sStmt->fetch();
        if (!$student) { http_response_code(404); echo json_encode(['error' => 'Student was not found: ' . $studentNumber]); return; }

        $programID = (string)($student['programID'] ?? '');
        if ($programID === '') { http_response_code(422); echo json_encode(['error' => 'Student has no program assigned. Program must exist before enrollment creation.']); return; }

        if ($this->_studentHasActiveTermRegistration($db, $studentNumber, $ay, $sem)) {
            http_response_code(409);
            echo json_encode(['error' => 'Student already has a current enrollment for ' . $ay . ' / ' . $sem . '.']);
            return;
        }

        $secStmt = $db->prepare("SELECT sectionName FROM tblSections WHERE sectionID = ?");
        $secStmt->execute([$sectionID]);
        $section = $secStmt->fetch();
        if (!$section) { http_response_code(404); echo json_encode(['error' => 'Section was not found: ' . $sectionID]); return; }

        $now = date('Y-m-d H:i:s');

        try {
            $db->beginTransaction();
            $seq = \App\Models\SequenceGenerator::reserveIdBlock($db, 'tblRegistrations', 1);
            $registrationNumber = \App\Models\SequenceGenerator::formatId('REG', $seq['firstNo'], 9);

            $this->_createRegistration($db, [
                'RegistrationNumber' => $registrationNumber,
                'studentNumber'      => $studentNumber,
                'programID'          => $programID,
                'yearLevel'          => $yearLevel,
                'sectionID'          => $sectionID,
                'academicYear'       => $ay,
                'semester'           => $sem,
                'createdBy'          => $createdBy,
                'dateCreated'        => $now,
            ]);
            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Unable to create registration: ' . $e->getMessage()]);
            return;
        }

        echo json_encode([
            'ok'                 => true,
            'registrationNumber' => $registrationNumber,
            'studentNumber'      => $studentNumber,
            'academicYear'       => $ay,
            'semester'           => $sem,
            'message'            => 'Registration created for ' . $ay . ' / ' . $sem . '.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/enrollment/registrations/update
    // Body: { registrationNumber, programID, yearLevel, sectionID, modifiedBy }
    //
    // Updates program, year level, and section on an existing registration.
    // ─────────────────────────────────────────────────────────────────────────
    public function updateRegistration()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $registrationNumber = trim((string)($input['registrationNumber'] ?? ''));
        $programID = trim((string)($input['programID'] ?? ''));
        $yearLevel = trim((string)($input['yearLevel'] ?? ''));
        $sectionID = trim((string)($input['sectionID'] ?? ''));
        $modifiedBy = trim((string)($input['modifiedBy'] ?? ''));

        if ($registrationNumber === '') { http_response_code(400); echo json_encode(['error' => 'Registration number is required.']); return; }
        if ($programID === '')          { http_response_code(400); echo json_encode(['error' => 'Program is required.']); return; }
        if ($yearLevel === '')          { http_response_code(400); echo json_encode(['error' => 'Year level is required.']); return; }
        if ($sectionID === '')          { http_response_code(400); echo json_encode(['error' => 'Section is required.']); return; }

        $db = Database::getConnection();

        $existsStmt = $db->prepare("SELECT RegistrationNumber FROM tblRegistrations WHERE RegistrationNumber = ?");
        $existsStmt->execute([$registrationNumber]);
        if (!$existsStmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Registration not found: ' . $registrationNumber]); return; }

        $pStmt = $db->prepare("SELECT programCode, programDescription FROM tblPrograms WHERE programID = ?");
        $pStmt->execute([$programID]);
        $program = $pStmt->fetch();
        if (!$program) { http_response_code(404); echo json_encode(['error' => 'Program was not found: ' . $programID]); return; }

        $sStmt = $db->prepare("SELECT sectionName FROM tblSections WHERE sectionID = ?");
        $sStmt->execute([$sectionID]);
        $section = $sStmt->fetch();
        if (!$section) { http_response_code(404); echo json_encode(['error' => 'Section was not found: ' . $sectionID]); return; }

        $now = date('Y-m-d H:i:s');

        try {
            $this->_updateRegistration($db, [
                'RegistrationNumber' => $registrationNumber,
                'programID'          => $programID,
                'sectionID'          => $sectionID,
                'yearLevel'          => $yearLevel,
                'modifiedBy'         => $modifiedBy,
                'lastModified'       => $now,
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Update failed: ' . $e->getMessage()]);
            return;
        }

        echo json_encode([
            'ok'                 => true,
            'registrationNumber' => $registrationNumber,
            'programID'          => $programID,
            'programCode'        => (string)($program['programCode'] ?? ''),
            'programDescription' => (string)($program['programDescription'] ?? ''),
            'yearLevel'          => $yearLevel,
            'sectionID'          => $sectionID,
            'sectionName'        => (string)($section['sectionName'] ?? ''),
            'message'            => 'Enrollment updated.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/enrollment/assessments/commit
    //
    // Commits draft assessment changes (adds, updates, deactivates) for one or
    // more registrations in a single atomic transaction. Three-pass structure:
    //   1. Validate all changes in-memory before any writes.
    //   2. Reserve a single ID block for all new assessments.
    //   3. Apply updates and deactivations.
    // A failure at any point rolls back all changes in the batch.
    // ─────────────────────────────────────────────────────────────────────────
    public function commitAssessmentDrafts()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $batches = $input['batches'] ?? [];
        $committedBy = trim((string)($input['committedBy'] ?? ''));

        if (!is_array($batches) || empty($batches)) {
            http_response_code(400);
            echo json_encode(['error' => 'No draft changes were provided for commit.']);
            return;
        }

        $db = Database::getConnection();

        // ── Pass 1: validate everything in-memory before any writes ──────────
        $pendingAdds = []; // flat list across all batches: [registrationNumber, feeID, amount, note]
        foreach ($batches as $batch) {
            $registrationNumber = trim((string)($batch['registrationNumber'] ?? ''));
            if ($registrationNumber === '') { http_response_code(400); echo json_encode(['error' => 'Batch item is missing registration number.']); return; }

            $regStmt = $db->prepare("SELECT RegistrationNumber FROM tblRegistrations WHERE RegistrationNumber = ?");
            $regStmt->execute([$registrationNumber]);
            if (!$regStmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Registration not found: ' . $registrationNumber]); return; }

            foreach ((array)($batch['adds'] ?? []) as $add) {
                $feeID  = trim((string)($add['feeID'] ?? ''));
                $amount = (float)($add['amount'] ?? 0);

                $feeStmt = $db->prepare("SELECT feeID FROM tblFees WHERE feeID = ?");
                $feeStmt->execute([$feeID]);
                if (!$feeStmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Fee not found: ' . $feeID]); return; }
                if ($amount <= 0) { http_response_code(422); echo json_encode(['error' => 'Amount must be greater than zero for fee: ' . $feeID]); return; }

                $pendingAdds[] = [
                    'registrationNumber' => $registrationNumber,
                    'feeID'  => $feeID,
                    'amount' => number_format($amount, 2, '.', ''),
                    'note'   => (string)($add['note'] ?? ''),
                ];
            }
        }

        $touchedIds = [];
        $insertedCount = 0;
        $updatedCount  = 0;
        $deactivatedCount = 0;
        $warnings = [];

        try {
            $db->beginTransaction();

            // ── Pass 2: reserve one ID block for ALL adds across ALL batches ──
            if (!empty($pendingAdds)) {
                $seq = \App\Models\SequenceGenerator::reserveIdBlock($db, 'tblAssessments', count($pendingAdds));
                $now = date('Y-m-d H:i:s');

                $insStmt = $db->prepare("
                    INSERT INTO tblAssessments
                        (assessmentID, registrationNumber, feeID, amount, cash, note, isActive, createdBy, dateCreated, modifiedBy, lastModified)
                    VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, '', '')
                ");
                foreach ($pendingAdds as $i => $item) {
                    $newID = \App\Models\SequenceGenerator::formatId('ASM', $seq['firstNo'] + $i, 8);
                    $insStmt->execute([$newID, $item['registrationNumber'], $item['feeID'], $item['amount'], $item['amount'], $item['note'], $committedBy, $now]);
                    $touchedIds[$newID] = true;
                    $insertedCount++;
                }
            }

            // ── Pass 3: updates + deactivates, per batch ──────────────────────
            foreach ($batches as $batch) {
                $registrationNumber = trim((string)($batch['registrationNumber'] ?? ''));

                foreach ((array)($batch['updates'] ?? []) as $upd) {
                    $assessmentID = trim((string)($upd['assessmentID'] ?? ''));
                    if ($assessmentID === '') continue;

                    $rowStmt = $db->prepare("SELECT registrationNumber, amount FROM tblAssessments WHERE assessmentID = ?");
                    $rowStmt->execute([$assessmentID]);
                    $row = $rowStmt->fetch();
                    if (!$row) throw new \Exception('Assessment ID was not found: ' . $assessmentID);
                    if ((string)$row['registrationNumber'] !== $registrationNumber) throw new \Exception('Assessment line does not belong to the selected registration.');

                    $feeID = trim((string)($upd['feeID'] ?? ''));
                    if ($feeID !== '') {
                        $feeStmt = $db->prepare("SELECT feeID FROM tblFees WHERE feeID = ?");
                        $feeStmt->execute([$feeID]);
                        if (!$feeStmt->fetch()) throw new \Exception('Fee was not found: ' . $feeID);
                    }
                    $amountRaw = trim((string)($upd['amount'] ?? ''));
                    $amount = $amountRaw !== '' ? (float)$amountRaw : (float)$row['amount'];
                    if ($amount <= 0) throw new \Exception('Assessment amount must be greater than zero.');

                    $updStmt = $db->prepare("
                        UPDATE tblAssessments SET
                            feeID = COALESCE(NULLIF(?, ''), feeID),
                            amount = ?, cash = ?, note = ?, isActive = 1,
                            modifiedBy = ?, lastModified = ?
                        WHERE assessmentID = ?
                    ");
                    $updStmt->execute([$feeID, number_format($amount, 2, '.', ''), number_format($amount, 2, '.', ''), (string)($upd['note'] ?? ''), $committedBy, date('Y-m-d H:i:s'), $assessmentID]);
                    $touchedIds[$assessmentID] = true;
                    $updatedCount++;
                }

                foreach ((array)($batch['deactivates'] ?? []) as $deact) {
                    $assessmentID = trim((string)($deact['assessmentID'] ?? ''));
                    if ($assessmentID === '') continue;

                    $scenario = $this->_assessmentDeletionScenario($db, $assessmentID, $registrationNumber);

                    $deactStmt = $db->prepare("UPDATE tblAssessments SET isActive = 0, modifiedBy = ?, lastModified = ? WHERE assessmentID = ?");
                    $deactStmt->execute([$committedBy, date('Y-m-d H:i:s'), $assessmentID]);
                    $touchedIds[$assessmentID] = true;
                    $deactivatedCount++;

                    if ($scenario === 'WITH_PAYMENT') $warnings[] = 'Assessment ' . $assessmentID . ' contains payment history and has been deactivated instead of deleted.';
                    elseif ($scenario === 'FULLY_PAID') $warnings[] = 'Assessment ' . $assessmentID . ' is fully paid and cannot be removed. It has been marked inactive.';
                }
            }

            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Commit failed: ' . $e->getMessage()]);
            return;
        }

        // ── Build confirmed per-registration results for the frontend ────────
        $results = [];
        foreach ($batches as $batch) {
            $registrationNumber = trim((string)($batch['registrationNumber'] ?? ''));
            $regStmt = $db->prepare("SELECT * FROM tblRegistrations WHERE RegistrationNumber = ?");
            $regStmt->execute([$registrationNumber]);
            $regRow = $regStmt->fetch();
            $summary = $regRow ? $this->_buildRegistrationSummary($db, $regRow) : null;
            $results[] = [
                'registrationNumber' => $registrationNumber,
                'assessments'        => $summary['assessments'] ?? [],
                'totalAssessed'      => $summary['totalAssessed'] ?? '0.00',
                'totalPaid'          => $summary['totalPaid'] ?? '0.00',
                'balance'            => $summary['balance'] ?? '0.00',
            ];
        }

        echo json_encode([
            'ok'                 => true,
            'totalRegistrations' => count($batches),
            'inserted'           => $insertedCount,
            'updated'            => $updatedCount,
            'deactivated'        => $deactivatedCount,
            'warnings'           => $warnings,
            'results'            => $results,
            'message'            => 'Draft assessment changes committed.',
        ]);
    }

    // Determines the deletion scenario for an assessment.
    private function _assessmentDeletionScenario($db, string $assessmentID, string $registrationNumber): string
    {
        $rowStmt = $db->prepare("SELECT amount, registrationNumber FROM tblAssessments WHERE assessmentID = ?");
        $rowStmt->execute([$assessmentID]);
        $row = $rowStmt->fetch();
        if (!$row) throw new \Exception('Assessment ID was not found: ' . $assessmentID);
        if ($registrationNumber !== '' && (string)$row['registrationNumber'] !== $registrationNumber) {
            throw new \Exception('Assessment line does not belong to the selected registration.');
        }

        $dStmt = $db->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(Amount),0) AS paid FROM tblPaymentDetails WHERE AssessmentID = ?");
        $dStmt->execute([$assessmentID]);
        $d = $dStmt->fetch();
        $paymentCount = (int)($d['cnt'] ?? 0);
        $paidAmount   = (float)($d['paid'] ?? 0);
        $amount       = (float)($row['amount'] ?? 0);

        if ($paidAmount > 0.009 && $amount - $paidAmount <= 0.009) return 'FULLY_PAID';
        if ($paidAmount > 0.009 || $paymentCount > 0) return 'WITH_PAYMENT';
        return 'NO_PAYMENT';
    }

    public function mirror()
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['operation'])) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required fields: operation"]);
            return;
        }

        $operation = $input['operation'];

        if (!in_array($operation, self::KNOWN_MIRROR_OPERATIONS, true)) {
            http_response_code(400);
            echo json_encode(["error" => "Unknown operation: {$operation}"]);
            return;
        }

        $db = Database::getConnection();

        try {
            switch ($operation) {

                case 'registration_create':
                case 'registration_update':
                    if (empty($input['registration'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing required field: registration"]);
                        return;
                    }
                    $reg = $input['registration'];

                    if ($operation === 'registration_create') {
                        $this->_createRegistration($db, $reg);
                    } else {
                        $this->_updateRegistration($db, $reg);
                    }

                    echo json_encode([
                        "status"             => "success",
                        "operation"          => $operation,
                        "RegistrationNumber" => $reg['RegistrationNumber'] ?? null,
                    ]);
                    break;

                case 'assessment_create':
                case 'assessment_update':
                case 'assessment_deactivate':
                    if (empty($input['assessment']) || !is_array($input['assessment'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing required field: assessment"]);
                        return;
                    }
                    $assessment = $input['assessment'];

                    if (empty($assessment['assessmentID'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "assessment.assessmentID is required"]);
                        return;
                    }

                    // deactivate is just an upsert that forces isActive=false,
                    // regardless of what (if anything) the caller sent for it.
                    if ($operation === 'assessment_deactivate') {
                        $assessment['isActive'] = false;
                    }

                    $this->_upsertAssessment($db, $assessment);

                    echo json_encode([
                        "status"       => "success",
                        "operation"    => $operation,
                        "assessmentID" => $assessment['assessmentID'],
                    ]);
                    break;

                case 'assessment_batch_commit':
                    if (empty($input['assessments']) || !is_array($input['assessments'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing required field: assessments"]);
                        return;
                    }

                    $count = 0;
                    $db->beginTransaction();
                    try {
                        foreach ($input['assessments'] as $assessment) {
                            if (empty($assessment['assessmentID'])) {
                                throw new \Exception('Each assessment requires an assessmentID');
                            }
                            $this->_upsertAssessment($db, $assessment);
                            $count++;
                        }
                        $db->commit();
                    } catch (\Exception $e) {
                        $db->rollBack();
                        throw $e;
                    }

                    echo json_encode([
                        "status"    => "success",
                        "operation" => $operation,
                        "count"     => $count,
                    ]);
                    break;

                // receipt_create and receipt_detail_create branches:
                case 'receipt_create':
                    if (empty($input['payment']) || !is_array($input['payment'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing required field: payment"]);
                        return;
                    }
                    $payment = $input['payment'];

                    if (empty($payment['paymentID'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "payment.paymentID is required"]);
                        return;
                    }

                    $this->_createPayment($db, $payment);

                    echo json_encode([
                        "status"    => "success",
                        "operation" => $operation,
                        "paymentID" => $payment['paymentID'],
                    ]);
                    break;

                case 'receipt_detail_create':
                    if (empty($input['paymentDetail']) || !is_array($input['paymentDetail'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing required field: paymentDetail"]);
                        return;
                    }
                    $detail = $input['paymentDetail'];

                    if (empty($detail['paymentDetailID'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "paymentDetail.paymentDetailID is required"]);
                        return;
                    }

                    $this->_createPaymentDetail($db, $detail);

                    echo json_encode([
                        "status"          => "success",
                        "operation"       => $operation,
                        "paymentDetailID" => $detail['paymentDetailID'],
                    ]);
                    break;
            }

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Mirror failed: " . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/enrollment/search?q=<text>
    //
    // Searches tblStudents by studentNumber, lastName, firstName, or full-name
    // concatenations (case-insensitive substring). Scoped to the active term:
    // only returns students with a registration matching the current
    // academicYear and semester from tblAppSettings.
    // ─────────────────────────────────────────────────────────────────────────
    public function search()
    {
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Search text is required.']);
            return;
        }

        $db = Database::getConnection();

        try {
            $term = (new ReferenceDataService())->getActiveTerm();
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            return;
        }
        $ay  = $term['academicYear'];
        $sem = $term['semester'];

        $like = '%' . $q . '%';
        $stmt = $db->prepare("
            SELECT * FROM tblStudents
            WHERE studentNumber LIKE :q1
               OR lastName      LIKE :q2
               OR firstName     LIKE :q3
               OR CONCAT(firstName, ' ', lastName) LIKE :q4
               OR CONCAT(lastName, ', ', firstName) LIKE :q5
            ORDER BY lastName, firstName
            LIMIT 50
        ");
        $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like]);
        $studentRows = $stmt->fetchAll();

        $students = [];
        $totalRegistrations = 0;

        foreach ($studentRows as $student) {
            $studentNumber = (string)($student['studentNumber'] ?? '');

            $regStmt = $db->prepare("
                SELECT * FROM tblRegistrations
                WHERE studentNumber = ? AND academicYear = ? AND semester = ?
                ORDER BY dateCreated
            ");
            $regStmt->execute([$studentNumber, $ay, $sem]);
            $regRows = $regStmt->fetchAll();

            $groupedRegistrations = [];
            $activeRegistration = null;
            foreach ($regRows as $reg) {
                $summary = $this->_buildRegistrationSummary($db, $reg);
                $groupedRegistrations[] = $summary;
                $totalRegistrations++;
                if (!$activeRegistration) $activeRegistration = $summary;
            }

            $studentProgramID = (string)($student['programID'] ?? ($activeRegistration['programID'] ?? ''));
            $progRow = null;
            if ($studentProgramID !== '') {
                $pStmt = $db->prepare("SELECT programCode, programDescription FROM tblPrograms WHERE programID = ?");
                $pStmt->execute([$studentProgramID]);
                $progRow = $pStmt->fetch();
            }

            $students[] = [
                'studentNumber'      => $studentNumber,
                'studentID'          => (string)($student['studentID'] ?? ''),
                'fullName'           => $this->_rosterStudentName($student),
                'emailAddress'       => (string)($student['emailAddress'] ?? ''),
                'contactNumber'      => (string)($student['contactNumber'] ?? ''),
                'activeAcademicYear' => $ay,
                'activeSemester'     => $sem,
                'enrollmentStatus'   => $activeRegistration ? 'CURRENT ENROLLMENT' : 'NEW ENROLLMENT',
                'hasCurrentEnrollment' => (bool)$activeRegistration,
                'programID'          => $studentProgramID,
                'programCode'        => (string)($progRow['programCode'] ?? ($activeRegistration['programCode'] ?? '')),
                'programDescription' => (string)($progRow['programDescription'] ?? ($activeRegistration['programDescription'] ?? '')),
                'activeRegistration' => $activeRegistration,
                'registrations'      => $groupedRegistrations,
            ];
        }

        echo json_encode([
            'ok'                  => true,
            'totalStudents'       => count($students),
            'totalRegistrations'  => $totalRegistrations,
            'students'            => $students,
        ]);
    }

    // Builds one registration's full detail object: program/section labels,
    // assessment lines (with per-line paid/balance/status), payment receipts,
    // payment allocations, and mismatch warnings.
    private function _buildRegistrationSummary($db, array $reg): array
    {
        $registrationNumber = (string)($reg['RegistrationNumber'] ?? '');

        $pStmt = $db->prepare("SELECT programCode, programDescription FROM tblPrograms WHERE programID = ?");
        $pStmt->execute([(string)($reg['programID'] ?? '')]);
        $program = $pStmt->fetch() ?: [];

        $sStmt = $db->prepare("SELECT sectionName FROM tblSections WHERE sectionID = ?");
        $sStmt->execute([(string)($reg['sectionID'] ?? '')]);
        $section = $sStmt->fetch() ?: [];

        $aStmt = $db->prepare("
            SELECT a.*, f.feeCode, f.feeNote AS feeDescription
            FROM tblAssessments a
            LEFT JOIN tblFees f ON f.feeID = a.feeID
            WHERE a.registrationNumber = ?
        ");
        $aStmt->execute([$registrationNumber]);
        $assessmentRows = $aStmt->fetchAll();

        $assessmentLines = [];
        $paymentAllocations = [];
        $totalAssessed = 0.0;
        $totalAllocatedPaid = 0.0;
        $warnings = [];

        foreach ($assessmentRows as $assessment) {
            $amount = (float)($assessment['amount'] ?? 0);
            $cash   = (float)($assessment['cash']   ?? 0);
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

            if ($isActive) { $totalAssessed += $amount; $totalAllocatedPaid += $paidForAssessment; }

            $assessmentLines[] = [
                'assessmentID'   => (string)($assessment['assessmentID'] ?? ''),
                'feeID'          => (string)($assessment['feeID'] ?? ''),
                'feeCode'        => (string)($assessment['feeCode'] ?? ''),
                'feeDescription' => (string)($assessment['feeDescription'] ?? ''),
                'amount'         => number_format($amount, 2, '.', ''),
                'cash'           => number_format($cash, 2, '.', ''),
                'note'           => (string)($assessment['note'] ?? ''),
                'paidAmount'     => number_format($paidForAssessment, 2, '.', ''),
                'balance'        => number_format($amount - $paidForAssessment, 2, '.', ''),
                'isActive'       => $isActive,
                'status'         => $this->_resolveAssessmentStatus($isActive, $amount, $paidForAssessment),
            ];
        }

        $payStmt = $db->prepare("SELECT * FROM tblPayments WHERE registrationNumber = ?");
        $payStmt->execute([$registrationNumber]);
        $paymentRows = $payStmt->fetchAll();

        $paymentReceipts = [];
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

            $allocationsForOr = array_values(array_filter($paymentAllocations, fn($a) => strtolower($a['ORNumber']) === strtolower($orNumber)));

            $paymentReceipts[] = [
                'paymentID'       => (string)($payment['paymentID'] ?? ''),
                'ORNumber'        => $orNumber,
                'amountPaid'      => number_format($receiptPaid, 2, '.', ''),
                'allocatedAmount' => number_format($allocatedForReceipt, 2, '.', ''),
                'paymentDate'     => trim(implode(' ', array_filter([
                                        (string)($payment['PaymentMonth'] ?? ''),
                                        (string)($payment['PaymentDay'] ?? ''),
                                        (string)($payment['PaymentYear'] ?? ''),
                                     ]))),
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
            'registrationNumber' => $registrationNumber,
            'academicYear'       => (string)($reg['academicYear'] ?? ''),
            'semester'           => (string)($reg['semester'] ?? ''),
            'yearLevel'          => (string)($reg['yearLevel'] ?? ''),
            'programID'          => (string)($reg['programID'] ?? ''),
            'programCode'        => (string)($program['programCode'] ?? ''),
            'programDescription' => (string)($program['programDescription'] ?? ''),
            'sectionID'          => (string)($reg['sectionID'] ?? ''),
            'sectionName'        => (string)($section['sectionName'] ?? ''),
            'totalAssessed'      => number_format($totalAssessed, 2, '.', ''),
            'totalPaid'          => number_format($totalAllocatedPaid, 2, '.', ''),
            'totalReceiptPaid'   => number_format($totalReceiptPaid, 2, '.', ''),
            'balance'            => number_format($totalAssessed - $totalAllocatedPaid, 2, '.', ''),
            'hasPaymentMismatch' => abs($totalReceiptPaid - $totalAllocatedPaid) > 0.009,
            'warnings'           => $warnings,
            'assessments'        => $assessmentLines,
            'paymentAllocations' => $paymentAllocations,
            'unlistedPaymentDetails' => $unlisted,
            'payments'           => $paymentReceipts,
        ];
    }

    private function _resolveAssessmentStatus(bool $isActive, float $amount, float $paidAmount): string
    {
        if (!$isActive) return 'Inactive';
        if ($amount > 0 && $amount - $paidAmount <= 0.009) return 'Paid';
        if ($paidAmount > 0.009) return 'Partially Paid';
        return 'Active';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/enrollment/summary
    //   ?academicYear=2026-2027        (required)
    //   &semester=1ST%20SEMESTER       (required)
    //
    // Returns enrollment counts grouped by program and class (program + year
    // level + section), plus financial totals (totalAssessed, totalPaid,
    // outstandingBalance) for the given term.
    // ─────────────────────────────────────────────────────────────────────────
    public function summary()
    {
        $ay  = trim($_GET['academicYear'] ?? '');
        $sem = trim($_GET['semester']     ?? '');

        if ($ay === '' || $sem === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'academicYear and semester are required.']);
            return;
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT
                r.RegistrationNumber,
                r.programID,
                r.sectionID,
                r.yearLevel,
                p.programCode,
                p.programDescription,
                sec.sectionName
            FROM tblRegistrations r
            LEFT JOIN tblPrograms p   ON p.programID   = r.programID
            LEFT JOIN tblSections sec ON sec.sectionID = r.sectionID
            WHERE r.academicYear = :academicYear
              AND r.semester     = :semester
        ");
        $stmt->execute([':academicYear' => $ay, ':semester' => $sem]);
        $rows = $stmt->fetchAll();

        // Financial totals — computed from active assessments and their payment details
        // for the same registrations as the enrollment counts above.
        $regNumbers = array_values(array_unique(array_filter(array_map(
            fn($r) => (string)($r['RegistrationNumber'] ?? ''), $rows
        ))));

        $totalAssessed = 0.0;
        $totalPaid = 0.0;
        if (!empty($regNumbers)) {
            $placeholders = implode(',', array_fill(0, count($regNumbers), '?'));
            $asmStmt = $db->prepare("
                SELECT a.assessmentID, a.amount,
                       COALESCE((SELECT SUM(pd.Amount) FROM tblPaymentDetails pd WHERE pd.AssessmentID = a.assessmentID), 0) AS paid
                FROM tblAssessments a
                WHERE a.registrationNumber IN ($placeholders) AND a.isActive = 1
            ");
            $asmStmt->execute($regNumbers);
            foreach ($asmStmt->fetchAll() as $asm) {
                $totalAssessed += (float)($asm['amount'] ?? 0);
                $totalPaid     += (float)($asm['paid'] ?? 0);
            }
        }

        $YEAR_LEVEL_ORDER = ['1st Year','2nd Year','3rd Year','4th Year','5th Year','Grade 11','Grade 12'];

        $programCounts = [];
        $classCounts   = [];
        $yearLevelSet  = [];

        foreach ($rows as $reg) {
            $programID = (string)($reg['programID'] ?? '');
            $sectionID = (string)($reg['sectionID'] ?? '');
            $yearLevel = (string)($reg['yearLevel'] ?? '');
            $yearLevel = $yearLevel !== '' ? $yearLevel : '(No Year Level)';

            $yearLevelSet[$yearLevel] = true;

            $programKey = $programID !== '' ? $programID : '(No Program)';
            if (!isset($programCounts[$programKey])) {
                $isFallback = $programKey === '(No Program)';
                $programCounts[$programKey] = [
                    'programID'          => $programKey,
                    'programCode'        => $isFallback ? '(No Program)' : ($reg['programCode'] ?: $programID),
                    'programDescription' => $isFallback ? 'Registrations without a program assigned' : ($reg['programDescription'] ?: ''),
                    'byYearLevel'        => [],
                    'total'              => 0,
                ];
            }
            $programCounts[$programKey]['byYearLevel'][$yearLevel] =
                ($programCounts[$programKey]['byYearLevel'][$yearLevel] ?? 0) + 1;
            $programCounts[$programKey]['total']++;

            $classProgramKey = $programID !== '' ? $programID : '(No Program)';
            $classSectionKey = $sectionID !== '' ? $sectionID : '(No Section)';
            $classKey = $classProgramKey . '|' . $yearLevel . '|' . $classSectionKey;

            if (!isset($classCounts[$classKey])) {
                $isFallbackProgram = $programID === '';
                $isFallbackSection = $sectionID === '';
                $classCounts[$classKey] = [
                    'programID'   => $isFallbackProgram ? '' : $programID,
                    'programCode' => $isFallbackProgram ? '(No Program)' : ($reg['programCode'] ?: $programID),
                    'yearLevel'   => $yearLevel,
                    'sectionID'   => $isFallbackSection ? '' : $sectionID,
                    'sectionName' => $isFallbackSection ? '(No Section)' : ($reg['sectionName'] ?: $sectionID),
                    'count'       => 0,
                    'isFallback'  => $isFallbackProgram || $isFallbackSection,
                ];
            }
            $classCounts[$classKey]['count']++;
        }

        $yearLevels = array_keys($yearLevelSet);
        usort($yearLevels, function($a, $b) use ($YEAR_LEVEL_ORDER) {
            if ($a === '(No Year Level)') return 1;
            if ($b === '(No Year Level)') return -1;
            $ia = array_search($a, $YEAR_LEVEL_ORDER);
            $ib = array_search($b, $YEAR_LEVEL_ORDER);
            if ($ia === false && $ib === false) return strcmp($a, $b);
            if ($ia === false) return 1;
            if ($ib === false) return -1;
            return $ia - $ib;
        });

        $byProgram = array_values($programCounts);
        usort($byProgram, fn($a, $b) => strcmp($a['programCode'], $b['programCode']));

        $byClass = array_values($classCounts);
        foreach ($byClass as &$c) {
            $ylDigits = $this->_yearLevelDigits($c['yearLevel']);
            $c['classCode'] = $c['programCode'] . '-' . $ylDigits . '-' . $c['sectionName'];
        }
        unset($c);
        usort($byClass, function($a, $b) {
            if ($a['isFallback'] && !$b['isFallback']) return 1;
            if (!$a['isFallback'] && $b['isFallback']) return -1;
            return strcmp($a['classCode'], $b['classCode']);
        });

        echo json_encode([
            'ok'                 => true,
            'academicYear'       => $ay,
            'semester'           => $sem,
            'totalAssessed'      => number_format($totalAssessed, 2, '.', ''),
            'totalPaid'          => number_format($totalPaid, 2, '.', ''),
            'outstandingBalance' => number_format($totalAssessed - $totalPaid, 2, '.', ''),
            'totalEnrolled' => count($rows),
            'programCount'  => count($byProgram),
            'sectionCount'  => count($byClass),
            'yearLevels'    => $yearLevels,
            'byProgram'     => $byProgram,
            'byClass'       => $byClass,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/enrollment/roster
    //   ?academicYear=2026-2027   (required)
    //   &semester=1ST%20SEMESTER  (required)
    //   &yearLevel=2nd%20Year     (required)
    //   &programID=BSIT           (optional — '' or NULL matches the "(No Program)" bucket)
    //   &sectionID=SEC001         (optional — '' or NULL matches the "(No Section)" bucket)
    //
    // Returns the class roster for the given programID + yearLevel + sectionID
    // combination, matching the same bucketing used by the summary endpoint.
    // ─────────────────────────────────────────────────────────────────────────
    public function roster()
    {
        $ay        = trim($_GET['academicYear'] ?? '');
        $sem       = trim($_GET['semester']     ?? '');
        $yearLevel = trim($_GET['yearLevel']    ?? '');
        $programID = trim($_GET['programID']    ?? '');
        $sectionID = trim($_GET['sectionID']    ?? '');

        if ($ay === '' || $sem === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'academicYear and semester are required.']);
            return;
        }
        if ($yearLevel === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'yearLevel is required to load the class roster.']);
            return;
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT
                r.RegistrationNumber,
                r.studentNumber,
                r.dateCreated,
                r.yearLevel,
                p.programCode,
                sec.sectionName,
                s.lastName,
                s.nameExtension,
                s.firstName,
                s.middleName,
                s.middleInitial,
                s.gender
            FROM tblRegistrations r
            LEFT JOIN tblPrograms p   ON p.programID   = r.programID
            LEFT JOIN tblSections sec ON sec.sectionID = r.sectionID
            LEFT JOIN tblStudents s   ON s.studentNumber = r.studentNumber
            WHERE r.academicYear      = :academicYear
              AND r.semester          = :semester
              AND COALESCE(r.programID, '') = :programID
              AND COALESCE(r.sectionID, '') = :sectionID
        ");
        $stmt->execute([
            ':academicYear' => $ay,
            ':semester'     => $sem,
            ':programID'    => $programID,
            ':sectionID'    => $sectionID,
        ]);
        $rawRows = $stmt->fetchAll();

        // Match the same blank-yearLevel fallback bucketing used by the summary
        // endpoint: registrations with no yearLevel are grouped under '(No Year Level)'.
        $rows = array_filter($rawRows, function ($row) use ($yearLevel) {
            $rowYearLevel = (string)($row['yearLevel'] ?? '');
            $rowYearLevel = $rowYearLevel !== '' ? $rowYearLevel : '(No Year Level)';
            return $rowYearLevel === $yearLevel;
        });

        $programCode = $programID !== '' ? '' : '(No Program)';
        $sectionName = $sectionID !== '' ? '' : '(No Section)';

        $students = [];
        foreach ($rows as $row) {
            if ($programCode === '') {
                $programCode = $row['programCode'] ?: $programID;
            }
            if ($sectionName === '') {
                $sectionName = $row['sectionName'] ?: $sectionID;
            }
            $students[] = [
                'registrationNumber' => (string)($row['RegistrationNumber'] ?? ''),
                'studentNumber'      => (string)($row['studentNumber'] ?? ''),
                'fullName'           => $this->_rosterStudentName($row),
                'gender'             => (string)($row['gender'] ?? ''),
                'dateRegistered'     => (string)($row['dateCreated'] ?? ''),
            ];
        }

        usort($students, fn($a, $b) => strcmp($a['fullName'], $b['fullName']));

        foreach ($students as $i => &$s) {
            $s['no'] = $i + 1;
        }
        unset($s);

        if ($programCode === '') $programCode = $programID !== '' ? $programID : '(No Program)';
        if ($sectionName === '') $sectionName = $sectionID !== '' ? $sectionID : '(No Section)';

        $ylDigits  = $this->_yearLevelDigits($yearLevel);
        $classCode = $programCode . '-' . $ylDigits . '-' . $sectionName;

        foreach ($students as &$s) {
            $s['classCode'] = $classCode;
        }
        unset($s);

        echo json_encode([
            'ok'            => true,
            'classCode'     => $classCode,
            'programID'     => $programID,
            'yearLevel'     => $yearLevel,
            'sectionID'     => $sectionID,
            'programCode'   => $programCode,
            'sectionName'   => $sectionName,
            'totalStudents' => count($students),
            'students'      => $students,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/enrollment/students   (also aliased as GET /api/accounts/students)
    //   ?q=<search text>     (required — min 2 characters)
    //   &limit=<n>            (optional — default 20, hard cap 50)
    //
    // Prefix-match typeahead across studentNumber, lastName, firstName, and
    // "First Last" concatenation. Minimum 2-character query and hard cap of
    // 50 results are enforced server-side.
    // ─────────────────────────────────────────────────────────────────────────
    public function students()
    {
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
            SELECT
                studentNumber, studentID,
                lastName, firstName, middleName, middleInitial, nameExtension
            FROM tblStudents
            WHERE studentNumber LIKE :q1
               OR lastName      LIKE :q2
               OR firstName     LIKE :q3
               OR CONCAT(firstName, ' ', lastName) LIKE :q4
            ORDER BY lastName, firstName
            LIMIT :lim
        ");

        // Prefix-only — no leading '%' — keeps the studentNumber PK
        // and (lastName, firstName) index usable.
        $prefix = $q . '%';
        $stmt->bindValue(':q1', $prefix, \PDO::PARAM_STR);
        $stmt->bindValue(':q2', $prefix, \PDO::PARAM_STR);
        $stmt->bindValue(':q3', $prefix, \PDO::PARAM_STR);
        $stmt->bindValue(':q4', $prefix, \PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $suggestions = [];
        foreach ($rows as $row) {
            // Reuse the shared roster name builder for consistency.
            // builder directly — "[lastName] [nameExtension], [firstName]
            // [middleName]" — rather than maintaining a second formatting rule.
            $name = $this->_rosterStudentName($row);
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
            'ok'          => true,
            'query'       => $q,
            'suggestions' => $suggestions,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/enrollment/fees   (also aliased as GET /api/accounts/fees)
    // ─────────────────────────────────────────────────────────────────────────
    public function fees()
    {
        $out = (new ReferenceDataService())->getActiveFees();
        echo json_encode(['ok' => true, 'fees' => $out]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/enrollment/fee-templates   (also aliased as GET /api/accounts/fee-templates)
    // ─────────────────────────────────────────────────────────────────────────
    public function feeTemplates()
    {
        $out = (new ReferenceDataService())->getActiveFeeTemplates();
        echo json_encode(['ok' => true, 'templates' => $out]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/enrollment/fee-template-fees   (also aliased as GET /api/accounts/fee-template-fees)
    //   ?feeTemplateID=<id>   (required)
    // ─────────────────────────────────────────────────────────────────────────
    public function feeTemplateFees()
    {
        $feeTemplateID = trim($_GET['feeTemplateID'] ?? '');

        if ($feeTemplateID === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'feeTemplateID is required.']);
            return;
        }

        $out = (new ReferenceDataService())->getFeeTemplateFees($feeTemplateID);

        echo json_encode([
            'ok'            => true,
            'feeTemplateID' => $feeTemplateID,
            'fees'          => $out,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/enrollment/sections   (also aliased as GET /api/accounts/sections)
    // ─────────────────────────────────────────────────────────────────────────
    public function sections()
    {
        $out = (new ReferenceDataService())->getAllSections();
        echo json_encode(['ok' => true, 'sections' => $out]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/enrollment/programs   (also aliased as GET /api/accounts/programs)
    // ─────────────────────────────────────────────────────────────────────────
    public function programs()
    {
        $out = (new ReferenceDataService())->getAllPrograms();
        echo json_encode(['ok' => true, 'programs' => $out]);
    }

    // Delegates to ReferenceDataService::buildStudentFullName() for
    // consistent name formatting across all modules. Thin private wrapper
    // so existing callers don't need to change.
    private function _rosterStudentName(array $row): string
    {
        return (new ReferenceDataService())->buildStudentFullName($row);
    }

    private function _yearLevelDigits(string $yearLevel): string
    {
        if (preg_match('/(\d+)/', $yearLevel, $m)) return $m[1];
        if (stripos($yearLevel, 'Grade 11') !== false) return '11';
        if (stripos($yearLevel, 'Grade 12') !== false) return '12';
        return '0';
    }

    // ── Private helpers — registrations ──────────────────────────────────

    private function _createRegistration($db, array $r)
    {
        $stmt = $db->prepare("
            INSERT INTO tblRegistrations (
                registrationID, RegistrationNumber, studentNumber,
                programID, trackID, strandID, bundleID,
                strandSpecializationID, ScholarshipID,
                yearLevel, sectionID, academicYear, semester,
                createdBy, dateCreated, modifiedBy, lastModified
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
            ON DUPLICATE KEY UPDATE
                studentNumber          = VALUES(studentNumber),
                programID              = VALUES(programID),
                sectionID              = VALUES(sectionID),
                yearLevel              = VALUES(yearLevel),
                academicYear           = VALUES(academicYear),
                semester               = VALUES(semester),
                modifiedBy             = VALUES(modifiedBy),
                lastModified           = VALUES(lastModified)
        ");

        $stmt->execute([
            $r['registrationID']         ?? null,
            $r['RegistrationNumber']     ?? null,
            $r['studentNumber']          ?? null,
            $r['programID']              ?? null,
            $r['trackID']                ?? '',
            $r['strandID']               ?? '',
            $r['bundleID']               ?? '',
            $r['strandSpecializationID'] ?? '',
            $r['ScholarshipID']          ?? '',
            $r['yearLevel']              ?? null,
            $r['sectionID']              ?? null,
            $r['academicYear']           ?? null,
            $r['semester']               ?? null,
            $r['createdBy']              ?? null,
            $r['dateCreated']            ?? null,
            $r['modifiedBy']             ?? null,
            $r['lastModified']           ?? null,
        ]);
    }

    private function _updateRegistration($db, array $r)
    {
        if (empty($r['RegistrationNumber'])) {
            throw new \Exception('RegistrationNumber is required for update');
        }

        $stmt = $db->prepare("
            UPDATE tblRegistrations SET
                programID    = ?,
                sectionID    = ?,
                yearLevel    = ?,
                modifiedBy   = ?,
                lastModified = ?
            WHERE RegistrationNumber = ?
        ");

        $stmt->execute([
            $r['programID']          ?? null,
            $r['sectionID']          ?? null,
            $r['yearLevel']          ?? null,
            $r['modifiedBy']         ?? null,
            $r['lastModified']       ?? null,
            $r['RegistrationNumber'],
        ]);
    }

    // ── Private helpers — assessments ──────────────────────────

    // Upserts a single assessment row keyed on assessmentID (the table's
    // PRIMARY KEY). Insert path writes every column, including
    // createdBy/dateCreated. Update path (ON DUPLICATE KEY) deliberately
    // Reused as-is by both Enrollment and Accounts mirror calls:
    // INSERT ... ON DUPLICATE KEY UPDATE excludes createdBy/dateCreated
    // so retried mirror calls don't clobber audit fields.
    private function _upsertAssessment($db, array $a)
    {
        if (empty($a['assessmentID'])) {
            throw new \Exception('assessmentID is required');
        }

        $isActive = array_key_exists('isActive', $a)
            ? $this->_normalizeAssessmentActive($a['isActive'])
            : 1;

        $amount = (isset($a['amount']) && $a['amount'] !== '') ? $a['amount'] : '0.00';
        $cash   = (isset($a['cash'])   && $a['cash']   !== '') ? $a['cash']   : '0.00';

        $stmt = $db->prepare("
            INSERT INTO tblAssessments (
                assessmentID, registrationNumber, feeID,
                amount, cash, note, isActive,
                createdBy, dateCreated, modifiedBy, lastModified
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
            ON DUPLICATE KEY UPDATE
                registrationNumber = VALUES(registrationNumber),
                feeID              = VALUES(feeID),
                amount             = VALUES(amount),
                cash               = VALUES(cash),
                note               = VALUES(note),
                isActive           = VALUES(isActive),
                modifiedBy         = VALUES(modifiedBy),
                lastModified       = VALUES(lastModified)
        ");

        $stmt->execute([
            $a['assessmentID'],
            $a['registrationNumber'] ?? null,
            $a['feeID']              ?? null,
            $amount,
            $cash,
            $a['note']               ?? null,
            $isActive,
            $a['createdBy']          ?? null,
            // dateCreated/lastModified are VARCHAR (see A2 schema correction) —
            // stored exactly as the caller sends them, no format/timezone
            // translation. Only blank/"NULL"-literal values are normalized away.
            $this->_nullIfBlank($a['dateCreated']  ?? null),
            $a['modifiedBy']         ?? null,
            $this->_nullIfBlank($a['lastModified'] ?? null),
        ]);
    }

    // Treats a blank/NULL-literal string the same way ImportController's
    // generic blank-to-null rule does, so the mirror endpoint and the CSV
    // import path agree on what "no value" means for dateCreated/lastModified
    // (now VARCHAR columns). Kept as an explicit call
    // rather than relying on column defaults, since a caller sending "" is
    // an intentional "I have nothing for this field" signal, not a value
    // to store literally.
    private function _nullIfBlank($value)
    {
        if ($value === null) return null;
        $normalized = trim((string)$value);
        if ($normalized === '' || strtolower($normalized) === 'null') return null;
        return $value;
    }

    // Normalizes an isActive value: blank/missing means ACTIVE (1).
    // Accepts real JSON booleans, integers, and string forms.
    private function _normalizeAssessmentActive($value): int
    {
        if ($value === null) return 1;
        if (is_bool($value)) return $value ? 1 : 0;
        if (is_int($value))  return $value ? 1 : 0;

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '') return 1;
        return in_array($normalized, ['1', 'true', 'yes', 'active'], true) ? 1 : 0;
    }

    // ── Private helpers — receipts ────────────────────────────

    // Inserts or updates a payment row. ON DUPLICATE KEY UPDATE is kept\n    // for idempotency: a retried mirror call must not create a second row.
    private function _createPayment($db, array $p)
    {
        $stmt = $db->prepare("
            INSERT INTO tblPayments (
                paymentID, ORNumber, registrationNumber, AmountPaid,
                PaymentMonthNumber, PaymentMonth, PaymentDay, PaymentYear,
                dateCreated, createdBy
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
            ON DUPLICATE KEY UPDATE
                ORNumber           = VALUES(ORNumber),
                registrationNumber = VALUES(registrationNumber),
                AmountPaid         = VALUES(AmountPaid),
                PaymentMonthNumber = VALUES(PaymentMonthNumber),
                PaymentMonth       = VALUES(PaymentMonth),
                PaymentDay         = VALUES(PaymentDay),
                PaymentYear        = VALUES(PaymentYear)
        ");

        $stmt->execute([
            $p['paymentID']          ?? null,
            $p['ORNumber']           ?? null,
            $p['registrationNumber'] ?? null,
            $p['AmountPaid']         ?? '0.00',
            $p['PaymentMonthNumber'] ?? null,
            $p['PaymentMonth']       ?? null,
            $p['PaymentDay']         ?? null,
            $p['PaymentYear']        ?? null,
            $p['dateCreated']        ?? null,
            $p['createdBy']          ?? null,
        ]);
    }

    // Inserts or updates a payment detail row (same idempotency contract
    // as _createPayment() above).
    private function _createPaymentDetail($db, array $d)
    {
        $stmt = $db->prepare("
            INSERT INTO tblPaymentDetails (
                paymentDetailID, ORNumber, AssessmentID, Amount,
                createdBy, dateCreated
            ) VALUES (
                ?, ?, ?, ?, ?, ?
            )
            ON DUPLICATE KEY UPDATE
                ORNumber     = VALUES(ORNumber),
                AssessmentID = VALUES(AssessmentID),
                Amount       = VALUES(Amount)
        ");

        $stmt->execute([
            $d['paymentDetailID'] ?? null,
            $d['ORNumber']        ?? null,
            $d['AssessmentID']    ?? null,
            $d['Amount']          ?? '0.00',
            $d['createdBy']       ?? null,
            $d['dateCreated']     ?? null,
        ]);
    }
}
