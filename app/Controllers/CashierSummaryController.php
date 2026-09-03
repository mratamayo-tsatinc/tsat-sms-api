<?php

namespace App\Controllers;

use App\Core\Database;

// ─────────────────────────────────────────────────────────────────────────
// CashierSummaryController
//
// Handles the Cashier Summary Report: a read-only reporting concern
// layered on tblPayments, independent of the POS write path in
// CashierController.
//
// IMPORTANT: there is no server session in this API. Every request must
// pass the caller's email explicitly (?email=...). The controller
// re-derives isAdmin from ADMIN_EMAILS on every request — it NEVER
// trusts a client-supplied isAdmin flag or cashierFilter value for a
// non-admin caller.
//
// Student display names use _buildStudentDisplayName() — NOT
// ReferenceDataService::buildStudentFullName(). This report uses the
// format "Last Ext, First M." (middle initial only, not full middle name),
// e.g. "Dela Cruz, Juan S.", which differs from the shared full-name
// builder used by other endpoints. Do NOT change this to
// buildStudentFullName() — that would alter the report format.
//
// PERFORMANCE: date bounds and cashier filter are pushed into the SQL
// WHERE clause. Student and cashier display names are batch-resolved via
// WHERE ... IN (...) instead of one query per row. Requires these indexes:
//   ALTER TABLE tblPayments ADD INDEX idx_payments_datecreated (dateCreated);
//   ALTER TABLE tblPayments ADD INDEX idx_payments_paymentyear_month_day
//       (PaymentYear, PaymentMonthNumber, PaymentDay);
//   ALTER TABLE tblPayments ADD INDEX idx_payments_createdby (createdBy);
// ─────────────────────────────────────────────────────────────────────────
class CashierSummaryController
{
    // Admin email list for the Cashier Summary module.
    // Keep every entry lowercase — comparison lowercases both sides.
    private const ADMIN_EMAILS = ['administrator@tsatinc.edu.ph'];

    private function _isAdmin(string $email): bool
    {
        return in_array(strtolower(trim($email)), self::ADMIN_EMAILS, true);
    }

    // Single-lookup display-name resolver for the current caller's email.
    // Degrades gracefully to the raw email on any failure.
    private function _resolveUserDisplayName($db, string $rawEmail): string
    {
        if ($rawEmail === '') return $rawEmail;
        try {
            $stmt = $db->prepare("SELECT fullName FROM tblUsers WHERE email = ? LIMIT 1");
            $stmt->execute([$rawEmail]);
            $row = $stmt->fetch();
            $fullName = trim((string)($row['fullName'] ?? ''));
            return $fullName !== '' ? $fullName : $rawEmail;
        } catch (\Exception $e) {
            return $rawEmail;
        }
    }

    // Builds a student display name for the summary report.
    // Format: "[Last name] [Ext], [First name] [Middle initial]."
    // e.g. "Dela Cruz, Juan S." or "Santos Jr., Mark R."
    // NOTE: This uses middle initial only (not full middle name), which
    // differs from ReferenceDataService::buildStudentFullName(). Do NOT
    // change this to buildStudentFullName() — that would alter the report format.
    private function _buildStudentDisplayName(array $student): string
    {
        $lastName      = trim((string)($student['lastName'] ?? ''));
        $nameExtension = trim((string)($student['nameExtension'] ?? ''));
        $firstName     = trim((string)($student['firstName'] ?? ''));
        $middleName    = trim((string)($student['middleName'] ?? ''));
        $studentNumber = trim((string)($student['studentNumber'] ?? ''));

        $firstInitial = function (string $nameStr): string {
            if ($nameStr === '') return '';
            $firstWord = preg_split('/\s+/', $nameStr)[0] ?? '';
            return $firstWord !== '' ? (strtoupper(mb_substr($firstWord, 0, 1)) . '.') : '';
        };

        $lastPart  = trim(implode(' ', array_filter([$lastName, $nameExtension], fn($p) => $p !== '')));
        $givenPart = trim(implode(' ', array_filter([$firstName, $firstInitial($middleName)], fn($p) => $p !== '')));

        if ($lastPart !== '' && $givenPart !== '') {
            $namePart = $lastPart . ', ' . $givenPart;
        } elseif ($lastPart !== '') {
            $namePart = $lastPart;
        } elseif ($givenPart !== '') {
            $namePart = $givenPart;
        } else {
            $namePart = '';
        }

        // Only reached when NO name fields exist at all — student number
        // is the sole remaining identifier in that edge case.
        if ($namePart === '') return $studentNumber !== '' ? $studentNumber : '(Unknown Student)';
        return $namePart;
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/cashier/summary/bootstrap?email=<caller email>
    //
    // Returns isAdmin status, today's date, and (for admins) the cashier list.\n    // Non-admins get an empty
    // cashier list + restrictedToSelf:true; the client is expected to
    // hide the cashier selector in that case.
    // ─────────────────────────────────────────────────────────────────────
    public function bootstrap()
    {
        $email = trim($_GET['email'] ?? '');
        if ($email === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'email is required.']);
            return;
        }

        $db = Database::getConnection();
        $isAdmin = $this->_isAdmin($email);
        $today = date('Y-m-d');

        if (!$isAdmin) {
            echo json_encode([
                'ok' => true, 'today' => $today, 'isAdmin' => false,
                'cashiers' => [], 'restrictedToSelf' => true,
                'selfId' => $email, 'selfName' => $this->_resolveUserDisplayName($db, $email),
                'message' => 'Summary access ready (scoped to self).',
            ]);
            return;
        }

        $rows = $db->query("SELECT DISTINCT createdBy FROM tblPayments WHERE createdBy IS NOT NULL AND createdBy <> ''")->fetchAll();
        $cashiers = [];
        foreach ($rows as $row) {
            $raw = (string)$row['createdBy'];
            $cashiers[] = ['id' => $raw, 'displayName' => $this->_resolveUserDisplayName($db, $raw)];
        }
        usort($cashiers, fn($a, $b) => strcmp($a['displayName'], $b['displayName']));
        array_unshift($cashiers, ['id' => '__ALL__', 'displayName' => 'All Cashiers']);

