<?php

namespace App\Services;

use App\Core\Database;

/**
 * App\Services\ScheduleReferenceDataService
 *
 * Shared, module-agnostic read access to tblRooms / tblClassSchedule /
 * tblClassScheduleLoads — the same "compose, don't own" role
 * ReferenceDataService.php plays for tblFees/tblSections/tblPrograms.
 * Rooms and schedules are not conceptually owned by Subject Loading; this
 * class has no knowledge of HTTP and no Subject Loading-specific logic, so
 * a future module (e.g. a Registrar/Room-Scheduling controller) can
 * compose it directly without any Subject Loading coupling. The nested
 * `api/subject-loading/*` route prefix is a Subject Loading routing
 * decision only (see the implementation plan §5.1) — it does not make
 * this service Subject-Loading-owned.
 *
 * Phase 1 of the schedule-consumption implementation plan: read-only.
 * getScheduleForOffering()/getAllRooms()/teacherClassLoadExists() are the
 * only methods that exist so far. Phase 7 (documented, not built) would
 * add createRoom()/updateRoom()/setRoomActive()/createScheduleSlot()/
 * attachScheduleToOffering()/detachScheduleFromOffering() etc. to this
 * same file — not a new service — so no refactor is expected when that
 * phase starts.
 */
class ScheduleReferenceDataService
{
    /**
     * Every active schedule slot attached to one Teacher Class Load
     * ("Subject Offering"), each row fully joined with its room.
     *
     * Confirmed against live data (implementation plan §2.2/§2.3):
     *   - tblClassSchedule.day stores one full weekday name per row
     *     ("Monday".."Saturday", "Sunday" allowed by the column but not
     *     yet seen in data) — a MWF class is three separate scheduleID
     *     rows, same room/time. No day-splitting logic needed here.
     *   - The same scheduleID CAN legitimately be linked to more than one
     *     teacherClassLoadID (combined/merged class sessions — confirmed
     *     intentional, not a conflict). This method does not special-case
     *     that: it simply returns whatever rows match the requested
     *     teacherClassLoadID, exactly as it would for an unshared slot.
     *     "Who else shares this slot" is a distinct, not-yet-built
     *     feature — flagged in the plan, not implemented here.
     *
     * Returns rows ordered Monday..Sunday, then by start time. Empty
     * array (not an exception) when the offering exists but has no active
     * schedule assigned yet — that is a normal state, not an error.
     *
     * Policy-agnostic: returns full room/schedule facts (including
     * currently-always-NULL roomType, per the plan's closing note in §2)
     * with no business-rule filtering. Rows whose room is itself inactive
     * are still included (a schedule can describe a historical
     * assignment) — only the schedule-slot link itself and its parent
     * tblClassSchedule row need to be active for a row to appear at all.
     */
    public function getScheduleForOffering(string $teacherClassLoadID): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT
                csl.classScheduleLoadID, csl.scheduleID, csl.teacherClassLoadID,
                csl.isActive AS linkIsActive,
                cs.day, cs.startTime, cs.endTime, cs.academicYear, cs.semester,
                cs.isActive AS scheduleIsActive,
                r.roomID, r.roomName, r.roomType, r.isLectureRoom, r.isLaboratory,
                r.department, r.isActive AS roomIsActive
            FROM tblClassScheduleLoads csl
            INNER JOIN tblClassSchedule cs ON cs.scheduleID = csl.scheduleID
            INNER JOIN tblRooms r          ON r.roomID = cs.roomID
            WHERE csl.teacherClassLoadID = :teacherClassLoadID
              AND csl.isActive = 1
              AND cs.isActive = 1
            ORDER BY
              FIELD(cs.day, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
              cs.startTime
        ");
        $stmt->execute([':teacherClassLoadID' => $teacherClassLoadID]);
        $rows = $stmt->fetchAll();

        return array_map([$this, '_toScheduleApiRow'], $rows);
    }

