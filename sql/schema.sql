CREATE TABLE tblStudents (

  -- Identity
  studentID         INT,                        -- numeric ID, max seen: 4 digits
  studentNumber     VARCHAR(20) PRIMARY KEY,    -- e.g. "20000008", up to 8 digits
  lrn               VARCHAR(20),                -- stored as float in sheet e.g. "9999999.0"

  -- Name
  lastName          VARCHAR(40),                -- max seen: 32 chars
  firstName         VARCHAR(30),                -- max seen: 26 chars
  middleName        VARCHAR(30),                -- max seen: 23 chars
  middleInitial     VARCHAR(5),                 -- usually 1 char, occasional "N/A"
  nameExtension     VARCHAR(10),                -- Jr, Sr, III, max seen: 10 chars

  -- Program (numeric IDs from sheet, not UUIDs)
  programID         VARCHAR(10),                -- numeric e.g. "14", stored as string for safe import
  trackID           VARCHAR(10) DEFAULT '',     -- numeric e.g. "1", stubbed for non-SHS
  strandID          VARCHAR(10) DEFAULT '',     -- numeric e.g. "2", stubbed for non-SHS
  bundleID          VARCHAR(10) DEFAULT '',     -- numeric e.g. "5", stubbed for non-SHS

  -- Address
  address           VARCHAR(100),               -- max seen: 85 chars
  region            VARCHAR(30),                -- max seen: 26 chars e.g. "REGION III (CENTRAL LUZON)"
  province          VARCHAR(30),                -- max seen: 6 chars
  city_municipality VARCHAR(80),                -- max seen: 14 chars, allow headroom
  barangay          VARCHAR(80),                -- max seen: 18 chars, allow headroom
  district          VARCHAR(10),                -- max seen: 3 chars e.g. "3RD"
  zipcode           VARCHAR(10),                -- stored as float in sheet e.g. "2317.0"

  -- Personal Info
  birthDate         VARCHAR(25),                -- sheet format: "6/28/2013" or "10/25/1987"
  birthPlace        VARCHAR(60),                -- max seen: 56 chars
  civilStatus       VARCHAR(10),                -- only "SINGLE" or "MARRIED" seen
  religion          VARCHAR(60),                -- max seen: 48 chars
  gender            VARCHAR(10),                -- mixed case in sheet: Male/MALE/Female/FEMALE

  -- Contact
  contactNumber     VARCHAR(60),                -- dirty: some contain two numbers separated by "/"
  telephone         VARCHAR(20),                -- max seen: 14 chars e.g. "045 5280076"
  emailAddress      VARCHAR(100),               -- max seen: 31 chars, some contain dirty values

  -- Father
  fatherName              VARCHAR(40),          -- max seen: 34 chars
  fatherAddress           VARCHAR(100),         -- max seen: 73 chars
  fatherContactNumber     VARCHAR(60),          -- dirty: sometimes contains addresses

  -- Mother
  motherName              VARCHAR(40),          -- max seen: 33 chars
  motherAddress           VARCHAR(100),         -- max seen: 73 chars
  motherContactNumber     VARCHAR(60),          -- dirty: same pattern as father

  -- Guardian
  guardianName                VARCHAR(60),      -- max seen: 52 chars e.g. "MR AND MRS FERRER"
  guardianAddress             VARCHAR(100),     -- max seen: 67 chars
  guardianContactNumber       VARCHAR(60),      -- dirty: same pattern
  guardianRelationToStudent   VARCHAR(20),      -- max seen: 14 chars e.g. "FATHER/SIBLING"

  -- Previous School
  lastAttendedSchool          VARCHAR(60),      -- max seen: 57 chars
  lastAttendedSchoolAddress   VARCHAR(60),      -- max seen: 51 chars

  -- Audit
  yearRegistered    CHAR(4),                    -- always 4-digit year e.g. "2013"
  createdBy         VARCHAR(35),                -- numeric user ID as string, max seen: 32 chars
  dateCreated       VARCHAR(80),                -- sheet format: "2/4/2013" or "2/4/2013 10:30:00"
  modifiedBy        VARCHAR(35),                -- numeric user ID as string, max seen: 32 chars
  lastModified      VARCHAR(80)                 -- same format as dateCreated

) ROW_FORMAT=DYNAMIC;

