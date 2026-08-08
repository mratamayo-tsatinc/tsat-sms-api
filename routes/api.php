<?php
 
return [
    'GET' => [
        'api/admission/bootstrap'         => [\App\Controllers\AdmissionController::class,   'bootstrap'],
        'api/admission/students'          => [\App\Controllers\AdmissionController::class,   'students'],
        'api/admission/search'            => [\App\Controllers\AdmissionController::class,   'search'],
		'api/admission/lookups'           => [\App\Controllers\AdmissionController::class,   'lookups'],
        'api/admission/programs'          => [\App\Controllers\EnrollmentController::class,  'programs'],
        'api/admission/next-number'       => [\App\Controllers\AdmissionController::class,   'nextNumberPreview'],
        'api/admission/lock/active'       => [\App\Controllers\EditLockController::class,    'active'],
		'api/geo/provinces'               => [\App\Controllers\PsgcController::class,         'provinces'],
        'api/geo/cities'                  => [\App\Controllers\PsgcController::class,         'cities'],
        'api/geo/barangays'               => [\App\Controllers\PsgcController::class,         'barangays'],
        'api/enrollment/bootstrap'        => [\App\Controllers\EnrollmentController::class,  'bootstrap'],
		'api/enrollment/active-term' 	  => [\App\Controllers\EnrollmentController::class, 'activeTerm'],	
        'api/enrollment/summary'          => [\App\Controllers\EnrollmentController::class,  'summary'],
        'api/enrollment/roster'           => [\App\Controllers\EnrollmentController::class,  'roster'],
        'api/enrollment/students'         => [\App\Controllers\EnrollmentController::class,  'students'],
		'api/enrollment/search'           => [\App\Controllers\EnrollmentController::class,  'search'],
        'api/enrollment/fees'             => [\App\Controllers\EnrollmentController::class,  'fees'],
        'api/enrollment/fee-templates'    => [\App\Controllers\EnrollmentController::class,  'feeTemplates'],
        'api/enrollment/fee-template-fees'=> [\App\Controllers\EnrollmentController::class,  'feeTemplateFees'],
        'api/enrollment/sections'         => [\App\Controllers\EnrollmentController::class,  'sections'],
        'api/enrollment/programs'         => [\App\Controllers\EnrollmentController::class,  'programs'],
        'api/id/bootstrap'                => [\App\Controllers\IdController::class,          'bootstrap'],
        'api/id/student'                  => [\App\Controllers\IdController::class,          'student'],
        'api/id/enrolled'                 => [\App\Controllers\IdController::class,          'enrolled'],
        'api/id/student-index'            => [\App\Controllers\IdController::class,          'studentIndex'],
        // Application-wide active term, stored in tblAppSettings.
        'api/id/active-term'              => [\App\Controllers\EnrollmentController::class,  'activeTerm'],
        // All ID applications joined with student, registration, program,
        // and section data, formatted for card export. Not filtered by term.
        'api/id/applications/export'      => [\App\Controllers\IdController::class,          'applicationList'],
        'api/lms/bootstrap'               => [\App\Controllers\LmsController::class,         'bootstrap'],
        'api/lms/accounts'                => [\App\Controllers\LmsController::class,         'accounts'],
        // Application-wide active term, stored in tblAppSettings.
        'api/lms/active-term'             => [\App\Controllers\EnrollmentController::class,  'activeTerm'],
        
        // ── Moodle (live REST integration) ──
        // GET on our API even though MoodleController internally issues
        // POST requests against Moodle's own REST protocol.
        'api/moodle/courses/resolve'                     => [\App\Controllers\MoodleController::class, 'resolveCourseId'],
        'api/moodle/courses/enrolled-users'               => [\App\Controllers\MoodleController::class, 'enrolledUsers'],
        'api/moodle/courses/enrolled-users-by-shortname'  => [\App\Controllers\MoodleController::class, 'enrolledUsersByShortname'],
		// Resolves a Moodle userid from email, then fetches that user's
        // enrolled courses (our system only stores email, not Moodle's userid).
        'api/moodle/users/enrolled-courses'               => [\App\Controllers\MoodleController::class, 'enrolledCoursesByEmail'],         

        // Exam Permit — reads all 4 grading-period checkbox custom
        // profile fields in one call (core_user_get_users_by_field
        // already returns every customfield on the user record).
        'api/moodle/users/exam-permit-status'             => [\App\Controllers\MoodleController::class, 'examPermitStatusByEmail'],

        // ── Accounts module ──
        // Reference/lookup endpoints aliased to EnrollmentController —
        // tblFees, tblSections, tblPrograms, tblStudents are shared,
        // module-agnostic tables.
        'api/accounts/fees'               => [\App\Controllers\EnrollmentController::class,  'fees'],
        'api/accounts/fee-templates'      => [\App\Controllers\EnrollmentController::class,  'feeTemplates'],
        'api/accounts/fee-template-fees'  => [\App\Controllers\EnrollmentController::class,  'feeTemplateFees'],
        'api/accounts/sections'           => [\App\Controllers\EnrollmentController::class,  'sections'],
        'api/accounts/programs'           => [\App\Controllers\EnrollmentController::class,  'programs'],
        'api/accounts/students'           => [\App\Controllers\EnrollmentController::class,  'students'],
        // Distinct academicYear/semester combinations from tblRegistrations,
        // for term-filter dropdowns. Not restricted to the active term.
        'api/accounts/filters'            => [\App\Controllers\AccountsController::class,    'filters'],
        // Full-detail search — includes registrations, assessments, and
        // payments. Can be scoped to a specific term or all terms.
        'api/accounts/search'             => [\App\Controllers\AccountsController::class,    'search'],
 
        // ── Cashier module ──
        // tblFees is a shared, module-agnostic table.
        'api/cashier/fees'                => [\App\Controllers\EnrollmentController::class,  'fees'],
        // Application-wide active term, stored in tblAppSettings.
        'api/cashier/active-term'         => [\App\Controllers\EnrollmentController::class,  'activeTerm'],
        // Student typeahead scoped to a specific academicYear/semester —
        // only returns students with a matching registration.
        'api/cashier/students'            => [\App\Controllers\CashierController::class,     'students'],
        // Outstanding assessment balances for a student in the active term.
        'api/cashier/balances'            => [\App\Controllers\CashierController::class,     'balances'],
		'api/cashier/payment-history' => [\App\Controllers\CashierController::class, 'paymentHistory'],
		'api/cashier/receipt'         => [\App\Controllers\CashierController::class, 'receipt'],
		'api/cashier/summary/bootstrap'   => [\App\Controllers\CashierSummaryController::class, 'bootstrap'],
        'api/cashier/summary/report'      => [\App\Controllers\CashierSummaryController::class, 'report'],
        // Outstanding Balance tab filter reference data (programs, sections,
        // year levels), derived from live active-term enrollment rather than
        // the full reference tables.
        'api/cashier/outstanding-balance/bootstrap' => [\App\Controllers\CashierSummaryController::class, 'outstandingBalanceBootstrap'],
        // Powers both card views (mode=account|fee).
        'api/cashier/outstanding-balance/report'    => [\App\Controllers\CashierSummaryController::class, 'outstandingBalanceReport'],
	
		// ── Loading module: Teachers ──
		'api/subject-loading/teachers'        => [\App\Controllers\SubjectLoading\TeacherController::class, 'list'],
    	'api/subject-loading/teachers/search' => [\App\Controllers\SubjectLoading\TeacherController::class, 'search'],

   	 	// ── Loading module: Subjects ──
    	'api/subject-loading/subjects'        => [\App\Controllers\SubjectLoading\SubjectController::class, 'list'],

    	// ── Loading module: shared bootstrap/reference ──
    	'api/subject-loading/bootstrap'       => [\App\Controllers\SubjectLoading\LoadingController::class, 'bootstrap'],
		'api/subject-loading/active-term'     => [\App\Controllers\SubjectLoading\LoadingController::class, 'activeTerm'],
    	'api/subject-loading/classes'         => [\App\Controllers\SubjectLoading\LoadingController::class, 'classes'],
    	'api/subject-loading/programs'        => [\App\Controllers\SubjectLoading\LoadingController::class, 'programs'],
    	'api/subject-loading/sections'        => [\App\Controllers\SubjectLoading\LoadingController::class, 'sections'],
    	'api/subject-loading/class-roster'    => [\App\Controllers\SubjectLoading\LoadingController::class, 'classRoster'],
	
		// ── Teacher Subject Loads ──
    	'api/subject-loading/teacher-subject-loads' => [\App\Controllers\SubjectLoading\TeacherSubjectLoadController::class, 'list'],

    	// ── Teacher Class Loads (a.k.a. Subject Offerings) ──
    	'api/subject-loading/teacher-class-loads'   => [\App\Controllers\SubjectLoading\TeacherClassLoadController::class, 'list'],
    	'api/subject-loading/offerings/search'      => [\App\Controllers\SubjectLoading\TeacherClassLoadController::class, 'searchOfferings'],

		// ── Class Schedule (rooms/day/time for a Subject Offering) ──
		// Read-only. Composes the shared App\Services\ScheduleReferenceDataService.
		'api/subject-loading/class-schedule/for-offering' => [\App\Controllers\SubjectLoading\ClassScheduleController::class, 'forOffering'],

		// ── Rooms ──
		// Read-only. Composes App\Services\ScheduleReferenceDataService::getAllRooms().
		'api/subject-loading/rooms' => [\App\Controllers\SubjectLoading\RoomController::class, 'list'],

		// ── Student subject enrollment / roster ──
    	'api/subject-loading/subject-roster'        => [\App\Controllers\SubjectLoading\StudentSubjectEnrollmentController::class, 'roster'],
    	'api/subject-loading/student-loads'         => [\App\Controllers\SubjectLoading\StudentSubjectEnrollmentController::class, 'studentLoads'],

		// ── Irregular Enrollment (Cross-Class Subject Enrollment) ──
		// Thin wrapper around ReferenceDataService::getLookupValues(['ENROLLMENT_REASON']).
		'api/subject-loading/enrollment-reasons'    => [\App\Controllers\SubjectLoading\StudentSubjectEnrollmentController::class, 'enrollmentReasons'],
		
		// Offerings scoped to one class, with live enrolled/not-yet-enrolled
    	// counts — powers the Class-Based Enrollment tab.
    	'api/subject-loading/class-offerings'       => [\App\Controllers\SubjectLoading\StudentSubjectEnrollmentController::class, 'classOfferings'],
		'api/subject-loading/class-offerings/all'   => [\App\Controllers\SubjectLoading\StudentSubjectEnrollmentController::class, 'classOfferingsAll'],

		// Term-scoped student typeahead for cross-class enrollment search.
		'api/subject-loading/students/search'       => [\App\Controllers\SubjectLoading\StudentSubjectEnrollmentController::class, 'searchStudents'],
		
		// ── WLP module ──
		'api/wlp/bootstrap'              => [\App\Controllers\Wlp\WlpController::class, 'bootstrap'],
		// Aliases to the shared, module-agnostic teacher/load lookups.
		'api/wlp/teachers'               => [\App\Controllers\SubjectLoading\TeacherController::class, 'list'],
		'api/wlp/teacher-subject-loads'  => [\App\Controllers\SubjectLoading\TeacherSubjectLoadController::class, 'list'],

		// ── ECR module ──
		'api/ecr/bootstrap'              => [\App\Controllers\Ecr\EcrController::class, 'bootstrap'],
		'api/ecr/teachers'               => [\App\Controllers\SubjectLoading\TeacherController::class, 'list'],
		'api/ecr/teacher-subject-loads'  => [\App\Controllers\SubjectLoading\TeacherSubjectLoadController::class, 'list'],
		'api/ecr/teacher-class-loads'    => [\App\Controllers\SubjectLoading\TeacherClassLoadController::class, 'list'],
		'api/ecr/class-roster'           => [\App\Controllers\SubjectLoading\LoadingController::class, 'classRoster'],

		// ── ECR Drive-state mirror (read) — ECR Drive-State Mirror plan, Phase 2 ──
		'api/ecr/mirror/teacher-folders' => [\App\Controllers\Ecr\EcrMirrorController::class, 'teacherFolders'],
		'api/ecr/mirror/files'           => [\App\Controllers\Ecr\EcrMirrorController::class, 'files'],
		'api/ecr/mirror/roster'          => [\App\Controllers\Ecr\EcrMirrorController::class, 'roster'],
		'api/ecr/mirror/roster-counts'   => [\App\Controllers\Ecr\EcrMirrorController::class, 'rosterCounts'],
		'api/ecr/mirror/attendance'      => [\App\Controllers\Ecr\EcrAttendanceMirrorController::class, 'attendance'],
		'api/ecr/mirror/attendance-stats' => [\App\Controllers\Ecr\EcrAttendanceMirrorController::class, 'attendanceStats'],

		// ── Exam Permit module (read-only) ──
		'api/exam-permit/bootstrap' => [\App\Controllers\ExamPermit\ExamPermitController::class, 'bootstrap'],
		'api/exam-permit/students'  => [\App\Controllers\ExamPermit\ExamPermitController::class, 'students'],
		'api/exam-permit/permit'    => [\App\Controllers\ExamPermit\ExamPermitController::class, 'permit'],
    ],
    'POST' => [
        'api/admission/store'        => [\App\Controllers\AdmissionController::class,   'store'],
        'api/admission/mirror'       => [\App\Controllers\AdmissionController::class,   'mirror'],
        'api/admission/students/stub'   => [\App\Controllers\AdmissionController::class, 'createStub'],
        'api/admission/lock/acquire'    => [\App\Controllers\EditLockController::class,  'acquire'],
        'api/admission/lock/release'    => [\App\Controllers\EditLockController::class,  'release'],
        'api/admission/students/update' => [\App\Controllers\AdmissionController::class, 'updateStudent'],
        'api/enrollment/mirror'      => [\App\Controllers\EnrollmentController::class,  'mirror'],
		'api/enrollment/registrations'        => [\App\Controllers\EnrollmentController::class,  'createRegistration'],
        'api/enrollment/registrations/update' => [\App\Controllers\EnrollmentController::class,  'updateRegistration'],
        'api/enrollment/assessments/commit'   => [\App\Controllers\EnrollmentController::class,  'commitAssessmentDrafts'],
        'api/id/mirror'              => [\App\Controllers\IdController::class,          'mirror'],
        'api/id/applications'        => [\App\Controllers\IdController::class,          'createApplication'],
        'api/id/applications/status' => [\App\Controllers\IdController::class,          'updateApplicationStatus'],
        'api/id/applications/print-status' => [\App\Controllers\IdController::class,    'updatePrintStatus'],
        'api/lms/mirror'             => [\App\Controllers\LmsController::class,         'mirror'],
		'api/lms/accounts/process'   => [\App\Controllers\LmsController::class,         'processAccount'],
		'api/lms/accounts/release'   => [\App\Controllers\LmsController::class,         'releaseAccount'],
		'api/lms/accounts/complete'  => [\App\Controllers\LmsController::class,         'completeAccount'],
		'api/lms/accounts/import/workspace' => [\App\Controllers\LmsController::class,   'importWorkspaceCsv'],
		'api/lms/accounts/import/moodle'    => [\App\Controllers\LmsController::class,   'importMoodleCsv'],

        // ── Moodle (live REST integration) — writes ──
        // These mutate Moodle state (enrol_manual_enrol_users, and
        // optionally core_group_create_groups / core_group_add_group_members).
        // groupName is an optional field on both bodies.
        'api/moodle/courses/enroll-user'          => [\App\Controllers\MoodleController::class, 'enrollUser'],
        'api/moodle/courses/enroll-user-by-email' => [\App\Controllers\MoodleController::class, 'enrollUserByShortnameAndEmail'],
		
		// Sets an existing manual enrolment's status to ACTIVE or SUSPENDED.
        // No separate "update enrolment" WS function exists on this Moodle
        // install — this re-calls enrol_manual_enrol_users with a status
        // flag, which Moodle applies as an in-place update rather than a
        // duplicate create for an already-enrolled user/course pair. See
        // MoodleController::updateEnrollmentStatusByShortnameAndEmail() for
        // the caveat this implies.
        'api/moodle/courses/enrollment-status-by-email' => [\App\Controllers\MoodleController::class, 'updateEnrollmentStatusByShortnameAndEmail'],

        // Exam Permit — writes exactly ONE grading period's checkbox
        // custom profile field per call (core_user_update_users applies
        // customfields as a partial update, so this never touches the
        // other three periods). Body: { email, period, active }.
        'api/moodle/users/exam-permit-status' => [\App\Controllers\MoodleController::class, 'updateExamPermitStatusByEmail'],
		
        'api/import'                 => [\App\Controllers\ImportController::class,       'handle'],
 
        // Alias to EnrollmentController::mirror() — handles receipt_create
        // and receipt_detail_create writes for the Accounts module.
        'api/accounts/mirror'        => [\App\Controllers\EnrollmentController::class,  'mirror'],

        // Creates an assessment line on an existing registration.
        'api/accounts/assessments'   => [\App\Controllers\AccountsController::class,   'createAssessment'],

        // Creates a payment receipt on an existing registration.
        'api/accounts/receipts'      => [\App\Controllers\AccountsController::class,   'createReceipt'],

        // Allocates a portion of a receipt toward a specific assessment line.
        // Enforces receipt-remaining and assessment-remaining over-allocation guards.
        'api/accounts/receipt-details' => [\App\Controllers\AccountsController::class, 'createReceiptDetail'],
 
        // Adds a new assessment line to an active-term registration.
        'api/cashier/assessments'    => [\App\Controllers\CashierController::class,   'createAssessment'],

        // Alias to EnrollmentController::mirror() — handles assessment_create,
        // receipt_create, and receipt_detail_create writes for Cashier.
        'api/cashier/mirror'         => [\App\Controllers\EnrollmentController::class,  'mirror'],
		'api/cashier/payments' => [\App\Controllers\CashierController::class, 'createPayment'],

		'api/subject-loading/teachers'        => [\App\Controllers\SubjectLoading\TeacherController::class, 'store'],
    	'api/subject-loading/teachers/update' => [\App\Controllers\SubjectLoading\TeacherController::class, 'update'],
    	'api/subject-loading/teachers/status' => [\App\Controllers\SubjectLoading\TeacherController::class, 'setActive'],

   		'api/subject-loading/subjects'        => [\App\Controllers\SubjectLoading\SubjectController::class, 'store'],
    	'api/subject-loading/subjects/update' => [\App\Controllers\SubjectLoading\SubjectController::class, 'update'],
    	'api/subject-loading/subjects/status' => [\App\Controllers\SubjectLoading\SubjectController::class, 'setActive'],

		'api/subject-loading/teacher-subject-loads'        => [\App\Controllers\SubjectLoading\TeacherSubjectLoadController::class, 'create'],
    	'api/subject-loading/teacher-subject-loads/update' => [\App\Controllers\SubjectLoading\TeacherSubjectLoadController::class, 'update'],
    	'api/subject-loading/teacher-subject-loads/status' => [\App\Controllers\SubjectLoading\TeacherSubjectLoadController::class, 'setActive'],
		// Bulk counterpart to create() — assigns several subjects to one teacher at once.
		'api/subject-loading/teacher-subject-loads/bulk'   => [\App\Controllers\SubjectLoading\TeacherSubjectLoadController::class, 'assignSubjects'],

    	'api/subject-loading/teacher-class-loads'          => [\App\Controllers\SubjectLoading\TeacherClassLoadController::class, 'assignClass'],
		'api/subject-loading/teacher-class-loads/bulk'     => [\App\Controllers\SubjectLoading\TeacherClassLoadController::class, 'assignClasses'],
    	'api/subject-loading/teacher-class-loads/status'   => [\App\Controllers\SubjectLoading\TeacherClassLoadController::class, 'setActive'],

		// ── Student Subject Loading ──
    	'api/subject-loading/student-loads/enroll'          => [\App\Controllers\SubjectLoading\StudentSubjectEnrollmentController::class, 'enroll'],
    	'api/subject-loading/student-loads/bulk-enroll'     => [\App\Controllers\SubjectLoading\StudentSubjectEnrollmentController::class, 'bulkEnroll'],

		// "Enroll All Unenrolled" — enrolls every currently-registered,
    	// not-yet-enrolled student in a class into one Subject Offering.
    	'api/subject-loading/student-loads/enroll-class'    => [\App\Controllers\SubjectLoading\StudentSubjectEnrollmentController::class, 'enrollClass'],

    	// Accepts EITHER a single studentNumber (per-student sync) OR a class
    	// selector {programID, yearLevel, sectionID} (whole-class sync).
    	'api/subject-loading/student-loads/sync-home-class' => [\App\Controllers\SubjectLoading\StudentSubjectEnrollmentController::class, 'syncHomeClass'],
    	// Generic ENROLLED / DROPPED / CREDITED status transition.
    	'api/subject-loading/student-loads/status'          => [\App\Controllers\SubjectLoading\StudentSubjectEnrollmentController::class, 'setStatus'],

		// ── ECR Drive-state mirror (write) — ECR Drive-State Mirror plan, Phase 2 ──
		'api/ecr/mirror/teacher-folders/sync'      => [\App\Controllers\Ecr\EcrMirrorController::class, 'syncTeacherFolder'],
		'api/ecr/mirror/teacher-folders/sync-bulk' => [\App\Controllers\Ecr\EcrMirrorController::class, 'syncTeacherFoldersBulk'],
		'api/ecr/mirror/teacher-folders/override'  => [\App\Controllers\Ecr\EcrMirrorController::class, 'overrideTeacherFolder'],
		'api/ecr/mirror/files/sync'                => [\App\Controllers\Ecr\EcrMirrorController::class, 'syncFile'],
		'api/ecr/mirror/files/sync-bulk'           => [\App\Controllers\Ecr\EcrMirrorController::class, 'syncFilesBulk'],
		'api/ecr/mirror/files/override'            => [\App\Controllers\Ecr\EcrMirrorController::class, 'overrideFile'],
		'api/ecr/mirror/roster/sync-bulk'          => [\App\Controllers\Ecr\EcrMirrorController::class, 'syncRosterBulk'],
		'api/ecr/mirror/roster/upsert'             => [\App\Controllers\Ecr\EcrMirrorController::class, 'upsertRosterRow'],
		'api/ecr/mirror/roster/upsert-bulk'        => [\App\Controllers\Ecr\EcrMirrorController::class, 'upsertRosterRowsBulk'],
		'api/ecr/mirror/attendance/sync-bulk'      => [\App\Controllers\Ecr\EcrAttendanceMirrorController::class, 'syncAttendanceBulk'],
    ],
];