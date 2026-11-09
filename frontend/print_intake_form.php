<?php
// print_intake_form.php
// Blank printable GENERAL INTAKE SHEET — opened from new_applicant.php PRINT button
date_default_timezone_set('Asia/Manila');

session_start();
if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

$printed = date("F d, Y g:i A");
$year    = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Intake Sheet</title>
    <link rel="icon" type="image/png" href="<?php echo defined('BASE_URL') ? BASE_URL : '../'; ?>images/dswdlogo2.png">
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
        .btn-print { background: var(--navy); color: #fff; }
        .btn-print:hover { background: #002a6d; }
        .btn-close { background: #fff; color: var(--muted); border: 1px solid var(--border); }
        .btn-close:hover { background: #f1f5f9; }

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
        .doc-header .logo-wrap { display: flex; align-items: center; gap: 14px; }
        .doc-header .seal {
            width: 56px; height: 56px;
            background: #fff;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 26px;
            flex-shrink: 0;
        }
        .doc-header h1 { font-size: 15px; font-weight: 800; line-height: 1.2; }
        .doc-header p  { font-size: 10px; opacity: .75; margin-top: 3px; letter-spacing: .5px; }
        .doc-header .right-info { text-align: right; font-size: 11px; opacity: .85; line-height: 1.7; }

        /* Gold accent bar */
        .accent-bar {
            height: 5px;
            background: repeating-linear-gradient(90deg, var(--gold) 0, var(--gold) 20px, var(--red) 20px, var(--red) 40px);
        }

        /* ── Reference code banner (blank) ── */
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
        .code-banner .write-line {
            border-bottom: 1.5px solid var(--navy);
            width: 220px;
            height: 28px;
            display: inline-block;
        }

        /* ── Header Intake Classification Grid ── */
        .intake-class-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr 1.2fr 2fr;
            border-bottom: 2.5px solid #000;
            background: #fff;
            font-size: 11px;
            font-weight: 700;
        }
        .intake-class-grid > div {
            padding: 10px 14px;
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
            font-size: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* ── Section title ── */
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
        .section-title.slate { background: #475569; }
        .section-title span.sub {
            font-weight: 400; font-size: 10px; opacity: .8;
            font-style: italic; text-transform: none; letter-spacing: 0;
        }

        /* ── Info body ── */
        .info-body { padding: 20px 28px; }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px 24px;
        }
        .info-grid.cols-2   { grid-template-columns: 1fr 1fr; }
        .info-grid.cols-4   { grid-template-columns: repeat(4, 1fr); }
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
        /* Blank writeable line replaces data value */
        .info-item .blank-line {
            border-bottom: 1.5px solid #d1d9e6;
            min-height: 26px;
            padding-bottom: 4px;
        }
        .info-item .blank-line.tall { min-height: 48px; }

        /* ── Checkbox / radio grid ── */
        .check-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            background: #f8fafc;
            padding: 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
        }
        .check-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--text);
        }
        .check-box {
            width: 14px; height: 14px;
            border: 1.5px solid #000;
            border-radius: 2px;
            flex-shrink: 0;
            display: inline-block;
        }
        .circle-box {
            width: 14px; height: 14px;
            border: 1.5px solid #000;
            border-radius: 50%;
            flex-shrink: 0;
            display: inline-block;
        }

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
            height: 30px;
            color: var(--text);
        }
        .family-table tr:last-child td { border-bottom: none; }

        /* ── Signature area ── */
        .sig-area {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            padding: 24px 28px;
            border-top: 1.5px solid var(--border);
        }
        .sig-box { text-align: center; }
        .sig-line { border-bottom: 1.5px solid var(--text); margin-bottom: 6px; height: 40px; }
        .sig-label { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }

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
            .document { box-shadow: none; border: none; max-width: 100%; }
            @page { margin: 12mm 14mm; size: A4; }
        }
    </style>
</head>
<body>

