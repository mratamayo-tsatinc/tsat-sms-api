<?php

namespace App\Core;

class Mapper
{
    public static function toFrontendArray(array $row): array
    {
        return [
            'studentID'                 => $row['studentID'] ?? '',
            'studentNumber'             => $row['studentNumber'] ?? '',
            'lrn'                       => $row['lrn'] ?? '',
            'lastName'                  => $row['lastName'] ?? '',
            'firstName'                 => $row['firstName'] ?? '',
            'middleName'                => $row['middleName'] ?? '',
            'middleInitial'             => $row['middleInitial'] ?? '',
            'nameExtension'             => $row['nameExtension'] ?? '',
            'programID'                 => $row['programID'] ?? '',
            'trackID'                   => '',
            'strandID'                  => '',
            'bundleID'                  => '',
            'address'                   => $row['address'] ?? '',
            'region'                    => $row['region'] ?? '',
            'province'                  => $row['province'] ?? '',
            'cityMunicipality'          => $row['city_municipality'] ?? '',
            'barangay'                  => $row['barangay'] ?? '',
            'district'                  => $row['district'] ?? '',
            'zipCode'                   => $row['zipcode'] ?? '',
            'birthDate'                 => $row['birthDate'] ?? '',
            'birthPlace'                => $row['birthPlace'] ?? '',
            'civilStatus'               => $row['civilStatus'] ?? '',
            'religion'                  => $row['religion'] ?? '',
            'gender'                    => $row['gender'] ?? '',
            'contactNumber'             => $row['contactNumber'] ?? '',
            'telephone'                 => $row['telephone'] ?? '',
            'emailAddress'              => $row['emailAddress'] ?? '',
            'fatherName'                => $row['fatherName'] ?? '',
            'fatherAddress'             => $row['fatherAddress'] ?? '',
            'fatherContactNumber'       => $row['fatherContactNumber'] ?? '',
            'motherName'                => $row['motherName'] ?? '',
            'motherAddress'             => $row['motherAddress'] ?? '',
            'motherContactNumber'       => $row['motherContactNumber'] ?? '',
            'guardianName'              => $row['guardianName'] ?? '',
            'guardianAddress'           => $row['guardianAddress'] ?? '',
            'guardianContactNumber'     => $row['guardianContactNumber'] ?? '',
            'guardianRelationToStudent' => $row['guardianRelationToStudent'] ?? '',
            'guardianRelationship'      => $row['guardianRelationToStudent'] ?? '', // alias for frontend field i
            'lastAttendedSchool'        => $row['lastAttendedSchool'] ?? '',
            'lastAttendedSchoolAddress' => $row['lastAttendedSchoolAddress'] ?? '',
            'yearRegistered'            => $row['yearRegistered'] ?? '',
            'createdBy'                 => $row['createdBy'] ?? '',
            'dateCreated'               => $row['dateCreated'] ?? '',
            'modifiedBy'                => $row['modifiedBy'] ?? '',
            'lastModified'              => $row['lastModified'] ?? ''
        ];
    }
}
