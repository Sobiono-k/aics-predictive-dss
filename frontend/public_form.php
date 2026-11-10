<?php
// public_form.php — Public-facing AICS Online Application Form
date_default_timezone_set('Asia/Manila');

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/qr_access.php';

$submittedToken = $_GET['token'] ?? '';$isTokenValid = isValidQrToken($conn,$submittedToken);
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Application Form</title>
    <link rel="icon" type="image/png" href="<?php echo defined('BASE_URL') ? BASE_URL : '../'; ?>images/dswdlogo2.png">
    <link rel="stylesheet" href="<?php echo defined('BASE_URL') ? BASE_URL : ''; ?>css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy:   #003893;
            --red:    #ce1126;
            --gold:   #c8a94a;
            --slate:  #475569;
            --light:  #f0f4ff;
            --border: #cbd5e1;
            --text:   #1e293b;
            --muted:  #64748b;
            --bg:     #eef2f7;
            --white:  #ffffff;
            --radius: 10px;
            --success:#10b981;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text);
            padding-bottom: 60px;
        }

        /* ─── Top header ─── */
        .top-header {
            background: var(--navy);
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 12px rgba(0,0,0,.3);
        }
        .header-inner {
            max-width: 820px;
            margin: 0 auto;
            padding: 16px 24px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .header-seal {
            width: 48px; height: 48px;
            background: #fff;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; flex-shrink: 0;
        }
        .header-text h1 { font-size: 15px; font-weight: 800; line-height: 1.2; }
        .header-text p  { font-size: 11px; opacity: .8; margin-top: 3px; letter-spacing: .5px; text-transform: uppercase; }
        .accent-bar {
            height: 4px;
            background: repeating-linear-gradient(90deg, var(--gold) 0, var(--gold) 20px, var(--red) 20px, var(--red) 40px);
        }

        /* ─── Main Wrapper Container ─── */
        .main-container {
            max-width: 820px;
            margin: 20px auto 0;
            padding: 0 16px;
        }

        /* ─── Intake Classification Header Grid (Inside Part I) ─── */
        .intake-class-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1.4fr;
            border-bottom: 1.5px solid var(--border);
            background: #fff;
            font-size: 11.5px;
            font-weight: 700;
        }
        .intake-class-grid > div {
            padding: 14px 16px;
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 8px;
        }
        .intake-class-grid > div:last-child {
            border-right: none;
        }
        .intake-row {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #334155;
            cursor: pointer;
        }
        .intake-row input {
            accent-color: var(--navy);
            width: 15px;
            height: 15px;
            cursor: pointer;
        }
        .date-box-wrap {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .date-box {
            border: 1px solid var(--border);
            border-radius: 4px;
            height: 26px;
            text-align: center;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            font-weight: 700;
        }

        /* ─── Step progress ─── */
        .progress-wrap {
            display: flex;
            margin-bottom: 20px;
        }
        .step {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .step-circle {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: #fff;
            border: 2px solid var(--border);
            color: var(--muted);
            font-size: 12px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            transition: .3s;
        }
        .step.active .step-circle { background: var(--navy); border-color: var(--navy); color: #fff; }
        .step.done   .step-circle { background: var(--success); border-color: var(--success); color: #fff; }
        .step-label { font-size: 12px; font-weight: 600; color: var(--muted); }
        .step.active .step-label  { color: var(--navy); font-weight: 700; }
        .step.done   .step-label  { color: var(--success); }
        .step-line { flex: 1; height: 2px; background: var(--border); margin: 0 10px; }

        /* ─── Form card ─── */
        .form-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1.5px solid var(--border);
            overflow: hidden;
            margin-bottom: 20px;
            display: none;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .form-section.active { display: block; }

        .sec-header {
            padding: 14px 24px;
            display: flex; align-items: center; gap: 10px;
            font-size: 13px; font-weight: 800;
            color: #fff; text-transform: uppercase; letter-spacing: .5px;
        }
        .sec-header.navy  { background: var(--navy); }
        .sec-header.red   { background: var(--red); }
        .sec-header.slate { background: var(--slate); }
        .sec-header span.sub {
            font-weight: 400; font-size: 11px; opacity: .85;
            font-style: italic; text-transform: none; letter-spacing: 0;
        }

        .sec-body { padding: 24px; }

        /* ─── Fields Grid alignment ─── */
        .field-row {
            display: grid;
            gap: 16px;
            margin-bottom: 18px;
        }
        .field-row.g2      { grid-template-columns: 1fr 1fr; }
        .field-row.g3      { grid-template-columns: 1fr 1fr 1fr; }
        .field-row.g-name  { grid-template-columns: 2fr 2fr 2fr 1fr; }
        .field-row.g-addr  { grid-template-columns: 2fr 1.5fr 1fr 1fr; }
        .field-row.g-info  { grid-template-columns: 1.2fr 1fr 1fr 1.2fr; }

        .field { display: flex; flex-direction: column; gap: 6px; }
        .field label {
            font-size: 10.5px; font-weight: 800;
            color: var(--muted); text-transform: uppercase; letter-spacing: .6px;
        }
        .field label .fil {
            display: block; font-size: 9.5px; font-weight: 400;
            font-style: italic; color: #94a3b8;
            text-transform: none; letter-spacing: 0;
        }
        .req { color: var(--red); }

        .field input[type="text"],
        .field input[type="tel"],
        .family-table input[type="text"] {
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .field input:not([type="checkbox"]):not([type="radio"]),
        .field select,
        .field textarea {
            border: 1.5px solid var(--border);
            border-radius: 7px;
            padding: 11px 14px;
            font-size: 13.5px;
            font-family: 'Inter', sans-serif;
            color: var(--text);
            background: #fafbff;
            transition: border-color .2s, box-shadow .2s;
            width: 100%;
        }
        .field input:not([type="checkbox"]):not([type="radio"]):focus,
        .field select:focus,
        .field textarea:focus {
            outline: none;
            border-color: var(--navy);
            box-shadow: 0 0 0 3px rgba(0,56,147,.1);
            background: #fff;
        }
        .field input.invalid,
        .field select.invalid,
        .check-grid.invalid { border-color: var(--red); background: #fff5f5; }
        .field input[readonly] { background: #f1f5f9; color: var(--muted); cursor: default; }
        .field select { cursor: pointer; }
        .field textarea { resize: vertical; min-height: 80px; text-transform: uppercase; }

        /* ─── Radio / Check grid ─── */
        .check-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 16px;
            padding: 16px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            background: #fafbff;
            margin-top: 6px;
            transition: border-color 0.2s;
        }
        .check-item {
            display: flex; align-items: flex-start; gap: 8px;
            font-size: 13px; color: var(--text);
            cursor: pointer; padding: 4px 0;
        }
        .check-item input[type="radio"],
        .check-item input[type="checkbox"] {
            width: 16px; height: 16px; flex-shrink: 0;
            margin-top: 2px; accent-color: var(--navy); cursor: pointer;
        }
        .check-other-input {
            margin-top: 8px; padding: 10px 12px;
            border: 1.5px solid var(--border); border-radius: 7px;
            font-size: 14px; font-family: 'Inter', sans-serif;
            width: 100%; display: none; background: #fff;
            text-transform: uppercase;
        }
        .check-other-input:focus { outline: none; border-color: var(--navy); }

        /* ─── Family table ─── */
        .family-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .family-table th {
            background: #f1f5fb; padding: 10px 12px; text-align: left;
            font-size: 10px; font-weight: 800; color: var(--muted);
            text-transform: uppercase; border-bottom: 1.5px solid var(--border); letter-spacing: .4px;
        }
        .family-table td { padding: 8px; border-bottom: 1px solid #f0f4f8; vertical-align: middle; }
        .family-table tr:last-child td { border-bottom: none; }
        .family-table input,
        .family-table select {
            border: 1.5px solid var(--border); border-radius: 6px;
            padding: 8px 10px; font-size: 13px; width: 100%;
            background: #fafbff; font-family: 'Inter', sans-serif;
        }
        .family-table input[type="text"] { text-transform: uppercase; }
        .family-table input:focus,
        .family-table select:focus { outline: none; border-color: var(--navy); }

        .btn-add-row {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 9px 16px; background: #eff6ff;
            color: var(--navy); border: 1.5px dashed var(--navy);
            border-radius: 7px; font-size: 12px; font-weight: 700;
            cursor: pointer; margin-top: 12px; transition: .2s;
        }
        .btn-add-row:hover { background: #dbeafe; }

        /* ─── Nav buttons ─── */
        .nav-row {
            display: flex; justify-content: space-between;
            align-items: center; gap: 12px; margin-top: 10px;
        }
        .btn-nav {
            padding: 13px 30px; border-radius: 8px; border: none;
            font-family: 'Inter', sans-serif; font-size: 14px; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; gap: 8px; transition: .2s;
        }
        .btn-back   { background: #f1f5f9; color: var(--muted); border: 1.5px solid var(--border); }
        .btn-back:hover { background: #e2e8f0; }
        .btn-next   { background: var(--navy); color: #fff; }
        .btn-next:hover { background: #002a6d; }
        .btn-submit { background: var(--success); color: #fff; }
        .btn-submit:hover { background: #059669; }
        .btn-submit:disabled { background: #94a3b8; cursor: not-allowed; }

        /* ─── Privacy box ─── */
        .privacy-box {
            background: #f8faff; border: 1.5px solid var(--border);
            border-radius: 8px; padding: 18px;
            font-size: 12.5px; color: var(--muted); line-height: 1.8;
        }
        .privacy-check {
            display: flex; align-items: flex-start; gap: 10px; margin-top: 14px;
        }
        .privacy-check input { width: 18px; height: 18px; accent-color: var(--navy); flex-shrink: 0; margin-top: 2px; }
        .privacy-check label { font-size: 13px; font-weight: 600; color: var(--text); cursor: pointer; }

        /* ─── Error banner ─── */
        .err-banner {
            background: #fef2f2; border: 1.5px solid #fca5a5;
            border-radius: 8px; padding: 12px 16px; font-size: 13px;
            color: #b91c1c; display: none; align-items: center; gap: 10px; margin-bottom: 20px;
        }
        .err-banner.show { display: flex; }

        /* ─── Expired Box Styling ─── */
        .expired-card {
            background: var(--white);
            border-radius: var(--radius);
            border: 1.5px solid var(--border);
            max-width: 600px;
            margin: 40px auto;
            padding: 40px 30px;
            text-align: center;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        }
        .expired-card i {
            font-size: 48px;
            color: var(--red);
            margin-bottom: 20px;
        }
        .expired-card h2 {
            font-size: 20px;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 10px;
        }
        .expired-card p {
            font-size: 14px;
            color: var(--muted);
            line-height: 1.6;
        }

        /* ─── Duplicate alert ─── */
        .dup-alert {
            max-width: 820px; margin: 20px auto 0; padding: 0 16px;
        }
        .dup-alert-inner {
            background: #fef2f2; border: 1.5px solid #fca5a5;
            border-radius: 10px; padding: 16px 20px;
            display: flex; gap: 12px; align-items: flex-start;
        }

        @media (max-width: 768px) {
            .field-row.g-name,
            .field-row.g-addr,
            .field-row.g-info,
            .field-row.g3 { grid-template-columns: 1fr 1fr; }
            .intake-class-grid { grid-template-columns: 1fr 1fr; }
            .check-grid   { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .field-row.g-name,
            .field-row.g-addr,
            .field-row.g-info,
            .field-row.g3,
            .field-row.g2 { grid-template-columns: 1fr; }
            .intake-class-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ── Top header ── -->
<div class="top-header">
    <div class="header-inner">
        <div class="header-seal">🏛️</div>
        <div class="header-text">
            <h1>DSWD — AICS Online Application</h1>
            <p>General Intake Sheet &nbsp;|&nbsp; Batasan Hills Branch, Quezon City</p>
        </div>
    </div>
    <div class="accent-bar"></div>
</div>

<?php if (!$isTokenValid): ?>
    <!-- ── EXPIRED / INVALID TOKEN SCREEN ── -->
    <div class="main-container">
        <div class="expired-card">
            <i class="fas fa-exclamation-circle"></i>
            <h2>Application Link Expired</h2>
            <p>This QR code or application link is no longer active.</p>
            <p style="margin-top: 8px;">Please ask the DSWD AICS staff for the latest QR code.</p>
        </div>
    </div>
<?php else: ?>
    <!-- ── MAIN FORM ── -->
    <?php
    $err = $_GET['err'] ?? '';
    $errMessages = [
        'duplicate_name'  => 'Mayroon nang nakarehistrong aplikasyon na may <strong>parehong pangalan at petsa ng kapanganakan</strong>. Makipag-ugnayan sa counter para sa tulong.',
        'duplicate_phone' => 'Ang <strong>numero ng telepono</strong> ay mayroon nang naka-pending na aplikasyon. Makipag-ugnayan sa counter.',
        'db_error'        => 'May nangyaring error sa server. Pakisubukan muli o makipag-ugnayan sa kawani.',
    ];
    if (isset($errMessages[$err])):
    ?>
    <div class="dup-alert">
        <div class="dup-alert-inner">
            <i class="fas fa-exclamation-triangle" style="color:#ef4444;font-size:20px;flex-shrink:0;margin-top:2px;"></i>
            <div>
                <div style="font-weight:800;color:#991b1b;margin-bottom:4px;font-size:14px;">
                    <?php echo $err === 'db_error' ? 'Server Error' : 'Duplicate Application Detected'; ?>
                </div>
                <div style="font-size:13px;color:#b91c1c;"><?php echo $errMessages[$err]; ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="main-container">

        <!-- ── Step progress ── -->
        <div class="progress-wrap" id="progressBar">
            <div class="step active" id="step-ind-1">
                <div class="step-circle">1</div>
                <div class="step-label">Personal Info</div>
            </div>
            <div class="step-line"></div>
            <div class="step" id="step-ind-2">
                <div class="step-circle">2</div>
                <div class="step-label">Assistance</div>
            </div>
            <div class="step-line"></div>
            <div class="step" id="step-ind-3">
                <div class="step-circle">3</div>
                <div class="step-label">Family & Submit</div>
            </div>
        </div>

        <form action="submit_public_form.php" method="POST" id="aicsForm" novalidate>
            <input type="hidden" name="qr_token" value="<?php echo htmlspecialchars($submittedToken, ENT_QUOTES, 'UTF-8'); ?>">

            <!-- ════════════════════════════════════
                 STEP 1 — PERSONAL INFORMATION
            ════════════════════════════════════ -->
            <div class="form-section active" id="step1">

                <div class="sec-header navy">
                    <i class="fas fa-user"></i>
                    Part I — Impormasyon ng Benepisyaryo
                    <span class="sub">(Beneficiary's Identifying Information)</span>
                </div>

                <!-- ── Intake Classification Header Grid (Inside Part I block) ── -->
                <div class="intake-class-grid">
                    <div>
                        <label class="intake-row"><input type="radio" name="program_type" value="AICS" checked> AICS</label>
                        <label class="intake-row"><input type="radio" name="program_type" value="AKAP"> AKAP</label>
                        <label class="intake-row"><input type="radio" name="program_type" value="Others"> Others</label>
                    </div>
                    <div>
                        <label class="intake-row"><input type="radio" name="client_nature" value="New" checked> New</label>
                        <label class="intake-row"><input type="radio" name="client_nature" value="Returning"> Returning</label>
                    </div>
                    <div>
                        <label class="intake-row"><input type="radio" name="intake_venue" value="Onsite" checked> Onsite</label>
                        <label class="intake-row"><input type="radio" name="intake_venue" value="Malasakit Center"> Malasakit Center</label>
                        <label class="intake-row"><input type="radio" name="intake_venue" value="Offsite"> Offsite</label>
                    </div>
                    <div style="flex-direction:row; align-items:center; justify-content:space-between;">
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            <label class="intake-row"><input type="radio" name="intake_mode" value="Walk-in" checked> Walk-in</label>
                            <label class="intake-row"><input type="radio" name="intake_mode" value="Referral"> Referral</label>
                        </div>
                        <div style="text-align:center;">
                            <div style="margin-bottom:4px; font-size:11px;">Date :</div>
                            <div class="date-box-wrap">
                                <div style="display:flex; flex-direction:column; align-items:center;">
                                    <div class="date-box" style="width:26px;"><?php echo date('m'); ?></div>
                                    <span style="font-size:7px; font-style:italic; font-weight:400; color:#555; margin-top:2px;">MM</span>
                                </div>
                                <div style="display:flex; flex-direction:column; align-items:center;">
                                    <div class="date-box" style="width:26px;"><?php echo date('d'); ?></div>
                                    <span style="font-size:7px; font-style:italic; font-weight:400; color:#555; margin-top:2px;">DD</span>
                                </div>
                                <div style="display:flex; flex-direction:column; align-items:center;">
                                    <div class="date-box" style="width:38px;"><?php echo date('Y'); ?></div>
                                    <span style="font-size:7px; font-style:italic; font-weight:400; color:#555; margin-top:2px;">YYYY</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="sec-body">

                    <div class="err-banner" id="err1">
                        <i class="fas fa-exclamation-circle"></i>
                        <span id="err1msg">Pakiusap punan ang lahat ng may * bago magpatuloy.</span>
                    </div>

                    <!-- Name row -->
                    <div class="field-row g-name">
                        <div class="field">
                            <label>Apelyido <span class="req">*</span><span class="fil">Last Name</span></label>
                            <input type="text" name="lname" id="lname" placeholder="DELA CRUZ" required autocomplete="family-name">
                        </div>
                        <div class="field">
                            <label>Unang Pangalan <span class="req">*</span><span class="fil">First Name</span></label>
                            <input type="text" name="fname" id="fname" placeholder="JUAN" required autocomplete="given-name">
                        </div>
                        <div class="field">
                            <label>Gitnang Pangalan<span class="fil">Middle Name</span></label>
                            <input type="text" name="mname" id="mname" placeholder="SANTOS" autocomplete="additional-name">
                        </div>
                        <div class="field">
                            <label>Ext.<span class="fil">Sr, Jr, II</span></label>
                            <select name="ext">
                                <option value="">—</option>
                                <option>SR.</option><option>JR.</option>
                                <option>II</option><option>III</option><option>IV</option>
                            </select>
                        </div>
                    </div>

                    <!-- Birth date / age / sex / civil status -->
                    <div class="field-row g-info">
                        <div class="field">
                            <label>Kapanganakan <span class="req">*</span><span class="fil">Birthdate</span></label>
                            <input type="date" name="dob" id="dob" required>
                        </div>
                        <div class="field">
                            <label>Edad<span class="fil">Age (auto)</span></label>
                            <input type="number" name="age" id="age" placeholder="—" readonly>
                        </div>
                        <div class="field">
                            <label>Kasarian <span class="req">*</span><span class="fil">Sex</span></label>
                            <select name="sex" required>
                                <option value="">— Piliin —</option>
                                <option>Male</option>
                                <option>Female</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Katayuang Sibil <span class="req">*</span><span class="fil">Civil Status</span></label>
                            <select name="civil_status" required>
                                <option value="">— Piliin —</option>
                                <option>Single</option><option>Married</option>
                                <option>Widowed</option><option>Separated</option><option>Common-law</option>
                            </select>
                        </div>
                    </div>

                    <!-- Address -->
                    <div class="field-row g-addr">
                        <div class="field">
                            <label>House No./Street/Purok<span class="fil">Ex. 123 SUN ST.</span></label>
                            <input type="text" name="street" placeholder="123 MABINI ST.">
                        </div>
                        <div class="field">
                            <label>Barangay <span class="req">*</span><span class="fil">Barangay</span></label>
                            <input type="text" name="barangay" placeholder="BARANGAY" required>
                        </div>
                        <div class="field">
                            <label>City <span class="req">*</span><span class="fil">Lungsod</span></label>
                            <input type="text" name="city" placeholder="CITY" required>
                        </div>
                        <div class="field">
                            <label>Region</label>
                            <input type="text" name="region" value="NCR" readonly>
                        </div>
                    </div>

                    <!-- Contact / Occupation -->
                    <div class="field-row g2" style="margin-bottom:0;">
                        <div class="field">
                            <label>Numero ng Telepono <span class="req">*</span><span class="fil">Mobile No. (09XXXXXXXXX)</span></label>
                            <input type="tel" name="cp_number" id="cp_number" placeholder="09XXXXXXXXX" required>
                        </div>
                        <div class="field">
                            <label>Trabaho<span class="fil">Occupation, N/A if none</span></label>
                            <input type="text" name="occupation" placeholder="TRABAHO">
                        </div>
                    </div>

                </div><!-- end sec-body -->

                <div class="sec-body" style="padding-top:0;">
                    <div class="nav-row">
                        <span></span>
                        <button type="button" class="btn-nav btn-next" onclick="goStep(2)">
                            Next: Assistance Details <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div><!-- end step1 -->


            <!-- ════════════════════════════════════
                 STEP 2 — ASSISTANCE DETAILS
            ════════════════════════════════════ -->
            <div class="form-section" id="step2">

                <div class="sec-header red">
                    <i class="fas fa-hand-holding-medical"></i>
                    Part II — Detalye ng Tulong
                    <span class="sub">(Assistance Details)</span>
                </div>
                
                <div class="sec-body">

                    <!-- Error Banner -->
                    <div class="err-banner" id="err2">
                        <i class="fas fa-exclamation-circle"></i>
                        <span id="err2msg">Pakiusap punan ang lahat ng may * bago magpatuloy.</span>
                    </div>

                    <!-- Client Category -->
                    <div class="field-row g2" style="margin-bottom:16px;">
                        <div class="field" style="grid-column: span 2;">
                            <label>Kategorya ng Kliyente <span class="req">*</span> <span class="fil">Client Category</span></label>
                            <select name="client_category" id="client_category" required>
                                <option value="">— Piliin —</option>
                                <option value="Family Heads and Other Needy Adult">Family Heads and Other Needy Adult</option>
                                <option value="Persons with Disabilities">Persons with Disabilities</option>
                                <option value="Senior Citizens">Senior Citizens</option>
                                <option value="Men/Women in Specially Difficult Circumstances">Men/Women in Specially Difficult Circumstances</option>
                            </select>
                        </div>
                    </div>

                    <!-- Hidden input para sa JS assignment -->
                    <input type="hidden" name="client_subcategory" id="subcatHidden">

                    <!-- Sub-category (Direktang Papasok sa medical_cause column) -->
                    <div class="field" style="margin-bottom:18px;">
                        <label>Pumili ng Sub-Kategorya (Medical Cause): <span class="req">*</span>
                            <span class="fil">Isa lamang (Select one only)</span>
                        </label>
                        <div class="check-grid" id="subcatGrid">
                            <?php
                            $subcats = [
                                "Speech Impairment",
                                "Psychosocial Disability",
                                "Learning Disability",
                                "Cancer",
                                "Deaf/Hard-of-Hearing",
                                "Mental Disability",
                                "Visual Disability",
                                "Intellectual Disability",
                                "Physical Disability",
                                "Rare Disease"
                            ];
                            foreach ($subcats as $s):
                            ?>
                            <label class="check-item">
                                <input type="radio" name="medical_cause" class="medcause-check" value="<?php echo htmlspecialchars($s); ?>">
                                <?php echo htmlspecialchars($s); ?>
                            </label>
                            <?php endforeach; ?>
                            <label class="check-item">
                                <input type="radio" name="medical_cause" id="medother_cb" value="__other__">
                                Others (specify)
                            </label>
                            <input type="text" class="check-other-input" id="medother_input" placeholder="PLEASE SPECIFY...">
                        </div>
                    </div>

                    <!-- Assistance Type -->
                    <div class="field" style="margin-bottom:0;">
                        <label>Uri ng Tulong na Hinihiling <span class="req">*</span>
                            <span class="fil">Type of Assistance — Isa lamang (Select one only)</span>
                        </label>
                        <div class="check-grid" id="assistGrid">
                            <?php
                            $assists = ["Medical Assistance", "Cash Assistance"];
                            foreach ($assists as $a):
                            ?>
                            <label class="check-item">
                                <input type="radio" name="assistance_type" class="assist-check" value="<?php echo htmlspecialchars($a); ?>">
                                <?php echo htmlspecialchars($a); ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div><!-- end sec-body -->

                <!-- Navigation Buttons -->
                <div class="sec-body" style="padding-top:0;">
                    <div class="nav-row">
                        <button type="button" class="btn-nav btn-back" onclick="goStep(1)">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <button type="button" class="btn-nav btn-next" onclick="goStep(3)">
                            Next: Family & Submit <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div><!-- end step2 -->


            <!-- ════════════════════════════════════
                 STEP 3 — FAMILY COMPOSITION + SUBMIT
            ════════════════════════════════════ -->
            <div class="form-section" id="step3">

                <div class="sec-header slate">
                    <i class="fas fa-users"></i>
                    Komposisyon ng Pamilya
                    <span class="sub">(Family Composition)</span>
                </div>
                
                <div class="sec-body">

                    <div class="err-banner" id="err3">
                        <i class="fas fa-exclamation-circle"></i>
                        <span id="err3msg">Kailangan ninyong sumang-ayon sa Data Privacy Policy bago mag-submit.</span>
                    </div>

                    <p style="font-size:12.5px; color:var(--muted); margin-bottom:14px;">
                        <i class="fas fa-info-circle" style="margin-right:4px; color:var(--navy);"></i>
                        Opsyonal. Punan ang impormasyon ng bawat miyembro ng pamilya.
                    </p>
                    <div style="overflow-x:auto;">
                        <table class="family-table">
                            <thead>
                                <tr>
                                    <th style="width:32%;">Buong Pangalan<br><em style="font-weight:400;font-style:italic;">Complete Name</em></th>
                                    <th style="width:22%;">Relasyon<br><em style="font-weight:400;font-style:italic;">Relationship</em></th>
                                    <th style="width:9%;">Edad<br><em style="font-weight:400;font-style:italic;">Age</em></th>
                                    <th style="width:20%;">Trabaho<br><em style="font-weight:400;font-style:italic;">Occupation</em></th>
                                    <th style="width:17%;">Buwanang Kita<br><em style="font-weight:400;font-style:italic;">Monthly Salary</em></th>
                                </tr>
                            </thead>
                            <tbody id="familyBody">
                                <?php for ($i = 0; $i < 3; $i++): ?>
                                <tr>
                                    <td><input type="text" name="family_name[]" placeholder="FULL NAME"></td>
                                    <td>
                                        <select name="family_relation[]">
                                            <option value="">— Select —</option>
                                            <option>Spouse</option><option>Child</option><option>Parent</option>
                                            <option>Sibling</option><option>Grandchild</option><option>Grandparent</option><option>Other</option>
                                        </select>
                                    </td>
                                    <td><input type="number" name="family_age[]" placeholder="Age" min="0" max="120"></td>
                                    <td><input type="text" name="family_occupation[]" placeholder="OCCUPATION"></td>
                                    <td><input type="number" name="family_salary[]" placeholder="0.00" min="0" step="0.01"></td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn-add-row" onclick="addFamilyRow()">
                        <i class="fas fa-plus"></i> Magdagdag ng miyembro
                    </button>
                </div>

                <!-- Privacy Notice -->
                <div class="sec-header slate" style="background:#334155;">
                    <i class="fas fa-shield-alt"></i>
                    Data Privacy Notice — RA 10173
                </div>
                <div class="sec-body">
                    <div class="privacy-box">
                        Kami ay nakatuon sa pagprotekta at paggalang sa privacy ng aming mga kliyente at benepisyaryo at ang personal na impormasyon ay kokolektahin, itatala, itatago, ipoproseso at gagamitin lamang alinsunod sa
                        <strong>Republic Act No. 10173 o ang Data Privacy Act of 2012.</strong>
                        Sa pag-submit ng form na ito, ibinibigay ninyo ang inyong pahintulot sa DSWD at sumasang-ayon sa mga tuntunin at kundisyon.
                        <div class="privacy-check">
                            <input type="checkbox" id="privacyConsent" name="privacy_consent" value="1" required>
                            <label for="privacyConsent">
                                Sumasang-ayon ako / I agree to the Data Privacy Policy. <span class="req">*</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="sec-body" style="padding-top:0;">
                    <div class="nav-row">
                        <button type="button" class="btn-nav btn-back" onclick="goStep(2)">
                            <i class="fas fa-arrow-left"></i> Back
                        </button>
                        <button type="submit" class="btn-nav btn-submit" id="submitBtn">
                            <i class="fas fa-paper-plane"></i> I-submit ang Application
                        </button>
                    </div>
                </div>
            </div><!-- end step3 -->

        </form>
    </div><!-- end main-container -->

    <script>
    let currentStep = 1;

    function forceUpper(el) {
        const start = el.selectionStart;
        const end   = el.selectionEnd;
        el.value    = el.value.toUpperCase();
        try { el.setSelectionRange(start, end); } catch(e) {}
    }

    document.querySelectorAll('input[type="text"], input[type="tel"]').forEach(el => {
        if (el.readOnly) return;
        el.addEventListener('input', () => forceUpper(el));
        el.addEventListener('blur',  () => forceUpper(el));
    });

    const dobInput = document.getElementById('dob');
    if (dobInput) {
        dobInput.addEventListener('change', function () {
            const dob = new Date(this.value);
            if (isNaN(dob)) return;
            const today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const m = today.getMonth() - dob.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
            document.getElementById('age').value = age >= 0 ? age : '';
        });
    }

    // Watch 'Others (specify)' option
    function watchRadioOther(radioName, otherVal, inputId) {
        document.querySelectorAll('[name="' + radioName + '"]').forEach(r => {
            r.addEventListener('change', () => {
                const inp = document.getElementById(inputId);
                inp.style.display = (r.value === otherVal && r.checked) ? 'block' : 'none';
                if (r.value !== otherVal) inp.value = '';
            });
        });
    }
    watchRadioOther('medical_cause', '__other__', 'medother_input');

    // Automatic remove invalid class when radio changes
    document.querySelectorAll('input[type="radio"]').forEach(el => {
        el.addEventListener('change', () => {
            const grid = el.closest('.check-grid');
            if (grid) grid.classList.remove('invalid');
        });
    });

    function getRadioValue(radioName, otherVal, otherInputId) {
        const selected = document.querySelector('[name="' + radioName + '"]:checked');
        if (!selected) return '';
        if (selected.value === otherVal) {
            return document.getElementById(otherInputId).value.toUpperCase().trim();
        }
        return selected.value;
    }

    function validateStep(step) {
        if (step === 1) {
            const required = ['lname','fname','dob','sex','civil_status','barangay','city','cp_number'];
            let valid = true, firstBad = null;

            required.forEach(name => {
                const el = document.querySelector('[name="' + name + '"]');
                if (!el) return;
                if (!el.value.trim()) {
                    el.classList.add('invalid'); valid = false;
                    if (!firstBad) firstBad = el;
                } else { el.classList.remove('invalid'); }
            });

            const mobile = document.getElementById('cp_number');
            if (mobile && mobile.value.trim() &&
                !/^(09|\+639)\d{9}$/.test(mobile.value.replace(/[-\s]/g, ''))) {
                mobile.classList.add('invalid'); valid = false;
                showErr(1, 'Pakiusap, maglagay ng tamang numero ng telepono (09XXXXXXXXX).');
                if (!firstBad) firstBad = mobile;
            }

            if (!valid) {
                document.getElementById('err1').classList.add('show');
                if (firstBad) firstBad.scrollIntoView({ behavior:'smooth', block:'center' });
            } else {
                document.getElementById('err1').classList.remove('show');
            }
            return valid;
        }

        if (step === 2) {
            const cat = document.getElementById('client_category');
            if (!cat.value) { 
                cat.classList.add('invalid'); 
                showErr(2,'Piliin ang Client Category.'); 
                return false; 
            }
            cat.classList.remove('invalid');

            // Validate Medical Cause (Sub-Category)
            const medVal = getRadioValue('medical_cause', '__other__', 'medother_input');
            const subcatGrid = document.getElementById('subcatGrid');
            if (!medVal) {
                subcatGrid.classList.add('invalid');
                showErr(2, 'Pumili ng isang Sub-Kategorya (Medical Cause).');
                return false;
            } else {
                subcatGrid.classList.remove('invalid');
                document.getElementById('subcatHidden').value = medVal;
            }

            // Validate Assistance Type
            const assistVal = document.querySelector('[name="assistance_type"]:checked');
            const assistGrid = document.getElementById('assistGrid');
            if (!assistVal) {
                if (assistGrid) assistGrid.classList.add('invalid');
                showErr(2, 'Pumili ng Uri ng Tulong na Hinihiling.');
                return false;
            } else {
                if (assistGrid) assistGrid.classList.remove('invalid');
            }

            document.getElementById('err2').classList.remove('show');
            return true;
        }
        return true;
    }

    function showErr(step, msg) {
        document.getElementById('err'+step+'msg').textContent = msg;
        const b = document.getElementById('err'+step);
        b.classList.add('show');
        b.scrollIntoView({ behavior:'smooth', block:'center' });
    }

    function goStep(target) {
        if (target > currentStep && !validateStep(currentStep)) return;

        document.getElementById('step' + currentStep).classList.remove('active');
        currentStep = target;
        document.getElementById('step' + currentStep).classList.add('active');
        document.getElementById('step' + currentStep).scrollIntoView({ behavior:'smooth', block:'start' });
        updateProgress();
    }

    function updateProgress() {
        for (let i = 1; i <= 3; i++) {
            const el = document.getElementById('step-ind-' + i);
            el.classList.remove('active','done');
            if (i === currentStep) el.classList.add('active');
            else if (i < currentStep) el.classList.add('done');
        }
    }

    const aicsForm = document.getElementById('aicsForm');
    if (aicsForm) {
        aicsForm.addEventListener('submit', function(e) {
            if (!validateStep(1) || !validateStep(2)) {
                e.preventDefault();
                return;
            }

            if (!document.getElementById('privacyConsent').checked) {
                e.preventDefault();
                showErr(3,'Kailangan ninyong sumang-ayon sa Data Privacy Policy bago mag-submit.');
                return;
            }
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Isinusumite...';
        });
    }

    function addFamilyRow() {
        const tbody = document.getElementById('familyBody');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" name="family_name[]" placeholder="FULL NAME"></td>
            <td>
                <select name="family_relation[]">
                    <option value="">— Select —</option>
                    <option>Spouse</option><option>Child</option><option>Parent</option>
                    <option>Sibling</option><option>Grandchild</option><option>Grandparent</option><option>Other</option>
                </select>
            </td>
            <td><input type="number" name="family_age[]" placeholder="Age" min="0" max="120"></td>
            <td><input type="text" name="family_occupation[]" placeholder="OCCUPATION"></td>
            <td><input type="number" name="family_salary[]" placeholder="0.00" min="0" step="0.01"></td>`;
        tbody.appendChild(tr);

        tr.querySelectorAll('input[type="text"]').forEach(el => {
            el.addEventListener('input', () => forceUpper(el));
            el.addEventListener('blur',  () => forceUpper(el));
        });
    }

    document.querySelectorAll('input, select').forEach(el => {
        el.addEventListener('change', () => el.classList.remove('invalid'));
        el.addEventListener('input',  () => el.classList.remove('invalid'));
    });
    </script>
<?php endif; ?>

</body>
</html>