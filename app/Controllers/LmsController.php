<?php

namespace App\Controllers;

use App\Core\Database;

class LmsController
{
    private const VALID_STATUSES = ['PENDING', 'ON_PROCESS', 'CREATED'];
    
    // ─────────────────────────────────────────────────────────────────────────
    // Derives the LMS username from student name and number.
    // Format: [initials of firstName words][lastName].[studentNumber]
    // All lowercase, ñ/Ñ → n, non-alphanumeric characters removed.
    // Written to tblLmsAccounts.username on first insert and stable thereafter.
    // ─────────────────────────────────────────────────────────────────────────
    private function _deriveUsername(string $firstName, string $lastName, string $studentNumber): string
    {
        $fn = str_replace(['ñ', 'Ñ'], 'n', $firstName);
        $ln = str_replace(['ñ', 'Ñ'], 'n', $lastName);
        $sn = trim($studentNumber);

        $words = preg_split('/\s+/', trim($fn));
        $initials = '';
        foreach ($words as $w) {
            if ($w !== '') $initials .= $w[0];
        }
        $initials = preg_replace('/[^a-zA-Z0-9]/', '', $initials);

        $lastNameClean = preg_replace('/[^a-zA-Z0-9]/', '', preg_replace('/\s+/', '', $ln));

        return strtolower($initials . $lastNameClean . '.' . $sn);
    }

    // Looks up personalEmail (tblStudents.emailAddress) and derives the
    // username for a student who has no tblLmsAccounts row yet. Used by
    // processAccount()'s insert branch and both CSV import endpoints'
    // insert branches later in this plan — unified into one helper.
    private function _lookupPersonalEmailAndUsername($db, string $studentNumber): array
    {
        $stmt = $db->prepare("SELECT firstName, lastName, emailAddress FROM tblStudents WHERE studentNumber = ?");
        $stmt->execute([$studentNumber]);
        $row = $stmt->fetch();
        if (!$row) return ['personalEmail' => '', 'username' => ''];

        return [
            'personalEmail' => trim((string)($row['emailAddress'] ?? '')),
            'username'      => $this->_deriveUsername(
                (string)($row['firstName'] ?? ''),
                (string)($row['lastName']  ?? ''),
                $studentNumber
            ),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Minimal RFC-4180 CSV parser using PHP's native fgetcsv() stream reader.
    // Returns an array of arrays (rows × columns).
    // ─────────────────────────────────────────────────────────────────────────
    private function _parseCsvText(string $text): array
    {
        $rows = [];
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        //while (($row = fgetcsv($stream)) !== false) {
	while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);
        return $rows;
    }

    // Normalizes a CSV header cell: strips [bracket] suffixes when
    // stripBrackets is true, then trims and lowercases.
    private function _normalizeCsvHeader(string $h, bool $stripBrackets): string
    {
        $h = trim($h);
        if ($stripBrackets) $h = preg_replace('/\s*\[.*?\]/', '', $h);
        return strtolower(trim($h));
    }

    // Builds the set of studentNumbers (as keys, value = true) enrolled in
    // the given academicYear/semester, matching _getActiveTerm_() +
    // tblRegistrations loop in importWorkspaceCsv()/importMoodleCsv(). An
    // empty academicYear or semester matches everything for that field,
    // mirroring the "!activeTerm.academicYear || match" matching logic.
    private function _buildEnrolledSet($db, string $academicYear, string $semester): array
    {
        $sql = "SELECT DISTINCT studentNumber FROM tblRegistrations WHERE 1=1";
        $params = [];
        if ($academicYear !== '') { $sql .= " AND academicYear = ?"; $params[] = $academicYear; }
        if ($semester     !== '') { $sql .= " AND semester = ?";     $params[] = $semester; }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $set = [];
        foreach ($stmt->fetchAll() as $r) {
            $sno = trim((string)($r['studentNumber'] ?? ''));
            if ($sno !== '') $set[strtolower($sno)] = true;
        }
        return $set;
    }

    // Normalizes a recovery phone cell the same way importWorkspaceCsv()
    // does client-side today: 10+ digit unprefixed numbers get a leading
    // '+'. (PHP reads CSV cells as plain strings, so the scientific-
    // notation float-coercion edge case the GAS/Sheets version guards
    // against does not apply here.)
    private function _normalizeRecoveryPhone(string $v): string
    {
        $v = trim($v);
        if ($v !== '' && preg_match('/^\d{10,}$/', $v)) $v = '+' . $v;
        return $v;
    }
    
    // ─────────────────────────────────────────────────────────────────────────
    // Converts a Moodle relative "Last access" string ("now", "11 hours
    // 6 mins", "44 days 22 hours", "110 days") into an absolute MySQL
    // datetime by subtracting the parsed duration from $importDate.
    // Returns '' for empty or unparseable input.
    // ─────────────────────────────────────────────────────────────────────────
    private function _parseMoodleLastAccess(string $raw, \DateTime $importDate): string
    {
        $s = strtolower(trim($raw));
        if ($s === '') return '';
        if ($s === 'now') return $importDate->format('Y-m-d H:i:s');

        $totalMins = 0;
        if (preg_match('/(\d+)\s*day/',  $s, $m)) $totalMins += ((int)$m[1]) * 1440;
        if (preg_match('/(\d+)\s*hour/', $s, $m)) $totalMins += ((int)$m[1]) * 60;
        if (preg_match('/(\d+)\s*min/',  $s, $m)) $totalMins += (int)$m[1];

        if ($totalMins === 0) return '';

        $dt = clone $importDate;
        $dt->modify('-' . $totalMins . ' minutes');
        return $dt->format('Y-m-d H:i:s');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/lms/accounts/import/moodle
    // Body: { csvText, academicYear, semester, officerEmail }
    //
    // Parses a Moodle user CSV, gates rows against the active-term enrollment
    // set, upserts matching LMS account rows, and marks enrolled-but-absent
    // students as 'Not found in Moodle'.
    // ─────────────────────────────────────────────────────────────────────────
    public function importMoodleCsv()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $csvText      = (string)($input['csvText']      ?? '');
        $academicYear = trim((string)($input['academicYear'] ?? ''));
        $semester     = trim((string)($input['semester']     ?? ''));
        $officerEmail = trim((string)($input['officerEmail'] ?? ''));

        if (trim($csvText) === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'updated' => 0, 'inserted' => 0, 'skipped' => 0, 'notEnrolled' => 0, 'markedAbsent' => 0, 'errors' => ['CSV content is empty.'], 'syncDate' => '']);
            return;
        }

