<?php

namespace App\Controllers;

use App\Core\Database;

// ─────────────────────────────────────────────────────────────────────────
// EditLockController
//
// Manages per-record edit locks using tblEditLocks.
// studentNumber is the PRIMARY KEY, so each acquire operation touches
// exactly one row. SELECT ... FOR UPDATE serializes concurrent acquire
// attempts on the same studentNumber without locking unrelated rows.
//
// Acquire logic:
//   - No row, or expired row  → acquire (insert/overwrite)
//   - Active row, same owner  → refresh TTL, return sessionToken
//   - Active row, other owner → 409
//
// No nested-transaction risk — acquire() opens and closes the only
// transaction in this class.
// ─────────────────────────────────────────────────────────────────────────
class EditLockController
{
    private const TTL_MINUTES = 30;

    // ─────────────────────────────────────────────────────────────────
    // POST /api/admission/lock/acquire
    // Body: { studentNumber, email }
    //
    // Acquire logic:
    //   - No existing row, or row expired  → acquire (insert/overwrite)
    //   - Existing row, same owner, active → refresh TTL, return owned:true
    //   - Existing row, different owner, active → 409, do not acquire
    // ─────────────────────────────────────────────────────────────────
    public function acquire()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentNumber = trim((string)($input['studentNumber'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));

        if ($studentNumber === '') { http_response_code(400); echo json_encode(['error' => 'studentNumber is required.']); return; }
        if ($email === '')         { http_response_code(400); echo json_encode(['error' => 'email is required.']); return; }

        $db = Database::getConnection();

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT * FROM tblEditLocks WHERE studentNumber = ? FOR UPDATE");
            $stmt->execute([$studentNumber]);
            $row = $stmt->fetch();

            $now = new \DateTime('now');
            $nowStr = $now->format('Y-m-d H:i:s');

            if ($row) {
                $expiresAt = new \DateTime((string)$row['expiresAt']);
                if ($expiresAt > $now) {
                    // Active lock exists.
                    if (strcasecmp((string)$row['lockedByEmail'], $email) === 0) {
                        // Same owner reopening the record — refresh TTL only.
                        $newExpires = (clone $now)->modify('+' . self::TTL_MINUTES . ' minutes');
                        $upd = $db->prepare("UPDATE tblEditLocks SET expiresAt = ? WHERE studentNumber = ?");
                        $upd->execute([$newExpires->format('Y-m-d H:i:s'), $studentNumber]);
                        $db->commit();

                        echo json_encode([
                            'ok' => true, 'owned' => true,
                            'sessionToken' => $row['sessionToken'],
                            'lockedAt' => $row['lockedAt'],
                            'expiresAt' => $newExpires->format('Y-m-d H:i:s'),
                            'expiresAtMs' => $newExpires->getTimestamp() * 1000,
                            'message' => 'Edit lock refreshed.',
                        ]);
                        return;
                    }

                    // Locked by someone else — reject, release the row lock.
                    $db->rollBack();
                    http_response_code(409);
                    echo json_encode(['error' =>
                        'This record is currently being edited by ' . $row['lockedByEmail'] .
                        '. Please wait for them to finish or for the lock to expire (' . $row['expiresAt'] . ').'
                    ]);
                    return;
                }
                // Row exists but is stale (expired) — fall through and overwrite it below.
            }

            $token = substr(bin2hex(random_bytes(8)), 0, 16);
            $newExpires = (clone $now)->modify('+' . self::TTL_MINUTES . ' minutes');

            $upsert = $db->prepare("
                INSERT INTO tblEditLocks (studentNumber, lockedByEmail, lockedAt, expiresAt, sessionToken)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    lockedByEmail = VALUES(lockedByEmail),
                    lockedAt      = VALUES(lockedAt),
                    expiresAt     = VALUES(expiresAt),
                    sessionToken  = VALUES(sessionToken)
            ");
            $upsert->execute([$studentNumber, $email, $nowStr, $newExpires->format('Y-m-d H:i:s'), $token]);

            $db->commit();

            echo json_encode([
                'ok' => true, 'owned' => false,
                'sessionToken' => $token,
                'lockedAt' => $nowStr,
                'expiresAt' => $newExpires->format('Y-m-d H:i:s'),
                'expiresAtMs' => $newExpires->getTimestamp() * 1000,
                'message' => 'Edit lock acquired.',
            ]);
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Lock acquire failed: ' . $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/admission/lock/release
    // Body: { studentNumber, sessionToken, email }
    //
    // Idempotent — returns ok:true even if no lock exists (mirrors
    // releaseEditLock()'s "Lock already cleared." case).
    //
    // AUTHORIZATION: sessionToken is the ONLY thing that authorizes a
    // release. `email` is accepted and used for logging/audit only — it is
    // NOT an alternate proof of ownership.
    //
    // SECURITY: sessionToken is the only valid release credential. An
    // email-based fallback would allow anyone who knows another user's email
    // to release their lock without holding the token. sessionToken is a
    // random 16-hex-char value returned only to whoever acquired the lock.
    //
    // No transaction needed: a single DELETE is already atomic.
    // ─────────────────────────────────────────────────────────────────
    public function release()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $studentNumber = trim((string)($input['studentNumber'] ?? ''));
        $token = trim((string)($input['sessionToken'] ?? ''));
        // $email is accepted for logging/audit only — NOT checked for authorization.
        $email = trim((string)($input['email'] ?? ''));

        if ($studentNumber === '') { http_response_code(400); echo json_encode(['error' => 'studentNumber is required.']); return; }
        if ($token === '')         { http_response_code(400); echo json_encode(['error' => 'sessionToken is required.']); return; }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT lockedByEmail, sessionToken FROM tblEditLocks WHERE studentNumber = ?");
        $stmt->execute([$studentNumber]);
        $row = $stmt->fetch();

        if (!$row) {
            echo json_encode(['ok' => true, 'message' => 'Lock already cleared.']);
            return;
        }

        if (!hash_equals((string)$row['sessionToken'], $token)) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not own this edit lock and cannot release it.']);
            return;
        }

        $del = $db->prepare("DELETE FROM tblEditLocks WHERE studentNumber = ?");
        $del->execute([$studentNumber]);
        echo json_encode(['ok' => true, 'message' => 'Edit lock released.']);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/admission/lock/active?email=<email>
    //
    // Returns the active lock held by the given email, if any.
    // Used on page load to restore the lock banner after a browser refresh.
    // ─────────────────────────────────────────────────────────────────
    public function active()
    {
        $email = trim((string)($_GET['email'] ?? ''));
        if ($email === '') { echo json_encode(['ok' => true, 'hasLock' => false]); return; }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM tblEditLocks WHERE lockedByEmail = ? AND expiresAt > NOW() LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row) { echo json_encode(['ok' => true, 'hasLock' => false]); return; }

        $expiresAt = new \DateTime((string)$row['expiresAt']);
        echo json_encode([
            'ok' => true, 'hasLock' => true,
            'studentNumber' => $row['studentNumber'],
            'sessionToken'  => $row['sessionToken'],
            'expiresAt'     => $row['expiresAt'],
            'expiresAtMs'   => $expiresAt->getTimestamp() * 1000,
        ]);
    }
}
