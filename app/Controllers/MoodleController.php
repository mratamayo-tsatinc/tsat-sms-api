<?php

namespace App\Controllers;

/**
 * MoodleController
 *
 * Top-level, module-agnostic controller (same tier as LmsController) that
 * answers "what does Moodle say about this shortname / this course id?" in
 * plain, undecorated data. It knows nothing about Subject Loading, rosters,
 * CSV exports, or what "blocked"/"eligible" means — that policy lives in
 * the caller (subjectLoading.html's JS today; StudentSubjectEnrollmentController
 * later, if centralized server-side).
 *
 * Every public method: wrapped in try/catch, returns the shared
 * { ok: bool, ... } envelope, and sets its HTTP status explicitly via
 * _respond()/_fail().
 *
 * Config (see plan §6 / §8.1): read from environment variables, never
 * hardcoded. Missing config fails loudly (500 MOODLE_CONFIG_ERROR) rather
 * than silently hitting Moodle with an empty token.
 *
 *   MOODLE_WSTOKEN          - Moodle web service token
 *   MOODLE_BASE_URL         - e.g. https://lms.tsatinc.edu.ph/webservice/rest/server.php
 *   MOODLE_STUDENT_ROLE_ID  - optional; roleid used by enrollUser()/
 *                             enrollUserByShortnameAndEmail() when no
 *                             roleId is given in the request. Defaults to
 *                             5 (stock Moodle "Student" role) if unset —
 *                             verify against Site Administration → Users →
 *                             Permissions → Define roles on this instance,
 *                             since role ids are data, not a protocol
 *                             constant, and are not guaranteed to be 5 on
 *                             a customized install.
 *
 * SECURITY NOTE (plan §2.3): the wstoken value that shipped in
 * Moodle_REST_API.txt was shared in plaintext and must be treated as
 * compromised — rotate it in Moodle's admin panel and only ever put the
 * new value in the environment, never in source control.
 */
class MoodleController
{
    private const WSFUNCTION_RESOLVE_COURSE  = 'core_course_get_courses_by_field';
    private const WSFUNCTION_ENROLLED_USERS  = 'core_enrol_get_enrolled_users';
    private const WSFUNCTION_RESOLVE_USER    = 'core_user_get_users_by_field';
    private const WSFUNCTION_USER_COURSES    = 'core_enrol_get_users_courses';

    // Student enrollment (§ new) — enrol_manual_enrol_users is the standard
    // Manual-enrolment-plugin WS function. Returns null on success.
    private const WSFUNCTION_ENROL_USER      = 'enrol_manual_enrol_users';

    // Course groups (§ new) — find-or-create-then-add pattern, mirroring the
    // resolve-then-act convention already used for courses/users elsewhere
    // in this controller rather than relying on a duplicate-name exception.
    private const WSFUNCTION_COURSE_GROUPS    = 'core_group_get_course_groups';
    private const WSFUNCTION_CREATE_GROUPS    = 'core_group_create_groups';
    private const WSFUNCTION_ADD_GROUP_MEMBER = 'core_group_add_group_members';

    // Exam Permit (§ new) — one checkbox custom user profile field per
    // grading period, written via core_user_update_users. No separate
    // "read" WS function exists — customfields ride along on every
    // core_user_get_users_by_field response, so the read side reuses
    // WSFUNCTION_RESOLVE_USER instead of a dedicated constant.
    private const WSFUNCTION_UPDATE_USER = 'core_user_update_users';

    // Exam Permit — one checkbox custom user profile field per grading
    // period (plan §0/§3.1). Keys match
    // ExamPermitReferenceDataService::VALID_PERIODS exactly so a period
    // string validated/echoed there round-trips unchanged here —
    // duplicated rather than shared since MoodleController has no
    // dependency on that service (same deliberate-duplication rationale
    // as ExamPermitReferenceDataService::_buildClassCode()'s own doc
    // comment). No env var needed now that the mapping is a fixed
    // 4-entry table rather than a single configurable shortname.
    private const EXAM_PERMIT_FIELD_BY_PERIOD = [
        'PRELIM'     => 'PLExamPermit',
        'MIDTERM'    => 'MTExamPermit',
        'SEMIFINALS' => 'SFExamPermit',
        'FINALS'     => 'FExamPermit',
    ];


    // Placeholder timeouts per plan §4.4/§8.3 — tune once real course
    // sizes are known. core_enrol_get_enrolled_users is explicitly called
    // out in the source material as slow on large courses.
    private const CONNECT_TIMEOUT_SECONDS = 15;
    private const TOTAL_TIMEOUT_SECONDS   = 25;

    // ==================================================================
    // 4.1 resolveCourseId() — GET /api/moodle/courses/resolve?shortname=
    // ==================================================================
    public function resolveCourseId(): void
    {
        try {
            $shortname = isset($_GET['shortname']) ? trim((string) $_GET['shortname']) : '';
            if ($shortname === '') {
                $this->_fail(422, 'VALIDATION_ERROR', 'shortname is required.');
                return;
            }

            $core = $this->_resolveCourseCore($shortname);

            switch ($core['status']) {
                case 'found':
                    $this->_respond(200, [
                        'ok'       => true,
                        'found'    => true,
                        'courseId' => $core['courseId'],
                        'course'   => $core['course'],
                    ]);
                    return;

                case 'not_found':
                    $this->_respond(200, [
                        'ok'       => true,
                        'found'    => false,
                        'courseId' => null,
                    ]);
                    return;

                case 'timeout':
                    $this->_fail(504, 'MOODLE_TIMEOUT', $core['message']);
                    return;

                case 'unavailable':
                default:
                    $this->_fail(502, 'MOODLE_UNAVAILABLE', $core['message']);
                    return;
            }
        } catch (\Throwable $e) {
            $this->_fail(500, 'INTERNAL_ERROR', 'Unexpected error resolving the Moodle course.');
        }
    }

    // ==================================================================
    // 4.2 enrolledUsers() — GET /api/moodle/courses/enrolled-users?courseId=
    // ==================================================================
    public function enrolledUsers(): void
    {
        try {
            $courseIdRaw = $_GET['courseId'] ?? null;
            if ($courseIdRaw === null || $courseIdRaw === '' || !ctype_digit((string) $courseIdRaw)) {
                $this->_fail(422, 'VALIDATION_ERROR', 'courseId is required and must be an integer.');
                return;
            }
            $courseId = (int) $courseIdRaw;

            $core = $this->_enrolledUsersCore($courseId);

            switch ($core['status']) {
                case 'ok':
                    $this->_respond(200, [
                        'ok'    => true,
                        'users' => $core['users'],
                    ]);
                    return;

                case 'course_not_found':
                    // Structured non-error (HTTP 200) — Moodle's own
                    // dml_missing_record_exception for an unknown course id.
                    $this->_respond(200, [
                        'ok'    => false,
                        'error' => ['code' => 'COURSE_NOT_FOUND', 'message' => $core['message']],
                    ]);
                    return;

                case 'timeout':
                    $this->_fail(504, 'MOODLE_TIMEOUT', $core['message']);
                    return;

                case 'unavailable':
                default:
                    $this->_fail(502, 'MOODLE_UNAVAILABLE', $core['message']);
                    return;
            }
        } catch (\Throwable $e) {
            $this->_fail(500, 'INTERNAL_ERROR', 'Unexpected error fetching enrolled users.');
        }
    }

