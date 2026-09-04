<?php

namespace App\Services;

use App\Core\Database;
use App\Models\SequenceGenerator;

class ExamPermitAuditService
{
    public function writeAudit(string $actionType, string $outcome, array $data = []): string
    {
        $db = Database::getConnection();
        $seq = SequenceGenerator::reserveIdBlock($db, 'tblExamPermitAudit', 1);
        $id = SequenceGenerator::formatId('EPA', $seq['firstNo'], 7);
        $stmt = $db->prepare("INSERT INTO tblExamPermitAudit
            (auditID, permitID, studentNumber, registrationNumber, academicYear, semester, period,
             actionType, outcome, actorEmail, actorName, detail, createdAt)
            VALUES (:id,:permitID,:studentNumber,:registrationNumber,:academicYear,:semester,:period,
                    :actionType,:outcome,:actorEmail,:actorName,:detail,NOW())");
        $stmt->execute([
            ':id'=>$id, ':permitID'=>$data['permitID'] ?? null, ':studentNumber'=>$data['studentNumber'] ?? null,
            ':registrationNumber'=>$data['registrationNumber'] ?? null, ':academicYear'=>$data['academicYear'] ?? null,
            ':semester'=>$data['semester'] ?? null, ':period'=>$data['period'] ?? null, ':actionType'=>$actionType,
            ':outcome'=>$outcome, ':actorEmail'=>$data['actorEmail'] ?? null, ':actorName'=>$data['actorName'] ?? null,
            ':detail'=>is_string($data['detail'] ?? null) ? ($data['detail'] ?? null) : json_encode($data['detail'] ?? null),
        ]);
        return $id;
    }
}