CREATE TABLE tblAdmissionDetails (
  studentNumber           VARCHAR(20) PRIMARY KEY,
  medicalHistory          TEXT,
  reportCardStatus        VARCHAR(20),
  reportCardUpload        VARCHAR(255),
  goodMoralStatus         VARCHAR(20),
  goodMoralUpload         VARCHAR(255),
  birthCertificateStatus  VARCHAR(20),
  birthCertificateUpload  VARCHAR(255),
  notes                   TEXT,
  createdBy               VARCHAR(35),
  dateCreated             VARCHAR(25),
  modifiedBy              VARCHAR(35),
  lastModified            VARCHAR(25)
) ROW_FORMAT=DYNAMIC;

CREATE TABLE tblStudentNumberGenerator (
  studentCount  INT UNSIGNED NOT NULL DEFAULT 0,
  academicYear  CHAR(4) PRIMARY KEY             -- always 4-digit year e.g. "2024"
) ROW_FORMAT=DYNAMIC;

CREATE TABLE tblEditLocks (
  studentNumber VARCHAR(20) PRIMARY KEY,
  lockedByEmail VARCHAR(100),
  lockedAt      VARCHAR(25),
  expiresAt     VARCHAR(25),
  sessionToken  VARCHAR(64)
) ROW_FORMAT=DYNAMIC;

CREATE TABLE tblPrograms (
  programID           VARCHAR(10) PRIMARY KEY,  -- numeric ID as string
  programCode         VARCHAR(20),
  programDescription  TEXT,
  programNote         TEXT,
  educationalLevel    VARCHAR(30),
  createdBy           VARCHAR(35),
  dateCreated         VARCHAR(25),
  modifiedBy          VARCHAR(35),
  lastModified        VARCHAR(25)
) ROW_FORMAT=DYNAMIC;

-- ============================================================================
-- Exam Permit Phase 1/2 schema sync (manual phpMyAdmin-approved structures)
-- ============================================================================

CREATE TABLE tblExamPermits (
  permitID            VARCHAR(20)  NOT NULL PRIMARY KEY,
  studentNumber       VARCHAR(20)  NOT NULL,
  registrationNumber  VARCHAR(15)  NOT NULL,
  academicYear        VARCHAR(15)  NOT NULL,
  semester            VARCHAR(20)  NOT NULL,
  period              VARCHAR(20)  NOT NULL,
  status              VARCHAR(20)  NOT NULL,
  gatePolicyID        VARCHAR(20)  NULL,
  gateDecision        VARCHAR(20)  NULL,
  gateSummary         TEXT,
  generatedBy         VARCHAR(100) NULL,
  generatedAt         VARCHAR(30)  NULL,
  lastPrintedBy       VARCHAR(100) NULL,
  lastPrintedAt       VARCHAR(30)  NULL,
  printCount          INT UNSIGNED NOT NULL DEFAULT 0,
  moodleActivatedBy   VARCHAR(100) NULL,
  moodleActivatedAt   VARCHAR(30)  NULL
) ROW_FORMAT=DYNAMIC;

ALTER TABLE tblExamPermits
  ADD INDEX idx_exampermit_student_term (studentNumber, academicYear, semester, period),
  ADD INDEX idx_exampermit_registration (registrationNumber),
  ADD INDEX idx_exampermit_status (status),
  ADD INDEX idx_exampermit_policy (gatePolicyID);

CREATE TABLE tblExamPermitAudit (
  auditID             VARCHAR(20)  NOT NULL PRIMARY KEY,
  permitID            VARCHAR(20)  NULL,
  studentNumber       VARCHAR(20)  NULL,
  registrationNumber  VARCHAR(15)  NULL,
  academicYear        VARCHAR(15)  NULL,
  semester            VARCHAR(20)  NULL,
  period              VARCHAR(20)  NULL,
  actionType          VARCHAR(40)  NOT NULL,
  outcome             VARCHAR(20)  NOT NULL,
  actorEmail          VARCHAR(100) NULL,
  actorName           VARCHAR(255) NULL,
  detail              TEXT,
  createdAt           VARCHAR(30)  NULL
) ROW_FORMAT=DYNAMIC;

ALTER TABLE tblExamPermitAudit
  ADD INDEX idx_exampermitaudit_permit (permitID),
  ADD INDEX idx_exampermitaudit_student_term (studentNumber, academicYear, semester, period),
  ADD INDEX idx_exampermitaudit_action (actionType),
  ADD INDEX idx_exampermitaudit_created (createdAt);

