<?php

namespace App\Controllers\Ecr;

use App\Services\EcrMirrorService;

/**
 * App\Controllers\Ecr\EcrMirrorController
 *
 * Read/write endpoints for the ECR Drive-state mirror
 * (tblEcrTeacherFolders, tblEcrFiles). New file — ECR Drive-State Mirror
 * plan, Phase 2.2/2.3. Every public method wraps its entire body in
 * try/catch, matching every other controller in this codebase (e.g.
 * TeacherClassLoadController) — no exception is ever allowed to
 * propagate to index.php. Every response follows the { ok: bool, ... }
 * envelope.
 *
 * This controller has no Drive knowledge of its own. Every field it
 * writes was already resolved against live Drive by Ecr.gs before the
 * request was ever made; this class only checks shape (§2.4 —
 * _looksLikeDriveId()) and persists via EcrMirrorService, which is
 * itself pure storage with no policy (see EcrMirrorService's docblock).
 *
 * Sync vs. override — the only place the isManualOverride "don't clear
 * it unless told to" rule is decided:
 *   - syncTeacherFolder()/syncFile() (and their *Bulk() counterparts)
 *     pass isManualOverride straight through from the request body.
 *     Ecr.gs is responsible for echoing back the mirror's existing value
 *     (Phase 3), or explicitly sending false for the dedicated "clear
 *     override" call (Phase 5.3).
 *   - overrideTeacherFolder()/overrideFile() always force it to true,
 *     regardless of the request body.
 */
class EcrMirrorController
{
    // ═══════════════════════════════════════════════════════════════
    // Reads
    // ═══════════════════════════════════════════════════════════════