        $rows = $this->_parseCsvText($csvText);
        if (count($rows) < 2) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'updated' => 0, 'inserted' => 0, 'skipped' => 0, 'notEnrolled' => 0, 'markedAbsent' => 0, 'errors' => ['CSV has no data rows.'], 'syncDate' => '']);
            return;
        }

        $headers = array_map(function ($h) { return $this->_normalizeCsvHeader((string)$h, false); }, $rows[0]);
        $iEmail      = array_search('email address', $headers, true);
        $iLastAccess = array_search('last access',   $headers, true);

        if ($iEmail === false) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'updated' => 0, 'inserted' => 0, 'skipped' => 0, 'notEnrolled' => 0, 'markedAbsent' => 0,
                'errors' => ['Required column "Email address" not found in CSV.'], 'syncDate' => '']);
            return;
        }

        $db = Database::getConnection();
        $enrolledSet = $this->_buildEnrolledSet($db, $academicYear, $semester);
        $now = new \DateTime('now');
        $nowStr = $now->format('Y-m-d H:i:s');

        $skipped = 0;
        $studentCsvMap = [];
        for ($r = 1; $r < count($rows); $r++) {
            $row   = $rows[$r];
            $email = trim((string)($row[$iEmail] ?? ''));
            if (!preg_match('/\.(\d{8})@/', $email, $m)) { $skipped++; continue; }
            $sno = $m[1];
            $rawAccess = $iLastAccess !== false ? trim((string)($row[$iLastAccess] ?? '')) : '';
            $studentCsvMap[$sno] = [
                'moodleEmail'      => $email,
                'moodleLastAccess' => $this->_parseMoodleLastAccess($rawAccess, $now),
                'moodleStatus'     => 'Active',
            ];
        }

        if (!count($studentCsvMap)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'updated' => 0, 'inserted' => 0, 'skipped' => $skipped, 'notEnrolled' => 0, 'markedAbsent' => 0,
                'errors' => ['No student accounts found in CSV (expected <name>.<8-digit-number>@<domain> pattern).'], 'syncDate' => '']);
            return;
        }

        $existingStmt = $db->query("SELECT studentNumber FROM tblLmsAccounts");
        $existingSet = [];
        foreach ($existingStmt->fetchAll() as $r2) { $existingSet[trim((string)$r2['studentNumber'])] = true; }

        $notEnrolled = 0;
        $upserts = [];

        foreach ($studentCsvMap as $sno => $csvData) {
            $sno = (string) $sno; // PHP coerces numeric-string array keys to int — restore string type
            if (!isset($enrolledSet[strtolower($sno)])) { $notEnrolled++; continue; }

            $base = [
                'studentNumber'    => $sno,
                'moodleLastAccess' => $csvData['moodleLastAccess'],
                'moodleStatus'     => $csvData['moodleStatus'],
                'moodleSyncDate'   => $nowStr,
                'moodleEmail'      => $csvData['moodleEmail'],
            ];

            if (isset($existingSet[$sno])) {
                $base['path'] = 'update';
            } else {
                $lookup = $this->_lookupPersonalEmailAndUsername($db, $sno);
                $base['path']          = 'insert';
                $base['status']        = 'CREATED';
                $base['createdBy']     = $officerEmail . ' (moodle-csv-import)';
                $base['createdDate']   = $nowStr;
                $base['notes']         = 'Auto-inserted by Moodle CSV import';
                $base['username']      = $lookup['username'] ?: strtok($csvData['moodleEmail'], '@');
            }
            $upserts[] = $base;
        }

        // Second pass — enrolled students with an existing row but absent
        // from this CSV get moodleStatus = 'Not found in Moodle'.
        $absentUpdates = [];
        foreach (array_keys($existingSet) as $sno) {
            $sno = (string) $sno; // same array-key coercion gotcha as the loop above
            if (!isset($enrolledSet[strtolower($sno)])) continue;
            if (isset($studentCsvMap[$sno])) continue;
            $absentUpdates[] = [
                'studentNumber'  => $sno,
                'moodleStatus'   => 'Not found in Moodle',
                'moodleSyncDate' => $nowStr,
            ];
        }

        // Reuses the _moodleSync() helper for the actual upserts.
        $counts = $this->_moodleSync($db, $upserts, $absentUpdates);

        echo json_encode([
            'ok'           => true,
            'updated'      => $counts['updated'],
            'inserted'     => $counts['inserted'],
            'skipped'      => $skipped,
            'notEnrolled'  => $notEnrolled,
            'markedAbsent' => $counts['markedAbsent'],
            'errors'       => [],
            'syncDate'     => $nowStr,
        ]);
    }
 
    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/lms/accounts/import/workspace
    // Body: { csvText, academicYear, semester, officerEmail }
    //
    // Parses a Google Workspace Admin CSV export, gates rows against the
    // active-term enrollment set, and upserts LMS account rows.
    // ─────────────────────────────────────────────────────────────────────────
    public function importWorkspaceCsv()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $csvText      = (string)($input['csvText']      ?? '');
        $academicYear = trim((string)($input['academicYear'] ?? ''));
        $semester     = trim((string)($input['semester']     ?? ''));
        $officerEmail = trim((string)($input['officerEmail'] ?? ''));

        if (trim($csvText) === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'updated' => 0, 'inserted' => 0, 'skipped' => 0, 'notEnrolled' => 0, 'errors' => ['CSV content is empty.'], 'syncDate' => '']);
            return;
        }

        $rows = $this->_parseCsvText($csvText);
        if (count($rows) < 2) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'updated' => 0, 'inserted' => 0, 'skipped' => 0, 'notEnrolled' => 0, 'errors' => ['CSV has no data rows.'], 'syncDate' => '']);
            return;
        }

        $headers = array_map(function ($h) { return $this->_normalizeCsvHeader((string)$h, true); }, $rows[0]);
        $iEmail         = array_search('email address',  $headers, true);
        $iStatus        = array_search('status',         $headers, true);
        $iSignIn        = array_search('last sign in',   $headers, true);
        $iUsage         = array_search('email usage',    $headers, true);
        $iDriveUsage    = array_search('drive usage',    $headers, true);
        $iRecoveryEmail = array_search('recovery email', $headers, true);
        $iRecoveryPhone = array_search('recovery phone', $headers, true);

        if ($iEmail === false || $iStatus === false) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'updated' => 0, 'inserted' => 0, 'skipped' => 0, 'notEnrolled' => 0,
                'errors' => ['Required columns (Email Address, Status) not found in CSV.'], 'syncDate' => '']);
            return;
        }

        $db = Database::getConnection();
        $enrolledSet = $this->_buildEnrolledSet($db, $academicYear, $semester);

        $skipped = 0;
        $studentCsvMap = []; // sno => row data
        for ($r = 1; $r < count($rows); $r++) {
            $row   = $rows[$r];
            $email = trim((string)($row[$iEmail] ?? ''));
            if (!preg_match('/\.(\d{8})@/', $email, $m)) { $skipped++; continue; }
            $sno = $m[1];
            $studentCsvMap[$sno] = [
                'workspaceStatus' => trim((string)($row[$iStatus] ?? '')),
                'lastSignIn'      => $iSignIn        !== false ? trim((string)($row[$iSignIn]        ?? '')) : '',
                'emailUsage'      => $iUsage         !== false ? trim((string)($row[$iUsage]         ?? '')) : '',
                'driveUsage'      => $iDriveUsage    !== false ? trim((string)($row[$iDriveUsage]    ?? '')) : '',
                'recoveryEmail'   => $iRecoveryEmail !== false ? trim((string)($row[$iRecoveryEmail] ?? '')) : '',
                'recoveryPhone'   => $iRecoveryPhone !== false ? $this->_normalizeRecoveryPhone((string)($row[$iRecoveryPhone] ?? '')) : '',
                'workspaceEmail'  => $email,
            ];
        }

        if (!count($studentCsvMap)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'updated' => 0, 'inserted' => 0, 'skipped' => $skipped, 'notEnrolled' => 0,
                'errors' => ['No student accounts found in CSV (expected <name>.<8-digit-number>@<domain> pattern).'], 'syncDate' => '']);
            return;
        }

        // Which SNOs already have a tblLmsAccounts row? (path=update vs insert)
        $existingStmt = $db->query("SELECT studentNumber FROM tblLmsAccounts");
        $existingSet = [];
        foreach ($existingStmt->fetchAll() as $r2) { $existingSet[trim((string)$r2['studentNumber'])] = true; }

        $now      = date('Y-m-d H:i:s');
        $notEnrolled = 0;
        $upserts  = [];

        foreach ($studentCsvMap as $sno => $csvData) {
            $sno = (string) $sno; // PHP coerces numeric-string array keys to int — restore string type
            if (!isset($enrolledSet[strtolower($sno)])) { $notEnrolled++; continue; }

            $base = [
                'studentNumber'     => $sno,
                'workspaceStatus'   => $csvData['workspaceStatus'],
                'lastSignIn'        => $csvData['lastSignIn'],
                'emailUsage'        => $csvData['emailUsage'],
                'driveUsage'        => $csvData['driveUsage'],
                'recoveryEmail'     => $csvData['recoveryEmail'],
                'recoveryPhone'     => $csvData['recoveryPhone'],
                'workspaceSyncDate' => $now,
            ];

            if (isset($existingSet[$sno])) {
                $base['path'] = 'update';
            } else {
                $lookup = $this->_lookupPersonalEmailAndUsername($db, $sno);
                $base['path']           = 'insert';
                $base['status']         = 'CREATED';
                $base['createdBy']      = $officerEmail . ' (csv-import)';
                $base['createdDate']    = $now;
                $base['notes']          = 'Auto-inserted by CSV import';
                $base['personalEmail']  = $lookup['personalEmail'];
                $base['username']       = $lookup['username'] ?: strtok($csvData['workspaceEmail'], '@');
            }
            $upserts[] = $base;
        }

        // Reuses the already-implemented, already-correct _workspaceSync()
        // private helper — same INSERT/UPDATE branching mirror()'s
        // lms_workspace_sync branch already exercises. No new SQL here.
        $counts = $this->_workspaceSync($db, $upserts);

        echo json_encode([
            'ok'          => true,
            'updated'     => $counts['updated'],
            'inserted'    => $counts['inserted'],
            'skipped'     => $skipped,
            'notEnrolled' => $notEnrolled,
            'errors'      => [],
            'syncDate'    => $now,
        ]);
    }

    public function bootstrap()
    {
        $db = Database::getConnection();

        $accounts      = $db->query("SELECT * FROM tblLmsAccounts ORDER BY studentNumber")->fetchAll();
        $students      = $db->query("SELECT studentNumber, lastName, firstName, middleName, nameExtension, emailAddress, contactNumber FROM tblStudents")->fetchAll();
        $registrations = $db->query("SELECT RegistrationNumber, studentNumber, programID, sectionID, yearLevel, academicYear, semester FROM tblRegistrations")->fetchAll();
        $programs      = $db->query("SELECT * FROM tblPrograms")->fetchAll();
        $sections      = $db->query("SELECT * FROM tblSections")->fetchAll();

        echo json_encode([
            'accounts'      => $accounts,
            'students'      => $students,
            'registrations' => $registrations,
            'programs'      => $programs,
            'sections'      => $sections,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/lms/accounts
    //   ?academicYear=2026-2027        (required)
    //   &semester=1ST%20SEMESTER       (required)
    //
    // Returns all enrolled students for the given term joined with their
    // tblLmsAccounts row. Null lms.* columns indicate no account yet.
    // ─────────────────────────────────────────────────────────────────────────
    public function accounts()
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
                s.middleName,
                s.nameExtension,
                s.emailAddress,
                s.contactNumber,

                r.yearLevel,

                p.programCode,

                sec.sectionName,

                lms.personalEmail,
                lms.username,
                lms.status,
                lms.processedBy,
                lms.processedDate,
                lms.createdBy,
                lms.createdDate,
                lms.notes,
                lms.workspaceStatus,
                lms.lastSignIn,
                lms.emailUsage,
                lms.driveUsage,
                lms.recoveryEmail,
                lms.recoveryPhone,
                lms.workspaceSyncDate,
                lms.lmsLastAccess,
                lms.lmsSyncDate,
                lms.moodleLastAccess,
                lms.moodleStatus,
                lms.moodleSyncDate,
                lms.moodleEmail

            FROM tblRegistrations r
            JOIN tblStudents s    ON s.studentNumber = r.studentNumber
            JOIN tblPrograms p    ON p.programID     = r.programID
            JOIN tblSections sec  ON sec.sectionID   = r.sectionID
            LEFT JOIN tblLmsAccounts lms ON lms.studentNumber = r.studentNumber
            WHERE r.academicYear = :academicYear
              AND r.semester     = :semester
            ORDER BY s.lastName ASC, s.firstName ASC
        ");

        $stmt->execute([
            ':academicYear' => $ay,
            ':semester'     => $sem,
        ]);

        $rows = $stmt->fetchAll();

        echo json_encode([
            'ok'   => true,
            'rows' => $rows,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/lms/accounts/process
    // Body: { studentNumber, officerEmail }
    //
    // Marks an LMS account as ON_PROCESS.
    // Lock-ownership rules:
    //   - No row yet            -> insert with status ON_PROCESS
    //   - Row exists, PENDING   -> update to ON_PROCESS, claimed by officerEmail
    //   - Row exists, ON_PROCESS, owned by officerEmail (case-insensitive)
    //                           -> update (idempotent re-claim; harmless)
    //   - Row exists, ON_PROCESS, owned by someone else
    //                           -> 409, "On-Process by <handle>. Cannot take over."
    //   - Row exists, CREATED   -> 409, "Account already created."
    //
    // Reuses the already-implemented, already-correct _insertAccount() /
    // _updateProcess() private helpers below (originally written for the
    // mirror() dispatch path) for the actual SQL. Do not duplicate that SQL.
    //
    // Returns raw (unformatted) processedDate — lms.html already has a
    // working client-side _formatLmsTimestamp() to render it; no PHP
    // formatter is added here.
    // ─────────────────────────────────────────────────────────────────────────
    public function processAccount()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentNumber = trim((string)($input['studentNumber'] ?? ''));
        $officerEmail  = trim((string)($input['officerEmail']  ?? ''));

        if ($studentNumber === '') { http_response_code(400); echo json_encode(['ok' => false, 'message' => 'studentNumber is required.']); return; }
        if ($officerEmail  === '') { http_response_code(400); echo json_encode(['ok' => false, 'message' => 'officerEmail is required.']);  return; }

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT studentNumber, status, processedBy FROM tblLmsAccounts WHERE studentNumber = ?");
        $stmt->execute([$studentNumber]);
        $existing = $stmt->fetch();

        $now = date('Y-m-d H:i:s');

        if ($existing) {
            $currentStatus = strtoupper(trim((string)($existing['status']      ?? '')));
            $currentOwner  = trim((string)($existing['processedBy'] ?? ''));

            if ($currentStatus === 'CREATED') {
                http_response_code(409);
                echo json_encode(['ok' => false, 'message' => 'Account already created.']);
                return;
            }
            if ($currentStatus === 'ON_PROCESS' && strcasecmp($currentOwner, $officerEmail) !== 0) {
                $lockerHandle = strtok($currentOwner, '@') ?: $currentOwner;
                http_response_code(409);
                echo json_encode(['ok' => false, 'message' => 'On-Process by ' . $lockerHandle . '. Cannot take over.']);
                return;
            }

            $this->_updateProcess($db, [
                'studentNumber' => $studentNumber,
                'status'        => 'ON_PROCESS',
                'processedBy'   => $officerEmail,
                'processedDate' => $now,
            ]);
        } else {
            $lookup = $this->_lookupPersonalEmailAndUsername($db, $studentNumber);
            $this->_insertAccount($db, [
                'studentNumber' => $studentNumber,
                'personalEmail' => $lookup['personalEmail'],
                'username'      => $lookup['username'],
                'status'        => 'ON_PROCESS',
                'processedBy'   => $officerEmail,
                'processedDate' => $now,
                'createdBy'     => null,
                'createdDate'   => null,
                'notes'         => null,
            ]);
        }

        echo json_encode([
            'ok'            => true,
            'status'        => 'ON_PROCESS',
            'processedBy'   => $officerEmail,
            'processedDate' => $now,
        ]);
    }
    
   // ─────────────────────────────────────────────────────────────────────────
    // POST /api/lms/accounts/release
    // Body: { studentNumber, officerEmail }
    //
    // Marks an LMS account as CREATED.
    // _release() itself has no validation (it never needed any — GAS
    // validated before calling this endpoint; this endpoint adds the
    // same ownership checks as the prior implementation, then calls the existing helper.
    // ─────────────────────────────────────────────────────────────────────────
    public function releaseAccount()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentNumber = trim((string)($input['studentNumber'] ?? ''));
        $officerEmail  = trim((string)($input['officerEmail']  ?? ''));

        if ($studentNumber === '') { http_response_code(400); echo json_encode(['ok' => false, 'message' => 'studentNumber is required.']); return; }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT status, processedBy FROM tblLmsAccounts WHERE studentNumber = ?");
        $stmt->execute([$studentNumber]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Nothing to release — no account record found.']);
            return;
        }

        $currentStatus = strtoupper(trim((string)($row['status']      ?? '')));
        $currentOwner  = trim((string)($row['processedBy'] ?? ''));

        if ($currentStatus === 'PENDING' || $currentStatus === 'CREATED') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'Nothing to release — status is already ' . $currentStatus . '.']);
            return;
        }
        if ($currentStatus === 'ON_PROCESS' && strcasecmp($currentOwner, $officerEmail) !== 0) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'Not your lock — you can only release records you are processing.']);
            return;
        }

        $this->_release($db, $studentNumber);

        echo json_encode(['ok' => true]);
    }
    
    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/lms/accounts/complete
    // Body: { studentNumber, officerEmail }
    //
    // Records final Moodle and workspace credentials for a completed LMS account.
    // Reuses the existing private _complete() helper (same one mirror()'s
    // lms_complete branch already calls) — no duplicated SQL.
    // ─────────────────────────────────────────────────────────────────────────
    public function completeAccount()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentNumber = trim((string)($input['studentNumber'] ?? ''));
        $officerEmail  = trim((string)($input['officerEmail']  ?? ''));

        if ($studentNumber === '') { http_response_code(400); echo json_encode(['ok' => false, 'message' => 'studentNumber is required.']); return; }
        if ($officerEmail  === '') { http_response_code(400); echo json_encode(['ok' => false, 'message' => 'officerEmail is required.']);  return; }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT status, processedBy FROM tblLmsAccounts WHERE studentNumber = ?");
        $stmt->execute([$studentNumber]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Account record not found. Process the account first.']);
            return;
        }

        $currentStatus = strtoupper(trim((string)($row['status']      ?? '')));
        $currentOwner  = trim((string)($row['processedBy'] ?? ''));

        if ($currentStatus === 'CREATED') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'Account is already marked as Created.']);
            return;
        }
        if ($currentStatus === 'PENDING') {
            http_response_code(409);
            echo json_encode(['ok' => false, 'message' => 'Account must be On-Process before it can be marked Created.']);
            return;
        }
        if ($currentStatus === 'ON_PROCESS' && strcasecmp($currentOwner, $officerEmail) !== 0) {
            $lockerHandle = strtok($currentOwner, '@') ?: $currentOwner;
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'This account is being processed by ' . $lockerHandle . '. You cannot complete it.']);
            return;
        }

        $now = date('Y-m-d H:i:s');
        $this->_complete($db, $studentNumber, $officerEmail, $now);

        echo json_encode(['ok' => true, 'createdBy' => $officerEmail, 'createdDate' => $now]);
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

                case 'lms_process':
                    if (empty($input['account'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing required field: account"]);
                        return;
                    }
                    $path = $input['account']['path'] ?? ($input['path'] ?? 'update');
                    if ($path === 'insert') {
                        $this->_insertAccount($db, $input['account']);
                    } else {
                        $this->_updateProcess($db, $input['account']);
                    }
                    echo json_encode([
                        "status"        => "success",
                        "operation"     => $operation,
                        "path"          => $path,
                        "studentNumber" => $input['account']['studentNumber'] ?? null,
                    ]);
                    break;

                case 'lms_release':
                    if (empty($input['studentNumber'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing required field: studentNumber"]);
                        return;
                    }
                    $this->_release($db, $input['studentNumber']);
                    echo json_encode([
                        "status"        => "success",
                        "operation"     => $operation,
                        "studentNumber" => $input['studentNumber'],
                    ]);
                    break;

                case 'lms_complete':
                    if (empty($input['studentNumber'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing required field: studentNumber"]);
                        return;
                    }
                    $this->_complete($db, $input['studentNumber'], $input['createdBy'] ?? null, $input['createdDate'] ?? null);
                    echo json_encode([
                        "status"        => "success",
                        "operation"     => $operation,
                        "studentNumber" => $input['studentNumber'],
                    ]);
                    break;

                case 'lms_workspace_sync':
                    if (empty($input['upserts']) || !is_array($input['upserts'])) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing or invalid field: upserts"]);
                        return;
                    }
                    $counts = $this->_workspaceSync($db, $input['upserts']);
                    echo json_encode([
                        "status"    => "success",
                        "operation" => $operation,
                        "inserted"  => $counts['inserted'],
                        "updated"   => $counts['updated'],
                    ]);
                    break;

                case 'lms_moodle_sync':
                    $upserts      = $input['upserts']       ?? [];
                    $absentUpdates= $input['absentUpdates'] ?? [];
                    if (empty($upserts) && empty($absentUpdates)) {
                        http_response_code(400);
                        echo json_encode(["error" => "Missing fields: upserts and/or absentUpdates"]);
                        return;
                    }
                    $counts = $this->_moodleSync($db, $upserts, $absentUpdates);
                    echo json_encode([
                        "status"       => "success",
                        "operation"    => $operation,
                        "inserted"     => $counts['inserted'],
                        "updated"      => $counts['updated'],
                        "markedAbsent" => $counts['markedAbsent'],
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

    // ── Private helpers ───────────────────────────────────────────────

    private function _insertAccount($db, array $a)
    {
        $stmt = $db->prepare("
            INSERT INTO tblLmsAccounts (
                studentNumber, personalEmail, username,
                status, processedBy, processedDate,
                createdBy, createdDate, notes,
                workspaceStatus, lastSignIn, emailUsage, driveUsage,
                recoveryEmail, recoveryPhone, workspaceSyncDate,
                lmsLastAccess, lmsSyncDate,
                moodleLastAccess, moodleStatus, moodleSyncDate, moodleEmail
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
            ON DUPLICATE KEY UPDATE
                status        = VALUES(status),
                processedBy   = VALUES(processedBy),
                processedDate = VALUES(processedDate)
        ");

        $stmt->execute([
            $a['studentNumber']    ?? null,
            $a['personalEmail']    ?? null,
            $a['username']         ?? null,
            $a['status']           ?? 'ON_PROCESS',
            $a['processedBy']      ?? null,
            $a['processedDate']    ?? null,
            $a['createdBy']        ?? null,
            $a['createdDate']      ?? null,
            $a['notes']            ?? null,
            $a['workspaceStatus']  ?? null,
            $a['lastSignIn']       ?? null,
            $a['emailUsage']       ?? null,
            $a['driveUsage']       ?? null,
            $a['recoveryEmail']    ?? null,
            $a['recoveryPhone']    ?? null,
            $a['workspaceSyncDate']?? null,
            $a['lmsLastAccess']    ?? null,
            $a['lmsSyncDate']      ?? null,
            $a['moodleLastAccess'] ?? null,
            $a['moodleStatus']     ?? null,
            $a['moodleSyncDate']   ?? null,
            $a['moodleEmail']      ?? null,
        ]);
    }

    private function _updateProcess($db, array $a)
    {
        if (empty($a['studentNumber'])) return;

        $stmt = $db->prepare("
            UPDATE tblLmsAccounts SET
                status        = ?,
                processedBy   = ?,
                processedDate = ?
            WHERE studentNumber = ?
        ");

        $stmt->execute([
            $a['status']        ?? 'ON_PROCESS',
            $a['processedBy']   ?? null,
            $a['processedDate'] ?? null,
            $a['studentNumber'],
        ]);
    }

    private function _release($db, string $studentNumber)
    {
        $stmt = $db->prepare("
            UPDATE tblLmsAccounts SET
                status        = 'PENDING',
                processedBy   = NULL,
                processedDate = NULL
            WHERE studentNumber = ?
        ");
        $stmt->execute([$studentNumber]);
    }

    private function _complete($db, string $studentNumber, ?string $createdBy, ?string $createdDate)
    {
        $stmt = $db->prepare("
            UPDATE tblLmsAccounts SET
                status      = 'CREATED',
                createdBy   = ?,
                createdDate = ?
            WHERE studentNumber = ?
        ");
        $stmt->execute([$createdBy, $createdDate, $studentNumber]);
    }

    private function _workspaceSync($db, array $upserts): array
    {
        $counts = ['inserted' => 0, 'updated' => 0];

        foreach ($upserts as $u) {
            if (empty($u['studentNumber'])) continue;
            $path = $u['path'] ?? 'update';

            if ($path === 'insert') {
                $stmt = $db->prepare("
                    INSERT INTO tblLmsAccounts (
                        studentNumber, personalEmail, username,
                        status, createdBy, createdDate, notes,
                        workspaceStatus, lastSignIn, emailUsage, driveUsage,
                        recoveryEmail, recoveryPhone, workspaceSyncDate
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        workspaceStatus   = VALUES(workspaceStatus),
                        lastSignIn        = VALUES(lastSignIn),
                        emailUsage        = VALUES(emailUsage),
                        driveUsage        = VALUES(driveUsage),
                        recoveryEmail     = VALUES(recoveryEmail),
                        recoveryPhone     = VALUES(recoveryPhone),
                        workspaceSyncDate = VALUES(workspaceSyncDate)
                ");
                $stmt->execute([
                    $u['studentNumber']    ?? null,
                    $u['personalEmail']    ?? null,
                    $u['username']         ?? null,
                    $u['status']           ?? 'CREATED',
                    $u['createdBy']        ?? null,
                    $u['createdDate']      ?? null,
                    $u['notes']            ?? null,
                    $u['workspaceStatus']  ?? null,
                    $u['lastSignIn']       ?? null,
                    $u['emailUsage']       ?? null,
                    $u['driveUsage']       ?? null,
                    $u['recoveryEmail']    ?? null,
                    $u['recoveryPhone']    ?? null,
                    $u['workspaceSyncDate']?? null,
                ]);
                $counts['inserted']++;
            } else {
                $stmt = $db->prepare("
                    UPDATE tblLmsAccounts SET
                        workspaceStatus   = ?,
                        lastSignIn        = ?,
                        emailUsage        = ?,
                        driveUsage        = ?,
                        recoveryEmail     = ?,
                        recoveryPhone     = ?,
                        workspaceSyncDate = ?
                    WHERE studentNumber = ?
                ");
                $stmt->execute([
                    $u['workspaceStatus']   ?? null,
                    $u['lastSignIn']        ?? null,
                    $u['emailUsage']        ?? null,
                    $u['driveUsage']        ?? null,
                    $u['recoveryEmail']     ?? null,
                    $u['recoveryPhone']     ?? null,
                    $u['workspaceSyncDate'] ?? null,
                    $u['studentNumber'],
                ]);
                $counts['updated']++;
            }
        }

        return $counts;
    }

    private function _moodleSync($db, array $upserts, array $absentUpdates): array
    {
        $counts = ['inserted' => 0, 'updated' => 0, 'markedAbsent' => 0];

        foreach ($upserts as $u) {
            if (empty($u['studentNumber'])) continue;
            $path = $u['path'] ?? 'update';

            if ($path === 'insert') {
                $stmt = $db->prepare("
                    INSERT INTO tblLmsAccounts (
                        studentNumber, username, status,
                        createdBy, createdDate, notes,
                        moodleLastAccess, moodleStatus, moodleSyncDate, moodleEmail
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        moodleLastAccess = VALUES(moodleLastAccess),
                        moodleStatus     = VALUES(moodleStatus),
                        moodleSyncDate   = VALUES(moodleSyncDate),
                        moodleEmail      = VALUES(moodleEmail)
                ");
                $stmt->execute([
                    $u['studentNumber']    ?? null,
                    $u['username']         ?? null,
                    $u['status']           ?? 'CREATED',
                    $u['createdBy']        ?? null,
                    $u['createdDate']      ?? null,
                    $u['notes']            ?? null,
                    $u['moodleLastAccess'] ?? null,
                    $u['moodleStatus']     ?? null,
                    $u['moodleSyncDate']   ?? null,
                    $u['moodleEmail']      ?? null,
                ]);
                $counts['inserted']++;
            } else {
                $stmt = $db->prepare("
                    UPDATE tblLmsAccounts SET
                        moodleLastAccess = ?,
                        moodleStatus     = ?,
                        moodleSyncDate   = ?,
                        moodleEmail      = ?
                    WHERE studentNumber = ?
                ");
                $stmt->execute([
                    $u['moodleLastAccess'] ?? null,
                    $u['moodleStatus']     ?? null,
                    $u['moodleSyncDate']   ?? null,
                    $u['moodleEmail']      ?? null,
                    $u['studentNumber'],
                ]);
                $counts['updated']++;
            }
        }

        foreach ($absentUpdates as $a) {
            if (empty($a['studentNumber'])) continue;
            $stmt = $db->prepare("
                UPDATE tblLmsAccounts SET
                    moodleStatus   = ?,
                    moodleSyncDate = ?
                WHERE studentNumber = ?
            ");
            $stmt->execute([
                $a['moodleStatus']   ?? 'Not found in Moodle',
                $a['moodleSyncDate'] ?? null,
                $a['studentNumber'],
            ]);
            $counts['markedAbsent']++;
        }

        return $counts;
    }
}
