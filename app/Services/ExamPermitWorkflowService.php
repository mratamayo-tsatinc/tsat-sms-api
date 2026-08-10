<?php

namespace App\Services;

use App\Core\Database;
use App\Models\SequenceGenerator;

/**
 * Write workflow for Exam Permit Phase 3.
 *
 * Covers:
 * - policy resolution and gate evaluation
 * - permit generation (record-first)
 * - print/reprint status updates with policy-change guard
 * - Moodle action precondition checks (local gate only)
 * - audit logging
 */
class ExamPermitWorkflowService
{
    private const VALID_PERIODS = ['PRELIM', 'MIDTERM', 'SEMIFINALS', 'FINALS'];

    public function logMoodleAction(array $input): void
    {
        $studentNumber = trim((string)($input['studentNumber'] ?? ''));
        $academicYear  = trim((string)($input['academicYear'] ?? ''));
        $semester      = trim((string)($input['semester'] ?? ''));
        $period        = strtoupper(trim((string)($input['period'] ?? '')));
        $actorEmail    = trim((string)($input['actorEmail'] ?? ''));
        $isActivate    = (bool)($input['active'] ?? false);
        $outcome       = strtoupper(trim((string)($input['outcome'] ?? 'SUCCESS')));
        $detail        = trim((string)($input['detail'] ?? ''));

        if ($studentNumber === '' || $academicYear === '' || $semester === '' || $period === '' || $actorEmail === '') {
            return;
        }
        if (!in_array($period, self::VALID_PERIODS, true)) {
            return;
        }

        $db = Database::getConnection();
        $ctx = $this->getRegistrationContext($db, $studentNumber, $academicYear, $semester);
        $permit = $this->findIssuedPermit($db, $studentNumber, $academicYear, $semester, $period);

        $this->writeAudit($db, [
            'permitID' => $permit['permitID'] ?? null,
            'studentNumber' => $studentNumber,
            'registrationNumber' => $ctx['registrationNumber'] ?? null,
            'academicYear' => $academicYear,
            'semester' => $semester,
            'period' => $period,
            'actionType' => $isActivate ? 'MOODLE_ACTIVATE' : 'MOODLE_DEACTIVATE',
            'outcome' => $outcome,
            'actorEmail' => $actorEmail,
            'actorName' => $this->resolveActorName($db, $actorEmail),
            'detail' => $detail !== '' ? $detail : 'Moodle exam permit status action.',
        ]);
    }