    // ==================================================================
    // 4.3 enrolledUsersByShortname() — convenience composition only.
    // GET /api/moodle/courses/enrolled-users-by-shortname?shortname=
    //
    // Composes _resolveCourseCore() + _enrolledUsersCore() — the exact
    // same private core methods the two endpoints above use — so no
    // Moodle-calling logic is duplicated here. This is the endpoint the
    // roster modal actually calls (plan §7).
    // ==================================================================
    public function enrolledUsersByShortname(): void
    {
        try {
            $shortname = isset($_GET['shortname']) ? trim((string) $_GET['shortname']) : '';
            if ($shortname === '') {
                $this->_fail(422, 'VALIDATION_ERROR', 'shortname is required.');
                return;
            }

            $resolved = $this->_resolveCourseCore($shortname);

            if ($resolved['status'] === 'timeout') {
                $this->_fail(504, 'MOODLE_TIMEOUT', $resolved['message']);
                return;
            }
            if ($resolved['status'] === 'unavailable') {
                $this->_fail(502, 'MOODLE_UNAVAILABLE', $resolved['message']);
                return;
            }
            if ($resolved['status'] === 'not_found') {
                $this->_respond(200, [
                    'ok'       => true,
                    'found'    => false,
                    'courseId' => null,
                    'users'    => [],
                ]);
                return;
            }

            // $resolved['status'] === 'found' from here on.
            $courseId = $resolved['courseId'];
            $users = $this->_enrolledUsersCore($courseId);

            switch ($users['status']) {
                case 'ok':
                    $this->_respond(200, [
                        'ok'       => true,
                        'found'    => true,
                        'courseId' => $courseId,
                        'users'    => $users['users'],
                    ]);
                    return;

                case 'course_not_found':
                    // The shortname resolved to a course id a moment ago but
                    // Moodle no longer recognizes it (e.g. deleted between the
                    // two calls) — treat the composed result as "no course"
                    // rather than surfacing a confusing partial state.
                    $this->_respond(200, [
                        'ok'       => true,
                        'found'    => false,
                        'courseId' => null,
                        'users'    => [],
                    ]);
                    return;

                case 'timeout':
                    $this->_fail(504, 'MOODLE_TIMEOUT', $users['message']);
                    return;

                case 'unavailable':
                default:
                    $this->_fail(502, 'MOODLE_UNAVAILABLE', $users['message']);
                    return;
            }
        } catch (\Throwable $e) {
            $this->_fail(500, 'INTERNAL_ERROR', 'Unexpected error fetching the Moodle roster.');
        }
    }

    // ==================================================================
    // 4.5 enrolledCoursesByEmail() — convenience composition only.
    // GET /api/moodle/users/enrolled-courses?email=
    //
    // Our external site only stores student email/username, never Moodle's
    // internal userid, so this composes _resolveUserIdCore() +
    // _enrolledCoursesCore() the same way enrolledUsersByShortname() composes
    // course resolution + roster lookup. No Moodle-calling logic is
    // duplicated here.
    // ==================================================================
    public function enrolledCoursesByEmail(): void
    {
        try {
            $email = isset($_GET['email']) ? trim((string) $_GET['email']) : '';
            if ($email === '') {
                $this->_fail(422, 'VALIDATION_ERROR', 'email is required.');
                return;
            }

            $resolved = $this->_resolveUserIdCore($email);

            if ($resolved['status'] === 'timeout') {
                $this->_fail(504, 'MOODLE_TIMEOUT', $resolved['message']);
                return;
            }
            if ($resolved['status'] === 'unavailable') {
                $this->_fail(502, 'MOODLE_UNAVAILABLE', $resolved['message']);
                return;
            }
            if ($resolved['status'] === 'not_found') {
                $this->_respond(200, [
                    'ok'      => true,
                    'found'   => false,
                    'userId'  => null,
                    'courses' => [],
                ]);
                return;
            }

            // $resolved['status'] === 'found' from here on.
            $userId = $resolved['userId'];
            $courses = $this->_enrolledCoursesCore($userId);

            switch ($courses['status']) {
                case 'ok':
                    $this->_respond(200, [
                        'ok'      => true,
                        'found'   => true,
                        'userId'  => $userId,
                        'courses' => $courses['courses'],
                    ]);
                    return;

                case 'user_not_found':
                    // The email resolved to a userid a moment ago but Moodle
                    // no longer recognizes it (e.g. deleted between the two
                    // calls) — treat the composed result as "no user" rather
                    // than surfacing a confusing partial state, matching
                    // enrolledUsersByShortname()'s course_not_found handling.
                    $this->_respond(200, [
                        'ok'      => true,
                        'found'   => false,
                        'userId'  => null,
                        'courses' => [],
                    ]);
                    return;

                case 'timeout':
                    $this->_fail(504, 'MOODLE_TIMEOUT', $courses['message']);
                    return;

                case 'unavailable':
                default:
                    $this->_fail(502, 'MOODLE_UNAVAILABLE', $courses['message']);
                    return;
            }
        } catch (\Throwable $e) {
            $this->_fail(500, 'INTERNAL_ERROR', 'Unexpected error fetching enrolled courses.');
        }
    }

