/**
 * @deprecated — Development-time validation script for the browser console.
 * Not part of the runtime API. Kept for reference only.
 *
 * Usage:
 *   fetch('https://api.tsathub.cloud/sms/debug.js').then(r=>r.text()).then(t=>eval(t))
 */
(async function() {
  const API = 'https://api.tsathub.cloud/sms/api/admission/bootstrap';
  const GRP = 'color: #4f8ef7; font-weight: bold; font-size: 13px';
  const OK  = 'color: #4caf7d; font-weight: bold';
  const ERR = 'color: #e05c5c; font-weight: bold';
  const WRN = 'color: #f0a04b; font-weight: bold';
  const DIM = 'color: #6b7280';
  const HDR = 'color: #a78bfa; font-weight: bold';

  console.group('%c╔══ SMS Admission API Validation ══╗', GRP);

  // ── 1. FETCH MYSQL DATA ───────────────────────────────────────────
  console.group('%c── 1. MySQL API Data ──', HDR);
  let mysql;
  try {
    const res = await fetch(API);
    mysql = await res.json();
    console.log('%c✓ API responded', OK);
    console.log('%cStudents  : ' + mysql.students.length, OK);
    console.log('%cPrograms  : ' + mysql.lookups.programs.length, OK);
    console.log('%cDetails   : ' + mysql.details.length, OK);
    console.log('%cTracks    : ' + mysql.lookups.tracks.length + ' (stubbed — expected)', DIM);
    console.log('%cStrands   : ' + mysql.lookups.strands.length + ' (stubbed — expected)', DIM);
    console.log('%cBundles   : ' + mysql.lookups.bundles.length + ' (stubbed — expected)', DIM);
    console.groupCollapsed('students[ ] — first 5');
      console.table(mysql.students.slice(0, 5));
    console.groupEnd();
    console.groupCollapsed('programs[ ] — all');
      console.table(mysql.lookups.programs);
    console.groupEnd();
    console.groupCollapsed('details[ ] — first 5');
      console.table(mysql.details.slice(0, 5));
    console.groupEnd();
  } catch(e) {
    console.error('%c✗ API fetch failed: ' + e.message, ERR);
    console.groupEnd();
    console.groupEnd();
    return;
  }
  console.groupEnd();

  // ── 2. GAS DATA PROBE ─────────────────────────────────────────────
  // These are the exact variable names from admissions.html
  console.group('%c── 2. In-Memory Data Probe ──', HDR);

  // preloadedStudentIndex — lightweight index loaded on bootstrap()
  const gasIndex    = typeof preloadedStudentIndex !== 'undefined' ? preloadedStudentIndex : null;
  // programOptions — programs array loaded on bootstrap()
  const gasPrograms = typeof programOptions       !== 'undefined' ? programOptions       : null;
  // latestAdmissionData — populated after a search is run
  const gasSearch   = typeof latestAdmissionData  !== 'undefined' ? latestAdmissionData  : null;
  // currentUserEmail — logged in user
  const gasUser     = typeof currentUserEmail     !== 'undefined' ? currentUserEmail     : null;

  if (gasIndex !== null) {
    console.log('%c✓ preloadedStudentIndex : ' + gasIndex.length + ' records', OK);
    console.groupCollapsed('preloadedStudentIndex[ ] — first 5');
      console.table(gasIndex.slice(0, 5));
    console.groupEnd();
  } else {
    console.log('%c✗ preloadedStudentIndex not found — is the page fully loaded?', ERR);
  }

  if (gasPrograms !== null) {
    console.log('%c✓ programOptions : ' + gasPrograms.length + ' records', OK);
    console.groupCollapsed('programOptions[ ] — all');
      console.table(gasPrograms);
    console.groupEnd();
  } else {
    console.log('%c✗ programOptions not found', ERR);
  }

  if (gasSearch !== null) {
    console.log('%c✓ latestAdmissionData : present (search was run)', OK);
    console.groupCollapsed('latestAdmissionData');
      console.log(gasSearch);
    console.groupEnd();
  } else {
    console.log('%c— latestAdmissionData : null (no search run yet — expected on fresh load)', DIM);
  }

  if (gasUser !== null) {
    console.log('%c✓ currentUserEmail : ' + (gasUser || '(empty)'), OK);
  }

  console.groupEnd();

  // ── 3. COUNT COMPARISON ───────────────────────────────────────────
  console.group('%c── 3. Count Comparison ──', HDR);
  console.table({
    'Students (index)': {
      'MySQL (full records)' : mysql.students.length,
      'GAS (preloaded index)': gasIndex !== null ? gasIndex.length : 'not found',
      'Note'                 : 'GAS index is a lightweight subset — counts should match'
    },
    'Programs': {
      'MySQL (full records)' : mysql.lookups.programs.length,
      'GAS (programOptions)' : gasPrograms !== null ? gasPrograms.length : 'not found',
      'Note'                 : 'Should match exactly'
    }
  });

  // Count match check
  if (gasIndex !== null) {
    if (mysql.students.length === gasIndex.length) {
      console.log('%c✓ Student count matches: ' + mysql.students.length, OK);
    } else {
      console.log('%c✗ Student count MISMATCH — MySQL: ' + mysql.students.length + ' | GAS: ' + gasIndex.length, ERR);
    }
  }
  if (gasPrograms !== null) {
    if (mysql.lookups.programs.length === gasPrograms.length) {
      console.log('%c✓ Program count matches: ' + mysql.lookups.programs.length, OK);
    } else {
      console.log('%c✗ Program count MISMATCH — MySQL: ' + mysql.lookups.programs.length + ' | GAS: ' + gasPrograms.length, ERR);
    }
  }
  console.groupEnd();

  // ── 4. FIELD-LEVEL SPOT CHECK ─────────────────────────────────────
  // GAS preloadedStudentIndex is a lightweight index — compare what fields it has
  if (gasIndex !== null && gasIndex.length > 0 && mysql.students.length > 0) {
    console.group('%c── 4. Field-Level Spot Check ──', HDR);
    console.log('%cNote: GAS preloadedStudentIndex is a lightweight index.', DIM);
    console.log('%cComparing shared fields only.', DIM);

    const gasFirst   = gasIndex[0];
    const mysqlMatch = mysql.students.find(function(s) {
      return String(s.studentNumber) === String(gasFirst.studentNumber);
    });

    if (mysqlMatch) {
      const sharedFields = Object.keys(gasFirst).filter(function(k) {
        return mysqlMatch[k] !== undefined;
      });
      console.log('%cShared fields: ' + sharedFields.join(', '), DIM);

      const comparison = {};
      sharedFields.forEach(function(f) {
        const mv = mysqlMatch[f] !== undefined ? String(mysqlMatch[f]).trim() : '—';
        const gv = gasFirst[f]   !== undefined ? String(gasFirst[f]).trim()   : '—';
        comparison[f] = {
          'MySQL' : mv,
          'GAS'   : gv,
          'Match' : mv === gv ? '✓' : '✗'
        };
      });
      console.table(comparison);
    } else {
      console.log('%c✗ Could not find matching student by studentNumber.', WRN);
      console.log('%c  GAS first studentNumber: ' + gasFirst.studentNumber, DIM);
      console.log('%c  MySQL first studentNumber: ' + mysql.students[0].studentNumber, DIM);
    }
    console.groupEnd();
  }

  // ── 5. MAPPER INTEGRITY CHECK ─────────────────────────────────────
  console.group('%c── 5. Mapper Integrity Check ──', HDR);
  if (mysql.students.length > 0) {
    const s = mysql.students[0];
    const checks = [
      ['cityMunicipality key exists',  s.cityMunicipality  !== undefined],
      ['city_municipality absent',     s.city_municipality === undefined],
      ['zipCode key exists',           s.zipCode           !== undefined],
      ['zipcode absent',               s.zipcode           === undefined],
      ['trackID is empty string',      s.trackID           === ''],
      ['strandID is empty string',     s.strandID          === ''],
      ['bundleID is empty string',     s.bundleID          === ''],
    ];
    checks.forEach(function(c) {
      console.log((c[1] ? '%c✓ ' : '%c✗ ') + c[0], c[1] ? OK : ERR);
    });
  }
  console.groupEnd();

  // Store for manual inspection
  window.__smsMySQL = mysql;
  window.__smsGAS   = {
    studentIndex    : gasIndex,
    programOptions  : gasPrograms,
    latestAdmission : gasSearch,
    currentUser     : gasUser
  };

  console.log('%c╚══ Validation complete ══╝', GRP);
  console.log('%cMySQL data → window.__smsMySQL', DIM);
  console.log('%cGAS data   → window.__smsGAS', DIM);
  console.groupEnd();
})();
