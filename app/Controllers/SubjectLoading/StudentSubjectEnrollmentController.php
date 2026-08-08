<?php

namespace App\Controllers\SubjectLoading;

use App\Core\Database;
use App\Models\SequenceGenerator;
use App\Services\SubjectLoadingReferenceDataService;

/**
 * App\Controllers\SubjectLoading\StudentSubjectEnrollmentController
 *
 * Owns every way a student ends up attached to a Subject Offering (a
 * tblTeacherClassLoads row): individual enroll, bulk enroll, home-class
 * auto-sync (per-student and per-class), and the ENROLLED / DROPPED /
 * CREDITED status lifecycle. Every endpoint follows a consistent response
 * envelope (`ok` present as a boolean on both branches, extra fields flat
 * at the top level) and every public method wraps its entire body in
 * try/catch, since an uncaught \Throwable would otherwise leak a raw PHP
 * stack trace to the client.
 */
class StudentSubjectEnrollmentController
{
    private const VALID_STATUSES = ['ENROLLED', 'DROPPED', 'CREDITED'];

    // Fixed transition set. DROPPED -> CREDITED directly (and any other
    // combination not listed) is rejected with 422 INVALID_TRANSITION —
    // the officer must reverse to ENROLLED first, so the reason for each
    // state change stays explicit in statusNote.
    private const ALLOWED_TRANSITIONS = [
        'ENROLLED' => ['DROPPED', 'CREDITED'],
        'DROPPED'  => ['ENROLLED'],
        'CREDITED' => ['ENROLLED'],
    ];

