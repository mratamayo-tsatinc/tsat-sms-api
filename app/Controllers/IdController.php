<?php

namespace App\Controllers;

use App\Core\Database;

class IdController
{
    private const VALID_STATUSES = ['PENDING', 'EXPORTED', 'PRINTED'];

    // ─────────────────────────────────────────────────────────────────────────
    // Shared SQL fragment selecting all columns needed across the student(),
    // enrolled(), studentIndex(), and applicationList() endpoints.
    //
    // Aliased columns resolve JOIN ambiguity:
    //   reg_studentNumber  → $reg['studentNumber']
    //   p_programID        → $program['programID']
    //   sec_sectionID      → $section['sectionID']
    //   app_*              → $idApp / $application fields
    //
    // NOTE: do NOT pass these through Mapper::toFrontendArray() — the Mapper
    // renames city_municipality → cityMunicipality and zipcode → zipCode,
    // which would break the card field builder's expected column names.
    // ─────────────────────────────────────────────────────────────────────────
    private const JOIN_COLUMNS = "
        s.studentNumber,
        s.lastName,
        s.firstName,
        s.middleName,
        s.nameExtension,
        s.birthDate,
        s.address,
        s.barangay,
        s.city_municipality,
        s.province,
        s.district,
        s.zipcode,
        s.guardianName,
        s.guardianRelationToStudent,
        s.guardianContactNumber,

        r.RegistrationNumber,
        r.studentNumber       AS reg_studentNumber,
        r.programID,
        r.sectionID,
        r.yearLevel,
        r.academicYear,
        r.semester,

        p.programID           AS p_programID,
        p.programCode,
        p.programDescription,

        sec.sectionID         AS sec_sectionID,
        sec.sectionName,

        a.idApplicationID,
        a.registrationNumber  AS app_registrationNumber,
        a.academicYear        AS app_academicYear,
        a.semester            AS app_semester,
        a.status,
        a.datePrepared,
        a.frontPrintedAt,
        a.frontPrintedBy,
        a.backPrintedAt,
        a.backPrintedBy
    ";

