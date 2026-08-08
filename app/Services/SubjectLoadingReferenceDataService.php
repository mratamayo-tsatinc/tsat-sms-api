<?php

namespace App\Services;

use App\Core\Database;

/**
 * Reference/lookup data for the Subject Loading module.
 *
 * Some methods compose ReferenceDataService's existing public methods
 * directly (shared reads with no module-specific logic). Others implement
 * logic specific to this module, such as the class-bucketing rule used to
 * derive distinct classes from registrations.
 */
class SubjectLoadingReferenceDataService
{
    // -----------------------------------------------------------------
    // Composed reads — delegate to ReferenceDataService's shared,
    // module-agnostic methods.
    // -----------------------------------------------------------------

    public function getActiveTerm(): array
    {
        // Throws \Exception if tblAppSettings is missing
        // activeacademicyear/activesemester. Callers should catch this and
        // translate it into their own error response.
        return (new ReferenceDataService())->getActiveTerm();
    }

    public function getAllPrograms(): array
    {
        return (new ReferenceDataService())->getAllPrograms();
    }

    public function getAllSections(): array
    {
        return (new ReferenceDataService())->getAllSections();
    }

    public function buildStudentFullName(array $row): string
    {
        return (new ReferenceDataService())->buildStudentFullName($row);
    }

    // Reads lookup values (e.g. ENROLLMENT_REASON) via
    // ReferenceDataService::getLookupValues().
    public function getLookupValues(array $categories): array
    {
        return (new ReferenceDataService())->getLookupValues($categories);
    }

    // -----------------------------------------------------------------
    // Module-specific logic.
    // -----------------------------------------------------------------

    /**
     * Returns [{ teacherID, label, ... }] — all teachers, or active-only.
     */
    public function getAllTeachers(bool $activeOnly = false): array
    {
        $db = Database::getConnection();

        $sql = "
            SELECT teacherID, lastName, firstName, middleName, middleInitial,
                   nameExtension, emailAddress, isActive
            FROM tblTeachers
        ";
        if ($activeOnly) {
            $sql .= " WHERE isActive = 1 ";
        }
        $sql .= " ORDER BY lastName, firstName ";

        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $teacherID = (string)($row['teacherID'] ?? '');
            if ($teacherID === '') continue;
            $lastName  = (string)($row['lastName'] ?? '');
            $firstName = (string)($row['firstName'] ?? '');
            $label = trim(implode(', ', array_filter([$lastName, $firstName], fn($p) => $p !== '')));
            $out[] = [
                'teacherID'     => $teacherID,
                'lastName'      => $lastName,
                'firstName'     => $firstName,
                'middleName'    => (string)($row['middleName'] ?? ''),
                'middleInitial' => (string)($row['middleInitial'] ?? ''),
                'nameExtension' => (string)($row['nameExtension'] ?? ''),
                'emailAddress'  => (string)($row['emailAddress'] ?? ''),
                'isActive'      => (bool)((int)($row['isActive'] ?? 0)),
                'label'         => $label !== '' ? $label : $teacherID,
            ];
        }

