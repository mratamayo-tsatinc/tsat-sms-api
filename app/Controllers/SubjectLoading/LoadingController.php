<?php

namespace App\Controllers\SubjectLoading;

use App\Core\Database;
use App\Services\SubjectLoadingReferenceDataService;

/**
 * Shared bootstrap/reference endpoints for the Subject Loading module.
 *
 * activeTerm()/programs()/sections() are thin pass-through methods that
 * internally call the existing ReferenceDataService's public methods
 * read-only, via the new SubjectLoadingReferenceDataService (§5.3) —
 * ReferenceDataService.php itself is never touched. classes()/classRoster()
 * reimplement EnrollmentController's query shapes as new, independent code
 * (§0.2) — no call into EnrollmentController.php.
 *
 * bootstrap() deliberately does NOT copy EnrollmentController::bootstrap()'s
 * bare-object shape — it follows the §5.0 envelope like every other
 * endpoint in this module (see §5.0).
 */
class LoadingController
{
    /**
     * GET /api/subject-loading/bootstrap
     * {ok: true, activeTerm, teachers, subjects}
     */
    public function bootstrap()
    {
        try {
            $service = new SubjectLoadingReferenceDataService();

            try {
                $term = $service->getActiveTerm();
            } catch (\Exception $e) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'ACTIVE_TERM_UNSET',
                    'message' => $e->getMessage(),
                ]]);
                return;
            }

            $teachers = $service->getAllTeachers(true);
            $subjects = $service->getActiveSubjects();

            echo json_encode([
                'ok' => true,
                'activeTerm' => $term,
                'teachers' => $teachers,
                'subjects' => $subjects,
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/subject-loading/active-term
     * {ok: true, academicYear, semester} — mirrors
     * EnrollmentController::activeTerm()'s shape (§12.6), with `ok`
     * made consistently present per §5.0.
     */
    public function activeTerm()
    {
        try {
            $term = (new SubjectLoadingReferenceDataService())->getActiveTerm();
            echo json_encode([
                'ok' => true,
                'academicYear' => $term['academicYear'],
                'semester' => $term['semester'],
            ]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => [
                'code' => 'ACTIVE_TERM_UNSET',
                'message' => $e->getMessage(),
            ]]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/subject-loading/programs
     */
    public function programs()
    {
        try {
            $programs = (new SubjectLoadingReferenceDataService())->getAllPrograms();
            echo json_encode(['ok' => true, 'programs' => $programs]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/subject-loading/sections
     */
    public function sections()
    {
        try {
            $sections = (new SubjectLoadingReferenceDataService())->getAllSections();
            echo json_encode(['ok' => true, 'sections' => $sections]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/subject-loading/classes?academicYear=&semester=
     * Derives the distinct class list for the term from tblRegistrations
     * (same grouping logic as EnrollmentController::summary()'s byClass),
     * via SubjectLoadingReferenceDataService::getDistinctClasses() — new,
     * independent code, not a call into EnrollmentController.
     * If academicYear/semester are omitted, defaults to the active term.
     */
    public function classes()
    {
        try {
            $service = new SubjectLoadingReferenceDataService();

            $academicYear = trim($_GET['academicYear'] ?? '');
            $semester     = trim($_GET['semester'] ?? '');

            if ($academicYear === '' || $semester === '') {
                try {
                    $term = $service->getActiveTerm();
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

            $classes = $service->getDistinctClasses($academicYear, $semester);

            echo json_encode([
                'ok' => true,
                'academicYear' => $academicYear,
                'semester' => $semester,
                'classes' => $classes,
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/subject-loading/class-roster
     *   ?academicYear=&semester=&yearLevel=&programID=&sectionID=
     *
     * Thin pass-through equivalent of EnrollmentController::roster()'s
     * query shape (§12.6), reimplemented as new code — not routed to the
     * existing controller, per §0.2. Used to pre-fill the "whole class"
     * bulk-enroll source in Phase 3.
     */
    public function classRoster()
    {
        try {
            $academicYear = trim($_GET['academicYear'] ?? '');
            $semester     = trim($_GET['semester'] ?? '');
            $yearLevel    = trim($_GET['yearLevel'] ?? '');
            $programID    = trim($_GET['programID'] ?? '');
            $sectionID    = trim($_GET['sectionID'] ?? '');

            if ($academicYear === '' || $semester === '' || $yearLevel === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'academicYear, semester, and yearLevel are required.',
                ]]);
                return;
            }

            $db = Database::getConnection();
            $refService = new SubjectLoadingReferenceDataService();

            $stmt = $db->prepare("
                SELECT
                    r.RegistrationNumber,
                    r.studentNumber,
                    r.dateCreated,
                    r.yearLevel,
                    p.programCode,
                    sec.sectionName,
                    s.lastName,
                    s.nameExtension,
                    s.firstName,
                    s.middleName,
                    s.middleInitial,
                    s.gender
                FROM tblRegistrations r
                LEFT JOIN tblPrograms p   ON p.programID   = r.programID
                LEFT JOIN tblSections sec ON sec.sectionID = r.sectionID
                LEFT JOIN tblStudents s   ON s.studentNumber = r.studentNumber
                WHERE r.academicYear      = :academicYear
                  AND r.semester          = :semester
                  AND COALESCE(r.programID, '') = :programID
                  AND COALESCE(r.sectionID, '') = :sectionID
            ");
            $stmt->execute([
                ':academicYear' => $academicYear,
                ':semester'     => $semester,
                ':programID'    => $programID,
                ':sectionID'    => $sectionID,
            ]);
            $rawRows = $stmt->fetchAll();

            $wantedYearLevel = $yearLevel !== '' ? $yearLevel : '(No Year Level)';

            $roster = [];
            foreach ($rawRows as $row) {
                $rowYearLevel = (string)($row['yearLevel'] ?? '');
                $rowYearLevel = $rowYearLevel !== '' ? $rowYearLevel : '(No Year Level)';
                if ($rowYearLevel !== $wantedYearLevel) {
                    continue;
                }

                $fullName = $refService->buildStudentFullName([
                    'lastName'      => $row['lastName'] ?? '',
                    'firstName'     => $row['firstName'] ?? '',
                    'middleName'    => $row['middleName'] ?? '',
                    'middleInitial' => $row['middleInitial'] ?? '',
                    'nameExtension' => $row['nameExtension'] ?? '',
                ]);

                $roster[] = [
                    'registrationNumber' => (string)($row['RegistrationNumber'] ?? ''),
                    'studentNumber'      => (string)($row['studentNumber'] ?? ''),
                    'fullName'           => $fullName,
                    'firstName'          => (string)($row['firstName'] ?? ''),
                    'lastName'           => (string)($row['lastName'] ?? ''),
                    'middleName'         => (string)($row['middleName'] ?? ''),
                    'middleInitial'      => (string)($row['middleInitial'] ?? ''),
                    'nameExtension'      => (string)($row['nameExtension'] ?? ''),
                    'gender'             => (string)($row['gender'] ?? ''),
                    'programCode'        => (string)($row['programCode'] ?? ($programID !== '' ? $programID : '(No Program)')),
                    'sectionName'        => (string)($row['sectionName'] ?? ($sectionID !== '' ? $sectionID : '(No Section)')),
                    'yearLevel'          => $rowYearLevel,
                ];
            }

            usort($roster, fn($a, $b) => strcmp($a['fullName'], $b['fullName']));
            foreach ($roster as $i => &$r) {
                $r['rowNumber'] = $i + 1;
            }
            unset($r);

            echo json_encode([
                'ok' => true,
                'academicYear' => $academicYear,
                'semester' => $semester,
                'yearLevel' => $wantedYearLevel,
                'programID' => $programID,
                'sectionID' => $sectionID,
                'roster' => $roster,
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
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