    /**
     * GET /api/ecr/mirror/teacher-folders?compactTermCode=202627S1
     * { ok:true, teacherFolders: [{teacherID, compactTermCode, folderID,
     *   folderExists, isShared, sharedWithEmail, isManualOverride,
     *   lastSyncedAt, lastSyncedBy, ...}] }
     */
    public function teacherFolders()
    {
        try {
            $compactTermCode = trim($_GET['compactTermCode'] ?? '');
            if ($compactTermCode === '') {
                $this->_validationError('compactTermCode is required.', ['compactTermCode' => 'Required.']);
                return;
            }

            $rows = (new EcrMirrorService())->getTeacherFolders($compactTermCode);
            echo json_encode(['ok' => true, 'teacherFolders' => $rows]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/ecr/mirror/files?academicYear=...&semester=...
     * { ok:true, files: [{teacherClassLoadID, fileID, fileExists,
     *   isShared, level, isManualOverride, lastSyncedAt, lastSyncedBy, ...}] }
     */
    public function files()
    {
        try {
            $academicYear = trim($_GET['academicYear'] ?? '');
            $semester     = trim($_GET['semester'] ?? '');
            if ($academicYear === '' || $semester === '') {
                $this->_validationError('academicYear and semester are required.', [
                    'academicYear' => 'Required.',
                    'semester'     => 'Required.',
                ]);
                return;
            }

            $rows = (new EcrMirrorService())->getFiles($academicYear, $semester);
            echo json_encode(['ok' => true, 'files' => $rows]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * GET /api/ecr/mirror/roster-counts?academicYear=...&semester=...
     * { ok:true, counts: { [teacherClassLoadID]: {total, ENROLLED,
     *   DROPPED, CREDITED, UNSPECIFIED} } }
     *
     * Aggregated, per-offering breakdown of what's actually mirrored
     * from the ECR Google Sheet (tblEcrRosterMirror) for the WHOLE term
     * in one call — powers the ECR list's enrolled-count column, so the
     * officer can compare the ECR file's real contents against the
     * officially enrolled count (already on each offering row as
     * enrolledCount) without fetching every offering's full roster
     * individually. An offering with no key in the response has no
     * mirrored rows at all (never synced / empty file).
     */
    public function rosterCounts()
    {
        try {
            $academicYear = trim($_GET['academicYear'] ?? '');
            $semester     = trim($_GET['semester'] ?? '');
            if ($academicYear === '' || $semester === '') {
                $this->_validationError('academicYear and semester are required.', [
                    'academicYear' => 'Required.',
                    'semester'     => 'Required.',
                ]);
                return;
            }

            $counts = (new EcrMirrorService())->getRosterCounts($academicYear, $semester);
            echo json_encode(['ok' => true, 'counts' => $counts]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Sync — atomic + bulk. isManualOverride is passed through as-is;
    // see class docblock.
    // ═══════════════════════════════════════════════════════════════

    /**
     * POST /api/ecr/mirror/teacher-folders/sync   (ATOMIC — one teacher)
     * Body: { teacherID, compactTermCode, folderID, folderExists,
     *         isShared, sharedWithEmail?, isManualOverride, syncedBy }
     */
    public function syncTeacherFolder()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherID = trim((string)($body['teacherID'] ?? ''));
            $compactTermCode = trim((string)($body['compactTermCode'] ?? ''));

            $fieldErrors = [];
            if ($teacherID === '') $fieldErrors['teacherID'] = 'Required.';
            if ($compactTermCode === '') $fieldErrors['compactTermCode'] = 'Required.';
            if ($fieldErrors) {
                $this->_validationError('teacherID and compactTermCode are required.', $fieldErrors);
                return;
            }

            $folderIdError = $this->_checkDriveIdShape($body['folderID'] ?? null, 'folderID');
            if ($folderIdError !== null) {
                $this->_invalidDriveId($folderIdError, 'folderID');
                return;
            }

            (new EcrMirrorService())->upsertTeacherFolder([
                'teacherID'        => $teacherID,
                'compactTermCode'  => $compactTermCode,
                'folderID'         => $body['folderID'] ?? null,
                'folderExists'     => $body['folderExists'] ?? false,
                'isShared'         => $body['isShared'] ?? false,
                'sharedWithEmail'  => $body['sharedWithEmail'] ?? null,
                'isManualOverride' => $body['isManualOverride'] ?? false,
                'syncedBy'         => $body['syncedBy'] ?? null,
            ]);

            echo json_encode(['ok' => true, 'message' => 'Teacher folder mirror synced.']);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/ecr/mirror/teacher-folders/sync-bulk   (GLOBAL — many)
     * Body: { compactTermCode, rows: [ {teacherID, folderID, folderExists,
     *         isShared, sharedWithEmail?, isManualOverride}, ... ], syncedBy }
     * Transactional — all rows write, or none do.
     */
    public function syncTeacherFoldersBulk()
    {
        try {
            $body = $this->_readJsonBody();

            $compactTermCode = trim((string)($body['compactTermCode'] ?? ''));
            if ($compactTermCode === '') {
                $this->_validationError('compactTermCode is required.', ['compactTermCode' => 'Required.']);
                return;
            }

            $inputRows = $body['rows'] ?? [];
            if (!is_array($inputRows) || count($inputRows) === 0) {
                $this->_validationError('At least one row is required.', ['rows' => 'Required.']);
                return;
            }

            $syncedBy = $body['syncedBy'] ?? null;
            $rows = [];
            foreach ($inputRows as $r) {
                if (!is_array($r)) continue;
                $teacherID = trim((string)($r['teacherID'] ?? ''));
                if ($teacherID === '') continue;

                $folderIdError = $this->_checkDriveIdShape($r['folderID'] ?? null, 'folderID');
                if ($folderIdError !== null) {
                    $this->_invalidDriveId('One or more rows have an invalid folderID: ' . $folderIdError, 'folderID');
                    return;
                }

                $rows[] = [
                    'teacherID'        => $teacherID,
                    'compactTermCode'  => $compactTermCode,
                    'folderID'         => $r['folderID'] ?? null,
                    'folderExists'     => $r['folderExists'] ?? false,
                    'isShared'         => $r['isShared'] ?? false,
                    'sharedWithEmail'  => $r['sharedWithEmail'] ?? null,
                    'isManualOverride' => $r['isManualOverride'] ?? false,
                    'syncedBy'         => $syncedBy,
                ];
            }

            if (!$rows) {
                $this->_validationError('No valid rows were provided.', ['rows' => 'Required.']);
                return;
            }

            $written = (new EcrMirrorService())->upsertTeacherFoldersBulk($rows);
            echo json_encode([
                'ok'      => true,
                'written' => $written,
                'message' => $written . ' teacher folder(s) synced.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/ecr/mirror/files/sync   (ATOMIC — one offering)
     * Body: { teacherClassLoadID, fileID, fileExists, isShared, level,
     *         isManualOverride, syncedBy }
     */
    public function syncFile()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherClassLoadID = trim((string)($body['teacherClassLoadID'] ?? ''));
            if ($teacherClassLoadID === '') {
                $this->_validationError('teacherClassLoadID is required.', ['teacherClassLoadID' => 'Required.']);
                return;
            }

            $fileIdError = $this->_checkDriveIdShape($body['fileID'] ?? null, 'fileID');
            if ($fileIdError !== null) {
                $this->_invalidDriveId($fileIdError, 'fileID');
                return;
            }

            (new EcrMirrorService())->upsertFile([
                'teacherClassLoadID' => $teacherClassLoadID,
                'fileID'             => $body['fileID'] ?? null,
                'fileExists'         => $body['fileExists'] ?? false,
                'isShared'           => $body['isShared'] ?? false,
                'level'              => $body['level'] ?? null,
                'isManualOverride'   => $body['isManualOverride'] ?? false,
                'syncedBy'           => $body['syncedBy'] ?? null,
            ]);

            echo json_encode(['ok' => true, 'message' => 'ECR file mirror synced.']);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/ecr/mirror/files/sync-bulk   (GLOBAL — many)
     * Body: { rows: [ {teacherClassLoadID, fileID, fileExists, isShared,
     *         level, isManualOverride}, ... ], syncedBy }
     * Transactional — all rows write, or none do.
     */
    public function syncFilesBulk()
    {
        try {
            $body = $this->_readJsonBody();

            $inputRows = $body['rows'] ?? [];
            if (!is_array($inputRows) || count($inputRows) === 0) {
                $this->_validationError('At least one row is required.', ['rows' => 'Required.']);
                return;
            }

            $syncedBy = $body['syncedBy'] ?? null;
            $rows = [];
            foreach ($inputRows as $r) {
                if (!is_array($r)) continue;
                $teacherClassLoadID = trim((string)($r['teacherClassLoadID'] ?? ''));
                if ($teacherClassLoadID === '') continue;

                $fileIdError = $this->_checkDriveIdShape($r['fileID'] ?? null, 'fileID');
                if ($fileIdError !== null) {
                    $this->_invalidDriveId('One or more rows have an invalid fileID: ' . $fileIdError, 'fileID');
                    return;
                }

                $rows[] = [
                    'teacherClassLoadID' => $teacherClassLoadID,
                    'fileID'             => $r['fileID'] ?? null,
                    'fileExists'         => $r['fileExists'] ?? false,
                    'isShared'           => $r['isShared'] ?? false,
                    'level'              => $r['level'] ?? null,
                    'isManualOverride'   => $r['isManualOverride'] ?? false,
                    'syncedBy'           => $syncedBy,
                ];
            }

            if (!$rows) {
                $this->_validationError('No valid rows were provided.', ['rows' => 'Required.']);
                return;
            }

            $written = (new EcrMirrorService())->upsertFilesBulk($rows);
            echo json_encode([
                'ok'      => true,
                'written' => $written,
                'message' => $written . ' ECR file(s) synced.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

 	// ═══════════════════════════════════════════════════════════════
    // Roster mirror (Phase 6). No override endpoints — roster rows are
    // never manually corrected the way a folder/file ID is; a wrong
    // roster row is fixed by re-syncing, not overriding.
    // ═══════════════════════════════════════════════════════════════

    /**
     * GET /api/ecr/mirror/roster?teacherClassLoadID=...
     * { ok:true, roster: [{teacherClassLoadID, studentNumber, ecrStatus,
     *   lastSyncedAt, lastSyncedBy}] }
     */
    public function roster()
    {
        try {
            $teacherClassLoadID = trim($_GET['teacherClassLoadID'] ?? '');
            if ($teacherClassLoadID === '') {
                $this->_validationError('teacherClassLoadID is required.', ['teacherClassLoadID' => 'Required.']);
                return;
            }

            $rows = (new EcrMirrorService())->getRoster($teacherClassLoadID);
            echo json_encode(['ok' => true, 'roster' => $rows]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/ecr/mirror/roster/sync-bulk   (FULL SNAPSHOT — one offering)
     * Body: { teacherClassLoadID, rows: [ {studentNumber, ecrStatus}, ... ], syncedBy }
     * Replaces the ENTIRE mirror roster for this offering — a student
     * not present in rows is understood to no longer be in the ECR file
     * and is removed from the mirror. An EMPTY rows array is valid (a
     * genuinely empty ECR file) and correctly clears stale rows — unlike
     * the folder/file bulk endpoints, that is NOT a validation error
     * here.
     */
    public function syncRosterBulk()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherClassLoadID = trim((string)($body['teacherClassLoadID'] ?? ''));
            if ($teacherClassLoadID === '') {
                $this->_validationError('teacherClassLoadID is required.', ['teacherClassLoadID' => 'Required.']);
                return;
            }

            $inputRows = $body['rows'] ?? [];
            if (!is_array($inputRows)) {
                $this->_validationError('rows must be an array.', ['rows' => 'Must be an array.']);
                return;
            }

            $syncedBy = $body['syncedBy'] ?? null;
            $rows = [];
            foreach ($inputRows as $r) {
                if (!is_array($r)) continue;
                $studentNumber = trim((string)($r['studentNumber'] ?? ''));
                if ($studentNumber === '') continue;
                $rows[] = [
                    'studentNumber' => $studentNumber,
                    'ecrStatus'     => $r['ecrStatus'] ?? null,
                ];
            }

            $written = (new EcrMirrorService())->replaceRosterBulk($teacherClassLoadID, $rows, $syncedBy);
            echo json_encode([
                'ok'      => true,
                'written' => $written,
                'message' => $written . ' roster row(s) synced.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/ecr/mirror/roster/upsert   (ATOMIC — one student)
     * Body: { teacherClassLoadID, studentNumber, ecrStatus, syncedBy }
     * Incremental write — mirrors updateEcrStudentStatus()'s own sheet
     * write inline (Phase 4.2 pattern). Never deletes anything.
     */
    public function upsertRosterRow()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherClassLoadID = trim((string)($body['teacherClassLoadID'] ?? ''));
            $studentNumber = trim((string)($body['studentNumber'] ?? ''));

            $fieldErrors = [];
            if ($teacherClassLoadID === '') $fieldErrors['teacherClassLoadID'] = 'Required.';
            if ($studentNumber === '') $fieldErrors['studentNumber'] = 'Required.';
            if ($fieldErrors) {
                $this->_validationError('teacherClassLoadID and studentNumber are required.', $fieldErrors);
                return;
            }

            (new EcrMirrorService())->upsertRosterRow([
                'teacherClassLoadID' => $teacherClassLoadID,
                'studentNumber'      => $studentNumber,
                'ecrStatus'          => $body['ecrStatus'] ?? null,
                'syncedBy'           => $body['syncedBy'] ?? null,
            ]);

            echo json_encode(['ok' => true, 'message' => 'Roster row synced.']);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/ecr/mirror/roster/upsert-bulk   (INCREMENTAL — many rows)
     * Body: { rows: [ {teacherClassLoadID, studentNumber, ecrStatus}, ... ], syncedBy }
     * Mirrors addStudentsToEcrFile()'s own sheet writes inline (Phase
     * 4.2 pattern). Only adds/updates the given rows — never deletes.
     */
    public function upsertRosterRowsBulk()
    {
        try {
            $body = $this->_readJsonBody();

            $inputRows = $body['rows'] ?? [];
            if (!is_array($inputRows) || count($inputRows) === 0) {
                $this->_validationError('At least one row is required.', ['rows' => 'Required.']);
                return;
            }

            $syncedBy = $body['syncedBy'] ?? null;
            $rows = [];
            foreach ($inputRows as $r) {
                if (!is_array($r)) continue;
                $teacherClassLoadID = trim((string)($r['teacherClassLoadID'] ?? ''));
                $studentNumber = trim((string)($r['studentNumber'] ?? ''));
                if ($teacherClassLoadID === '' || $studentNumber === '') continue;

                $rows[] = [
                    'teacherClassLoadID' => $teacherClassLoadID,
                    'studentNumber'      => $studentNumber,
                    'ecrStatus'          => $r['ecrStatus'] ?? null,
                    'syncedBy'           => $syncedBy,
                ];
            }

            if (!$rows) {
                $this->_validationError('No valid rows were provided.', ['rows' => 'Required.']);
                return;
            }

            $written = (new EcrMirrorService())->upsertRosterRowsBulk($rows);
            echo json_encode([
                'ok'      => true,
                'written' => $written,
                'message' => $written . ' roster row(s) synced.',
            ]);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }
    
 

    // ═══════════════════════════════════════════════════════════════
    // Manual override — always forces isManualOverride = true. PHP only
    // checks shape (§2.4); the real validation (does this ID resolve, and
    // to the right type — folder vs. file) already happened in Apps
    // Script via DriveApp.getFolderById()/getFileById() before this
    // endpoint was ever called.
    // ═══════════════════════════════════════════════════════════════

    /**
     * POST /api/ecr/mirror/teacher-folders/override
     * Body: { teacherID, compactTermCode, folderID, folderExists, isShared,
     *         sharedWithEmail?, overriddenBy }
     */
    public function overrideTeacherFolder()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherID = trim((string)($body['teacherID'] ?? ''));
            $compactTermCode = trim((string)($body['compactTermCode'] ?? ''));
            $folderID = trim((string)($body['folderID'] ?? ''));

            $fieldErrors = [];
            if ($teacherID === '') $fieldErrors['teacherID'] = 'Required.';
            if ($compactTermCode === '') $fieldErrors['compactTermCode'] = 'Required.';
            if ($folderID === '') $fieldErrors['folderID'] = 'Required.';
            if ($fieldErrors) {
                $this->_validationError('teacherID, compactTermCode, and folderID are required.', $fieldErrors);
                return;
            }

            if (!$this->_looksLikeDriveId($folderID)) {
                $this->_invalidDriveId('folderID does not look like a valid Drive ID.', 'folderID');
                return;
            }

            (new EcrMirrorService())->upsertTeacherFolder([
                'teacherID'        => $teacherID,
                'compactTermCode'  => $compactTermCode,
                'folderID'         => $folderID,
                'folderExists'     => $body['folderExists'] ?? true,
                'isShared'         => $body['isShared'] ?? false,
                'sharedWithEmail'  => $body['sharedWithEmail'] ?? null,
                'isManualOverride' => true,
                'syncedBy'         => $body['overriddenBy'] ?? null,
            ]);

            echo json_encode(['ok' => true, 'message' => 'Teacher folder overridden.']);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    /**
     * POST /api/ecr/mirror/files/override
     * Body: { teacherClassLoadID, fileID, fileExists, isShared, level,
     *         overriddenBy }
     */
    public function overrideFile()
    {
        try {
            $body = $this->_readJsonBody();

            $teacherClassLoadID = trim((string)($body['teacherClassLoadID'] ?? ''));
            $fileID = trim((string)($body['fileID'] ?? ''));

            $fieldErrors = [];
            if ($teacherClassLoadID === '') $fieldErrors['teacherClassLoadID'] = 'Required.';
            if ($fileID === '') $fieldErrors['fileID'] = 'Required.';
            if ($fieldErrors) {
                $this->_validationError('teacherClassLoadID and fileID are required.', $fieldErrors);
                return;
            }

            if (!$this->_looksLikeDriveId($fileID)) {
                $this->_invalidDriveId('fileID does not look like a valid Drive ID.', 'fileID');
                return;
            }

            (new EcrMirrorService())->upsertFile([
                'teacherClassLoadID' => $teacherClassLoadID,
                'fileID'             => $fileID,
                'fileExists'         => $body['fileExists'] ?? true,
                'isShared'           => $body['isShared'] ?? false,
                'level'              => $body['level'] ?? null,
                'isManualOverride'   => true,
                'syncedBy'           => $body['overriddenBy'] ?? null,
            ]);

            echo json_encode(['ok' => true, 'message' => 'ECR file overridden.']);
        } catch (\Throwable $e) {
            $this->_serverError($e);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    // Real Drive IDs are typically 25-44 chars of [A-Za-z0-9_-]. Be
    // generous with the length bound rather than brittle (§2.4). This is
    // a cheap defense against garbage input (empty string, a pasted full
    // Drive URL, SQL-hostile characters) — not real validation. The real
    // validation already happened in Apps Script before this endpoint was
    // ever reached (see class docblock).
    private function _looksLikeDriveId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{10,100}$/', trim($id));
    }

    // Shared shape check for the plain sync endpoints, where the ID is
    // OPTIONAL (a sync that found nothing sends folderID/fileID as
    // null/blank, meaning "doesn't exist" — that's valid, not an error).
    // Only rejects a NON-blank value that fails the shape check. Returns
    // an error message, or null if the value is acceptable.
    private function _checkDriveIdShape($value, string $fieldLabel): ?string
    {
        $id = trim((string)($value ?? ''));
        if ($id === '') return null; // blank is fine here — means "not found"
        if (!$this->_looksLikeDriveId($id)) {
            return $fieldLabel . ' does not look like a valid Drive ID.';
        }
        return null;
    }

    private function _readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function _validationError(string $message, array $fields = []): void
    {
        http_response_code(400);
        $error = ['code' => 'VALIDATION_ERROR', 'message' => $message];
        if ($fields) $error['fields'] = $fields;
        echo json_encode(['ok' => false, 'error' => $error]);
    }

    // 422 VALIDATION_ERROR / code: 'INVALID_DRIVE_ID', per §2.4.
    private function _invalidDriveId(string $message, string $fieldName): void
    {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => [
            'code'    => 'INVALID_DRIVE_ID',
            'message' => $message,
            'fields'  => [$fieldName => 'INVALID_DRIVE_ID'],
        ]]);
    }

    private function _serverError(\Throwable $e): void
    {
        error_log('[Ecr][EcrMirrorController] ' . get_class($e) . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());

        http_response_code(500);
        $payload = ['ok' => false, 'error' => [
            'code' => 'SERVER_ERROR',
            'message' => 'An unexpected error occurred.',
        ]];

        if (($_GET['debug'] ?? '') === '1') {
            $payload['debug'] = [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ];
        }

        echo json_encode($payload);
    }
}
