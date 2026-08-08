<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SMS — CSV Importer</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500&display=swap');

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #0f1117;
    --surface:   #181c27;
    --border:    #2a2f3d;
    --accent:    #4f8ef7;
    --danger:    #e05c5c;
    --success:   #4caf7d;
    --warning:   #f0a04b;
    --text:      #e2e6f0;
    --muted:     #6b7280;
    --mono:      'IBM Plex Mono', monospace;
    --sans:      'IBM Plex Sans', sans-serif;
  }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--sans);
    font-weight: 300;
    min-height: 100vh;
    padding: 40px 24px;
  }

  header {
    max-width: 760px;
    margin: 0 auto 48px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 24px;
  }

  header .eyebrow {
    font-family: var(--mono);
    font-size: 11px;
    letter-spacing: 0.12em;
    color: var(--accent);
    text-transform: uppercase;
    margin-bottom: 8px;
  }

  header h1 {
    font-size: 28px;
    font-weight: 500;
    letter-spacing: -0.02em;
    color: var(--text);
  }

  header p {
    margin-top: 8px;
    font-size: 14px;
    color: var(--muted);
    line-height: 1.6;
  }

  .card {
    max-width: 760px;
    margin: 0 auto 24px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 28px;
  }

  .card-title {
    font-family: var(--mono);
    font-size: 11px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 20px;
  }

  .field { margin-bottom: 20px; }

  label {
    display: block;
    font-size: 13px;
    font-weight: 500;
    color: var(--text);
    margin-bottom: 8px;
  }

  label span {
    font-family: var(--mono);
    font-size: 11px;
    color: var(--muted);
    font-weight: 400;
    margin-left: 6px;
  }

  select {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    font-family: var(--sans);
    font-size: 14px;
    padding: 10px 14px;
    outline: none;
    transition: border-color 0.15s;
  }

  select:focus { border-color: var(--accent); }
  select option { background: var(--surface); }

  .drop-zone {
    border: 1.5px dashed var(--border);
    border-radius: 6px;
    padding: 32px;
    text-align: center;
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
    position: relative;
  }

  .drop-zone:hover, .drop-zone.dragover {
    border-color: var(--accent);
    background: rgba(79, 142, 247, 0.04);
  }

  .drop-zone input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
    border: none;
  }

  .drop-icon { font-size: 28px; margin-bottom: 10px; opacity: 0.4; }
  .drop-label { font-size: 14px; color: var(--muted); }
  .drop-label strong { color: var(--accent); font-weight: 500; }
  .file-selected { margin-top: 10px; font-family: var(--mono); font-size: 12px; color: var(--success); }

  .warning-box {
    background: rgba(240, 160, 75, 0.08);
    border: 1px solid rgba(240, 160, 75, 0.3);
    border-radius: 6px;
    padding: 14px 16px;
    font-size: 13px;
    color: var(--warning);
    line-height: 1.6;
    margin-bottom: 20px;
  }

  .warning-box strong { font-weight: 500; }

  .progress-bar {
    height: 3px;
    background: var(--border);
    border-radius: 2px;
    margin-bottom: 20px;
    overflow: hidden;
    display: none;
  }

  .progress-fill {
    height: 100%;
    background: var(--accent);
    border-radius: 2px;
    width: 0%;
    transition: width 0.3s;
  }

  button#importBtn {
    width: 100%;
    padding: 12px;
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 6px;
    font-family: var(--sans);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: opacity 0.15s;
  }

  button#importBtn:hover { opacity: 0.88; }
  button#importBtn:disabled { opacity: 0.4; cursor: not-allowed; }

  .log-card { max-width: 760px; margin: 0 auto; }

  .log-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
  }

  .log-title {
    font-family: var(--mono);
    font-size: 11px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--muted);
  }

  .clear-btn {
    background: none;
    border: 1px solid var(--border);
    color: var(--muted);
    font-size: 11px;
    padding: 4px 10px;
    border-radius: 4px;
    font-family: var(--mono);
    cursor: pointer;
    transition: border-color 0.15s;
  }

  .clear-btn:hover { border-color: var(--muted); }

  #log {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 16px;
    font-family: var(--mono);
    font-size: 12px;
    line-height: 1.8;
    min-height: 160px;
    max-height: 400px;
    overflow-y: auto;
  }

  .log-line { display: flex; gap: 12px; }
  .log-time  { color: var(--muted); flex-shrink: 0; }
  .log-info  { color: var(--text); }
  .log-ok    { color: var(--success); }
  .log-warn  { color: var(--warning); }
  .log-error { color: var(--danger); }
