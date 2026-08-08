<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Mapper;
use App\Models\SequenceGenerator;
use App\Services\ReferenceDataService;

// ─────────────────────────────────────────────────────────────────────────
// ReferenceDataService
//
// Read-only queries for lookup/reference tables. Keeping this logic here
// keeps boolean-default rules (isActive vs feeIsDisabled) consistent
// everywhere they're used. See each method's notes for which "blank means
// X" rule applies.
//
// This class has no knowledge of HTTP — it returns plain arrays. Callers
// are responsible for json_encode-ing the response shape they need.
// ─────────────────────────────────────────────────────────────────────────
class ReferenceDataService
{
    // Returns all active fees from tblFees, excluding disabled ones.
    // Blank/NULL feeIsDisabled is treated as NOT disabled.
    // Sorted by feeCode (falling back to feeID).
    public function getActiveFees(): array
    {
        $db = Database::getConnection();

        $stmt = $db->query("
            SELECT feeID, feeCode, feeNote
            FROM tblFees
            WHERE COALESCE(feeIsDisabled, 0) = 0
        ");
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $feeID = (string)($row['feeID'] ?? '');
            if ($feeID === '') continue;
            $feeCode = (string)($row['feeCode'] ?? '');
            $out[] = [
                'feeID'   => $feeID,
                'feeCode' => $feeCode,
                'feeNote' => (string)($row['feeNote'] ?? ''),
                'label'   => $feeCode !== '' ? $feeCode : $feeID,
            ];
        }

        usort($out, fn($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }

    // Returns all active fee templates from tblFeeTemplates.
    // Blank/NULL isActive is treated as ACTIVE.
    // NOTE: this is the OPPOSITE default from getActiveFees(), where
    // blank/NULL feeIsDisabled means NOT disabled — the two columns have
    // different blank-means semantics.
    // Sorted by label (feeTemplateCode - note).
    public function getActiveFeeTemplates(): array
    {
        $db = Database::getConnection();

        $stmt = $db->query("
            SELECT feeTemplateID, feeTemplateCode, note
            FROM tblFeeTemplates
            WHERE COALESCE(isActive, 1) = 1
        ");
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $templateId = (string)($row['feeTemplateID'] ?? '');
            if ($templateId === '') continue;
            $code = (string)($row['feeTemplateCode'] ?? '');
            $note = (string)($row['note'] ?? '');
            $label = implode(' - ', array_filter([$code, $note], fn($p) => $p !== ''));
            $out[] = [
                'feeTemplateID'   => $templateId,
                'feeTemplateCode' => $code,
                'note'            => $note,
                'label'           => $label !== '' ? $label : $templateId,
            ];
        }

        usort($out, fn($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }

    // Returns the fees belonging to a fee template, joining tblFeeTemplateFees
    // to tblFees and skipping disabled fees. An unknown feeTemplateID returns
    // an empty array.
    public function getFeeTemplateFees(string $feeTemplateID): array
    {
        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT
                tf.feeTemplatefeeID,
                tf.feeID,
                tf.cash,
                f.feeCode,
                f.feeNote,
                f.feeIsDisabled
            FROM tblFeeTemplateFees tf
            LEFT JOIN tblFees f ON f.feeID = tf.feeID
            WHERE tf.feeTemplateID = :feeTemplateID
        ");
        $stmt->execute([':feeTemplateID' => $feeTemplateID]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $disabledRaw = $row['feeIsDisabled'] ?? null;
            $isDisabled  = $disabledRaw !== null && $disabledRaw !== ''
                ? (int)$disabledRaw === 1
                : false;
            if ($isDisabled) continue;

            $amount = number_format((float)($row['cash'] ?? 0), 2, '.', '');
            $out[] = [
                'feeTemplatefeeID' => (string)($row['feeTemplatefeeID'] ?? ''),
                'feeID'            => (string)($row['feeID'] ?? ''),
                'feeCode'          => (string)($row['feeCode'] ?? ''),
                'feeNote'          => (string)($row['feeNote'] ?? ''),
                'amount'           => $amount,
                'cash'             => $amount,
            ];
        }

        return $out;
    }

    // Returns all sections from tblSections with no program/yearLevel filter.
    // Callers filter client-side against the full pre-loaded array.
    // Sorted by sectionName.
    public function getAllSections(): array
    {
        $db = Database::getConnection();

        $stmt = $db->query("
            SELECT sectionID, sectionName, programID, yearLevel
            FROM tblSections
        ");
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $sectionID = (string)($row['sectionID'] ?? '');
            if ($sectionID === '') continue;
            $sectionName = (string)($row['sectionName'] ?? '');
            $label = implode(' - ', array_filter([$sectionName, $sectionID], fn($p) => $p !== ''));
            $out[] = [
                'sectionID'   => $sectionID,
                'sectionName' => $sectionName,
                'programID'   => (string)($row['programID'] ?? ''),
                'yearLevel'   => (string)($row['yearLevel'] ?? ''),
                'label'       => $label,
            ];
        }

        usort($out, fn($a, $b) => strcmp($a['sectionName'], $b['sectionName']));
        return $out;
    }
	
	// Reads activeacademicyear and activesemester from tblAppSettings.
    // Throws if either setting is missing/blank.
    public function getActiveTerm(): array
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT settingKey, settingValue FROM tblAppSettings");
        $rows = $stmt->fetchAll();

        $settings = [];
        foreach ($rows as $row) {
            $settings[strtolower((string)$row['settingKey'])] = (string)($row['settingValue'] ?? '');
        }

        $academicYear = trim($settings['activeacademicyear'] ?? '');
        $semester     = trim($settings['activesemester']     ?? '');

        if ($academicYear === '' || $semester === '') {
            throw new \Exception('Active Academic Year and Active Semester must be configured in tblAppSettings.');
        }

        return [
                    'academicYear' => $academicYear,
                    'semester' => $semester,
                    'compactTermCode' => $this->_buildCompactTermCode($academicYear, $semester),
                ];
    }

	// Builds a compact term code from an academic year and semester label,
	// e.g. "2026-2027" + "1ST SEMESTER" -> "202627S1". Pulls the leading
	// number out of the semester string via /(\d+)/.
	private function _buildCompactTermCode(string $academicYear, string $semester): string
	{
	    $parts = explode('-', $academicYear);
	    $startYear = trim($parts[0] ?? '');
	    $endYearShort = substr(trim($parts[1] ?? ''), -2);
	    $semDigit = preg_match('/(\d+)/', $semester, $m) ? $m[1] : '0';
	    return $startYear . $endYearShort . 'S' . $semDigit;
	}
	
	// Reads lookup values from ref_lookup_values for the given categories.
    public function getLookupValues(array $categories): array
    {
        $db = Database::getConnection();
        $cats = array_map(fn($c) => strtoupper(trim((string)$c)), $categories);
        $cats = array_values(array_filter($cats, fn($c) => $c !== ''));

        $out = [];
        foreach ($cats as $c) { $out[$c] = []; }
        if (empty($cats)) return $out;

        $placeholders = implode(',', array_fill(0, count($cats), '?'));
        $stmt = $db->prepare("
            SELECT category, code, label, sortOrder
            FROM ref_lookup_values
            WHERE isActive = 1 AND category IN ($placeholders)
            ORDER BY category, sortOrder
        ");
        $stmt->execute($cats);
        foreach ($stmt->fetchAll() as $row) {
            $cat = (string)$row['category'];
            if (!isset($out[$cat])) $out[$cat] = [];
            $out[$cat][] = [
                'code'      => (string)$row['code'],
                'label'     => (string)$row['label'],
                'sortOrder' => (int)$row['sortOrder'],
            ];
        }
        return $out;
    }
	
    // Builds a student display name from a tblStudents-shaped row.
    // Format: "[lastName] [nameExtension], [firstName] [middleName]"
    // Prefers middleName over middleInitial when both are present.
    public function buildStudentFullName(array $row): string
    {
        $lastName      = trim((string)($row['lastName'] ?? ''));
        $nameExtension = trim((string)($row['nameExtension'] ?? ''));
        $firstName     = trim((string)($row['firstName'] ?? ''));
        $middleName    = trim((string)($row['middleName'] ?? '')) ?: trim((string)($row['middleInitial'] ?? ''));

        $lastPart  = implode(' ', array_filter([$lastName, $nameExtension], fn($p) => $p !== ''));
        $firstPart = implode(' ', array_filter([$firstName, $middleName], fn($p) => $p !== ''));

        return implode(', ', array_filter([$lastPart, $firstPart], fn($p) => $p !== ''));
    }

    // Returns all programs from tblPrograms, sorted by label (programCode - programDescription).
    public function getAllPrograms(): array
    {
        $db = Database::getConnection();

        $stmt = $db->query("
            SELECT programID, programCode, programDescription
            FROM tblPrograms
        ");
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            $programID = (string)($row['programID'] ?? '');
            if ($programID === '') continue;
            $code = (string)($row['programCode'] ?? '');
            $desc = (string)($row['programDescription'] ?? '');
            $label = implode(' - ', array_filter([$code, $desc], fn($p) => $p !== ''));
            $out[] = [
                'programID'          => $programID,
                'programCode'        => $code,
                'programDescription' => $desc,
                'label'              => $label !== '' ? $label : $programID,
            ];
        }

        usort($out, fn($a, $b) => strcmp($a['label'], $b['label']));
        return $out;
    }
}
