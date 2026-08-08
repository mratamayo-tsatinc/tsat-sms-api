<?php

namespace App\Services;

use App\Core\Database;

/**
 * App\Services\EcrAttendanceMirrorService
 *
 * Read/write access to the ECR Attendance mirror (tblEcrAttendanceMeetings,
 * tblEcrAttendanceSummary).
 *
 * NO-DELETE CONTRACT: this service never issues a DELETE against either
 * table. Every sync UPSERTs (INSERT ... ON DUPLICATE KEY UPDATE) the rows
 * it found, stamping ALL of them with ONE shared lastSyncedAt timestamp
 * generated once per call to upsertAttendanceBulk(). Reads (getMeetings(),
 * getSummary(), getAttendanceStats()) only return rows whose lastSyncedAt
 * equals the MAX lastSyncedAt on file for that teacherClassLoadID — i.e.
 * the most recent sync's rows. A row that isn't part of the newest sync
 * (a meeting slot no longer used, a student no longer on the ATTE sheet)
 * keeps its old timestamp and silently drops out of every read without
 * ever being deleted. Accepted trade-off: unbounded row growth across
 * re-syncs (see plan §2) — not a bug, not something to "fix" here.
 *
 * Same "dumb storage, no policy" contract as EcrMirrorService otherwise:
 * no staleness rules beyond the timestamp comparison above, no
 * Drive/Sheets access of any kind. Ecr.gs is the only caller that reads
 * the ATTE sheet; this class only persists whatever it found, via
 * EcrAttendanceMirrorController.
 */
class EcrAttendanceMirrorService
{
    private const PERIODS = ['PRELIM', 'MIDTERM', 'SEMIFINALS', 'FINALS'];
    private const SUMMARY_PERIODS = ['PRELIM', 'MIDTERM', 'SEMIFINALS', 'FINALS', 'TERM'];
    private const STATUSES = ['P', 'L', 'A', 'E'];