    public function generatePermit(array $input): array
    {
        $studentNumber = trim((string)($input['studentNumber'] ?? ''));
        $academicYear  = trim((string)($input['academicYear'] ?? ''));
        $semester      = trim((string)($input['semester'] ?? ''));
        $period        = strtoupper(trim((string)($input['period'] ?? '')));
        $actorEmail    = trim((string)($input['actorEmail'] ?? ''));

        if ($studentNumber === '' || $academicYear === '' || $semester === '' || $period === '' || $actorEmail === '') {
            return $this->error('VALIDATION_ERROR', 'studentNumber, academicYear, semester, period, and actorEmail are required.');
        }
        if (!in_array($period, self::VALID_PERIODS, true)) {
            return $this->error('VALIDATION_ERROR', 'period must be PRELIM, MIDTERM, SEMIFINALS, or FINALS.');
        }

        $db = Database::getConnection();
        $ctx = $this->getRegistrationContext($db, $studentNumber, $academicYear, $semester);
        if (!$ctx) {
            return $this->error('NOT_REGISTERED_THIS_TERM', 'Student has no registration for the given term.');
        }

        $existing = $this->findIssuedPermit($db, $studentNumber, $academicYear, $semester, $period);
        if ($existing) {
            return [
                'ok' => true,
                'code' => 'ALREADY_ISSUED',
                'message' => 'Permit already exists for this student, term, and period.',
                'permit' => $existing,
                'gate' => null,
            ];
        }

        $gate = $this->evaluateGate($db, $ctx, $period, $academicYear, $semester);
        if (!$gate['allowed']) {
            $this->writeAudit($db, [
                'permitID' => null,
                'studentNumber' => $ctx['studentNumber'],
                'registrationNumber' => $ctx['registrationNumber'],
                'academicYear' => $academicYear,
                'semester' => $semester,
                'period' => $period,
                'actionType' => 'GENERATE',
                'outcome' => 'DENY',
                'actorEmail' => $actorEmail,
                'actorName' => $this->resolveActorName($db, $actorEmail),
                'detail' => $gate['summary'],
            ]);
            return [
                'ok' => false,
                'code' => 'GATE_DENIED',
                'message' => 'Permit generation denied by policy gate.',
                'gate' => $gate,
            ];
        }

        try {
            $db->beginTransaction();

            $permitSeq = SequenceGenerator::reserveIdBlock($db, 'tblExamPermits', 1);
            $permitID  = SequenceGenerator::formatId('EPR', (int)$permitSeq['firstNo'], 7);

            $now = date('Y-m-d H:i:s');
            $insert = $db->prepare(
                "INSERT INTO tblExamPermits
                (permitID, studentNumber, registrationNumber, academicYear, semester, period, status,
                 gatePolicyID, gateDecision, gateSummary, generatedBy, generatedAt,
                 lastPrintedBy, lastPrintedAt, printCount, moodleActivatedBy, moodleActivatedAt)
                VALUES
                (:permitID, :studentNumber, :registrationNumber, :academicYear, :semester, :period, 'ISSUED',
                 :gatePolicyID, :gateDecision, :gateSummary, :generatedBy, :generatedAt,
                 NULL, NULL, 0, NULL, NULL)"
            );
            $insert->execute([
                ':permitID' => $permitID,
                ':studentNumber' => $ctx['studentNumber'],
                ':registrationNumber' => $ctx['registrationNumber'],
                ':academicYear' => $academicYear,
                ':semester' => $semester,
                ':period' => $period,
                ':gatePolicyID' => $gate['policyID'],
                ':gateDecision' => 'ALLOW',
                ':gateSummary' => $gate['summary'],
                ':generatedBy' => $actorEmail,
                ':generatedAt' => $now,
            ]);

            $this->writeAudit($db, [
                'permitID' => $permitID,
                'studentNumber' => $ctx['studentNumber'],
                'registrationNumber' => $ctx['registrationNumber'],
                'academicYear' => $academicYear,
                'semester' => $semester,
                'period' => $period,
                'actionType' => 'GENERATE',
                'outcome' => 'ALLOW',
                'actorEmail' => $actorEmail,
                'actorName' => $this->resolveActorName($db, $actorEmail),
                'detail' => 'Permit issued successfully.',
            ], true);

            $db->commit();

            return [
                'ok' => true,
                'code' => 'GENERATED',
                'message' => 'Permit generated successfully.',
                'permit' => $this->getPermitById($db, $permitID),
                'gate' => $gate,
            ];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function updatePrintStatus(array $input): array
    {
        $permitID   = trim((string)($input['permitID'] ?? ''));
        $actorEmail = trim((string)($input['actorEmail'] ?? ''));

        if ($permitID === '' || $actorEmail === '') {
            return $this->error('VALIDATION_ERROR', 'permitID and actorEmail are required.');
        }

        $db = Database::getConnection();
        $permit = $this->getPermitById($db, $permitID);
        if (!$permit) {
            return $this->error('PERMIT_NOT_FOUND', 'Permit not found.');
        }

        $ctx = $this->getRegistrationContext(
            $db,
            (string)$permit['studentNumber'],
            (string)$permit['academicYear'],
            (string)$permit['semester']
        );
        if (!$ctx) {
            return $this->error('NOT_REGISTERED_THIS_TERM', 'Registration context not found for permit term.');
        }

        $gate = $this->evaluateGate(
            $db,
            $ctx,
            (string)$permit['period'],
            (string)$permit['academicYear'],
            (string)$permit['semester']
        );

        $policyChanged = ($gate['policyID'] ?? '') !== (string)($permit['gatePolicyID'] ?? '');
        if ($policyChanged) {
            $this->writeAudit($db, [
                'permitID' => $permitID,
                'studentNumber' => (string)$permit['studentNumber'],
                'registrationNumber' => (string)$permit['registrationNumber'],
                'academicYear' => (string)$permit['academicYear'],
                'semester' => (string)$permit['semester'],
                'period' => (string)$permit['period'],
                'actionType' => 'REPRINT',
                'outcome' => 'DENY',
                'actorEmail' => $actorEmail,
                'actorName' => $this->resolveActorName($db, $actorEmail),
                'detail' => 'Reprint blocked: policy changed since issuance. Use void/reissue path.',
            ]);
            return $this->error('POLICY_CHANGED', 'Reprint blocked because policy changed since issuance. Use void/reissue flow.');
        }

        $prevCount = (int)($permit['printCount'] ?? 0);
        $newCount  = $prevCount + 1;
        $now       = date('Y-m-d H:i:s');
        $action    = $prevCount > 0 ? 'REPRINT' : 'PRINT';

        $upd = $db->prepare("UPDATE tblExamPermits SET printCount = :printCount, lastPrintedBy = :by, lastPrintedAt = :at WHERE permitID = :permitID");
        $upd->execute([
            ':printCount' => $newCount,
            ':by' => $actorEmail,
            ':at' => $now,
            ':permitID' => $permitID,
        ]);

        $this->writeAudit($db, [
            'permitID' => $permitID,
            'studentNumber' => (string)$permit['studentNumber'],
            'registrationNumber' => (string)$permit['registrationNumber'],
            'academicYear' => (string)$permit['academicYear'],
            'semester' => (string)$permit['semester'],
            'period' => (string)$permit['period'],
            'actionType' => $action,
            'outcome' => 'SUCCESS',
            'actorEmail' => $actorEmail,
            'actorName' => $this->resolveActorName($db, $actorEmail),
            'detail' => 'Print count updated to ' . $newCount . '.',
        ]);

        return [
            'ok' => true,
            'code' => 'PRINT_STATUS_UPDATED',
            'message' => 'Print status updated.',
            'permit' => $this->getPermitById($db, $permitID),
        ];
    }

    public function latestIssued(array $input): array
    {
        $studentNumber = trim((string)($input['studentNumber'] ?? ''));
        $academicYear  = trim((string)($input['academicYear'] ?? ''));
        $semester      = trim((string)($input['semester'] ?? ''));
        $period        = strtoupper(trim((string)($input['period'] ?? '')));

        if ($studentNumber === '' || $academicYear === '' || $semester === '') {
            return $this->error('VALIDATION_ERROR', 'studentNumber, academicYear, and semester are required.');
        }

        $db = Database::getConnection();
        $sql = "SELECT * FROM tblExamPermits WHERE studentNumber = :sn AND academicYear = :ay AND semester = :sem";
        $params = [':sn' => $studentNumber, ':ay' => $academicYear, ':sem' => $semester];

        if ($period !== '') {
            if (!in_array($period, self::VALID_PERIODS, true)) {
                return $this->error('VALIDATION_ERROR', 'period must be PRELIM, MIDTERM, SEMIFINALS, or FINALS.');
            }
            $sql .= " AND period = :period";
            $params[':period'] = $period;
        }

        $sql .= " ORDER BY generatedAt DESC, permitID DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'ok' => true,
            'permit' => $row ?: null,
        ];
    }

    public function moodleEligibility(array $input): array
    {
        $studentNumber = trim((string)($input['studentNumber'] ?? ''));
        $academicYear  = trim((string)($input['academicYear'] ?? ''));
        $semester      = trim((string)($input['semester'] ?? ''));
        $period        = strtoupper(trim((string)($input['period'] ?? '')));

        if ($studentNumber === '' || $academicYear === '' || $semester === '' || $period === '') {
            return $this->error('VALIDATION_ERROR', 'studentNumber, academicYear, semester, and period are required.');
        }
        if (!in_array($period, self::VALID_PERIODS, true)) {
            return $this->error('VALIDATION_ERROR', 'period must be PRELIM, MIDTERM, SEMIFINALS, or FINALS.');
        }

        $db = Database::getConnection();
        $permit = $this->findIssuedPermit($db, $studentNumber, $academicYear, $semester, $period);
        if (!$permit) {
            return [
                'ok' => true,
                'eligible' => false,
                'code' => 'NO_PERMIT',
                'message' => 'No issued permit exists for this term and period.',
                'permit' => null,
                'gate' => null,
            ];
        }

        $ctx = $this->getRegistrationContext($db, $studentNumber, $academicYear, $semester);
        if (!$ctx) {
            return [
                'ok' => true,
                'eligible' => false,
                'code' => 'NO_REGISTRATION_CONTEXT',
                'message' => 'Registration context not found for permit term.',
                'permit' => $permit,
                'gate' => null,
            ];
        }

        $gate = $this->evaluateGate($db, $ctx, $period, $academicYear, $semester);
        if (!$gate['allowed']) {
            return [
                'ok' => true,
                'eligible' => false,
                'code' => 'GATE_DENIED',
                'message' => 'Current policy gate denies Moodle action.',
                'permit' => $permit,
                'gate' => $gate,
            ];
        }

        return [
            'ok' => true,
            'eligible' => true,
            'code' => 'ELIGIBLE',
            'message' => 'Permit exists and gate passes for this term and period.',
            'permit' => $permit,
            'gate' => $gate,
        ];
    }

    private function evaluateGate($db, array $ctx, string $period, string $academicYear, string $semester): array
    {
        $policy = $this->resolvePolicy($db, $ctx, $period, $academicYear, $semester);
        if (!$policy) {
            return [
                'allowed' => false,
                'policyID' => null,
                'summary' => 'No matching enabled policy for this student/term/period.',
                'reasons' => ['No matching enabled policy for this student/term/period.'],
            ];
        }

        $snapshot = $this->buildFinanceSnapshot($db, (string)$ctx['registrationNumber']);
        $groups = $this->loadPolicyGroups($db, (string)$policy['policyID']);

        if (!$groups) {
            return [
                'allowed' => false,
                'policyID' => (string)$policy['policyID'],
                'summary' => 'Policy has no enabled groups/rules.',
                'reasons' => ['Policy has no enabled groups/rules.'],
            ];
        }

        $denyReasons = [];
        foreach ($groups as $group) {
            $groupPassed = $this->evaluateGroup($group, $snapshot, $denyReasons);
            if (!$groupPassed) {
                break;
            }
        }

        $allowed = count($denyReasons) === 0;
        return [
            'allowed' => $allowed,
            'policyID' => (string)$policy['policyID'],
            'summary' => $allowed ? 'All policy conditions passed.' : $denyReasons[0],
            'reasons' => $denyReasons,
        ];
    }

    private function evaluateGroup(array $group, array $snapshot, array &$denyReasons): bool
    {
        $rules = $group['rules'] ?? [];
        if (!$rules) {
            $denyReasons[] = 'Policy group ' . ($group['groupName'] ?? '(unnamed)') . ' has no enabled rules.';
            return false;
        }

        $operator = strtoupper((string)($group['operatorType'] ?? 'AND'));
        $passes = [];
        $firstFailMessage = null;

        foreach ($rules as $rule) {
            [$rulePass, $ruleMessage] = $this->evaluateRule($rule, $snapshot);
            $passes[] = $rulePass;
            if (!$rulePass && $firstFailMessage === null) {
                $firstFailMessage = $ruleMessage;
            }
        }

        $groupPass = $operator === 'OR'
            ? in_array(true, $passes, true)
            : !in_array(false, $passes, true);

        if ((int)($group['isNegated'] ?? 0) === 1) {
            $groupPass = !$groupPass;
        }

        if (!$groupPass) {
            $label = trim((string)($group['groupName'] ?? 'Policy group'));
            $denyReasons[] = $label . ': ' . ($firstFailMessage ?: 'One or more rules failed.');
        }

        return $groupPass;
    }

    private function evaluateRule(array $rule, array $snapshot): array
    {
        $ruleType = strtoupper((string)($rule['ruleType'] ?? ''));
        $feeID = trim((string)($rule['feeID'] ?? ''));
        $threshold = isset($rule['thresholdValue']) ? (float)$rule['thresholdValue'] : null;
        $label = trim((string)($rule['ruleLabel'] ?? $ruleType));

        $pass = false;
        $message = 'Rule evaluation failed.';

        if ($ruleType === 'TOTAL_BALANCE_ZERO') {
            $pass = $snapshot['totalBalance'] <= 0.009;
            $message = $pass ? $label . ' passed.' : $label . ' failed (total balance: ' . number_format($snapshot['totalBalance'], 2, '.', '') . ').';
        } elseif ($ruleType === 'FEE_BALANCE_ZERO') {
            $feeBal = $snapshot['feeBalance'][$feeID] ?? 0.0;
            $pass = $feeBal <= 0.009;
            $message = $pass ? $label . ' passed.' : $label . ' failed (fee balance for ' . $feeID . ': ' . number_format($feeBal, 2, '.', '') . ').';
        } elseif ($ruleType === 'OVERALL_PERCENT_AT_LEAST') {
            $needed = $threshold ?? 0.0;
            $pass = $snapshot['overallPercentPaid'] + 0.0001 >= $needed;
            $message = $pass ? $label . ' passed.' : $label . ' failed (overall paid: ' . number_format($snapshot['overallPercentPaid'], 2, '.', '') . '%, required: ' . number_format($needed, 2, '.', '') . '%).';
        } elseif ($ruleType === 'FEE_PERCENT_AT_LEAST') {
            $needed = $threshold ?? 0.0;
            $feePct = $snapshot['feePercentPaid'][$feeID] ?? 0.0;
            $pass = $feePct + 0.0001 >= $needed;
            $message = $pass ? $label . ' passed.' : $label . ' failed (fee paid for ' . $feeID . ': ' . number_format($feePct, 2, '.', '') . '%, required: ' . number_format($needed, 2, '.', '') . '%).';
        } elseif ($ruleType === 'PROMISSORY_NOTE_ABSENT') {
            $pass = !$snapshot['hasPromissoryNote'];
            $message = $pass ? $label . ' passed.' : $label . ' failed (promissory indicator detected in assessment notes).';
        } else {
            $pass = false;
            $message = 'Unsupported ruleType: ' . $ruleType . '.';
        }

        if ((int)($rule['isNegated'] ?? 0) === 1) {
            $pass = !$pass;
            $message = $pass ? ('NOT(' . $label . ') passed.') : ('NOT(' . $label . ') failed.');
        }

        return [$pass, $message];
    }

    private function resolvePolicy($db, array $ctx, string $period, string $academicYear, string $semester): ?array
    {
        $stmt = $db->prepare(
            "SELECT p.policyID, p.policyName, p.appliesToPeriods, p.activeAcademicYear, p.activeSemester,
                    s.scopeType, s.studentNumber, s.programID, s.yearLevel, s.classCode, s.priorityOrder
             FROM tblExamPermitPolicies p
             JOIN tblExamPermitPolicyScopes s ON s.policyID = p.policyID
             WHERE p.isEnabled = 1
               AND (p.activeAcademicYear IS NULL OR p.activeAcademicYear = '' OR p.activeAcademicYear = :ay)
               AND (p.activeSemester IS NULL OR p.activeSemester = '' OR p.activeSemester = :sem)
               AND (p.appliesToPeriods IS NULL OR p.appliesToPeriods = '' OR FIND_IN_SET(:period, p.appliesToPeriods) > 0)"
        );
        $stmt->execute([
            ':ay' => $academicYear,
            ':sem' => $semester,
            ':period' => $period,
        ]);

        $candidates = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!$this->scopeMatches($row, $ctx)) continue;
            $row['_priority'] = $this->scopePriority($row, $ctx);
            $candidates[] = $row;
        }

