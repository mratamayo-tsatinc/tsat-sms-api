<?php

namespace App\Services;

use App\Core\Database;

/**
 * App\Services\EcrMirrorService
 *
 * Read/write access to the ECR Drive-state mirror (tblEcrTeacherFolders,
 * tblEcrFiles). New file — ECR Drive-State Mirror plan, Phase 2.1.
 *
 * Deliberately contains NO policy — no staleness rules, no decision about
 * when a row "should" be re-synced, no Drive access of any kind. Callers
 * (Ecr.gs, via EcrMirrorController) are the only ones who talk to Drive;
 * this class just persists whatever they found. This is what "the mirror
 * is dumb storage, not policy" (plan §1, principle 1) means in practice.
 *
 * isManualOverride handling: this service always persists exactly the
 * isManualOverride value it's given in $row — it does not infer, protect,
 * or default that flag itself. The plan's "a plain sync must never
 * silently clear an existing override" rule is enforced by WHO calls this
 * service with WHAT value, not by branching in here:
 *   - EcrMirrorController::syncTeacherFolder()/syncFile() (and their bulk
 *     counterparts) pass through whatever the request body sent — Ecr.gs
 *     is responsible for reading the existing mirror row first (Phase 3)
 *     and echoing its isManualOverride back unchanged, EXCEPT for the
 *     dedicated "clear override" sync call (Phase 5.3), which explicitly
 *     sends false.
 *   - EcrMirrorController::overrideTeacherFolder()/overrideFile() always
 *     pass true, regardless of what the request body contains.
 * Keeping this class a pure persist-what-you're-given layer keeps that
 * policy in exactly one place (the controller), per plan §1.
 */
class EcrMirrorService
{
    // -----------------------------------------------------------------
    // Teacher folders
    // -----------------------------------------------------------------