        return $out;
    }

    /**
     * Returns [{ subjectID, subjectCode, subjectTitle, lecUnits, labUnits, label }]
     * for isActive = 1 subjects only.
     */
    public function getActiveSubjects(): array
    {
        $db = Database::getConnection();

        $stmt = $db->query("
            SELECT subjectID, subjectCode, subjectTitle, lecUnits, labUnits
            FROM tblSubjects
            WHERE isActive = 1
            ORDER BY subjectCode, subjectTitle
        ");
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $subjectID = (string)($row['subjectID'] ?? '');
            if ($subjectID === '') continue;
            $code  = (string)($row['subjectCode'] ?? '');
            $title = (string)($row['subjectTitle'] ?? '');
            $label = trim(implode(' - ', array_filter([$code, $title], fn($p) => $p !== '')));
            $out[] = [
                'subjectID'    => $subjectID,
                'subjectCode'  => $code,
                'subjectTitle' => $title,
                'lecUnits'     => (float)($row['lecUnits'] ?? 0),
                'labUnits'     => (float)($row['labUnits'] ?? 0),
                'label'        => $label !== '' ? $label : $subjectID,
            ];
        }

        return $out;
    }

    /**
     * Returns the distinct (programID, yearLevel, sectionID) classes
     * enrolled in a term, each annotated with a live count of active
     * Subject Offerings.
     *
     * Algorithm:
     *   1. Query tblRegistrations LEFT JOIN tblPrograms / tblSections,
     *      filtered to academicYear/semester. programCode/sectionName come
     *      only from the join, never from tblSections' own program/year
     *      columns.
     *   2. Blank yearLevel -> '(No Year Level)'; blank programID/sectionID
     *      -> row is bucketed under a fallback ('(No Program)'/'(No Section)').
     *   3. The composite bucket key is the raw (possibly blank)
     *      programID/sectionID + yearLevel, not the display label.
     *   4. Display programCode/sectionName fall back to the raw ID only
     *      if the joined name/code came back empty, not merely because the
     *      ID itself is blank.
     *   5. classCode = programCode + yearLevelDigits + "-" + sectionName.
     *   6. Fallback buckets sort after non-fallback ones; within each
     *      group, sort by classCode string comparison.
     *
     * activeOfferingCount is the count of active tblTeacherClassLoads rows
     * (with an active parent tblTeacherSubjectLoads) matching each class's
     * raw key, for the given academicYear/semester — computed via one
     * additional grouped query and merged in PHP by key, not per-row.
     */
    public function getDistinctClasses(string $academicYear, string $semester): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT
                r.RegistrationNumber,
                r.programID,
                r.sectionID,
                r.yearLevel,
                p.programCode,
                p.programDescription,
                sec.sectionName
            FROM tblRegistrations r
            LEFT JOIN tblPrograms p   ON p.programID   = r.programID
            LEFT JOIN tblSections sec ON sec.sectionID = r.sectionID
            WHERE r.academicYear = :academicYear
              AND r.semester     = :semester
        ");
        $stmt->execute([':academicYear' => $academicYear, ':semester' => $semester]);
        $rows = $stmt->fetchAll();

        $classCounts = [];
        foreach ($rows as $reg) {
            $programID = (string)($reg['programID'] ?? '');
            $sectionID = (string)($reg['sectionID'] ?? '');
            $yearLevelRaw = (string)($reg['yearLevel'] ?? '');
            $yearLevel = $yearLevelRaw !== '' ? $yearLevelRaw : '(No Year Level)';

            $classProgramKey = $programID !== '' ? $programID : '(No Program)';
            $classSectionKey = $sectionID !== '' ? $sectionID : '(No Section)';
            $classKey = $classProgramKey . '|' . $yearLevel . '|' . $classSectionKey;

            if (!isset($classCounts[$classKey])) {
                $isFallbackProgram = $programID === '';
                $isFallbackSection = $sectionID === '';
                $classCounts[$classKey] = [
                    'programID'    => $isFallbackProgram ? '' : $programID,
                    'programCode'  => $isFallbackProgram ? '(No Program)' : (($reg['programCode'] ?: null) ?: $programID),
                    'yearLevel'    => $yearLevel,
                    // Raw (possibly blank) yearLevel, distinct from the
                    // display fallback above — needed so callers that
                    // round-trip this class back into a write send the same
                    // blank-vs-value semantics tblRegistrations uses, not
                    // the literal string "(No Year Level)".
                    'yearLevelRaw' => $yearLevelRaw,
                    'sectionID'    => $isFallbackSection ? '' : $sectionID,
                    'sectionName'  => $isFallbackSection ? '(No Section)' : (($reg['sectionName'] ?: null) ?: $sectionID),
                    'count'        => 0,
                    'isFallback'   => $isFallbackProgram || $isFallbackSection,
                ];
            }
            $classCounts[$classKey]['count']++;
        }

        // Grouped, offering-driven query run alongside the registration-
        // driven query above, merged in PHP by matching the same
        // blank-normalized key (the two queries have different WHERE
        // clauses, so merging in PHP keeps each independently readable).
        $offeringStmt = $db->prepare("
            SELECT tcl.programID, tcl.yearLevel, tcl.sectionID, COUNT(*) AS offeringCount
            FROM tblTeacherClassLoads tcl
            INNER JOIN tblTeacherSubjectLoads tsl
                    ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
            WHERE tsl.academicYear = :academicYear AND tsl.semester = :semester
              AND tcl.isActive = 1 AND tsl.isActive = 1
            GROUP BY tcl.programID, tcl.yearLevel, tcl.sectionID
        ");
        $offeringStmt->execute([':academicYear' => $academicYear, ':semester' => $semester]);
        $offeringRows = $offeringStmt->fetchAll();

        $offeringCounts = []; // classKey => offeringCount
        foreach ($offeringRows as $row) {
            $tclProgramID = (string)($row['programID'] ?? '');
            $tclSectionID = (string)($row['sectionID'] ?? '');
            $tclYearLevelRaw = (string)($row['yearLevel'] ?? '');
            $tclYearLevel = $tclYearLevelRaw !== '' ? $tclYearLevelRaw : '(No Year Level)';

            $tclProgramKey = $tclProgramID !== '' ? $tclProgramID : '(No Program)';
            $tclSectionKey = $tclSectionID !== '' ? $tclSectionID : '(No Section)';
            $tclClassKey = $tclProgramKey . '|' . $tclYearLevel . '|' . $tclSectionKey;

            $offeringCounts[$tclClassKey] = ($offeringCounts[$tclClassKey] ?? 0) + (int)($row['offeringCount'] ?? 0);
        }

        $byClass = array_values($classCounts);
        foreach ($byClass as &$c) {
            $ylDigits = $this->_yearLevelDigits($c['yearLevel']);
            // No separator between program code and year-digit — e.g.
            // "IT1-MANDRAKE", "SHS11-HARRINGTON".
            $c['classCode'] = $c['programCode'] . $ylDigits . '-' . $c['sectionName'];

            // Recompute this bucket's raw key the same way it was built
            // above to look up the merged offering count. Always present
            // — 0, not omitted, when a class has no active offerings yet.
            $lookupProgramKey = $c['programID'] !== '' ? $c['programID'] : '(No Program)';
            $lookupSectionKey = $c['sectionID'] !== '' ? $c['sectionID'] : '(No Section)';
            $lookupKey = $lookupProgramKey . '|' . $c['yearLevel'] . '|' . $lookupSectionKey;
            $c['activeOfferingCount'] = $offeringCounts[$lookupKey] ?? 0;
        }
        unset($c);

        usort($byClass, function ($a, $b) {
            if ($a['isFallback'] && !$b['isFallback']) return 1;
            if (!$a['isFallback'] && $b['isFallback']) return -1;
            return strcmp($a['classCode'], $b['classCode']);
        });

        return $byClass;
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
}
