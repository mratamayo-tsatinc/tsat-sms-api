<?php

namespace App\Controllers\SubjectLoading;

use App\Core\Database;
use App\Models\SequenceGenerator;
use App\Services\SubjectLoadingReferenceDataService;

/**
 * Teacher Subject Load management (§5.2) — "which teacher teaches which
 * subject, for which active term, tied to which Moodle course shortname."
 *
 * New file under App\Controllers\SubjectLoading — does not extend, alias,
 * or modify any existing controller (§0.2). Plain class, no constructor,
 * matching TeacherController/SubjectController's conventions exactly
 * (this file mirrors their helper-method shape 1:1 so the module stays
 * internally consistent).
 *
 * Every method follows the §5.0 response envelope: `ok` is always present,
 * on both branches. Every method wraps its entire body in try/catch per
 * §6 — no exception is ever allowed to propagate to index.php.
 */
class TeacherSubjectLoadController
{
    /**
     * GET /api/subject-loading/teacher-subject-loads
     * Filters: academicYear, semester (defaults to the active term if
     * either is omitted), optional teacherID, subjectID.
     */
    public function list()
    {
        try {
            $db = Database::getConnection();

            $academicYear = trim($_GET['academicYear'] ?? '');
            $semester     = trim($_GET['semester'] ?? '');
            $teacherID    = trim($_GET['teacherID'] ?? '');
            $subjectID    = trim($_GET['subjectID'] ?? '');

            if ($academicYear === '' || $semester === '') {
                try {
                    $term = (new SubjectLoadingReferenceDataService())->getActiveTerm();
                    $academicYear = $academicYear !== '' ? $academicYear : $term['academicYear'];
                    $semester     = $semester !== '' ? $semester : $term['semester'];
                } catch (\Exception $e) {
                    http_response_code(500);
                    echo json_encode(['ok' => false, 'error' => [
                        'code' => 'ACTIVE_TERM_UNSET',
                        'message' => $e->getMessage(),
                    ]]);
                    return;
                }
            }

            $sql = "
                SELECT
                    tsl.teacherSubjectLoadID, tsl.teacherID, tsl.subjectID,
                    tsl.academicYear, tsl.semester, tsl.moodleCourseShortname, tsl.isActive,
                    tsl.createdBy, tsl.dateCreated, tsl.modifiedBy, tsl.lastModified,
                    t.lastName AS teacherLastName, t.firstName AS teacherFirstName,
                    s.subjectCode, s.subjectTitle
                FROM tblTeacherSubjectLoads tsl
                LEFT JOIN tblTeachers t ON t.teacherID = tsl.teacherID
                LEFT JOIN tblSubjects s ON s.subjectID = tsl.subjectID
                WHERE tsl.academicYear = :academicYear
                  AND tsl.semester = :semester
            ";
            $params = [':academicYear' => $academicYear, ':semester' => $semester];

            if ($teacherID !== '') {
                $sql .= " AND tsl.teacherID = :teacherID";
                $params[':teacherID'] = $teacherID;
            }
            if ($subjectID !== '') {
                $sql .= " AND tsl.subjectID = :subjectID";
                $params[':subjectID'] = $subjectID;
            }
            $sql .= " ORDER BY t.lastName, t.firstName, s.subjectCode";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $loads = array_map([$this, '_toApiRow'], $rows);

            echo json_encode(['ok' => true, 'loads' => $loads]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/subject-loading/teacher-subject-loads
     * Body: { teacherID, subjectID, academicYear?, semester?,
     *         moodleCourseShortname?, createdBy }
     * Validates teacher/subject exist and are active. Enforces
     * uq_teacher_subject_term_moodle via ON DUPLICATE KEY UPDATE
     * (idempotent re-submit), exactly like _upsertAssessment() (§5.2).
     */
    public function create()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherID = trim((string)($body['teacherID'] ?? ''));
            $subjectID = trim((string)($body['subjectID'] ?? ''));
            $academicYear = trim((string)($body['academicYear'] ?? ''));
            $semester     = trim((string)($body['semester'] ?? ''));
            $moodleCourseShortname = $this->_nullIfBlank((string)($body['moodleCourseShortname'] ?? ''));
            $createdBy = trim((string)($body['createdBy'] ?? ''));

            $fieldErrors = [];
            if ($teacherID === '') $fieldErrors['teacherID'] = 'Required.';
            if ($subjectID === '') $fieldErrors['subjectID'] = 'Required.';
            if ($fieldErrors) {
                $this->_validationError('teacherID and subjectID are required.', $fieldErrors);
                return;
            }

            $db = Database::getConnection();

            if ($academicYear === '' || $semester === '') {
                try {
                    $term = (new SubjectLoadingReferenceDataService())->getActiveTerm();
                    $academicYear = $academicYear !== '' ? $academicYear : $term['academicYear'];
                    $semester     = $semester !== '' ? $semester : $term['semester'];
                } catch (\Exception $e) {
                    http_response_code(500);
                    echo json_encode(['ok' => false, 'error' => [
                        'code' => 'ACTIVE_TERM_UNSET',
                        'message' => $e->getMessage(),
                    ]]);
                    return;
                }
            }

            $teacherStmt = $db->prepare("SELECT isActive FROM tblTeachers WHERE teacherID = ?");
            $teacherStmt->execute([$teacherID]);
            $teacherRow = $teacherStmt->fetch();
            if (!$teacherRow) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND', 'message' => 'Teacher not found.',
                ]]);
                return;
            }
            if ((int)$teacherRow['isActive'] !== 1) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'INACTIVE_TEACHER', 'message' => 'Teacher is not active.',
                ]]);
                return;
            }

            $subjectStmt = $db->prepare("SELECT isActive FROM tblSubjects WHERE subjectID = ?");
            $subjectStmt->execute([$subjectID]);
            $subjectRow = $subjectStmt->fetch();
            if (!$subjectRow) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND', 'message' => 'Subject not found.',
                ]]);
                return;
            }
            if ((int)$subjectRow['isActive'] !== 1) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'INACTIVE_SUBJECT', 'message' => 'Subject is not active.',
                ]]);
                return;
            }

            $ownsTransaction = !$db->inTransaction();
            if ($ownsTransaction) $db->beginTransaction();

            try {
                $seq = SequenceGenerator::reserveIdBlock($db, 'tblTeacherSubjectLoads', 1);
                $newID = SequenceGenerator::formatId('TSL', $seq['firstNo'], 8);
                $now = $this->_nowString();

                $stmt = $db->prepare("
                    INSERT INTO tblTeacherSubjectLoads
                        (teacherSubjectLoadID, teacherID, subjectID, academicYear, semester,
                         moodleCourseShortname, isActive, createdBy, dateCreated, modifiedBy, lastModified)
                    VALUES
                        (:id, :teacherID, :subjectID, :academicYear, :semester,
                         :moodleCourseShortname, 1, :createdBy, :dateCreated, :createdBy2, :dateCreated2)
                    ON DUPLICATE KEY UPDATE
                        modifiedBy = VALUES(createdBy),
                        lastModified = VALUES(dateCreated)
                ");
                $stmt->execute([
                    ':id' => $newID,
                    ':teacherID' => $teacherID,
                    ':subjectID' => $subjectID,
                    ':academicYear' => $academicYear,
                    ':semester' => $semester,
                    ':moodleCourseShortname' => $moodleCourseShortname,
                    ':createdBy' => $this->_nullIfBlank($createdBy),
                    ':dateCreated' => $now,
                    ':createdBy2' => $this->_nullIfBlank($createdBy),
                    ':dateCreated2' => $now,
                ]);

                // Resolve the actual PK — ON DUPLICATE KEY UPDATE won't
                // have used $newID if a row already existed under the
                // composite key.
                $resolveStmt = $db->prepare("
                    SELECT teacherSubjectLoadID FROM tblTeacherSubjectLoads
                    WHERE teacherID = :teacherID AND subjectID = :subjectID
                      AND academicYear = :academicYear AND semester = :semester
                      AND (moodleCourseShortname <=> :moodleCourseShortname)
                ");
                $resolveStmt->execute([
                    ':teacherID' => $teacherID,
                    ':subjectID' => $subjectID,
                    ':academicYear' => $academicYear,
                    ':semester' => $semester,
                    ':moodleCourseShortname' => $moodleCourseShortname,
                ]);
                $resolved = $resolveStmt->fetch();
                $teacherSubjectLoadID = $resolved ? $resolved['teacherSubjectLoadID'] : $newID;

                if ($ownsTransaction) $db->commit();
            } catch (\Throwable $e) {
                if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
                throw $e;
            }

            http_response_code(201);
            echo json_encode([
                'ok' => true,
                'teacherSubjectLoadID' => $teacherSubjectLoadID,
                'message' => 'Teacher subject load created.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/subject-loading/teacher-subject-loads/update
     * Body: { teacherSubjectLoadID, moodleCourseShortname?, modifiedBy }
     * Allows changing moodleCourseShortname without breaking identity —
     * identity is the DB row's PK, not the composite; the composite
     * unique key still guards against creating a duplicate.
     */
    public function update()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherSubjectLoadID = trim((string)($body['teacherSubjectLoadID'] ?? ''));
            if ($teacherSubjectLoadID === '') {
                $this->_validationError('teacherSubjectLoadID is required.', ['teacherSubjectLoadID' => 'Required.']);
                return;
            }

            $db = Database::getConnection();

            $existingStmt = $db->prepare("SELECT * FROM tblTeacherSubjectLoads WHERE teacherSubjectLoadID = ?");
            $existingStmt->execute([$teacherSubjectLoadID]);
            $existing = $existingStmt->fetch();
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND', 'message' => 'Teacher subject load not found.',
                ]]);
                return;
            }

            $modifiedBy = trim((string)($body['modifiedBy'] ?? ''));
            $moodleCourseShortname = array_key_exists('moodleCourseShortname', $body)
                ? $this->_nullIfBlank((string)$body['moodleCourseShortname'])
                : $existing['moodleCourseShortname'];

            $dupStmt = $db->prepare("
                SELECT teacherSubjectLoadID FROM tblTeacherSubjectLoads
                WHERE teacherID = :teacherID AND subjectID = :subjectID
                  AND academicYear = :academicYear AND semester = :semester
                  AND (moodleCourseShortname <=> :moodleCourseShortname)
                  AND teacherSubjectLoadID != :selfID
            ");
            $dupStmt->execute([
                ':teacherID' => $existing['teacherID'],
                ':subjectID' => $existing['subjectID'],
                ':academicYear' => $existing['academicYear'],
                ':semester' => $existing['semester'],
                ':moodleCourseShortname' => $moodleCourseShortname,
                ':selfID' => $teacherSubjectLoadID,
            ]);
            if ($dupStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'DUPLICATE_LOAD',
                    'message' => 'Another teacher subject load already uses this teacher, subject, term, and Moodle course.',
                ]]);
                return;
            }

            $stmt = $db->prepare("
                UPDATE tblTeacherSubjectLoads
                SET moodleCourseShortname = :moodleCourseShortname,
                    modifiedBy = :modifiedBy, lastModified = :lastModified
                WHERE teacherSubjectLoadID = :id
            ");
            $stmt->execute([
                ':moodleCourseShortname' => $moodleCourseShortname,
                ':modifiedBy' => $this->_nullIfBlank($modifiedBy),
                ':lastModified' => $this->_nowString(),
                ':id' => $teacherSubjectLoadID,
            ]);

            echo json_encode(['ok' => true, 'teacherSubjectLoadID' => $teacherSubjectLoadID, 'message' => 'Teacher subject load updated.']);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/subject-loading/teacher-subject-loads/status
     * Body: { teacherSubjectLoadID, isActive, modifiedBy }
     * Soft-deactivate. Does NOT cascade-deactivate Class Loads — returns
     * dependentClassLoadCount so the front end can warn (§5.2).
     */
    public function setActive()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherSubjectLoadID = trim((string)($body['teacherSubjectLoadID'] ?? ''));
            if ($teacherSubjectLoadID === '') {
                $this->_validationError('teacherSubjectLoadID is required.', ['teacherSubjectLoadID' => 'Required.']);
                return;
            }
            if (!array_key_exists('isActive', $body)) {
                $this->_validationError('isActive is required.', ['isActive' => 'Required.']);
                return;
            }

            $isActive = $this->_truthy($body['isActive']) ? 1 : 0;
            $modifiedBy = trim((string)($body['modifiedBy'] ?? ''));

            $db = Database::getConnection();

            $exists = $db->prepare("SELECT teacherSubjectLoadID FROM tblTeacherSubjectLoads WHERE teacherSubjectLoadID = ?");
            $exists->execute([$teacherSubjectLoadID]);
            if (!$exists->fetch()) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND', 'message' => 'Teacher subject load not found.',
                ]]);
                return;
            }

            $stmt = $db->prepare("
                UPDATE tblTeacherSubjectLoads
                SET isActive = :isActive, modifiedBy = :modifiedBy, lastModified = :lastModified
                WHERE teacherSubjectLoadID = :id
            ");
            $stmt->execute([
                ':isActive' => $isActive,
                ':modifiedBy' => $this->_nullIfBlank($modifiedBy),
                ':lastModified' => $this->_nowString(),
                ':id' => $teacherSubjectLoadID,
            ]);

            $countStmt = $db->prepare("
                SELECT COUNT(*) AS cnt FROM tblTeacherClassLoads
                WHERE teacherSubjectLoadID = ? AND isActive = 1
            ");
            $countStmt->execute([$teacherSubjectLoadID]);
            $dependentCount = (int)($countStmt->fetch()['cnt'] ?? 0);

            echo json_encode([
                'ok' => true,
                'teacherSubjectLoadID' => $teacherSubjectLoadID,
                'isActive' => (bool)$isActive,
                'dependentClassLoadCount' => $dependentCount,
                'message' => $isActive ? 'Teacher subject load reactivated.' : 'Teacher subject load deactivated.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }
    
    /**
     * POST /api/subject-loading/teacher-subject-loads/bulk
     * [REVISED — see §5.2d] Bulk counterpart to create() — assigns many
     * subjects (each with its own optional Moodle course shortname) to
     * one teacher in a single call/transaction. create()/update()/
     * setActive()/list() are unchanged by this method.
     *
     * Body: { teacherID, academicYear?, semester?,
     *         subjects: [{ subjectID, moodleCourseShortname? }, ...],
     *         createdBy }
     */
    public function assignSubjects()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherID = trim((string)($body['teacherID'] ?? ''));
            $academicYear = trim((string)($body['academicYear'] ?? ''));
            $semester     = trim((string)($body['semester'] ?? ''));
            $createdBy = trim((string)($body['createdBy'] ?? ''));
            $subjectsInput = isset($body['subjects']) && is_array($body['subjects']) ? $body['subjects'] : [];

            $fieldErrors = [];
            if ($teacherID === '') $fieldErrors['teacherID'] = 'Required.';
            if (!$subjectsInput) $fieldErrors['subjects'] = 'At least one subject is required.';
            if ($fieldErrors) {
                $this->_validationError('teacherID and a non-empty subjects array are required.', $fieldErrors);
                return;
            }

            $db = Database::getConnection();

            if ($academicYear === '' || $semester === '') {
                try {
                    $term = (new SubjectLoadingReferenceDataService())->getActiveTerm();
                    $academicYear = $academicYear !== '' ? $academicYear : $term['academicYear'];
                    $semester     = $semester !== '' ? $semester : $term['semester'];
                } catch (\Exception $e) {
                    http_response_code(500);
                    echo json_encode(['ok' => false, 'error' => [
                        'code' => 'ACTIVE_TERM_UNSET',
                        'message' => $e->getMessage(),
                    ]]);
                    return;
                }
            }

            // Teacher validated once, up front — a bad teacherID fails the
            // whole batch (unlike per-subject validation below).
            $teacherStmt = $db->prepare("SELECT isActive FROM tblTeachers WHERE teacherID = ?");
            $teacherStmt->execute([$teacherID]);
            $teacherRow = $teacherStmt->fetch();
            if (!$teacherRow) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND', 'message' => 'Teacher not found.',
                ]]);
                return;
            }
            if ((int)$teacherRow['isActive'] !== 1) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'INACTIVE_TEACHER', 'message' => 'Teacher is not active.',
                ]]);
                return;
            }

            // De-dupe by (subjectID, normalized moodleCourseShortname) —
            // two entries for the same subjectID collapse into one unless
            // their Moodle shortnames genuinely differ, mirroring
            // uq_teacher_subject_term_moodle (§4.1).
            $deduped = [];
            foreach ($subjectsInput as $entry) {
                if (!is_array($entry)) continue;
                $subjectID = trim((string)($entry['subjectID'] ?? ''));
                if ($subjectID === '') continue;
                $moodle = $this->_nullIfBlank((string)($entry['moodleCourseShortname'] ?? ''));
                $dedupeKey = $subjectID . '|' . ($moodle ?? '');
                $deduped[$dedupeKey] = ['subjectID' => $subjectID, 'moodleCourseShortname' => $moodle];
            }

            if (!$deduped) {
                $this->_validationError('No valid subject entries were provided.', ['subjects' => 'Required.']);
                return;
            }

            // Invalid entries are skipped, not fatal to the whole batch —
            // collected into invalidSubjects[], mirroring bulkEnroll()'s
            // tolerate-partial-overlap posture (§5.2).
            $valid = [];
            $invalidSubjects = [];
            foreach ($deduped as $entry) {
                $subjectStmt = $db->prepare("SELECT isActive FROM tblSubjects WHERE subjectID = ?");
                $subjectStmt->execute([$entry['subjectID']]);
                $subjectRow = $subjectStmt->fetch();
                if (!$subjectRow) {
                    $invalidSubjects[] = ['subjectID' => $entry['subjectID'], 'reason' => 'NOT_FOUND'];
                    continue;
                }
                if ((int)$subjectRow['isActive'] !== 1) {
                    $invalidSubjects[] = ['subjectID' => $entry['subjectID'], 'reason' => 'INACTIVE_SUBJECT'];
                    continue;
                }
                $valid[] = $entry;
            }

            if (!$valid) {
                // Structured non-error per §5.0's sub-rule — same
                // precedent enrollClass()'s empty-set case sets (§5.2b).
                echo json_encode([
                    'ok' => true,
                    'teacherID' => $teacherID,
                    'academicYear' => $academicYear,
                    'semester' => $semester,
                    'assigned' => [],
                    'invalidSubjects' => $invalidSubjects,
                    'message' => 'No valid subjects to assign.',
                ]);
                return;
            }

            $ownsTransaction = !$db->inTransaction();
            if ($ownsTransaction) $db->beginTransaction();

            $assigned = [];
            try {
                // One ID block sized to the whole batch, not one
                // reservation per row (§4.4/§6).
                $seq = SequenceGenerator::reserveIdBlock($db, 'tblTeacherSubjectLoads', count($valid));
                $nextNo = $seq['firstNo'];
                $now = $this->_nowString();

                $insertStmt = $db->prepare("
                    INSERT INTO tblTeacherSubjectLoads
                        (teacherSubjectLoadID, teacherID, subjectID, academicYear, semester,
                         moodleCourseShortname, isActive, createdBy, dateCreated, modifiedBy, lastModified)
                    VALUES
                        (:id, :teacherID, :subjectID, :academicYear, :semester,
                         :moodleCourseShortname, 1, :createdBy, :dateCreated, :createdBy2, :dateCreated2)
                    ON DUPLICATE KEY UPDATE
                        modifiedBy = VALUES(createdBy),
                        lastModified = VALUES(dateCreated)
                ");
                $resolveStmt = $db->prepare("
                    SELECT teacherSubjectLoadID FROM tblTeacherSubjectLoads
                    WHERE teacherID = :teacherID AND subjectID = :subjectID
                      AND academicYear = :academicYear AND semester = :semester
                      AND (moodleCourseShortname <=> :moodleCourseShortname)
                ");

                foreach ($valid as $entry) {
                    $newID = SequenceGenerator::formatId('TSL', $nextNo, 8);
                    $nextNo++;

                    $insertStmt->execute([
                        ':id' => $newID,
                        ':teacherID' => $teacherID,
                        ':subjectID' => $entry['subjectID'],
                        ':academicYear' => $academicYear,
                        ':semester' => $semester,
                        ':moodleCourseShortname' => $entry['moodleCourseShortname'],
                        ':createdBy' => $this->_nullIfBlank($createdBy),
                        ':dateCreated' => $now,
                        ':createdBy2' => $this->_nullIfBlank($createdBy),
                        ':dateCreated2' => $now,
                    ]);

                    $resolveStmt->execute([
                        ':teacherID' => $teacherID,
                        ':subjectID' => $entry['subjectID'],
                        ':academicYear' => $academicYear,
                        ':semester' => $semester,
                        ':moodleCourseShortname' => $entry['moodleCourseShortname'],
                    ]);
                    $resolved = $resolveStmt->fetch();
                    $teacherSubjectLoadID = $resolved ? $resolved['teacherSubjectLoadID'] : $newID;

                    $assigned[] = [
                        'teacherSubjectLoadID' => $teacherSubjectLoadID,
                        'subjectID' => $entry['subjectID'],
                        'moodleCourseShortname' => $entry['moodleCourseShortname'],
                    ];
                }

                if ($ownsTransaction) $db->commit();
            } catch (\Throwable $e) {
                if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
                throw $e;
            }

            echo json_encode([
                'ok' => true,
                'teacherID' => $teacherID,
                'academicYear' => $academicYear,
                'semester' => $semester,
                'assigned' => $assigned,
                'invalidSubjects' => $invalidSubjects,
                'message' => count($assigned) . ' subject(s) assigned.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    // -------------------------------------------------------------
    // Helpers (local to this controller, per §0.2/§6 — mirrors
    // TeacherController/SubjectController's helper shape exactly so
    // the module stays internally consistent).
    // ------------------------------------------------------------

    private function _toApiRow(array $row): array
    {
        $teacherName = trim(($row['teacherLastName'] ?? '') . ', ' . ($row['teacherFirstName'] ?? ''), ', ');
        return [
            'teacherSubjectLoadID'  => (string)$row['teacherSubjectLoadID'],
            'teacherID'             => (string)($row['teacherID'] ?? ''),
            'subjectID'             => (string)($row['subjectID'] ?? ''),
            'academicYear'          => (string)($row['academicYear'] ?? ''),
            'semester'              => (string)($row['semester'] ?? ''),
            'moodleCourseShortname' => $row['moodleCourseShortname'],
            'teacherName'           => $teacherName,
            'subjectCode'           => $row['subjectCode'],
            'subjectTitle'          => $row['subjectTitle'],
            'isActive'              => (bool)((int)($row['isActive'] ?? 0)),
            'createdBy'             => $row['createdBy'],
            'dateCreated'           => $row['dateCreated'],
            'modifiedBy'            => $row['modifiedBy'],
            'lastModified'          => $row['lastModified'],
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
        // (§6: no stack trace ever reaches the browser).
        error_log('[SubjectLoading][TeacherSubjectLoadController] ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());

        http_response_code(500);
        $payload = ['ok' => false, 'error' => [
            'code' => 'SERVER_ERROR',
            'message' => 'An unexpected error occurred.',
        ]];

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