    /**
     * Returns every tblEcrTeacherFolders row for one term.
     * @return array<int, array{teacherID:string, compactTermCode:string,
     *   folderID:?string, folderExists:bool, isShared:bool,
     *   sharedWithEmail:?string, isManualOverride:bool, lastSyncedAt:?string,
     *   lastSyncedBy:?string, createdBy:?string, dateCreated:?string,
     *   modifiedBy:?string, lastModified:?string}>
     */
    public function getTeacherFolders(string $compactTermCode): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT teacherID, compactTermCode, folderID, folderExists, isShared,
                   sharedWithEmail, isManualOverride, lastSyncedAt, lastSyncedBy,
                   createdBy, dateCreated, modifiedBy, lastModified
            FROM tblEcrTeacherFolders
            WHERE compactTermCode = :compactTermCode
            ORDER BY teacherID
        ");
        $stmt->execute([':compactTermCode' => $compactTermCode]);
        return array_map([$this, '_toTeacherFolderRow'], $stmt->fetchAll());
    }

    /**
     * Upserts one tblEcrTeacherFolders row, keyed by (teacherID,
     * compactTermCode). $row keys: teacherID, compactTermCode, folderID,
     * folderExists, isShared, sharedWithEmail, isManualOverride, syncedBy.
     * syncedBy doubles as createdBy on first insert and modifiedBy on
     * every subsequent update, same createdBy/dateCreated vs
     * modifiedBy/lastModified split used throughout this codebase (e.g.
     * TeacherClassLoadController::assignClass()).
     */
    public function upsertTeacherFolder(array $row): void
    {
        $db = Database::getConnection();
        $now = $this->_nowString();
        $syncedBy = $this->_nullIfBlank($row['syncedBy'] ?? null);

        $stmt = $db->prepare("
            INSERT INTO tblEcrTeacherFolders (
                teacherID, compactTermCode, folderID, folderExists, isShared,
                sharedWithEmail, isManualOverride,
                lastSyncedAt, lastSyncedBy,
                createdBy, dateCreated, modifiedBy, lastModified
            ) VALUES (
                :teacherID, :compactTermCode, :folderID, :folderExists, :isShared,
                :sharedWithEmail, :isManualOverride,
                :lastSyncedAt, :lastSyncedBy,
                :createdBy, :dateCreated, NULL, NULL
            )
            ON DUPLICATE KEY UPDATE
                folderID         = VALUES(folderID),
                folderExists     = VALUES(folderExists),
                isShared         = VALUES(isShared),
                sharedWithEmail  = VALUES(sharedWithEmail),
                isManualOverride = VALUES(isManualOverride),
                lastSyncedAt     = VALUES(lastSyncedAt),
                lastSyncedBy     = VALUES(lastSyncedBy),
                modifiedBy       = VALUES(lastSyncedBy),
                lastModified     = VALUES(lastSyncedAt)
        ");
        $stmt->execute([
            ':teacherID'        => (string)$row['teacherID'],
            ':compactTermCode'  => (string)$row['compactTermCode'],
            ':folderID'         => $this->_nullIfBlank($row['folderID'] ?? null),
            ':folderExists'     => $this->_truthy($row['folderExists'] ?? false) ? 1 : 0,
            ':isShared'         => $this->_truthy($row['isShared'] ?? false) ? 1 : 0,
            ':sharedWithEmail'  => $this->_nullIfBlank($row['sharedWithEmail'] ?? null),
            ':isManualOverride' => $this->_truthy($row['isManualOverride'] ?? false) ? 1 : 0,
            ':lastSyncedAt'     => $now,
            ':lastSyncedBy'     => $syncedBy,
            ':createdBy'        => $syncedBy,
            ':dateCreated'      => $now,
        ]);
    }

    /**
     * Upserts many tblEcrTeacherFolders rows in one transaction. Each
     * $rows[] entry has the same shape as upsertTeacherFolder()'s $row
     * (compactTermCode may be supplied once and copied onto every entry
     * by the caller, or included per-row — either is accepted here).
     * @return int Number of rows written.
     */
    public function upsertTeacherFoldersBulk(array $rows): int
    {
        $db = Database::getConnection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();

        try {
            $count = 0;
            foreach ($rows as $row) {
                $this->upsertTeacherFolder($row);
                $count++;
            }
            if ($ownsTransaction) $db->commit();
            return $count;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    // -----------------------------------------------------------------
    // ECR files
    // -----------------------------------------------------------------

    /**
     * Returns every tblEcrFiles row for one term, joined through
     * tblTeacherClassLoads/tblTeacherSubjectLoads to scope by term (the
     * mirror row itself carries no academicYear/semester — teacherClassLoadID
     * already is the per-term key).
     * @return array<int, array{teacherClassLoadID:string, fileID:?string,
     *   fileExists:bool, isShared:bool, level:?string, isManualOverride:bool,
     *   lastSyncedAt:?string, lastSyncedBy:?string, createdBy:?string,
     *   dateCreated:?string, modifiedBy:?string, lastModified:?string}>
     */
    public function getFiles(string $academicYear, string $semester): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT ef.teacherClassLoadID, ef.fileID, ef.fileExists, ef.isShared, ef.level,
                   ef.isManualOverride, ef.lastSyncedAt, ef.lastSyncedBy,
                   ef.createdBy, ef.dateCreated, ef.modifiedBy, ef.lastModified
            FROM tblEcrFiles ef
            INNER JOIN tblTeacherClassLoads tcl ON tcl.teacherClassLoadID = ef.teacherClassLoadID
            INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
            WHERE tsl.academicYear = :academicYear AND tsl.semester = :semester
            ORDER BY ef.teacherClassLoadID
        ");
        $stmt->execute([':academicYear' => $academicYear, ':semester' => $semester]);
        return array_map([$this, '_toFileRow'], $stmt->fetchAll());
    }

    /**
     * Upserts one tblEcrFiles row, keyed by teacherClassLoadID. $row keys:
     * teacherClassLoadID, fileID, fileExists, isShared, level,
     * isManualOverride, syncedBy.
     */
    public function upsertFile(array $row): void
    {
        $db = Database::getConnection();
        $now = $this->_nowString();
        $syncedBy = $this->_nullIfBlank($row['syncedBy'] ?? null);

        $stmt = $db->prepare("
            INSERT INTO tblEcrFiles (
                teacherClassLoadID, fileID, fileExists, isShared, level,
                isManualOverride, lastSyncedAt, lastSyncedBy,
                createdBy, dateCreated, modifiedBy, lastModified
            ) VALUES (
                :teacherClassLoadID, :fileID, :fileExists, :isShared, :level,
                :isManualOverride, :lastSyncedAt, :lastSyncedBy,
                :createdBy, :dateCreated, NULL, NULL
            )
            ON DUPLICATE KEY UPDATE
                fileID           = VALUES(fileID),
                fileExists       = VALUES(fileExists),
                isShared         = VALUES(isShared),
                level            = VALUES(level),
                isManualOverride = VALUES(isManualOverride),
                lastSyncedAt     = VALUES(lastSyncedAt),
                lastSyncedBy     = VALUES(lastSyncedBy),
                modifiedBy       = VALUES(lastSyncedBy),
                lastModified     = VALUES(lastSyncedAt)
        ");
        $stmt->execute([
            ':teacherClassLoadID' => (string)$row['teacherClassLoadID'],
            ':fileID'             => $this->_nullIfBlank($row['fileID'] ?? null),
            ':fileExists'         => $this->_truthy($row['fileExists'] ?? false) ? 1 : 0,
            ':isShared'           => $this->_truthy($row['isShared'] ?? false) ? 1 : 0,
            ':level'              => $this->_nullIfBlank($row['level'] ?? null),
            ':isManualOverride'   => $this->_truthy($row['isManualOverride'] ?? false) ? 1 : 0,
            ':lastSyncedAt'       => $now,
            ':lastSyncedBy'       => $syncedBy,
            ':createdBy'          => $syncedBy,
            ':dateCreated'        => $now,
        ]);
    }

    /**
     * Upserts many tblEcrFiles rows in one transaction.
     * @return int Number of rows written.
     */
    public function upsertFilesBulk(array $rows): int
    {
        $db = Database::getConnection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();

        try {
            $count = 0;
            foreach ($rows as $row) {
                $this->upsertFile($row);
                $count++;
            }
            if ($ownsTransaction) $db->commit();
            return $count;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

	// -----------------------------------------------------------------
    // Roster mirror (Phase 6)
    // -----------------------------------------------------------------

    /**
     * Returns every tblEcrRosterMirror row for one offering.
     * @return array<int, array{teacherClassLoadID:string, studentNumber:string,
     *   ecrStatus:?string, lastSyncedAt:?string, lastSyncedBy:?string}>
     */
    public function getRoster(string $teacherClassLoadID): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT teacherClassLoadID, studentNumber, ecrStatus, lastSyncedAt, lastSyncedBy
            FROM tblEcrRosterMirror
            WHERE teacherClassLoadID = :teacherClassLoadID
            ORDER BY studentNumber
        ");
        $stmt->execute([':teacherClassLoadID' => $teacherClassLoadID]);
        return array_map([$this, '_toRosterRow'], $stmt->fetchAll());
    }

    /**
     * Returns, per offering, how many tblEcrRosterMirror rows exist for
     * one term, broken down by ecrStatus. This is what's ACTUALLY
     * mirrored from the ECR Google Sheet — not the officially enrolled
     * count from tblStudentSubjectEnrollments (that's a separate figure,
     * already carried on each teacher-class-loads row as enrolledCount;
     * see EcrReferenceDataService's docblock). The ECR list UI shows both
     * side by side so an officer can spot an offering whose ECR file has
     * drifted from the official roster — WITHOUT live-scanning every
     * sheet, since the mirror is the read source (plan §1).
     *
     * Scoped the same way getFiles() is: the roster mirror itself carries
     * no academicYear/semester, so this joins through
     * tblTeacherClassLoads/tblTeacherSubjectLoads to scope by term.
     *
     * @return array<string, array{total:int, ENROLLED:int, DROPPED:int,
     *   CREDITED:int, UNSPECIFIED:int}> keyed by teacherClassLoadID.
     *   Offerings with no mirrored rows simply have no entry — callers
     *   should treat a missing key as all-zero. Any ecrStatus value
     *   outside ENROLLED/DROPPED/CREDITED (including blank/NULL) is
     *   bucketed under UNSPECIFIED rather than dropped, so the total
     *   always reconciles with the sum of the buckets.
     */
    public function getRosterCounts(string $academicYear, string $semester): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT erm.teacherClassLoadID, erm.ecrStatus, COUNT(*) AS cnt
            FROM tblEcrRosterMirror erm
            INNER JOIN tblTeacherClassLoads tcl ON tcl.teacherClassLoadID = erm.teacherClassLoadID
            INNER JOIN tblTeacherSubjectLoads tsl ON tsl.teacherSubjectLoadID = tcl.teacherSubjectLoadID
            WHERE tsl.academicYear = :academicYear AND tsl.semester = :semester
            GROUP BY erm.teacherClassLoadID, erm.ecrStatus
        ");
        $stmt->execute([':academicYear' => $academicYear, ':semester' => $semester]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $teacherClassLoadID = (string)($row['teacherClassLoadID'] ?? '');
            if ($teacherClassLoadID === '') continue;

            if (!isset($out[$teacherClassLoadID])) {
                $out[$teacherClassLoadID] = [
                    'total' => 0, 'ENROLLED' => 0, 'DROPPED' => 0, 'CREDITED' => 0, 'UNSPECIFIED' => 0,
                ];
            }

            $status = strtoupper(trim((string)($row['ecrStatus'] ?? '')));
            $bucket = in_array($status, ['ENROLLED', 'DROPPED', 'CREDITED'], true) ? $status : 'UNSPECIFIED';

            $cnt = (int)($row['cnt'] ?? 0);
            $out[$teacherClassLoadID][$bucket] += $cnt;
            $out[$teacherClassLoadID]['total'] += $cnt;
        }

        return $out;
    }

    /**
     * Upserts ONE tblEcrRosterMirror row, keyed by (teacherClassLoadID,
     * studentNumber). INCREMENTAL write — used by
     * updateEcrStudentStatus() in Ecr.gs to mirror the one row it just
     * changed inline (Phase 4.2 pattern), NOT for a full roster sync —
     * see replaceRosterBulk() below for that.
     * $row keys: teacherClassLoadID, studentNumber, ecrStatus, syncedBy.
     */
    public function upsertRosterRow(array $row): void
    {
        $db = Database::getConnection();
        $now = $this->_nowString();
        $syncedBy = $this->_nullIfBlank($row['syncedBy'] ?? null);

        $stmt = $db->prepare("
            INSERT INTO tblEcrRosterMirror (
                teacherClassLoadID, studentNumber, ecrStatus, lastSyncedAt, lastSyncedBy
            ) VALUES (
                :teacherClassLoadID, :studentNumber, :ecrStatus, :lastSyncedAt, :lastSyncedBy
            )
            ON DUPLICATE KEY UPDATE
                ecrStatus     = VALUES(ecrStatus),
                lastSyncedAt  = VALUES(lastSyncedAt),
                lastSyncedBy  = VALUES(lastSyncedBy)
        ");
        $stmt->execute([
            ':teacherClassLoadID' => (string)$row['teacherClassLoadID'],
            ':studentNumber'      => (string)$row['studentNumber'],
            ':ecrStatus'          => $this->_nullIfBlank($row['ecrStatus'] ?? null),
            ':lastSyncedAt'       => $now,
            ':lastSyncedBy'       => $syncedBy,
        ]);
    }

    /**
     * Upserts many tblEcrRosterMirror rows in one transaction — same
     * INCREMENTAL semantics as upsertRosterRow(), batched. Used by
     * addStudentsToEcrFile() in Ecr.gs right after it writes several new
     * student rows into the sheet.
     * @return int Number of rows written.
     */
    public function upsertRosterRowsBulk(array $rows): int
    {
        $db = Database::getConnection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();

        try {
            $count = 0;
            foreach ($rows as $row) {
                $this->upsertRosterRow($row);
                $count++;
            }
            if ($ownsTransaction) $db->commit();
            return $count;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Replaces the ENTIRE mirror roster for one offering with $rows — a
     * FULL-SNAPSHOT sync, not an incremental upsert. Unlike the
     * folder/file mirrors, an explicit roster sync means "this is
     * exactly every student currently in the ECR file for this
     * offering" — a student removed from the sheet since the last sync
     * must not linger in the mirror as a stale row. Deletes every
     * existing row for this teacherClassLoadID, then inserts $rows, all
     * in one transaction. This is the write path for Ecr.gs's
     * syncEcrRoster() (the officer's explicit "Sync roster" click) —
     * NOT for the incremental add/status-update paths above.
     * $rows entries: { studentNumber, ecrStatus }.
     * @return int Number of rows written.
     */
    public function replaceRosterBulk(string $teacherClassLoadID, array $rows, ?string $syncedBy): int
    {
        $db = Database::getConnection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();

        try {
            $del = $db->prepare("DELETE FROM tblEcrRosterMirror WHERE teacherClassLoadID = :teacherClassLoadID");
            $del->execute([':teacherClassLoadID' => $teacherClassLoadID]);

            $now = $this->_nowString();
            $syncedByNormalized = $this->_nullIfBlank($syncedBy);
            $ins = $db->prepare("
                INSERT INTO tblEcrRosterMirror (teacherClassLoadID, studentNumber, ecrStatus, lastSyncedAt, lastSyncedBy)
                VALUES (:teacherClassLoadID, :studentNumber, :ecrStatus, :lastSyncedAt, :lastSyncedBy)
            ");

            $count = 0;
            foreach ($rows as $row) {
                $studentNumber = trim((string)($row['studentNumber'] ?? ''));
                if ($studentNumber === '') continue;
                $ins->execute([
                    ':teacherClassLoadID' => $teacherClassLoadID,
                    ':studentNumber'      => $studentNumber,
                    ':ecrStatus'          => $this->_nullIfBlank($row['ecrStatus'] ?? null),
                    ':lastSyncedAt'       => $now,
                    ':lastSyncedBy'       => $syncedByNormalized,
                ]);
                $count++;
            }

            if ($ownsTransaction) $db->commit();
            return $count;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
	
	
    // -----------------------------------------------------------------
    // Row shaping — DB row -> API-shaped array (bools as bools, blank
    // strings normalized the same way as the rest of this codebase).
    // -----------------------------------------------------------------

    private function _toTeacherFolderRow(array $row): array
    {
        return [
            'teacherID'        => (string)($row['teacherID'] ?? ''),
            'compactTermCode'  => (string)($row['compactTermCode'] ?? ''),
            'folderID'         => $row['folderID'] !== null ? (string)$row['folderID'] : null,
            'folderExists'     => (bool)((int)($row['folderExists'] ?? 0)),
            'isShared'         => (bool)((int)($row['isShared'] ?? 0)),
            'sharedWithEmail'  => $row['sharedWithEmail'] !== null ? (string)$row['sharedWithEmail'] : null,
            'isManualOverride' => (bool)((int)($row['isManualOverride'] ?? 0)),
            'lastSyncedAt'     => $row['lastSyncedAt'] !== null ? (string)$row['lastSyncedAt'] : null,
            'lastSyncedBy'     => $row['lastSyncedBy'] !== null ? (string)$row['lastSyncedBy'] : null,
            'createdBy'        => $row['createdBy'] !== null ? (string)$row['createdBy'] : null,
            'dateCreated'      => $row['dateCreated'] !== null ? (string)$row['dateCreated'] : null,
            'modifiedBy'       => $row['modifiedBy'] !== null ? (string)$row['modifiedBy'] : null,
            'lastModified'     => $row['lastModified'] !== null ? (string)$row['lastModified'] : null,
        ];
    }

    private function _toFileRow(array $row): array
    {
        return [
            'teacherClassLoadID' => (string)($row['teacherClassLoadID'] ?? ''),
            'fileID'             => $row['fileID'] !== null ? (string)$row['fileID'] : null,
            'fileExists'         => (bool)((int)($row['fileExists'] ?? 0)),
            'isShared'           => (bool)((int)($row['isShared'] ?? 0)),
            'level'              => $row['level'] !== null ? (string)$row['level'] : null,
            'isManualOverride'   => (bool)((int)($row['isManualOverride'] ?? 0)),
            'lastSyncedAt'       => $row['lastSyncedAt'] !== null ? (string)$row['lastSyncedAt'] : null,
            'lastSyncedBy'       => $row['lastSyncedBy'] !== null ? (string)$row['lastSyncedBy'] : null,
            'createdBy'          => $row['createdBy'] !== null ? (string)$row['createdBy'] : null,
            'dateCreated'        => $row['dateCreated'] !== null ? (string)$row['dateCreated'] : null,
            'modifiedBy'         => $row['modifiedBy'] !== null ? (string)$row['modifiedBy'] : null,
            'lastModified'       => $row['lastModified'] !== null ? (string)$row['lastModified'] : null,
        ];
    }

	private function _toRosterRow(array $row): array
    {
        return [
            'teacherClassLoadID' => (string)($row['teacherClassLoadID'] ?? ''),
            'studentNumber'      => (string)($row['studentNumber'] ?? ''),
            'ecrStatus'          => $row['ecrStatus'] !== null ? (string)$row['ecrStatus'] : null,
            'lastSyncedAt'       => $row['lastSyncedAt'] !== null ? (string)$row['lastSyncedAt'] : null,
            'lastSyncedBy'       => $row['lastSyncedBy'] !== null ? (string)$row['lastSyncedBy'] : null,
        ];
    }
	
    // -----------------------------------------------------------------
    // Helpers — same blank-to-NULL / truthy conventions used elsewhere in
    // this codebase (e.g. StudentSubjectEnrollmentController::_nullIfBlank(),
    // ::_truthy()).
    // -----------------------------------------------------------------

    private function _nullIfBlank($value)
    {
        if ($value === null) return null;
        $normalized = trim((string)$value);
        if ($normalized === '' || strtolower($normalized) === 'null') return null;
        return $value;
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
}
