CREATE TABLE IF NOT EXISTS tblAssessments (
    assessmentID        VARCHAR(20)   NOT NULL,
    registrationNumber  VARCHAR(20)   NULL,
    feeID                VARCHAR(20)   NULL,
    amount               DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cash                 DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    note                 VARCHAR(500)  NULL,
    isActive             TINYINT(1)    NOT NULL DEFAULT 1,
    createdBy            VARCHAR(100)  NULL,
    dateCreated          DATETIME      NULL,
    modifiedBy           VARCHAR(100)  NULL,
    lastModified         DATETIME      NULL,
    PRIMARY KEY (assessmentID),
    KEY idx_assessments_registrationNumber (registrationNumber),
    KEY idx_assessments_feeID (feeID),
    KEY idx_assessments_isActive (isActive)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
