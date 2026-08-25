<?php
// view_pending_profile.php
// Opens in a new window — printable DSWD-styled profile of a pending applicant
require_once 'auth.php';

require_once 'db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { echo "Invalid request."; exit(); }

$stmt = $conn->prepare("SELECT * FROM aics_sample_data WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) { echo "<p style='font-family:sans-serif;padding:40px;color:#ef4444;'>Record not found.</p>"; exit(); }

$fullname  = ucwords(strtolower(trim($row['fname'] . ' ' . ($row['mname'] ? $row['mname'] . ' ' : '') . $row['lname'])));
$bdate     = (!empty($row['birth_date']) && $row['birth_date'] !== '0000-00-00') ? date("F d, Y", strtotime($row['birth_date'])) : 'Not specified';
$submitted = !empty($row['request_date']) 
    ? date("F d, Y g:i A", strtotime($row['request_date'])) 
    : 'N/A';
$printed   = date("F d, Y g:i A");
$status = $row['status'] ?? 'Pending';

// Family composition (stored as JSON if available)
$family = [];
if (!empty($row['family_composition'])) {
    $decoded = json_decode($row['family_composition'], true);
    if (is_array($decoded)) $family = $decoded;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DSWD Profile — <?php echo htmlspecialchars($fullname); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy:  #003893;
            --red:   #ce1126;
            --gold:  #c8a94a;
            --light: #f0f4ff;
            --border:#d1d9e6;
            --text:  #1e293b;
            --muted: #64748b;
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
            max-width: 780px;
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
        .btn-close  { background: #fff; color: var(--muted); border: 1px solid var(--border); }
        .btn-close:hover  { background: #f1f5f9; }
        .btn-confirm-toolbar { background: #10b981; color: #fff; }
        .btn-confirm-toolbar:hover { background: #059669; }

        /* ── Document wrapper ── */
        .document {
            max-width: 780px;
            margin: 0 auto;
            background: #fff;
            border: 1.5px solid var(--border);
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
            font-size: 15px; font-weight: 800; line-height: 1.2;
        }
        .doc-header p {
            font-size: 10px; opacity: .75; margin-top: 3px; letter-spacing: .5px;
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

        /* ── Reference code banner ── */
        .code-banner {
            background: var(--light);
            border-bottom: 1.5px solid var(--border);
            padding: 14px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .code-banner .code {
            font-family: 'Courier New', monospace;
            font-size: 22px;
            font-weight: 800;
            color: var(--navy);
            letter-spacing: 2px;
        }
        .status-pill {
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .5px;
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fde68a;
        }
        .status-pill.claimed { background: #dcfce7; color: #166534; border-color: #bbf7d0; }

        /* ── Section ── */
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
        }
        .section-title.red { background: var(--red); }
        .section-title span.sub {
            font-weight: 400; font-size: 10px; opacity: .8; font-style: italic; text-transform: none; letter-spacing: 0;
        }

        /* ── Info grid ── */
        .info-body { padding: 20px 28px; }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px 24px;
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
            font-size: 14px;
            color: var(--text);
            font-weight: 600;
            border-bottom: 1.5px solid #e2e8f0;
            padding-bottom: 5px;
            min-height: 26px;
        }
        .info-item .value.highlight { color: var(--navy); }
        .info-item .value.green     { color: #059669; }
        .info-item .value.mono      { font-family: 'Courier New', monospace; color: var(--navy); font-size: 13px; }

        /* ── Family table ── */
        .family-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .family-table th {
            background: #f8fafc;
            padding: 8px 12px;
            text-align: left;
            font-size: 9px;
            font-weight: 800;
            color: var(--muted);
            text-transform: uppercase;
            border-bottom: 1.5px solid var(--border);
            letter-spacing: .5px;
        }
        .family-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text);
        }
        .family-table tr:last-child td { border-bottom: none; }
        .family-table .empty-row td {
            color: #cbd5e1; font-style: italic; text-align: center; padding: 20px;
        }

        /* ── Signature area ── */
        .sig-area {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            padding: 24px 28px;
            border-top: 1.5px solid var(--border);
        }
        .sig-box { text-align: center; }
        .sig-line {
            border-bottom: 1.5px solid var(--text);
            margin-bottom: 6px;
            height: 40px;
        }
        .sig-label {
            font-size: 10px;
            color: var(--muted);
            text-transform: uppercase;
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
            color: rgba(255,255,255,.65);
        }
        .doc-footer span.gold { color: var(--gold); font-weight: 700; }

        /* ── Divider ── */
        .hr { border: 0; border-top: 1px solid #e2e8f0; margin: 0; }

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
                border: none;
                max-width: 100%;
            }
            @page { margin: 12mm 14mm; size: A4; }
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
    <?php if (($row['status'] ?? '') !== 'Approved'): ?>
   
    <?php endif; ?>
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
            <div style="margin-top:4px;">Date Printed: <strong><?php echo $printed; ?></strong></div>
        </div>
    </div>
    <div class="accent-bar"></div>

    <!-- Reference Code Banner -->
    <div class="code-banner">
        <div>
            <div style="font-size:10px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.8px; margin-bottom:4px;">Application Reference Code</div>
            <div class="code"><?php echo htmlspecialchars($row['application_code']); ?></div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:10px; color:var(--muted); margin-bottom:4px;">Submission Status</div>
            <?php
                $status = $row['status'] ?? 'Pending';
                $statusClass = strtolower($status);
            ?>

            <span class="status-pill <?php echo $statusClass === 'approved' ? 'claimed' : ''; ?>">
                <?php echo htmlspecialchars($status); ?>
            </span>
            <div style="font-size:10px; color:var(--muted); margin-top:6px;">
                Submitted: <?php echo $submitted; ?>
            </div>
        </div>
    </div>

    <!-- ── PART I: BENEFICIARY INFORMATION ── -->
    <div class="section-title">
        <i class="fas fa-user"></i>
        Part I: Beneficiary's Identifying Information
        <span class="sub">— Impormasyon ng Benepisyaryo</span>
    </div>

    <div class="info-body">
        <!-- Name -->
        <div class="info-grid cols-4" style="margin-bottom:18px;">
            <div class="info-item" style="grid-column: span 2;">
                <label>Buong Pangalan / Full Name</label>
                <div class="value highlight"><?php echo htmlspecialchars($fullname); ?></div>
            </div>
            <div class="info-item">
                <label>Apelyido / Last Name</label>
                <div class="value"><?php echo htmlspecialchars(ucwords(strtolower($row['lname']))); ?></div>
            </div>
            <div class="info-item">
                <label>Unang Pangalan / First Name</label>
                <div class="value"><?php echo htmlspecialchars(ucwords(strtolower($row['fname']))); ?></div>
            </div>
        </div>

        <!-- Personal details -->
        <div class="info-grid" style="margin-bottom:18px;">
            <div class="info-item">
                <label>Kapanganakan / Date of Birth</label>
                <div class="value"><?php echo $bdate; ?></div>
            </div>
            <div class="info-item">
                <label>Kasarian / Sex</label>
                <div class="value"><?php echo htmlspecialchars($row['sex'] ?? '—'); ?></div>
            </div>
            <div class="info-item">
                <label>Katayuang Sibil / Civil Status</label>
                <div class="value"><?php echo htmlspecialchars($row['civil_status'] ?? '—'); ?></div>
            </div>
        </div>

        <div class="info-grid" style="margin-bottom:18px;">
            <div class="info-item">
                <label>Barangay</label>
                <div class="value"><?php echo htmlspecialchars($row['barangay'] ?? '—'); ?></div>
            </div>
            <div class="info-item">
                <label>Lungsod / City</label>
                <div class="value">Quezon City</div>
            </div>
            <div class="info-item">
                <label>Rehiyon / Region</label>
                <div class="value">NCR</div>
            </div>
        </div>
    </div>

    <hr class="hr">

    <!-- ── PART II: ASSISTANCE DETAILS ── -->
    <div class="section-title red">
        <i class="fas fa-hand-holding-medical"></i>
        Part II: Assistance Details
        <span class="sub">— Detalye ng Tulong</span>
    </div>

    <div class="info-body">
        <div class="info-grid cols-2" style="margin-bottom:18px;">
            <div class="info-item">
                <label>Uri ng Tulong / Type of Assistance</label>
                <div class="value green"><?php echo htmlspecialchars($row['assistance_type'] ?? '—'); ?></div>
            </div>
            <div class="info-item">
                <label>Kategorya ng Kliyente / Client Category</label>
                <div class="value"><?php echo htmlspecialchars($row['client_category'] ?? '—'); ?></div>
            </div>
        </div>

        <div class="info-grid cols-full" style="margin-bottom:18px;">
            <div class="info-item">
                <label>Sub-Kategorya / Sub-Category</label>
                <div class="value"><?php echo htmlspecialchars($row['client_subcategory'] ?? '—'); ?></div>
            </div>
        </div>

        <div class="info-grid cols-full">
            <div class="info-item">
                <label>Dahilan ng Kahilingan / Medical Cause</label>
                <div class="value" style="white-space:pre-line; line-height:1.6; font-size:13px;">
                    <?php
                    $cause = $row['medical_cause'] ?? '—';
                    $decoded = json_decode($cause, true);
                    echo htmlspecialchars(is_array($decoded) ? implode(', ', $decoded) : $cause);
                    ?>
                </div>
            </div>
        </div>
    </div>

    <hr class="hr">

    <!-- ── FAMILY COMPOSITION ── -->
    <div class="section-title" style="background:#475569;">
        <i class="fas fa-users"></i>
        Komposisyon ng Pamilya / Family Composition
    </div>

    <div class="info-body" style="padding-top:16px; padding-bottom:16px;">
        <table class="family-table">
            <thead>
                <tr>
                    <th style="width:35%;">Buong Pangalan (Complete Name)</th>
                    <th style="width:22%;">Relasyon (Relationship)</th>
                    <th style="width:10%;">Edad (Age)</th>
                    <th style="width:18%;">Trabaho (Occupation)</th>
                    <th style="width:15%;">Buwanang Kita (Salary)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($family)): ?>
                    <?php foreach ($family as $member): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($member['name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($member['relation'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($member['age'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($member['occupation'] ?? '—'); ?></td>
                        <td><?php echo !empty($member['salary']) ? '₱ ' . number_format($member['salary'], 2) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row">
                        <td colspan="5">No family composition data provided.</td>
                    </tr>
                <?php endif; ?>
                <!-- Blank rows for manual writing if printed -->
                <?php for ($i = count($family); $i < 3; $i++): ?>
                <tr>
                    <td style="height:28px;">&nbsp;</td>
                    <td></td><td></td><td></td><td></td>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>

    <hr class="hr">

    <!-- ── SOCIAL WORKER'S ASSESSMENT (blank for staff to fill) ── -->
    <div class="section-title red">
        <i class="fas fa-clipboard-check"></i>
        Social Worker's Assessment
        <span class="sub">— Para sa DSWD Personnel</span>
    </div>

    <div class="info-body">
        <div style="border: 1.5px dashed var(--border); border-radius:8px; padding:16px; min-height:80px; background:#fafbff; font-size:12px; color:#94a3b8; font-style:italic;">
            The client currently lacks financial resources due to unexpected circumstances, creating a need for assistance with basic necessities. The verification of their financial standing will be handled via a DSWD assessment interview.
        </div>

        <div class="info-grid cols-2" style="margin-top:18px;">
            <div class="info-item">
                <label>Provided</label>
                <div class="value">&nbsp;</div>
            </div>
            <div class="info-item">
                <label>Amount</label>
                <div class="value">&nbsp;</div>
            </div>
        </div>
        <div class="info-grid cols-full" style="margin-top:12px;">
            <div class="info-item">
                <label>Fund Source</label>
                <div class="value">PSP <?php echo date('Y'); ?></div>
            </div>
        </div>
    </div>

    <!-- ── PRIVACY NOTICE ── -->
    <div style="padding: 14px 28px; background:#f8fafc; border-top: 1px solid var(--border); font-size:10px; color:var(--muted); line-height:1.7;">
        We are committed to protect and respect the privacy of our clients and beneficiaries and we will only collect, record, store,
        process and use personal information in accordance with <strong>Republic Act No. 10173 or the Data Privacy Act of 2012.</strong>
        By signing this form you are giving your consent to the DSWD.
    </div>

    <!-- ── SIGNATURE AREA ── -->
    <div class="sig-area">
        <div class="sig-box">
            <div class="sig-line"></div>
            <div style="font-size:12px; font-weight:700; color:var(--text);"><?php echo htmlspecialchars($fullname); ?></div>
            <div class="sig-label">Buong Pangalan at Pirma ng Kliyente<br>(Signature over Printed Name)</div>
        </div>
        <div class="sig-box">
            <div class="sig-line"></div>
            <div style="font-size:12px; font-weight:700; color:var(--text);">&nbsp;</div>
            <div class="sig-label">Interviewed by &nbsp;/&nbsp; Reviewed & Approved by<br>(Social Worker)</div>
        </div>
    </div>

    <!-- Footer -->
    <div class="doc-footer">
        <span>DSWD Field Office NCR &nbsp;|&nbsp; Batasan Hills Branch &nbsp;|&nbsp; Quezon City</span>
        <span class="gold">AICS — Online Application System</span>
    </div>

</div><!-- end .document -->

<script>
// Auto-print option — remove if you don't want this
// window.onload = () => window.print();
</script>

</body>
</html>
<?php $conn->close(); ?>