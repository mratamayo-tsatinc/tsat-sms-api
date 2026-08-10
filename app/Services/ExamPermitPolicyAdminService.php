<?php

namespace App\Services;

use App\Core\Database;
use App\Models\SequenceGenerator;

class ExamPermitPolicyAdminService
{
    private const VALID_PERIODS = ['PRELIM', 'MIDTERM', 'SEMIFINALS', 'FINALS'];
    private const VALID_SCOPE_TYPES = ['GLOBAL', 'TERM', 'STUDENT', 'PROGRAM_YEAR', 'CLASS'];
    private const VALID_OPERATORS = ['AND', 'OR'];

    public function bootstrap(array $input): array
    {
        $academicYear = trim((string)($input['academicYear'] ?? ''));
        $semester = trim((string)($input['semester'] ?? ''));

        if ($academicYear === '' || $semester === '') {
            $term = (new ReferenceDataService())->getActiveTerm();
            $academicYear = (string)$term['academicYear'];
            $semester = (string)$term['semester'];
        }

        $db = Database::getConnection();

        return [
            'ok' => true,
            'activeTerm' => [
                'academicYear' => $academicYear,
                'semester' => $semester,
            ],
            'programs' => $this->getEnrolledPrograms($db, $academicYear, $semester),
            'classes' => $this->getEnrolledClasses($db, $academicYear, $semester),
            'yearLevels' => $this->getEnrolledYearLevels($db, $academicYear, $semester),
        ];
    }

    public function listPolicies(): array
    {
        $db = Database::getConnection();

        $policies = $db->query(
            "SELECT policyID, policyName, description, activeAcademicYear, activeSemester, appliesToPeriods,
                    isEnabled, createdBy, dateCreated, modifiedBy, lastModified
             FROM tblExamPermitPolicies
             ORDER BY isEnabled DESC, policyName"
        )->fetchAll();

        $scopeStmt = $db->prepare(
            "SELECT policyScopeID, policyID, scopeType, studentNumber, programID, yearLevel, classCode,
                    priorityOrder, createdBy, dateCreated, modifiedBy, lastModified
             FROM tblExamPermitPolicyScopes
             WHERE policyID = :policyID
             ORDER BY priorityOrder DESC, policyScopeID
             LIMIT 1"
        );

        $groupStmt = $db->prepare(
            "SELECT policyGroupID, policyID, groupName, operatorType, isNegated, sortOrder, description,
                    isEnabled, createdBy, dateCreated, modifiedBy, lastModified
             FROM tblExamPermitPolicyGroups
             WHERE policyID = :policyID
             ORDER BY sortOrder, policyGroupID"
        );

        $ruleStmt = $db->prepare(
            "SELECT policyRuleID, policyGroupID, ruleType, ruleLabel, feeID, thresholdValue, parameterText,
                    isNegated, sortOrder, isEnabled, createdBy, dateCreated, modifiedBy, lastModified
             FROM tblExamPermitPolicyRules
             WHERE policyGroupID = :groupID
             ORDER BY sortOrder, policyRuleID"
        );

        $out = [];
        foreach ($policies as $policy) {
            $policyID = (string)$policy['policyID'];

            $scopeStmt->execute([':policyID' => $policyID]);
            $scope = $scopeStmt->fetch() ?: null;

            $groupStmt->execute([':policyID' => $policyID]);
            $groups = $groupStmt->fetchAll();
            foreach ($groups as &$group) {
                $ruleStmt->execute([':groupID' => (string)$group['policyGroupID']]);
                $group['rules'] = $ruleStmt->fetchAll();
            }

            $out[] = [
                'policyID' => $policyID,
                'policyName' => (string)$policy['policyName'],
                'description' => (string)($policy['description'] ?? ''),
                'activeAcademicYear' => (string)($policy['activeAcademicYear'] ?? ''),
                'activeSemester' => (string)($policy['activeSemester'] ?? ''),
                'appliesToPeriods' => $this->splitPeriods((string)($policy['appliesToPeriods'] ?? '')),
                'isEnabled' => (int)($policy['isEnabled'] ?? 0) === 1,
                'scope' => $scope,
                'groups' => $groups,
                'createdBy' => (string)($policy['createdBy'] ?? ''),
                'dateCreated' => (string)($policy['dateCreated'] ?? ''),
                'modifiedBy' => (string)($policy['modifiedBy'] ?? ''),
                'lastModified' => (string)($policy['lastModified'] ?? ''),
            ];
        }

        return ['ok' => true, 'policies' => $out];
    }