    public function bootstrap()
    {
        $db = Database::getConnection();

        $applications  = $db->query("SELECT * FROM tblIDApplications ORDER BY idApplicationID DESC")->fetchAll();
        $students      = $db->query("SELECT studentNumber, lastName, firstName, middleName, nameExtension FROM tblStudents")->fetchAll();
        $registrations = $db->query("SELECT RegistrationNumber, studentNumber, programID, sectionID, yearLevel, academicYear, semester FROM tblRegistrations")->fetchAll();
        $programs      = $db->query("SELECT * FROM tblPrograms")->fetchAll();
        $sections      = $db->query("SELECT * FROM tblSections")->fetchAll();

        echo json_encode([
            'applications'  => $applications,
            'students'      => $students,
            'registrations' => $registrations,
            'programs'      => $programs,
            'sections'      => $sections,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/id/student
    //   ?studentNumber=SNO
    //   &academicYear=2026-2027     (required)
    //   &semester=1ST%20SEMESTER    (required)
    //
    // Returns the student's registration, program, section, and ID application
    // for the given active term. Returns 404 if no registration is found.
    // ─────────────────────────────────────────────────────────────────────────
    public function student()
    {
        $sno = trim($_GET['studentNumber'] ?? '');
        $ay  = trim($_GET['academicYear']  ?? '');
        $sem = trim($_GET['semester']      ?? '');

        if ($sno === '' || $ay === '' || $sem === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'studentNumber, academicYear, and semester are required.']);
            return;
        }

        $db   = Database::getConnection();
        $stmt = $db->prepare("
            SELECT " . self::JOIN_COLUMNS . "
            FROM tblStudents s
            JOIN tblRegistrations r
                ON  r.studentNumber = s.studentNumber
                AND r.academicYear  = :academicYear
                AND r.semester      = :semester
            JOIN tblPrograms p    ON p.programID   = r.programID
            JOIN tblSections sec  ON sec.sectionID = r.sectionID
            LEFT JOIN tblIDApplications a
                ON a.registrationNumber = r.RegistrationNumber
            WHERE s.studentNumber = :studentNumber
            LIMIT 1
        ");
        $stmt->execute([
            ':studentNumber' => $sno,
            ':academicYear'  => $ay,
            ':semester'      => $sem,
        ]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Student not found: ' . $sno]);
            return;
        }

        $nested = $this->_nestRow_($row);
        echo json_encode($this->_buildCardPayload(
            $nested['student'], $nested['reg'], $nested['program'], $nested['section'], $nested['idApp']
        ));
	//$nested = $this->_nestRow_($row);
        //$cardPayload = $this->_buildCardPayload(
        //    $nested['student'], $nested['reg'], $nested['program'], $nested['section'], $nested['idApp']
        //);

        // @deprecated — Compatibility shim: merges both the flat card* shape
        // and the old nested { student, reg, program, section, idApp } shape
        // into one response. This shim can be removed once id.html is confirmed
        // live and nothing reads the nested keys anymore.
        //echo json_encode(array_merge($cardPayload, [
        //    'student' => $nested['student'],
        //    'reg'     => $nested['reg'],
        //    'program' => $nested['program'],
        //    'section' => $nested['section'],
        //    'idApp'   => $nested['idApp'],
        //]));
    }

    // Builds the card payload response for a student.
    // Formats all card display fields: full name, program, year level,
    // address lines, guardian info, and birth date.
    private function _buildCardPayload(array $student, array $reg, array $program, array $section, ?array $idApp): array
    {
        $card = $this->_buildCardFields($student, $reg, $program, $section);

        $regNo = trim((string)($reg['RegistrationNumber'] ?? ''));
        $idApplicationID = $idApp ? trim((string)($idApp['idApplicationID'] ?? '')) : '';

        return [
            'ok' => true,
            'studentNumber'      => $card['cardStudentNumber'],
            'registrationNumber' => $regNo,
            'idApplicationID'    => $idApplicationID,
            'cardStudentNumber'  => $card['cardStudentNumber'],
            'cardFullName'       => $card['cardFullName'],
            'cardStudentName'    => $card['searchName'],
            'studentName'        => $card['searchName'],
            'cardProgram'        => $card['cardProgram'],
            'programCode'        => $card['programCode'],
            'cardYearLevel'      => $card['cardYearLevel'],
            'cardSectionName'    => $card['cardSectionName'],
            'cardBirthDate'      => $card['cardBirthDate'],
            'cardAddressLine1'   => $card['cardAddressLine1'],
            'cardAddressLine2'   => $card['cardAddressLine2'],
            'cardAddressLine3'   => $card['cardAddressLine3'],
            'cardGuardianLabel'  => $card['cardGuardianLabel'],
            'cardGuardianContactNo' => $card['cardGuardianContactNo'],
            'cardStudentProgram'   => $card['cardProgram'],
            'cardStudentYearLevel' => $card['cardYearLevel'],
            'sectionName'          => $card['cardSectionName'],
            'cardGuardianContactNumber' => $card['cardGuardianContactNo'],
            'lastName'      => $card['lastName'],
            'firstName'     => $card['firstName'],
            'middleName'    => $card['middleName'],
            'middleInitial' => $card['mi'],
            'nameExtension' => $card['nameExtension'],
            'barangay'           => $card['barangay'],
            'city_municipality'  => $card['city'],
            'province'           => $card['province'],
            'district'           => $card['district'],
            'zipcode'            => $card['zipcode'],
            'guardianName'                  => $card['guardianName'],
            'guardianRelationToStudent'     => $card['guardianRel'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Formats raw student/registration/program/section data into card display
    // fields: uppercased name, ordinal year level, address lines, guardian label.
    // Factored out of _buildCardPayload() so applicationList() can reuse it.
    // ─────────────────────────────────────────────────────────────────────────
    private function _buildCardFields(array $student, array $reg, array $program, array $section): array
    {
        $lastName   = strtoupper(trim((string)($student['lastName']      ?? '')));
        $firstName  = strtoupper(trim((string)($student['firstName']     ?? '')));
        $middleName = strtoupper(trim((string)($student['middleName']    ?? '')));
        $nameExt    = strtoupper(trim((string)($student['nameExtension'] ?? '')));
        $hasAlnumMiddle = (bool)preg_match('/[A-Z0-9]/i', $middleName);
        $isMiddleNA     = (bool)preg_match('/^(NA|N\/A)$/i', trim($middleName));
        $mi = ($hasAlnumMiddle && !$isMiddleNA) ? (substr($middleName, 0, 1) . '.') : '';

        $cardFullName = trim(implode(' ', array_filter([$firstName, $mi, $lastName, $nameExt])));
        $searchName   = $lastName . ', ' . $firstName . ($mi !== '' ? ' ' . $mi : '');

        $programCode = strtoupper(trim((string)($program['programCode']        ?? '')));
        $programDesc = strtoupper(trim((string)($program['programDescription'] ?? '')));
        $cardProgram = $programDesc !== '' ? $programDesc . ' (' . $programCode . ')' : $programCode;

        $rawYear = trim((string)($reg['yearLevel'] ?? ''));
        $cardYearLevel = $rawYear !== '' ? $this->_ordinal($rawYear) . ' YEAR' : '';
        $cardSectionName = strtoupper(trim((string)($section['sectionName'] ?? '')));

        $cardBirthDate = $this->_formatBirthDate((string)($student['birthDate'] ?? ''));

        $street   = strtoupper(trim((string)($student['address']           ?? '')));
        $barangay = strtoupper(trim((string)($student['barangay']          ?? '')));
        $city     = strtoupper(trim((string)($student['city_municipality'] ?? '')));
        $province = strtoupper(trim((string)($student['province']          ?? '')));
        $district = strtoupper(trim((string)($student['district']         ?? '')));
        $zipcode  = trim((string)($student['zipcode'] ?? ''));

        $hasAlnumStreet = (bool)preg_match('/[A-Z0-9]/i', $street);
        $isStreetNA     = (bool)preg_match('/^(NA|N\/A)$/i', trim($street));
        $cardAddressLine1 = ($hasAlnumStreet && !$isStreetNA) ? $street : '';

        $districtNum = preg_replace('/[^0-9]/', '', $district);
        $cityDistrict = $city . ($districtNum !== '' ? ' (DISTRICT ' . $districtNum . ')' : '');
        $cardAddressLine2 = implode(', ', array_filter([$barangay, $cityDistrict], fn($p) => $p !== ''));
        $cardAddressLine3 = $province . ($zipcode !== '' ? ', ' . $zipcode : '');

        $guardianName    = strtoupper(trim((string)($student['guardianName']              ?? '')));
        $guardianRel     = strtoupper(trim((string)($student['guardianRelationToStudent'] ?? '')));
        $guardianContact = trim((string)($student['guardianContactNumber'] ?? ''));
        $cardGuardianLabel = $guardianName !== ''
            ? $guardianName . ($guardianRel !== '' ? ' (' . $guardianRel . ')' : '')
            : '';

        return [
            'cardStudentNumber'  => trim((string)($student['studentNumber'] ?? '')),
            'cardFullName'       => $cardFullName,
            'searchName'         => $searchName,
            'cardProgram'        => $cardProgram,
            'programCode'        => $programCode,
            'cardYearLevel'      => $cardYearLevel,
            'cardSectionName'    => $cardSectionName,
            'cardBirthDate'      => $cardBirthDate,
            'cardAddressLine1'   => $cardAddressLine1,
            'cardAddressLine2'   => $cardAddressLine2,
            'cardAddressLine3'   => $cardAddressLine3,
            'cardGuardianLabel'  => $cardGuardianLabel,
            'cardGuardianContactNo' => $guardianContact,
            'lastName'      => $lastName,
            'firstName'     => $firstName,
            'middleName'    => $middleName,
            'mi'            => $mi,
            'nameExtension' => $nameExt,
            'barangay'           => $barangay,
            'city'               => $city,
            'province'           => $province,
            'district'           => $district,
            'zipcode'            => $zipcode,
            'guardianName'       => $guardianName,
            'guardianRel'        => $guardianRel,
        ];
    }

    // Converts a numeric year string to ordinal (e.g. "1" → "1ST").
    private function _ordinal(string $n): string
    {
        $num = (int)$n;
        if ($num === 0 && $n !== '0') return $n;
        $suffixes = ['TH','ST','ND','RD','TH','TH','TH','TH','TH','TH'];
        $suffix = ($num % 100 >= 11 && $num % 100 <= 13) ? 'TH' : ($suffixes[$num % 10] ?? 'TH');
        return $num . $suffix;
    }

    // Formats a birth date string as "MONTH DAY, YEAR" in Philippine Time, all-caps.
    // Returns the raw string if parsing fails.
    private function _formatBirthDate(string $raw): string
    {
        if ($raw === '') return '';
        try {
            $d = new \DateTime($raw, new \DateTimeZone('Asia/Manila'));
            return strtoupper($d->format('F j, Y'));
        } catch (\Exception $e) {
            return $raw;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/id/enrolled
    //   ?academicYear=2026-2027          (required)
    //   &semester=1ST%20SEMESTER         (required)
    //
    // Returns all enrolled students for the given term with their program,
    // section, and ID application data. Sorted by lastName, firstName.
    // ─────────────────────────────────────────────────────────────────────────
    public function enrolled()
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
            SELECT " . self::JOIN_COLUMNS . "
            FROM tblRegistrations r
            JOIN tblStudents s    ON s.studentNumber = r.studentNumber
            JOIN tblPrograms p    ON p.programID     = r.programID
            JOIN tblSections sec  ON sec.sectionID   = r.sectionID
            LEFT JOIN tblIDApplications a
                ON a.registrationNumber = r.RegistrationNumber
            WHERE r.academicYear = :academicYear
              AND r.semester     = :semester
            ORDER BY s.lastName ASC, s.firstName ASC
        ");
        $stmt->execute([
            ':academicYear' => $ay,
            ':semester'     => $sem,
        ]);
        $rawRows = $stmt->fetchAll();

        $rows = array_map(function ($row) {
            // enrolled() uses key "application" — matches data.rows[i].application
            $nested = $this->_nestRow_($row);
            $nested['application'] = $nested['idApp'];
            unset($nested['idApp']);
            return $nested;
        }, $rawRows);

        echo json_encode(['ok' => true, 'rows' => $rows]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/id/student-index
    //   ?academicYear=2026-2027     (required)
    //   &semester=1ST%20SEMESTER    (required)
    //
    // Returns a compact student name/label index for the active term,
    // for use in typeahead lookups.
    // ─────────────────────────────────────────────────────────────────────────
    public function studentIndex()
    {
        $ay  = trim($_GET['academicYear'] ?? '');
        $sem = trim($_GET['semester']     ?? '');

        if ($ay === '' || $sem === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'academicYear and semester are required.']);
            return;
        }

        $db   = Database::getConnection();
        $stmt = $db->prepare("
            SELECT
                s.studentNumber,
                s.lastName,
                s.firstName,
                s.middleName
            FROM tblRegistrations r
            JOIN tblStudents s ON s.studentNumber = r.studentNumber
            WHERE r.academicYear = :academicYear
              AND r.semester     = :semester
            ORDER BY s.lastName ASC, s.firstName ASC
        ");
        $stmt->execute([
            ':academicYear' => $ay,
            ':semester'     => $sem,
        ]);
        $rows = $stmt->fetchAll();

        // Build name and label server-side:
        //   name  = "DELA CRUZ, JUAN A."   (LAST, FIRST [MI.])
        //   label = "20250001 — DELA CRUZ, JUAN A."
        // Middle initial: first char of middleName + "." when middleName is non-empty.
        $index = array_map(function ($row) {
            $last  = strtoupper(trim($row['lastName']  ?? ''));
            $first = strtoupper(trim($row['firstName'] ?? ''));
            $mid   = trim($row['middleName'] ?? '');
            $mi    = $mid !== '' ? strtoupper(substr($mid, 0, 1)) . '.' : '';

            $name  = $last . ', ' . $first . ($mi !== '' ? ' ' . $mi : '');
            $label = trim($row['studentNumber']) . ' — ' . $name;

            return [
                'studentNumber' => trim($row['studentNumber']),
                'name'          => $name,
                'label'         => $label,
            ];
        }, $rows);

        echo json_encode(['ok' => true, 'index' => $index]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/id/applications/export
    //
    // Returns every ID application joined with student, registration, program,
    // and section data. Unfiltered by term. Orphaned rows are skipped.
    // ─────────────────────────────────────────────────────────────────────────
    public function applicationList()
    {
        $db = Database::getConnection();

        $stmt = $db->query("
            SELECT " . self::JOIN_COLUMNS . "
            FROM tblIDApplications a
            JOIN tblRegistrations r ON r.RegistrationNumber = a.registrationNumber
            JOIN tblStudents s      ON s.studentNumber      = r.studentNumber
            JOIN tblPrograms p      ON p.programID          = r.programID
            JOIN tblSections sec    ON sec.sectionID        = r.sectionID
            ORDER BY a.idApplicationID DESC
        ");
        $rawRows = $stmt->fetchAll();

        $applications = array_map(function ($row) {
            $nested = $this->_nestRow_($row);
            return $this->_buildApplicationListRecord(
                $nested['student'], $nested['reg'], $nested['program'], $nested['section'], $nested['idApp']
            );
        }, $rawRows);

        echo json_encode(['ok' => true, 'applications' => $applications]);
    }

    // Builds the flattened application record used by exportSelectedCsv()
    // in id.html. Contains card display fields plus status and print timestamps.
    private function _buildApplicationListRecord(array $student, array $reg, array $program, array $section, ?array $idApp): array
    {
        $card = $this->_buildCardFields($student, $reg, $program, $section);
        $regNo = trim((string)($reg['RegistrationNumber'] ?? ''));

        return [
            'idApplicationID'           => $idApp ? trim((string)($idApp['idApplicationID'] ?? '')) : '',
            'studentNumber'             => $card['cardStudentNumber'],
            'registrationNumber'        => $regNo,
            'cardStudentNumber'         => $card['cardStudentNumber'],
            'cardStudentName'           => $card['searchName'],
            'cardFullName'              => $card['cardFullName'],
            'cardAcademicYear'          => $idApp ? trim((string)($idApp['academicYear'] ?? '')) : '',
            'cardSemester'              => $idApp ? trim((string)($idApp['semester'] ?? '')) : '',
            'cardProgram'               => $card['cardProgram'],
            'programCode'               => $card['programCode'],
            'cardStudentYearLevel'      => $card['cardYearLevel'],
            'cardYearLevel'             => $card['cardYearLevel'],
            'sectionName'               => $card['cardSectionName'],
            'cardBirthDate'             => $card['cardBirthDate'],
            'cardAddressLine1'          => $card['cardAddressLine1'],
            'cardAddressLine2'          => $card['cardAddressLine2'],
            'cardAddressLine3'          => $card['cardAddressLine3'],
            'cardGuardianLabel'         => $card['cardGuardianLabel'],
            'cardGuardianContactNo'     => $card['cardGuardianContactNo'],
            'cardGuardianContactNumber' => $card['cardGuardianContactNo'],
            'status'                    => $idApp ? strtoupper(trim((string)($idApp['status'] ?? 'PENDING'))) : 'PENDING',
            'datePrepared'              => $idApp ? $this->_formatTimestamp((string)($idApp['datePrepared'] ?? '')) : '',
            'frontPrintedAt'            => $idApp ? $this->_formatTimestamp((string)($idApp['frontPrintedAt'] ?? '')) : '',
            'frontPrintedBy'            => $idApp ? (string)($idApp['frontPrintedBy'] ?? '') : '',
            'backPrintedAt'             => $idApp ? $this->_formatTimestamp((string)($idApp['backPrintedAt'] ?? '')) : '',
            'backPrintedBy'             => $idApp ? (string)($idApp['backPrintedBy'] ?? '') : '',
        ];
    }

    // Formats a MySQL/PHP timestamp string as "Day, Mon DD, YYYY H:MM AM/PM"
    // in Philippine Time.
    private function _formatTimestamp(string $raw): string
    {
        if ($raw === '') return '';
        try {
            $d = new \DateTime($raw, new \DateTimeZone('Asia/Manila'));
            return $d->format('D, M j, Y g:i A');
        } catch (\Exception $e) {
            return $raw;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/id/applications
    // Body: { studentNumber, registrationNumber, academicYear, semester, createdBy }
    //
    // Creates an ID application for the given registration. Idempotent:
    // returns the existing idApplicationID if one already exists.
    // ─────────────────────────────────────────────────────────────────────────
    public function createApplication()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentNumber      = trim((string)($input['studentNumber'] ?? ''));
        $registrationNumber = trim((string)($input['registrationNumber'] ?? ''));
        $academicYear       = trim((string)($input['academicYear'] ?? ''));
        $semester           = trim((string)($input['semester'] ?? ''));
        $createdBy          = trim((string)($input['createdBy'] ?? ''));

        if ($studentNumber === '')      { http_response_code(400); echo json_encode(['ok' => false, 'message' => 'studentNumber is required.']); return; }
        if ($registrationNumber === '') { http_response_code(400); echo json_encode(['ok' => false, 'message' => 'registrationNumber is required.']); return; }

        $db = Database::getConnection();

        // Idempotency check — match on registrationNumber, then require
        // academicYear/semester to match only when supplied.
        $sql = "SELECT idApplicationID FROM tblIDApplications WHERE registrationNumber = ?";
        $params = [$registrationNumber];
        if ($academicYear !== '') { $sql .= " AND academicYear = ?"; $params[] = $academicYear; }
        if ($semester !== '')     { $sql .= " AND semester = ?";     $params[] = $semester; }
        $sql .= " LIMIT 1";

        $existsStmt = $db->prepare($sql);
        $existsStmt->execute($params);
        $existing = $existsStmt->fetch();
        if ($existing) {
            echo json_encode(['ok' => true, 'idApplicationID' => (string)$existing['idApplicationID']]);
            return;
        }

        // Cross-check: ensure registrationNumber belongs to the given studentNumber.
        // This validation is not part of the route logic in id.html, but is
        // enforced here since this is a directly callable REST endpoint.
        $regStmt = $db->prepare("SELECT RegistrationNumber, studentNumber FROM tblRegistrations WHERE RegistrationNumber = ?");
        $regStmt->execute([$registrationNumber]);
        $regRow = $regStmt->fetch();
        if (!$regRow) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Registration number was not found: ' . $registrationNumber]);
            return;
        }
        if ((string)$regRow['studentNumber'] !== $studentNumber) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'registrationNumber ' . $registrationNumber . ' does not belong to studentNumber ' . $studentNumber . '.']);
            return;
        }

        $now = date('Y-m-d H:i:s');

        try {
            $db->beginTransaction();
            $seq = \App\Models\SequenceGenerator::reserveIdBlock($db, 'tblIDApplications', 1);
            $newId = \App\Models\SequenceGenerator::formatId('IDA', $seq['firstNo'], 8);

            // Reuses the existing private _createApplication() helper —
            // same INSERT ... ON DUPLICATE KEY UPDATE status=VALUES(status)
            // shape already proven correct by the mirror() dispatch path.
            $this->_createApplication($db, [
                'idApplicationID'    => $newId,
                'studentNumber'      => $studentNumber,
                'registrationNumber' => $registrationNumber,
                'academicYear'       => $academicYear,
                'semester'           => $semester,
                'status'             => 'PENDING',
                'datePrepared'       => $now,
                'createdBy'          => $createdBy,
            ]);

            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Unable to save ID application: ' . $e->getMessage()]);
            return;
        }

        echo json_encode(['ok' => true, 'idApplicationID' => $newId]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/id/applications/status
    // Body: { ids: string[], status: 'PENDING'|'EXPORTED'|'PRINTED' }
    //
    // Updates the status of the given ID application IDs.
    // ─────────────────────────────────────────────────────────────────────────
    public function updateApplicationStatus()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $ids    = (isset($input['ids']) && is_array($input['ids'])) ? $input['ids'] : [];
        $status = strtoupper(trim((string)($input['status'] ?? '')));

        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'No application IDs provided.']);
            return;
        }
        if (!in_array($status, self::VALID_STATUSES, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Invalid status: ' . $status . '. Valid values: ' . implode(', ', self::VALID_STATUSES)]);
            return;
        }

        $db = Database::getConnection();

        try {
            $db->beginTransaction();
            $updated = $this->_updateStatus($db, $ids, $status);
            if ($updated === 0) {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'No matching applications found for the provided IDs.']);
                return;
            }
            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Status update failed: ' . $e->getMessage()]);
            return;
        }

        echo json_encode(['ok' => true, 'updated' => $updated]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/id/applications/print-status
    // Body: { ids: string[], side: 'front'|'back'|'both', printed: bool, printedBy: string }
    //
    // Marks (or un-marks, when printed=false) the given side(s) as printed for
    // one or more applications, then recomputes each row's derived `status`:
    //   - both sides printed          -> status = 'PRINTED'
    //   - a side is un-marked and the
    //     row was 'PRINTED'           -> status reverts to 'EXPORTED'
    //   - otherwise                   -> status is left untouched
    //
    // This never downgrades PENDING and never invents a new status value —
    // it only ever toggles between the existing PENDING/EXPORTED/PRINTED enum,
    // so every existing consumer of `status` (summary bar counts, the status
    // filter dropdown, statusBadgeHtml() in id.html) keeps working unmodified.
    // ─────────────────────────────────────────────────────────────────────────
    public function updatePrintStatus()
    {
        $input     = json_decode(file_get_contents('php://input'), true);
        $ids       = (isset($input['ids']) && is_array($input['ids'])) ? $input['ids'] : [];
        $side      = strtolower(trim((string)($input['side'] ?? '')));
        $printed   = array_key_exists('printed', $input) ? (bool)$input['printed'] : true;
        $printedBy = trim((string)($input['printedBy'] ?? ''));

        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'No application IDs provided.']);
            return;
        }
        if (!in_array($side, ['front', 'back', 'both'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Invalid side: ' . $side . '. Valid values: front, back, both']);
            return;
        }

        $db = Database::getConnection();

        try {
            $db->beginTransaction();
            $result = $this->_updatePrintStatus($db, $ids, $side, $printed, $printedBy);
            if ($result['updated'] === 0) {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['ok' => false, 'message' => 'No matching applications found for the provided IDs.']);
                return;
            }
            $db->commit();
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Print status update failed: ' . $e->getMessage()]);
            return;
        }

        echo json_encode([
            'ok'      => true,
            'updated' => $result['updated'],
            'rows'    => $result['rows'], // [{ idApplicationID, status, frontPrintedAt, backPrintedAt }, ...]
        ]);
    }

    public function mirror()
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['operation'])) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required field: operation"]);
            return;
        }

        $operation = $input['operation'];
        $db        = Database::getConnection();

        try {
            switch ($operation) {

                case 'id_application_create':
                    if (empty($input['application'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing required field: application"]);
                        return;
                    }
                    $result = $this->_createApplication($db, $input['application']);
                    echo json_encode([
                        "status"          => "success",
                        "operation"       => $operation,
                        "idApplicationID" => $result,
                    ]);
                    break;

                case 'id_application_status_update':
                    if (empty($input['ids']) || !is_array($input['ids'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing or invalid field: ids (must be array)"]);
                        return;
                    }
                    if (empty($input['status'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing required field: status"]);
                        return;
                    }
                    $status = strtoupper(trim($input['status']));
                    if (!in_array($status, self::VALID_STATUSES, true)) {
                        http_response_code(400);
                        echo json_encode(["error" => "Invalid status: {$status}. Valid values: " . implode(', ', self::VALID_STATUSES)]);
                        return;
                    }
                    $updated = $this->_updateStatus($db, $input['ids'], $status);
                    echo json_encode([
                        "status"    => "success",
                        "operation" => $operation,
                        "updated"   => $updated,
                    ]);
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(["error" => "Unknown operation: {$operation}"]);
                    return;
            }

        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(["error" => "Mirror failed: " . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Re-maps a flat JOIN row into the nested { student, reg, program,
     * section, idApp } shape expected by _buildCardPayload() and _buildCardFields().
     *
     * Column name casing is critical — id.html's card renderer and
     * populateDetailForm() depend on these exact keys:
     *   city_municipality   underscore, lowercase
     *   zipcode             all lowercase
     *   RegistrationNumber  capital R and N
     *   birthDate           camelCase capital D
     *
     * @param  array $row  Flat PDO fetch row
     * @return array       Nested { student, reg, program, section, idApp }
     */
    private function _nestRow_(array $row): array
    {
        $student = [
            'studentNumber'              => $row['studentNumber'],
            'lastName'                   => $row['lastName'],
            'firstName'                  => $row['firstName'],
            'middleName'                 => $row['middleName'],
            'nameExtension'              => $row['nameExtension'],
            'birthDate'                  => $row['birthDate'],           // camelCase D
            'address'                    => $row['address'],
            'barangay'                   => $row['barangay'],
            'city_municipality'          => $row['city_municipality'],   // underscore, lowercase
            'province'                   => $row['province'],
            'district'                   => $row['district'],
            'zipcode'                    => $row['zipcode'],             // all lowercase
            'guardianName'               => $row['guardianName'],
            'guardianRelationToStudent'  => $row['guardianRelationToStudent'],
            'guardianContactNumber'      => $row['guardianContactNumber'],
        ];

        $reg = [
            'RegistrationNumber' => $row['RegistrationNumber'],          // capital R and N
            'studentNumber'      => $row['reg_studentNumber'],           // de-aliased
            'programID'          => $row['programID'],
            'sectionID'          => $row['sectionID'],
            'yearLevel'          => $row['yearLevel'],
            'academicYear'       => $row['academicYear'],
            'semester'           => $row['semester'],
        ];

        $program = [
            'programID'          => $row['p_programID'],                 // de-aliased
            'programCode'        => $row['programCode'],
            'programDescription' => $row['programDescription'],
        ];

        $section = [
            'sectionID'   => $row['sec_sectionID'],                      // de-aliased
            'sectionName' => $row['sectionName'],
        ];

        // idApp is null when the LEFT JOIN found no application row
        $idApp = $row['idApplicationID'] !== null ? [
            'idApplicationID'    => $row['idApplicationID'],
            'registrationNumber' => $row['app_registrationNumber'],      // de-aliased
            'academicYear'       => $row['app_academicYear'],            // de-aliased
            'semester'           => $row['app_semester'],                // de-aliased
            'status'             => $row['status'],
            'datePrepared'       => $row['datePrepared'],
            'frontPrintedAt'     => $row['frontPrintedAt'],
            'frontPrintedBy'     => $row['frontPrintedBy'],
            'backPrintedAt'      => $row['backPrintedAt'],
            'backPrintedBy'      => $row['backPrintedBy'],
        ] : null;

        return [
            'student' => $student,
            'reg'     => $reg,
            'program' => $program,
            'section' => $section,
            'idApp'   => $idApp,
        ];
    }

    private function _createApplication($db, array $a): string
    {
        $stmt = $db->prepare("
            INSERT INTO tblIDApplications (
                idApplicationID, studentNumber, registrationNumber,
                academicYear, semester, status, datePrepared, createdBy
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status)
        ");

        $stmt->execute([
            $a['idApplicationID']    ?? null,
            $a['studentNumber']      ?? null,
            $a['registrationNumber'] ?? null,
            $a['academicYear']       ?? null,
            $a['semester']           ?? null,
            $a['status']             ?? 'PENDING',
            $a['datePrepared']       ?? null,
            $a['createdBy']          ?? null,
        ]);

        return $a['idApplicationID'] ?? '';
    }

    private function _updatePrintStatus($db, array $ids, string $side, bool $printed, string $printedBy): array
    {
        if (empty($ids)) return ['updated' => 0, 'rows' => []];

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $now = $printed ? date('Y-m-d H:i:s') : null;
        $by  = $printed ? $printedBy : null;

        $setSql = [];
        $params = [];
        if ($side === 'front' || $side === 'both') {
            $setSql[] = 'frontPrintedAt = ?'; $params[] = $now;
            $setSql[] = 'frontPrintedBy = ?'; $params[] = $by;
        }
        if ($side === 'back' || $side === 'both') {
            $setSql[] = 'backPrintedAt = ?';  $params[] = $now;
            $setSql[] = 'backPrintedBy = ?';  $params[] = $by;
        }

        $stmt = $db->prepare("
            UPDATE tblIDApplications
            SET " . implode(', ', $setSql) . "
            WHERE idApplicationID IN ({$placeholders})
        ");
        $stmt->execute(array_merge($params, $ids));
        $updated = $stmt->rowCount();

        // Recompute the derived status in the same request — see method
        // doc comment above for the exact rule.
        $stmt2 = $db->prepare("
            UPDATE tblIDApplications
            SET status = CASE
                WHEN frontPrintedAt IS NOT NULL AND backPrintedAt IS NOT NULL THEN 'PRINTED'
                WHEN status = 'PRINTED' THEN 'EXPORTED'
                ELSE status
            END
            WHERE idApplicationID IN ({$placeholders})
        ");
        $stmt2->execute($ids);

        $stmt3 = $db->prepare("
            SELECT idApplicationID, status, frontPrintedAt, backPrintedAt
            FROM tblIDApplications
            WHERE idApplicationID IN ({$placeholders})
        ");
        $stmt3->execute($ids);
        $rows = $stmt3->fetchAll();

        return ['updated' => $updated, 'rows' => $rows];
    }

    private function _updateStatus($db, array $ids, string $status): int
    {
        if (empty($ids)) return 0;

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            UPDATE tblIDApplications
            SET status = ?
            WHERE idApplicationID IN ({$placeholders})
        ");

        $stmt->execute(array_merge([$status], $ids));

        return $stmt->rowCount();
    }
}
