<?php

namespace App\Models;

use PDO;

class SequenceGenerator
{
    // Reserves a BLOCK of `count` sequential integers from tblIDGenerator
    // for the given table name. Uses SELECT ... FOR UPDATE to serialize
    // concurrent callers for the same table name; firstNo..lastNo are
    // exclusively owned by the caller once this returns.
    public static function reserveIdBlock(PDO $db, string $tableName, int $count = 1): array
    {
        $count = max(1, $count);
        $ownsTransaction = !$db->inTransaction();

        try {
            if ($ownsTransaction) $db->beginTransaction();

            $stmt = $db->prepare("SELECT NextNo FROM tblIDGenerator WHERE TableName = ? FOR UPDATE");
            $stmt->execute([$tableName]);
            $row = $stmt->fetch();

            if (!$row) {
                throw new \Exception('tblIDGenerator has no entry for table: ' . $tableName);
            }

            $firstNo = (int)$row['NextNo'];
            $lastNo  = $firstNo + $count - 1;
            $newNext = $lastNo + 1;

            $upd = $db->prepare("UPDATE tblIDGenerator SET NextNo = ? WHERE TableName = ?");
            $upd->execute([$newNext, $tableName]);

            if ($ownsTransaction) $db->commit();

            return ['firstNo' => $firstNo, 'lastNo' => $lastNo];
        } catch (\Exception $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    // Formats a reserved integer into a prefixed, zero-padded string ID.
    public static function formatId(string $prefix, int $number, int $padWidth = 6): string
    {
        return $prefix . str_pad((string)$number, $padWidth, '0', STR_PAD_LEFT);
    }

    public static function reserveSequence(PDO $db, string $academicYear): int
    {
        try {
            $db->beginTransaction();

            $stmt = $db->prepare(
                "SELECT studentCount FROM tblStudentNumberGenerator
                 WHERE academicYear = ? FOR UPDATE"
            );
            $stmt->execute([$academicYear]);
            $row = $stmt->fetch();

            if (!$row) {
                $currentCount = 0;
                $db->prepare(
                    "INSERT INTO tblStudentNumberGenerator (studentCount, academicYear)
                     VALUES (?, ?)"
                )->execute([$currentCount + 1, $academicYear]);
            } else {
                $currentCount = (int) $row['studentCount'];
                $db->prepare(
                    "UPDATE tblStudentNumberGenerator
                     SET studentCount = ?
                     WHERE academicYear = ?"
                )->execute([$currentCount + 1, $academicYear]);
            }

            $db->commit();
            return $currentCount + 1;

        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