</style>
</head>
<body>

<header>
  <div class="eyebrow">School Management System</div>
  <h1>CSV Importer</h1>
  <p>Mirrors a Google Sheet CSV export into MySQL. Existing table data is cleared before each import — the sheet is always the source of truth.</p>
</header>

<div class="card">
  <div class="card-title">Import Configuration</div>

  <div class="field">
    <label>Target Table <span>which table to populate</span></label>
    <select id="tableSelect">
      <option value="">— Select a table —</option>
      <option value="tblStudents">tblStudents</option>
      <option value="tblStudentNumberGenerator">tblStudentNumberGenerator</option>
      <option value="tblAdmissionDetails">tblAdmissionDetails</option>
      <option value="tblPrograms">tblPrograms</option>
      <option value="tblEditLocks">tblEditLocks</option>
      <option value="tblRegistrations">tblRegistrations</option>
      <option value="tblSections">tblSections</option>
      <option value="tblIDApplications">tblIDApplications</option>
      <option value="tblLmsAccounts">tblLmsAccounts</option>
      <option value="tblAssessments">tblAssessments</option>
      <option value="tblFees">tblFees</option>
      <option value="tblFeeTemplates">tblFeeTemplates</option>
      <option value="tblFeeTemplateFees">tblFeeTemplateFees</option>
      <option value="tblPayments">tblPayments</option>
      <option value="tblPaymentDetails">tblPaymentDetails</option>
    </select>
  </div>

  <div class="field">
    <label>CSV File <span>exported from Google Sheets</span></label>
    <div class="drop-zone" id="dropZone">
      <input type="file" id="csvFile" accept=".csv" />
      <div class="drop-icon">⬆</div>
      <div class="drop-label"><strong>Choose file</strong> or drag and drop here</div>
      <div class="file-selected" id="fileName"></div>
    </div>
  </div>

  <div class="warning-box">
    <strong>⚠ Mirror mode:</strong> Importing will <strong>DELETE all existing rows</strong> in the selected table before inserting CSV data. This ensures MySQL matches the sheet exactly.
  </div>

  <div class="progress-bar" id="progressBar">
    <div class="progress-fill" id="progressFill"></div>
  </div>

  <button id="importBtn" disabled>Import CSV</button>
</div>

<div class="log-card">
  <div class="log-header">
    <div class="log-title">Import Log</div>
    <button class="clear-btn" onclick="clearLog()">Clear</button>
  </div>
  <div id="log">
    <div class="log-line">
      <span class="log-time">--:--:--</span>
      <span class="log-info">Waiting for import...</span>
    </div>
  </div>
</div>

<script>
const API_BASE    = 'https://api.tsathub.cloud/sms/api';
const tableSelect = document.getElementById('tableSelect');
const csvFile     = document.getElementById('csvFile');
const importBtn   = document.getElementById('importBtn');
const fileName    = document.getElementById('fileName');
const dropZone    = document.getElementById('dropZone');
const progressBar = document.getElementById('progressBar');
const progressFill= document.getElementById('progressFill');

let selectedFile = null;

function checkReady() {
  importBtn.disabled = !(tableSelect.value && selectedFile);
}

tableSelect.addEventListener('change', checkReady);

csvFile.addEventListener('change', () => {
  selectedFile = csvFile.files[0] || null;
  fileName.textContent = selectedFile ? '✓ ' + selectedFile.name : '';
  checkReady();
});

dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e => {
  e.preventDefault();
  dropZone.classList.remove('dragover');
  const file = e.dataTransfer.files[0];
  if (file && file.name.endsWith('.csv')) {
    selectedFile = file;
    fileName.textContent = '✓ ' + file.name;
    checkReady();
  } else {
    log('Only .csv files are accepted', 'error');
  }
});

function now() { return new Date().toTimeString().slice(0, 8); }

function log(msg, type) {
  type = type || 'info';
  const el   = document.getElementById('log');
  const line = document.createElement('div');
  line.className = 'log-line';
  line.innerHTML = '<span class="log-time">' + now() + '</span><span class="log-' + type + '">' + msg + '</span>';
  el.appendChild(line);
  el.scrollTop = el.scrollHeight;
}

function clearLog() { document.getElementById('log').innerHTML = ''; }

function setProgress(pct) {
  progressBar.style.display = 'block';
  progressFill.style.width  = pct + '%';
  if (pct >= 100) setTimeout(function() {
    progressBar.style.display = 'none';
    progressFill.style.width  = '0%';
  }, 800);
}

// Parses the *entire* file as one character stream rather than splitting on
// '\n' up front. Google Sheets CSV exports quote any cell that contains a
// comma, a quote, or an embedded newline (multi-line cell content) — and
// escapes embedded quotes as "". Splitting on '\n' before parsing quotes
// tears a quoted multi-line cell in half, which shifts every column after
// it for that row (symptom: garbage/concatenated values landing in the
// wrong column, e.g. "Data too long for column 'assessmentID'" when several
// fields' worth of text get crammed into it).
function parseCSV(text) {
  const allRows = [];
  var row = [], cur = '', inQ = false;
  const src = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');

  for (var i = 0; i < src.length; i++) {
    const ch = src[i];

    if (inQ) {
      if (ch === '"') {
        if (src[i + 1] === '"') { cur += '"'; i++; } // escaped quote ""
        else { inQ = false; }
      } else {
        cur += ch; // includes embedded newlines — stay inside the field
      }
      continue;
    }

    if (ch === '"') { inQ = true; }
    else if (ch === ',') { row.push(cur); cur = ''; }
    else if (ch === '\n') { row.push(cur); cur = ''; allRows.push(row); row = []; }
    else { cur += ch; }
  }
  // last field/row if file doesn't end with a trailing newline
  if (cur !== '' || row.length > 0) { row.push(cur); allRows.push(row); }

  // Drop fully-empty trailing rows (e.g. trailing blank line in the file)
  while (allRows.length && allRows[allRows.length - 1].every(function(v) { return v.trim() === ''; })) {
    allRows.pop();
  }

  if (allRows.length === 0) return { headers: [], rows: [] };

  const headers = allRows[0].map(function(h) { return h.trim().replace(/^"|"$/g, ''); });
  const rows = [];

  for (var r = 1; r < allRows.length; r++) {
    const values = allRows[r];
    if (values.every(function(v) { return v.trim() === ''; })) continue; // skip blank lines

    const rowObj = {};
    headers.forEach(function(h, idx) {
      rowObj[h] = values[idx] !== undefined ? values[idx].trim() : '';
    });
    rows.push(rowObj);
  }

  return { headers: headers, rows: rows };
}

// Large CSVs (tens of thousands of rows) are sent as several smaller
// sequential requests instead of one giant request. A single request with
// 100k+ rows can be tens of MB of JSON and can take long enough server-side
// (one execute() per row) that it blows past the PHP/webserver execution
// timeout — the server then returns an HTML timeout/error page, which is
// what causes "Unexpected token '<' ... is not valid JSON" on the client.
const CHUNK_SIZE = 2000;

function chunkArray(arr, size) {
  const chunks = [];
  for (let i = 0; i < arr.length; i += size) {
    chunks.push(arr.slice(i, i + size));
  }
  return chunks;
}

