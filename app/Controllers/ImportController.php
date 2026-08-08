<?php

namespace App\Controllers;

use App\Core\Database;

class ImportController
{
    private const ALLOWED_TABLES = [
        'tblStudents',
        'tblStudentNumberGenerator',
        'tblAdmissionDetails',
        'tblPrograms',
        'tblEditLocks',
        'tblRegistrations',
        'tblSections',
        'tblIDApplications',
        'tblLmsAccounts',
        'tblAssessments',
        // Reference tables that the read endpoints SELECT from:
        'tblFees',
        'tblFeeTemplates',
        'tblFeeTemplateFees',
        'tblPayments',
        'tblPaymentDetails',
	'ref_lookup_values',
	'tblIDGenerator',
        'tblAppSettings',
	'tblUsers',
    ];

    public function handle()
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['table']) || empty($input['rows'])) {
            http_response_code(400);
            echo json_encode(["error" => "Missing required fields: table, rows"]);
            return;
        }

        $table = $input['table'];
        $rows  = $input['rows'];

        // Chunked imports (large CSVs sent as several sequential requests
        // from importer.html) pass truncate:true only on the first chunk
        // and truncate:false on every chunk after that, so the table isn't
        // wiped between chunks of the same logical import. Defaults to
        // true so any existing single-shot caller behaves exactly as before.
        $truncate = array_key_exists('truncate', $input) ? (bool)$input['truncate'] : true;

        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            http_response_code(400);
            echo json_encode(["error" => "Table not allowed: {$table}"]);
            return;
        }

        if (!is_array($rows) || count($rows) === 0) {
            http_response_code(400);
            echo json_encode(["error" => "No rows provided"]);
            return;
        }

        $db = Database::getConnection();

        try {
            $db->beginTransaction();

            // Only the first chunk of a chunked import clears the table.
            // A one-shot (non-chunked) caller still gets the original
            // always-replace behaviour, since $truncate defaults to true.
            $deleted = 0;
            if ($truncate) {
                $deleted = $db->exec("DELETE FROM `{$table}`");
            }

            $columns      = array_keys($rows[0]);
            $tableColumns = $db->query("DESCRIBE `{$table}`")->fetchAll(\PDO::FETCH_COLUMN);
            $validColumns = array_values(array_filter($columns, fn($col) => in_array($col, $tableColumns, true)));

            if (empty($validColumns)) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(["error" => "No matching columns found between CSV and table"]);
                return;
            }

            $columnList = implode(', ', array_map(fn($c) => "`{$c}`", $validColumns));

            // Normalize boolean columns to MySQL tinyint.
            // Money columns (amount/cash/AmountPaid) default to 0.00 when blank.
            // Each column's blank-means behavior is documented inline.
            $normalizeValue = function ($col, $val) use ($table) {
                // tblAssessments.isActive — blank/missing => ACTIVE (1).
                if ($table === 'tblAssessments' && $col === 'isActive') {
                    $normalized = strtolower(trim((string)($val ?? '')));
                    if ($normalized === '') return 1;
                    return in_array($normalized, ['1', 'true', 'yes', 'active'], true) ? 1 : 0;
                }
                if ($table === 'tblAssessments' && ($col === 'amount' || $col === 'cash')) {
                    $trimmed = trim((string)($val ?? ''));
                    return $trimmed === '' ? '0.00' : $trimmed;
                }
		
		// tblUsers.Active — checkbox import. Blank/missing => ACTIVE (1).
                if ($table === 'tblUsers' && $col === 'Active') {
                    $normalized = strtolower(trim((string)($val ?? '')));
                    if ($normalized === '') return 1;
                    return in_array($normalized, ['1', 'true', 'yes', 'active'], true) ? 1 : 0;
                }

                // tblFees.feeIsDisabled — blank/missing => NOT disabled (0).
                // Note: this is the OPPOSITE of isActive's blank-means-true rule.
                if ($table === 'tblFees' && $col === 'feeIsDisabled') {
                    $normalized = strtolower(trim((string)($val ?? '')));
                    if ($normalized === '') return 0;
                    return in_array($normalized, ['1', 'true', 'yes'], true) ? 1 : 0;
                }

                // tblFeeTemplates.isActive — blank/missing => ACTIVE (1).
                if ($table === 'tblFeeTemplates' && $col === 'isActive') {
                    $normalized = strtolower(trim((string)($val ?? '')));
                    if ($normalized === '') return 1;
                    return in_array($normalized, ['1', 'true', 'yes', 'active'], true) ? 1 : 0;
                }

                // Reference table money columns: blank => '0.00',
                // never NULL, since all are NOT NULL DEFAULT 0.00.
                if ($table === 'tblFeeTemplateFees' && $col === 'cash') {
                    $trimmed = trim((string)($val ?? ''));
                    return $trimmed === '' ? '0.00' : $trimmed;
                }
                if ($table === 'tblPayments' && $col === 'AmountPaid') {
                    $trimmed = trim((string)($val ?? ''));
                    return $trimmed === '' ? '0.00' : $trimmed;
                }
                if ($table === 'tblPaymentDetails' && $col === 'Amount') {
                    $trimmed = trim((string)($val ?? ''));
                    return $trimmed === '' ? '0.00' : $trimmed;
                }
		if ($table === 'tblPaymentDetails' && $col === 'dateCreated') {
		    $trimmed = trim((string)($val ?? ''));
		    return $trimmed === '' ? null : $trimmed;
		}

                if ($val === '' || $val === 'NULL' || $val === 'null' || $val === null) return null;
                return $val;
            };

            $inserted = 0;
            $skipped  = 0;
            $skipLog  = [];

            // PERF: insert in batches of up to 500 rows per INSERT statement
            // instead of one execute() call per row. Row-by-row execute()
            // across 100k+ rows is what pushes a single request past the
            // PHP/webserver execution timeout, which is what causes the
            // server to return an HTML timeout/error page instead of JSON
            // (the "Unexpected token '<'" error on large CSV imports).
            // If a whole batch fails (e.g. one bad row in it), fall back to
            // row-by-row for just that batch so skipLog stays precise.
            $BATCH_SIZE     = 500;
            $singlePlaceholders = '(' . implode(', ', array_fill(0, count($validColumns), '?')) . ')';
            $singleRowStmt  = $db->prepare("INSERT INTO `{$table}` ({$columnList}) VALUES {$singlePlaceholders}");

            $rowChunks = array_chunk($rows, $BATCH_SIZE, true); // preserve keys for accurate row-number reporting

            foreach ($rowChunks as $chunk) {
                $placeholderGroups = [];
                $flatValues        = [];

                foreach ($chunk as $row) {
                    $rowValues = array_map(function ($col) use ($row, $normalizeValue) {
                        return $normalizeValue($col, $row[$col] ?? null);
                    }, $validColumns);
                    $placeholderGroups[] = $singlePlaceholders;
                    $flatValues = array_merge($flatValues, $rowValues);
                }

                try {
                    $batchStmt = $db->prepare(
                        "INSERT INTO `{$table}` ({$columnList}) VALUES " . implode(', ', $placeholderGroups)
                    );
                    $batchStmt->execute($flatValues);
                    $inserted += count($chunk);
                } catch (\Exception $e) {
                    // Batch failed — isolate the bad row(s) instead of losing the whole batch.
                    foreach ($chunk as $index => $row) {
                        try {
                            $values = array_map(function ($col) use ($row, $normalizeValue) {
                                return $normalizeValue($col, $row[$col] ?? null);
                            }, $validColumns);
                            $singleRowStmt->execute($values);
                            $inserted++;
                        } catch (\Exception $rowError) {
                            $skipped++;
                            $pk = $row[array_key_first($row)] ?? "row_" . ($index + 2);
                            $skipLog[] = [
                                "row"    => $index + 2, // +2 = 1-based + header
                                "id"     => $pk,
                                "reason" => $rowError->getMessage()
                            ];
                        }
                    }
                }
            }

            $db->commit();

            echo json_encode([
                "status"    => "success",
                "table"     => $table,
                "truncated" => $truncate,
                "deleted"   => $deleted,
                "inserted"  => $inserted,
                "skipped"   => $skipped,
                "skipLog"   => $skipLog,
            ]);

        } catch (\Exception $e) {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(["error" => "Import failed: " . $e->getMessage()]);
        }
    }
}