        if (!$candidates) return null;

        usort($candidates, function ($a, $b) {
            if ((int)$a['_priority'] === (int)$b['_priority']) {
                return (int)($b['priorityOrder'] ?? 0) <=> (int)($a['priorityOrder'] ?? 0);
            }
            return (int)$b['_priority'] <=> (int)$a['_priority'];
        });

        return $candidates[0] ?? null;
    }

    private function scopeMatches(array $scope, array $ctx): bool
    {
        $type = strtoupper(trim((string)($scope['scopeType'] ?? '')));
        if ($type === 'GLOBAL') return true;
        if ($type === 'TERM') return true;
        if ($type === 'STUDENT') {
            return trim((string)($scope['studentNumber'] ?? '')) === (string)$ctx['studentNumber'];
        }
        if ($type === 'PROGRAM_YEAR') {
            return trim((string)($scope['programID'] ?? '')) === (string)$ctx['programID']
                && trim((string)($scope['yearLevel'] ?? '')) === (string)$ctx['yearLevel'];
        }
        if ($type === 'CLASS') {
            return trim((string)($scope['classCode'] ?? '')) === (string)$ctx['classCode'];
        }
        return false;
    }

    private function scopePriority(array $scope, array $ctx): int
    {
        $type = strtoupper(trim((string)($scope['scopeType'] ?? '')));
        if ($type === 'STUDENT') return 500;
        if ($type === 'CLASS') return 400;
        if ($type === 'PROGRAM_YEAR') return 300;
        if ($type === 'TERM') return 200;
        return 100; // GLOBAL and unknown fallback
    }

    private function loadPolicyGroups($db, string $policyID): array
    {
        $gStmt = $db->prepare("SELECT policyGroupID, groupName, operatorType, isNegated, sortOrder FROM tblExamPermitPolicyGroups WHERE policyID = :pid AND isEnabled = 1 ORDER BY sortOrder");
        $gStmt->execute([':pid' => $policyID]);
        $groups = $gStmt->fetchAll();

        $rStmt = $db->prepare("SELECT policyRuleID, policyGroupID, ruleType, ruleLabel, feeID, thresholdValue, parameterText, isNegated, sortOrder FROM tblExamPermitPolicyRules WHERE policyGroupID = :gid AND isEnabled = 1 ORDER BY sortOrder");

        foreach ($groups as &$group) {
            $rStmt->execute([':gid' => $group['policyGroupID']]);
            $group['rules'] = $rStmt->fetchAll();
        }

        return $groups;
    }

    private function buildFinanceSnapshot($db, string $registrationNumber): array
    {
        $stmt = $db->prepare(
            "SELECT assessmentID, feeID, amount, note
             FROM tblAssessments
             WHERE registrationNumber = :reg
               AND COALESCE(isActive, 1) = 1"
        );
        $stmt->execute([':reg' => $registrationNumber]);
        $assessments = $stmt->fetchAll();

        $totalAssessed = 0.0;
        $feeAssessed = [];
        $assessmentIDs = [];
        $hasPromissoryNote = false;

        foreach ($assessments as $row) {
            $aid = trim((string)($row['assessmentID'] ?? ''));
            if ($aid === '') continue;
            $feeID = trim((string)($row['feeID'] ?? ''));
            $amount = (float)($row['amount'] ?? 0);
            $assessmentIDs[] = $aid;

            $totalAssessed += $amount;
            if (!isset($feeAssessed[$feeID])) $feeAssessed[$feeID] = 0.0;
            $feeAssessed[$feeID] += $amount;

            $note = strtoupper((string)($row['note'] ?? ''));
            if ($note !== '' && strpos($note, 'PROMISSORY') !== false) {
                $hasPromissoryNote = true;
            }
        }

        $paidByAssessment = [];
        if (!empty($assessmentIDs)) {
            $placeholders = implode(',', array_fill(0, count($assessmentIDs), '?'));
            $paidStmt = $db->prepare(
                "SELECT AssessmentID, COALESCE(SUM(Amount), 0) AS paid
                 FROM tblPaymentDetails
                 WHERE AssessmentID IN ($placeholders)
                 GROUP BY AssessmentID"
            );
            $paidStmt->execute($assessmentIDs);
            foreach ($paidStmt->fetchAll() as $row) {
                $paidByAssessment[(string)$row['AssessmentID']] = (float)$row['paid'];
            }
        }

        $totalPaid = 0.0;
        $feePaid = [];
        foreach ($assessments as $row) {
            $aid = trim((string)($row['assessmentID'] ?? ''));
            if ($aid === '') continue;
            $feeID = trim((string)($row['feeID'] ?? ''));
            $paid = $paidByAssessment[$aid] ?? 0.0;
            $totalPaid += $paid;
            if (!isset($feePaid[$feeID])) $feePaid[$feeID] = 0.0;
            $feePaid[$feeID] += $paid;
        }

        $totalBalance = max(0.0, $totalAssessed - $totalPaid);
        $overallPercentPaid = $totalAssessed > 0 ? (($totalPaid / $totalAssessed) * 100.0) : 100.0;

        $feeBalance = [];
        $feePercentPaid = [];
        foreach ($feeAssessed as $feeID => $assessed) {
            $paid = $feePaid[$feeID] ?? 0.0;
            $feeBalance[$feeID] = max(0.0, $assessed - $paid);
            $feePercentPaid[$feeID] = $assessed > 0 ? (($paid / $assessed) * 100.0) : 100.0;
        }

        return [
            'registrationNumber' => $registrationNumber,
            'totalAssessed' => $totalAssessed,
            'totalPaid' => $totalPaid,
            'totalBalance' => $totalBalance,
            'overallPercentPaid' => $overallPercentPaid,
            'feeAssessed' => $feeAssessed,
            'feePaid' => $feePaid,
            'feeBalance' => $feeBalance,
            'feePercentPaid' => $feePercentPaid,
            'hasPromissoryNote' => $hasPromissoryNote,
        ];
    }

    private function getRegistrationContext($db, string $studentNumber, string $academicYear, string $semester): ?array
    {
        $stmt = $db->prepare(
            "SELECT r.RegistrationNumber, r.studentNumber, r.programID, r.yearLevel, r.sectionID,
                    p.programCode, sec.sectionName
             FROM tblRegistrations r
             LEFT JOIN tblPrograms p ON p.programID = r.programID
             LEFT JOIN tblSections sec ON sec.sectionID = r.sectionID
             WHERE r.studentNumber = :sn
               AND r.academicYear = :ay
               AND r.semester = :sem
             ORDER BY r.dateCreated DESC
             LIMIT 1"
        );
        $stmt->execute([':sn' => $studentNumber, ':ay' => $academicYear, ':sem' => $semester]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $programCode = trim((string)($row['programCode'] ?? (string)($row['programID'] ?? '')));
        $yearLevel = trim((string)($row['yearLevel'] ?? ''));
        $sectionName = trim((string)($row['sectionName'] ?? (string)($row['sectionID'] ?? '')));

        return [
            'registrationNumber' => trim((string)($row['RegistrationNumber'] ?? '')),
            'studentNumber' => trim((string)($row['studentNumber'] ?? '')),
            'programID' => trim((string)($row['programID'] ?? '')),
            'yearLevel' => $yearLevel,
            'sectionID' => trim((string)($row['sectionID'] ?? '')),
            'programCode' => $programCode,
            'sectionName' => $sectionName,
            'classCode' => $this->buildClassCode($programCode, $yearLevel, $sectionName),
        ];
    }

    private function buildClassCode(string $programCode, string $yearLevel, string $sectionName): string
    {
        $digit = '0';
        if (preg_match('/(\d+)/', $yearLevel, $m)) {
            $digit = substr((string)$m[1], 0, 1);
        }
        return strtoupper($programCode) . $digit . '-' . strtoupper($sectionName);
    }

    private function findIssuedPermit($db, string $studentNumber, string $academicYear, string $semester, string $period): ?array
    {
        $stmt = $db->prepare(
            "SELECT *
             FROM tblExamPermits
             WHERE studentNumber = :sn
               AND academicYear = :ay
               AND semester = :sem
               AND period = :period
               AND status = 'ISSUED'
             ORDER BY generatedAt DESC, permitID DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':sn' => $studentNumber,
            ':ay' => $academicYear,
            ':sem' => $semester,
            ':period' => $period,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function getPermitById($db, string $permitID): ?array
    {
        $stmt = $db->prepare("SELECT * FROM tblExamPermits WHERE permitID = :permitID LIMIT 1");
        $stmt->execute([':permitID' => $permitID]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function resolveActorName($db, string $email): string
    {
        $stmt = $db->prepare("SELECT FullName FROM tblUsers WHERE Email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
        $name = trim((string)($row['FullName'] ?? ''));
        return $name !== '' ? $name : $email;
    }

    private function writeAudit($db, array $payload, bool $reuseTransaction = false): void
    {
        $owns = !$reuseTransaction && !$db->inTransaction();
        try {
            if ($owns) $db->beginTransaction();

            $seq = SequenceGenerator::reserveIdBlock($db, 'tblExamPermitAudit', 1);
            $auditID = SequenceGenerator::formatId('EPA', (int)$seq['firstNo'], 7);

            $stmt = $db->prepare(
                "INSERT INTO tblExamPermitAudit
                (auditID, permitID, studentNumber, registrationNumber, academicYear, semester, period,
                 actionType, outcome, actorEmail, actorName, detail, createdAt)
                VALUES
                (:auditID, :permitID, :studentNumber, :registrationNumber, :academicYear, :semester, :period,
                 :actionType, :outcome, :actorEmail, :actorName, :detail, :createdAt)"
            );
            $stmt->execute([
                ':auditID' => $auditID,
                ':permitID' => $payload['permitID'] ?? null,
                ':studentNumber' => $payload['studentNumber'] ?? null,
                ':registrationNumber' => $payload['registrationNumber'] ?? null,
                ':academicYear' => $payload['academicYear'] ?? null,
                ':semester' => $payload['semester'] ?? null,
                ':period' => $payload['period'] ?? null,
                ':actionType' => (string)($payload['actionType'] ?? 'UNKNOWN'),
                ':outcome' => (string)($payload['outcome'] ?? 'FAILED'),
                ':actorEmail' => (string)($payload['actorEmail'] ?? ''),
                ':actorName' => (string)($payload['actorName'] ?? ''),
                ':detail' => (string)($payload['detail'] ?? ''),
                ':createdAt' => date('Y-m-d H:i:s'),
            ]);

            if ($owns) $db->commit();
        } catch (\Throwable $e) {
            if ($owns && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private function error(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
