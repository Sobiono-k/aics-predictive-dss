<?php
// view_pending_profile.php
// Opens in a new window — printable DSWD-styled General Intake Sheet profile of an applicant
date_default_timezone_set('Asia/Manila');
require_once 'auth.php';
require_once(__DIR__ . '/../db.php');

// Helper function for safe HTML escaping in PHP 8.1+
function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { echo "Invalid request."; exit(); }

$stmt = $conn->prepare("SELECT * FROM aics_sample_data WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) { echo "<p style='font-family:sans-serif;padding:40px;color:#ef4444;'>Record not found.</p>"; exit(); }

$fname = $row['fname'] ?? '';
$mname = $row['mname'] ?? '';
$lname = $row['lname'] ?? '';
$ext   = $row['ext'] ?? '';

$fullname  = strtoupper(trim($fname . ' ' . ($mname ? $mname . ' ' : '') . $lname . ($ext ? ' ' . $ext : '')));
$bdate     = (!empty($row['birth_date']) && $row['birth_date'] !== '0000-00-00') ? date("F d, Y", strtotime($row['birth_date'])) : 'Not specified';
$submitted = !empty($row['request_date']) 
    ? date("F d, Y g:i A", strtotime($row['request_date'])) 
    : 'N/A';
$printed   = date("F d, Y g:i A");
$status    = $row['status'] ?? 'Pending';

// Family composition (stored as JSON if available)
$family = [];
if (!empty($row['family_composition'])) {
    $decoded = json_decode($row['family_composition'], true);
    if (is_array($decoded)) $family = $decoded;
}
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Intake Sheet — <?php echo h($fullname); ?></title>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>images/dswdlogo2.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy:   #003893;
            --red:    #ce1126;
            --gold:   #c8a94a;
            --light:  #f0f4ff;
            --border: #000000;
            --text:   #000000;
            --muted:  #475569;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: #e8ecf2;
            color: var(--text);
            padding: 24px;
        }

        /* ── Screen-only toolbar ── */
        .toolbar {
            max-width: 820px;
            margin: 0 auto 16px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn-toolbar {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: flex; align-items: center; gap: 8px;
            transition: .2s;
        }
        .btn-print  { background: var(--navy); color: #fff; }
        .btn-print:hover  { background: #002a6d; }
        .btn-close  { background: #fff; color: var(--muted); border: 1px solid #cbd5e1; }
        .btn-close:hover  { background: #f1f5f9; }
        .btn-confirm-toolbar { background: #10b981; color: #fff; }
        .btn-confirm-toolbar:hover { background: #059669; }

        /* ── Document wrapper ── */
        .document {
            max-width: 820px;
            margin: 0 auto;
            background: #fff;
            border: 2px solid #000000;
            box-shadow: 0 8px 30px rgba(0,0,0,.12);
        }

        /* ── Doc header ── */
        .doc-header {
            background: var(--navy);
            color: #fff;
            padding: 18px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .doc-header .logo-wrap {
            display: flex; align-items: center; gap: 14px;
        }
        .doc-header .seal {
            width: 56px; height: 56px;
            background: #fff;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 26px;
            flex-shrink: 0;
        }
        .doc-header h1 {
            font-size: 15px; font-weight: 800; line-height: 1.2; text-transform: uppercase;
        }
        .doc-header p {
            font-size: 10px; opacity: .75; margin-top: 3px; letter-spacing: .5px; text-transform: uppercase;
        }
        .doc-header .right-info {
            text-align: right;
            font-size: 11px;
            opacity: .85;
            line-height: 1.7;
        }

        /* Gold accent bar */
        .accent-bar {
            height: 5px;
            background: repeating-linear-gradient(90deg, var(--gold) 0, var(--gold) 20px, var(--red) 20px, var(--red) 40px);
        }

        /* ── Intake Classification Grid Layout ── */
        .intake-class-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr 1.2fr 1.8fr;
            border-bottom: 2px solid #000;
            background: #fff;
            font-size: 11px;
            font-weight: 700;
        }
        .intake-class-grid > div {
            padding: 10px 12px;
            border-right: 1.5px solid #000;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 6px;
        }
        .intake-class-grid > div:last-child {
            border-right: none;
        }
        .intake-row {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #000;
            text-transform: uppercase;
        }
        .checkbox-box {
            width: 14px; height: 14px;
            border: 1px solid #000;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 900; background: #fff;
        }
        .circle-box {
            width: 14px; height: 14px;
            border: 1px solid #000;
            border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 10px; font-weight: 900; background: #fff;
        }
        .date-box-wrap {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .date-box {
            border: 1px solid #000;
            height: 22px;
            text-align: center;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            font-weight: 700;
        }

        /* ── Reference code banner ── */
        .code-banner {
            background: var(--light);
            border-bottom: 2px solid #000;
            padding: 12px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .code-banner .code {
            font-family: 'Courier New', monospace;
            font-size: 20px;
            font-weight: 800;
            color: var(--navy);
            letter-spacing: 2px;
        }
        .status-pill {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fde68a;
        }
        .status-pill.claimed { background: #dcfce7; color: #166534; border-color: #bbf7d0; }

        /* ── Section Title ── */
        .section-title {
            background: var(--navy);
            color: #fff;
            padding: 8px 28px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 1.5px solid #000;
        }
        .section-title.red { background: var(--red); }
        .section-title.slate { background: #475569; }
        .section-title span.sub {
            font-weight: 400; font-size: 10px; opacity: .9; font-style: italic; text-transform: none; letter-spacing: 0;
        }

        /* ── Info grid ── */
        .info-body { padding: 16px 28px; }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px 20px;
        }
        .info-grid.cols-2 { grid-template-columns: 1fr 1fr; }
        .info-grid.cols-4 { grid-template-columns: repeat(4, 1fr); }
        .info-grid.cols-full { grid-template-columns: 1fr; }

        .info-item label {
            font-size: 9px;
            font-weight: 800;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .8px;
            display: block;
            margin-bottom: 3px;
        }
        .info-item .value {
            font-size: 13px;
            color: #000;
            font-weight: 700;
            border: 1px solid #94a3b8;
            border-radius: 3px;
            background: #f8fafc;
            padding: 6px 10px;
            min-height: 28px;
            display: flex;
            align-items: center;
            text-transform: uppercase;
        }
        .info-item .value.highlight { color: var(--navy); }
        .info-item .value.green     { color: #059669; }

        /* ── Family table ── */
        .family-table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .family-table th {
            background: #f1f5f9;
            padding: 6px 8px;
            text-align: left;
            font-size: 9px;
            font-weight: 800;
            color: var(--muted);
            text-transform: uppercase;
            border: 1px solid #94a3b8;
            letter-spacing: .5px;
        }
        .family-table td {
            padding: 6px 8px;
            border: 1px solid #94a3b8;
            color: #000;
            font-weight: 600;
            text-transform: uppercase;
        }
        .family-table tr.empty-row td {
            color: #cbd5e1; font-style: italic; text-align: center; padding: 15px;
        }

        /* ── Signature area ── */
        .sig-area {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            padding: 20px 28px;
            border-top: 2px solid #000;
        }
        .sig-box { text-align: center; }
        .sig-line {
            border-bottom: 1.5px solid #000;
            margin-bottom: 4px;
            height: 35px;
        }
        .sig-label {
            font-size: 9.5px;
            color: #334155;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .5px;
        }

        /* ── Footer ── */
        .doc-footer {
            background: var(--navy);
            padding: 10px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10px;
            color: rgba(255,255,255,.7);
        }
        .doc-footer span.gold { color: var(--gold); font-weight: 700; }

        /* ── Divider ── */
        .hr { border: 0; border-top: 1.5px solid #000; margin: 0; }

        /* ── Print styles ── */
        @media print {
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .document {
                box-shadow: none;
                border: 2px solid #000;
                max-width: 100%;
            }
            @page { margin: 8mm; size: A4; }
        }

        @media (max-width: 600px) {
            body { padding: 10px; }
            .info-grid      { grid-template-columns: 1fr 1fr; }
            .info-grid.cols-4 { grid-template-columns: 1fr 1fr; }
            .sig-area       { grid-template-columns: 1fr; gap: 20px; }
            .doc-header     { flex-direction: column; }
            .doc-header .right-info { text-align: left; }
        }
    </style>
</head>
<body>

<!-- ── Screen toolbar ── -->
<div class="toolbar">
    <button class="btn-toolbar btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Profile
    </button>
    <button class="btn-toolbar btn-close" onclick="window.close()">
        <i class="fas fa-times"></i> Close
    </button>
    <span style="font-size:12px; color:var(--muted); margin-left:auto;">
        <i class="fas fa-print" style="margin-right:4px;"></i> Printed: <?php echo $printed; ?>
    </span>
</div>

<!-- ══════════════════════════════════════════
    PRINTABLE DOCUMENT
══════════════════════════════════════════ -->
<div class="document">

    <!-- Header -->
    <div class="doc-header">
        <div class="logo-wrap">
            <div class="seal">🏛️</div>
            <div>
                <h1>Department of Social Welfare and Development</h1>
                <p>AICS — ASSISTANCE TO INDIVIDUALS IN CRISIS SITUATION</p>
                <p>Batasan Hills Branch &nbsp;|&nbsp; Quezon City</p>
            </div>
        </div>
        <div class="right-info">
            <div><strong>GENERAL INTAKE SHEET</strong></div>
            <div>DSWD-PMB-GF-011 | REV 02</div>
            <div style="margin-top:2px;">Date Printed: <strong><?php echo $printed; ?></strong></div>
        </div>
    </div>
    <div class="accent-bar"></div>

    <!-- Intake Classification Header Grid -->
    <div class="intake-class-grid">
        <!-- Col 1: Program Type -->
        <div>
            <div class="intake-row">
                <span class="checkbox-box"><?php echo (($row['program_type'] ?? 'AICS') === 'AICS') ? '✓' : ''; ?></span> AICS
            </div>
            <div class="intake-row">
                <span class="checkbox-box"><?php echo (($row['program_type'] ?? '') === 'AKAP') ? '✓' : ''; ?></span> AKAP
            </div>
            <div class="intake-row">
                <span class="checkbox-box"><?php echo (!in_array($row['program_type'] ?? '', ['AICS', 'AKAP']) && !empty($row['program_type'])) ? '✓' : ''; ?></span> Others
            </div>
        </div>
        <!-- Col 2: Client Nature -->
        <div>
            <div class="intake-row">
                <span class="circle-box"><?php echo (($row['client_nature'] ?? 'NEW') === 'NEW') ? '✓' : ''; ?></span> New
            </div>
            <div class="intake-row" style="margin-top:4px;">
                <span class="circle-box"><?php echo (($row['client_nature'] ?? '') === 'RETURNING') ? '✓' : ''; ?></span> Returning
            </div>
        </div>
        <!-- Col 3: Intake Venue -->
        <div>
            <div class="intake-row">
                <span class="checkbox-box"><?php echo (($row['intake_venue'] ?? 'ONSITE') === 'ONSITE') ? '✓' : ''; ?></span> Onsite
            </div>
            <div class="intake-row">
                <span class="checkbox-box"><?php echo (($row['intake_venue'] ?? '') === 'MALASAKIT CENTER') ? '✓' : ''; ?></span> Malasakit Center
            </div>
            <div class="intake-row">
                <span class="checkbox-box"><?php echo (($row['intake_venue'] ?? '') === 'OFFSITE') ? '✓' : ''; ?></span> Offsite
            </div>
        </div>
        <!-- Col 4: Intake Mode & Date -->
        <div style="flex-direction:row; align-items:center; justify-content:space-between;">
            <div style="display:flex; flex-direction:column; gap:6px;">
                <div class="intake-row">
                    <span class="circle-box"><?php echo (($row['intake_mode'] ?? 'WALK-IN') === 'WALK-IN') ? '✓' : ''; ?></span> Walk-in
                </div>
                <div class="intake-row">
                    <span class="circle-box"><?php echo (($row['intake_mode'] ?? '') === 'REFERRAL') ? '✓' : ''; ?></span> Referral
                </div>
            </div>
            <div style="text-align:center;">
                <div style="margin-bottom:2px; font-size:9.5px;">Date :</div>
                <div class="date-box-wrap">
                    <div class="date-box" style="width:24px;"><?php echo !empty($row['request_date']) ? date('m', strtotime($row['request_date'])) : date('m'); ?></div>
                    <div class="date-box" style="width:24px;"><?php echo !empty($row['request_date']) ? date('d', strtotime($row['request_date'])) : date('d'); ?></div>
                    <div class="date-box" style="width:36px;"><?php echo !empty($row['request_date']) ? date('Y', strtotime($row['request_date'])) : date('Y'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Reference Code & Status Banner -->
    <div class="code-banner">
        <div>
            <div style="font-size:9px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.8px; margin-bottom:2px;">Application Reference Code</div>
            <div class="code"><?php echo h($row['application_code'] ?? 'N/A'); ?></div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:9px; color:var(--muted); margin-bottom:2px;">Submission Status</div>
            <?php
                $status = $row['status'] ?? 'Pending';
                $statusClass = strtolower($status);
            ?>
            <span class="status-pill <?php echo ($statusClass === 'approved' || $statusClass === 'claimed') ? 'claimed' : ''; ?>">
                <?php echo h($status); ?>
            </span>
            <div style="font-size:9.5px; color:var(--muted); margin-top:4px;">
                Submitted: <?php echo $submitted; ?>
            </div>
        </div>
    </div>

    <!-- ── PART I: BENEFICIARY INFORMATION ── -->
    <div class="section-title">
        <i class="fas fa-user"></i>
        Part I — Impormasyon ng Benepisyaryo
        <span class="sub">(Beneficiary's Identifying Information)</span>
    </div>

    <div class="info-body">
        <!-- Name -->
        <div class="info-grid cols-4" style="margin-bottom:12px;">
            <div class="info-item" style="grid-column: span 2;">
                <label>Buong Pangalan / Full Name</label>
                <div class="value highlight"><?php echo h($fullname); ?></div>
            </div>
            <div class="info-item">
                <label>Apelyido / Last Name</label>
                <div class="value"><?php echo h(ucwords(strtolower($lname))); ?></div>
            </div>
            <div class="info-item">
                <label>Unang Pangalan / First Name</label>
                <div class="value"><?php echo h(ucwords(strtolower($fname))); ?></div>
            </div>
        </div>

        <!-- Personal details -->
        <div class="info-grid cols-4" style="margin-bottom:12px;">
            <div class="info-item" style="grid-column: span 2;">
                <label>Gitnang Pangalan / Middle Name & Ext</label>
                <div class="value"><?php echo h(ucwords(strtolower($mname . ($ext ? ' ' . $ext : '')))); ?></div>
            </div>
            <div class="info-item">
                <label>Kapanganakan / Birthdate</label>
                <div class="value"><?php echo $bdate; ?></div>
            </div>
            <div class="info-item">
                <label>Edad / Age</label>
                <div class="value">
                    <?php 
                        if(!empty($row['birth_date']) && $row['birth_date'] !== '0000-00-00') {
                            $from = new DateTime($row['birth_date']);
                            $to   = new DateTime('today');
                            echo $from->diff($to)->y;
                        } else {
                            echo '—';
                        }
                    ?>
                </div>
            </div>
        </div>

        <div class="info-grid cols-4" style="margin-bottom:12px;">
            <div class="info-item">
                <label>Kasarian / Sex</label>
                <div class="value"><?php echo h($row['sex'] ?? '—'); ?></div>
            </div>
            <div class="info-item" style="grid-column: span 2;">
                <label>Katayuang Sibil / Civil Status</label>
                <div class="value"><?php echo h($row['civil_status'] ?? '—'); ?></div>
            </div>
            <div class="info-item">
                <label>Telepono / Mobile No.</label>
                <div class="value"><?php echo h($row['cp_number'] ?? '—'); ?></div>
            </div>
        </div>

        <div class="info-grid" style="margin-bottom:12px;">
            <div class="info-item">
                <label>Barangay</label>
                <div class="value"><?php echo h($row['barangay'] ?? '—'); ?></div>
            </div>
            <div class="info-item">
                <label>Lungsod / City</label>
                <div class="value">QUEZON CITY</div>
            </div>
            <div class="info-item">
                <label>Rehiyon / Region</label>
                <div class="value">NCR</div>
            </div>
        </div>
    </div>

    <hr class="hr">

    <!-- ── PART II: ASSISTANCE DETAILS ── -->
    <div class="section-title">
        <i class="fas fa-hand-holding-medical"></i>
        Part II — Detalye ng Tulong
        <span class="sub">(Assistance Details)</span>
    </div>

    <div class="info-body">
        <div class="info-grid cols-2" style="margin-bottom:12px;">
            <div class="info-item">
                <label>Uri ng Tulong / Type of Assistance</label>
                <div class="value green"><?php echo h($row['assistance_type'] ?? '—'); ?></div>
            </div>
            <div class="info-item">
                <label>Kategorya ng Kliyente / Client Category</label>
                <div class="value"><?php echo h($row['client_category'] ?? '—'); ?></div>
            </div>
        </div>

        <div class="info-grid cols-full" style="margin-bottom:12px;">
            <div class="info-item">
                <label>Sub-Kategorya / Sub-Category</label>
                <div class="value"><?php echo h($row['client_subcategory'] ?? '—'); ?></div>
            </div>
        </div>
    </div>

    <hr class="hr">

    <!-- ── PROBLEM PRESENTED ── -->
    <div class="section-title slate">
        <i class="fas fa-exclamation-circle"></i>
        PROBLEM PRESENTED
    </div>

    <div class="info-body" style="padding-top:16px; padding-bottom:16px;">
        <div style="border: 1.5px dashed var(--border); border-radius:8px; padding:16px; min-height:80px; background:#fafbff;">
        </div>
    </div>

    <hr class="hr">

    <!-- ── SOCIAL WORKER'S ASSESSMENT ── -->
    <div class="section-title">
        <i class="fas fa-clipboard-check"></i>
        Social Worker's Assessment
        <span class="sub">— Para sa DSWD Personnel</span>
    </div>
    <div class="info-body">
        <div style="border: 1.5px dashed var(--border); border-radius:8px; padding:16px; min-height:100px; background:#fafbff;">
        </div>
    </div>

    <div class="info-body" style="padding-top:0; padding-bottom:16px;">
        <table class="family-table">
            <thead>
                <tr>
                    <th style="width:8%; text-align:center;">#</th>
                    <th style="width:52%;">Purpose of Assistance</th>
                    <th style="width:25%;">Amount</th>
                    <th style="width:15%;">Fund Source</th>
                </tr>
            </thead>
            <tbody>
                <?php for ($i = 0; $i < 3; $i++): ?>
                <tr>
                    <td style="text-align:center;"><?php echo $i + 1; ?></td>
                    <td>&nbsp;</td>
                    <td></td>
                    <td></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <!-- ── SIGNATURE AREA ── -->
    <div class="sig-area">
        <div class="sig-box">
            <div class="sig-line"></div>
            <div style="font-size:11px; font-weight:700; color:var(--text);"><?php echo h($fullname); ?></div>
            <div class="sig-label">Buong Pangalan at Pirma ng Kliyente<br>(Signature over Printed Name)</div>
        </div>
        <div class="sig-box">
            <div class="sig-line"></div>
            <div style="font-size:11px; font-weight:700; color:var(--text);">&nbsp;</div>
            <div class="sig-label">Interviewed by &nbsp;/&nbsp; Reviewed & Approved by<br>(Social Worker)</div>
        </div>
    </div>

    <!-- Footer -->
    <div class="doc-footer">
        <span>DSWD Field Office NCR &nbsp;|&nbsp; Batasan Hills Branch &nbsp;|&nbsp; Quezon City</span>
        <span class="gold">AICS — Online Application System</span>
    </div>

</div><!-- end .document -->

</body>
</html>
<?php $conn->close(); ?>