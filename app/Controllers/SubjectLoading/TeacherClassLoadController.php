<?php

namespace App\Controllers\SubjectLoading;

use App\Core\Database;
use App\Models\SequenceGenerator;

/**
 * Teacher Class Load management — a Teacher Subject Load assigned to one
 * or more classes (programID + yearLevel + sectionID), a.k.a. "Subject
 * Offerings." Also owns searchOfferings(), the unified subject/teacher/
 * class search used by the Enroll / Bulk Enroll pickers.
 */
class TeacherClassLoadController
{
    /**
     * GET /api/subject-loading/teacher-class-loads
     * Filters by teacherSubjectLoadID, or by class
     * (programID/yearLevel/sectionID + academicYear/semester) — supports
     * both "loads for this teacher" and "who teaches this class" views.
     */
    public function list()
    {
        try {
            $db = Database::getConnection();

            $teacherSubjectLoadID = trim($_GET['teacherSubjectLoadID'] ?? '');
            $programID = trim($_GET['programID'] ?? '');
            $yearLevel = trim($_GET['yearLevel'] ?? '');
            $sectionID = trim($_GET['sectionID'] ?? '');
            $academicYear = trim($_GET['academicYear'] ?? '');
            $semester     = trim($_GET['semester'] ?? '');

            $sql = "
                SELECT
                    tcl.teacherClassLoadID, tcl.teacherSubjectLoadID, tcl.programID,
                    tcl.yearLevel, tcl.sectionID, tcl.isActive,
                    tcl.createdBy, tcl.dateCreated, tcl.modifiedBy, tcl.lastModified,
                    tsl.teacherID, tsl.subjectID, tsl.academicYear, tsl.semester, tsl.moodleCourseShortname,
                    t.lastName AS teacherLastName, t.firstName AS teacherFirstName,
                    s.subjectCode, s.subjectTitle,
                    p.programCode, sec.sectionName
                FROM tblTeacherClassLoads tcl
                INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
                LEFT JOIN tblTeachers t ON t.teacherID = tsl.teacherID
                LEFT JOIN tblSubjects s ON s.subjectID = tsl.subjectID
                LEFT JOIN tblPrograms p ON p.programID = tcl.programID
                LEFT JOIN tblSections sec ON sec.sectionID = tcl.sectionID
                WHERE 1 = 1
            ";
            $params = [];

            if ($teacherSubjectLoadID !== '') {
                $sql .= " AND tcl.teacherSubjectLoadID = :teacherSubjectLoadID";
                $params[':teacherSubjectLoadID'] = $teacherSubjectLoadID;
            }
            if ($programID !== '') {
                $sql .= " AND tcl.programID = :programID";
                $params[':programID'] = $programID;
            }
            if ($yearLevel !== '') {
                $sql .= " AND tcl.yearLevel = :yearLevel";
                $params[':yearLevel'] = $yearLevel;
            }
            if ($sectionID !== '') {
                $sql .= " AND tcl.sectionID = :sectionID";
                $params[':sectionID'] = $sectionID;
            }
            if ($academicYear !== '') {
                $sql .= " AND tsl.academicYear = :academicYear";
                $params[':academicYear'] = $academicYear;
            }
            if ($semester !== '') {
                $sql .= " AND tsl.semester = :semester";
                $params[':semester'] = $semester;
            }
            $sql .= " ORDER BY t.lastName, t.firstName, s.subjectCode, p.programCode, sec.sectionName";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $loads = array_map([$this, '_toApiRow'], $rows);

            echo json_encode(['ok' => true, 'classLoads' => $loads]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/subject-loading/teacher-class-loads
     * Body: { teacherSubjectLoadID, programID?, yearLevel?, sectionID?, createdBy }
     * Warns (does not block) if the class has no registrations in that
     * term. Enforces uq_class_load via ON DUPLICATE KEY UPDATE
     * (idempotent re-submit).
     */
    public function assignClass()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherSubjectLoadID = trim((string)($body['teacherSubjectLoadID'] ?? ''));
            if ($teacherSubjectLoadID === '') {
                $this->_validationError('teacherSubjectLoadID is required.', ['teacherSubjectLoadID' => 'Required.']);
                return;
            }

            $programID = $this->_nullIfBlank((string)($body['programID'] ?? ''));
            $yearLevel = $this->_nullIfBlank((string)($body['yearLevel'] ?? ''));
            $sectionID = $this->_nullIfBlank((string)($body['sectionID'] ?? ''));
            $createdBy = trim((string)($body['createdBy'] ?? ''));

            $db = Database::getConnection();

            $parentStmt = $db->prepare("SELECT academicYear, semester FROM tblTeacherSubjectLoads WHERE teacherSubjectLoadID = ?");
            $parentStmt->execute([$teacherSubjectLoadID]);
            $parent = $parentStmt->fetch();
            if (!$parent) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND', 'message' => 'Teacher subject load not found.',
                ]]);
                return;
            }

            // Warn (do not block) if the class has zero current
            // registrations for this term.
            $warnings = [];
            $regCountStmt = $db->prepare("
                SELECT COUNT(*) AS cnt FROM tblRegistrations
                WHERE academicYear = :academicYear AND semester = :semester
                  AND COALESCE(programID, '') = :programID
                  AND COALESCE(sectionID, '') = :sectionID
            ");
            $regCountStmt->execute([
                ':academicYear' => $parent['academicYear'],
                ':semester' => $parent['semester'],
                ':programID' => (string)($programID ?? ''),
                ':sectionID' => (string)($sectionID ?? ''),
            ]);
            $registrationCount = (int)($regCountStmt->fetch()['cnt'] ?? 0);
            if ($registrationCount === 0) {
                $warnings[] = 'This class currently has no registrations for the active term.';
            }

            $ownsTransaction = !$db->inTransaction();
            if ($ownsTransaction) $db->beginTransaction();

            try {
                $seq = SequenceGenerator::reserveIdBlock($db, 'tblTeacherClassLoads', 1);
                $newID = SequenceGenerator::formatId('TCL', $seq['firstNo'], 8);
                $now = $this->_nowString();

                $stmt = $db->prepare("
                    INSERT INTO tblTeacherClassLoads
                        (teacherClassLoadID, teacherSubjectLoadID, programID, yearLevel, sectionID,
                         isActive, createdBy, dateCreated, modifiedBy, lastModified)
                    VALUES
                        (:id, :teacherSubjectLoadID, :programID, :yearLevel, :sectionID,
                         1, :createdBy, :dateCreated, :createdBy2, :dateCreated2)
                    ON DUPLICATE KEY UPDATE
                        modifiedBy = VALUES(createdBy),
                        lastModified = VALUES(dateCreated)
                ");
                $stmt->execute([
                    ':id' => $newID,
                    ':teacherSubjectLoadID' => $teacherSubjectLoadID,
                    ':programID' => $programID,
                    ':yearLevel' => $yearLevel,
                    ':sectionID' => $sectionID,
                    ':createdBy' => $this->_nullIfBlank($createdBy),
                    ':dateCreated' => $now,
                    ':createdBy2' => $this->_nullIfBlank($createdBy),
                    ':dateCreated2' => $now,
                ]);

                $resolveStmt = $db->prepare("
                    SELECT teacherClassLoadID FROM tblTeacherClassLoads
                    WHERE teacherSubjectLoadID = :teacherSubjectLoadID
                      AND (programID <=> :programID)
                      AND (yearLevel <=> :yearLevel)
                      AND (sectionID <=> :sectionID)
                ");
                $resolveStmt->execute([
                    ':teacherSubjectLoadID' => $teacherSubjectLoadID,
                    ':programID' => $programID,
                    ':yearLevel' => $yearLevel,
                    ':sectionID' => $sectionID,
                ]);
                $resolved = $resolveStmt->fetch();
                $teacherClassLoadID = $resolved ? $resolved['teacherClassLoadID'] : $newID;

                if ($ownsTransaction) $db->commit();
            } catch (\Throwable $e) {
                if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
                throw $e;
            }

            http_response_code(201);
            echo json_encode([
                'ok' => true,
                'teacherClassLoadID' => $teacherClassLoadID,
                'warnings' => $warnings,
                'message' => 'Class assigned to teacher subject load.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/subject-loading/teacher-class-loads/bulk
     * Body: { teacherSubjectLoadID, classes: [{ programID, yearLevel, sectionID }, ...], createdBy }
     *
     * Bulk counterpart to assignClass() — assigns one Teacher Subject Load
     * to several classes in a single request. Each entry in `classes` is
     * expected to already have at least one live registration, so unlike
     * assignClass() there is no zero-registrations warning to compute.
     *
     * Same idempotent ON DUPLICATE KEY UPDATE upsert per class as
     * assignClass(), all inside one transaction. Re-submitting an
     * already-assigned class is a no-op.
     */
    public function assignClasses()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherSubjectLoadID = trim((string)($body['teacherSubjectLoadID'] ?? ''));
            if ($teacherSubjectLoadID === '') {
                $this->_validationError('teacherSubjectLoadID is required.', ['teacherSubjectLoadID' => 'Required.']);
                return;
            }

            $classes = $body['classes'] ?? [];
            if (!is_array($classes) || count($classes) === 0) {
                $this->_validationError('At least one class is required.', ['classes' => 'Required.']);
                return;
            }

            $createdBy = trim((string)($body['createdBy'] ?? ''));

            $db = Database::getConnection();

            $parentStmt = $db->prepare("SELECT teacherSubjectLoadID FROM tblTeacherSubjectLoads WHERE teacherSubjectLoadID = ?");
            $parentStmt->execute([$teacherSubjectLoadID]);
            if (!$parentStmt->fetch()) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND', 'message' => 'Teacher subject load not found.',
                ]]);
                return;
            }

            // Normalize + de-dupe requested classes up front (a repeated
            // checkbox submission, or the same class sent twice, should
            // only reserve/insert once).
            $normalized = [];
            foreach ($classes as $c) {
                $programID = $this->_nullIfBlank((string)($c['programID'] ?? ''));
                $yearLevel = $this->_nullIfBlank((string)($c['yearLevel'] ?? ''));
                $sectionID = $this->_nullIfBlank((string)($c['sectionID'] ?? ''));
                $key = ($programID ?? '') . '|' . ($yearLevel ?? '') . '|' . ($sectionID ?? '');
                $normalized[$key] = ['programID' => $programID, 'yearLevel' => $yearLevel, 'sectionID' => $sectionID];
            }
            $normalized = array_values($normalized);

            $ownsTransaction = !$db->inTransaction();
            if ($ownsTransaction) $db->beginTransaction();

            $assigned = [];
            try {
                $seq = SequenceGenerator::reserveIdBlock($db, 'tblTeacherClassLoads', count($normalized));
                $nextNo = $seq['firstNo'];
                $now = $this->_nowString();

                $insertStmt = $db->prepare("
                    INSERT INTO tblTeacherClassLoads
                        (teacherClassLoadID, teacherSubjectLoadID, programID, yearLevel, sectionID,
                         isActive, createdBy, dateCreated, modifiedBy, lastModified)
                    VALUES
                        (:id, :teacherSubjectLoadID, :programID, :yearLevel, :sectionID,
                         1, :createdBy, :dateCreated, :createdBy2, :dateCreated2)
                    ON DUPLICATE KEY UPDATE
                        modifiedBy = VALUES(createdBy),
                        lastModified = VALUES(dateCreated)
                ");

                $resolveStmt = $db->prepare("
                    SELECT teacherClassLoadID FROM tblTeacherClassLoads
                    WHERE teacherSubjectLoadID = :teacherSubjectLoadID
                      AND (programID <=> :programID)
                      AND (yearLevel <=> :yearLevel)
                      AND (sectionID <=> :sectionID)
                ");

                foreach ($normalized as $c) {
                    $newID = SequenceGenerator::formatId('TCL', $nextNo, 8);
                    $nextNo++;

                    $insertStmt->execute([
                        ':id' => $newID,
                        ':teacherSubjectLoadID' => $teacherSubjectLoadID,
                        ':programID' => $c['programID'],
                        ':yearLevel' => $c['yearLevel'],
                        ':sectionID' => $c['sectionID'],
                        ':createdBy' => $this->_nullIfBlank($createdBy),
                        ':dateCreated' => $now,
                        ':createdBy2' => $this->_nullIfBlank($createdBy),
                        ':dateCreated2' => $now,
                    ]);

                    $resolveStmt->execute([
                        ':teacherSubjectLoadID' => $teacherSubjectLoadID,
                        ':programID' => $c['programID'],
                        ':yearLevel' => $c['yearLevel'],
                        ':sectionID' => $c['sectionID'],
                    ]);
                    $resolved = $resolveStmt->fetch();

                    $assigned[] = [
                        'teacherClassLoadID' => $resolved ? $resolved['teacherClassLoadID'] : $newID,
                        'programID' => $c['programID'],
                        'yearLevel' => $c['yearLevel'],
                        'sectionID' => $c['sectionID'],
                    ];
                }

                if ($ownsTransaction) $db->commit();
            } catch (\Throwable $e) {
                if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
                throw $e;
            }

            http_response_code(201);
            echo json_encode([
                'ok' => true,
                'assigned' => $assigned,
                'message' => count($assigned) . ' class' . (count($assigned) === 1 ? '' : 'es') . ' assigned to teacher subject load.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/subject-loading/teacher-class-loads/status
     * Body: { teacherClassLoadID, isActive, modifiedBy }
     * Soft-deactivate; response includes dependentEnrollmentCount so the
     * UI can warn before deactivating a class load with enrolled students.
     * Historical tblStudentSubjectEnrollments rows are left intact.
     */
    public function setActive()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherClassLoadID = trim((string)($body['teacherClassLoadID'] ?? ''));
            if ($teacherClassLoadID === '') {
                $this->_validationError('teacherClassLoadID is required.', ['teacherClassLoadID' => 'Required.']);
                return;
            }
            if (!array_key_exists('isActive', $body)) {
                $this->_validationError('isActive is required.', ['isActive' => 'Required.']);
                return;
            }

            $isActive = $this->_truthy($body['isActive']) ? 1 : 0;
            $modifiedBy = trim((string)($body['modifiedBy'] ?? ''));

            $db = Database::getConnection();

            $exists = $db->prepare("SELECT teacherClassLoadID FROM tblTeacherClassLoads WHERE teacherClassLoadID = ?");
            $exists->execute([$teacherClassLoadID]);
            if (!$exists->fetch()) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'NOT_FOUND', 'message' => 'Teacher class load not found.',
                ]]);
                return;
            }

            $stmt = $db->prepare("
                UPDATE tblTeacherClassLoads
                SET isActive = :isActive, modifiedBy = :modifiedBy, lastModified = :lastModified
                WHERE teacherClassLoadID = :id
            ");
            $stmt->execute([
                ':isActive' => $isActive,
                ':modifiedBy' => $this->_nullIfBlank($modifiedBy),
                ':lastModified' => $this->_nowString(),
                ':id' => $teacherClassLoadID,
            ]);

            $countStmt = $db->prepare("
                SELECT COUNT(*) AS cnt FROM tblStudentSubjectEnrollments
                WHERE teacherClassLoadID = ? AND isActive = 1
            ");
            $countStmt->execute([$teacherClassLoadID]);
            $dependentCount = (int)($countStmt->fetch()['cnt'] ?? 0);

            echo json_encode([
                'ok' => true,
                'teacherClassLoadID' => $teacherClassLoadID,
                'isActive' => (bool)$isActive,
                'dependentEnrollmentCount' => $dependentCount,
                'message' => $isActive ? 'Teacher class load reactivated.' : 'Teacher class load deactivated.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/subject-loading/offerings/search
     * Required: academicYear, semester. Optional, combinable: q (free
     * text substring match across subject/teacher/class), subjectCode,
     * subjectTitle, teacherName, programID, yearLevel, sectionID.
     */
    public function searchOfferings()
    {
        try {
            $academicYear = trim($_GET['academicYear'] ?? '');
            $semester     = trim($_GET['semester'] ?? '');
            if ($academicYear === '' || $semester === '') {
                $this->_validationError('academicYear and semester are required.', [
                    'academicYear' => 'Required.',
                    'semester' => 'Required.',
                ]);
                return;
            }

            $q = trim($_GET['q'] ?? '');
            $subjectCode  = trim($_GET['subjectCode'] ?? '');
            $subjectTitle = trim($_GET['subjectTitle'] ?? '');
            $teacherName  = trim($_GET['teacherName'] ?? '');
            $programID    = trim($_GET['programID'] ?? '');
            $yearLevel    = trim($_GET['yearLevel'] ?? '');
            $sectionID    = trim($_GET['sectionID'] ?? '');

            $db = Database::getConnection();

            $sql = "
                SELECT
                    tcl.teacherClassLoadID, tsl.moodleCourseShortname,
                    s.subjectCode, s.subjectTitle,
                    t.lastName AS teacherLastName, t.firstName AS teacherFirstName,
                    tcl.programID, p.programCode, tcl.yearLevel, tcl.sectionID, sec.sectionName,
                    (SELECT COUNT(*) FROM tblStudentSubjectEnrollments sse
                        WHERE sse.teacherClassLoadID = tcl.teacherClassLoadID
                          AND sse.isActive = 1
                          AND sse.enrollmentStatus = 'ENROLLED') AS enrolledCount,
                    (SELECT COUNT(*) FROM tblStudentSubjectEnrollments sse2
                        INNER JOIN tblLmsAccounts la2 ON la2.studentNumber = sse2.studentNumber
                        WHERE sse2.teacherClassLoadID = tcl.teacherClassLoadID
                          AND sse2.isActive = 1
                          AND sse2.enrollmentStatus = 'ENROLLED'
                          AND la2.status = 'CREATED'
                          AND la2.moodleEmail IS NOT NULL
                          AND la2.moodleEmail <> '') AS moodleReadyCount
                FROM tblTeacherClassLoads tcl
                INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
                LEFT JOIN tblSubjects s ON s.subjectID = tsl.subjectID
                LEFT JOIN tblTeachers t ON t.teacherID = tsl.teacherID
                LEFT JOIN tblPrograms p ON p.programID = tcl.programID
                LEFT JOIN tblSections sec ON sec.sectionID = tcl.sectionID
                WHERE tsl.academicYear = :academicYear
                  AND tsl.semester = :semester
                  AND tcl.isActive = 1
		  AND tsl.isActive = 1
            ";
            $params = [':academicYear' => $academicYear, ':semester' => $semester];

            // Broader substring style, OR-joined '%q%' across
            // subject/teacher/class fields.
            if ($q !== '') {
                $like = '%' . $q . '%';
                $sql .= " AND (
                    s.subjectCode LIKE :q1
                    OR s.subjectTitle LIKE :q2
                    OR CONCAT(t.firstName, ' ', t.lastName) LIKE :q3
                    OR CONCAT(t.lastName, ', ', t.firstName) LIKE :q4
                    OR CONCAT(COALESCE(p.programCode, ''), ' ', COALESCE(sec.sectionName, '')) LIKE :q5
                )";
                $params[':q1'] = $like;
                $params[':q2'] = $like;
                $params[':q3'] = $like;
                $params[':q4'] = $like;
                $params[':q5'] = $like;
            }
            if ($subjectCode !== '') {
                $sql .= " AND s.subjectCode LIKE :subjectCode";
                $params[':subjectCode'] = '%' . $subjectCode . '%';
            }
            if ($subjectTitle !== '') {
                $sql .= " AND s.subjectTitle LIKE :subjectTitle";
                $params[':subjectTitle'] = '%' . $subjectTitle . '%';
            }
            if ($teacherName !== '') {
                $sql .= " AND CONCAT(t.firstName, ' ', t.lastName) LIKE :teacherName";
                $params[':teacherName'] = '%' . $teacherName . '%';
            }
            if ($programID !== '') {
                $sql .= " AND tcl.programID = :programID";
                $params[':programID'] = $programID;
            }
            if ($yearLevel !== '') {
                $sql .= " AND tcl.yearLevel = :yearLevel";
                $params[':yearLevel'] = $yearLevel;
            }
            if ($sectionID !== '') {
                $sql .= " AND tcl.sectionID = :sectionID";
                $params[':sectionID'] = $sectionID;
            }
            $sql .= " ORDER BY s.subjectCode, t.lastName, t.firstName";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $results = array_map([$this, '_toOfferingRow'], $rows);

            echo json_encode(['ok' => true, 'query' => $q, 'offerings' => $results]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    // -------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------

    private function _bucketLabel($rawId, string $fallback): string
    {
        $rawId = trim((string)($rawId ?? ''));
        return $rawId === '' ? $fallback : $rawId;
    }

    // Extracts the leading number from a year-level label, falling back
    // to hardcoded values for "Grade 11"/"Grade 12", or "0" if nothing matches.
    private function _yearLevelDigits(string $yearLevel): string
    {
        if (preg_match('/(\d+)/', $yearLevel, $m)) return $m[1];
        if (stripos($yearLevel, 'Grade 11') !== false) return '11';
        if (stripos($yearLevel, 'Grade 12') !== false) return '12';
        return '0';
    }

    private function _toApiRow(array $row): array
    {
        $teacherName = trim(($row['teacherLastName'] ?? '') . ', ' . ($row['teacherFirstName'] ?? ''), ', ');
        $yearLevelLabel = trim((string)($row['yearLevel'] ?? '')) !== '' ? $row['yearLevel'] : '(No Year Level)';
        $programCode = $row['programID'] ? ($row['programCode'] ?: $row['programID']) : '(No Program)';
        $sectionName = $row['sectionID'] ? ($row['sectionName'] ?: $row['sectionID']) : '(No Section)';
        $classCode = $programCode . '-' . $this->_yearLevelDigits($yearLevelLabel) . '-' . $sectionName;

        return [
            'teacherClassLoadID'    => (string)$row['teacherClassLoadID'],
            'teacherSubjectLoadID'  => (string)($row['teacherSubjectLoadID'] ?? ''),
            'teacherID'             => (string)($row['teacherID'] ?? ''),
            'subjectID'             => (string)($row['subjectID'] ?? ''),
            'academicYear'          => (string)($row['academicYear'] ?? ''),
            'semester'              => (string)($row['semester'] ?? ''),
            'moodleCourseShortname' => $row['moodleCourseShortname'],
            'teacherName'           => $teacherName,
            'subjectCode'           => $row['subjectCode'],
            'subjectTitle'          => $row['subjectTitle'],
            'programID'             => $row['programID'],
            'programCode'           => $programCode,
            'yearLevel'             => $yearLevelLabel,
            'sectionID'             => $row['sectionID'],
            'sectionName'           => $sectionName,
            'classCode'             => $classCode,
            'isActive'              => (bool)((int)($row['isActive'] ?? 0)),
            'createdBy'             => $row['createdBy'],
            'dateCreated'           => $row['dateCreated'],
            'modifiedBy'            => $row['modifiedBy'],
            'lastModified'          => $row['lastModified'],
        ];
    }

    private function _toOfferingRow(array $row): array
    {
        $teacherName = trim(($row['teacherLastName'] ?? '') . ', ' . ($row['teacherFirstName'] ?? ''), ', ');
        $programCode = $row['programID'] ? ($row['programCode'] ?: $row['programID']) : '(No Program)';
        $sectionName = $row['sectionID'] ? ($row['sectionName'] ?: $row['sectionID']) : '(No Section)';
        $yearLevelLabel = trim((string)($row['yearLevel'] ?? '')) !== '' ? $row['yearLevel'] : '(No Year Level)';

        return [
            'teacherClassLoadID'    => (string)$row['teacherClassLoadID'],
            'subjectCode'           => $row['subjectCode'],
            'subjectTitle'          => $row['subjectTitle'],
            'teacherName'           => $teacherName,
            'moodleCourseShortname' => $row['moodleCourseShortname'],
            'programCode'           => $programCode,
            'yearLevel'             => $yearLevelLabel,
            'sectionName'           => $sectionName,
            'enrolledCount'         => (int)$row['enrolledCount'],
            // How many of the currently ENROLLED students already have a
            // confirmed, synced Moodle account, out of enrolledCount above.
            'moodleReadyCount'      => (int)($row['moodleReadyCount'] ?? 0),
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
        error_log('[SubjectLoading][TeacherClassLoadController] ' . get_class($e) . ': ' . $e->getMessage()
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
