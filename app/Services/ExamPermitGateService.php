<?php

namespace App\Services;

use App\Core\Database;

class ExamPermitGateService
{
    public function __construct(private ?ExamPermitWatchlistService $watchlist=null, private ?ExamPermitPolicyService $policies=null) { $this->watchlist ??=new ExamPermitWatchlistService(); $this->policies ??=new ExamPermitPolicyService(); }

    public function evaluateGate(string $studentNumber,string $academicYear,string $semester,string $period): array
    {
        $watch=$this->watchlist->findActive($studentNumber,$academicYear,$semester,$period);
        if($watch) return ['decision'=>$watch['listType']==='WHITELIST'?'ALLOW':'DENY','source'=>'WATCHLIST','policyID'=>null,'watchlistID'=>$watch['watchlistID'],'reason'=>$watch['reason']];
        $policy=$this->policies->resolve($studentNumber,$academicYear,$semester,$period);
        if(!$policy) return ['decision'=>'ALLOW','source'=>'POLICY','policyID'=>null,'watchlistID'=>null,'reason'=>'No applicable policy.'];
        $rules=$this->policies->evaluateRules($studentNumber,$academicYear,$semester,$policy);
        return ['decision'=>$rules['allow']?'ALLOW':'DENY','source'=>'POLICY','policyID'=>$policy['policyID'],'watchlistID'=>null,'reason'=>$rules['message']];
    }

    public function checkMoodleEligibility(string $studentNumber,string $academicYear,string $semester,string $period): array
    {
        $stmt=Database::getConnection()->prepare("SELECT * FROM tblExamPermits WHERE studentNumber=:sn AND academicYear=:ay AND semester=:sem AND period=:period AND status='ISSUED' ORDER BY generatedAt DESC, permitID DESC LIMIT 1");
        $stmt->execute([':sn'=>$studentNumber,':ay'=>$academicYear,':sem'=>$semester,':period'=>$period]); $permit=$stmt->fetch();
        if(!$permit) return ['eligible'=>false,'code'=>'NO_PERMIT','message'=>'No issued permit exists for this student and period.','gate'=>['decision'=>'DENY','source'=>null,'policyID'=>null,'watchlistID'=>null]];
        $gate=$this->evaluateGate($studentNumber,$academicYear,$semester,$period);
        return ['eligible'=>$gate['decision']==='ALLOW','code'=>$gate['decision']==='ALLOW'?'PERMIT_EXISTS_AND_GATE_PASS':'GATE_DENIED','message'=>$gate['reason'],'gate'=>$gate];
    }
}