    /**
     * Returns tblEcrAttendanceMeetings rows for one offering, filtered to
     * the LATEST synced snapshot only — i.e. rows whose lastSyncedAt
     * equals MAX(lastSyncedAt) for this teacherClassLoadID.
     * @return array<int, array{teacherClassLoadID:string, studentNumber:string,
     *   period:string, meetingNo:int, meetingDate:?string, status:?string,
     *   lastSyncedAt:?string, lastSyncedBy:?string}>
     */
    public function getMeetings(string $teacherClassLoadID): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT m.teacherClassLoadID, m.studentNumber, m.period, m.meetingNo, m.meetingDate,
                   m.status, m.lastSyncedAt, m.lastSyncedBy
            FROM tblEcrAttendanceMeetings m
            INNER JOIN (
                SELECT teacherClassLoadID, MAX(lastSyncedAt) AS latestSyncedAt
                FROM tblEcrAttendanceMeetings
                WHERE teacherClassLoadID = :tcl1
                GROUP BY teacherClassLoadID
            ) latest ON latest.teacherClassLoadID = m.teacherClassLoadID
                    AND latest.latestSyncedAt = m.lastSyncedAt
            WHERE m.teacherClassLoadID = :tcl2
            ORDER BY m.studentNumber, FIELD(m.period,'PRELIM','MIDTERM','SEMIFINALS','FINALS'), m.meetingNo
        ");
        $stmt->execute([':tcl1' => $teacherClassLoadID, ':tcl2' => $teacherClassLoadID]);
        return array_map([$this, '_toMeetingRow'], $stmt->fetchAll());
    }

    /**
     * Returns tblEcrAttendanceSummary rows for one offering (four
     * per-period rows plus one 'TERM' row per student), filtered to the
     * LATEST synced snapshot only — same rule as getMeetings().
     * @return array<int, array{teacherClassLoadID:string, studentNumber:string,
     *   period:string, presentCount:int, lateCount:int, absentCount:int,
     *   excusedCount:int, totalCount:float, lastSyncedAt:?string,
     *   lastSyncedBy:?string}>
     */
    public function getSummary(string $teacherClassLoadID): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT s.teacherClassLoadID, s.studentNumber, s.period, s.presentCount, s.lateCount,
                   s.absentCount, s.excusedCount, s.totalCount, s.lastSyncedAt, s.lastSyncedBy
            FROM tblEcrAttendanceSummary s
            INNER JOIN (
                SELECT teacherClassLoadID, MAX(lastSyncedAt) AS latestSyncedAt
                FROM tblEcrAttendanceSummary
                WHERE teacherClassLoadID = :tcl1
                GROUP BY teacherClassLoadID
            ) latest ON latest.teacherClassLoadID = s.teacherClassLoadID
                    AND latest.latestSyncedAt = s.lastSyncedAt
            WHERE s.teacherClassLoadID = :tcl2
            ORDER BY s.studentNumber, FIELD(s.period,'PRELIM','MIDTERM','SEMIFINALS','FINALS','TERM')
        ");
        $stmt->execute([':tcl1' => $teacherClassLoadID, ':tcl2' => $teacherClassLoadID]);
        return array_map([$this, '_toSummaryRow'], $stmt->fetchAll());
    }

    /**
     * Upserts the ENTIRE live-read result for one offering into BOTH
     * tables — NO DELETE is ever issued. Every row in $meetings/$summary
     * is written via INSERT ... ON DUPLICATE KEY UPDATE and stamped with
     * ONE shared $now timestamp, generated once for this whole call. A
     * row previously written for this offering that is NOT present in
     * this call's $meetings/$summary keeps its old lastSyncedAt and is
     * simply excluded from getMeetings()/getSummary()/getAttendanceStats()'s
     * "latest snapshot" filter from now on — it is never physically
     * removed.
     *
     * $meetings entries: { studentNumber, period, meetingNo, meetingDate, status }.
     * $summary entries:  { studentNumber, period, present, late, absent, excused, total }.
     *
     * @return array{meetingsWritten:int, summaryWritten:int, syncedAt:string}
     */
    public function upsertAttendanceBulk(string $teacherClassLoadID, array $meetings, array $summary, ?string $syncedBy): array
    {
        $db = Database::getConnection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();

        try {
            // ONE timestamp for this entire sync — this IS the "latest
            // snapshot" marker every read filters on.
            $now = $this->_nowString();
            $syncedByNormalized = $this->_nullIfBlank($syncedBy);

            $insMeeting = $db->prepare("
                INSERT INTO tblEcrAttendanceMeetings (
                    teacherClassLoadID, studentNumber, period, meetingNo, meetingDate,
                    status, lastSyncedAt, lastSyncedBy
                ) VALUES (
                    :teacherClassLoadID, :studentNumber, :period, :meetingNo, :meetingDate,
                    :status, :lastSyncedAt, :lastSyncedBy
                )
                ON DUPLICATE KEY UPDATE
                    meetingDate  = VALUES(meetingDate),
                    status       = VALUES(status),
                    lastSyncedAt = VALUES(lastSyncedAt),
                    lastSyncedBy = VALUES(lastSyncedBy)
            ");
            $meetingsWritten = 0;
            foreach ($meetings as $row) {
                $studentNumber = trim((string)($row['studentNumber'] ?? ''));
                $period = strtoupper(trim((string)($row['period'] ?? '')));
                $meetingNo = (int)($row['meetingNo'] ?? 0);
                if ($studentNumber === '' || !in_array($period, self::PERIODS, true)) continue;
                if ($meetingNo < 1 || $meetingNo > 5) continue;

                $insMeeting->execute([
                    ':teacherClassLoadID' => $teacherClassLoadID,
                    ':studentNumber'      => $studentNumber,
                    ':period'             => $period,
                    ':meetingNo'          => $meetingNo,
                    ':meetingDate'        => $this->_nullIfBlank($row['meetingDate'] ?? null),
                    ':status'             => $this->_normalizeStatus($row['status'] ?? null),
                    ':lastSyncedAt'       => $now,
                    ':lastSyncedBy'       => $syncedByNormalized,
                ]);
                $meetingsWritten++;
            }

            $insSummary = $db->prepare("
                INSERT INTO tblEcrAttendanceSummary (
                    teacherClassLoadID, studentNumber, period, presentCount, lateCount,
                    absentCount, excusedCount, totalCount, lastSyncedAt, lastSyncedBy
                ) VALUES (
                    :teacherClassLoadID, :studentNumber, :period, :presentCount, :lateCount,
                    :absentCount, :excusedCount, :totalCount, :lastSyncedAt, :lastSyncedBy
                )
                ON DUPLICATE KEY UPDATE
                    presentCount = VALUES(presentCount),
                    lateCount    = VALUES(lateCount),
                    absentCount  = VALUES(absentCount),
                    excusedCount = VALUES(excusedCount),
                    totalCount   = VALUES(totalCount),
                    lastSyncedAt = VALUES(lastSyncedAt),
                    lastSyncedBy = VALUES(lastSyncedBy)
            ");
            $summaryWritten = 0;
            foreach ($summary as $row) {
                $studentNumber = trim((string)($row['studentNumber'] ?? ''));
                $period = strtoupper(trim((string)($row['period'] ?? '')));
                if ($studentNumber === '' || !in_array($period, self::SUMMARY_PERIODS, true)) continue;

                $insSummary->execute([
                    ':teacherClassLoadID' => $teacherClassLoadID,
                    ':studentNumber'      => $studentNumber,
                    ':period'             => $period,
                    ':presentCount'       => (int)($row['present'] ?? 0),
                    ':lateCount'          => (int)($row['late'] ?? 0),
                    ':absentCount'        => (int)($row['absent'] ?? 0),
                    ':excusedCount'       => (int)($row['excused'] ?? 0),
                    ':totalCount'         => (float)($row['total'] ?? 0),
                    ':lastSyncedAt'       => $now,
                    ':lastSyncedBy'       => $syncedByNormalized,
                ]);
                $summaryWritten++;
            }

            if ($ownsTransaction) $db->commit();
            return ['meetingsWritten' => $meetingsWritten, 'summaryWritten' => $summaryWritten, 'syncedAt' => $now];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Fully agnostic, per-period AND per-status attendance breakdown for
     * every offering in one term. This is the FINAL version of this
     * method — it supersedes an earlier single-present-rate design (which
     * only ever tracked meetingCount/studentCount/averageAttendanceRate)
     * in favor of the fully agnostic per-period/per-status shape below.
     *
     * Design decision — bulk, whole-term call, not a per-offering
     * endpoint: this method backs the existing bulk
     * `GET /api/ecr/mirror/attendance-stats` route (one call, whole term,
     * client renders/filters locally), matching `roster-counts`,
     * `teacher-folders`, and `files`. Reasons a per-offering endpoint was
     * rejected:
     *   - Consistency with the rest of this module — every other ECR
     *     mirror list endpoint already works this way.
     *   - The data is cheap and already computed from stored rows — no
     *     Drive access, no meaningful per-request cost saved by a
     *     per-item call. Per offering this is ~50 numeric fields (5
     *     periods × 4 statuses × 2 numbers, plus a few counts); even at a
     *     couple hundred offerings in a large term, the payload stays
     *     small.
     *   - Expand needs to feel instant — with the bulk shape, expanding a
     *     row in the UI is a pure client-side toggle with zero additional
     *     requests or loading states.
     *   - Agnostic to what's shown later — because the shape is
     *     `periods{period}.rates/raw` keyed off `SUMMARY_PERIODS`/
     *     `STATUSES`, a future UI (trend charts, a 5th status) reads from
     *     data it already has.
     *   If a future term ever gets large enough that this payload becomes
     *   a real problem, the fix is pagination or a summary/detail split
     *   at the HTTP layer — not per-offering fetching. Not needed now.
     *
     * Deliberately loops over self::SUMMARY_PERIODS (PRELIM/MIDTERM/
     * SEMIFINALS/FINALS/TERM) and self::STATUSES (P/L/A/E) instead of
     * hardcoding four period branches and four status fields — adding a
     * fifth grading period or a fifth status letter later means updating
     * those two class constants, not rewriting this method or any of its
     * callers (PHP or JS).
     *
     * "Rate" here is a CLASS-LEVEL aggregate, not a per-student average:
     * for a given period, SUM(that status's count across every student
     * in the offering) / SUM(all four statuses' counts in that period) *
     * 100. This is deliberately different from the sheet's own weighted
     * TTL column (P=1, L=.5, A=0, E=.25 — see the plan's non-goals, never
     * recomputed here): P+L+A+E rates always sum to ~100% for any period,
     * which the weighted figure does not, and that's what makes "late
     * rate" / "absent rate" / "excused rate" meaningful figures on their
     * own rather than only meaningful relative to a weighted total.
     *
     * "Raw" is the same class-level SUM, unweighted and un-percentaged —
     * the actual meeting-mark count, for anyone who wants the number
     * instead of (or alongside) the rate.
     *
     * Filtered to the LATEST synced snapshot only per teacherClassLoadID
     * — same no-delete/latest-timestamp rule as getMeetings()/getSummary().
     *
     * @return array<string, array{
     *   meetingCount:int, studentCount:int, attendanceRate:?float,
     *   lastSyncedAt:?string,
     *   periods: array<string, array{
     *     meetingCount:int, studentCount:int,
     *     raw:array{P:int,L:int,A:int,E:int},
     *     rates:array{P:?float,L:?float,A:?float,E:?float},
     *     lastSyncedAt:?string
     *   }>
     * }> keyed by teacherClassLoadID.
     *   - Top-level meetingCount/studentCount/attendanceRate/lastSyncedAt
     *     are convenience mirrors of periods['TERM'] (attendanceRate =
     *     periods['TERM'].rates['P']) — kept so a caller that only wants
     *     the collapsed "N meetings · X% present · Synced Xh ago" summary
     *     doesn't need to reach into periods{}.
     *   - lastSyncedAt is identical across every period belonging to one
     *     offering's latest sync — upsertAttendanceBulk() stamps
     *     meetings AND every summary row (including TERM) from one sync
     *     call with a single shared timestamp — so any period's value
     *     would do; TERM is used because it's always present once any
     *     sync has run.
     *   - An offering with no key has never had an attendance sync run.
     *   - A period entirely absent from an offering's periods{} (e.g.
     *     FINALS hasn't happened yet) means "not yet available" — never
     *     synthesize it as zero.
     */
    public function getAttendanceStats(string $academicYear, string $semester): array
    {
        $db = Database::getConnection();

        // --- Meeting counts, latest sync only, grouped by (offering, period) ---
        $meetingStmt = $db->prepare("
            SELECT m.teacherClassLoadID, m.period,
                   COUNT(DISTINCT m.meetingNo) AS meetingCount
            FROM tblEcrAttendanceMeetings m
            INNER JOIN (
                SELECT teacherClassLoadID, MAX(lastSyncedAt) AS latestSyncedAt
                FROM tblEcrAttendanceMeetings
                GROUP BY teacherClassLoadID
            ) latest ON latest.teacherClassLoadID = m.teacherClassLoadID
                    AND latest.latestSyncedAt = m.lastSyncedAt
            INNER JOIN tblTeacherClassLoads tcl ON tcl.teacherClassLoadID = m.teacherClassLoadID
            INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
            WHERE tsl.academicYear = :ay1 AND tsl.semester = :s1
              AND m.meetingDate IS NOT NULL
            GROUP BY m.teacherClassLoadID, m.period
        ");
        $meetingStmt->execute([':ay1' => $academicYear, ':s1' => $semester]);

        $meetingsByOffering = []; // tcl -> period -> meetingCount
        foreach ($meetingStmt->fetchAll() as $row) {
            $tcl = (string)($row['teacherClassLoadID'] ?? '');
            $period = (string)($row['period'] ?? '');
            if ($tcl === '' || $period === '') continue;
            $meetingsByOffering[$tcl][$period] = (int)($row['meetingCount'] ?? 0);
        }

        // --- Status sums, latest sync only, grouped by (offering, period)
        // — covers every period in self::SUMMARY_PERIODS, including the
        // sheet's own TERM row, in one pass. ---
        $summaryStmt = $db->prepare("
            SELECT s.teacherClassLoadID, s.period,
                   SUM(s.presentCount) AS sumP, SUM(s.lateCount) AS sumL,
                   SUM(s.absentCount) AS sumA, SUM(s.excusedCount) AS sumE,
                   COUNT(DISTINCT CASE
                       WHEN (s.presentCount + s.lateCount + s.absentCount + s.excusedCount) > 0
                       THEN s.studentNumber
                   END) AS studentCount,
                   MAX(s.lastSyncedAt) AS lastSyncedAt
            FROM tblEcrAttendanceSummary s
            INNER JOIN (
                SELECT teacherClassLoadID, MAX(lastSyncedAt) AS latestSyncedAt
                FROM tblEcrAttendanceSummary
                GROUP BY teacherClassLoadID
            ) latest ON latest.teacherClassLoadID = s.teacherClassLoadID
                    AND latest.latestSyncedAt = s.lastSyncedAt
            INNER JOIN tblTeacherClassLoads tcl ON tcl.teacherClassLoadID = s.teacherClassLoadID
            INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
            WHERE tsl.academicYear = :ay2 AND tsl.semester = :s2
            GROUP BY s.teacherClassLoadID, s.period
        ");
        $summaryStmt->execute([':ay2' => $academicYear, ':s2' => $semester]);

        $out = [];
        foreach ($summaryStmt->fetchAll() as $row) {
            $tcl = (string)($row['teacherClassLoadID'] ?? '');
            $period = (string)($row['period'] ?? '');
            if ($tcl === '' || $period === '') continue;

            $raw = [
                'P' => (int)($row['sumP'] ?? 0),
                'L' => (int)($row['sumL'] ?? 0),
                'A' => (int)($row['sumA'] ?? 0),
                'E' => (int)($row['sumE'] ?? 0),
            ];
            $totalMarks = array_sum($raw);

            $rates = [];
            foreach (self::STATUSES as $statusKey) {
                $rates[$statusKey] = $totalMarks > 0 ? round(($raw[$statusKey] / $totalMarks) * 100.0, 1) : null;
            }

            if (!isset($out[$tcl])) {
                $out[$tcl] = ['meetingCount' => 0, 'studentCount' => 0, 'attendanceRate' => null, 'periods' => []];
            }

            // TERM has no row of its own in tblEcrAttendanceMeetings (only
            // the four graded periods do) — its meetingCount is the sum of
            // whatever graded periods this offering has meeting data for.
            $meetingCountForPeriod = $meetingsByOffering[$tcl][$period]
                ?? ($period === 'TERM' ? array_sum($meetingsByOffering[$tcl] ?? []) : 0);

            $out[$tcl]['periods'][$period] = [
                'meetingCount' => $meetingCountForPeriod,
                'studentCount' => (int)($row['studentCount'] ?? 0),
                'raw'          => $raw,
                'rates'        => $rates,
                'lastSyncedAt' => $row['lastSyncedAt'] !== null ? (string)$row['lastSyncedAt'] : null,
            ];
        }

        // Convenience top-level mirrors of the TERM period, for callers
        // that only want the collapsed summary. lastSyncedAt is the same
        // value across every period in a given offering's latest sync
        // (upsertAttendanceBulk() stamps meetings AND every summary row,
        // including TERM, with one shared timestamp per call) — TERM is
        // used here only because it's guaranteed present whenever any
        // sync has ever run for that offering.
        foreach ($out as $tcl => &$entry) {
            $entry['lastSyncedAt'] = null;
            if (isset($entry['periods']['TERM'])) {
                $entry['meetingCount']   = $entry['periods']['TERM']['meetingCount'];
                $entry['studentCount']   = $entry['periods']['TERM']['studentCount'];
                $entry['attendanceRate'] = $entry['periods']['TERM']['rates']['P'];
                $entry['lastSyncedAt']   = $entry['periods']['TERM']['lastSyncedAt'];
            }
        }
        unset($entry);

        return $out;
    }

    // -----------------------------------------------------------------
    // Row shaping
    // -----------------------------------------------------------------

    private function _toMeetingRow(array $row): array
    {
        return [
            'teacherClassLoadID' => (string)($row['teacherClassLoadID'] ?? ''),
            'studentNumber'      => (string)($row['studentNumber'] ?? ''),
            'period'             => (string)($row['period'] ?? ''),
            'meetingNo'          => (int)($row['meetingNo'] ?? 0),
            'meetingDate'        => $row['meetingDate'] !== null ? (string)$row['meetingDate'] : null,
            'status'             => $row['status'] !== null ? (string)$row['status'] : null,
            'lastSyncedAt'       => $row['lastSyncedAt'] !== null ? (string)$row['lastSyncedAt'] : null,
            'lastSyncedBy'       => $row['lastSyncedBy'] !== null ? (string)$row['lastSyncedBy'] : null,
        ];
    }

    private function _toSummaryRow(array $row): array
    {
        return [
            'teacherClassLoadID' => (string)($row['teacherClassLoadID'] ?? ''),
            'studentNumber'      => (string)($row['studentNumber'] ?? ''),
            'period'             => (string)($row['period'] ?? ''),
            'presentCount'       => (int)($row['presentCount'] ?? 0),
            'lateCount'          => (int)($row['lateCount'] ?? 0),
            'absentCount'        => (int)($row['absentCount'] ?? 0),
            'excusedCount'       => (int)($row['excusedCount'] ?? 0),
            'totalCount'         => (float)($row['totalCount'] ?? 0),
            'lastSyncedAt'       => $row['lastSyncedAt'] !== null ? (string)$row['lastSyncedAt'] : null,
            'lastSyncedBy'       => $row['lastSyncedBy'] !== null ? (string)$row['lastSyncedBy'] : null,
        ];
    }

    // -----------------------------------------------------------------
    // Helpers — same conventions as EcrMirrorService.
    // -----------------------------------------------------------------

    private function _normalizeStatus($value): ?string
    {
        $s = strtoupper(trim((string)($value ?? '')));
        return in_array($s, self::STATUSES, true) ? $s : null;
    }

    private function _nullIfBlank($value)
    {
        if ($value === null) return null;
        $normalized = trim((string)$value);
        if ($normalized === '' || strtolower($normalized) === 'null') return null;
        return $value;
    }

    private function _nowString(): string
    {
        return date('Y-m-d H:i:s');
    }
}
