<?php

namespace App\Services;

use App\Core\Database;

/**
 * App\Services\ExamPermitReferenceDataService
 *
 * Read-only. Composes ReferenceDataService for the active term. Implements
 * its own term-scoped student roster + per-student subject/attendance
 * report queries — this module writes nothing, so there is no
 * mirror/service split like ECR's.
 */
class ExamPermitReferenceDataService
{
    private const VALID_PERIODS = ['PRELIM', 'MIDTERM', 'SEMIFINALS', 'FINALS'];

    public function getActiveTerm(): array
    {
        return (new ReferenceDataService())->getActiveTerm();
    }

    /**
     * Every student registered in the given term, one row per
     * studentNumber (a student with multiple registrations in the SAME
     * term — should not normally happen — is deduped to their most
     * recent registration by dateCreated). Powers the filterable list
     * (by name / student number / class) — filtering itself happens
     * client-side against this one bulk fetch, same "fetch once, filter
     * in memory" pattern already used everywhere else in this codebase
     * (Class Loading, Student Subject Loading, Rosters & Export).
     *
     * @return array<int, array{studentNumber:string, fullName:string,
     *   lastName:string, firstName:string, registrationNumber:string,
     *   programID:string, programCode:string, yearLevel:string,
     *   sectionID:string, sectionName:string, classCode:string}>
     */
    public function getTermStudentRoster(string $academicYear, string $semester): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT r.RegistrationNumber, r.studentNumber, r.programID, r.yearLevel, r.sectionID,
                   r.dateCreated,
                   s.lastName, s.firstName, s.middleName, s.middleInitial, s.nameExtension,
                   p.programCode, sec.sectionName
            FROM tblRegistrations r
            INNER JOIN tblStudents s ON s.studentNumber = r.studentNumber
            LEFT JOIN tblPrograms p   ON p.programID   = r.programID
            LEFT JOIN tblSections sec ON sec.sectionID = r.sectionID
            WHERE r.academicYear = :academicYear AND r.semester = :semester
            ORDER BY r.dateCreated DESC
        ");
        $stmt->execute([':academicYear' => $academicYear, ':semester' => $semester]);
        $rows = $stmt->fetchAll();

        $refSvc = new SubjectLoadingReferenceDataService();
        $byStudent = [];
        foreach ($rows as $row) {
            $sn = (string)($row['studentNumber'] ?? '');
            if ($sn === '' || isset($byStudent[$sn])) continue; // first row wins = most recent (DESC order)

            $programID = (string)($row['programID'] ?? '');
            $sectionID = (string)($row['sectionID'] ?? '');
            $yearLevel = (string)($row['yearLevel'] ?? '');
            $programCode = $row['programCode'] ?: ($programID !== '' ? $programID : '(No Program)');
            $sectionName = $row['sectionName'] ?: ($sectionID !== '' ? $sectionID : '(No Section)');

            $byStudent[$sn] = [
                'studentNumber'      => $sn,
                'registrationNumber' => (string)($row['RegistrationNumber'] ?? ''),
                'fullName'           => $refSvc->buildStudentFullName($row),
                'lastName'           => (string)($row['lastName'] ?? ''),
                'firstName'          => (string)($row['firstName'] ?? ''),
                'programID'          => $programID,
                'programCode'        => (string)$programCode,
                'yearLevel'          => $yearLevel !== '' ? $yearLevel : '(No Year Level)',
                'sectionID'          => $sectionID,
                'sectionName'        => (string)$sectionName,
                'classCode'          => $this->_buildClassCode((string)$programCode, $yearLevel, (string)$sectionName),
            ];
        }