<!-- ── Screen toolbar ── -->
<div class="toolbar">
    <button class="btn-toolbar btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Form
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
                <p>Batasan Hills &nbsp;|&nbsp; Quezon City</p>
            </div>
        </div>
        <div class="right-info">
            <div><strong>GENERAL INTAKE SHEET</strong></div>
            <div>DSWD-PMB-GF-011 | REV 02</div>
            <div style="margin-top:4px;">Date Printed: <strong><?php echo $printed; ?></strong></div>
        </div>
    </div>
    <div class="accent-bar"></div>

    <!-- Reference Code Banner (blank) -->
    <div class="code-banner">
        <div>
            <div style="font-size:10px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.8px; margin-bottom:6px;">Application Reference Code</div>
            <span class="write-line"></span>
        </div>
        <div style="text-align:right;">
            <div style="font-size:10px; color:var(--muted); margin-bottom:6px;">Date of Request</div>
            <span class="write-line" style="width:160px;"></span>
        </div>
    </div>

    <!-- ── NEW CLASSIFICATION HEADER (FROM IMAGE) ── -->
    <div class="intake-class-grid">
        <!-- Col 1: Program -->
        <div>
            <div class="intake-row"><span class="check-box"></span> AICS</div>
            <div class="intake-row"><span class="check-box"></span> AKAP</div>
            <div class="intake-row" style="margin-top:2px;">
                <span class="check-box"></span> Others:
            </div>
            <div style="border-bottom: 1.5px solid #000; width: 100%; margin-top:2px;"></div>
        </div>

        <!-- Col 2: Client Type -->
        <div>
            <div class="intake-row"><span class="circle-box"></span> New</div>
            <div class="intake-row" style="margin-top:4px;"><span class="circle-box"></span> Returning</div>
        </div>

        <!-- Col 3: Location -->
        <div>
            <div class="intake-row"><span class="check-box"></span> Onsite</div>
            <div class="intake-row"><span class="check-box"></span> Malasakit Center</div>
            <div class="intake-row"><span class="check-box"></span> Offsite</div>
        </div>

        <!-- Col 4: Intake & Date -->
        <div style="flex-direction:row; align-items:center; justify-content:space-between;">
            <div style="display:flex; flex-direction:column; gap:6px;">
                <div class="intake-row"><span class="circle-box"></span> Walk-in</div>
                <div class="intake-row"><span class="circle-box"></span> Referral</div>
            </div>
            <div style="text-align:center;">
                <div style="margin-bottom:4px; font-size:11px;">Date :</div>
                <div class="date-box-wrap">
                    <div style="display:flex; flex-direction:column; align-items:center;">
                        <div class="date-box" style="width:32px;"></div>
                        <span style="font-size:8px; font-style:italic; font-weight:400; color:#555; margin-top:2px;">MM</span>
                    </div>
                    <div style="display:flex; flex-direction:column; align-items:center;">
                        <div class="date-box" style="width:32px;"></div>
                        <span style="font-size:8px; font-style:italic; font-weight:400; color:#555; margin-top:2px;">DD</span>
                    </div>
                    <div style="display:flex; flex-direction:column; align-items:center;">
                        <div class="date-box" style="width:50px;"></div>
                        <span style="font-size:8px; font-style:italic; font-weight:400; color:#555; margin-top:2px;">YYYY</span>
                    </div>
                </div>
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
        <!-- Name row -->
        <div class="info-grid cols-4" style="margin-bottom:18px;">
            <div class="info-item" style="grid-column: span 2;">
                <label>Buong Pangalan / Full Name</label>
                <div class="blank-line"></div>
            </div>
            <div class="info-item">
                <label>Apelyido / Last Name</label>
                <div class="blank-line"></div>
            </div>
            <div class="info-item">
                <label>Unang Pangalan / First Name</label>
                <div class="blank-line"></div>
            </div>
        </div>

        <!-- Personal details -->
        <div class="info-grid" style="margin-bottom:18px;">
            <div class="info-item">
                <label>Kapanganakan / Date of Birth</label>
                <div class="blank-line"></div>
            </div>
            <div class="info-item">
                <label>Edad / Age</label>
                <div class="blank-line"></div>
            </div>
            <div class="info-item">
                <label>Kasarian / Sex</label>
                <div style="display:flex; gap:20px; padding-top:6px;">
                    <span class="check-item"><span class="circle-box"></span> Male</span>
                    <span class="check-item"><span class="circle-box"></span> Female</span>
                </div>
            </div>
        </div>

        <div class="info-grid" style="margin-bottom:18px;">
            <div class="info-item">
                <label>Katayuang Sibil / Civil Status</label>
                <div style="display:flex; flex-wrap:wrap; gap:8px 16px; padding-top:4px; font-size:11px;">
                    <span class="check-item"><span class="circle-box"></span> Single</span>
                    <span class="check-item"><span class="circle-box"></span> Married</span>
                    <span class="check-item"><span class="circle-box"></span> Widowed</span>
                    <span class="check-item"><span class="circle-box"></span> Separated</span>
                    <span class="check-item"><span class="circle-box"></span> Common-law</span>
                </div>
            </div>
            <div class="info-item">
                <label>Telepono / Contact Number</label>
                <div class="blank-line"></div>
            </div>
            <div class="info-item">
                <label>Email Address</label>
                <div class="blank-line"></div>
            </div>
        </div>

        <div class="info-grid" style="margin-bottom:18px;">
            <div class="info-item">
                <label>Barangay (Quezon City)</label>
                <div class="blank-line"></div>
            </div>
            <div class="info-item">
                <label>Lungsod / City</label>
                <div class="blank-line"></div>
            </div>
            <div class="info-item">
                <label>Rehiyon / Region</label>
                <div class="blank-line"></div>
            </div>
        </div>

        <div class="info-grid cols-full" style="margin-bottom:4px;">
            <div class="info-item">
                <label>House No. / Street / Village / Address</label>
                <div class="blank-line"></div>
            </div>
        </div>
    </div>

    <hr class="hr">

    <!-- ── CLIENT CATEGORY ── -->
    <div class="section-title" style="background:#6366f1;">
        <i class="fas fa-users"></i>
        Client Category / Sector
        <span class="sub">— Kategorya ng Kliyente</span>
    </div>

    <div class="info-body">
        <div class="check-grid" style="background:#f5f3ff; border-color:#c084fc; margin-bottom:14px;">
            <?php
            $categories = [
                "Family Heads and Other Needy Adult",
                "Persons with Disabilities",
                "Senior Citizens",
                "Men/Women in Specially Difficult Circumstances"
            ];
            foreach($categories as $cat): ?>
                <span class="check-item"><span class="circle-box"></span> <?php echo htmlspecialchars($cat); ?></span>
            <?php endforeach; ?>
        </div>

        <div style="font-size:10px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.8px; margin-bottom:8px;">Client Subcategory</div>
        <div class="check-grid" style="background:#faf5ff; border-color:#e9d5ff;">
            <?php 
                    $subcategories = [
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
                    foreach($subcategories as $sub): ?>
                        <label class="option-item">
                            <input type="radio" name="medical_cause" value="<?php echo $sub; ?>"
                                   <?php echo ($sub === 'Others specify') ? 'id="subcat_others_radio"' : ''; ?> required> <?php echo $sub; ?>
                        </label>
                    <?php endforeach; ?>
        </div>
    </div>

    <hr class="hr">

    <!-- ── PART II: ASSISTANCE DETAILS ── -->
    <div class="section-title">
        <i class="fas fa-hand-holding-medical"></i>
        Part II: Assistance Details
        <span class="sub">— Detalye ng Tulong</span>
    </div>

    <div class="info-body">

        <!-- Monthly Household Income -->
        <div style="font-size:10px; font-weight:800; color:var(--muted); text-transform:uppercase; letter-spacing:.8px; margin-bottom:8px;">Monthly Household Income</div>
        <div class="check-grid" style="margin-bottom:18px;">
            <?php
            $incomes = ["Less than 10,000", "10,000 - 20,000", "21,000 - 40,000", "41,000 - 100,000", "101,000 and above"];
            foreach($incomes as $inc): ?>
                <span class="check-item"><span class="circle-box"></span> ₱<?php echo htmlspecialchars($inc); ?></span>
            <?php endforeach; ?>
        </div>
    

        <!-- Type of Assistance -->
        <div style="font-size:10px; font-weight:800; color:#10b981; text-transform:uppercase; letter-spacing:.8px; margin-bottom:8px;">Uri ng Tulong / Type of Assistance</div>
        <div class="check-grid" style="background:#f0fdf4; border-color:#10b981; margin-bottom:6px;">
            <?php
            $types = ["Medical Assistance", "Cash Assistance"];
            foreach($types as $t): ?>
                <span class="check-item"><span class="circle-box"></span> <?php echo htmlspecialchars($t); ?></span>
            <?php endforeach; ?>
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
        <div style="border: 1.5px dashed var(--border); border-radius:8px; padding:16px; min-height:120px; background:#fafbff;">
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
            <div style="font-size:12px; font-weight:700; color:var(--text);">&nbsp;</div>
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
        <span>DSWD Field Office NCR &nbsp;|&nbsp; Batasan Hills&nbsp;|&nbsp; Quezon City</span>
        <span class="gold">AICS — Online Application System</span>
    </div>

</div><!-- end .document -->

</body>
</html>