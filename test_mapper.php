<?php
// @deprecated — Development-time unit test script. Not part of the runtime API.
require_once __DIR__ . '/app/Core/Mapper.php';

use App\Core\Mapper;

$mockRow = [
    'studentID'          => '921',
    'studentNumber'      => '20000008',
    'lrn'                => '9999999.0',
    'lastName'           => 'DELA CRUZ',
    'firstName'          => 'JUAN',
    'city_municipality'  => 'TARLAC CITY',
    'zipcode'            => '2300.0',
];

$result = Mapper::toFrontendArray($mockRow);

assert($result['cityMunicipality'] === 'TARLAC CITY', 'FAIL: cityMunicipality mapping');
assert($result['zipCode']          === '2300.0',       'FAIL: zipCode mapping');
assert($result['trackID']          === '',             'FAIL: trackID must be empty string');
assert($result['strandID']         === '',             'FAIL: strandID must be empty string');
assert($result['bundleID']         === '',             'FAIL: bundleID must be empty string');

echo "All Phase 1 assertions passed.\n";