        $out = array_values($byStudent);
        usort($out, fn($a, $b) => strcmp($a['lastName'] . $a['firstName'], $b['lastName'] . $b['firstName']));
        return $out;
    }

    /**
     * One student's full exam-permit payload: their registration/class
     * header, plus every ENROLLED subject offering with teacher/class and
     * that offering's ACCUMULATED attendance (period='TERM' — the sum of
     * every graded period synced so far, NOT one single period). $period
     * is used ONLY to validate and echo back which permit TYPE is being
     * generated (for the printed title, e.g. "PRELIM Exam Permit") — it
     * never filters or changes the attendance figures returned. Attendance
     * is identical in the response regardless of which of the four valid
     * $period values is passed.
     * DROPPED/CREDITED subject enrollments are excluded — an exam permit
     * only ever lists what the student is currently taking.
     *
     * @return array{ok:bool, student:?array, subjects:array, error:?string}
     */
    public function getStudentExamPermit(string $studentNumber, string $academicYear, string $semester, string $period): array
    {
        $period = strtoupper(trim($period));
        if (!in_array($period, self::VALID_PERIODS, true)) {
            return ['ok' => false, 'student' => null, 'subjects' => [], 'error' => 'INVALID_PERIOD'];
        }
        // NOTE: $period is NOT used in the attendance query below — it is
        // purely the permit-type label, returned to the caller as-is.
        // Attendance always reads the accumulated 'TERM' row.

        $db = Database::getConnection();

        $regStmt = $db->prepare("
            SELECT r.RegistrationNumber, r.programID, r.yearLevel, r.sectionID,
                   s.studentNumber, s.lastName, s.firstName, s.middleName, s.middleInitial, s.nameExtension,
                   p.programCode, sec.sectionName
            FROM tblRegistrations r
            INNER JOIN tblStudents s ON s.studentNumber = r.studentNumber
            LEFT JOIN tblPrograms p   ON p.programID   = r.programID
            LEFT JOIN tblSections sec ON sec.sectionID = r.sectionID
            WHERE r.studentNumber = :sn AND r.academicYear = :ay AND r.semester = :sem
            ORDER BY r.dateCreated DESC
            LIMIT 1
        ");
        $regStmt->execute([':sn' => $studentNumber, ':ay' => $academicYear, ':sem' => $semester]);
        $reg = $regStmt->fetch();
        if (!$reg) {
            return ['ok' => false, 'student' => null, 'subjects' => [], 'error' => 'NOT_REGISTERED_THIS_TERM'];
        }

        $refSvc = new SubjectLoadingReferenceDataService();
        $programID = (string)($reg['programID'] ?? '');
        $sectionID = (string)($reg['sectionID'] ?? '');
        $yearLevel = (string)($reg['yearLevel'] ?? '');
        $programCode = $reg['programCode'] ?: ($programID !== '' ? $programID : '(No Program)');
        $sectionName = $reg['sectionName'] ?: ($sectionID !== '' ? $sectionID : '(No Section)');

        $student = [
            'studentNumber'      => (string)($reg['studentNumber'] ?? $studentNumber),
            'registrationNumber' => (string)($reg['RegistrationNumber'] ?? ''),
            'fullName'           => $refSvc->buildStudentFullName($reg),
            'classCode'          => $this->_buildClassCode((string)$programCode, $yearLevel, (string)$sectionName),
            'programCode'        => (string)$programCode,
            'yearLevel'          => $yearLevel !== '' ? $yearLevel : '(No Year Level)',
            'sectionName'        => (string)$sectionName,
        ];

        // Subjects: ENROLLED only, joined to their offering's teacher/class,
        // LEFT JOIN attendance summary hardcoded to period='TERM' — the
        // ACCUMULATED figure across every graded period synced so far, not
        // any single period. A subject with no synced attendance yet still
        // appears, with null figures — never silently dropped from the
        // permit.
        $subStmt = $db->prepare("
            SELECT sse.teacherClassLoadID, sub.subjectCode, sub.subjectTitle,
                   t.lastName AS tLastName, t.firstName AS tFirstName,
                   tcl.programID AS tclProgramID, tcl.yearLevel AS tclYearLevel, tcl.sectionID AS tclSectionID,
                   tp.programCode AS tclProgramCode, tsec.sectionName AS tclSectionName,
                   ea.presentCount, ea.lateCount, ea.absentCount, ea.excusedCount, ea.lastSyncedAt
            FROM tblStudentSubjectEnrollments sse
            INNER JOIN tblTeacherClassLoads tcl ON tcl.teacherClassLoadID = sse.teacherClassLoadID
            INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
            INNER JOIN tblSubjects sub ON sub.subjectID = tsl.subjectID
            INNER JOIN tblTeachers t ON t.teacherID = tsl.teacherID
            LEFT JOIN tblPrograms tp   ON tp.programID   = tcl.programID
            LEFT JOIN tblSections tsec ON tsec.sectionID = tcl.sectionID
            LEFT JOIN tblEcrAttendanceSummary ea
                   ON ea.teacherClassLoadID = sse.teacherClassLoadID
                  AND ea.studentNumber = sse.studentNumber
                  AND ea.period = 'TERM'
                  AND ea.lastSyncedAt = (
                        SELECT MAX(ea2.lastSyncedAt) FROM tblEcrAttendanceSummary ea2
                        WHERE ea2.teacherClassLoadID = sse.teacherClassLoadID
                  )
            WHERE sse.studentNumber = :sn
              AND sse.enrollmentStatus = 'ENROLLED'
              AND tsl.academicYear = :ay AND tsl.semester = :sem
            ORDER BY sub.subjectCode
        ");
        $subStmt->execute([
            ':sn' => $studentNumber, ':ay' => $academicYear, ':sem' => $semester,
        ]);

        $subjects = [];
        foreach ($subStmt->fetchAll() as $row) {
            $tclProgramID = (string)($row['tclProgramID'] ?? '');
            $tclSectionID = (string)($row['tclSectionID'] ?? '');
            $tclYearLevel = (string)($row['tclYearLevel'] ?? '');
            $tclProgramCode = $row['tclProgramCode'] ?: ($tclProgramID !== '' ? $tclProgramID : '(No Program)');
            $tclSectionName = $row['tclSectionName'] ?: ($tclSectionID !== '' ? $tclSectionID : '(No Section)');

            $present = $row['presentCount'] !== null ? (int)$row['presentCount'] : null;
            $late    = $row['lateCount']    !== null ? (int)$row['lateCount']    : null;
            $absent  = $row['absentCount']  !== null ? (int)$row['absentCount']  : null;
            $excused = $row['excusedCount'] !== null ? (int)$row['excusedCount'] : null;

            $hasAttendance = $present !== null;
            $totalMeetings = $hasAttendance ? ($present + $late + $absent + $excused) : null;
            $rate = ($hasAttendance && $totalMeetings > 0)
                ? round((($present + $late) / $totalMeetings) * 100, 1)
                : null;

            $subjects[] = [
                'teacherClassLoadID' => (string)($row['teacherClassLoadID'] ?? ''),
                'subjectCode'        => (string)($row['subjectCode'] ?? ''),
                'subjectTitle'       => (string)($row['subjectTitle'] ?? ''),
                'teacherName'        => trim(($row['tLastName'] ?? '') . ', ' . ($row['tFirstName'] ?? '')),
                'classCode'          => $this->_buildClassCode((string)$tclProgramCode, $tclYearLevel, (string)$tclSectionName),
                'attendance'         => [
                    'present'        => $present,
                    'late'           => $late,
                    'absent'         => $absent,
                    'excused'        => $excused,
                    'totalMeetings'  => $totalMeetings,
                    'ratePercent'    => $rate, // null = not yet synced for this period
                    'lastSyncedAt'   => $row['lastSyncedAt'] !== null ? (string)$row['lastSyncedAt'] : null,
                ],
            ];
        }

        return ['ok' => true, 'student' => $student, 'subjects' => $subjects, 'permitType' => $period, 'error' => null];
    }

    // Same "{programCode}{yearLevelDigits}-{sectionName}" convention used
    // client-side by buildOfferingClassCode() in subjectloading.html/ecr.html
    // and server-side by SubjectLoadingReferenceDataService's classCode —
    // duplicated here deliberately (this module never edits those files).
    private function _buildClassCode(string $programCode, string $yearLevel, string $sectionName): string
    {
        $digits = '0';
        if (preg_match('/(\d+)/', $yearLevel, $m)) {
            $digits = $m[1];
        } elseif (stripos($yearLevel, 'Grade 11') !== false) {
            $digits = '11';
        } elseif (stripos($yearLevel, 'Grade 12') !== false) {
            $digits = '12';
        }
        return $programCode . $digits . '-' . $sectionName;
    }
}