    /**
     * Existence check only — used by the controller to distinguish
     * "offering exists, no schedule yet" (200, empty array) from
     * "offering doesn't exist at all" (404), per the implementation plan.
     * Does not care about tblTeacherClassLoads.isActive — an inactive
     * (deactivated) offering still "exists" for this purpose; its
     * schedule rows (if any) are still meaningful historical data.
     */
    public function teacherClassLoadExists(string $teacherClassLoadID): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT teacherClassLoadID FROM tblTeacherClassLoads WHERE teacherClassLoadID = ?");
        $stmt->execute([$teacherClassLoadID]);
        return (bool)$stmt->fetch();
    }

    /**
     * All rooms, or active-only. Not consumed by the Phase 1 target UI
     * yet (the Class Load status modal only needs getScheduleForOffering()
     * — room details ride along in that same response). Included now
     * because Phase 2 of the plan wires this up as its own endpoint
     * (api/subject-loading/rooms), and any future schedule-creation UI
     * (Phase 7) needs a room picker immediately — cheaper to ship this
     * read method alongside the schedule read method now than to add a
     * second service method later for the same table.
     */
    public function getAllRooms(bool $activeOnly = false): array
    {
        $db = Database::getConnection();

        $sql = "
            SELECT roomID, roomName, roomType, isLectureRoom, isLaboratory,
                   department, isActive, createdBy, dateCreated, modifiedBy, lastModified
            FROM tblRooms
        ";
        if ($activeOnly) {
            $sql .= " WHERE isActive = 1 ";
        }
        $sql .= " ORDER BY roomName ";

        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll();

        return array_map([$this, '_toRoomApiRow'], $rows);
    }

    // -------------------------------------------------------------
    // Row shaping — kept local to this service (not a controller
    // concern), mirroring SubjectLoadingReferenceDataService's own
    // _toApiRow-style private helpers.
    // -------------------------------------------------------------

    private function _toScheduleApiRow(array $row): array
    {
        return [
            'classScheduleLoadID' => (string)($row['classScheduleLoadID'] ?? ''),
            'scheduleID'          => (string)($row['scheduleID'] ?? ''),
            'teacherClassLoadID'  => (string)($row['teacherClassLoadID'] ?? ''),
            'linkIsActive'        => (bool)((int)($row['linkIsActive'] ?? 0)),
            'day'                 => (string)($row['day'] ?? ''),
            'startTime'           => (string)($row['startTime'] ?? ''),
            'endTime'             => (string)($row['endTime'] ?? ''),
            'academicYear'        => (string)($row['academicYear'] ?? ''),
            'semester'            => (string)($row['semester'] ?? ''),
            'scheduleIsActive'    => (bool)((int)($row['scheduleIsActive'] ?? 0)),
            'room'                => [
                'roomID'        => (string)($row['roomID'] ?? ''),
                'roomName'      => (string)($row['roomName'] ?? ''),
                // roomType is currently always NULL in live data — passed
                // through as-is (null, not coerced to '') so the frontend
                // can tell "not set" apart from "set but blank" and fall
                // back to isLectureRoom/isLaboratory/department for display.
                'roomType'      => $row['roomType'] !== null ? (string)$row['roomType'] : null,
                'isLectureRoom' => (bool)((int)($row['isLectureRoom'] ?? 0)),
                'isLaboratory'  => (bool)((int)($row['isLaboratory'] ?? 0)),
                'department'    => (string)($row['department'] ?? ''),
                'isActive'      => (bool)((int)($row['roomIsActive'] ?? 0)),
            ],
        ];
    }

    private function _toRoomApiRow(array $row): array
    {
        return [
            'roomID'        => (string)($row['roomID'] ?? ''),
            'roomName'      => (string)($row['roomName'] ?? ''),
            'roomType'      => $row['roomType'] !== null ? (string)$row['roomType'] : null,
            'isLectureRoom' => (bool)((int)($row['isLectureRoom'] ?? 0)),
            'isLaboratory'  => (bool)((int)($row['isLaboratory'] ?? 0)),
            'department'    => (string)($row['department'] ?? ''),
            'isActive'      => (bool)((int)($row['isActive'] ?? 0)),
            'createdBy'     => $row['createdBy'],
            'dateCreated'   => $row['dateCreated'],
            'modifiedBy'    => $row['modifiedBy'],
            'lastModified'  => $row['lastModified'],
        ];
    }
}
