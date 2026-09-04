<?php

namespace App\Services;

use App\Core\Database;
use App\Models\SequenceGenerator;

class ExamPermitWatchlistService
{
    public function findActive(string $studentNumber, string $academicYear, string $semester, string $period): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM tblExamPermitWatchlist
            WHERE studentNumber=:sn AND academicYear=:ay AND semester=:sem AND status='ACTIVE'
              AND (period IS NULL OR period=:period)
            ORDER BY (period IS NULL), dateAdded DESC, watchlistID DESC LIMIT 1");
        $stmt->execute([':sn'=>$studentNumber, ':ay'=>$academicYear, ':sem'=>$semester, ':period'=>$period]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function list(string $academicYear, string $semester, ?string $studentNumber = null, ?string $listType = null, ?string $status = null): array
    {
        $where = ['academicYear=:ay', 'semester=:sem']; $params = [':ay'=>$academicYear, ':sem'=>$semester];
        if ($studentNumber !== null && $studentNumber !== '') { $where[]='studentNumber=:sn'; $params[':sn']=$studentNumber; }
        if ($listType) { $where[]='listType=:listType'; $params[':listType']=$listType; }
        if ($status) { $where[]='status=:status'; $params[':status']=$status; }
        $stmt = Database::getConnection()->prepare('SELECT * FROM tblExamPermitWatchlist WHERE '.implode(' AND ', $where).' ORDER BY dateAdded DESC, watchlistID DESC');
        $stmt->execute($params); return $stmt->fetchAll();
    }

    public function add(array $input): string
    {
        $db=Database::getConnection(); $seq=SequenceGenerator::reserveIdBlock($db,'tblExamPermitWatchlist',1);
        $id=SequenceGenerator::formatId('EPW',$seq['firstNo'],7);
        $stmt=$db->prepare("INSERT INTO tblExamPermitWatchlist (watchlistID,studentNumber,listType,reason,academicYear,semester,period,status,addedBy,dateAdded) VALUES (:id,:sn,:type,:reason,:ay,:sem,:period,'ACTIVE',:by,NOW())");
        $stmt->execute([':id'=>$id,':sn'=>$input['studentNumber'],':type'=>$input['listType'],':reason'=>$input['reason'],':ay'=>$input['academicYear'],':sem'=>$input['semester'],':period'=>$input['period'] ?? null,':by'=>$input['actorEmail'] ?? null]);
        return $id;
    }

    public function remove(string $id, string $actorEmail): bool
    {
        $stmt=Database::getConnection()->prepare("UPDATE tblExamPermitWatchlist SET status='REMOVED', removedBy=:by, dateRemoved=NOW() WHERE watchlistID=:id AND status='ACTIVE'");
        $stmt->execute([':by'=>$actorEmail,':id'=>$id]); return $stmt->rowCount() > 0;
    }
}