// Sends chunk `i` of `chunks`, then recurses to the next chunk. Only the
// first chunk sends truncate:true (clears the table); every chunk after
// that appends, so the table isn't wiped between chunks of the same import.
function sendChunksSequentially(table, chunks, i, totals) {
  if (i >= chunks.length) return Promise.resolve();

  const chunk = chunks[i];
  const isFirstChunk = i === 0;
  log('Sending batch ' + (i + 1) + ' of ' + chunks.length + ' (' + chunk.length + ' rows)...', 'info');

  return fetch(API_BASE + '/import', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify({ table: table, rows: chunk, truncate: isFirstChunk })
  })
  .then(function(res) {
    return res.json()
      .then(function(data) { return { ok: res.ok, status: res.status, data: data }; })
      .catch(function() {
        // Response wasn't valid JSON — usually means the server returned an
        // HTML error/timeout page instead of the expected JSON body.
        throw new Error(
          'Batch ' + (i + 1) + ' — server returned a non-JSON response (HTTP ' + res.status + '). ' +
          'This usually means the request timed out or hit a server error page. Try a smaller CHUNK_SIZE.'
        );
      });
  })
  .then(function(result) {
    if (!result.ok) {
      throw new Error('Batch ' + (i + 1) + ' — server error ' + result.status + ': ' + (result.data.error || 'Unknown'));
    }
    const data = result.data;
    totals.deleted  += Number(data.deleted  || 0);
    totals.inserted += Number(data.inserted || 0);
    totals.skipped  += Number(data.skipped  || 0);
    if (Array.isArray(data.skipLog)) totals.skipLog = totals.skipLog.concat(data.skipLog);

    log('  ✓ Batch ' + (i + 1) + ': inserted ' + data.inserted + ', skipped ' + data.skipped, 'ok');
    setProgress(Math.round(((i + 1) / chunks.length) * 96) + 2);

    return sendChunksSequentially(table, chunks, i + 1, totals);
  });
}

importBtn.addEventListener('click', function() {
  const table = tableSelect.value;
  if (!table || !selectedFile) return;

  importBtn.disabled = true;
  clearLog();
  log('Starting import → ' + table, 'info');
  log('Reading: ' + selectedFile.name, 'info');

  selectedFile.text().then(function(text) {
    const parsed = parseCSV(text);
    const headers = parsed.headers;
    const rows = parsed.rows;

    log('Parsed ' + rows.length + ' rows · ' + headers.length + ' columns', 'info');
    log('Columns: ' + headers.join(', '), 'info');

    if (rows.length === 0) {
      log('No data rows found. Aborting.', 'error');
      importBtn.disabled = false;
      return;
    }

    const chunks = chunkArray(rows, CHUNK_SIZE);
    const totals = { deleted: 0, inserted: 0, skipped: 0, skipLog: [] };
    log('Sending in ' + chunks.length + ' batch(es) of up to ' + CHUNK_SIZE + ' rows each...', 'info');
    setProgress(2);

    sendChunksSequentially(table, chunks, 0, totals)
      .then(function() {
        setProgress(100);
        log('✓ Cleared: ' + totals.deleted + ' existing rows removed', 'ok');
        log('✓ Inserted: ' + totals.inserted + ' rows total', 'ok');
        if (totals.skipped > 0) {
          log('⚠ Skipped: ' + totals.skipped + ' rows total', 'warn');
          totals.skipLog.slice(0, 50).forEach(function(s) {
            log('  → Row ' + s.row + ' [' + s.id + ']: ' + s.reason, 'warn');
          });
          if (totals.skipLog.length > 50) {
            log('  … and ' + (totals.skipLog.length - 50) + ' more skipped row(s).', 'warn');
          }
        }
        log('Done — ' + table + ' now mirrors the sheet.', 'ok');
      })
      .catch(function(err) {
        log('Import stopped: ' + err.message, 'error');
        log('Rows from batches that already succeeded remain in the table. Fix the issue and re-run the import — the first batch will clear the table again before re-inserting everything.', 'warn');
        setProgress(0);
      })
      .finally(function() {
        importBtn.disabled = false;
        checkReady();
      });
  });
});
</script>
</body>
</html>