	// ─────────────────────────────────────────────────────────────────
	    // GET /api/subject-loading/students/search
	    //   ?q=...            (required, 2+ chars)
	    //   &academicYear=...  (required)
	    //   &semester=...      (required)
	    //
	    // Term-scoped student typeahead — matches students who have a
	    // registration in the given term (via INNER JOIN tblRegistrations),
	    // 2-char minimum, 50-row cap, ordered by lastName, firstName.
	    // ─────────────────────────────────────────────────────────────────
	    public function searchStudents()
	    {
	        try {
	            $q            = trim($_GET['q'] ?? '');
	            $academicYear = trim($_GET['academicYear'] ?? '');
	            $semester     = trim($_GET['semester'] ?? '');
	
	            if ($academicYear === '' || $semester === '') {
	                $this->_fail(400, 'VALIDATION_ERROR', 'academicYear and semester are required.');
	                return;
	            }
	            if (mb_strlen($q) < 2) {
	                echo json_encode(['ok' => true, 'students' => []]);
	                return;
	            }
	
	            $db = Database::getConnection();
	
				$stmt = $db->prepare("
	                SELECT s.studentNumber, s.lastName, s.firstName, s.middleName,
	                       s.middleInitial, s.nameExtension,
	                       r.programID, r.yearLevel, r.sectionID,
	                       p.programCode, sec.sectionName
	                FROM tblStudents s
	                INNER JOIN tblRegistrations r
	                    ON r.studentNumber = s.studentNumber
	                   AND r.academicYear = :academicYear
	                   AND r.semester = :semester
	                LEFT JOIN tblPrograms p   ON p.programID   = r.programID
	                LEFT JOIN tblSections sec ON sec.sectionID = r.sectionID
	                WHERE (
	                    s.lastName  LIKE :q1
	                    OR s.firstName LIKE :q2
	                    OR CONCAT(s.firstName, ' ', s.lastName) LIKE :q3
	                    OR CONCAT(s.lastName, ', ', s.firstName) LIKE :q4
	                    OR s.studentNumber LIKE :q5
	                )
	                ORDER BY s.lastName, s.firstName
	                LIMIT 50
	            ");				
	            
	            $like = '%' . $q . '%';
	            $stmt->execute([
	                ':academicYear' => $academicYear,
	                ':semester'     => $semester,
	                ':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like,
	            ]);
	            $rows = $stmt->fetchAll();
	
				$refSvc = new SubjectLoadingReferenceDataService();
	            $students = [];
	            foreach ($rows as $row) {
	                $programID = (string)($row['programID'] ?? '');
	                $yearLevel = (string)($row['yearLevel'] ?? '');
	                $sectionID = (string)($row['sectionID'] ?? '');

	                // Join gives the display value; fall back to the raw ID
	                // only if the join came back empty — never surface a raw
	                // programID/sectionID as if it were a display label.
	                $programCode = $row['programCode'] ?: ($programID !== '' ? $programID : '(No Program)');
	                $sectionName = $row['sectionName'] ?: ($sectionID !== '' ? $sectionID : '(No Section)');

	                $students[] = [
	                    'studentNumber' => (string)($row['studentNumber'] ?? ''),
	                    'fullName'      => $refSvc->buildStudentFullName($row),
	                    'programID'     => $programID,
	                    'programCode'   => (string)$programCode,
	                    'yearLevel'     => $yearLevel !== '' ? $yearLevel : '(No Year Level)',
	                    'sectionID'     => $sectionID,
	                    'sectionName'   => (string)$sectionName,
	                ];
	            }	            
	
	            echo json_encode(['ok' => true, 'students' => $students]);
	        } catch (\Throwable $e) {
	            $this->_fail(500, 'SERVER_ERROR', 'Unable to search students: ' . $e->getMessage());
	        }
	    }

	    // ─────────────────────────────────────────────────────────────────
        // POST /api/subject-loading/student-loads/enroll
        // Body: { teacherClassLoadID, studentNumber, enrollmentType, createdBy,
        //         enrollmentReasonCode?, enrollmentReasonNote?, confirmDuplicate? }
        //
        // Per-subject / individual enroll — exactly one student into exactly
        // one Subject Offering. Rejects a genuine duplicate (409
        // ALREADY_ENROLLED). If the existing row is DROPPED/CREDITED, this
        // is a structured non-error state (HTTP 200, ok:false) — the
        // officer didn't do anything wrong, they hit a state the UI should
        // react to (reactivate via setStatus()), not alarm on.
        //
        // When enrollmentType === 'CROSS', additionally validates
        // enrollmentReasonCode/enrollmentReasonNote, snapshots the
        // student's current home class, and runs the duplicate-across-
        // offerings guard. All of this is gated strictly behind
        // enrollmentType === 'CROSS' — the REGULAR path (the default) is
        // unaffected.
        // ─────────────────────────────────────────────────────────────────
        public function enroll()
        {
            $db = null;
            try {
                $input = json_decode(file_get_contents('php://input'), true) ?: [];
                $teacherClassLoadID   = trim((string)($input['teacherClassLoadID'] ?? ''));
                $studentNumber        = trim((string)($input['studentNumber'] ?? ''));
                $enrollmentType       = trim((string)($input['enrollmentType'] ?? '')) ?: 'REGULAR';
                $enrollmentReasonCode = trim((string)($input['enrollmentReasonCode'] ?? ''));
                $enrollmentReasonNote = trim((string)($input['enrollmentReasonNote'] ?? ''));
                $confirmDuplicate     = $this->_truthy($input['confirmDuplicate'] ?? false);
                $createdBy            = trim((string)($input['createdBy'] ?? ''));
    
                if ($teacherClassLoadID === '') { $this->_fail(400, 'VALIDATION_ERROR', 'A Subject Offering must be selected.'); return; }
                if ($studentNumber === '')      { $this->_fail(400, 'VALIDATION_ERROR', 'A student must be selected.'); return; }
    
                $db = Database::getConnection();
    
                if (!$this->_findTeacherClassLoad($db, $teacherClassLoadID)) {
                    $this->_fail(404, 'NOT_FOUND', 'Subject Offering was not found: ' . $teacherClassLoadID);
                    return;
                }
    
                $sStmt = $db->prepare("SELECT studentNumber FROM tblStudents WHERE studentNumber = ?");
                $sStmt->execute([$studentNumber]);
                if (!$sStmt->fetch()) {
                    $this->_fail(404, 'NOT_FOUND', 'Student was not found: ' . $studentNumber);
                    return;
                }
    
                // Existing behavior, runs for both REGULAR and CROSS.
                $existing = $this->_findEnrollment($db, $studentNumber, $teacherClassLoadID);
    
                if ($existing) {
                    $status = (string)$existing['enrollmentStatus'];
                    if ($status === 'ENROLLED') {
                        $this->_fail(409, 'ALREADY_ENROLLED', 'Student is already enrolled in this Subject Offering.');
                        return;
                    }
                    // Structured non-error state — HTTP 200, ok:false.
                    http_response_code(200);
                    echo json_encode([
                        'ok'    => false,
                        'error' => [
                            'code'                   => 'PREVIOUSLY_' . $status,
                            'message'                => 'Previously ' . ucfirst(strtolower($status)) . ' — reactivate instead?',
                            'studentSubjectEnrollID' => (string)$existing['studentSubjectEnrollID'],
                        ],
                    ]);
                    return;
                }
    
                // CROSS-only steps below are skipped entirely for REGULAR
                // (the default).
                $homeProgramID = null;
                $homeYearLevel = null;
                $homeSectionID = null;
    
                if ($enrollmentType === 'CROSS') {
                    if ($enrollmentReasonCode === '') {
                        $this->_fail(422, 'VALIDATION_ERROR', 'enrollmentReasonCode is required for a CROSS enrollment.');
                        return;
                    }
    
                    // Existence check only, no branching on which code.
                    $reasonStmt = $db->prepare("
                        SELECT code FROM ref_lookup_values
                        WHERE category = 'ENROLLMENT_REASON' AND code = ? AND isActive = 1
                    ");
                    $reasonStmt->execute([$enrollmentReasonCode]);
                    if (!$reasonStmt->fetch()) {
                        $this->_fail(422, 'VALIDATION_ERROR', 'enrollmentReasonCode is not a recognized, active enrollment reason.');
                        return;
                    }
    
                    // Enforced server-side, matching what the client already
                    // requires.
                    if ($enrollmentReasonNote === '') {
                        $this->_fail(422, 'VALIDATION_ERROR', 'enrollmentReasonNote is required for a CROSS enrollment.');
                        return;
                    }
    
                    // Home-class snapshot, captured once at the moment of
                    // enrollment, never updated afterward.
                    $regStmt = $db->prepare("
                        SELECT programID, yearLevel, sectionID FROM tblRegistrations
                        WHERE studentNumber = ? ORDER BY dateCreated DESC LIMIT 1
                    ");
                    $regStmt->execute([$studentNumber]);
                    $regRow = $regStmt->fetch();
                    $homeProgramID = $regRow ? $this->_nullIfBlank((string)($regRow['programID'] ?? '')) : null;
                    $homeYearLevel = $regRow ? $this->_nullIfBlank((string)($regRow['yearLevel'] ?? '')) : null;
                    $homeSectionID = $regRow ? $this->_nullIfBlank((string)($regRow['sectionID'] ?? '')) : null;
    
                    // Duplicate-across-offerings guard, CROSS only.
                    $tslStmt = $db->prepare("
                        SELECT tsl.subjectID, tsl.academicYear, tsl.semester
                        FROM tblTeacherClassLoads tcl
                        INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
                        WHERE tcl.teacherClassLoadID = ?
                    ");
                    $tslStmt->execute([$teacherClassLoadID]);
                    $tslRow = $tslStmt->fetch();
    
                    if ($tslRow) {
                        $dupStmt = $db->prepare("
                            SELECT sse.teacherClassLoadID,
                                   sub.subjectCode, sub.subjectTitle,
                                   t.lastName AS teacherLastName, t.firstName AS teacherFirstName,
                                   tcl2.programID, tcl2.yearLevel, tcl2.sectionID
                            FROM tblStudentSubjectEnrollments sse
                            JOIN tblTeacherClassLoads tcl2 ON tcl2.teacherClassLoadID = sse.teacherClassLoadID
                            JOIN tblTeacherSubjectLoads tsl2 ON tsl2.teacherSubjectLoadID = tcl2.teacherSubjectLoadID
                            LEFT JOIN tblSubjects sub ON sub.subjectID = tsl2.subjectID
                            LEFT JOIN tblTeachers t ON t.teacherID = tsl2.teacherID
                            WHERE sse.studentNumber = ?
                              AND tsl2.subjectID = ?
                              AND sse.enrollmentStatus = 'ENROLLED'
                              AND sse.isActive = 1
                              AND tsl2.academicYear = ?
                              AND tsl2.semester = ?
                            LIMIT 1
                        ");
                        $dupStmt->execute([
                            $studentNumber,
                            $tslRow['subjectID'],
                            $tslRow['academicYear'],
                            $tslRow['semester'],
                        ]);
                        $dupRow = $dupStmt->fetch();
    
                        if ($dupRow && !$confirmDuplicate) {
                            http_response_code(200);
                            echo json_encode([
                                'ok'    => false,
                                'error' => [
                                    'code'    => 'ALREADY_ENROLLED_IN_SUBJECT',
                                    'message' => 'Student is already enrolled in this subject via a different offering.',
                                    'existingOffering' => [
                                        'teacherClassLoadID' => (string)$dupRow['teacherClassLoadID'],
                                        'subjectCode'         => (string)($dupRow['subjectCode'] ?? ''),
                                        'teacherName'         => trim(($dupRow['teacherLastName'] ?? '') . ', ' . ($dupRow['teacherFirstName'] ?? ''), ', '),
                                        'programID'           => (string)($dupRow['programID'] ?? ''),
                                        'yearLevel'           => (string)($dupRow['yearLevel'] ?? ''),
                                        'sectionID'           => (string)($dupRow['sectionID'] ?? ''),
                                    ],
                                ],
                            ]);
                            return;
                        }
                    }
                }
    
                $regNumber = $this->_currentRegistrationNumber($db, $studentNumber);
                $now = date('Y-m-d H:i:s');
    
                $db->beginTransaction();
                $seq = SequenceGenerator::reserveIdBlock($db, 'tblStudentSubjectEnrollments', 1);
                $newID = SequenceGenerator::formatId('SEN', $seq['firstNo'], 9);
    
                $ins = $db->prepare("
                    INSERT INTO tblStudentSubjectEnrollments (
                        studentSubjectEnrollID, studentNumber, teacherClassLoadID, registrationNumber,
                        enrollmentSource, enrollmentType, enrollmentStatus,
                        enrollmentReasonCode, enrollmentReasonNote,
                        homeProgramID, homeYearLevel, homeSectionID,
                        statusNote, statusBy, statusDate, isActive,
                        createdBy, dateCreated, modifiedBy, lastModified
                    ) VALUES (
                        ?, ?, ?, ?, 'MANUAL', ?, 'ENROLLED',
                        ?, ?,
                        ?, ?, ?,
                        NULL, NULL, NULL, 1,
                        ?, ?, NULL, NULL
                    )
                ");
                $ins->execute([
                    $newID, $studentNumber, $teacherClassLoadID, $this->_nullIfBlank($regNumber), $enrollmentType,
                    $enrollmentType === 'CROSS' ? $enrollmentReasonCode : null,
                    $enrollmentType === 'CROSS' ? $this->_nullIfBlank($enrollmentReasonNote) : null,
                    $homeProgramID, $homeYearLevel, $homeSectionID,
                    $createdBy, $now,
                ]);
                $db->commit();
    
                http_response_code(201);
                echo json_encode([
                    'ok'                     => true,
                    'studentSubjectEnrollID' => $newID,
                    'message'                => 'Student enrolled.',
                ]);
            } catch (\Throwable $e) {
                if ($db && $db->inTransaction()) $db->rollBack();
                $this->_fail(500, 'SERVER_ERROR', 'Unable to enroll student: ' . $e->getMessage());
            }
        }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/subject-loading/student-loads/bulk-enroll
    // Body: { teacherClassLoadID, studentNumbers: [...], enrollmentType, createdBy }
    //
    // Enrolls many students into the SAME Subject Offering in one atomic
    // call. Unlike enroll(), already-enrolled students in the batch are
    // skipped, not rejected — bulk operations must tolerate partial
    // overlap. Reports exactly what happened so the officer sees the
    // outcome without the whole batch failing on one bad/duplicate row.
    // ─────────────────────────────────────────────────────────────────
    public function bulkEnroll()
    {
        $db = null;
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $teacherClassLoadID = trim((string)($input['teacherClassLoadID'] ?? ''));
            $studentNumbers     = is_array($input['studentNumbers'] ?? null) ? $input['studentNumbers'] : [];
            $enrollmentType     = trim((string)($input['enrollmentType'] ?? '')) ?: 'REGULAR';
            $createdBy          = trim((string)($input['createdBy'] ?? ''));

            if ($teacherClassLoadID === '') { $this->_fail(400, 'VALIDATION_ERROR', 'A Subject Offering must be selected.'); return; }
            if (empty($studentNumbers))     { $this->_fail(400, 'VALIDATION_ERROR', 'At least one student is required.'); return; }

            $db = Database::getConnection();

            if (!$this->_findTeacherClassLoad($db, $teacherClassLoadID)) {
                $this->_fail(404, 'NOT_FOUND', 'Subject Offering was not found: ' . $teacherClassLoadID);
                return;
            }

            // De-dupe the requested list.
            $studentNumbers = array_values(array_unique(array_map(fn($s) => trim((string)$s), $studentNumbers)));
            $studentNumbers = array_values(array_filter($studentNumbers, fn($s) => $s !== ''));

            $invalidStudents        = [];
            $skippedAlreadyEnrolled = [];
            $toEnroll               = [];

            foreach ($studentNumbers as $sn) {
                $sStmt = $db->prepare("SELECT studentNumber FROM tblStudents WHERE studentNumber = ?");
                $sStmt->execute([$sn]);
                if (!$sStmt->fetch()) { $invalidStudents[] = $sn; continue; }

                if ($this->_findEnrollment($db, $sn, $teacherClassLoadID)) {
                    $skippedAlreadyEnrolled[] = $sn;
                    continue;
                }
                $toEnroll[] = $sn;
            }

            $enrolledCount = 0;
            if (!empty($toEnroll)) {
                $db->beginTransaction();
                $seq = SequenceGenerator::reserveIdBlock($db, 'tblStudentSubjectEnrollments', count($toEnroll));
                $now = date('Y-m-d H:i:s');

                $ins = $db->prepare("
                    INSERT INTO tblStudentSubjectEnrollments (
                        studentSubjectEnrollID, studentNumber, teacherClassLoadID, registrationNumber,
                        enrollmentSource, enrollmentType, enrollmentStatus,
                        statusNote, statusBy, statusDate, isActive,
                        createdBy, dateCreated, modifiedBy, lastModified
                    ) VALUES (
                        ?, ?, ?, ?, 'MANUAL', ?, 'ENROLLED',
                        NULL, NULL, NULL, 1,
                        ?, ?, NULL, NULL
                    )
                    ON DUPLICATE KEY UPDATE
                        modifiedBy   = VALUES(createdBy),
                        lastModified = VALUES(dateCreated)
                ");

                foreach ($toEnroll as $i => $sn) {
                    $newID     = SequenceGenerator::formatId('SEN', $seq['firstNo'] + $i, 9);
                    $regNumber = $this->_currentRegistrationNumber($db, $sn);
                    $ins->execute([$newID, $sn, $teacherClassLoadID, $this->_nullIfBlank($regNumber), $enrollmentType, $createdBy, $now]);
                    $enrolledCount++;
                }
                $db->commit();
            }

            echo json_encode([
                'ok'                     => true,
                'enrolledCount'          => $enrolledCount,
                'skippedAlreadyEnrolled' => $skippedAlreadyEnrolled,
                'invalidStudents'        => $invalidStudents,
                'warnings'               => [],
                'message'                => $enrolledCount . ' student(s) enrolled.',
            ]);
        } catch (\Throwable $e) {
            if ($db && $db->inTransaction()) $db->rollBack();
            $this->_fail(500, 'SERVER_ERROR', 'Unable to bulk-enroll students: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/subject-loading/student-loads/sync-home-class
    // Body EITHER: { studentNumber, academicYear, semester, createdBy }
    //          OR : { classSelector: {programID, yearLevel, sectionID},
    //                 academicYear, semester, createdBy }
    //
    // Derives each target student's home-class triple from
    // tblRegistrations, then auto-enrolls them (enrollmentSource=AUTO,
    // enrollmentType=REGULAR, enrollmentStatus=ENROLLED) into every
    // active TeacherClassLoad matching that triple for the term. Never
    // overwrites an existing DROPPED/CREDITED row back to ENROLLED —
    // reversal must go through setStatus(). Does not remove rows that no
    // longer match the student's current class (e.g. a section change) —
    // reports them as staleAutoEnrollments instead of silently dropping
    // the student from a roster.
    // ─────────────────────────────────────────────────────────────────
    public function syncHomeClass()
    {
        $db = null;
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $academicYear  = trim((string)($input['academicYear'] ?? ''));
            $semester      = trim((string)($input['semester'] ?? ''));
            $createdBy     = trim((string)($input['createdBy'] ?? ''));
            $studentNumber = trim((string)($input['studentNumber'] ?? ''));
            $classSelector = is_array($input['classSelector'] ?? null) ? $input['classSelector'] : null;

            if ($academicYear === '' || $semester === '') {
                $this->_fail(400, 'VALIDATION_ERROR', 'academicYear and semester are required.');
                return;
            }
            if ($studentNumber === '' && !$classSelector) {
                $this->_fail(400, 'VALIDATION_ERROR', 'Either studentNumber or classSelector is required.');
                return;
            }

            $db = Database::getConnection();

            // ── Resolve target students + each one's home-class triple ──
            $targets = []; // studentNumber => ['programID'=>, 'yearLevel'=>, 'sectionID'=>]

            if ($studentNumber !== '') {
                $regStmt = $db->prepare("
                    SELECT programID, yearLevel, sectionID FROM tblRegistrations
                    WHERE studentNumber = ? AND academicYear = ? AND semester = ?
                    LIMIT 1
                ");
                $regStmt->execute([$studentNumber, $academicYear, $semester]);
                $reg = $regStmt->fetch();
                if (!$reg) {
                    $this->_fail(404, 'NOT_FOUND', 'Student has no registration for ' . $academicYear . ' / ' . $semester . '.');
                    return;
                }
                $targets[$studentNumber] = [
                    'programID' => (string)($reg['programID'] ?? ''),
                    'yearLevel' => (string)($reg['yearLevel'] ?? ''),
                    'sectionID' => (string)($reg['sectionID'] ?? ''),
                ];
            } else {
                $programID = (string)($classSelector['programID'] ?? '');
                $yearLevel = (string)($classSelector['yearLevel'] ?? '');
                $sectionID = (string)($classSelector['sectionID'] ?? '');

                $regStmt = $db->prepare("
                    SELECT studentNumber FROM tblRegistrations
                    WHERE academicYear = ? AND semester = ?
                      AND COALESCE(programID, '') = ?
                      AND COALESCE(yearLevel, '') = ?
                      AND COALESCE(sectionID, '') = ?
                ");
                $regStmt->execute([$academicYear, $semester, $programID, $yearLevel, $sectionID]);
                foreach ($regStmt->fetchAll() as $row) {
                    $sn = (string)($row['studentNumber'] ?? '');
                    if ($sn === '') continue;
                    $targets[$sn] = ['programID' => $programID, 'yearLevel' => $yearLevel, 'sectionID' => $sectionID];
                }
                if (empty($targets)) {
                    $this->_fail(404, 'NOT_FOUND', 'No students are currently registered in that class for this term.');
                    return;
                }
            }

            $enrolledCount        = 0;
            $alreadyCount         = 0;
            $staleAutoEnrollments = [];
            $now = date('Y-m-d H:i:s');

            $db->beginTransaction();

            foreach ($targets as $sn => $triple) {
                // Active TeacherClassLoads matching this student's home-class
                // triple, for this term (every Subject Offering available to
                // that class).
                $tclStmt = $db->prepare("
                    SELECT tcl.teacherClassLoadID
                    FROM tblTeacherClassLoads tcl
                    INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
                    WHERE tsl.academicYear = ? AND tsl.semester = ?
                      AND tcl.isActive = 1 AND tsl.isActive = 1
                      AND COALESCE(tcl.programID, '') = ?
                      AND COALESCE(tcl.yearLevel, '') = ?
                      AND COALESCE(tcl.sectionID, '') = ?
                ");
                $tclStmt->execute([$academicYear, $semester, $triple['programID'], $triple['yearLevel'], $triple['sectionID']]);
                $matchingTcls = array_column($tclStmt->fetchAll(), 'teacherClassLoadID');

                foreach ($matchingTcls as $tclID) {
                    $existing = $this->_findEnrollment($db, $sn, $tclID);
                    if ($existing) {
                        // Never overwrite an existing row here — including
                        // DROPPED/CREDITED ones. Reversal must go through
                        // setStatus().
                        $alreadyCount++;
                        continue;
                    }
                    $seq   = SequenceGenerator::reserveIdBlock($db, 'tblStudentSubjectEnrollments', 1);
                    $newID = SequenceGenerator::formatId('SEN', $seq['firstNo'], 9);
                    $regNumber = $this->_currentRegistrationNumber($db, $sn);

                    $ins = $db->prepare("
                        INSERT INTO tblStudentSubjectEnrollments (
                            studentSubjectEnrollID, studentNumber, teacherClassLoadID, registrationNumber,
                            enrollmentSource, enrollmentType, enrollmentStatus,
                            statusNote, statusBy, statusDate, isActive,
                            createdBy, dateCreated, modifiedBy, lastModified
                        ) VALUES (
                            ?, ?, ?, ?, 'AUTO', 'REGULAR', 'ENROLLED',
                            NULL, NULL, NULL, 1,
                            ?, ?, NULL, NULL
                        )
                    ");
                    $ins->execute([$newID, $sn, $tclID, $this->_nullIfBlank($regNumber), $createdBy, $now]);
                    $enrolledCount++;
                }

                // Stale check: existing AUTO/ENROLLED rows for this student,
                // in this term, whose TeacherClassLoad's class triple no
                // longer matches this student's CURRENT home-class triple
                // (e.g. the student changed section). Reported, not removed.
                $staleStmt = $db->prepare("
                    SELECT sse.studentSubjectEnrollID, sse.teacherClassLoadID,
                           tcl.programID, tcl.yearLevel, tcl.sectionID
                    FROM tblStudentSubjectEnrollments sse
                    INNER JOIN tblTeacherClassLoads tcl ON tcl.teacherClassLoadID = sse.teacherClassLoadID
                    INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
                    WHERE sse.studentNumber = ?
                      AND sse.enrollmentSource = 'AUTO'
                      AND sse.enrollmentStatus = 'ENROLLED'
                      AND tsl.academicYear = ? AND tsl.semester = ?
                ");
                $staleStmt->execute([$sn, $academicYear, $semester]);
                foreach ($staleStmt->fetchAll() as $row) {
                    $rowTriple = [
                        'programID' => (string)($row['programID'] ?? ''),
                        'yearLevel' => (string)($row['yearLevel'] ?? ''),
                        'sectionID' => (string)($row['sectionID'] ?? ''),
                    ];
                    if ($rowTriple !== $triple) {
                        $staleAutoEnrollments[] = [
                            'studentNumber'          => $sn,
                            'studentSubjectEnrollID' => (string)$row['studentSubjectEnrollID'],
                            'teacherClassLoadID'     => (string)$row['teacherClassLoadID'],
                        ];
                    }
                }
            }

            $db->commit();

            echo json_encode([
                'ok'                   => true,
                'processedStudents'    => count($targets),
                'enrolledCount'        => $enrolledCount,
                'alreadyEnrolledCount' => $alreadyCount,
                'staleAutoEnrollments' => $staleAutoEnrollments,
                'warnings'             => [],
                'message'              => 'Home class sync complete.',
            ]);
        } catch (\Throwable $e) {
            if ($db && $db->inTransaction()) $db->rollBack();
            $this->_fail(500, 'SERVER_ERROR', 'Unable to sync home class: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/subject-loading/student-loads/status
    // Body: { studentSubjectEnrollID, status, note, statusBy }
    //    OR: { studentNumber, teacherClassLoadID, status, note, statusBy }
    //
    // Generic ENROLLED/DROPPED/CREDITED status transition, validated
    // server-side against the fixed transition set above.
    // ─────────────────────────────────────────────────────────────────
    public function setStatus()
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $studentSubjectEnrollID = trim((string)($input['studentSubjectEnrollID'] ?? ''));
            $studentNumber          = trim((string)($input['studentNumber'] ?? ''));
            $teacherClassLoadID     = trim((string)($input['teacherClassLoadID'] ?? ''));
            $status                 = strtoupper(trim((string)($input['status'] ?? '')));
            $note                   = trim((string)($input['note'] ?? ''));
            $statusBy               = trim((string)($input['statusBy'] ?? ($input['modifiedBy'] ?? '')));

            if (!in_array($status, self::VALID_STATUSES, true)) {
                $this->_fail(400, 'VALIDATION_ERROR', 'status must be one of: ' . implode(', ', self::VALID_STATUSES) . '.');
                return;
            }

            $db = Database::getConnection();

            if ($studentSubjectEnrollID !== '') {
                $stmt = $db->prepare("SELECT * FROM tblStudentSubjectEnrollments WHERE studentSubjectEnrollID = ?");
                $stmt->execute([$studentSubjectEnrollID]);
            } elseif ($studentNumber !== '' && $teacherClassLoadID !== '') {
                $stmt = $db->prepare("SELECT * FROM tblStudentSubjectEnrollments WHERE studentNumber = ? AND teacherClassLoadID = ?");
                $stmt->execute([$studentNumber, $teacherClassLoadID]);
            } else {
                $this->_fail(400, 'VALIDATION_ERROR', 'studentSubjectEnrollID, or studentNumber + teacherClassLoadID, is required.');
                return;
            }

            $row = $stmt->fetch();
            if (!$row) {
                $this->_fail(404, 'NOT_FOUND', 'Student subject enrollment was not found.');
                return;
            }

            $currentStatus = (string)$row['enrollmentStatus'];
            $id = (string)$row['studentSubjectEnrollID'];

            if ($currentStatus !== $status) {
                $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
                if (!in_array($status, $allowed, true)) {
                    $this->_fail(422, 'INVALID_TRANSITION', 'Cannot change status from ' . $currentStatus . ' to ' . $status . ' directly.');
                    return;
                }
            }

            $isActive = $status === 'ENROLLED' ? 1 : 0;
            $now = date('Y-m-d H:i:s');

            $upd = $db->prepare("
                UPDATE tblStudentSubjectEnrollments SET
                    enrollmentStatus = ?, statusNote = ?, statusBy = ?, statusDate = ?,
                    isActive = ?, modifiedBy = ?, lastModified = ?
                WHERE studentSubjectEnrollID = ?
            ");
            $upd->execute([$status, $this->_nullIfBlank($note), $this->_nullIfBlank($statusBy), $now, $isActive, $statusBy, $now, $id]);

            echo json_encode([
                'ok'                     => true,
                'studentSubjectEnrollID' => $id,
                'status'                 => $status,
                'message'                => 'Status updated to ' . $status . '.',
            ]);
        } catch (\Throwable $e) {
            $this->_fail(500, 'SERVER_ERROR', 'Unable to update status: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/subject-loading/subject-roster
    //   ?teacherSubjectLoadID=...  (one of these two is required)
    //   &teacherClassLoadID=...   (one of these two is required — scopes to
    //                              one class/offering; teacherSubjectLoadID
    //                              is resolved server-side via the join
    //                              when omitted)
    //   &status=ENROLLED|DROPPED|CREDITED|ALL|comma-list  (default ENROLLED)
    //
    // Also underlies the "whole class roster, not yet enrolled" bulk-enroll
    // source via LoadingController::classRoster() (a separate endpoint,
    // queried against tblRegistrations rather than this table).
    // ─────────────────────────────────────────────────────────────────
    public function roster()
    {
        try {
            $teacherSubjectLoadID = trim($_GET['teacherSubjectLoadID'] ?? '');
            $teacherClassLoadID   = trim($_GET['teacherClassLoadID'] ?? '');
            $statusParam          = trim($_GET['status'] ?? 'ENROLLED');

            // At least one identifier is required — teacherClassLoadID
            // alone is sufficient (teacherSubjectLoadID is resolved below
            // via the join rather than requiring the caller to supply it).
            if ($teacherSubjectLoadID === '' && $teacherClassLoadID === '') {
                $this->_fail(400, 'VALIDATION_ERROR', 'teacherSubjectLoadID or teacherClassLoadID is required.');
                return;
            }

            $statuses = $this->_parseStatusFilter($statusParam);

            $db = Database::getConnection();

            $sql = "
                SELECT sse.*, tcl.programID, tcl.yearLevel, tcl.sectionID,
                       tcl.teacherSubjectLoadID AS resolvedTeacherSubjectLoadID,
                       s.studentNumber AS sNum, s.lastName, s.firstName, s.middleName,
                       s.middleInitial, s.nameExtension, s.gender,
                       la.studentNumber AS lmsStudentNumber, la.status AS lmsStatus,
                       la.moodleEmail, la.moodleSyncDate,
                       rlv.label AS reasonLabel,
                       homeSec.sectionName AS homeSectionName
                FROM tblStudentSubjectEnrollments sse
                INNER JOIN tblTeacherClassLoads tcl ON tcl.teacherClassLoadID = sse.teacherClassLoadID
                LEFT JOIN tblStudents s ON s.studentNumber = sse.studentNumber
                LEFT JOIN tblLmsAccounts la ON la.studentNumber = sse.studentNumber
                LEFT JOIN ref_lookup_values rlv
                    ON rlv.category = 'ENROLLMENT_REASON' AND rlv.code = sse.enrollmentReasonCode
                LEFT JOIN tblSections homeSec ON homeSec.sectionID = sse.homeSectionID
                WHERE 1 = 1
            ";
            $params = [];

            if ($teacherSubjectLoadID !== '') {
                $sql .= " AND tcl.teacherSubjectLoadID = :tsl ";
                $params[':tsl'] = $teacherSubjectLoadID;
            }
            if ($teacherClassLoadID !== '') {
                $sql .= " AND sse.teacherClassLoadID = :tcl ";
                $params[':tcl'] = $teacherClassLoadID;
            }
            if ($statuses !== null) {
                $placeholders = [];
                foreach ($statuses as $i => $st) {
                    $key = ':st' . $i;
                    $placeholders[] = $key;
                    $params[$key] = $st;
                }
                $sql .= " AND sse.enrollmentStatus IN (" . implode(',', $placeholders) . ") ";
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            // Resolve teacherSubjectLoadID for the response when the caller
            // only supplied teacherClassLoadID, so the frontend can cache it
            // for subsequent calls instead of re-deriving it every time.
            if ($teacherSubjectLoadID === '' && !empty($rows)) {
                $teacherSubjectLoadID = (string)($rows[0]['resolvedTeacherSubjectLoadID'] ?? '');
            } elseif ($teacherSubjectLoadID === '' && $teacherClassLoadID !== '') {
                $tclStmt = $db->prepare("SELECT teacherSubjectLoadID FROM tblTeacherClassLoads WHERE teacherClassLoadID = ?");
                $tclStmt->execute([$teacherClassLoadID]);
                $tclRow = $tclStmt->fetch();
                $teacherSubjectLoadID = $tclRow ? (string)($tclRow['teacherSubjectLoadID'] ?? '') : '';
            }

            $refSvc = new SubjectLoadingReferenceDataService();
            $students = [];
            foreach ($rows as $row) {
                [$moodleExportEligible, $moodleIneligibleReason] = $this->_moodleEligibility($row);

                // Home-section display name, join-first with a raw-ID
                // fallback — never surface a raw sectionID as if it were a
                // display label.
                $homeSectionID = (string)($row['homeSectionID'] ?? '');
                $homeSectionName = $row['homeSectionName'] ?: ($homeSectionID !== '' ? $homeSectionID : '');

                $students[] = [
                    'studentSubjectEnrollID' => (string)($row['studentSubjectEnrollID'] ?? ''),
                    'studentNumber'          => (string)($row['sNum'] ?? $row['studentNumber'] ?? ''),
                    'fullName'               => $refSvc->buildStudentFullName($row),
                    'firstName'              => (string)($row['firstName'] ?? ''),
                    'lastName'               => (string)($row['lastName'] ?? ''),
                    'gender'                 => (string)($row['gender'] ?? ''),
                    'enrollmentSource'       => (string)($row['enrollmentSource'] ?? ''),
                    'enrollmentType'         => (string)($row['enrollmentType'] ?? ''),
                    'enrollmentStatus'       => (string)($row['enrollmentStatus'] ?? ''),
                    'statusNote'             => (string)($row['statusNote'] ?? ''),
                    'dateCreated'            => (string)($row['dateCreated'] ?? ''),
                    // Moodle export readiness, computed once here so the
                    // frontend never has to re-derive this rule itself, for
                    // both the on-screen Note column and the "Export for
                    // Moodle" CSV filter.
                    'lmsStatus'              => (string)($row['lmsStatus'] ?? ''),
                    'moodleEmail'            => (string)($row['moodleEmail'] ?? ''),
                    'moodleSyncDate'         => (string)($row['moodleSyncDate'] ?? ''),
                    'moodleExportEligible'   => $moodleExportEligible,
                    'moodleIneligibleReason' => $moodleIneligibleReason,
                    // Cross-enrollment badge fields — enrollmentReasonCode/
                    // homeProgramID/homeYearLevel/homeSectionID come from
                    // sse.*; reasonLabel/homeSectionName are the
                    // display-ready values the badge actually renders.
                    'enrollmentReasonCode'   => (string)($row['enrollmentReasonCode'] ?? ''),
                    'enrollmentReasonLabel'  => (string)($row['reasonLabel'] ?? ''),
                    'homeProgramID'          => (string)($row['homeProgramID'] ?? ''),
                    'homeYearLevel'          => (string)($row['homeYearLevel'] ?? ''),
                    'homeSectionID'          => $homeSectionID,
                    'homeSectionName'        => $homeSectionName,
                ];
            }
            usort($students, fn($a, $b) => strcmp($a['fullName'], $b['fullName']));
            foreach ($students as $i => &$s) { $s['no'] = $i + 1; }
            unset($s);

            echo json_encode([
                'ok'                   => true,
                'teacherSubjectLoadID' => $teacherSubjectLoadID,
                'teacherClassLoadID'   => $teacherClassLoadID,
                'status'               => $statusParam,
                'totalStudents'        => count($students),
                'students'             => $students,
            ]);
        } catch (\Throwable $e) {
            $this->_fail(500, 'SERVER_ERROR', 'Unable to load roster: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/subject-loading/student-loads
    //   ?studentNumber=...  (required)
    //   &academicYear=...   (required)
    //   &semester=...       (required)
    //
    // All subject enrollments for one student in a term — used for a
    // student-centric drill-down view and for bulkEnroll()'s
    // "already enrolled, skip" reasoning on the frontend.
    // ─────────────────────────────────────────────────────────────────
    public function studentLoads()
    {
        try {
            $studentNumber = trim($_GET['studentNumber'] ?? '');
            $academicYear  = trim($_GET['academicYear'] ?? '');
            $semester      = trim($_GET['semester'] ?? '');

            if ($studentNumber === '') { $this->_fail(400, 'VALIDATION_ERROR', 'studentNumber is required.'); return; }
            if ($academicYear === '' || $semester === '') { $this->_fail(400, 'VALIDATION_ERROR', 'academicYear and semester are required.'); return; }

            $db = Database::getConnection();

			$stmt = $db->prepare("
		                SELECT sse.*, sub.subjectCode, sub.subjectTitle,
		                       t.lastName AS tLastName, t.firstName AS tFirstName,
		                       tcl.programID, tcl.yearLevel, tcl.sectionID,
		                       tsl.moodleCourseShortname,
		                       la.moodleEmail,
		                       rlv.label AS reasonLabel,
		                       homeP.programCode AS homeProgramCode,
		                       homeSec.sectionName AS homeSectionName
		                FROM tblStudentSubjectEnrollments sse
		                INNER JOIN tblTeacherClassLoads tcl ON tcl.teacherClassLoadID = sse.teacherClassLoadID
		                INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
		                INNER JOIN tblSubjects sub ON sub.subjectID = tsl.subjectID
		                INNER JOIN tblTeachers t ON t.teacherID = tsl.teacherID
		                LEFT JOIN tblLmsAccounts la ON la.studentNumber = sse.studentNumber
		                LEFT JOIN ref_lookup_values rlv
		                    ON rlv.category = 'ENROLLMENT_REASON' AND rlv.code = sse.enrollmentReasonCode
		                LEFT JOIN tblPrograms homeP   ON homeP.programID   = sse.homeProgramID
		                LEFT JOIN tblSections homeSec ON homeSec.sectionID = sse.homeSectionID
		                WHERE sse.studentNumber = ? AND tsl.academicYear = ? AND tsl.semester = ?
		                ORDER BY sub.subjectCode
		            ");
            $stmt->execute([$studentNumber, $academicYear, $semester]);
            $rows = $stmt->fetchAll();

            $loads = [];
            foreach ($rows as $row) {
                $homeProgramID = (string)($row['homeProgramID'] ?? '');
                $homeSectionID = (string)($row['homeSectionID'] ?? '');
                $homeYearLevel = (string)($row['homeYearLevel'] ?? '');

                // Same join-first, raw-ID-fallback convention used
                // elsewhere in this module — never surface a raw
                // programID/sectionID as if it were a display label.
                $homeProgramCode = $row['homeProgramCode'] ?: ($homeProgramID !== '' ? $homeProgramID : '');
                $homeSectionName = $row['homeSectionName'] ?: ($homeSectionID !== '' ? $homeSectionID : '');
                $homeClassLabel = trim(implode('', [
                    (string)$homeProgramCode,
                    $homeYearLevel !== '' ? preg_replace('/\D/', '', $homeYearLevel) : '',
                ])) . ($homeSectionName !== '' ? '-' . $homeSectionName : '');
                $homeClassLabel = trim($homeClassLabel, '-');

                $loads[] = [
                    'studentSubjectEnrollID' => (string)($row['studentSubjectEnrollID'] ?? ''),
                    'teacherClassLoadID'     => (string)($row['teacherClassLoadID'] ?? ''),
                    'subjectCode'            => (string)($row['subjectCode'] ?? ''),
                    'subjectTitle'           => (string)($row['subjectTitle'] ?? ''),
                    'teacherName'            => trim(($row['tLastName'] ?? '') . ', ' . ($row['tFirstName'] ?? '')),
                    'moodleCourseShortname'  => (string)($row['moodleCourseShortname'] ?? ''),
                    'moodleEmail'            => (string)($row['moodleEmail'] ?? ''),
                    'enrollmentSource'       => (string)($row['enrollmentSource'] ?? ''),
                    'enrollmentType'         => (string)($row['enrollmentType'] ?? ''),
                    'enrollmentStatus'       => (string)($row['enrollmentStatus'] ?? ''),
                    'enrollmentReasonCode'   => (string)($row['enrollmentReasonCode'] ?? ''),
                    'enrollmentReasonLabel'  => (string)($row['reasonLabel'] ?? ''),
                    'enrollmentReasonNote'   => (string)($row['enrollmentReasonNote'] ?? ''),
                    'homeClassLabel'         => $homeClassLabel,
                    'statusNote'             => (string)($row['statusNote'] ?? ''),
                ];
            }            

            echo json_encode([
                'ok'            => true,
                'studentNumber' => $studentNumber,
                'academicYear'  => $academicYear,
                'semester'      => $semester,
                'loads'         => $loads,
            ]);
        } catch (\Throwable $e) {
            $this->_fail(500, 'SERVER_ERROR', 'Unable to load student subject enrollments: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/subject-loading/class-offerings
    //   ?programID=... &yearLevel=... &sectionID=...  (the class triple —
    //     blank values match the "(No Program)"/"(No Year Level)"/
    //     "(No Section)" buckets)
    //   &academicYear=...  &semester=...  (required)
    //
    // Every active Subject Offering available to a given class, each with
    // a live enrolledCount and notYetEnrolledCount so the officer can see,
    // at a glance, which offerings still need students enrolled and by
    // how many. notYetEnrolledCount is computed as an actual set
    // difference (registered-in-class MINUS already-ENROLLED-in-this-
    // offering), not a naive count subtraction, so it stays correct even
    // if an offering also has students enrolled from outside the class
    // (e.g. a cross-enrollment).
    // ─────────────────────────────────────────────────────────────────
    public function classOfferings()
    {
        try {
            $programID    = trim($_GET['programID'] ?? '');
            $yearLevel    = trim($_GET['yearLevel'] ?? '');
            $sectionID    = trim($_GET['sectionID'] ?? '');
            $academicYear = trim($_GET['academicYear'] ?? '');
            $semester     = trim($_GET['semester'] ?? '');

            if ($academicYear === '' || $semester === '') {
                $this->_fail(400, 'VALIDATION_ERROR', 'academicYear and semester are required.');
                return;
            }

            $db = Database::getConnection();

            $totalStmt = $db->prepare("
                SELECT COUNT(*) AS cnt FROM tblRegistrations
                WHERE academicYear = ? AND semester = ?
                  AND COALESCE(programID, '') = ?
                  AND COALESCE(yearLevel, '') = ?
                  AND COALESCE(sectionID, '') = ?
            ");
            $totalStmt->execute([$academicYear, $semester, $programID, $yearLevel, $sectionID]);
            $classTotalStudents = (int)($totalStmt->fetch()['cnt'] ?? 0);

            $tclStmt = $db->prepare("
                SELECT tcl.teacherClassLoadID, tcl.teacherSubjectLoadID,
                       tcl.programID AS tclProgramID, tcl.yearLevel AS tclYearLevel, tcl.sectionID AS tclSectionID,
                       tsl.moodleCourseShortname,
                       sub.subjectCode, sub.subjectTitle,
                       t.lastName, t.firstName,
                       p.programCode, sec.sectionName
                FROM tblTeacherClassLoads tcl
                INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
                INNER JOIN tblSubjects sub ON sub.subjectID = tsl.subjectID
                INNER JOIN tblTeachers t ON t.teacherID = tsl.teacherID
                LEFT JOIN tblPrograms p   ON p.programID   = tcl.programID
                LEFT JOIN tblSections sec ON sec.sectionID = tcl.sectionID
                WHERE tsl.academicYear = ? AND tsl.semester = ?
                  AND tcl.isActive = 1 AND tsl.isActive = 1
                  AND COALESCE(tcl.programID, '') = ?
                  AND COALESCE(tcl.yearLevel, '') = ?
                  AND COALESCE(tcl.sectionID, '') = ?
                ORDER BY sub.subjectCode
            ");
            $tclStmt->execute([$academicYear, $semester, $programID, $yearLevel, $sectionID]);
            $tclRows = $tclStmt->fetchAll();

            $offerings = [];
            foreach ($tclRows as $row) {
                $tclID = (string)$row['teacherClassLoadID'];

                $enrolledStmt = $db->prepare("
                    SELECT COUNT(*) AS cnt FROM tblStudentSubjectEnrollments
                    WHERE teacherClassLoadID = ? AND enrollmentStatus = 'ENROLLED'
                ");
                $enrolledStmt->execute([$tclID]);
                $enrolledCount = (int)($enrolledStmt->fetch()['cnt'] ?? 0);

                $unenrolledStmt = $db->prepare("
                    SELECT COUNT(*) AS cnt FROM tblRegistrations r
                    WHERE r.academicYear = ? AND r.semester = ?
                      AND COALESCE(r.programID, '') = ?
                      AND COALESCE(r.yearLevel, '') = ?
                      AND COALESCE(r.sectionID, '') = ?
                      AND r.studentNumber NOT IN (
                          SELECT studentNumber FROM tblStudentSubjectEnrollments
                          WHERE teacherClassLoadID = ? AND enrollmentStatus = 'ENROLLED'
                      )
                ");
                $unenrolledStmt->execute([$academicYear, $semester, $programID, $yearLevel, $sectionID, $tclID]);
                $notYetEnrolledCount = (int)($unenrolledStmt->fetch()['cnt'] ?? 0);

                $offerings[] = [
                    'teacherClassLoadID'    => $tclID,
                    'teacherSubjectLoadID'  => (string)($row['teacherSubjectLoadID'] ?? ''),
                    'subjectCode'           => (string)($row['subjectCode'] ?? ''),
                    'subjectTitle'          => (string)($row['subjectTitle'] ?? ''),
                    'teacherName'           => trim(($row['lastName'] ?? '') . ', ' . ($row['firstName'] ?? '')),
                    'moodleCourseShortname' => (string)($row['moodleCourseShortname'] ?? ''),
                    'programCode'           => (string)($row['programCode'] ?: ((string)($row['tclProgramID'] ?? '') !== '' ? $row['tclProgramID'] : '(No Program)')),
                    'yearLevel'             => (string)($row['tclYearLevel'] ?? '') !== '' ? (string)$row['tclYearLevel'] : '(No Year Level)',
                    'sectionName'           => (string)($row['sectionName'] ?: ((string)($row['tclSectionID'] ?? '') !== '' ? $row['tclSectionID'] : '(No Section)')),
                    'enrolledCount'         => $enrolledCount,
                    'notYetEnrolledCount'   => $notYetEnrolledCount,
                ];
            }

            echo json_encode([
                'ok'                  => true,
                'programID'           => $programID,
                'yearLevel'           => $yearLevel,
                'sectionID'           => $sectionID,
                'academicYear'        => $academicYear,
                'semester'            => $semester,
                'classTotalStudents'  => $classTotalStudents,
                'offerings'           => $offerings,
            ]);
        } catch (\Throwable $e) {
            $this->_fail(500, 'SERVER_ERROR', 'Unable to load class offerings: ' . $e->getMessage());
        }
    }
    
    // ─────────────────────────────────────────────────────────────────
// GET /api/subject-loading/class-offerings/all
//   ?academicYear=... &semester=...  (required)
//
// Bulk counterpart to classOfferings() — same row shape, flattened
// across every live class in the term, in one response. Powers the
// Class-Based Enrollment tab's progress chips without requiring a
// per-class modal open first. See classOfferings() above for the
// enrolledCount/notYetEnrolledCount semantics.
// ─────────────────────────────────────────────────────────────────
public function classOfferingsAll()
{
    try {
        $academicYear = trim($_GET['academicYear'] ?? '');
        $semester     = trim($_GET['semester'] ?? '');

        if ($academicYear === '' || $semester === '') {
            $this->_fail(400, 'VALIDATION_ERROR', 'academicYear and semester are required.');
            return;
        }

        $db = Database::getConnection();

        $tclStmt = $db->prepare("
            SELECT tcl.teacherClassLoadID, tcl.teacherSubjectLoadID,
                   tcl.programID AS tclProgramID, tcl.yearLevel AS tclYearLevel, tcl.sectionID AS tclSectionID,
                   tsl.moodleCourseShortname,
                   sub.subjectCode, sub.subjectTitle,
                   t.lastName, t.firstName,
                   p.programCode, sec.sectionName
            FROM tblTeacherClassLoads tcl
            INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
            INNER JOIN tblSubjects sub ON sub.subjectID = tsl.subjectID
            INNER JOIN tblTeachers t ON t.teacherID = tsl.teacherID
            LEFT JOIN tblPrograms p   ON p.programID   = tcl.programID
            LEFT JOIN tblSections sec ON sec.sectionID = tcl.sectionID
            WHERE tsl.academicYear = ? AND tsl.semester = ?
              AND tcl.isActive = 1 AND tsl.isActive = 1
            ORDER BY tcl.programID, tcl.yearLevel, tcl.sectionID, sub.subjectCode
        ");
        $tclStmt->execute([$academicYear, $semester]);
        $tclRows = $tclStmt->fetchAll();

        $offerings = [];
        foreach ($tclRows as $row) {
            $tclID     = (string)$row['teacherClassLoadID'];
            $programID = (string)($row['tclProgramID'] ?? '');
            $yearLevel = (string)($row['tclYearLevel'] ?? '');
            $sectionID = (string)($row['tclSectionID'] ?? '');

            $enrolledStmt = $db->prepare("
                SELECT COUNT(*) AS cnt FROM tblStudentSubjectEnrollments
                WHERE teacherClassLoadID = ? AND enrollmentStatus = 'ENROLLED'
            ");
            $enrolledStmt->execute([$tclID]);
            $enrolledCount = (int)($enrolledStmt->fetch()['cnt'] ?? 0);

            $unenrolledStmt = $db->prepare("
                SELECT COUNT(*) AS cnt FROM tblRegistrations r
                WHERE r.academicYear = ? AND r.semester = ?
                  AND COALESCE(r.programID, '') = ?
                  AND COALESCE(r.yearLevel, '') = ?
                  AND COALESCE(r.sectionID, '') = ?
                  AND r.studentNumber NOT IN (
                      SELECT studentNumber FROM tblStudentSubjectEnrollments
                      WHERE teacherClassLoadID = ? AND enrollmentStatus = 'ENROLLED'
                  )
            ");
            $unenrolledStmt->execute([$academicYear, $semester, $programID, $yearLevel, $sectionID, $tclID]);
            $notYetEnrolledCount = (int)($unenrolledStmt->fetch()['cnt'] ?? 0);

            $offerings[] = [
                'teacherClassLoadID'    => $tclID,
                'teacherSubjectLoadID'  => (string)($row['teacherSubjectLoadID'] ?? ''),
                'programID'             => $programID,
                'yearLevel'             => $yearLevel,
                'sectionID'             => $sectionID,
                'subjectCode'           => (string)($row['subjectCode'] ?? ''),
                'subjectTitle'          => (string)($row['subjectTitle'] ?? ''),
                'teacherName'           => trim(($row['lastName'] ?? '') . ', ' . ($row['firstName'] ?? '')),
                'moodleCourseShortname' => (string)($row['moodleCourseShortname'] ?? ''),
                'programCode'           => (string)($row['programCode'] ?: ($programID !== '' ? $programID : '(No Program)')),
                'sectionName'           => (string)($row['sectionName'] ?: ($sectionID !== '' ? $sectionID : '(No Section)')),
                'enrolledCount'         => $enrolledCount,
                'notYetEnrolledCount'   => $notYetEnrolledCount,
            ];
        }

        echo json_encode([
            'ok'           => true,
            'academicYear' => $academicYear,
            'semester'     => $semester,
            'offerings'    => $offerings,
        ]);
    } catch (\Throwable $e) {
        $this->_fail(500, 'SERVER_ERROR', 'Unable to load class offerings: ' . $e->getMessage());
    }
}

    // ─────────────────────────────────────────────────────────────────
    // POST /api/subject-loading/student-loads/enroll-class
    // Body: { teacherClassLoadID, programID, yearLevel, sectionID,
    //         academicYear, semester, enrollmentType, createdBy }
    //
    // "Enroll All Unenrolled": enrolls every currently-registered student
    // in the given class who does not already have an ENROLLED row for
    // this Subject Offering — computed server-side as a single
    // set-difference query, so the count enrolled here can never drift
    // from a stale client-side list. enrollmentSource is MANUAL (an
    // explicit officer action), distinct from syncHomeClass()'s AUTO
    // tagging.
    // ─────────────────────────────────────────────────────────────────
    public function enrollClass()
    {
        $db = null;
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $teacherClassLoadID = trim((string)($input['teacherClassLoadID'] ?? ''));
            $programID          = trim((string)($input['programID'] ?? ''));
            $yearLevel          = trim((string)($input['yearLevel'] ?? ''));
            $sectionID          = trim((string)($input['sectionID'] ?? ''));
            $academicYear       = trim((string)($input['academicYear'] ?? ''));
            $semester           = trim((string)($input['semester'] ?? ''));
            $enrollmentType     = trim((string)($input['enrollmentType'] ?? '')) ?: 'REGULAR';
            $createdBy          = trim((string)($input['createdBy'] ?? ''));

            if ($teacherClassLoadID === '') { $this->_fail(400, 'VALIDATION_ERROR', 'A Subject Offering must be selected.'); return; }
            if ($academicYear === '' || $semester === '') { $this->_fail(400, 'VALIDATION_ERROR', 'academicYear and semester are required.'); return; }

            $db = Database::getConnection();

            if (!$this->_findTeacherClassLoad($db, $teacherClassLoadID)) {
                $this->_fail(404, 'NOT_FOUND', 'Subject Offering was not found: ' . $teacherClassLoadID);
                return;
            }

            $stmt = $db->prepare("
                SELECT r.studentNumber FROM tblRegistrations r
                WHERE r.academicYear = ? AND r.semester = ?
                  AND COALESCE(r.programID, '') = ?
                  AND COALESCE(r.yearLevel, '') = ?
                  AND COALESCE(r.sectionID, '') = ?
                  AND r.studentNumber NOT IN (
                      SELECT studentNumber FROM tblStudentSubjectEnrollments
                      WHERE teacherClassLoadID = ? AND enrollmentStatus = 'ENROLLED'
                  )
            ");
            $stmt->execute([$academicYear, $semester, $programID, $yearLevel, $sectionID, $teacherClassLoadID]);
            $studentNumbers = array_column($stmt->fetchAll(), 'studentNumber');

            if (empty($studentNumbers)) {
                echo json_encode([
                    'ok'            => true,
                    'enrolledCount' => 0,
                    'message'       => 'All students in this class are already enrolled in this Subject Offering.',
                ]);
                return;
            }

            $now = date('Y-m-d H:i:s');
            $db->beginTransaction();
            $seq = SequenceGenerator::reserveIdBlock($db, 'tblStudentSubjectEnrollments', count($studentNumbers));

            $ins = $db->prepare("
                INSERT INTO tblStudentSubjectEnrollments (
                    studentSubjectEnrollID, studentNumber, teacherClassLoadID, registrationNumber,
                    enrollmentSource, enrollmentType, enrollmentStatus,
                    statusNote, statusBy, statusDate, isActive,
                    createdBy, dateCreated, modifiedBy, lastModified
                ) VALUES (
                    ?, ?, ?, ?, 'MANUAL', ?, 'ENROLLED',
                    NULL, NULL, NULL, 1,
                    ?, ?, NULL, NULL
                )
            ");

            $enrolledCount = 0;
            foreach ($studentNumbers as $i => $sn) {
                $newID     = SequenceGenerator::formatId('SEN', $seq['firstNo'] + $i, 9);
                $regNumber = $this->_currentRegistrationNumber($db, $sn);
                $ins->execute([$newID, $sn, $teacherClassLoadID, $this->_nullIfBlank($regNumber), $enrollmentType, $createdBy, $now]);
                $enrolledCount++;
            }
            $db->commit();

            echo json_encode([
                'ok'                  => true,
                'teacherClassLoadID'  => $teacherClassLoadID,
                'enrolledCount'       => $enrolledCount,
                'message'             => $enrolledCount . ' student(s) enrolled.',
            ]);
        } catch (\Throwable $e) {
            if ($db && $db->inTransaction()) $db->rollBack();
            $this->_fail(500, 'SERVER_ERROR', 'Unable to enroll class: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/subject-loading/enrollment-reasons
    //
    // Returns the active ENROLLMENT_REASON lookup rows, via
    // SubjectLoadingReferenceDataService::getLookupValues(), already
    // sorted by sortOrder.
    // ─────────────────────────────────────────────────────────────────
    public function enrollmentReasons()
    {
        try {
            $refSvc  = new SubjectLoadingReferenceDataService();
            $lookups = $refSvc->getLookupValues(['ENROLLMENT_REASON']);

            echo json_encode([
                'ok'      => true,
                'reasons' => $lookups['ENROLLMENT_REASON'] ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->_fail(500, 'SERVER_ERROR', 'Unable to load enrollment reasons: ' . $e->getMessage());
        }
    }

    // ── Private helpers ─────────────────────────────────────────────

    private function _findTeacherClassLoad($db, string $teacherClassLoadID)
    {
        $stmt = $db->prepare("SELECT * FROM tblTeacherClassLoads WHERE teacherClassLoadID = ?");
        $stmt->execute([$teacherClassLoadID]);
        return $stmt->fetch() ?: null;
    }

    private function _findEnrollment($db, string $studentNumber, string $teacherClassLoadID)
    {
        $stmt = $db->prepare("
            SELECT * FROM tblStudentSubjectEnrollments
            WHERE studentNumber = ? AND teacherClassLoadID = ?
            LIMIT 1
        ");
        $stmt->execute([$studentNumber, $teacherClassLoadID]);
        return $stmt->fetch() ?: null;
    }

    // Most recent registration, for traceability/audit only —
    // registrationNumber is not part of the enrollment row's identity.
    private function _currentRegistrationNumber($db, string $studentNumber): ?string
    {
        $stmt = $db->prepare("
            SELECT RegistrationNumber FROM tblRegistrations
            WHERE studentNumber = ? ORDER BY dateCreated DESC LIMIT 1
        ");
        $stmt->execute([$studentNumber]);
        $row = $stmt->fetch();
        return $row ? (string)($row['RegistrationNumber'] ?? '') : null;
    }

    // Determines whether a roster row is eligible for the "Export for
    // Moodle" CSV, and why not if it isn't. A student is eligible only if
    // they have a tblLmsAccounts row (LEFT JOINed in roster()'s SQL as
    // la.*), that row's status is CREATED, and moodleEmail is non-blank
    // (i.e. a confirmed Moodle account synced via
    // LmsController::importMoodleCsv() — not a derived/guessed email).
    // Used both to filter the CSV export and to populate the on-screen
    // roster table's Note column, so the eligibility rule lives in one
    // place only.
    private function _moodleEligibility(array $row): array
    {
        $hasLmsRow   = ($row['lmsStudentNumber'] ?? null) !== null;
        $lmsStatus   = strtoupper(trim((string)($row['lmsStatus'] ?? '')));
        $moodleEmail = trim((string)($row['moodleEmail'] ?? ''));

        if (!$hasLmsRow) {
            return [false, 'No LMS account yet'];
        }
        if ($lmsStatus !== 'CREATED') {
            return [false, 'LMS account not yet created (status: ' . $lmsStatus . ')'];
        }
        if ($moodleEmail === '') {
            return [false, "Not yet synced to Moodle — student hasn't logged in"];
        }
        return [true, ''];
    }

    // Parses the ?status= filter. Returns null for "no filter" (ALL), or
    // an array of statuses to match against enrollmentStatus. Defaults
    // to ['ENROLLED'] for blank/invalid input.
    private function _parseStatusFilter(string $raw): ?array
    {
        $raw = strtoupper(trim($raw));
        if ($raw === '' || $raw === 'ENROLLED') return ['ENROLLED'];
        if ($raw === 'ALL') return null;
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $parts = array_values(array_intersect($parts, self::VALID_STATUSES));
        return empty($parts) ? ['ENROLLED'] : $parts;
    }

    // Same blank-to-NULL rule used elsewhere in this codebase.
    private function _nullIfBlank($value)
    {
        if ($value === null) return null;
        $normalized = trim((string)$value);
        if ($normalized === '' || strtolower($normalized) === 'null') return null;
        return $value;
    }

    private function _fail(int $httpCode, string $code, string $message): void
    {
        http_response_code($httpCode);
        echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message]]);
    }

    private function _truthy($value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        $s = strtolower(trim((string)$value));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
