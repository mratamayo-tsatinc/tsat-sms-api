<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Mapper;
use App\Models\SequenceGenerator;

class AdmissionController
{
    // Returns the academic year start based on the current date.
    // The academic year starts June 1; before June, the previous calendar year is used.
    private function _currentAcademicStartYear(): int
    {
        $now = new \DateTime('now');
        $month = (int)$now->format('n');
        $year  = (int)$now->format('Y');
        return $month >= 6 ? $year : $year - 1;
    }

    private function _formatStudentNumber(int $academicYearStart, int $count): string
    {
        return (string)$academicYearStart . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/admission/next-number?academicYear=2026
    // Previews the next student number for the given academic year.
    // Peek only — does NOT reserve the number.
    // ─────────────────────────────────────────────────────────────────
    public function nextNumberPreview()
    {
        $ayParam = trim($_GET['academicYear'] ?? '');
        $ay = $ayParam !== '' ? (int)$ayParam : $this->_currentAcademicStartYear();

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT studentCount FROM tblStudentNumberGenerator WHERE academicYear = ?");
        $stmt->execute([(string)$ay]);
        $row = $stmt->fetch();
        $count = $row ? (int)$row['studentCount'] : 0;

        echo json_encode([
            'ok'                => true,
            'academicYearStart' => $ay,
            'academicYear'      => $ay . '-' . ($ay + 1),
            'preview'           => $this->_formatStudentNumber($ay, $count + 1),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/admission/lookups?categories=SEX,CIVIL_STATUS,RELIGION,CONTACT_RELATIONSHIP
    // Returns lookup values for the specified categories from ref_lookup_values.
    // ─────────────────────────────────────────────────────────────────
    public function lookups()
    {
        $raw = trim($_GET['categories'] ?? '');
        $categories = $raw === '' ? [] : explode(',', $raw);
        $out = (new \App\Services\ReferenceDataService())->getLookupValues($categories);
        echo json_encode(['ok' => true, 'lookups' => $out]);
    }

    // Checks whether an LRN is already assigned to another student.
    private function _lrnDuplicateExists($db, string $lrn, string $excludeStudentNumber = ''): bool
    {
        if ($lrn === '') return false;
        $stmt = $db->prepare("SELECT studentNumber FROM tblStudents WHERE lrn = ? AND studentNumber <> ? LIMIT 1");
        $stmt->execute([$lrn, $excludeStudentNumber]);
        return (bool)$stmt->fetch();
    }

    // Checks whether a student with the same last name, first name, middle name, and
    // birth date already exists. Skips the check when any of those fields is blank.
    private function _nameDobDuplicateExists($db, array $s, string $excludeStudentNumber = ''): bool
    {
        $lastName  = trim((string)($s['lastName']  ?? ''));
        $firstName = trim((string)($s['firstName'] ?? ''));
        $birthDate = trim((string)($s['birthDate'] ?? ''));
        if ($lastName === '' || $firstName === '' || $birthDate === '') return false;

        $stmt = $db->prepare("
            SELECT studentNumber FROM tblStudents
            WHERE lastName = ? AND firstName = ?
              AND COALESCE(middleName,'') = ?
              AND birthDate = ?
              AND studentNumber <> ?
            LIMIT 1
        ");
        $stmt->execute([
            $lastName, $firstName, trim((string)($s['middleName'] ?? '')), $birthDate, $excludeStudentNumber,
        ]);
        return (bool)$stmt->fetch();
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/admission/students/stub
    // Body: { student: { lastName, firstName, middleName?, middleInitial?,
    //                     nameExtension?, lrn? }, createdBy }
    //
    // Atomically: reserves the next student number for the current
    // academic year via SequenceGenerator::reserveSequence() (which does
    // its own SELECT ... FOR UPDATE), lets MySQL assign studentID via
    // AUTO_INCREMENT (studentID is AUTO_INCREMENT — this REQUIRES the
    // schema to already have that column defined as AUTO_INCREMENT), inserts the
    // row, and returns the same response shape
    // createAdmissionStudentForOfficer(stub:true) returned in GAS.
    //
    // inserts the row, and returns the assigned student number.
    // ─────────────────────────────────────────────────────────────────
    public function createStub()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $s = $input['student'] ?? [];
        $createdBy = trim((string)($input['createdBy'] ?? ''));

        $lastName  = strtoupper(trim((string)($s['lastName']  ?? '')));
        $firstName = strtoupper(trim((string)($s['firstName'] ?? '')));
        if ($lastName === '')  { http_response_code(400); echo json_encode(['error' => 'Last name is required.']); return; }
        if ($firstName === '') { http_response_code(400); echo json_encode(['error' => 'First name is required.']); return; }

        $lrn = trim((string)($s['lrn'] ?? ''));
        $db  = Database::getConnection();

        if ($this->_lrnDuplicateExists($db, $lrn)) {
            http_response_code(409);
            echo json_encode(['error' => 'A student with the same LRN already exists.']);
            return;
        }

        $academicYearStart = $this->_currentAcademicStartYear();
        $now = date('Y-m-d H:i:s');

        // reserveSequence() manages its own transaction internally.
        // Do NOT wrap this call in an outer beginTransaction().
        try {
            $count = SequenceGenerator::reserveSequence($db, (string)$academicYearStart);
            $studentNumber = $this->_formatStudentNumber($academicYearStart, $count);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Unable to reserve a student number: ' . $e->getMessage()]);
            return;
        }

        // The number is now reserved and committed. A single INSERT is atomic
        // on its own. If this INSERT fails, the reserved number is burned (a
        // permanent gap) — the contract is non-repeating, not gapless.
        try {
            $stmt = $db->prepare("
                INSERT INTO tblStudents (
                    studentNumber, lrn, lastName, firstName, middleName,
                    middleInitial, nameExtension, yearRegistered,
                    createdBy, dateCreated
                ) VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $studentNumber,
                $lrn,
                $lastName,
                $firstName,
                strtoupper(trim((string)($s['middleName'] ?? ''))),
                trim((string)($s['middleInitial'] ?? '')),
                strtoupper(trim((string)($s['nameExtension'] ?? ''))),
                (string)$academicYearStart,
                $createdBy,
                $now,
            ]);
            $studentID = (int)$db->lastInsertId();
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Student number ' . $studentNumber . ' was reserved but the record could not be saved: ' . $e->getMessage()]);
            return;
        }

        $nextPeek = $count + 1;

        echo json_encode([
            'ok'                => true,
            'studentID'         => $studentID,
            'studentNumber'     => $studentNumber,
            'academicYearStart' => $academicYearStart,
            'academicYear'      => $academicYearStart . '-' . ($academicYearStart + 1),
            'generatorCount'    => $count,
            'stub'              => true,
            'nextPreview'       => $this->_formatStudentNumber($academicYearStart, $nextPeek),
            'message'           => 'Student identity registered. Student Number ' . $studentNumber . ' has been assigned.',
        ]);
    }

    public function bootstrap()
    {
        $db = Database::getConnection();

        $programs = $db->query("SELECT * FROM tblPrograms")->fetchAll();
        $students = $db->query("SELECT * FROM tblStudents")->fetchAll();
        $details  = $db->query("SELECT * FROM tblAdmissionDetails")->fetchAll();

        $mappedStudents = array_map([Mapper::class, 'toFrontendArray'], $students);

        echo json_encode([
            'lookups' => [
                'programs' => $programs,
                'tracks'   => [],
                'strands'  => [],
                'bundles'  => []
            ],
            'students' => $mappedStudents,
            'details'  => $details
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/admission/students
    //   ?q=<search text>     (required — min 2 characters)
    //   &limit=<n>            (optional — default 20, hard cap 50)
    //
    // Prefix match on studentNumber, lastName, and firstName. Also
    // matches on "FirstName LastName" and "LastName, FirstName" patterns.
    // Minimum 2-character query enforced server-side.
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
			   OR CONCAT(lastname, ', ', firstName) LIKE :q5
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
		$stmt->bindValue(':q5', $prefix, \PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $suggestions = [];
        foreach ($rows as $row) {
            $name = $this->_buildStudentSuggestionName($row);
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

    // Builds "[lastName] [nameExtension], [firstName] [middleName]" display name.
    // middleName is preferred over middleInitial; falls back to middleInitial if blank.
    private function _buildStudentSuggestionName(array $row): string
    {
        $lastName      = trim((string)($row['lastName'] ?? ''));
        $nameExtension = trim((string)($row['nameExtension'] ?? ''));
        $firstName     = trim((string)($row['firstName'] ?? ''));
        $middleName    = trim((string)($row['middleName'] ?? '')) ?: trim((string)($row['middleInitial'] ?? ''));

        $lastPart  = implode(' ', array_filter([$lastName, $nameExtension], fn($p) => $p !== ''));
        $firstPart = implode(' ', array_filter([$firstName, $middleName], fn($p) => $p !== ''));

        return implode(', ', array_filter([$lastPart, $firstPart], fn($p) => $p !== ''));
    }

    // Builds the full student result object used by search() and updateStudent().
    // Joins tblAdmissionDetails and tblEditLocks so the frontend receives
    // editableStudent, editableDetails, documents[], and lockInfo in one response.
    // lockInfo.isOwnedByCurrentUser is always false — the frontend recomputes
    // it locally by comparing lockInfo.lockedByEmail against its own known email.
    private function _buildStudentResult($db, array $s): array
    {
        $sn = (string)($s['studentNumber'] ?? '');

        $dStmt = $db->prepare("SELECT * FROM tblAdmissionDetails WHERE studentNumber = ?");
        $dStmt->execute([$sn]);
        $d = $dStmt->fetch() ?: [];

        $lStmt = $db->prepare("SELECT * FROM tblEditLocks WHERE studentNumber = ? AND expiresAt > NOW()");
        $lStmt->execute([$sn]);
        $lock = $lStmt->fetch();

        $editableStudent = Mapper::toFrontendArray($s);
	// Alias: DB column is guardianRelationToStudent, frontend field/select id is
	// guardianRelationship — Mapper doesn't (currently) translate this one.
	$editableStudent['guardianRelationship'] = (string)($s['guardianRelationToStudent'] ?? '');
        $editableDetails = [
            'medicalHistory'         => (string)($d['medicalHistory'] ?? ''),
            'reportCardStatus'       => (string)($d['reportCardStatus'] ?? ''),
            'reportCardUpload'       => (string)($d['reportCardUpload'] ?? ''),
            'goodMoralStatus'        => (string)($d['goodMoralStatus'] ?? ''),
            'goodMoralUpload'        => (string)($d['goodMoralUpload'] ?? ''),
            'birthCertificateStatus' => (string)($d['birthCertificateStatus'] ?? ''),
            'birthCertificateUpload' => (string)($d['birthCertificateUpload'] ?? ''),
            'notes'                  => (string)($d['notes'] ?? ''),
        ];

        return [
            'studentID'             => (string)($s['studentID'] ?? ''),
            'studentNumber'         => $sn,
            'lrn'                   => (string)($s['lrn'] ?? ''),
            'fullName'              => $this->_buildStudentSuggestionName($s),
            'programID'             => (string)($s['programID'] ?? ''),
            'birthDate'             => (string)($s['birthDate'] ?? ''),
            'birthPlace'            => (string)($s['birthPlace'] ?? ''),
            'gender'                => (string)($s['gender'] ?? ''),
            'civilStatus'           => (string)($s['civilStatus'] ?? ''),
            'religion'              => (string)($s['religion'] ?? ''),
            'address'               => (string)($s['address'] ?? ''),
            'contactNumber'         => (string)($s['contactNumber'] ?? ''),
            'emailAddress'          => (string)($s['emailAddress'] ?? ''),
            'fatherName'            => (string)($s['fatherName'] ?? ''),
            'fatherContactNumber'   => (string)($s['fatherContactNumber'] ?? ''),
            'motherName'            => (string)($s['motherName'] ?? ''),
            'motherContactNumber'   => (string)($s['motherContactNumber'] ?? ''),
            'guardianName'          => (string)($s['guardianName'] ?? ''),
            'guardianContactNumber' => (string)($s['guardianContactNumber'] ?? ''),
            'lastAttendedSchool'    => (string)($s['lastAttendedSchool'] ?? ''),
            'yearRegistered'        => (string)($s['yearRegistered'] ?? ''),
            'medicalHistory'        => $editableDetails['medicalHistory'],
            'editableStudent'       => $editableStudent,
            'editableDetails'       => $editableDetails,
            'documents' => [
                ['label' => 'Report Card',       'status' => $editableDetails['reportCardStatus'],       'upload' => $editableDetails['reportCardUpload']],
                ['label' => 'Good Moral',        'status' => $editableDetails['goodMoralStatus'],        'upload' => $editableDetails['goodMoralUpload']],
                ['label' => 'Birth Certificate', 'status' => $editableDetails['birthCertificateStatus'], 'upload' => $editableDetails['birthCertificateUpload']],
            ],
            'lockInfo' => $lock ? [
                'locked' => true,
                'lockedByEmail' => $lock['lockedByEmail'],
                'expiresAt' => $lock['expiresAt'],
                'isOwnedByCurrentUser' => false,
            ] : ['locked' => false, 'lockedByEmail' => '', 'expiresAt' => '', 'isOwnedByCurrentUser' => false],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/admission/search?q=<text>
    //
    // Searches tblStudents by studentNumber, lastName, firstName (prefix match),
    // or LRN (exact match). Returns full student records including admission
    // details and edit lock info for each match. Hard cap: 50 results.
    //
    // Prefix match on name/number fields; exact match on LRN since users
    // typically paste or scan a full LRN rather than typing a partial one.
    // ─────────────────────────────────────────────────────────────────
    public function search()
    {
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Search text is required.']);
            return;
        }

        $db = Database::getConnection();
        $prefix = $q . '%';
        $stmt = $db->prepare("
            SELECT * FROM tblStudents
            WHERE studentNumber LIKE :q1
               OR lastName      LIKE :q2
               OR firstName     LIKE :q3
               OR lrn = :qexact
            ORDER BY lastName, firstName
            LIMIT 50
        ");
        $stmt->execute([':q1' => $prefix, ':q2' => $prefix, ':q3' => $prefix, ':qexact' => $q]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->_buildStudentResult($db, $row);
        }

        echo json_encode(['ok' => true, 'totalStudents' => count($out), 'students' => $out]);
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/admission/students/update
    // Body: { studentNumber, student: {...full profile fields...},
    //         details: {...}, modifiedBy, sessionToken }
    //
    // Updates a student's full profile and admission details.
    // Requires the caller to hold an active edit lock for the studentNumber
    // (sessionToken must match tblEditLocks.sessionToken).
    // ─────────────────────────────────────────────────────────────────
    public function updateStudent()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $sn = trim((string)($input['studentNumber'] ?? ''));
        $s  = $input['student'] ?? [];
	// Frontend posts guardianRelationship; DB/insert-update code expects
	// guardianRelationToStudent. Normalize here so the value actually saves.
	$s['guardianRelationToStudent'] = $s['guardianRelationship'] ?? ($s['guardianRelationToStudent'] ?? null);
        $d  = $input['details'] ?? [];
        $modifiedBy = trim((string)($input['modifiedBy'] ?? ''));
        $token = trim((string)($input['sessionToken'] ?? ''));

        if ($sn === '') {
            http_response_code(400);
            echo json_encode(['error' => 'studentNumber is required.']);
            return;
        }

        $db = Database::getConnection();

        // Lock check — sessionToken must match the active lock on this record.
        $lockStmt = $db->prepare("SELECT sessionToken FROM tblEditLocks WHERE studentNumber = ? AND expiresAt > NOW()");
        $lockStmt->execute([$sn]);
        $lockRow = $lockStmt->fetch();
        if (!$lockRow || !hash_equals((string)$lockRow['sessionToken'], $token)) {
            http_response_code(423);
            echo json_encode(['error' => 'You must hold an active edit lock on this record to save changes.']);
            return;
        }

        // Required-field validation.
        foreach ([
            'lastName' => 'Last name', 'firstName' => 'First name', 'birthDate' => 'Date of birth',
            'gender' => 'Gender', 'address' => 'Address', 'guardianName' => 'Guardian name',
            'guardianContactNumber' => 'Guardian contact number',
        ] as $field => $label) {
            if (trim((string)($s[$field] ?? '')) === '') {
                http_response_code(400);
                echo json_encode(['error' => $label . ' is required.']);
                return;
            }
        }

        // Duplicate checks (excluding self).
        if ($this->_lrnDuplicateExists($db, trim((string)($s['lrn'] ?? '')), $sn)) {
            http_response_code(409);
            echo json_encode(['error' => 'A student with the same LRN already exists.']);
            return;
        }
        if ($this->_nameDobDuplicateExists($db, $s, $sn)) {
            http_response_code(409);
            echo json_encode(['error' => 'A student with the same name and birth date already exists.']);
            return;
        }

        $s['modifiedBy']    = $modifiedBy;
        $s['lastModified']  = date('Y-m-d H:i:s');
        $s['studentNumber'] = $sn;

        try {
            $db->beginTransaction();
            $this->_upsertStudent($db, $s, 'update');
            $this->_upsertDetails($db, $sn, array_merge($d, ['_email' => $modifiedBy, '_now' => $s['lastModified']]));
            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Update failed: ' . $e->getMessage()]);
            return;
        }

        $fetch = $db->prepare("SELECT * FROM tblStudents WHERE studentNumber = ?");
        $fetch->execute([$sn]);
        $row = $fetch->fetch();
        $updated = $row ? $this->_buildStudentResult($db, $row) : null;

        echo json_encode([
            'ok'             => true,
            'studentID'      => $updated['studentID'] ?? '',
            'studentNumber'  => $sn,
            'updatedStudent' => $updated,
            'message'        => 'Student profile updated: ' . $sn . '.',
        ]);
    }

    public function mirror()
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['operation']) || empty($input['student'])) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required fields: operation, student"]);
            return;
        }

        $operation = $input['operation']; // 'create', 'create_stub', 'update'
        $student   = $input['student'];
        $details   = $input['details'] ?? null;
        $db        = Database::getConnection();

        try {
            if ($operation === 'create' || $operation === 'create_stub') {
                $this->_upsertStudent($db, $student, 'insert');
                if ($details && $operation === 'create') {
                    $this->_upsertDetails($db, $student['studentNumber'], $details);
                }
            } elseif ($operation === 'update') {
                $this->_upsertStudent($db, $student, 'update');
                if ($details) {
                    $this->_upsertDetails($db, $student['studentNumber'], $details);
                }
            } else {
                http_response_code(400);
                echo json_encode(["error" => "Unknown operation: {$operation}"]);
                return;
            }

            echo json_encode([
                "status"        => "success",
                "operation"     => $operation,
                "studentNumber" => $student['studentNumber'] ?? null
            ]);

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Mirror failed: " . $e->getMessage()]);
        }
    }

    public function store()
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid JSON payload"]);
            return;
        }

        $db = Database::getConnection();
        $this->_upsertStudent($db, $input, 'insert');

        echo json_encode([
            "status"        => "success",
            "studentNumber" => $input['studentNumber'] ?? null
        ]);
    }

    // ── Shared helpers ────────────────────────────────────────────────

    private function _upsertStudent($db, array $s, string $mode)
    {
        if ($mode === 'insert') {
            $stmt = $db->prepare("
                INSERT INTO tblStudents (
                    studentID, studentNumber, lrn,
                    lastName, firstName, middleName, middleInitial, nameExtension,
                    programID, trackID, strandID, bundleID,
                    address, region, province, city_municipality, barangay, district, zipcode,
                    birthDate, birthPlace, civilStatus, religion, gender,
                    contactNumber, telephone, emailAddress,
                    fatherName, fatherAddress, fatherContactNumber,
                    motherName, motherAddress, motherContactNumber,
                    guardianName, guardianAddress, guardianContactNumber,
                    guardianRelationToStudent,
                    lastAttendedSchool, lastAttendedSchoolAddress,
                    yearRegistered, createdBy, dateCreated, modifiedBy, lastModified
                ) VALUES (
                    ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,
                    ?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?
                )
                ON DUPLICATE KEY UPDATE
                    lrn                     = VALUES(lrn),
                    lastName                = VALUES(lastName),
                    firstName               = VALUES(firstName),
                    middleName              = VALUES(middleName),
                    middleInitial           = VALUES(middleInitial),
                    nameExtension           = VALUES(nameExtension),
                    programID               = VALUES(programID),
                    address                 = VALUES(address),
                    region                  = VALUES(region),
                    province                = VALUES(province),
                    city_municipality       = VALUES(city_municipality),
                    barangay                = VALUES(barangay),
                    district                = VALUES(district),
                    zipcode                 = VALUES(zipcode),
                    birthDate               = VALUES(birthDate),
                    birthPlace              = VALUES(birthPlace),
                    civilStatus             = VALUES(civilStatus),
                    religion                = VALUES(religion),
                    gender                  = VALUES(gender),
                    contactNumber           = VALUES(contactNumber),
                    telephone               = VALUES(telephone),
                    emailAddress            = VALUES(emailAddress),
                    fatherName              = VALUES(fatherName),
                    fatherAddress           = VALUES(fatherAddress),
                    fatherContactNumber     = VALUES(fatherContactNumber),
                    motherName              = VALUES(motherName),
                    motherAddress           = VALUES(motherAddress),
                    motherContactNumber     = VALUES(motherContactNumber),
                    guardianName            = VALUES(guardianName),
                    guardianAddress         = VALUES(guardianAddress),
                    guardianContactNumber   = VALUES(guardianContactNumber),
                    guardianRelationToStudent = VALUES(guardianRelationToStudent),
                    lastAttendedSchool      = VALUES(lastAttendedSchool),
                    lastAttendedSchoolAddress = VALUES(lastAttendedSchoolAddress),
                    modifiedBy              = VALUES(modifiedBy),
                    lastModified            = VALUES(lastModified)
            ");
        } else {
            $stmt = $db->prepare("
                UPDATE tblStudents SET
                    lrn                     = ?,
                    lastName                = ?,
                    firstName               = ?,
                    middleName              = ?,
                    middleInitial           = ?,
                    nameExtension           = ?,
                    programID               = ?,
                    address                 = ?,
                    region                  = ?,
                    province                = ?,
                    city_municipality       = ?,
                    barangay                = ?,
                    district                = ?,
                    zipcode                 = ?,
                    birthDate               = ?,
                    birthPlace              = ?,
                    civilStatus             = ?,
                    religion                = ?,
                    gender                  = ?,
                    contactNumber           = ?,
                    telephone               = ?,
                    emailAddress            = ?,
                    fatherName              = ?,
                    fatherAddress           = ?,
                    fatherContactNumber     = ?,
                    motherName              = ?,
                    motherAddress           = ?,
                    motherContactNumber     = ?,
                    guardianName            = ?,
                    guardianAddress         = ?,
                    guardianContactNumber   = ?,
                    guardianRelationToStudent = ?,
                    lastAttendedSchool      = ?,
                    lastAttendedSchoolAddress = ?,
                    modifiedBy              = ?,
                    lastModified            = ?
                WHERE studentNumber = ?
            ");
        }

        if ($mode === 'insert') {
            $stmt->execute([
                $s['studentID']                 ?? null,
                $s['studentNumber']             ?? null,
                $s['lrn']                       ?? null,
                $s['lastName']                  ?? null,
                $s['firstName']                 ?? null,
                $s['middleName']                ?? null,
                $s['middleInitial']             ?? null,
                $s['nameExtension']             ?? null,
                $s['programID']                 ?? null,
                $s['trackID']                   ?? '',
                $s['strandID']                  ?? '',
                $s['bundleID']                  ?? '',
                $s['address']                   ?? null,
                $s['region']                    ?? null,
                $s['province']                  ?? null,
                $s['cityMunicipality']          ?? null,
                $s['barangay']                  ?? null,
                $s['district']                  ?? null,
                $s['zipCode']                   ?? null,
                $s['birthDate']                 ?? null,
                $s['birthPlace']                ?? null,
                $s['civilStatus']               ?? null,
                $s['religion']                  ?? null,
                $s['gender']                    ?? null,
                $s['contactNumber']             ?? null,
                $s['telephone']                 ?? null,
                $s['emailAddress']              ?? null,
                $s['fatherName']                ?? null,
                $s['fatherAddress']             ?? null,
                $s['fatherContactNumber']       ?? null,
                $s['motherName']                ?? null,
                $s['motherAddress']             ?? null,
                $s['motherContactNumber']       ?? null,
                $s['guardianName']              ?? null,
                $s['guardianAddress']           ?? null,
                $s['guardianContactNumber']     ?? null,
                $s['guardianRelationToStudent'] ?? null,
                $s['lastAttendedSchool']        ?? null,
                $s['lastAttendedSchoolAddress'] ?? null,
                $s['yearRegistered']            ?? null,
                $s['createdBy']                 ?? null,
                $s['dateCreated']               ?? null,
                $s['modifiedBy']                ?? null,
                $s['lastModified']              ?? null,
            ]);
        } else {
            $stmt->execute([
                $s['lrn']                       ?? null,
                $s['lastName']                  ?? null,
                $s['firstName']                 ?? null,
                $s['middleName']                ?? null,
                $s['middleInitial']             ?? null,
                $s['nameExtension']             ?? null,
                $s['programID']                 ?? null,
                $s['address']                   ?? null,
                $s['region']                    ?? null,
                $s['province']                  ?? null,
                $s['cityMunicipality']          ?? null,
                $s['barangay']                  ?? null,
                $s['district']                  ?? null,
                $s['zipCode']                   ?? null,
                $s['birthDate']                 ?? null,
                $s['birthPlace']                ?? null,
                $s['civilStatus']               ?? null,
                $s['religion']                  ?? null,
                $s['gender']                    ?? null,
                $s['contactNumber']             ?? null,
                $s['telephone']                 ?? null,
                $s['emailAddress']              ?? null,
                $s['fatherName']                ?? null,
                $s['fatherAddress']             ?? null,
                $s['fatherContactNumber']       ?? null,
                $s['motherName']                ?? null,
                $s['motherAddress']             ?? null,
                $s['motherContactNumber']       ?? null,
                $s['guardianName']              ?? null,
                $s['guardianAddress']           ?? null,
                $s['guardianContactNumber']     ?? null,
                $s['guardianRelationToStudent'] ?? null,
                $s['lastAttendedSchool']        ?? null,
                $s['lastAttendedSchoolAddress'] ?? null,
                $s['modifiedBy']                ?? null,
                $s['lastModified']              ?? null,
                $s['studentNumber']             ?? null,
            ]);
        }
    }

    private function _upsertDetails($db, string $studentNumber, array $d)
    {
        $email = $d['_email'] ?? null;
        $now   = $d['_now']   ?? null;

        // Check if record already exists to preserve createdBy/dateCreated
        $existing = $db->prepare("SELECT createdBy, dateCreated FROM tblAdmissionDetails WHERE studentNumber = ?");
        $existing->execute([$studentNumber]);
        $existingRow = $existing->fetch();

        $createdBy    = $existingRow ? $existingRow['createdBy']   : $email;
        $dateCreated  = $existingRow ? $existingRow['dateCreated'] : $now;
        $modifiedBy   = $existingRow ? $email : null;
        $lastModified = $existingRow ? $now   : null;

        $stmt = $db->prepare("
            INSERT INTO tblAdmissionDetails (
                studentNumber, medicalHistory,
                reportCardStatus, reportCardUpload,
                goodMoralStatus, goodMoralUpload,
                birthCertificateStatus, birthCertificateUpload,
                notes, createdBy, dateCreated, modifiedBy, lastModified
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                medicalHistory         = VALUES(medicalHistory),
                reportCardStatus       = VALUES(reportCardStatus),
                reportCardUpload       = VALUES(reportCardUpload),
                goodMoralStatus        = VALUES(goodMoralStatus),
                goodMoralUpload        = VALUES(goodMoralUpload),
                birthCertificateStatus = VALUES(birthCertificateStatus),
                birthCertificateUpload = VALUES(birthCertificateUpload),
                notes                  = VALUES(notes),
                modifiedBy             = VALUES(modifiedBy),
                lastModified           = VALUES(lastModified)
        ");

        $stmt->execute([
            $studentNumber,
            $d['medicalHistory']         ?? null,
            $d['reportCardStatus']       ?? null,
            $d['reportCardUpload']       ?? null,
            $d['goodMoralStatus']        ?? null,
            $d['goodMoralUpload']        ?? null,
            $d['birthCertificateStatus'] ?? null,
            $d['birthCertificateUpload'] ?? null,
            $d['notes']                  ?? null,
            $createdBy,
            $dateCreated,
            $modifiedBy,
            $lastModified,
        ]);
    }
}