    public function policyAuditTrail(array $input): array
    {
        $policyID = trim((string)($input['policyID'] ?? ''));
        $limit = (int)($input['limit'] ?? 40);
        if ($limit < 1) $limit = 1;
        if ($limit > 200) $limit = 200;

        $db = Database::getConnection();

        $where = "actionType LIKE 'POLICY_%'";
        $params = [];
        if ($policyID !== '') {
            $where .= ' AND permitID = :policyID';
            $params[':policyID'] = $policyID;
        }

        $sql = "SELECT auditID, permitID, actionType, outcome, actorEmail, actorName, detail, createdAt
                FROM tblExamPermitAudit
                WHERE $where
                ORDER BY createdAt DESC, auditID DESC
                LIMIT $limit";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return [
            'ok' => true,
            'rows' => array_map(function ($r) {
                return [
                    'auditID' => (string)($r['auditID'] ?? ''),
                    'policyID' => (string)($r['permitID'] ?? ''),
                    'actionType' => (string)($r['actionType'] ?? ''),
                    'outcome' => (string)($r['outcome'] ?? ''),
                    'actorEmail' => (string)($r['actorEmail'] ?? ''),
                    'actorName' => (string)($r['actorName'] ?? ''),
                    'detail' => (string)($r['detail'] ?? ''),
                    'createdAt' => (string)($r['createdAt'] ?? ''),
                ];
            }, $rows),
        ];
    }

    public function savePolicy(array $input): array
    {
        $actorEmail = trim((string)($input['actorEmail'] ?? ''));
        $policyName = trim((string)($input['policyName'] ?? ''));
        $policyID = trim((string)($input['policyID'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $activeAcademicYear = trim((string)($input['activeAcademicYear'] ?? ''));
        $activeSemester = trim((string)($input['activeSemester'] ?? ''));
        $isEnabled = !empty($input['isEnabled']) ? 1 : 0;
        $scope = is_array($input['scope'] ?? null) ? $input['scope'] : [];
        $groups = is_array($input['groups'] ?? null) ? $input['groups'] : [];

        if ($actorEmail === '' || $policyName === '') {
            return $this->error('VALIDATION_ERROR', 'actorEmail and policyName are required.');
        }

        $periodsRaw = is_array($input['appliesToPeriods'] ?? null) ? $input['appliesToPeriods'] : [];
        $periods = [];
        foreach ($periodsRaw as $p) {
            $v = strtoupper(trim((string)$p));
            if ($v === '') continue;
            if (!in_array($v, self::VALID_PERIODS, true)) {
                return $this->error('VALIDATION_ERROR', 'Invalid period in appliesToPeriods: ' . $v);
            }
            if (!in_array($v, $periods, true)) $periods[] = $v;
        }
        if (empty($periods)) {
            return $this->error('VALIDATION_ERROR', 'At least one appliesToPeriod is required.');
        }

        $scopeType = strtoupper(trim((string)($scope['scopeType'] ?? '')));
        if (!in_array($scopeType, self::VALID_SCOPE_TYPES, true)) {
            return $this->error('VALIDATION_ERROR', 'scope.scopeType is required and must be one of GLOBAL, TERM, STUDENT, PROGRAM_YEAR, CLASS.');
        }

        $db = Database::getConnection();

        try {
            $db->beginTransaction();
            $now = date('Y-m-d H:i:s');

            $isCreate = false;
            if ($policyID === '') {
                $isCreate = true;
                $seq = SequenceGenerator::reserveIdBlock($db, 'tblExamPermitPolicies', 1);
                $policyID = SequenceGenerator::formatId('EPP', (int)$seq['firstNo'], 7);

                $ins = $db->prepare(
                    "INSERT INTO tblExamPermitPolicies
                    (policyID, policyName, description, activeAcademicYear, activeSemester, appliesToPeriods,
                     isEnabled, createdBy, dateCreated, modifiedBy, lastModified)
                    VALUES
                    (:policyID, :policyName, :description, :activeAcademicYear, :activeSemester, :appliesToPeriods,
                     :isEnabled, :createdBy, :dateCreated, :modifiedBy, :lastModified)"
                );
                $ins->execute([
                    ':policyID' => $policyID,
                    ':policyName' => $policyName,
                    ':description' => $description,
                    ':activeAcademicYear' => $activeAcademicYear !== '' ? $activeAcademicYear : null,
                    ':activeSemester' => $activeSemester !== '' ? $activeSemester : null,
                    ':appliesToPeriods' => implode(',', $periods),
                    ':isEnabled' => $isEnabled,
                    ':createdBy' => $actorEmail,
                    ':dateCreated' => $now,
                    ':modifiedBy' => $actorEmail,
                    ':lastModified' => $now,
                ]);
            } else {
                $upd = $db->prepare(
                    "UPDATE tblExamPermitPolicies
                     SET policyName = :policyName,
                         description = :description,
                         activeAcademicYear = :activeAcademicYear,
                         activeSemester = :activeSemester,
                         appliesToPeriods = :appliesToPeriods,
                         isEnabled = :isEnabled,
                         modifiedBy = :modifiedBy,
                         lastModified = :lastModified
                     WHERE policyID = :policyID"
                );
                $upd->execute([
                    ':policyName' => $policyName,
                    ':description' => $description,
                    ':activeAcademicYear' => $activeAcademicYear !== '' ? $activeAcademicYear : null,
                    ':activeSemester' => $activeSemester !== '' ? $activeSemester : null,
                    ':appliesToPeriods' => implode(',', $periods),
                    ':isEnabled' => $isEnabled,
                    ':modifiedBy' => $actorEmail,
                    ':lastModified' => $now,
                    ':policyID' => $policyID,
                ]);
            }

            $this->upsertScope($db, $policyID, $scope, $actorEmail, $now);
            $this->upsertGroupsAndRules($db, $policyID, $groups, $actorEmail, $now);

            $this->writePolicyAudit($db, $isCreate ? 'POLICY_CREATE' : 'POLICY_EDIT', 'SUCCESS', $actorEmail, 'Policy ' . $policyID . ' saved.', $policyID);

            $db->commit();

            return [
                'ok' => true,
                'policyID' => $policyID,
                'message' => $isCreate ? 'Policy created.' : 'Policy updated.',
            ];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public function setPolicyEnabled(array $input): array
    {
        $policyID = trim((string)($input['policyID'] ?? ''));
        $actorEmail = trim((string)($input['actorEmail'] ?? ''));
        $isEnabled = !empty($input['isEnabled']) ? 1 : 0;

        if ($policyID === '' || $actorEmail === '') {
            return $this->error('VALIDATION_ERROR', 'policyID and actorEmail are required.');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE tblExamPermitPolicies SET isEnabled = :isEnabled, modifiedBy = :by, lastModified = :at WHERE policyID = :policyID");
        $stmt->execute([
            ':isEnabled' => $isEnabled,
            ':by' => $actorEmail,
            ':at' => date('Y-m-d H:i:s'),
            ':policyID' => $policyID,
        ]);

        $this->writePolicyAudit($db, 'POLICY_ENABLE', 'SUCCESS', $actorEmail, ($isEnabled ? 'Enabled ' : 'Disabled ') . 'policy ' . $policyID . '.', $policyID);

        return ['ok' => true, 'message' => 'Policy status updated.'];
    }

    public function reorderGroups(array $input): array
    {
        $policyID = trim((string)($input['policyID'] ?? ''));
        $actorEmail = trim((string)($input['actorEmail'] ?? ''));
        $orderedGroupIDs = is_array($input['orderedGroupIDs'] ?? null) ? $input['orderedGroupIDs'] : [];

        if ($policyID === '' || $actorEmail === '' || empty($orderedGroupIDs)) {
            return $this->error('VALIDATION_ERROR', 'policyID, actorEmail, and orderedGroupIDs are required.');
        }

        $db = Database::getConnection();
        $upd = $db->prepare("UPDATE tblExamPermitPolicyGroups SET sortOrder = :sortOrder, modifiedBy = :by, lastModified = :at WHERE policyID = :policyID AND policyGroupID = :groupID");
        $i = 1;
        $now = date('Y-m-d H:i:s');
        foreach ($orderedGroupIDs as $groupID) {
            $gid = trim((string)$groupID);
            if ($gid === '') continue;
            $upd->execute([
                ':sortOrder' => $i++,
                ':by' => $actorEmail,
                ':at' => $now,
                ':policyID' => $policyID,
                ':groupID' => $gid,
            ]);
        }

        $this->writePolicyAudit($db, 'POLICY_GROUP_REORDER', 'SUCCESS', $actorEmail, 'Reordered groups for policy ' . $policyID . '.', $policyID);
        return ['ok' => true, 'message' => 'Group order updated.'];
    }

    public function reorderRules(array $input): array
    {
        $policyGroupID = trim((string)($input['policyGroupID'] ?? ''));
        $actorEmail = trim((string)($input['actorEmail'] ?? ''));
        $orderedRuleIDs = is_array($input['orderedRuleIDs'] ?? null) ? $input['orderedRuleIDs'] : [];

        if ($policyGroupID === '' || $actorEmail === '' || empty($orderedRuleIDs)) {
            return $this->error('VALIDATION_ERROR', 'policyGroupID, actorEmail, and orderedRuleIDs are required.');
        }

        $db = Database::getConnection();
        $upd = $db->prepare("UPDATE tblExamPermitPolicyRules SET sortOrder = :sortOrder, modifiedBy = :by, lastModified = :at WHERE policyGroupID = :groupID AND policyRuleID = :ruleID");
        $i = 1;
        $now = date('Y-m-d H:i:s');
        foreach ($orderedRuleIDs as $ruleID) {
            $rid = trim((string)$ruleID);
            if ($rid === '') continue;
            $upd->execute([
                ':sortOrder' => $i++,
                ':by' => $actorEmail,
                ':at' => $now,
                ':groupID' => $policyGroupID,
                ':ruleID' => $rid,
            ]);
        }

        $this->writePolicyAudit($db, 'POLICY_RULE_REORDER', 'SUCCESS', $actorEmail, 'Reordered rules for group ' . $policyGroupID . '.', null);
        return ['ok' => true, 'message' => 'Rule order updated.'];
    }

    private function upsertScope($db, string $policyID, array $scope, string $actorEmail, string $now): void
    {
        $scopeType = strtoupper(trim((string)($scope['scopeType'] ?? 'GLOBAL')));
        $studentNumber = trim((string)($scope['studentNumber'] ?? ''));
        $programID = trim((string)($scope['programID'] ?? ''));
        $yearLevel = trim((string)($scope['yearLevel'] ?? ''));
        $classCode = trim((string)($scope['classCode'] ?? ''));
        $priorityOrder = (int)($scope['priorityOrder'] ?? 1);

        $existingStmt = $db->prepare("SELECT policyScopeID FROM tblExamPermitPolicyScopes WHERE policyID = :policyID ORDER BY priorityOrder DESC, policyScopeID LIMIT 1");
        $existingStmt->execute([':policyID' => $policyID]);
        $existing = $existingStmt->fetch();

        if ($existing) {
            $upd = $db->prepare(
                "UPDATE tblExamPermitPolicyScopes
                 SET scopeType = :scopeType,
                     studentNumber = :studentNumber,
                     programID = :programID,
                     yearLevel = :yearLevel,
                     classCode = :classCode,
                     priorityOrder = :priorityOrder,
                     modifiedBy = :modifiedBy,
                     lastModified = :lastModified
                 WHERE policyScopeID = :policyScopeID"
            );
            $upd->execute([
                ':scopeType' => $scopeType,
                ':studentNumber' => $studentNumber !== '' ? $studentNumber : null,
                ':programID' => $programID !== '' ? $programID : null,
                ':yearLevel' => $yearLevel !== '' ? $yearLevel : null,
                ':classCode' => $classCode !== '' ? $classCode : null,
                ':priorityOrder' => $priorityOrder > 0 ? $priorityOrder : 1,
                ':modifiedBy' => $actorEmail,
                ':lastModified' => $now,
                ':policyScopeID' => (string)$existing['policyScopeID'],
            ]);
            return;
        }

        $scopeSeq = SequenceGenerator::reserveIdBlock($db, 'tblExamPermitPolicyScopes', 1);
        $policyScopeID = SequenceGenerator::formatId('EPS', (int)$scopeSeq['firstNo'], 7);

        $ins = $db->prepare(
            "INSERT INTO tblExamPermitPolicyScopes
            (policyScopeID, policyID, scopeType, studentNumber, programID, yearLevel, classCode,
             priorityOrder, createdBy, dateCreated, modifiedBy, lastModified)
            VALUES
            (:policyScopeID, :policyID, :scopeType, :studentNumber, :programID, :yearLevel, :classCode,
             :priorityOrder, :createdBy, :dateCreated, :modifiedBy, :lastModified)"
        );
        $ins->execute([
            ':policyScopeID' => $policyScopeID,
            ':policyID' => $policyID,
            ':scopeType' => $scopeType,
            ':studentNumber' => $studentNumber !== '' ? $studentNumber : null,
            ':programID' => $programID !== '' ? $programID : null,
            ':yearLevel' => $yearLevel !== '' ? $yearLevel : null,
            ':classCode' => $classCode !== '' ? $classCode : null,
            ':priorityOrder' => $priorityOrder > 0 ? $priorityOrder : 1,
            ':createdBy' => $actorEmail,
            ':dateCreated' => $now,
            ':modifiedBy' => $actorEmail,
            ':lastModified' => $now,
        ]);
    }

    private function upsertGroupsAndRules($db, string $policyID, array $groups, string $actorEmail, string $now): void
    {
        $keptGroupIDs = [];

        foreach ($groups as $gIndex => $group) {
            if (!is_array($group)) continue;

            $groupID = trim((string)($group['policyGroupID'] ?? ''));
            $groupName = trim((string)($group['groupName'] ?? ''));
            $operatorType = strtoupper(trim((string)($group['operatorType'] ?? 'AND')));
            $isNegated = !empty($group['isNegated']) ? 1 : 0;
            $description = trim((string)($group['description'] ?? ''));
            $isEnabled = !empty($group['isEnabled']) ? 1 : 0;
            $sortOrder = $gIndex + 1;

            if ($groupName === '') $groupName = 'Group ' . $sortOrder;
            if (!in_array($operatorType, self::VALID_OPERATORS, true)) $operatorType = 'AND';

            if ($groupID === '') {
                $seq = SequenceGenerator::reserveIdBlock($db, 'tblExamPermitPolicyGroups', 1);
                $groupID = SequenceGenerator::formatId('EPG', (int)$seq['firstNo'], 7);
                $ins = $db->prepare(
                    "INSERT INTO tblExamPermitPolicyGroups
                    (policyGroupID, policyID, groupName, operatorType, isNegated, sortOrder, description,
                     isEnabled, createdBy, dateCreated, modifiedBy, lastModified)
                    VALUES
                    (:policyGroupID, :policyID, :groupName, :operatorType, :isNegated, :sortOrder, :description,
                     :isEnabled, :createdBy, :dateCreated, :modifiedBy, :lastModified)"
                );
                $ins->execute([
                    ':policyGroupID' => $groupID,
                    ':policyID' => $policyID,
                    ':groupName' => $groupName,
                    ':operatorType' => $operatorType,
                    ':isNegated' => $isNegated,
                    ':sortOrder' => $sortOrder,
                    ':description' => $description,
                    ':isEnabled' => $isEnabled,
                    ':createdBy' => $actorEmail,
                    ':dateCreated' => $now,
                    ':modifiedBy' => $actorEmail,
                    ':lastModified' => $now,
                ]);
            } else {
                $upd = $db->prepare(
                    "UPDATE tblExamPermitPolicyGroups
                     SET groupName = :groupName,
                         operatorType = :operatorType,
                         isNegated = :isNegated,
                         sortOrder = :sortOrder,
                         description = :description,
                         isEnabled = :isEnabled,
                         modifiedBy = :modifiedBy,
                         lastModified = :lastModified
                     WHERE policyID = :policyID AND policyGroupID = :policyGroupID"
                );
                $upd->execute([
                    ':groupName' => $groupName,
                    ':operatorType' => $operatorType,
                    ':isNegated' => $isNegated,
                    ':sortOrder' => $sortOrder,
                    ':description' => $description,
                    ':isEnabled' => $isEnabled,
                    ':modifiedBy' => $actorEmail,
                    ':lastModified' => $now,
                    ':policyID' => $policyID,
                    ':policyGroupID' => $groupID,
                ]);
            }

            $keptGroupIDs[] = $groupID;
            $this->upsertRulesForGroup($db, $groupID, (array)($group['rules'] ?? []), $actorEmail, $now);
        }

        if (empty($keptGroupIDs)) {
            $db->prepare("DELETE FROM tblExamPermitPolicyRules WHERE policyGroupID IN (SELECT policyGroupID FROM tblExamPermitPolicyGroups WHERE policyID = :policyID)")
                ->execute([':policyID' => $policyID]);
            $db->prepare("DELETE FROM tblExamPermitPolicyGroups WHERE policyID = :policyID")
                ->execute([':policyID' => $policyID]);
            return;
        }

        $gPlaceholders = implode(',', array_fill(0, count($keptGroupIDs), '?'));

        $toDeleteStmt = $db->prepare("SELECT policyGroupID FROM tblExamPermitPolicyGroups WHERE policyID = ? AND policyGroupID NOT IN ($gPlaceholders)");
        $toDeleteStmt->execute(array_merge([$policyID], $keptGroupIDs));
        $toDeleteGroupIDs = array_map(fn($r) => (string)$r['policyGroupID'], $toDeleteStmt->fetchAll());

        if (!empty($toDeleteGroupIDs)) {
            $dPlaceholders = implode(',', array_fill(0, count($toDeleteGroupIDs), '?'));
            $db->prepare("DELETE FROM tblExamPermitPolicyRules WHERE policyGroupID IN ($dPlaceholders)")
                ->execute($toDeleteGroupIDs);
        }

        $deleteGroups = $db->prepare("DELETE FROM tblExamPermitPolicyGroups WHERE policyID = ? AND policyGroupID NOT IN ($gPlaceholders)");
        $deleteGroups->execute(array_merge([$policyID], $keptGroupIDs));
    }

    private function upsertRulesForGroup($db, string $policyGroupID, array $rules, string $actorEmail, string $now): void
    {
        $keptRuleIDs = [];

        foreach ($rules as $rIndex => $rule) {
            if (!is_array($rule)) continue;
            $ruleID = trim((string)($rule['policyRuleID'] ?? ''));
            $ruleType = strtoupper(trim((string)($rule['ruleType'] ?? '')));
            $ruleLabel = trim((string)($rule['ruleLabel'] ?? ''));
            $feeID = trim((string)($rule['feeID'] ?? ''));
            $thresholdValue = $rule['thresholdValue'] ?? null;
            $parameterText = trim((string)($rule['parameterText'] ?? ''));
            $isNegated = !empty($rule['isNegated']) ? 1 : 0;
            $isEnabled = !empty($rule['isEnabled']) ? 1 : 0;
            $sortOrder = $rIndex + 1;

            if ($ruleType === '') $ruleType = 'TOTAL_BALANCE_ZERO';
            if ($ruleLabel === '') $ruleLabel = $ruleType;

            $threshold = null;
            if ($thresholdValue !== null && $thresholdValue !== '') {
                $threshold = (float)$thresholdValue;
            }

            if ($ruleID === '') {
                $seq = SequenceGenerator::reserveIdBlock($db, 'tblExamPermitPolicyRules', 1);
                $ruleID = SequenceGenerator::formatId('EPRL', (int)$seq['firstNo'], 7);
                $ins = $db->prepare(
                    "INSERT INTO tblExamPermitPolicyRules
                    (policyRuleID, policyGroupID, ruleType, ruleLabel, feeID, thresholdValue, parameterText,
                     isNegated, sortOrder, isEnabled, createdBy, dateCreated, modifiedBy, lastModified)
                    VALUES
                    (:policyRuleID, :policyGroupID, :ruleType, :ruleLabel, :feeID, :thresholdValue, :parameterText,
                     :isNegated, :sortOrder, :isEnabled, :createdBy, :dateCreated, :modifiedBy, :lastModified)"
                );
                $ins->execute([
                    ':policyRuleID' => $ruleID,
                    ':policyGroupID' => $policyGroupID,
                    ':ruleType' => $ruleType,
                    ':ruleLabel' => $ruleLabel,
                    ':feeID' => $feeID !== '' ? $feeID : null,
                    ':thresholdValue' => $threshold,
                    ':parameterText' => $parameterText !== '' ? $parameterText : null,
                    ':isNegated' => $isNegated,
                    ':sortOrder' => $sortOrder,
                    ':isEnabled' => $isEnabled,
                    ':createdBy' => $actorEmail,
                    ':dateCreated' => $now,
                    ':modifiedBy' => $actorEmail,
                    ':lastModified' => $now,
                ]);
            } else {
                $upd = $db->prepare(
                    "UPDATE tblExamPermitPolicyRules
                     SET ruleType = :ruleType,
                         ruleLabel = :ruleLabel,
                         feeID = :feeID,
                         thresholdValue = :thresholdValue,
                         parameterText = :parameterText,
                         isNegated = :isNegated,
                         sortOrder = :sortOrder,
                         isEnabled = :isEnabled,
                         modifiedBy = :modifiedBy,
                         lastModified = :lastModified
                     WHERE policyGroupID = :policyGroupID AND policyRuleID = :policyRuleID"
                );
                $upd->execute([
                    ':ruleType' => $ruleType,
                    ':ruleLabel' => $ruleLabel,
                    ':feeID' => $feeID !== '' ? $feeID : null,
                    ':thresholdValue' => $threshold,
                    ':parameterText' => $parameterText !== '' ? $parameterText : null,
                    ':isNegated' => $isNegated,
                    ':sortOrder' => $sortOrder,
                    ':isEnabled' => $isEnabled,
                    ':modifiedBy' => $actorEmail,
                    ':lastModified' => $now,
                    ':policyGroupID' => $policyGroupID,
                    ':policyRuleID' => $ruleID,
                ]);
            }

            $keptRuleIDs[] = $ruleID;
        }

        if (empty($keptRuleIDs)) {
            $db->prepare("DELETE FROM tblExamPermitPolicyRules WHERE policyGroupID = :groupID")
                ->execute([':groupID' => $policyGroupID]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keptRuleIDs), '?'));
        $delete = $db->prepare("DELETE FROM tblExamPermitPolicyRules WHERE policyGroupID = ? AND policyRuleID NOT IN ($placeholders)");
        $delete->execute(array_merge([$policyGroupID], $keptRuleIDs));
    }

    private function writePolicyAudit($db, string $actionType, string $outcome, string $actorEmail, string $detail, ?string $policyID): void
    {
        $seq = SequenceGenerator::reserveIdBlock($db, 'tblExamPermitAudit', 1);
        $auditID = SequenceGenerator::formatId('EPA', (int)$seq['firstNo'], 7);

        $actorName = $this->resolveActorName($db, $actorEmail);

        $stmt = $db->prepare(
            "INSERT INTO tblExamPermitAudit
            (auditID, permitID, studentNumber, registrationNumber, academicYear, semester, period,
             actionType, outcome, actorEmail, actorName, detail, createdAt)
            VALUES
            (:auditID, :permitID, NULL, NULL, NULL, NULL, NULL,
             :actionType, :outcome, :actorEmail, :actorName, :detail, :createdAt)"
        );
        $stmt->execute([
            ':auditID' => $auditID,
            ':permitID' => $policyID,
            ':actionType' => $actionType,
            ':outcome' => $outcome,
            ':actorEmail' => $actorEmail,
            ':actorName' => $actorName,
            ':detail' => $detail,
            ':createdAt' => date('Y-m-d H:i:s'),
        ]);
    }

    private function resolveActorName($db, string $email): string
    {
        $stmt = $db->prepare("SELECT FullName FROM tblUsers WHERE Email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
        $name = trim((string)($row['FullName'] ?? ''));
        return $name !== '' ? $name : $email;
    }

    private function splitPeriods(string $raw): array
    {
        $parts = array_map(fn($p) => strtoupper(trim((string)$p)), explode(',', $raw));
        return array_values(array_filter($parts, fn($p) => $p !== ''));
    }

    private function getEnrolledPrograms($db, string $academicYear, string $semester): array
    {
        $stmt = $db->prepare(
            "SELECT DISTINCT r.programID, p.programCode, p.programDescription
             FROM tblRegistrations r
             JOIN tblPrograms p ON p.programID = r.programID
             WHERE r.academicYear = ? AND r.semester = ?
               AND r.programID IS NOT NULL AND r.programID <> ''"
        );
        $stmt->execute([$academicYear, $semester]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $programID = trim((string)($row['programID'] ?? ''));
            if ($programID === '') continue;
            $code = trim((string)($row['programCode'] ?? ''));
            $desc = trim((string)($row['programDescription'] ?? ''));
            $out[] = [
                'programID' => $programID,
                'programCode' => $code,
                'programDescription' => $desc,
                'label' => $desc !== '' && $code !== '' ? ($desc . ' (' . $code . ')') : ($code !== '' ? $code : $programID),
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }

    private function getEnrolledYearLevels($db, string $academicYear, string $semester): array
    {
        $stmt = $db->prepare(
            "SELECT DISTINCT yearLevel
             FROM tblRegistrations
             WHERE academicYear = ? AND semester = ?
               AND yearLevel IS NOT NULL AND yearLevel <> ''
             ORDER BY yearLevel"
        );
        $stmt->execute([$academicYear, $semester]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $yl = trim((string)($row['yearLevel'] ?? ''));
            if ($yl !== '') $out[] = $yl;
        }
        return $out;
    }

    private function getEnrolledClasses($db, string $academicYear, string $semester): array
    {
        $stmt = $db->prepare(
            "SELECT DISTINCT r.programID, r.yearLevel, r.sectionID, p.programCode, sec.sectionName
             FROM tblRegistrations r
             LEFT JOIN tblPrograms p ON p.programID = r.programID
             LEFT JOIN tblSections sec ON sec.sectionID = r.sectionID
             WHERE r.academicYear = ? AND r.semester = ?
               AND r.programID IS NOT NULL AND r.programID <> ''
               AND r.sectionID IS NOT NULL AND r.sectionID <> ''
               AND r.yearLevel IS NOT NULL AND r.yearLevel <> ''"
        );
        $stmt->execute([$academicYear, $semester]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $programID = trim((string)($row['programID'] ?? ''));
            $yearLevel = trim((string)($row['yearLevel'] ?? ''));
            $sectionID = trim((string)($row['sectionID'] ?? ''));
            $programCode = trim((string)($row['programCode'] ?? $programID));
            $sectionName = trim((string)($row['sectionName'] ?? $sectionID));
            if ($programID === '' || $yearLevel === '' || $sectionID === '') continue;
            $out[] = [
                'programID' => $programID,
                'yearLevel' => $yearLevel,
                'sectionID' => $sectionID,
                'classCode' => $this->buildClassCode($programCode, $yearLevel, $sectionName),
            ];
        }

        usort($out, fn($a, $b) => strcmp($a['classCode'], $b['classCode']));
        return $out;
    }

    private function buildClassCode(string $programCode, string $yearLevel, string $sectionName): string
    {
        $digit = '0';
        if (preg_match('/(\d+)/', $yearLevel, $m)) {
            $digit = substr((string)$m[1], 0, 1);
        }
        return strtoupper($programCode) . $digit . '-' . strtoupper($sectionName);
    }

    private function error(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