    // ==================================================================
    // 4.6 enrollUser() — POST /api/moodle/courses/enroll-user
    //
    // Enrolls a Moodle user (by internal userid) into a course (by
    // internal courseid) via the Manual enrolment plugin. Optionally also
    // ensures the user is a member of a named course group, creating the
    // group if it doesn't already exist. Group assignment is best-effort:
    // if enrollment succeeds but the group step fails, this still returns
    // 200 with enrolled:true and a group.status of "failed" rather than
    // masking a real enrollment behind a group-only problem.
    // ==================================================================
    public function enrollUser(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            $courseId  = $input['courseId'] ?? null;
            $userId    = $input['moodleUserId'] ?? null;
            $roleId    = $input['roleId'] ?? null;
            $groupName = isset($input['groupName']) ? trim((string) $input['groupName']) : null;

            if (!ctype_digit((string) $courseId) || !ctype_digit((string) $userId)) {
                $this->_fail(422, 'VALIDATION_ERROR', 'courseId and moodleUserId are required integers.');
                return;
            }
            if ($roleId !== null && !ctype_digit((string) $roleId)) {
                $this->_fail(422, 'VALIDATION_ERROR', 'roleId, if provided, must be an integer.');
                return;
            }
            if ($groupName === '') {
                $this->_fail(422, 'VALIDATION_ERROR', 'groupName, if provided, must not be empty.');
                return;
            }

            $courseId = (int) $courseId;
            $userId   = (int) $userId;

            $enrol = $this->_enrolUserCore($courseId, $userId, $roleId !== null ? (int) $roleId : null);

            switch ($enrol['status']) {
                case 'invalid':
                    $this->_fail(422, 'ENROLLMENT_INVALID', $enrol['message']);
                    return;
                case 'forbidden':
                    $this->_fail(502, 'MOODLE_FORBIDDEN', $enrol['message']);
                    return;
                case 'timeout':
                    $this->_fail(504, 'MOODLE_TIMEOUT', $enrol['message']);
                    return;
                case 'unavailable':
                    $this->_fail(502, 'MOODLE_UNAVAILABLE', $enrol['message']);
                    return;
                case 'ok':
                default:
                    break; // fall through to optional group handling / response
            }

            $response = ['ok' => true, 'enrolled' => true];

            if ($groupName !== null) {
                $response['group'] = $this->_handleGroupAssignment($courseId, $userId, $groupName);
            }

            $this->_respond(200, $response);
        } catch (\Throwable $e) {
            $this->_fail(500, 'INTERNAL_ERROR', 'Unexpected error enrolling the user.');
        }
    }

    // ==================================================================
    // 4.7 enrollUserByShortnameAndEmail() — convenience composition only.
    // POST /api/moodle/courses/enroll-user-by-email
    //
    // Composes _resolveCourseCore() + _resolveUserIdCore() + the same
    // _enrolUserCore() enrollUser() uses — no Moodle-calling logic is
    // duplicated here, matching the enrolledUsersByShortname() /
    // enrolledCoursesByEmail() convenience-composition pattern. Our
    // external site only stores email, never Moodle's internal userid.
    // ==================================================================
    public function enrollUserByShortnameAndEmail(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            $shortname = isset($input['shortname']) ? trim((string) $input['shortname']) : '';
            $email     = isset($input['email']) ? trim((string) $input['email']) : '';
            $roleId    = $input['roleId'] ?? null;
            $groupName = isset($input['groupName']) ? trim((string) $input['groupName']) : null;

            if ($shortname === '' || $email === '') {
                $this->_fail(422, 'VALIDATION_ERROR', 'shortname and email are required.');
                return;
            }
            if ($roleId !== null && !ctype_digit((string) $roleId)) {
                $this->_fail(422, 'VALIDATION_ERROR', 'roleId, if provided, must be an integer.');
                return;
            }
            if ($groupName === '') {
                $this->_fail(422, 'VALIDATION_ERROR', 'groupName, if provided, must not be empty.');
                return;
            }

            $course = $this->_resolveCourseCore($shortname);
            if ($course['status'] === 'timeout') {
                $this->_fail(504, 'MOODLE_TIMEOUT', $course['message']);
                return;
            }
            if ($course['status'] === 'unavailable') {
                $this->_fail(502, 'MOODLE_UNAVAILABLE', $course['message']);
                return;
            }
            if ($course['status'] === 'not_found') {
                $this->_respond(200, ['ok' => true, 'enrolled' => false, 'reason' => 'COURSE_NOT_FOUND']);
                return;
            }

            $user = $this->_resolveUserIdCore($email);
            if ($user['status'] === 'timeout') {
                $this->_fail(504, 'MOODLE_TIMEOUT', $user['message']);
                return;
            }
            if ($user['status'] === 'unavailable') {
                $this->_fail(502, 'MOODLE_UNAVAILABLE', $user['message']);
                return;
            }
            if ($user['status'] === 'not_found') {
                $this->_respond(200, ['ok' => true, 'enrolled' => false, 'reason' => 'USER_NOT_FOUND']);
                return;
            }

            // Both resolved from here on.
            $courseId = $course['courseId'];
            $userId   = $user['userId'];

            $enrol = $this->_enrolUserCore($courseId, $userId, $roleId !== null ? (int) $roleId : null);

            switch ($enrol['status']) {
                case 'invalid':
                    $this->_fail(422, 'ENROLLMENT_INVALID', $enrol['message']);
                    return;
                case 'forbidden':
                    $this->_fail(502, 'MOODLE_FORBIDDEN', $enrol['message']);
                    return;
                case 'timeout':
                    $this->_fail(504, 'MOODLE_TIMEOUT', $enrol['message']);
                    return;
                case 'unavailable':
                    $this->_fail(502, 'MOODLE_UNAVAILABLE', $enrol['message']);
                    return;
                case 'ok':
                default:
                    break; // fall through to optional group handling / response
            }

            $response = [
                'ok'       => true,
                'enrolled' => true,
                'courseId' => $courseId,
                'userId'   => $userId,
            ];

            if ($groupName !== null) {
                $response['group'] = $this->_handleGroupAssignment($courseId, $userId, $groupName);
            }

            $this->_respond(200, $response);
        } catch (\Throwable $e) {
            $this->_fail(500, 'INTERNAL_ERROR', 'Unexpected error enrolling the user.');
        }
    }
	
	// ==================================================================
    // 4.8 updateEnrollmentStatusByShortnameAndEmail() — convenience
    // composition only. POST /api/moodle/courses/enrollment-status-by-email
    //
    // Composes _resolveCourseCore() + _resolveUserIdCore() + the new
    // _updateEnrolmentStatusCore() — no Moodle-calling logic is duplicated
    // here, matching enrollUserByShortnameAndEmail()'s own composition
    // pattern immediately above.
    //
    // CAUTION: if the student wasn't already enrolled in this course,
    // this ENROLLS them fresh at the requested status rather than
    // failing — see _updateEnrolmentStatusCore()'s doc comment above.
    // Callers that must guarantee "only ever touch an existing enrolment"
    // should check enrolledUsersByShortname()/enrolledCoursesByEmail()
    // first.
    // ==================================================================
    public function updateEnrollmentStatusByShortnameAndEmail(): void
    {
        try {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];

            $shortname = isset($input['shortname']) ? trim((string) $input['shortname']) : '';
            $email     = isset($input['email']) ? trim((string) $input['email']) : '';
            $status    = isset($input['status']) ? strtoupper(trim((string) $input['status'])) : '';

            if ($shortname === '' || $email === '') {
                $this->_fail(422, 'VALIDATION_ERROR', 'shortname and email are required.');
                return;
            }
            if (!in_array($status, ['ACTIVE', 'SUSPENDED'], true)) {
                $this->_fail(422, 'VALIDATION_ERROR', 'status must be either ACTIVE or SUSPENDED.');
                return;
            }

            $course = $this->_resolveCourseCore($shortname);
            if ($course['status'] === 'timeout') {
                $this->_fail(504, 'MOODLE_TIMEOUT', $course['message']);
                return;
            }
            if ($course['status'] === 'unavailable') {
                $this->_fail(502, 'MOODLE_UNAVAILABLE', $course['message']);
                return;
            }
            if ($course['status'] === 'not_found') {
                $this->_respond(200, ['ok' => true, 'updated' => false, 'reason' => 'COURSE_NOT_FOUND']);
                return;
            }

            $user = $this->_resolveUserIdCore($email);
            if ($user['status'] === 'timeout') {
                $this->_fail(504, 'MOODLE_TIMEOUT', $user['message']);
                return;
            }
            if ($user['status'] === 'unavailable') {
                $this->_fail(502, 'MOODLE_UNAVAILABLE', $user['message']);
                return;
            }
            if ($user['status'] === 'not_found') {
                $this->_respond(200, ['ok' => true, 'updated' => false, 'reason' => 'USER_NOT_FOUND']);
                return;
            }

            // Both resolved from here on.
            $courseId = $course['courseId'];
            $userId   = $user['userId'];

            // Moodle's own internal Enrolment API status codes:
            // 0 = ENROL_USER_ACTIVE, 1 = ENROL_USER_SUSPENDED. Hardcoded
            // here for the same reason FORMAT_HTML (1) is hardcoded in
            // _createGroupCore() — this app talks to Moodle over HTTP
            // only and never loads Moodle's own PHP constants.
            $moodleStatus = $status === 'SUSPENDED' ? 1 : 0;

            $update = $this->_updateEnrolmentStatusCore($courseId, $userId, $moodleStatus);

            switch ($update['status']) {
                case 'invalid':
                    $this->_fail(422, 'ENROLLMENT_INVALID', $update['message']);
                    return;
                case 'forbidden':
                    $this->_fail(502, 'MOODLE_FORBIDDEN', $update['message']);
                    return;
                case 'timeout':
                    $this->_fail(504, 'MOODLE_TIMEOUT', $update['message']);
                    return;
                case 'unavailable':
                    $this->_fail(502, 'MOODLE_UNAVAILABLE', $update['message']);
                    return;
                case 'ok':
                default:
                    $this->_respond(200, [
                        'ok'       => true,
                        'updated'  => true,
                        'courseId' => $courseId,
                        'userId'   => $userId,
                        'status'   => $status,
                    ]);
                    return;
            }
        } catch (\Throwable $e) {
            $this->_fail(500, 'INTERNAL_ERROR', 'Unexpected error updating the enrollment status.');
        }
    }

    // ==================================================================
    // 4.9 examPermitStatusByEmail() — GET /api/moodle/users/exam-permit-status?email=
    // Returns ALL FOUR periods in one response — see
    // _resolveUserExamPermitsCore()'s doc comment below for why a single
    // core_user_get_users_by_field call already carries every
    // customfield, so there's no per-period read variant.
    // ==================================================================
    public function examPermitStatusByEmail(): void
    {
        try {
            $email = isset($_GET['email']) ? trim((string) $_GET['email']) : '';
            if ($email === '') {
                $this->_fail(422, 'VALIDATION_ERROR', 'email is required.');
                return;
            }

            $core = $this->_resolveUserExamPermitsCore($email);

            switch ($core['status']) {
                case 'found':
                    $this->_respond(200, [
                        'ok'          => true,
                        'found'       => true,
                        'userId'      => $core['userId'],
                        'examPermits' => $core['examPermits'],
                    ]);
                    return;
                case 'not_found':
                    $this->_respond(200, [
                        'ok'          => true,
                        'found'       => false,
                        'userId'      => null,
                        'examPermits' => null,
                    ]);
                    return;
                case 'timeout':
                    $this->_fail(504, 'MOODLE_TIMEOUT', $core['message']);
                    return;
                case 'unavailable':
                default:
                    $this->_fail(502, 'MOODLE_UNAVAILABLE', $core['message']);
                    return;
            }
        } catch (\Throwable $e) {
            $this->_fail(500, 'INTERNAL_ERROR', 'Unexpected error reading Exam Permit status.');
        }
    }

    // ==================================================================
    // 4.9b examPermitStatusByEmails() — POST /api/moodle/users/exam-permit-status/bulk
    // Body: { emails: string[] }
    //
    // Bulk sibling of examPermitStatusByEmail() above — resolves ALL
    // FOUR periods for MANY students in ONE Moodle round trip instead of
    // one request per student, using the same core_user_get_users_by_field
    // WS function with an array of emails (see
    // _resolveUserExamPermitsBulkCore()'s doc comment). POST rather than
    // GET despite being read-only: a class roster's worth of emails in a
    // querystring risks hitting URL length limits that a JSON body does
    // not, matching this codebase's existing GET-for-simple-reads /
    // POST-for-larger-payloads split.
    //
    // Response shape mirrors the request: { ok:true, results: { <email
    // as sent>: {found, userId, examPermits} } } — every requested email
    // is guaranteed a key, even ones Moodle didn't find, so the caller
    // never has to special-case a missing key vs. an explicit not-found.
    // ==================================================================
    public function examPermitStatusByEmails(): void
    {
        try {
            $input  = json_decode(file_get_contents('php://input'), true) ?? [];
            $emails = $input['emails'] ?? null;

            if (!is_array($emails) || empty($emails)) {
                $this->_fail(422, 'VALIDATION_ERROR', 'emails must be a non-empty array.');
                return;
            }

            // Normalize: string-cast, trim, drop empties, de-dupe. Casing
            // and exact text are preserved (beyond trimming) since the
            // response is keyed back by these exact strings.
            $emails = array_values(array_unique(array_filter(array_map(
                function ($e) { return is_string($e) ? trim($e) : ''; },
                $emails
            ), function ($e) { return $e !== ''; })));

            if (empty($emails)) {
                $this->_fail(422, 'VALIDATION_ERROR', 'emails must contain at least one non-empty value.');
                return;
            }

            $core = $this->_resolveUserExamPermitsBulkCore($emails);

            switch ($core['status']) {
                case 'ok':
                    $this->_respond(200, [
                        'ok'      => true,
                        'results' => $core['results'],
                    ]);
                    return;
                case 'timeout':
                    $this->_fail(504, 'MOODLE_TIMEOUT', $core['message']);
                    return;
                case 'unavailable':
                default:
                    $this->_fail(502, 'MOODLE_UNAVAILABLE', $core['message']);
                    return;
            }
        } catch (\Throwable $e) {
            $this->_fail(500, 'INTERNAL_ERROR', 'Unexpected error reading Exam Permit status in bulk.');
        }
    }

    // ==================================================================
    // 4.10 updateExamPermitStatusByEmail() — POST /api/moodle/users/exam-permit-status
    // Body: { email, period: 'PRELIM'|'MIDTERM'|'SEMIFINALS'|'FINALS', active: true|false }
    //
    // Writes exactly ONE period's field per call — see
    // _setUserExamPermitCore()'s doc comment for why this is a genuine
    // partial update, unlike updateEnrollmentStatusByShortnameAndEmail()'s
    // enrol-fresh-if-missing caveat immediately above; this endpoint has
    // no equivalent caveat since core_user_update_users only ever touches
    // the customfields explicitly sent.
    // ==================================================================
    public function updateExamPermitStatusByEmail(): void
    {
        try {
            $input  = json_decode(file_get_contents('php://input'), true) ?? [];
            $email  = isset($input['email']) ? trim((string) $input['email']) : '';
            $period = isset($input['period']) ? strtoupper(trim((string) $input['period'])) : '';
            $active = $input['active'] ?? null;
            $actorEmail = isset($input['actorEmail']) ? trim((string) $input['actorEmail']) : '';
            $actor = $actorEmail !== '' ? $actorEmail : $email;

            if ($email === '' || !isset(self::EXAM_PERMIT_FIELD_BY_PERIOD[$period]) || !is_bool($active)) {
                $this->_fail(422, 'VALIDATION_ERROR', 'email, a valid period (PRELIM/MIDTERM/SEMIFINALS/FINALS), and a boolean active are required.');
                return;
            }

            // Only userId is needed to write — cheaper than
            // _resolveUserExamPermitsCore(), which also parses all four
            // customfields we don't need here.
            $resolved = $this->_resolveUserIdCore($email);
            if ($resolved['status'] === 'timeout') {
                $this->_fail(504, 'MOODLE_TIMEOUT', $resolved['message']);
                return;
            }
            if ($resolved['status'] === 'unavailable') {
                $this->_fail(502, 'MOODLE_UNAVAILABLE', $resolved['message']);
                return;
            }
            if ($resolved['status'] === 'not_found') {
                $this->_respond(200, ['ok' => true, 'updated' => false, 'reason' => 'USER_NOT_FOUND']);
                return;
            }

            $localCtx = $this->_resolveExamPermitLocalGateContextByEmail($email);
            if ($localCtx['status'] !== 'ok') {
                if ($localCtx['status'] === 'not_linked') {
                    $this->_fail(409, 'EXAM_PERMIT_PRECONDITION_FAILED', 'Moodle email is not linked to a local student record.');
                    return;
                }
                $this->_fail(500, 'EXAM_PERMIT_PRECONDITION_ERROR', $localCtx['message'] ?? 'Unable to evaluate exam permit preconditions.');
                return;
            }

            $workflow = new \App\Services\ExamPermitWorkflowService();
            $eligibility = $workflow->moodleEligibility([
                'studentNumber' => $localCtx['studentNumber'],
                'academicYear' => $localCtx['academicYear'],
                'semester' => $localCtx['semester'],
                'period' => $period,
            ]);

            if (($eligibility['ok'] ?? false) !== true) {
                $this->_fail(500, 'EXAM_PERMIT_PRECONDITION_ERROR', $eligibility['message'] ?? 'Unable to evaluate exam permit preconditions.');
                return;
            }

            if (($eligibility['eligible'] ?? false) !== true) {
                $workflow->logMoodleAction([
                    'studentNumber' => $localCtx['studentNumber'],
                    'academicYear' => $localCtx['academicYear'],
                    'semester' => $localCtx['semester'],
                    'period' => $period,
                    'actorEmail' => $actor,
                    'active' => $active,
                    'outcome' => 'DENY',
                    'detail' => (string)($eligibility['message'] ?? 'Exam permit precondition failed.'),
                ]);
                $this->_fail(409, 'EXAM_PERMIT_PRECONDITION_FAILED', (string)($eligibility['message'] ?? 'Exam permit precondition failed.'));
                return;
            }

            $update = $this->_setUserExamPermitCore($resolved['userId'], $period, $active);

            switch ($update['status']) {
                case 'invalid_period': // defensive only — already validated above
                case 'invalid':
                    $workflow->logMoodleAction([
                        'studentNumber' => $localCtx['studentNumber'],
                        'academicYear' => $localCtx['academicYear'],
                        'semester' => $localCtx['semester'],
                        'period' => $period,
                        'actorEmail' => $actor,
                        'active' => $active,
                        'outcome' => 'FAILED',
                        'detail' => $update['message'] ?? 'Moodle status update rejected as invalid.',
                    ]);
                    $this->_fail(422, 'EXAM_PERMIT_UPDATE_INVALID', $update['message']);
                    return;
                case 'forbidden':
                    $workflow->logMoodleAction([
                        'studentNumber' => $localCtx['studentNumber'],
                        'academicYear' => $localCtx['academicYear'],
                        'semester' => $localCtx['semester'],
                        'period' => $period,
                        'actorEmail' => $actor,
                        'active' => $active,
                        'outcome' => 'FAILED',
                        'detail' => $update['message'] ?? 'Moodle denied exam permit update.',
                    ]);
                    $this->_fail(502, 'MOODLE_FORBIDDEN', $update['message']);
                    return;
                case 'timeout':
                    $workflow->logMoodleAction([
                        'studentNumber' => $localCtx['studentNumber'],
                        'academicYear' => $localCtx['academicYear'],
                        'semester' => $localCtx['semester'],
                        'period' => $period,
                        'actorEmail' => $actor,
                        'active' => $active,
                        'outcome' => 'FAILED',
                        'detail' => $update['message'] ?? 'Moodle timeout during exam permit update.',
                    ]);
                    $this->_fail(504, 'MOODLE_TIMEOUT', $update['message']);
                    return;
                case 'unavailable':
                    $workflow->logMoodleAction([
                        'studentNumber' => $localCtx['studentNumber'],
                        'academicYear' => $localCtx['academicYear'],
                        'semester' => $localCtx['semester'],
                        'period' => $period,
                        'actorEmail' => $actor,
                        'active' => $active,
                        'outcome' => 'FAILED',
                        'detail' => $update['message'] ?? 'Moodle unavailable during exam permit update.',
                    ]);
                    $this->_fail(502, 'MOODLE_UNAVAILABLE', $update['message']);
                    return;
                case 'ok':
                default:
                    $workflow->logMoodleAction([
                        'studentNumber' => $localCtx['studentNumber'],
                        'academicYear' => $localCtx['academicYear'],
                        'semester' => $localCtx['semester'],
                        'period' => $period,
                        'actorEmail' => $actor,
                        'active' => $active,
                        'outcome' => 'SUCCESS',
                        'detail' => 'Moodle exam permit status updated successfully.',
                    ]);
                    $this->_respond(200, [
                        'ok'      => true,
                        'updated' => true,
                        'userId'  => $resolved['userId'],
                        'period'  => $period,
                        'active'  => $active,
                    ]);
                    return;
            }
        } catch (\Throwable $e) {
            $this->_fail(500, 'INTERNAL_ERROR', 'Unexpected error updating Exam Permit status.');
        }
    }

    private function _resolveExamPermitLocalGateContextByEmail(string $email): array
    {
        try {
            $db = \App\Core\Database::getConnection();

            $stmt = $db->prepare("SELECT studentNumber FROM tblLmsAccounts WHERE LOWER(moodleEmail) = LOWER(:email) LIMIT 1");
            $stmt->execute([':email' => $email]);
            $row = $stmt->fetch();
            $studentNumber = trim((string)($row['studentNumber'] ?? ''));

            if ($studentNumber === '') {
                return ['status' => 'not_linked'];
            }

            $term = (new \App\Services\ReferenceDataService())->getActiveTerm();
            return [
                'status' => 'ok',
                'studentNumber' => $studentNumber,
                'academicYear' => (string)$term['academicYear'],
                'semester' => (string)$term['semester'],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

	// ==================================================================
    // Private cores — one per Moodle operation, both routed through the
    // single _callMoodleWs() transport helper (§4.4). Public methods
    // above only translate these normalized results into HTTP responses;
    // no duplicate error-shape detection lives in more than one place.
    // ==================================================================

    /**
     * @return array{status:string,courseId?:int,course?:array,message?:string}
     */
    private function _resolveCourseCore(string $shortname): array
    {
        $result = $this->_callMoodleWs(self::WSFUNCTION_RESOLVE_COURSE, [
            'field' => 'shortname',
            'value' => $shortname,
        ]);

        if ($result['type'] === 'timeout') {
            return ['status' => 'timeout', 'message' => $result['message']];
        }
        if ($result['type'] === 'transport') {
            return ['status' => 'unavailable', 'message' => $result['message']];
        }
        if ($result['type'] === 'exception') {
            // core_course_get_courses_by_field doesn't document a "not
            // found" exception (empty courses[] is its not-found signal
            // instead) — any exception here is an unexpected Moodle-side
            // condition, treated as unavailable rather than guessed at.
            return ['status' => 'unavailable', 'message' => $result['message']];
        }

        // $result['type'] === 'success'
        $courses = $result['data']['courses'] ?? [];
        if (empty($courses)) {
            return ['status' => 'not_found'];
        }

        $course = $courses[0];
        return [
            'status'   => 'found',
            'courseId' => (int) $course['id'],
            'course'   => [
                'fullname'     => $course['fullname'] ?? null,
                'shortname'    => $course['shortname'] ?? null,
                'categoryname' => $course['categoryname'] ?? null,
            ],
        ];
    }

    /**
     * @return array{status:string,users?:array,message?:string}
     */
    private function _enrolledUsersCore(int $courseId): array
    {
        $result = $this->_callMoodleWs(self::WSFUNCTION_ENROLLED_USERS, [
            'options' => [
                ['name' => 'userfields', 'value' => 'id,username,fullname,email'],
            ],
            'courseid' => $courseId,
        ]);

        if ($result['type'] === 'timeout') {
            return ['status' => 'timeout', 'message' => $result['message']];
        }
        if ($result['type'] === 'transport') {
            return ['status' => 'unavailable', 'message' => $result['message']];
        }
        if ($result['type'] === 'exception') {
            if (($result['data']['errorcode'] ?? null) === 'invalidrecord') {
                return [
                    'status'  => 'course_not_found',
                    'message' => $result['data']['message'] ?? 'Moodle course not found.',
                ];
            }
            return ['status' => 'unavailable', 'message' => $result['message']];
        }

        // $result['type'] === 'success' — a bare JSON array of user objects.
        $rawUsers = is_array($result['data']) ? $result['data'] : [];
        $users = array_map(function ($u) {
            return [
                'moodleUserId' => $u['id'] ?? null,
                'username'     => $u['username'] ?? null,
                'fullname'     => $u['fullname'] ?? null,
                // Matched on explicitly (plan §2.2) — email is what we
                // already store/compare in tblLmsAccounts.moodleEmail;
                // username happening to equal email in this Moodle
                // instance is an implementation detail, not a guarantee.
                'email'        => $u['email'] ?? null,
            ];
        }, $rawUsers);

        return ['status' => 'ok', 'users' => $users];
    }
	
	/**
     * @return array{status:string,userId?:int,message?:string}
     */
    private function _resolveUserIdCore(string $emailOrUsername): array
    {
        $result = $this->_callMoodleWs(self::WSFUNCTION_RESOLVE_USER, [
            'field'      => 'email',
            'values'     => [$emailOrUsername],
        ]);

        if ($result['type'] === 'timeout') {
            return ['status' => 'timeout', 'message' => $result['message']];
        }
        if ($result['type'] === 'transport') {
            return ['status' => 'unavailable', 'message' => $result['message']];
        }
        if ($result['type'] === 'exception') {
            // core_user_get_users_by_field doesn't document a "not found"
            // exception (empty array is its not-found signal instead) — any
            // exception here is an unexpected Moodle-side condition, treated
            // as unavailable rather than guessed at (same rationale as
            // _resolveCourseCore()).
            return ['status' => 'unavailable', 'message' => $result['message']];
        }

        // $result['type'] === 'success' — a bare JSON array of user objects.
        $users = is_array($result['data']) ? $result['data'] : [];
        if (empty($users)) {
            return ['status' => 'not_found'];
        }

        return [
            'status' => 'found',
            'userId' => (int) $users[0]['id'],
        ];
    }

    /**
     * @return array{status:string,courses?:array,message?:string}
     */
    private function _enrolledCoursesCore(int $userId): array
    {
        $result = $this->_callMoodleWs(self::WSFUNCTION_USER_COURSES, [
            'userid' => $userId,
        ]);

        if ($result['type'] === 'timeout') {
            return ['status' => 'timeout', 'message' => $result['message']];
        }
        if ($result['type'] === 'transport') {
            return ['status' => 'unavailable', 'message' => $result['message']];
        }
        if ($result['type'] === 'exception') {
            if (($result['data']['errorcode'] ?? null) === 'invalidparameter') {
                return [
                    'status'  => 'user_not_found',
                    'message' => $result['data']['message'] ?? 'Moodle user not found.',
                ];
            }
            return ['status' => 'unavailable', 'message' => $result['message']];
        }

        // $result['type'] === 'success' — a bare JSON array of course objects.
        $rawCourses = is_array($result['data']) ? $result['data'] : [];
        $courses = array_map(function ($c) {
            return [
                'courseId'  => $c['id'] ?? null,
                'shortname' => $c['shortname'] ?? null,
                'fullname'  => $c['fullname'] ?? null,
                'progress'  => $c['progress'] ?? null,
                'visible'   => $c['visible'] ?? null,
            ];
        }, $rawCourses);

        return ['status' => 'ok', 'courses' => $courses];
    }

    /**
     * Resolves a Moodle user by email and extracts ALL FOUR Exam Permit
     * custom fields in one WS call — core_user_get_users_by_field already
     * returns every customfield on the user record regardless of which
     * one the caller cares about, so this reads all four at once rather
     * than making a caller specify a period. Sibling of
     * _resolveUserIdCore() rather than a shared refactor of it, matching
     * this controller's existing "one core per Moodle operation"
     * convention — callers that only need userId keep using
     * _resolveUserIdCore(); callers of this method pay one extra
     * round trip for the richer return shape.
     *
     * @return array{status:string,userId?:int,examPermits?:array<string,array{fieldConfigured:bool,active:?bool,rawValue:?string}>,message?:string}
     */
    private function _resolveUserExamPermitsCore(string $email): array
    {
        $result = $this->_callMoodleWs(self::WSFUNCTION_RESOLVE_USER, [
            'field'  => 'email',
            'values' => [$email],
        ]);

        if ($result['type'] === 'timeout') {
            return ['status' => 'timeout', 'message' => $result['message']];
        }
        if ($result['type'] === 'transport') {
            return ['status' => 'unavailable', 'message' => $result['message']];
        }
        if ($result['type'] === 'exception') {
            // Same rationale as _resolveUserIdCore(): no documented
            // "not found" exception for this WS function — empty array
            // is its not-found signal instead.
            return ['status' => 'unavailable', 'message' => $result['message']];
        }

        // $result['type'] === 'success' — a bare JSON array of user objects.
        $users = is_array($result['data']) ? $result['data'] : [];
        if (empty($users)) {
            return ['status' => 'not_found'];
        }

        $user = $users[0];
        $customFields = is_array($user['customfields'] ?? null) ? $user['customfields'] : [];

        return [
            'status'      => 'found',
            'userId'      => (int) $user['id'],
            'examPermits' => $this->_extractExamPermitsFromCustomFields($customFields),
        ];
    }

    /**
     * Shared by _resolveUserExamPermitsCore() (single) and
     * _resolveUserExamPermitsBulkCore() (many) — takes one Moodle user's
     * raw 'customfields' array and maps it to the four-period Exam
     * Permit shape both callers return. Pulled out so the bulk path
     * doesn't duplicate this field-mapping logic per user.
     *
     * @param array $customFields raw 'customfields' entries from a single
     *   core_user_get_users_by_field user record
     * @return array<string,array{fieldConfigured:bool,active:?bool,rawValue:?string}>
     */
    private function _extractExamPermitsFromCustomFields(array $customFields): array
    {
        $rawByShortname = [];
        foreach ($customFields as $cf) {
            if (isset($cf['shortname'])) {
                $rawByShortname[$cf['shortname']] = isset($cf['value']) ? (string) $cf['value'] : null;
            }
        }

        $examPermits = [];
        foreach (self::EXAM_PERMIT_FIELD_BY_PERIOD as $period => $shortname) {
            $configured = array_key_exists($shortname, $rawByShortname);
            $raw = $configured ? $rawByShortname[$shortname] : null;
            $examPermits[$period] = [
                'fieldConfigured' => $configured,
                // Checkbox custom fields store '1'/'0' as strings (plan
                // §Phase 0, decision 2) — anything else (missing field,
                // unexpected value) resolves to null rather than a guessed
                // boolean.
                'active'          => $configured ? ($raw === '1') : null,
                'rawValue'        => $raw,
            ];
        }

        return $examPermits;
    }

    /**
     * Bulk sibling of _resolveUserExamPermitsCore() — resolves MANY users
     * by email and extracts all four Exam Permit customfields for each,
     * in ONE core_user_get_users_by_field call. Moodle's 'values' param
     * already accepts an array (the single-user core above just always
     * passed a one-element array); the only new work here is matching
     * each returned user back to the email that was requested, since
     * Moodle silently OMITS any email it can't find rather than
     * returning a placeholder for it — so every requested email that
     * doesn't come back in the response is resolved as not-found here,
     * not left unanswered.
     *
     * @param string[] $emails
     * @return array{status:string,results?:array<string,array{found:bool,userId:?int,examPermits:?array}>,message?:string}
     */
    private function _resolveUserExamPermitsBulkCore(array $emails): array
    {
        $result = $this->_callMoodleWs(self::WSFUNCTION_RESOLVE_USER, [
            'field'  => 'email',
            'values' => $emails,
        ]);

        if ($result['type'] === 'timeout') {
            return ['status' => 'timeout', 'message' => $result['message']];
        }
        if ($result['type'] === 'transport') {
            return ['status' => 'unavailable', 'message' => $result['message']];
        }
        if ($result['type'] === 'exception') {
            // Same rationale as _resolveUserExamPermitsCore(): no
            // documented "not found" exception for this WS function —
            // Moodle just omits unmatched emails from the returned array.
            return ['status' => 'unavailable', 'message' => $result['message']];
        }

        // $result['type'] === 'success' — a bare JSON array of user
        // objects, one per email Moodle actually matched. Order is not
        // guaranteed to follow the request, and unmatched emails are
        // simply absent rather than null-padded.
        $users = is_array($result['data']) ? $result['data'] : [];

        // Moodle matches email case-insensitively, so key the lookup map
        // the same way to avoid missing a match over a casing difference
        // between what we stored and what's on the Moodle account.
        $userByLowerEmail = [];
        foreach ($users as $u) {
            if (isset($u['email'])) {
                $userByLowerEmail[strtolower((string) $u['email'])] = $u;
            }
        }

        $results = [];
        foreach ($emails as $email) {
            $user = $userByLowerEmail[strtolower($email)] ?? null;

            if ($user === null) {
                $results[$email] = ['found' => false, 'userId' => null, 'examPermits' => null];
                continue;
            }

            $customFields = is_array($user['customfields'] ?? null) ? $user['customfields'] : [];
            $results[$email] = [
                'found'       => true,
                'userId'      => (int) $user['id'],
                'examPermits' => $this->_extractExamPermitsFromCustomFields($customFields),
            ];
        }

        return ['status' => 'ok', 'results' => $results];
    }

    /**
     * Sets ONE period's Exam Permit custom field via
     * core_user_update_users. Sends ONLY the single {type, value} pair
     * for the requested period's shortname — Moodle's update_users
     * applies customfields as a partial update (fields omitted from the
     * request are left untouched), so Activating/Deactivating PRELIM
     * never touches MIDTERM/SEMIFINALS/FINALS. (Verified against the
     * real install per plan Phase 7, step 3.)
     *
     * @return array{status:string,message?:string}
     */
    private function _setUserExamPermitCore(int $userId, string $period, bool $active): array
    {
        if (!isset(self::EXAM_PERMIT_FIELD_BY_PERIOD[$period])) {
            return ['status' => 'invalid_period', 'message' => 'Unknown exam permit period: ' . $period];
        }
        $shortname = self::EXAM_PERMIT_FIELD_BY_PERIOD[$period];

        $result = $this->_callMoodleWs(self::WSFUNCTION_UPDATE_USER, [
            'users' => [
                [
                    'id'           => $userId,
                    'customfields' => [
                        ['type' => $shortname, 'value' => $active ? '1' : '0'],
                    ],
                ],
            ],
        ]);

        if ($result['type'] === 'timeout') {
            return ['status' => 'timeout', 'message' => $result['message']];
        }
        if ($result['type'] === 'transport') {
            return ['status' => 'unavailable', 'message' => $result['message']];
        }
        if ($result['type'] === 'exception') {
            $errorcode = $result['data']['errorcode'] ?? null;
            if ($errorcode === 'invalidparameter') {
                return [
                    'status'  => 'invalid',
                    'message' => $result['data']['message'] ?? 'Invalid userId.',
                ];
            }
            if (in_array($errorcode, ['nopermissions', 'required_capability_exception'], true)) {
                return [
                    'status'  => 'forbidden',
                    'message' => $result['data']['message'] ?? 'Moodle denied the update.',
                ];
            }
            return ['status' => 'unavailable', 'message' => $result['message']];
        }

        // $result['type'] === 'success' — core_user_update_users returns null.
        return ['status' => 'ok'];
    }

    /**
     * Enrolls a user into a course via the Manual enrolment plugin.
     * enrol_manual_enrol_users returns null on success — idempotent-ish:
     * re-enrolling an already-enrolled user updates rather than errors.
     *
     * @return array{status:string,message?:string}
     */
    private function _enrolUserCore(int $courseId, int $userId, ?int $roleId = null, ?int $status = null): array
    {
        $roleId = $roleId ?? (int) (getenv('MOODLE_STUDENT_ROLE_ID') ?: 5);

        $enrolment = [
            'roleid'   => $roleId,
            'userid'   => $userId,
            'courseid' => $courseId,
        ];
        // $status is omitted entirely (not sent as 0/null) on the normal
        // enrollUser()/enrollUserByShortnameAndEmail() call paths, which
        // never pass it — Moodle defaults a brand-new enrolment to ACTIVE
        // on its own, so leaving the key out here preserves those two
        // endpoints' existing behavior exactly as before this change.
        // Only _updateEnrolmentStatusCore() below ever passes a value.
        if ($status !== null) {
            $enrolment['suspend'] = $status;
        }

        $result = $this->_callMoodleWs(self::WSFUNCTION_ENROL_USER, [
            'enrolments' => [$enrolment],
        ]);

        if ($result['type'] === 'timeout') {
            return ['status' => 'timeout', 'message' => $result['message']];
        }
        if ($result['type'] === 'transport') {
            return ['status' => 'unavailable', 'message' => $result['message']];
        }
        if ($result['type'] === 'exception') {
            $errorcode = $result['data']['errorcode'] ?? null;

            if ($errorcode === 'invalidparameter') {
                return [
                    'status'  => 'invalid',
                    'message' => $result['data']['message'] ?? 'Invalid courseId, moodleUserId, or roleId.',
                ];
            }
            if (in_array($errorcode, ['nopermissions', 'required_capability_exception'], true)) {
                return [
                    'status'  => 'forbidden',
                    'message' => $result['data']['message'] ?? 'Moodle denied the enrollment (insufficient WS token permissions).',
                ];
            }
            // Covers "manual enrolment disabled on this course" and any
            // other unmapped Moodle-side condition — treated as
            // unavailable rather than guessed at (same rationale as
            // _resolveCourseCore()).
            return ['status' => 'unavailable', 'message' => $result['message']];
        }

        // $result['type'] === 'success' — enrol_manual_enrol_users returns null.
        return ['status' => 'ok'];
    }
	
	/**
     * Sets an enrolment's status (0=active, 1=suspended) by re-calling
     * enrol_manual_enrol_users — the SAME WS function _enrolUserCore()
     * uses to create enrolments in the first place, just with a status
     * flag included. Moodle's own enrol_manual_external::enrol_users()
     * treats a repeat call for an already-enrolled user/course pair as an
     * in-place update rather than a duplicate-create error, which is what
     * this relies on — there is no separate "update enrolment" WS
     * function on this Moodle install.
     *
     * CAUTION — this is NOT a pure status update: if the user/course pair
     * was never enrolled, this call enrolls them fresh at whatever
     * $status is passed (e.g. calling this with SUSPENDED on a
     * never-enrolled student silently creates a SUSPENDED enrolment
     * instead of failing). There's no separate WS function on this
     * install to cheaply check "already enrolled?" first
     * (core_enrol_get_enrolled_users is scoped per-course, not per-user,
     * and this controller's own CONNECT/TOTAL_TIMEOUT constants already
     * flag it as slow on large courses) — so this accepts that trade-off
     * rather than adding a second Moodle round trip to every status
     * change. Callers should only invoke this for a user already known
     * to be enrolled (see updateEnrollmentStatusByShortnameAndEmail()'s
     * own doc comment). Always uses the default student role (roleId
     * omitted) since a status change should never also silently change
     * someone's role.
     *
     * @return array{status:string,message?:string}
     */
    private function _updateEnrolmentStatusCore(int $courseId, int $userId, int $status): array
    {
        return $this->_enrolUserCore($courseId, $userId, null, $status);
    }

    // ==================================================================
    // Course groups — find-or-create-then-add-member. Three cores, one
    // per Moodle WS function, plus _ensureGroupCore() which composes
    // find + create with a race-safety re-check, the same composition
    // pattern used by the public enroll*ByShortnameAndEmail() methods.
    // ==================================================================

    /**
     * @return array{status:string,groupId?:int,message?:string}
     */
    private function _findGroupCore(int $courseId, string $groupName): array
    {
        $result = $this->_callMoodleWs(self::WSFUNCTION_COURSE_GROUPS, [
            'courseid' => $courseId,
        ]);

        if ($result['type'] === 'timeout') {
            return ['status' => 'timeout', 'message' => $result['message']];
        }
        if ($result['type'] === 'transport') {
            return ['status' => 'unavailable', 'message' => $result['message']];
        }
        if ($result['type'] === 'exception') {
            return ['status' => 'unavailable', 'message' => $result['message']];
        }

        $groups = is_array($result['data']) ? $result['data'] : [];
        foreach ($groups as $g) {
            // Exact, case-sensitive match — Moodle group names aren't
            // guaranteed unique in a way we can rely on, so we match
            // precisely rather than guessing at fuzzy matches.
            if (($g['name'] ?? null) === $groupName) {
                return ['status' => 'found', 'groupId' => (int) $g['id']];
            }
        }

        return ['status' => 'not_found'];
    }

    /**
     * @return array{status:string,groupId?:int,message?:string}
     */
    private function _createGroupCore(int $courseId, string $groupName): array
    {
        $result = $this->_callMoodleWs(self::WSFUNCTION_CREATE_GROUPS, [
            'groups' => [
                [
                    'courseid'          => $courseId,
                    'name'              => $groupName,
                    // Confirmed via Moodle debuginfo (4.5, this install):
                    // 'description' is enforced as a required key here,
                    // not optional-with-default as upstream docs describe.
                    // Sent explicitly (with its paired descriptionformat)
                    // so validate_parameters() never sees it missing.
                    'description'       => '',
                    // 1 == Moodle's FORMAT_HTML. That constant only exists
                    // inside Moodle itself — this app calls Moodle over
                    // HTTP and never loads Moodle's PHP, so it must be
                    // hardcoded here rather than referenced by name.
                    'descriptionformat' => 1,
                ],
            ],
        ]);

        if ($result['type'] === 'timeout') {
            return ['status' => 'timeout', 'message' => $result['message']];
        }
        if ($result['type'] === 'transport') {
            return ['status' => 'unavailable', 'message' => $result['message']];
        }
        if ($result['type'] === 'exception') {
            // Covers a duplicate-name race between _findGroupCore() and
            // here — treated as a signal to re-resolve, not a hard
            // failure (handled by the caller, _ensureGroupCore()).
            return ['status' => 'create_failed', 'message' => $result['message']];
        }

        $created = is_array($result['data']) ? $result['data'] : [];
        if (empty($created) || !isset($created[0]['id'])) {
            return ['status' => 'create_failed', 'message' => 'Moodle did not return a created group id.'];
        }

        return ['status' => 'ok', 'groupId' => (int) $created[0]['id']];
    }

    /**
     * @return array{status:string,message?:string}
     */
    private function _addGroupMemberCore(int $groupId, int $userId): array
    {
        $result = $this->_callMoodleWs(self::WSFUNCTION_ADD_GROUP_MEMBER, [
            'members' => [
                [
                    'groupid' => $groupId,
                    'userid'  => $userId,
                ],
            ],
        ]);

        if ($result['type'] === 'timeout') {
            return ['status' => 'timeout', 'message' => $result['message']];
        }
        if ($result['type'] === 'transport') {
            return ['status' => 'unavailable', 'message' => $result['message']];
        }
        if ($result['type'] === 'exception') {
            $errorcode = $result['data']['errorcode'] ?? null;
            if (in_array($errorcode, ['invalidparameter', 'invalidrecord'], true)) {
                return [
                    'status'  => 'invalid',
                    'message' => $result['data']['message'] ?? 'Invalid groupId or userId.',
                ];
            }
            return ['status' => 'unavailable', 'message' => $result['message']];
        }

        // success — core_group_add_group_members returns null.
        return ['status' => 'ok'];
    }

    /**
     * Find-or-create a group by name in a course, with a single
     * race-safety re-check if creation fails (e.g. another concurrent
     * request created the same-named group between our find and create).
     *
     * @return array{status:string,groupId?:int,created?:bool,message?:string}
     */
    private function _ensureGroupCore(int $courseId, string $groupName): array
    {
        $found = $this->_findGroupCore($courseId, $groupName);
        if ($found['status'] === 'timeout' || $found['status'] === 'unavailable') {
            return $found;
        }
        if ($found['status'] === 'found') {
            return ['status' => 'ok', 'groupId' => $found['groupId'], 'created' => false];
        }

        // not_found — attempt to create it.
        $created = $this->_createGroupCore($courseId, $groupName);
        if ($created['status'] === 'ok') {
            return ['status' => 'ok', 'groupId' => $created['groupId'], 'created' => true];
        }
        if ($created['status'] === 'timeout' || $created['status'] === 'unavailable') {
            return $created;
        }

        // create_failed — re-resolve once rather than failing outright.
        $recheck = $this->_findGroupCore($courseId, $groupName);
        if ($recheck['status'] === 'found') {
            return ['status' => 'ok', 'groupId' => $recheck['groupId'], 'created' => false];
        }

        return ['status' => 'unavailable', 'message' => $created['message'] ?? 'Unable to create or resolve the group.'];
    }

    /**
     * Best-effort group ensure+add after a successful enrollment. Never
     * escalates to an HTTP-level failure for group problems — enrollment
     * already succeeded by the time this runs, so this always returns a
     * descriptive sub-object instead of throwing the request off course.
     *
     * @return array{status:string,groupId?:int,created?:bool,message?:string}
     */
    private function _handleGroupAssignment(int $courseId, int $userId, string $groupName): array
    {
        $ensured = $this->_ensureGroupCore($courseId, $groupName);
        if ($ensured['status'] !== 'ok') {
            return ['status' => 'failed', 'message' => $ensured['message'] ?? 'Could not resolve or create the group.'];
        }

        $added = $this->_addGroupMemberCore($ensured['groupId'], $userId);
        if ($added['status'] !== 'ok') {
            return [
                'status'  => 'failed',
                'groupId' => $ensured['groupId'],
                'created' => $ensured['created'],
                'message' => $added['message'] ?? 'Group existed/created but adding the member failed.',
            ];
        }

        return [
            'status'  => 'ok',
            'groupId' => $ensured['groupId'],
            'created' => $ensured['created'],
        ];
    }

    // ==================================================================
    // 4.4 Private transport helper — the ONE method that ever talks to
    // Moodle. Builds the POST body (http_build_query() so the nested
    // options[0][name]/options[0][value] syntax core_enrol_get_enrolled_users
    // needs is encoded correctly), applies a bounded cURL timeout, and
    // detects the three response shapes Moodle can return.
    //
    // @return array{type:'success'|'exception'|'timeout'|'transport', data?:mixed, message?:string}
    // ==================================================================
    private function _callMoodleWs(string $wsfunction, array $params): array
    {
        $baseUrl = getenv('MOODLE_BASE_URL');
        $wstoken = getenv('MOODLE_WSTOKEN');

        if ($baseUrl === false || $baseUrl === '' || $wstoken === false || $wstoken === '') {
            // Fails loudly per plan §6 — never silently hits Moodle with
            // an empty credential. Surfaced to the caller as a normal
            // "transport" failure so the public methods still return the
            // documented 502 shape rather than a raw exception.
            return [
                'type'    => 'transport',
                'message' => 'Moodle is not configured (MOODLE_BASE_URL / MOODLE_WSTOKEN missing).',
            ];
        }

        $body = array_merge([
            'wstoken'          => $wstoken,
            'wsfunction'       => $wsfunction,
            'moodlewsrestformat' => 'json',
        ], $params);

        $postFields = http_build_query($body);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $baseUrl,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        //curl_close($ch);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return [
                'type'    => 'timeout',
                'message' => 'Request to Moodle timed out.',
            ];
        }

        if ($errno !== 0 || $response === false) {
            return [
                'type'    => 'transport',
                'message' => 'Unable to reach Moodle: ' . curl_strerror($errno),
            ];
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            return [
                'type'    => 'transport',
                'message' => 'Moodle returned an unexpected HTTP status (' . $httpStatus . ').',
            ];
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'type'    => 'transport',
                'message' => 'Moodle returned a response that could not be parsed.',
            ];
        }

        // Moodle's own exception shape: { "exception": ..., "errorcode": ..., "message": ..., "debuginfo": ... }
        if (is_array($decoded) && array_key_exists('exception', $decoded)) {
            // debuginfo is only populated when Moodle's own debug mode is
            // on, and is far more specific than message (e.g. names the
            // exact offending param/key). Never sent to the API client —
            // logged server-side only, so callers can diagnose without
            // exposing internal Moodle detail over HTTP.
            error_log(sprintf(
                '[MoodleController] %s failed: errorcode=%s message=%s debuginfo=%s',
                $wsfunction,
                $decoded['errorcode'] ?? 'unknown',
                $decoded['message'] ?? '',
                $decoded['debuginfo'] ?? '(debug mode off — enable it in Moodle to get detail here)'
            ));

            return [
                'type'    => 'exception',
                'data'    => $decoded,
                'message' => $decoded['message'] ?? ('Moodle error: ' . ($decoded['errorcode'] ?? 'unknown')),
            ];
        }

        return ['type' => 'success', 'data' => $decoded];
    }

    // ==================================================================
    // Response helpers — shared envelope + explicit HTTP status, matching
    // the conventions already established by StudentSubjectEnrollmentController
    // and LmsController.
    // ==================================================================
    private function _respond(int $httpStatus, array $payload): void
    {
        http_response_code($httpStatus);
        header('Content-Type: application/json');
        echo json_encode($payload);
    }

    private function _fail(int $httpStatus, string $code, string $message): void
    {
        $this->_respond($httpStatus, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}