        $realCashierCount = count($cashiers) - 1; // exclude the synthetic __ALL__ entry
        echo json_encode([
            'ok' => true, 'today' => $today, 'isAdmin' => true,
            'cashiers' => $cashiers, 'restrictedToSelf' => false,
            // Distinguishes "found some" from "found none yet".
            'message' => $realCashierCount > 0
                ? ($realCashierCount . ' cashier(s) found.')
                : 'No cashier payment records found yet.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/cashier/outstanding-balance/bootstrap
    //
    // Returns the active term plus filter reference data (programs,
    // sections, year levels) for the Outstanding Balance tab.
    //
    // IMPORTANT — filter options must reflect live enrollment, not the
    // full reference tables. ReferenceDataService::getAllPrograms() /
    // getAllSections() return EVERY program/section in the system,
    // including ones with zero students registered in the active term.
    // Using those here would let a cashier pick a program/section/year
    // level that guarantees an empty result. Instead this derives filter
    // options directly from tblRegistrations scoped to the active term
    // (DISTINCT on the actual enrolled rows), so every option offered is
    // guaranteed to return at least one registration.
    //
    // No email param — unlike bootstrap()/report() above, this endpoint
    // has no admin/self-scoping concept; the Outstanding Balance report
    // is available to any cashier, same as balances()/createPayment().
    // ─────────────────────────────────────────────────────────────────────
    public function outstandingBalanceBootstrap()
    {
        $db = Database::getConnection();
        $referenceData = new \App\Services\ReferenceDataService();

        try {
            $term = $referenceData->getActiveTerm();
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            return;
        }

        echo json_encode([
            'ok'           => true,
            'academicYear' => $term['academicYear'],
            'semester'     => $term['semester'],
            'programs'     => $this->_getEnrolledPrograms($db, $term['academicYear'], $term['semester']),
            // Home Class filter — full (program, section, yearLevel)
            // triples, not section names alone. See _getEnrolledClasses()
            // for why: this schema no longer ties a section to one fixed
            // program/year level.
            'classes'      => $this->_getEnrolledClasses($db, $term['academicYear'], $term['semester']),
            'yearLevels'   => $this->_getEnrolledYearLevels($db, $term['academicYear'], $term['semester']),
            // Powers the Per Fee mode's fee filter — see _getEnrolledFees()
            // below. Not relevant to Per Account mode, but returned
            // unconditionally (same as programs/sections/yearLevels) so the
            // frontend can populate it lazily without a second round trip
            // when the cashier switches modes.
            'fees'         => $this->_getEnrolledFees($db, $term['academicYear'], $term['semester']),
        ]);
    }

    // Programs that actually have at least one registration in the given
    // term. DISTINCT r.programID (not p.programID) so a program is only
    // offered as a filter option if it is genuinely represented in
    // tblRegistrations — guarantees the Outstanding Balance report can
    // never be filtered down to a program with zero enrolled students.
    private function _getEnrolledPrograms($db, string $academicYear, string $semester): array
    {
        $stmt = $db->prepare("
            SELECT DISTINCT r.programID, p.programCode, p.programDescription
            FROM tblRegistrations r
            JOIN tblPrograms p ON p.programID = r.programID
            WHERE r.academicYear = ? AND r.semester = ? AND r.programID IS NOT NULL AND r.programID <> ''
        ");
        $stmt->execute([$academicYear, $semester]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $programID = (string)($row['programID'] ?? '');
            if ($programID === '') continue;
            $code  = (string)($row['programCode'] ?? '');
            $desc  = (string)($row['programDescription'] ?? '');
            $label = implode(' - ', array_filter([$code, $desc], fn($p) => $p !== ''));
            $out[] = [
                'programID' => $programID,
                'code' => $code !== '' ? $code : $programID,
                'description' => $desc !== '' ? $desc : $programID,
                'label'     => $label !== '' ? $label : $programID,
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }

    // Distinct (programID, sectionID, yearLevel) triples that actually
    // occur in the given term's registrations — the Home Class filter.
    //
    // IMPORTANT: unlike the legacy schema, tblSections carries no durable
    // programID/yearLevel of its own — school policy now allows the same
    // section name to be reused across different programs/year levels, so
    // programID/sectionID/yearLevel are three independent columns on
    // tblRegistrations, not derivable from tblSections alone. The only
    // place a program+section+year-level combination actually exists
    // together is a tblRegistrations row itself, so this reads the raw
    // triple from there — same live-enrollment guarantee as
    // _getEnrolledPrograms() above, just three columns instead of one.
    //
    // classCode is built via the same _buildClassCode() helper the
    // on-screen/printed Class column uses (see outstandingBalanceReport()
    // below), so a dropdown option's text is guaranteed to read exactly
    // like the Class value a cashier sees in the results list.
    //
    // DISTINCT is on the raw (programID, sectionID, yearLevel) triple, not
    // on the rendered classCode label — two registrations that are the
    // same program/section/year but recorded with differently-spelled
    // yearLevel text (e.g. "3rd Year" vs "Year 3") would surface as two
    // options that render identically. Deliberately not deduped down to
    // the label: yearLevel is stored as distinct numeric values in this
    // deployment (1, 2, 3, 11, 12, ...), so that collision does not occur
    // in practice, and a raw-value distinct is the safer default in
    // general — silently merging two different underlying values because
    // they happen to print the same is a bigger risk than one duplicate-
    // looking row.
    private function _getEnrolledClasses($db, string $academicYear, string $semester): array
    {
        $stmt = $db->prepare("
            SELECT DISTINCT r.programID, r.sectionID, r.yearLevel,
                   p.programCode, p.programDescription, sec.sectionName
            FROM tblRegistrations r
            JOIN tblSections sec     ON sec.sectionID = r.sectionID
            LEFT JOIN tblPrograms p  ON p.programID   = r.programID
            WHERE r.academicYear = ? AND r.semester = ?
              AND r.sectionID IS NOT NULL AND r.sectionID <> ''
              AND r.programID IS NOT NULL AND r.programID <> ''
              AND r.yearLevel IS NOT NULL AND r.yearLevel <> ''
        ");
        $stmt->execute([$academicYear, $semester]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $sectionID = (string)($row['sectionID'] ?? '');
            $programID = (string)($row['programID'] ?? '');
            $yearLevel = (string)($row['yearLevel'] ?? '');
            if ($sectionID === '' || $programID === '' || $yearLevel === '') continue;

            $out[] = [
                'programID'  => $programID,
                'sectionID'  => $sectionID,
                'yearLevel'  => $yearLevel,
                'classCode'  => $this->_buildClassCode($row),
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['classCode'], $b['classCode']));
        return $out;
    }

    // Year levels actually represented in the given term's registrations.
    // tblRegistrations.yearLevel is free-text, so this is a straight
    // DISTINCT with no reference-table join.
    private function _getEnrolledYearLevels($db, string $academicYear, string $semester): array
    {
        $stmt = $db->prepare("
            SELECT DISTINCT r.yearLevel
            FROM tblRegistrations r
            WHERE r.academicYear = ? AND r.semester = ? AND r.yearLevel IS NOT NULL AND r.yearLevel <> ''
            ORDER BY r.yearLevel
        ");
        $stmt->execute([$academicYear, $semester]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $yl = (string)($row['yearLevel'] ?? '');
            if ($yl !== '') $out[] = $yl;
        }
        return $out;
    }

    // Fees that actually have at least one ACTIVE assessment line in the
    // given term — same live-enrollment guarantee as _getEnrolledPrograms()
    // / _getEnrolledSections() above, but scoped through tblAssessments
    // (not tblRegistrations directly), since "this fee is offered" here
    // specifically means "this fee has been assessed on at least one
    // registration this term", not just "this fee exists in tblFees".
    // Powers the Per Fee mode fee-filter dropdown — this is the piece the
    // Outstanding Balance report was missing: mode=fee's whole purpose is
    // per-fee reporting, but there was previously no way to narrow the
    // report down to a chosen subset of fees. COALESCE(a.isActive, 1) = 1
    // matches the same default used by _buildFeeModeRecords() /
    // _buildAccountModeRecords() below, so a fee only offered via disabled
    // assessment lines never shows up as a selectable filter.
    private function _getEnrolledFees($db, string $academicYear, string $semester): array
    {
        $stmt = $db->prepare("
            SELECT DISTINCT a.feeID, f.feeCode, f.feeNote
            FROM tblAssessments a
            JOIN tblRegistrations r ON r.RegistrationNumber = a.registrationNumber
            LEFT JOIN tblFees f     ON f.feeID = a.feeID
            WHERE r.academicYear = ? AND r.semester = ?
              AND COALESCE(a.isActive, 1) = 1
              AND a.feeID IS NOT NULL AND a.feeID <> ''
        ");
        $stmt->execute([$academicYear, $semester]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $feeID = (string)($row['feeID'] ?? '');
            if ($feeID === '') continue;
            $code  = (string)($row['feeCode'] ?? '');
            $note  = (string)($row['feeNote'] ?? '');
            $label = implode(' - ', array_filter([$code, $note], fn($p) => $p !== ''));
            $out[] = [
                'feeID' => $feeID,
                'feeCode' => $code !== '' ? $code : $feeID,
                'label' => $label !== '' ? $label : $feeID,
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/cashier/outstanding-balance/report
    //   ?mode=account|fee                                (required)
    //   &q=<student number or name substring>            (optional)
    //   &programID=<programID>                           (optional)
    //   &sectionID=<sectionID>                            (optional)
    //   &yearLevel=<yearLevel>                            (optional)
    //   &feeIDs=<comma-separated feeID list>              (optional, mode=fee only — see below)
    //   &email=<caller email>                             (optional — see 'preparedBy' below)
    //
    // Read-only, hard-scoped to the active term (same as balances() /
    // outstandingBalanceBootstrap() above — no "all terms" toggle here).
    //
    // Two report shapes off one endpoint:
    //   mode=account — one row per registration with outstanding balance
    //                  (_buildAccountModeRecords()).
    //   mode=fee     — one row per unpaid/partially-paid assessment line
    //                  (_buildFeeModeRecords()).
    //
    // feeIDs — narrows mode=fee down to a chosen subset of fees (the
    // dropdown is populated from outstandingBalanceBootstrap()'s
    // enrollment-scoped 'fees' list — see _getEnrolledFees()). This is
    // deliberately ignored in mode=account: Per Account reports the
    // registration's FULL obligation across every active assessment, and
    // silently narrowing that sum to a fee subset would make the
    // "outstanding balance" figure quietly stop meaning what its label
    // says. Per Fee mode has no such aggregate to protect — each row is
    // already one assessment line — so filtering it down to chosen fees
    // is safe and is the whole point of this filter.
    //
    // balanceRaw is an internal-only field the two builder methods attach
    // to each record so grandOutstanding can be summed below WITHOUT
    // re-parsing the already-number_format()'d display string — it is
    // stripped before json_encode so it never reaches the client.
    //
    // Requires the Phase 1 indexes (idx_assessments_registrationNumber /
    // idx_assessments_regnum_active, idx_paymentDetails_AssessmentID,
    // idx_registrations_ay_sem) to avoid full table scans on
    // tblAssessments / tblPaymentDetails / tblRegistrations as they grow.
    //
    // Both builder methods' paidTbl subquery also joins
    // tblPaymentDetails.ORNumber -> tblPayments.ORNumber to reach the
    // payment/date-created columns (see paymentDateDisplay/
    // dateCreatedDisplay below) — idx_paymentDetails_ORNumber already
    // covers the tblPaymentDetails side; confirm tblPayments.ORNumber
    // itself is indexed/unique (it should already be, as the existing
    // Payment History / Summary lookups depend on it) before this runs
    // against production-sized data.
    // ─────────────────────────────────────────────────────────────────────
    public function outstandingBalanceReport()
    {
        $mode = trim($_GET['mode'] ?? '');
        if (!in_array($mode, ['account', 'fee'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'mode must be "account" or "fee".']);
            return;
        }

        $db = Database::getConnection();
        $referenceData = new \App\Services\ReferenceDataService();

        try {
            $term = $referenceData->getActiveTerm();
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
            return;
        }

        $q         = trim($_GET['q'] ?? '');
        $programID = trim($_GET['programID'] ?? '');
        $sectionID = trim($_GET['sectionID'] ?? '');
        $yearLevel = trim($_GET['yearLevel'] ?? '');

        // email — optional. When supplied, resolved to a display name via
        // the same _resolveUserDisplayName() helper used by bootstrap()'s
        // selfName/cashiers and report()'s generatedBy above, so the
        // printed "Prepared by" line on the client shows a real name
        // instead of a raw email address. Omitted (blank) rather than
        // erroring when absent — this endpoint has no admin/self-scoping
        // concept, so email is informational only, not an access check.
        $email      = trim($_GET['email'] ?? '');
        $preparedBy = $email !== '' ? $this->_resolveUserDisplayName($db, $email) : '';

        // feeIDs — comma-separated, mode=fee only. Parsed unconditionally
        // (harmless if a client passes it in mode=account) but only ever
        // applied to the WHERE clause below when $mode === 'fee' — see the
        // docblock above for why Per Account never honors this filter.
        $feeIDsRaw = trim($_GET['feeIDs'] ?? '');
        $feeIDs = $feeIDsRaw !== ''
            ? array_values(array_unique(array_filter(array_map('trim', explode(',', $feeIDsRaw)), fn($f) => $f !== '')))
            : [];

        $conditions = ['r.academicYear = ?', 'r.semester = ?'];
        $params = [$term['academicYear'], $term['semester']];

        if ($programID !== '') { $conditions[] = 'r.programID = ?'; $params[] = $programID; }
        if ($sectionID !== '') { $conditions[] = 'r.sectionID = ?'; $params[] = $sectionID; }
        if ($yearLevel !== '') { $conditions[] = 'r.yearLevel = ?'; $params[] = $yearLevel; }

        if ($q !== '') {
            $conditions[] = '(s.studentNumber LIKE ? OR s.lastName LIKE ? OR s.firstName LIKE ? ' .
                             'OR CONCAT(s.firstName, " ", s.lastName) LIKE ? OR CONCAT(s.lastName, ", ", s.firstName) LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        // Only mode=fee's query FROMs tblAssessments under alias "a" at
        // this level (_buildAccountModeRecords() reaches assessments only
        // through the assessed/paidTbl subqueries, which have no "a"
        // alias to hang this condition off), so this is intentionally
        // gated to $mode === 'fee' rather than added unconditionally above.
        if ($mode === 'fee' && !empty($feeIDs)) {
            $feePlaceholders = implode(',', array_fill(0, count($feeIDs), '?'));
            $conditions[] = "a.feeID IN ($feePlaceholders)";
            array_push($params, ...$feeIDs);
        }

        $where = implode(' AND ', $conditions);

        $records = $mode === 'account'
            ? $this->_buildAccountModeRecords($db, $where, $params)
            : $this->_buildFeeModeRecords($db, $where, $params);

        $grandOutstanding = array_sum(array_map(fn($r) => (float)$r['balanceRaw'], $records));

        // Strip the internal-only balanceRaw field before it goes over the
        // wire — it exists purely to compute grandOutstanding above.
        $records = array_map(function ($r) {
            unset($r['balanceRaw']);
            return $r;
        }, $records);

        echo json_encode([
            'ok'               => true,
            'mode'             => $mode,
            'academicYear'     => $term['academicYear'],
            'semester'         => $term['semester'],
            'filters'          => ['q' => $q, 'programID' => $programID, 'sectionID' => $sectionID, 'yearLevel' => $yearLevel, 'feeIDs' => $feeIDs],
            'count'            => count($records),
            'records'          => $records,
            'grandOutstanding' => number_format($grandOutstanding, 2, '.', ''),
            'generatedAt'      => date('Y-m-d H:i:s'),
            'preparedBy'       => $preparedBy,
            'message'          => count($records) . ' record(s) found.',
        ]);
    }

    // ── Per Account — one row per registration with outstanding balance,
    // aggregating ALL active assessments on that registration vs. ALL
    // payment-detail amounts allocated to those assessments.
    //
    // COALESCE(isActive, 1) = 1 in both subqueries — blank/missing
    // tblAssessments.isActive defaults to ACTIVE, same rule as
    // CashierController::balances().
    //
    // paymentDateDisplay / dateCreatedDisplay — names match the Summary
    // tab's own two date concepts (report()'s dateMode=paymentDate vs
    // dateCreated) for consistency across the module. Both dates live on
    // tblPayments, NOT tblPaymentDetails — tblPaymentDetails only has
    // AssessmentID/Amount/ORNumber, so paidTbl below joins through
    // ORNumber to reach tblPayments for the actual date columns. The two
    // are tracked independently (two separate MAX()s, not one row) because
    // the system allows backdating: PaymentYear/PaymentMonthNumber/
    // PaymentDay is the transaction date the cashier entered (possibly
    // backdated), while dateCreated is the real encoding timestamp — the
    // payment with the latest transaction date is not necessarily the one
    // encoded last, so these can point at two different underlying rows.
    private function _buildAccountModeRecords($db, string $where, array $params): array
    {
        $sql = "
            SELECT
                r.RegistrationNumber, r.studentNumber, r.sectionID, r.programID, r.yearLevel,
                s.lastName, s.firstName, s.middleName, s.middleInitial, s.nameExtension,
                sec.sectionName,
                prog.programCode, prog.programDescription,
                COALESCE(assessed.total, 0) AS totalAssessed,
                COALESCE(paidTbl.total, 0)  AS totalPaid,
                paidTbl.lastPaymentDateRaw,
                paidTbl.lastDateCreatedRaw
            FROM tblRegistrations r
            JOIN tblStudents s        ON s.studentNumber = r.studentNumber
            LEFT JOIN tblSections sec ON sec.sectionID = r.sectionID
            LEFT JOIN tblPrograms prog ON prog.programID = r.programID
            LEFT JOIN (
                SELECT registrationNumber, SUM(amount) AS total
                FROM tblAssessments
                WHERE COALESCE(isActive, 1) = 1
                GROUP BY registrationNumber
            ) assessed ON assessed.registrationNumber = r.RegistrationNumber
            LEFT JOIN (
                SELECT
                    a.registrationNumber,
                    SUM(pd.Amount) AS total,
                    MAX(STR_TO_DATE(CONCAT(p.PaymentYear, '-', p.PaymentMonthNumber, '-', p.PaymentDay), '%Y-%c-%e')) AS lastPaymentDateRaw,
                    MAX(p.dateCreated) AS lastDateCreatedRaw
                FROM tblPaymentDetails pd
                JOIN tblAssessments a      ON a.assessmentID = pd.AssessmentID
                LEFT JOIN tblPayments p    ON p.ORNumber = pd.ORNumber
                WHERE COALESCE(a.isActive, 1) = 1
                GROUP BY a.registrationNumber
            ) paidTbl ON paidTbl.registrationNumber = r.RegistrationNumber
            WHERE $where
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $referenceData = new \App\Services\ReferenceDataService();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $assessed = (float)($row['totalAssessed'] ?? 0);
            $paid     = (float)($row['totalPaid'] ?? 0);
            $balance  = $assessed - $paid;
            if ($balance <= 0.009) continue; // outstanding-only, same epsilon as balances()

            $out[] = [
                'registrationNumber' => (string)($row['RegistrationNumber'] ?? ''),
                'studentNumber'      => (string)($row['studentNumber'] ?? ''),
                'studentName'        => $referenceData->buildStudentFullName($row),
                'homeClass'          => (string)($row['sectionName'] ?? '') ?: (string)($row['sectionID'] ?? ''),
                'program'            => $this->_buildProgramLabel($row),
                'courseCode'         => $this->_buildProgramCode($row),
                'yearLevel'          => (string)($row['yearLevel'] ?? ''),
                // Compressed course+year+section display, e.g. "DIT3-LINUS".
                // 'homeClass'/'courseCode'/'yearLevel' above are kept as-is
                // (other/future consumers may still want them separately);
                // this is what the Outstanding Balance table actually
                // renders as its single "Class" column now — see
                // _buildClassCode() for the format/fallback rules.
                'classCode'          => $this->_buildClassCode($row),
                'totalObligation'    => number_format($assessed, 2, '.', ''),
                'totalPaid'          => number_format($paid, 2, '.', ''),
                'outstandingBalance' => number_format($balance, 2, '.', ''),
                // Only meaningful once at least one payment exists — null
                // (not '') otherwise, so the frontend can cleanly hide the
                // line. Most recent across ALL of this registration's
                // assessments/payments, not just this one fee.
                'paymentDateDisplay' => $paid > 0.009 ? $this->_formatAggregatedPaymentDate($row['lastPaymentDateRaw'] ?? null) : null,
                'dateCreatedDisplay' => $paid > 0.009 ? $this->_formatAggregatedDateCreated($row['lastDateCreatedRaw'] ?? null) : null,
                'balanceRaw'         => $balance,
            ];
        }

        usort($out, fn($a, $b) => strcmp($a['studentName'], $b['studentName']));
        return $out;
    }

    // ── Per Fee — one row per active assessment line with an outstanding
    // balance, including that fee's own last payment/date-created (only
    // populated once it has been paid at least once — see
    // paymentDateDisplay/dateCreatedDisplay below). Same tblPayments join
    // and backdating rationale as _buildAccountModeRecords() above, scoped
    // to this one assessmentID instead of the whole registration.
    private function _buildFeeModeRecords($db, string $where, array $params): array
    {
        $sql = "
            SELECT
                a.assessmentID, a.registrationNumber, a.amount, a.feeID,
                r.studentNumber, r.sectionID, r.programID, r.yearLevel,
                s.lastName, s.firstName, s.middleName, s.middleInitial, s.nameExtension,
                sec.sectionName,
                prog.programCode, prog.programDescription,
                f.feeCode, f.feeNote,
                COALESCE(paidTbl.total, 0) AS paidAmount,
                paidTbl.lastPaymentDateRaw,
                paidTbl.lastDateCreatedRaw
            FROM tblAssessments a
            JOIN tblRegistrations r    ON r.RegistrationNumber = a.registrationNumber
            JOIN tblStudents s         ON s.studentNumber = r.studentNumber
            LEFT JOIN tblSections sec  ON sec.sectionID = r.sectionID
            LEFT JOIN tblPrograms prog ON prog.programID = r.programID
            LEFT JOIN tblFees f        ON f.feeID = a.feeID
            LEFT JOIN (
                SELECT
                    pd.AssessmentID,
                    SUM(pd.Amount) AS total,
                    MAX(STR_TO_DATE(CONCAT(p.PaymentYear, '-', p.PaymentMonthNumber, '-', p.PaymentDay), '%Y-%c-%e')) AS lastPaymentDateRaw,
                    MAX(p.dateCreated) AS lastDateCreatedRaw
                FROM tblPaymentDetails pd
                LEFT JOIN tblPayments p ON p.ORNumber = pd.ORNumber
                GROUP BY pd.AssessmentID
            ) paidTbl ON paidTbl.AssessmentID = a.assessmentID
            WHERE COALESCE(a.isActive, 1) = 1 AND $where
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $referenceData = new \App\Services\ReferenceDataService();
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $amount  = (float)($row['amount'] ?? 0);
            $paid    = (float)($row['paidAmount'] ?? 0);
            $balance = $amount - $paid;
            if ($balance <= 0.009) continue; // outstanding-only, same epsilon as balances()

            $out[] = [
                'assessmentID'       => (string)($row['assessmentID'] ?? ''),
                'registrationNumber' => (string)($row['registrationNumber'] ?? ''),
                'studentNumber'      => (string)($row['studentNumber'] ?? ''),
                'studentName'        => $referenceData->buildStudentFullName($row),
                'homeClass'          => (string)($row['sectionName'] ?? '') ?: (string)($row['sectionID'] ?? ''),
                'program'            => $this->_buildProgramLabel($row),
                'courseCode'         => $this->_buildProgramCode($row),
                'yearLevel'          => (string)($row['yearLevel'] ?? ''),
                // Compressed course+year+section display, e.g. "DIT3-LINUS" —
                // see the account-mode note above / _buildClassCode() below.
                'classCode'          => $this->_buildClassCode($row),
                'feeCode'            => (string)($row['feeCode'] ?? ''),
                'feeDescription'     => (string)($row['feeNote'] ?? ''),
                'assessedAmount'     => number_format($amount, 2, '.', ''),
                'paidAmount'         => number_format($paid, 2, '.', ''),
                'balance'            => number_format($balance, 2, '.', ''),
                // Only meaningful when paid > 0 — null (not '') otherwise,
                // so the frontend can cleanly hide the line.
                'paymentDateDisplay' => $paid > 0.009 ? $this->_formatAggregatedPaymentDate($row['lastPaymentDateRaw'] ?? null) : null,
                'dateCreatedDisplay' => $paid > 0.009 ? $this->_formatAggregatedDateCreated($row['lastDateCreatedRaw'] ?? null) : null,
                'balanceRaw'         => $balance,
            ];
        }

        usort($out, fn($a, $b) => strcmp($a['studentName'], $b['studentName']) ?: strcmp($a['feeCode'], $b['feeCode']));
        return $out;
    }

    // Formats the aggregated (MAX'd) transaction-date DATE string returned
    // by the paidTbl subqueries above into the same space-joined
    // "Month Day Year" style as CashierSummaryController::report()'s own
    // _buildPaymentDateDisplay() — for naming/format consistency between
    // the Summary tab and the Outstanding Balance tab. Unlike
    // _buildPaymentDateDisplay(), this reads a plain SQL DATE (not
    // PaymentMonth/Day/Year columns off one row), because the value here
    // is a MAX() aggregate across potentially many payment rows — there is
    // no single "winning" row to pull PaymentMonth text from.
    private function _formatAggregatedPaymentDate(?string $raw): ?string
    {
        if ($raw === null || $raw === '') return null;
        $dt = \DateTime::createFromFormat('Y-m-d', $raw);
        return $dt ? $dt->format('F j Y') : null;
    }

    // Formats the aggregated (MAX'd) dateCreated string — same raw
    // pass-through as report()'s dateCreatedDisplay, since dateCreated is
    // already a human-readable 'Y-m-d H:i:s' VARCHAR, not a value that
    // needs reformatting.
    private function _formatAggregatedDateCreated(?string $raw): ?string
    {
        $raw = trim((string)$raw);
        return $raw !== '' ? $raw : null;
    }

    // Builds the "program" label shown on every Outstanding Balance card,
    // in both modes, regardless of whether the request was filtered by
    // programID — the registration's enrolled program must always be
    // visible, not just when the cashier happens to filter by it. Same
    // "code - description, falling back to raw ID" pattern as
    // _getEnrolledPrograms()'s label and homeClass's sectionName fallback.
    // A row whose programID doesn't resolve via the tblPrograms LEFT JOIN
    // (deleted/renamed program) still shows the raw programID rather than
    // going blank.
    private function _buildProgramLabel(array $row): string
    {
        $code = (string)($row['programCode'] ?? '');
        $desc = (string)($row['programDescription'] ?? '');
        $label = implode(' - ', array_filter([$code, $desc], fn($p) => $p !== ''));
        if ($label !== '') return $label;
        return (string)($row['programID'] ?? '');
    }

    // Course code alone (no description) — for table-style displays that
    // need the code as its own column rather than embedded in a combined
    // label. Same raw-programID fallback as _buildProgramLabel() above.
    private function _buildProgramCode(array $row): string
    {
        $code = (string)($row['programCode'] ?? '');
        if ($code !== '') return $code;
        return (string)($row['programID'] ?? '');
    }

    // Builds a compressed "class" / "home class" code by combining course
    // code, year level digit, and section name into a single display
    // string: "[courseCode][yearLevelDigit]-[sectionName]", e.g. "DIT3-LINUS"
    // for a DIT program, 3rd year, section "LINUS". Lets the Outstanding
    // Balance table collapse what used to be three separate columns
    // (Course Code / Year Level / Section) into one.
    //
    // Year-level digit extraction uses the same rule as
    // ReferenceDataService::_buildCompactTermCode() (/(\d+)/ on the
    // free-text yearLevel string) — tblRegistrations.yearLevel has no
    // fixed format ("3rd Year", "Year 3", "3", etc.), so this just pulls
    // the first run of digits out of whatever's there.
    //
    // Degrades gracefully instead of leaving gaps or literal "false"/"null"
    // text when a piece is missing:
    //   - no course code       -> falls back to the raw programID (via
    //                              _buildProgramCode(), same as elsewhere)
    //   - no digit in yearLevel -> digit is simply omitted, not padded
    //                              with a placeholder
    //   - no section name      -> falls back to the raw sectionID
    //   - course+year AND section both blank -> returns '' (caller decides
    //                              how to render an empty class code)
    private function _buildClassCode(array $row): string
    {
        $courseCode   = trim($this->_buildProgramCode($row));
        $yearLevelRaw = (string)($row['yearLevel'] ?? '');
        $yearDigit    = preg_match('/(\d+)/', $yearLevelRaw, $m) ? $m[1] : '';
        $sectionName  = trim((string)($row['sectionName'] ?? '')) ?: trim((string)($row['sectionID'] ?? ''));

        $prefix = $courseCode . $yearDigit;

        if ($prefix === '' && $sectionName === '') return '';
        if ($prefix === '') return strtoupper($sectionName);
        if ($sectionName === '') return strtoupper($prefix);

        return strtoupper($prefix) . '-' . strtoupper($sectionName);
    }

    // ─────────────────────────────────────────────────────────────────────
    // GET /api/cashier/summary/report
    //   ?email=<caller email>                          (required)
    //   &dateMode=paymentDate|dateCreated               (required)
    //   &rangeType=single|range                          (required)
    //   &date=YYYY-MM-DD                                 (when rangeType=single)
    //   &startDate=YYYY-MM-DD&endDate=YYYY-MM-DD         (when rangeType=range)
    //   &cashierFilter=__ALL__|<createdBy value>         (ignored/forced for non-admins)
    //
    // Returns a payment collection report for the given date range and cashier.
    // tblPaymentDetails is deliberately NOT joined/read — Total Collection
    // only needs payment-level AmountPaid.
    // tblPaymentDetails is not joined — this report only needs AmountPaid.
    //
    // See the class-level docblock for the performance fix applied here:
    // SQL-level date + cashier filtering, plus batched name lookups.
    // Requires the three tblPayments indexes documented above.
    // ─────────────────────────────────────────────────────────────────────
    public function report()
    {
        $email = trim($_GET['email'] ?? '');
        if ($email === '') { http_response_code(400); echo json_encode(['ok' => false, 'message' => 'email is required.']); return; }

        $dateMode = trim($_GET['dateMode'] ?? '');
        if (!in_array($dateMode, ['paymentDate', 'dateCreated'], true)) {
            http_response_code(422); echo json_encode(['ok' => false, 'message' => 'Date mode must be either "paymentDate" or "dateCreated".']); return;
        }
        $rangeType = trim($_GET['rangeType'] ?? '');
        if (!in_array($rangeType, ['single', 'range'], true)) {
            http_response_code(422); echo json_encode(['ok' => false, 'message' => 'Range type must be either "single" or "range".']); return;
        }

        $singleDate = trim($_GET['date'] ?? '');
        $startDate  = trim($_GET['startDate'] ?? '');
        $endDate    = trim($_GET['endDate'] ?? '');

        try {
            [$startBound, $endBound] = $this->_normalizeDateBounds($rangeType, $singleDate, $startDate, $endDate);
        } catch (\Exception $e) {
            http_response_code(422); echo json_encode(['ok' => false, 'message' => $e->getMessage()]); return;
        }

        $db = Database::getConnection();
        $isAdmin = $this->_isAdmin($email);
        // Server-side override for non-admins — never trust a client cashierFilter.
        // Computed BEFORE the main query (unlike the plan's original draft),
        // so it can be pushed into the SQL WHERE clause below.
        $effectiveCashier = $isAdmin ? (trim($_GET['cashierFilter'] ?? '') ?: '__ALL__') : $email;
        $wantAll = $effectiveCashier === '__ALL__';

        // ── SQL-level filtering — the performance fix. Both the date
        //    bound AND the cashier filter are pushed into WHERE now,
        //    instead of fetching all ~79k rows and filtering in PHP.
        //    dateCreated is bounded as a string range (matches the exact
        //    'yyyy-MM-dd HH:mm:ss' format _accountNowTimestamp_() writes);
        //    paymentDate mode bounds coarsely by year first (exact
        //    month/day boundary is still re-checked in PHP below, since
        //    that needs a real Date comparison, not just a numeric one —
        //    correctness for year-boundary ranges like Dec 28 -> Jan 3 is
        //    preserved by that PHP-side check, the SQL bound only narrows
        //    the candidate set).
        $conditions = [];
        $params = [];

        if ($dateMode === 'dateCreated') {
            $conditions[] = 'p.dateCreated BETWEEN ? AND ?';
            $params[] = $startBound->format('Y-m-d H:i:s');
            $params[] = $endBound->format('Y-m-d H:i:s');
        } else {
            $conditions[] = 'p.PaymentYear BETWEEN ? AND ?';
            $params[] = (int)$startBound->format('Y');
            $params[] = (int)$endBound->format('Y');
        }

        if (!$wantAll) {
            $conditions[] = 'p.createdBy = ?';
            $params[] = $effectiveCashier;
        }

        $sql = "
            SELECT p.*, r.studentNumber
            FROM tblPayments p
            LEFT JOIN tblRegistrations r ON r.RegistrationNumber = p.registrationNumber
            WHERE " . implode(' AND ', $conditions);

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $paymentRows = $stmt->fetchAll();

        // Batch-resolve student display names ONCE via WHERE ... IN (...)
        // instead of a per-row lookup.
        $studentNumbers = array_values(array_unique(array_filter(array_map(
            fn($r) => (string)($r['studentNumber'] ?? ''), $paymentRows
        ))));
        $displayNameByStudent = [];
        if (!empty($studentNumbers)) {
            $ph = implode(',', array_fill(0, count($studentNumbers), '?'));
            $sStmt = $db->prepare("SELECT * FROM tblStudents WHERE studentNumber IN ($ph)");
            $sStmt->execute($studentNumbers);
            foreach ($sStmt->fetchAll() as $srow) {
                $displayNameByStudent[(string)$srow['studentNumber']] = $this->_buildStudentDisplayName($srow);
            }
        }

        // Batch-resolve cashier display names ONCE instead of one
        // tblUsers query per payment row — this per-row query was the
        // other half of the original hang against a large result set.
        $createdByEmails = array_values(array_unique(array_filter(array_map(
            fn($r) => (string)($r['createdBy'] ?? ''), $paymentRows
        ))));
        $displayNameByEmail = [];
        if (!empty($createdByEmails)) {
            $ph2 = implode(',', array_fill(0, count($createdByEmails), '?'));
            $uStmt = $db->prepare("SELECT Email, fullName FROM tblUsers WHERE Email IN ($ph2)");
            $uStmt->execute($createdByEmails);
            foreach ($uStmt->fetchAll() as $u) {
                $displayNameByEmail[(string)$u['Email']] = trim((string)$u['fullName']) ?: (string)$u['Email'];
            }
        }

        $records = [];
        $grandTotal = 0.0;
        $byCashier = [];
        $byPaymentMethod = [];
        $referenceData = new \App\Services\ReferenceDataService();
        $methodLabels = $referenceData->getPaymentMethodLabels();

        foreach ($paymentRows as $payment) {
            // createdBy / display resolution stay as-is; SQL has already
            // applied the createdBy filter for the !$wantAll case, but
            // the exact date check below still runs for every row since
            // that's the only place a real Date comparison happens.
            $createdBy = (string)($payment['createdBy'] ?? '');

            $rowDate = $dateMode === 'paymentDate'
                ? $this->_buildPaymentDateObj($payment)
                : $this->_parseDateCreated((string)($payment['dateCreated'] ?? ''));

            if (!$rowDate) continue; // unparsable row — skip, never abort the whole report
            if ($rowDate < $startBound || $rowDate > $endBound) continue;

            $amountPaid = (float)($payment['AmountPaid'] ?? 0);
            $studentNumber = (string)($payment['studentNumber'] ?? '');
            $studentDisplayName = $displayNameByStudent[$studentNumber] ?? '(Unknown Student)';
            $createdByDisplayName = $displayNameByEmail[$createdBy] ?? $createdBy;

            // Blank/NULL paymentMethod is treated as CASH on every read path (standing rule).
            $methodCode = $referenceData->resolvePaymentMethodCode($payment['paymentMethod'] ?? null);
            $methodLabel = $methodLabels[$methodCode] ?? $methodCode;

            $grandTotal += $amountPaid;

            $records[] = [
                'ORNumber'             => (string)($payment['ORNumber'] ?? ''),
                'studentNumber'        => $studentNumber,
                'studentDisplayName'   => $studentDisplayName,
                'amountPaid'           => number_format($amountPaid, 2, '.', ''),
                'createdBy'            => $createdBy,
                'createdByDisplayName' => $createdByDisplayName,
                'paymentDateDisplay'   => $this->_buildPaymentDateDisplay($payment),
                'dateCreatedDisplay'   => (string)($payment['dateCreated'] ?? ''),
                'paymentMethod'        => $methodCode,
                'paymentMethodLabel'   => $methodLabel,
                'paymentReference'     => (string)($payment['paymentReference'] ?? ''),
            ];

            if ($wantAll) {
                $key = strtolower($createdBy) ?: '(blank)';
                if (!isset($byCashier[$key])) {
                    $byCashier[$key] = ['displayName' => $createdByDisplayName ?: '(blank)', 'count' => 0, 'total' => 0.0];
                }
                $byCashier[$key]['count']++;
                $byCashier[$key]['total'] += $amountPaid;
            }

            // Payment-method breakdown mirrors byCashier's shape, but is
            // always computed — it's an orthogonal axis, not admin-only.
            if (!isset($byPaymentMethod[$methodCode])) {
                $byPaymentMethod[$methodCode] = ['displayName' => $methodLabel, 'count' => 0, 'total' => 0.0];
            }
            $byPaymentMethod[$methodCode]['count']++;
            $byPaymentMethod[$methodCode]['total'] += $amountPaid;
        }

        usort($records, fn($a, $b) => strcmp($a['studentDisplayName'], $b['studentDisplayName']));

        $byCashierOut = [];
        foreach ($byCashier as $key => $entry) {
            $byCashierOut[$key] = [
                'displayName' => $entry['displayName'], 'count' => $entry['count'],
                'total' => number_format($entry['total'], 2, '.', ''),
            ];
        }

        $byPaymentMethodOut = [];
        foreach ($byPaymentMethod as $key => $entry) {
            $byPaymentMethodOut[$key] = [
                'displayName' => $entry['displayName'], 'count' => $entry['count'],
                'total' => number_format($entry['total'], 2, '.', ''),
            ];
        }

        echo json_encode([
            'ok' => true,
            'filters' => [
                'dateMode' => $dateMode, 'rangeType' => $rangeType,
                'date' => $singleDate, 'startDate' => $startDate, 'endDate' => $endDate,
                'cashierFilter' => $effectiveCashier, 'isAdmin' => $isAdmin,
            ],
            'grandTotal' => number_format($grandTotal, 2, '.', ''),
            'count' => count($records), 'records' => $records, 'byCashier' => $byCashierOut,
            'byPaymentMethod' => $byPaymentMethodOut,
            'generatedAt' => date('Y-m-d H:i:s'), 'generatedBy' => $this->_resolveUserDisplayName($db, $email),
            'message' => count($records) . ' payment record(s) found.',
        ]);
    }

    private function _normalizeDateBounds(string $rangeType, string $date, string $startDate, string $endDate): array
    {
        $parse = function (string $iso): \DateTime {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) throw new \Exception('Date must be in YYYY-MM-DD format: ' . $iso);
            return \DateTime::createFromFormat('Y-m-d H:i:s', $iso . ' 00:00:00');
        };

        if ($rangeType === 'single') {
            if ($date === '') throw new \Exception('Date is required.');
            $start = $parse($date);
            $end = clone $start; $end->setTime(23, 59, 59);
            return [$start, $end];
        }

        if ($startDate === '') throw new \Exception('Start date is required.');
        if ($endDate === '')   throw new \Exception('End date is required.');
        $start = $parse($startDate);
        $end = $parse($endDate); $end->setTime(23, 59, 59);
        if ($end < $start) throw new \Exception('End date must not be before start date.');
        return [$start, $end];
    }

    // Parses payment date components (PaymentYear, PaymentMonthNumber, PaymentDay)
    // into a DateTime object. Returns null when any component is missing.
    // PaymentYear/PaymentMonthNumber/PaymentDay only. PaymentMonth (text
    // name) is display-only and never authoritative here, matching GAS.
    private function _buildPaymentDateObj(array $payment): ?\DateTime
    {
        $year  = (int)($payment['PaymentYear'] ?? 0);
        $month = (int)($payment['PaymentMonthNumber'] ?? 0);
        $day   = (int)($payment['PaymentDay'] ?? 0);
        if (!$year || !$month || !$day) return null;
        return \DateTime::createFromFormat('Y-n-j H:i:s', "$year-$month-$day 00:00:00") ?: null;
    }

    // Parses the dateCreated column. dateCreated is stored as a VARCHAR
    // 'yyyy-MM-dd HH:mm:ss' string, not a native timestamp type.
    // Falls back to date-only; returns null on unparseable input.
    private function _parseDateCreated(string $raw): ?\DateTime
    {
        if ($raw === '') return null;
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $raw);
        if ($dt) return $dt;
        $dt = \DateTime::createFromFormat('Y-m-d', $raw);
        return $dt ?: null;
    }

    private function _buildPaymentDateDisplay(array $payment): string
    {
        $month = trim((string)($payment['PaymentMonth'] ?? ''));
        $day   = trim((string)($payment['PaymentDay']   ?? ''));
        $year  = trim((string)($payment['PaymentYear']  ?? ''));
        if ($month !== '' || $day !== '' || $year !== '') {
            return trim(implode(' ', array_filter([$month, $day, $year], fn($p) => $p !== '')));
        }
        return trim((string)($payment['dateCreated'] ?? ''));
    }
}