CREATE TABLE tblExamPermitPolicies (
  policyID            VARCHAR(20)  NOT NULL PRIMARY KEY,
  policyName          VARCHAR(150) NOT NULL,
  description         TEXT,
  activeAcademicYear  VARCHAR(15)  NULL,
  activeSemester      VARCHAR(20)  NULL,
  appliesToPeriods    VARCHAR(255) NULL,
  isEnabled           TINYINT(1)   NOT NULL DEFAULT 1,
  createdBy           VARCHAR(100) NULL,
  dateCreated         VARCHAR(30)  NULL,
  modifiedBy          VARCHAR(100) NULL,
  lastModified        VARCHAR(30)  NULL
) ROW_FORMAT=DYNAMIC;

ALTER TABLE tblExamPermitPolicies
  ADD INDEX idx_exampermitpolicy_enabled (isEnabled),
  ADD INDEX idx_exampermitpolicy_term (activeAcademicYear, activeSemester);

CREATE TABLE tblExamPermitPolicyScopes (
  policyScopeID       VARCHAR(20)  NOT NULL PRIMARY KEY,
  policyID            VARCHAR(20)  NOT NULL,
  scopeType           VARCHAR(30)  NOT NULL,
  studentNumber       VARCHAR(20)  NULL,
  programID           VARCHAR(10)  NULL,
  yearLevel           VARCHAR(20)  NULL,
  classCode           VARCHAR(50)  NULL,
  priorityOrder       INT UNSIGNED NOT NULL DEFAULT 1,
  createdBy           VARCHAR(100) NULL,
  dateCreated         VARCHAR(30)  NULL,
  modifiedBy          VARCHAR(100) NULL,
  lastModified        VARCHAR(30)  NULL
) ROW_FORMAT=DYNAMIC;

ALTER TABLE tblExamPermitPolicyScopes
  ADD INDEX idx_exampermitpolicyscope_policy (policyID),
  ADD INDEX idx_exampermitpolicyscope_type (scopeType),
  ADD INDEX idx_exampermitpolicyscope_student (studentNumber),
  ADD INDEX idx_exampermitpolicyscope_program_year (programID, yearLevel),
  ADD INDEX idx_exampermitpolicyscope_class (classCode);

CREATE TABLE tblExamPermitPolicyGroups (
  policyGroupID       VARCHAR(20)  NOT NULL PRIMARY KEY,
  policyID            VARCHAR(20)  NOT NULL,
  groupName           VARCHAR(150) NOT NULL,
  operatorType        VARCHAR(10)  NOT NULL,
  isNegated           TINYINT(1)   NOT NULL DEFAULT 0,
  sortOrder           INT UNSIGNED NOT NULL,
  description         TEXT,
  isEnabled           TINYINT(1)   NOT NULL DEFAULT 1,
  createdBy           VARCHAR(100) NULL,
  dateCreated         VARCHAR(30)  NULL,
  modifiedBy          VARCHAR(100) NULL,
  lastModified        VARCHAR(30)  NULL
) ROW_FORMAT=DYNAMIC;

ALTER TABLE tblExamPermitPolicyGroups
  ADD INDEX idx_exampermitpolicygroup_policy (policyID),
  ADD INDEX idx_exampermitpolicygroup_sort (policyID, sortOrder),
  ADD INDEX idx_exampermitpolicygroup_enabled (isEnabled);

CREATE TABLE tblExamPermitPolicyRules (
  policyRuleID        VARCHAR(20)   NOT NULL PRIMARY KEY,
  policyGroupID       VARCHAR(20)   NOT NULL,
  ruleType            VARCHAR(40)   NOT NULL,
  ruleLabel           VARCHAR(150)  NOT NULL,
  feeID               VARCHAR(20)   NULL,
  thresholdValue      DECIMAL(12,2) NULL,
  parameterText       TEXT,
  isNegated           TINYINT(1)    NOT NULL DEFAULT 0,
  sortOrder           INT UNSIGNED  NOT NULL,
  isEnabled           TINYINT(1)    NOT NULL DEFAULT 1,
  createdBy           VARCHAR(100)  NULL,
  dateCreated         VARCHAR(30)   NULL,
  modifiedBy          VARCHAR(100)  NULL,
  lastModified        VARCHAR(30)   NULL
) ROW_FORMAT=DYNAMIC;

ALTER TABLE tblExamPermitPolicyRules
  ADD INDEX idx_exampermitpolicyrule_group (policyGroupID),
  ADD INDEX idx_exampermitpolicyrule_sort (policyGroupID, sortOrder),
  ADD INDEX idx_exampermitpolicyrule_type (ruleType),
  ADD INDEX idx_exampermitpolicyrule_fee (feeID),
  ADD INDEX idx_exampermitpolicyrule_enabled (isEnabled);
