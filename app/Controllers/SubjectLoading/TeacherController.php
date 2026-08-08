<?php

namespace App\Controllers\SubjectLoading;

use App\Core\Database;
use App\Models\SequenceGenerator;

/**
 * Teacher record management (CRUD, minimal fields).
 *
 * New file under App\Controllers\SubjectLoading — does not extend, alias,
 * or modify any existing controller (§0.2). Plain class, no constructor,
 * matching the existing thin-controller convention observed in
 * EnrollmentController / AdmissionController / CashierController.
 *
 * Every method follows the §5.0 response envelope: `ok` is always present,
 * on both branches. Every method wraps its entire body in try/catch per
 * §6 — no exception is ever allowed to propagate to index.php.
 */
class TeacherController
{
    /**
     * GET /api/subject-loading/teachers
     * Optional ?activeOnly=1. Sorted by lastName/firstName.
     */
    public function list()
    {
        try {
            $db = Database::getConnection();
            $activeOnly = $this->_truthy($_GET['activeOnly'] ?? null);

            $sql = "
                SELECT teacherID, lastName, firstName, middleName, middleInitial,
                       nameExtension, emailAddress, isActive,
                       createdBy, dateCreated, modifiedBy, lastModified
                FROM tblTeachers
            ";
            if ($activeOnly) {
                $sql .= " WHERE isActive = 1 ";
            }
            $sql .= " ORDER BY lastName, firstName ";

            $stmt = $db->query($sql);
            $rows = $stmt->fetchAll();

            $teachers = array_map([$this, '_toApiRow'], $rows);

            echo json_encode(['ok' => true, 'teachers' => $teachers]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/subject-loading/teachers/search?q=&limit=
     * Prefix-typeahead, mirroring EnrollmentController::students() (§12.6)
     * adapted to tblTeachers' columns, per §5.2.
     */
    public function search()
    {
        try {
            $q = trim($_GET['q'] ?? '');

            if (mb_strlen($q) < 2) {
                echo json_encode(['ok' => true, 'query' => $q, 'suggestions' => []]);
                return;
            }

            $limit = (int)($_GET['limit'] ?? 20);
            if ($limit < 1)  $limit = 1;
            if ($limit > 50) $limit = 50; // hard cap regardless of client request

            $db = Database::getConnection();

            $stmt = $db->prepare("
                SELECT teacherID, lastName, firstName, middleName, middleInitial,
                       nameExtension, emailAddress, isActive
                FROM tblTeachers
                WHERE teacherID    LIKE :q1
                   OR lastName     LIKE :q2
                   OR firstName    LIKE :q3
                   OR CONCAT(firstName, ' ', lastName) LIKE :q4
                ORDER BY lastName, firstName
                LIMIT :lim
            ");

            // Prefix-only — no leading '%' — keeps indexes usable.
            $prefix = $q . '%';
            $stmt->bindValue(':q1', $prefix, \PDO::PARAM_STR);
            $stmt->bindValue(':q2', $prefix, \PDO::PARAM_STR);
            $stmt->bindValue(':q3', $prefix, \PDO::PARAM_STR);
            $stmt->bindValue(':q4', $prefix, \PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();

            $suggestions = array_map([$this, '_toApiRow'], $rows);

            echo json_encode(['ok' => true, 'query' => $q, 'suggestions' => $suggestions]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/subject-loading/teachers
     * Body: { lastName, firstName, middleName?, middleInitial?,
     *         nameExtension?, emailAddress?, createdBy }
     */
    public function store()
    {
        // TEMPORARY DEPLOYMENT-VERIFICATION MARKER — remove after debugging.
        // If ?ping=1 is on the URL, respond immediately, before any other
        // code runs, so we can confirm with 100% certainty that THIS file
        // is the one actually executing on the server.
        if (($_GET['ping'] ?? '') === '1') {
            http_response_code(200);
            echo json_encode(['ok' => true, 'ping' => 'TeacherController::store() marker v2 is live']);
            return;
        }

        try {
            $body = $this->_readJsonBody();

            $lastName  = trim((string)($body['lastName'] ?? ''));
            $firstName = trim((string)($body['firstName'] ?? ''));
            $emailRaw  = trim((string)($body['emailAddress'] ?? ''));
            $createdBy = trim((string)($body['createdBy'] ?? ''));

            $fieldErrors = [];
            if ($lastName === '')  $fieldErrors['lastName']  = 'Required.';
            if ($firstName === '') $fieldErrors['firstName'] = 'Required.';
            if ($fieldErrors) {
                $this->_validationError('Last name and first name are required.', $fieldErrors);
                return;
            }

            $email = $this->_nullIfBlank($emailRaw);
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Email address is not a valid format.',
                    'fields' => ['emailAddress' => 'Invalid format.'],
                ]]);
                return;
            }

            $db = Database::getConnection();

            $seq = SequenceGenerator::reserveIdBlock($db, 'tblTeachers', 1);
            $teacherID = SequenceGenerator::formatId('TCH', $seq['firstNo'], 6);

            $now = $this->_nowString();

            try {
                $stmt = $db->prepare("
                    INSERT INTO tblTeachers
                        (teacherID, lastName, firstName, middleName, middleInitial,
                         nameExtension, emailAddress, isActive,
                         createdBy, dateCreated, modifiedBy, lastModified)
                    VALUES
                        (:teacherID, :lastName, :firstName, :middleName, :middleInitial,
                         :nameExtension, :emailAddress, 1,
                         :createdBy, :dateCreated, :createdBy2, :dateCreated2)
                ");
                $stmt->execute([
                    ':teacherID'     => $teacherID,
                    ':lastName'      => $lastName,
                    ':firstName'     => $firstName,
                    ':middleName'    => $this->_nullIfBlank((string)($body['middleName'] ?? '')),
                    ':middleInitial' => $this->_nullIfBlank((string)($body['middleInitial'] ?? '')),
                    ':nameExtension' => $this->_nullIfBlank((string)($body['nameExtension'] ?? '')),
                    ':emailAddress'  => $email,
                    ':createdBy'     => $this->_nullIfBlank($createdBy),
                    ':dateCreated'   => $now,
                    ':createdBy2'    => $this->_nullIfBlank($createdBy),
                    ':dateCreated2'  => $now,
                ]);
            } catch (\PDOException $e) {
                if ($this->_isDuplicateKeyError($e)) {
                    http_response_code(409);
                    echo json_encode(['ok' => false, 'error' => [
                        'code' => 'DUPLICATE_EMAIL',
                        'message' => 'A teacher with this email address already exists.',
                    ]]);
                    return;
                }
                throw $e;
            }

            http_response_code(201);
            echo json_encode(['ok' => true, 'teacherID' => $teacherID, 'message' => 'Teacher created.']);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/subject-loading/teachers/update
     * Body: { teacherID, lastName, firstName, middleName?, middleInitial?,
     *         nameExtension?, emailAddress?, modifiedBy }
     */
    public function update()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherID = trim((string)($body['teacherID'] ?? ''));
            if ($teacherID === '') {
                $this->_validationError('teacherID is required.', ['teacherID' => 'Required.']);
                return;
            }

            $db = Database::getConnection();

            $exists = $db->prepare("SELECT teacherID FROM tblTeachers WHERE teacherID = ?");
            $exists->execute([$teacherID]);
            if (!$exists->fetch()) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Teacher not found.',
                ]]);
                return;
            }

            $lastName  = trim((string)($body['lastName'] ?? ''));
            $firstName = trim((string)($body['firstName'] ?? ''));
            $emailRaw  = trim((string)($body['emailAddress'] ?? ''));
            $modifiedBy = trim((string)($body['modifiedBy'] ?? ''));

            $fieldErrors = [];
            if ($lastName === '')  $fieldErrors['lastName']  = 'Required.';
            if ($firstName === '') $fieldErrors['firstName'] = 'Required.';
            if ($fieldErrors) {
                $this->_validationError('Last name and first name are required.', $fieldErrors);
                return;
            }

            $email = $this->_nullIfBlank($emailRaw);
            if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Email address is not a valid format.',
                    'fields' => ['emailAddress' => 'Invalid format.'],
                ]]);
                return;
            }

            try {
                $stmt = $db->prepare("
                    UPDATE tblTeachers
                    SET lastName = :lastName, firstName = :firstName,
                        middleName = :middleName, middleInitial = :middleInitial,
                        nameExtension = :nameExtension, emailAddress = :emailAddress,
                        modifiedBy = :modifiedBy, lastModified = :lastModified
                    WHERE teacherID = :teacherID
                ");
                $stmt->execute([
                    ':lastName'      => $lastName,
                    ':firstName'     => $firstName,
                    ':middleName'    => $this->_nullIfBlank((string)($body['middleName'] ?? '')),
                    ':middleInitial' => $this->_nullIfBlank((string)($body['middleInitial'] ?? '')),
                    ':nameExtension' => $this->_nullIfBlank((string)($body['nameExtension'] ?? '')),
                    ':emailAddress'  => $email,
                    ':modifiedBy'    => $this->_nullIfBlank($modifiedBy),
                    ':lastModified'  => $this->_nowString(),
                    ':teacherID'     => $teacherID,
                ]);
            } catch (\PDOException $e) {
                if ($this->_isDuplicateKeyError($e)) {
                    http_response_code(409);
                    echo json_encode(['ok' => false, 'error' => [
                        'code' => 'DUPLICATE_EMAIL',
                        'message' => 'A teacher with this email address already exists.',
                    ]]);
                    return;
                }
                throw $e;
            }

            // createdBy/dateCreated intentionally untouched (§5.2).
            echo json_encode(['ok' => true, 'teacherID' => $teacherID, 'message' => 'Teacher updated.']);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/subject-loading/teachers/status
     * Body: { teacherID, isActive, modifiedBy }
     * Soft-deactivate only — teachers may be referenced by historical
     * tblTeacherSubjectLoads rows. Warns via dependentTeacherSubjectLoadCount,
     * does not block or cascade (§5.2).
     */
    public function setActive()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherID = trim((string)($body['teacherID'] ?? ''));
            if ($teacherID === '') {
                $this->_validationError('teacherID is required.', ['teacherID' => 'Required.']);
                return;
            }
            if (!array_key_exists('isActive', $body)) {
                $this->_validationError('isActive is required.', ['isActive' => 'Required.']);
                return;
            }

            $isActive = $this->_truthy($body['isActive']) ? 1 : 0;
            $modifiedBy = trim((string)($body['modifiedBy'] ?? ''));

            $db = Database::getConnection();

            $exists = $db->prepare("SELECT teacherID FROM tblTeachers WHERE teacherID = ?");
            $exists->execute([$teacherID]);
            if (!$exists->fetch()) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Teacher not found.',
                ]]);
                return;
            }

            $stmt = $db->prepare("
                UPDATE tblTeachers
                SET isActive = :isActive, modifiedBy = :modifiedBy, lastModified = :lastModified
                WHERE teacherID = :teacherID
            ");
            $stmt->execute([
                ':isActive'     => $isActive,
                ':modifiedBy'   => $this->_nullIfBlank($modifiedBy),
                ':lastModified' => $this->_nowString(),
                ':teacherID'    => $teacherID,
            ]);

            $countStmt = $db->prepare("
                SELECT COUNT(*) AS cnt FROM tblTeacherSubjectLoads
                WHERE teacherID = ? AND isActive = 1
            ");
            $countStmt->execute([$teacherID]);
            $dependentCount = (int)($countStmt->fetch()['cnt'] ?? 0);

            echo json_encode([
                'ok' => true,
                'teacherID' => $teacherID,
                'isActive' => (bool)$isActive,
                'dependentTeacherSubjectLoadCount' => $dependentCount,
                'message' => $isActive ? 'Teacher reactivated.' : 'Teacher deactivated.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    // -------------------------------------------------------------
    // Helpers (local to this controller, per §0.2/§6 — cannot reuse
    // EnrollmentController's private _nullIfBlank()/etc.)
    // -------------------------------------------------------------

    private function _toApiRow(array $row): array
    {
        return [
            'teacherID'     => (string)$row['teacherID'],
            'lastName'      => (string)($row['lastName'] ?? ''),
            'firstName'     => (string)($row['firstName'] ?? ''),
            'middleName'    => (string)($row['middleName'] ?? ''),
            'middleInitial' => (string)($row['middleInitial'] ?? ''),
            'nameExtension' => (string)($row['nameExtension'] ?? ''),
            'emailAddress'  => (string)($row['emailAddress'] ?? ''),
            'isActive'      => (bool)((int)($row['isActive'] ?? 0)),
            'label'         => trim(($row['lastName'] ?? '') . ', ' . ($row['firstName'] ?? '')),
        ];
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
        // Logged server-side only by default — never echoed to the client
        // (§6: no stack trace ever reaches the browser). Check your PHP
        // error log for the real cause behind any 500 from this controller.
        error_log('[SubjectLoading][TeacherController] ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());

        http_response_code(500);
        $payload = ['ok' => false, 'error' => [
            'code' => 'SERVER_ERROR',
            'message' => 'An unexpected error occurred.',
        ]];

        // TEMPORARY DIAGNOSTIC ONLY — remove before shipping. Append
        // ?debug=1 to the request URL (works on POST too, as a query
        // string) to see the real exception in the Network tab response
        // body instead of needing server log access.
        if (($_GET['debug'] ?? '') === '1') {
            $payload['debug'] = [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ];
        }

        echo json_encode($payload);
    }
}
