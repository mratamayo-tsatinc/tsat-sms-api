<?php

namespace App\Services;

use App\Core\Database;
use App\Models\SequenceGenerator;

class ExamPermitPolicyService
{
    private const TYPES=['GLOBAL','TERM','STUDENT','PROGRAM_YEAR','CLASS'];

    public function resolve(string $studentNumber,string $academicYear,string $semester,string $period): ?array
    {
        $db=Database::getConnection();
        $stmt=$db->prepare("SELECT p.*, r.registrationNumber, r.programID AS regProgramID, r.yearLevel AS regYearLevel, r.sectionID, sec.sectionName, pr.programCode
          FROM tblExamPermitPolicies p LEFT JOIN tblRegistrations r ON r.studentNumber=:sn AND r.academicYear=:ay AND r.semester=:sem
          LEFT JOIN tblSections sec ON sec.sectionID=r.sectionID LEFT JOIN tblPrograms pr ON pr.programID=r.programID
          WHERE p.isEnabled=1 AND (p.activeAcademicYear IS NULL OR p.activeAcademicYear=:ay2) AND (p.activeSemester IS NULL OR p.activeSemester=:sem2)
          ORDER BY p.priorityOrder DESC, p.policyID DESC");
        $stmt->execute([':sn'=>$studentNumber,':ay'=>$academicYear,':sem'=>$semester,':ay2'=>$academicYear,':sem2'=>$semester]);
        $rows=$stmt->fetchAll(); if(!$rows) return null;
        $reg=$rows[0];
        $class=($reg['programCode'] ?: $reg['regProgramID']).preg_replace('/\D+/','',$reg['regYearLevel'] ?: '0').'-'.($reg['sectionName'] ?: $reg['sectionID']);
        $policies=$rows;
        foreach($policies as $p){
            $scope=$p['scopeType'];
            $match=$scope==='GLOBAL' || ($scope==='TERM') || ($scope==='STUDENT' && $p['studentNumber']===$studentNumber) || ($scope==='PROGRAM_YEAR' && $p['programID']===$p['regProgramID'] && $p['yearLevel']===$p['regYearLevel']) || ($scope==='CLASS' && $p['classCode']===$class);
            if($match && ($p['appliesToPeriods']===null || in_array($period,array_map('trim',explode(',',$p['appliesToPeriods'])),true))) { $p['classCode']=$class; return $p; }
        }
        return null;
    }

    public function rules(string $policyID): array { $s=Database::getConnection()->prepare('SELECT * FROM tblExamPermitPolicyRules WHERE policyID=:id AND isEnabled=1 ORDER BY sortOrder, policyRuleID'); $s->execute([':id'=>$policyID]); return $s->fetchAll(); }

    public function evaluateRules(string $studentNumber,string $academicYear,string $semester,array $policy): array
    {
        foreach($this->rules($policy['policyID']) as $rule){
            if($rule['ruleType']==='PROMISSORY_NOTE_ABSENT') return ['allow'=>false,'message'=>'This rule type is not implemented.'];
            $sql="SELECT COALESCE(SUM(a.amount),0)-COALESCE((SELECT SUM(pd.Amount) FROM tblPaymentDetails pd JOIN tblAssessments a2 ON a2.assessmentID=pd.AssessmentID WHERE a2.registrationNumber=a.registrationNumber AND a2.feeID=a.feeID),0) FROM tblAssessments a WHERE a.registrationNumber IN (SELECT RegistrationNumber FROM tblRegistrations WHERE studentNumber=:sn AND academicYear=:ay AND semester=:sem)";
            $params=[':sn'=>$studentNumber,':ay'=>$academicYear,':sem'=>$semester];
            if(in_array($rule['ruleType'],['FEE_BALANCE_ZERO','FEE_PERCENT_AT_LEAST'],true)){ $sql.=' AND a.feeID=:fee'; $params[':fee']=$rule['feeID']; }
            $q=Database::getConnection()->prepare($sql); $q->execute($params); $balance=(float)$q->fetchColumn();
            $pass=$rule['ruleType']==='TOTAL_BALANCE_ZERO' || $rule['ruleType']==='FEE_BALANCE_ZERO' ? $balance <= 0.0001 : $balance <= (float)($rule['thresholdValue'] ?? 100);
            if((bool)$rule['isNegated']) $pass=!$pass;
            if(!$pass) return ['allow'=>false,'message'=>$rule['ruleLabel']];
        }
        return ['allow'=>true,'message'=>'All policy rules passed.'];
    }

    public function save(array $input): string
    {
        $db=Database::getConnection(); $scope=$input['scope']??[]; $id=trim((string)($input['policyID']??'')); $new=$id==='';
        if($new){$seq=SequenceGenerator::reserveIdBlock($db,'tblExamPermitPolicies',1);$id=SequenceGenerator::formatId('EPP',$seq['firstNo'],7);}
        $sql=$new?"INSERT INTO tblExamPermitPolicies (policyID,policyName,description,activeAcademicYear,activeSemester,appliesToPeriods,scopeType,studentNumber,programID,yearLevel,classCode,priorityOrder,isEnabled,createdBy,dateCreated) VALUES (:id,:name,:description,:ay,:sem,:periods,:scope,:student,:program,:year,:class,:priority,:enabled,:actor,NOW())":"UPDATE tblExamPermitPolicies SET policyName=:name,description=:description,activeAcademicYear=:ay,activeSemester=:sem,appliesToPeriods=:periods,scopeType=:scope,studentNumber=:student,programID=:program,yearLevel=:year,classCode=:class,priorityOrder=:priority,isEnabled=:enabled,modifiedBy=:actor,lastModified=NOW() WHERE policyID=:id";
        $db->prepare($sql)->execute([':id'=>$id,':name'=>$input['policyName'],':description'=>$input['description']??null,':ay'=>$input['activeAcademicYear']??null,':sem'=>$input['activeSemester']??null,':periods'=>implode(',',(array)($input['appliesToPeriods']??[])),':scope'=>$scope['scopeType'],':student'=>$scope['studentNumber']??null,':program'=>$scope['programID']??null,':year'=>$scope['yearLevel']??null,':class'=>$scope['classCode']??null,':priority'=>(int)($scope['priorityOrder']??1),':enabled'=>!empty($input['isEnabled'])?1:0,':actor'=>$input['actorEmail']??null]);
        foreach((array)($input['rules']??[]) as $i=>$r){ if(($r['ruleType']??'')==='PROMISSORY_NOTE_ABSENT') throw new \InvalidArgumentException('RULE_TYPE_NOT_IMPLEMENTED'); $rid=trim((string)($r['policyRuleID']??'')); if($rid===''){ $seq=SequenceGenerator::reserveIdBlock($db,'tblExamPermitPolicyRules',1);$rid=SequenceGenerator::formatId('EPRL',$seq['firstNo'],6); $db->prepare("INSERT INTO tblExamPermitPolicyRules (policyRuleID,policyID,ruleType,ruleLabel,feeID,thresholdValue,parameterText,isNegated,sortOrder,isEnabled,createdBy,dateCreated) VALUES (:rid,:pid,:type,:label,:fee,:threshold,:text,:negated,:sort,:enabled,:actor,NOW())")->execute([':rid'=>$rid,':pid'=>$id,':type'=>$r['ruleType'],':label'=>$r['ruleLabel'],':fee'=>$r['feeID']??null,':threshold'=>$r['thresholdValue']??null,':text'=>$r['parameterText']??null,':negated'=>!empty($r['isNegated'])?1:0,':sort'=>$i+1,':enabled'=>!empty($r['isEnabled'])?1:0,':actor'=>$input['actorEmail']??null]); }}
        return $id;
    }
}
