<?php

namespace App\Controllers\SubjectLoading;

use App\Core\Database;
use App\Models\SequenceGenerator;

/**
 * Subject record management (CRUD, minimal fields).
 * Same shape as TeacherController (§5.2), keyed on subjectCode uniqueness
 * (same blank-to-NULL normalization rule as tblTeachers.emailAddress).
 */
class SubjectController
{
    /**
     * GET /api/subject-loading/subjects
     * Optional ?activeOnly=1.
     */
    public function list()
    {
        try {
            $db = Database::getConnection();
            $activeOnly = $this->_truthy($_GET['activeOnly'] ?? null);

            $sql = "
                SELECT subjectID, subjectCode, subjectTitle, lecUnits, labUnits, isActive,
                       createdBy, dateCreated, modifiedBy, lastModified
                FROM tblSubjects
            ";
            if ($activeOnly) {
                $sql .= " WHERE isActive = 1 ";
            }
            $sql .= " ORDER BY subjectCode, subjectTitle ";

            $stmt = $db->query($sql);
            $rows = $stmt->fetchAll();

            $subjects = array_map([$this, '_toApiRow'], $rows);

            echo json_encode(['ok' => true, 'subjects' => $subjects]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/subject-loading/subjects
     * Body: { subjectCode?, subjectTitle, lecUnits?, labUnits?, createdBy }
     */
    public function store()
    {
        try {
            $body = $this->_readJsonBody();

            $subjectTitle = trim((string)($body['subjectTitle'] ?? ''));
            $subjectCodeRaw = trim((string)($body['subjectCode'] ?? ''));
            $createdBy = trim((string)($body['createdBy'] ?? ''));

            if ($subjectTitle === '') {
                $this->_validationError('Subject title is required.', ['subjectTitle' => 'Required.']);
                return;
            }

            [$lecUnits, $labUnits, $unitsError] = $this->_parseUnits($body);
            if ($unitsError) {
                $this->_validationError($unitsError['message'], $unitsError['fields']);
                return;
            }

            $subjectCode = $this->_nullIfBlank($subjectCodeRaw);

            $db = Database::getConnection();
            $seq = SequenceGenerator::reserveIdBlock($db, 'tblSubjects', 1);
            $subjectID = SequenceGenerator::formatId('SUB', $seq['firstNo'], 6);

            $now = $this->_nowString();

            try {
                $stmt = $db->prepare("
                    INSERT INTO tblSubjects
                        (subjectID, subjectCode, subjectTitle, lecUnits, labUnits, isActive,
                         createdBy, dateCreated, modifiedBy, lastModified)
                    VALUES
                        (:subjectID, :subjectCode, :subjectTitle, :lecUnits, :labUnits, 1,
                         :createdBy, :dateCreated, :createdBy2, :dateCreated2)
                ");
                $stmt->execute([
                    ':subjectID'     => $subjectID,
                    ':subjectCode'   => $subjectCode,
                    ':subjectTitle'  => $subjectTitle,
                    ':lecUnits'      => $lecUnits,
                    ':labUnits'      => $labUnits,
                    ':createdBy'     => $this->_nullIfBlank($createdBy),
                    ':dateCreated'   => $now,
                    ':createdBy2'    => $this->_nullIfBlank($createdBy),
                    ':dateCreated2'  => $now,
                ]);
            } catch (\PDOException $e) {
                if ($this->_isDuplicateKeyError($e)) {
                    http_response_code(409);
                    echo json_encode(['ok' => false, 'error' => [
                        'code' => 'DUPLICATE_SUBJECT_CODE',
                        'message' => 'A subject with this code already exists.',
                    ]]);
                    return;
                }
                throw $e;
            }

            http_response_code(201);
            echo json_encode(['ok' => true, 'subjectID' => $subjectID, 'message' => 'Subject created.']);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/subject-loading/subjects/update
     * Body: { subjectID, subjectCode?, subjectTitle, lecUnits?, labUnits?, modifiedBy }
     */
    public function update()
    {
        try {
            $body = $this->_readJsonBody();

            $subjectID = trim((string)($body['subjectID'] ?? ''));
            if ($subjectID === '') {
                $this->_validationError('subjectID is required.', ['subjectID' => 'Required.']);
                return;
            }

            $db = Database::getConnection();

            $exists = $db->prepare("SELECT subjectID FROM tblSubjects WHERE subjectID = ?");
            $exists->execute([$subjectID]);
            if (!$exists->fetch()) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Subject not found.',
                ]]);
                return;
            }

            $subjectTitle = trim((string)($body['subjectTitle'] ?? ''));
            $subjectCodeRaw = trim((string)($body['subjectCode'] ?? ''));
            $modifiedBy = trim((string)($body['modifiedBy'] ?? ''));

            if ($subjectTitle === '') {
                $this->_validationError('Subject title is required.', ['subjectTitle' => 'Required.']);
                return;
            }

            [$lecUnits, $labUnits, $unitsError] = $this->_parseUnits($body);
            if ($unitsError) {
                $this->_validationError($unitsError['message'], $unitsError['fields']);
                return;
            }

            $subjectCode = $this->_nullIfBlank($subjectCodeRaw);

            try {
                $stmt = $db->prepare("
                    UPDATE tblSubjects
                    SET subjectCode = :subjectCode, subjectTitle = :subjectTitle,
                        lecUnits = :lecUnits, labUnits = :labUnits,
                        modifiedBy = :modifiedBy, lastModified = :lastModified
                    WHERE subjectID = :subjectID
                ");
                $stmt->execute([
                    ':subjectCode'  => $subjectCode,
                    ':subjectTitle' => $subjectTitle,
                    ':lecUnits'     => $lecUnits,
                    ':labUnits'     => $labUnits,
                    ':modifiedBy'   => $this->_nullIfBlank($modifiedBy),
                    ':lastModified' => $this->_nowString(),
                    ':subjectID'    => $subjectID,
                ]);
            } catch (\PDOException $e) {
                if ($this->_isDuplicateKeyError($e)) {
                    http_response_code(409);
                    echo json_encode(['ok' => false, 'error' => [
                        'code' => 'DUPLICATE_SUBJECT_CODE',
                        'message' => 'A subject with this code already exists.',
                    ]]);
                    return;
                }
                throw $e;
            }

            echo json_encode(['ok' => true, 'subjectID' => $subjectID, 'message' => 'Subject updated.']);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/subject-loading/subjects/status
     * Body: { subjectID, isActive, modifiedBy }
     */
    public function setActive()
    {
        try {
            $body = $this->_readJsonBody();

            $subjectID = trim((string)($body['subjectID'] ?? ''));
            if ($subjectID === '') {
                $this->_validationError('subjectID is required.', ['subjectID' => 'Required.']);
                return;
            }
            if (!array_key_exists('isActive', $body)) {
                $this->_validationError('isActive is required.', ['isActive' => 'Required.']);
                return;
            }

            $isActive = $this->_truthy($body['isActive']) ? 1 : 0;
            $modifiedBy = trim((string)($body['modifiedBy'] ?? ''));

            $db = Database::getConnection();

            $exists = $db->prepare("SELECT subjectID FROM tblSubjects WHERE subjectID = ?");
            $exists->execute([$subjectID]);
            if (!$exists->fetch()) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Subject not found.',
                ]]);
                return;
            }

            $stmt = $db->prepare("
                UPDATE tblSubjects
                SET isActive = :isActive, modifiedBy = :modifiedBy, lastModified = :lastModified
                WHERE subjectID = :subjectID
            ");
            $stmt->execute([
                ':isActive'     => $isActive,
                ':modifiedBy'   => $this->_nullIfBlank($modifiedBy),
                ':lastModified' => $this->_nowString(),
                ':subjectID'    => $subjectID,
            ]);

            $countStmt = $db->prepare("
                SELECT COUNT(*) AS cnt FROM tblTeacherSubjectLoads
                WHERE subjectID = ? AND isActive = 1
            ");
            $countStmt->execute([$subjectID]);
            $dependentCount = (int)($countStmt->fetch()['cnt'] ?? 0);

            echo json_encode([
                'ok' => true,
                'subjectID' => $subjectID,
                'isActive' => (bool)$isActive,
                'dependentTeacherSubjectLoadCount' => $dependentCount,
                'message' => $isActive ? 'Subject reactivated.' : 'Subject deactivated.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    // -------------------------------------------------------------
    // Helpers (local to this controller, per §0.2/§6)
    // -------------------------------------------------------------

    private function _toApiRow(array $row): array
    {
        $code = (string)($row['subjectCode'] ?? '');
        $title = (string)($row['subjectTitle'] ?? '');
        return [
            'subjectID'    => (string)$row['subjectID'],
            'subjectCode'  => $code,
            'subjectTitle' => $title,
            'lecUnits'     => (float)($row['lecUnits'] ?? 0),
            'labUnits'     => (float)($row['labUnits'] ?? 0),
            'isActive'     => (bool)((int)($row['isActive'] ?? 0)),
            'label'        => trim(implode(' - ', array_filter([$code, $title], fn($p) => $p !== ''))),
        ];
    }

    private function _parseUnits(array $body): array
    {
        $lecRaw = $body['lecUnits'] ?? 0;
        $labRaw = $body['labUnits'] ?? 0;

        if (!is_numeric($lecRaw) || (float)$lecRaw < 0) {
            return [0, 0, ['message' => 'Lecture units must be a non-negative number.', 'fields' => ['lecUnits' => 'Invalid value.']]];
        }
        if (!is_numeric($labRaw) || (float)$labRaw < 0) {
            return [0, 0, ['message' => 'Lab units must be a non-negative number.', 'fields' => ['labUnits' => 'Invalid value.']]];
        }

        return [round((float)$lecRaw, 2), round((float)$labRaw, 2), null];
    }

    private function _readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function _nullIfBlank(?string $value)
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }

    private function _truthy($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        $s = strtolower(trim((string)$value));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    private function _nowString(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function _isDuplicateKeyError(\PDOException $e): bool
    {
        return (int)($e->errorInfo[1] ?? 0) === 1062;
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
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => [
            'code' => 'SERVER_ERROR',
            'message' => 'An unexpected error occurred.',
        ]]);
    }
}
