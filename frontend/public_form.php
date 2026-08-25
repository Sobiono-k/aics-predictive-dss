<?php
// public_form.php — Public-facing AICS Online Application Form
// No login required. Submits to submit_public_form.php
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DSWD AICS — Online Application Form</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy:   #003893;
            --red:    #ce1126;
            --gold:   #c8a94a;
            --slate:  #475569;
            --light:  #f0f4ff;
            --border: #d1d9e6;
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
            max-width: 780px;
            margin: 0 auto;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .header-seal {
            width: 44px; height: 44px;
            background: #fff;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; flex-shrink: 0;
        }
        .header-text h1 { font-size: 14px; font-weight: 800; line-height: 1.2; }
        .header-text p  { font-size: 10px; opacity: .7; margin-top: 2px; letter-spacing: .5px; text-transform: uppercase; }
        .accent-bar {
            height: 4px;
            background: repeating-linear-gradient(90deg, var(--gold) 0, var(--gold) 20px, var(--red) 20px, var(--red) 40px);
        }

        /* ─── Step progress ─── */
        .progress-wrap {
            max-width: 780px;
            margin: 20px auto 0;
            padding: 0 16px;
            display: flex;
        }
        .step {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .step-circle {
            width: 30px; height: 30px;
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
        .step-label { font-size: 11px; font-weight: 600; color: var(--muted); }
        .step.active .step-label  { color: var(--navy); }
        .step.done   .step-label  { color: var(--success); }
        .step-line { flex: 1; height: 2px; background: var(--border); margin: 0 6px; }

        /* ─── Form card ─── */
        .form-wrap {
            max-width: 780px;
            margin: 16px auto 0;
            padding: 0 16px;
        }
        .form-section {
            background: var(--white);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            overflow: hidden;
            margin-bottom: 16px;
            display: none;
        }
        .form-section.active { display: block; }

        .sec-header {
            padding: 12px 22px;
            display: flex; align-items: center; gap: 10px;
            font-size: 13px; font-weight: 800;
            color: #fff; text-transform: uppercase; letter-spacing: .5px;
        }
        .sec-header.navy  { background: var(--navy); }
        .sec-header.red   { background: var(--red); }
        .sec-header.slate { background: var(--slate); }
        .sec-header span.sub {
            font-weight: 400; font-size: 11px; opacity: .8;
            font-style: italic; text-transform: none; letter-spacing: 0;
        }

        .sec-body { padding: 22px; }

        /* ─── Fields ─── */
        .field-row {
            display: grid;
            gap: 14px;
            margin-bottom: 16px;
        }
        .field-row.g2      { grid-template-columns: 1fr 1fr; }
        .field-row.g3      { grid-template-columns: 1fr 1fr 1fr; }
        .field-row.g-name  { grid-template-columns: 2fr 2fr 2fr 1fr; }
        .field-row.g-addr  { grid-template-columns: 2fr 1fr 1fr 1fr; }
        .field-row.g-info  { grid-template-columns: 1fr 1fr 1fr 1fr; }

        .field { display: flex; flex-direction: column; gap: 5px; }
        .field label {
            font-size: 10px; font-weight: 800;
            color: var(--muted); text-transform: uppercase; letter-spacing: .6px;
        }
        .field label .fil {
            display: block; font-size: 9px; font-weight: 400;
            font-style: italic; color: #94a3b8;
            text-transform: none; letter-spacing: 0;
        }
        .req { color: var(--red); }

        /* ── ALL TEXT INPUTS: uppercase display & stored value ── */
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
            padding: 10px 12px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            color: var(--text);
            background: #fafbff;
            transition: border-color .2s, box-shadow .2s;
            width: 100%;
            -webkit-appearance: none;
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
        .field select.invalid { border-color: var(--red); background: #fff5f5; }
        .field input[readonly] { background: #f1f5f9; color: var(--muted); cursor: default; }
        .field select { cursor: pointer; }
        .field textarea { resize: vertical; min-height: 80px; text-transform: uppercase; }

        /* ─── Radio / Check grid ─── */
        .check-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 16px;
            padding: 14px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            background: #fafbff;
            margin-top: 6px;
        }
        .check-item {
            display: flex; align-items: flex-start; gap: 8px;
            font-size: 13px; color: var(--text);
            cursor: pointer; padding: 4px 0;
        }
        .check-item input[type="radio"],
        .check-item input[type="checkbox"] {
            width: 15px; height: 15px; flex-shrink: 0;
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
            background: #f1f5fb; padding: 9px 10px; text-align: left;
            font-size: 10px; font-weight: 800; color: var(--muted);
            text-transform: uppercase; border-bottom: 1.5px solid var(--border); letter-spacing: .4px;
        }
        .family-table td { padding: 6px; border-bottom: 1px solid #f0f4f8; vertical-align: middle; }
        .family-table tr:last-child td { border-bottom: none; }
        .family-table input,
        .family-table select {
            border: 1px solid var(--border); border-radius: 6px;
            padding: 7px 8px; font-size: 13px; width: 100%;
            background: #fafbff; font-family: 'Inter', sans-serif;
        }
        .family-table input[type="text"] { text-transform: uppercase; }
        .family-table input:focus,
        .family-table select:focus { outline: none; border-color: var(--navy); }

        .btn-add-row {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; background: #eff6ff;
            color: var(--navy); border: 1.5px dashed var(--navy);
            border-radius: 7px; font-size: 12px; font-weight: 700;
            cursor: pointer; margin-top: 10px; transition: .2s;
        }
        .btn-add-row:hover { background: #dbeafe; }

        /* ─── Nav buttons ─── */
        .nav-row {
            display: flex; justify-content: space-between;
            align-items: center; gap: 12px; margin-top: 8px;
        }
        .btn-nav {
            padding: 12px 28px; border-radius: 8px; border: none;
            font-family: 'Inter', sans-serif; font-size: 14px; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; gap: 8px; transition: .2s;
        }
        .btn-back   { background: #f1f5f9; color: var(--muted); border: 1px solid var(--border); }
        .btn-back:hover { background: #e2e8f0; }
        .btn-next   { background: var(--navy); color: #fff; }
        .btn-next:hover { background: #002a6d; }
        .btn-submit { background: var(--success); color: #fff; }
        .btn-submit:hover { background: #059669; }
        .btn-submit:disabled { background: #94a3b8; cursor: not-allowed; }

        /* ─── Privacy box ─── */
        .privacy-box {
            background: #f8faff; border: 1px solid var(--border);
            border-radius: 8px; padding: 16px;
            font-size: 12px; color: var(--muted); line-height: 1.8;
        }
        .privacy-check {
            display: flex; align-items: flex-start; gap: 10px; margin-top: 12px;
        }
        .privacy-check input { width: 18px; height: 18px; accent-color: var(--navy); flex-shrink: 0; margin-top: 1px; }
        .privacy-check label { font-size: 13px; font-weight: 600; color: var(--text); cursor: pointer; }

        /* ─── Error banner ─── */
        .err-banner {
            background: #fef2f2; border: 1px solid #fca5a5;
            border-radius: 8px; padding: 12px 16px; font-size: 13px;
            color: #b91c1c; display: none; align-items: center; gap: 10px; margin-bottom: 16px;
        }
        .err-banner.show { display: flex; }

        /* ─── Duplicate alert ─── */
        .dup-alert {
            max-width: 780px; margin: 16px auto 0; padding: 0 16px;
        }
        .dup-alert-inner {
            background: #fef2f2; border: 1.5px solid #fca5a5;
            border-radius: 10px; padding: 16px 20px;
            display: flex; gap: 12px; align-items: flex-start;
        }

        /* ─── Responsive ─── */
        @media (max-width: 640px) {
            .field-row.g-name,
            .field-row.g-addr,
            .field-row.g-info,
            .field-row.g3 { grid-template-columns: 1fr 1fr; }
            .check-grid   { grid-template-columns: 1fr; }
            .sec-body     { padding: 16px; }
        }
        @media (max-width: 400px) {
            .field-row.g-name,
            .field-row.g-addr,
            .field-row.g-info,
            .field-row.g3,
            .field-row.g2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php
// ── Show duplicate/error alerts passed back from submit_public_form.php ──
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
<div class="form-wrap">

    <!-- ════════════════════════════════════
         STEP 1 — PERSONAL INFORMATION
    ════════════════════════════════════ -->
    <div class="form-section active" id="step1">

        <div class="err-banner" id="err1">
            <i class="fas fa-exclamation-circle"></i>
            <span id="err1msg">Pakiusap punan ang lahat ng may * bago magpatuloy.</span>
        </div>

        <div class="sec-header navy">
            <i class="fas fa-user"></i>
            Part I — Impormasyon ng Benepisyaryo
            <span class="sub">(Beneficiary's Identifying Information)</span>
        </div>
        <div class="sec-body">

            <!-- Name row -->
            <div class="field-row g-name">
                <div class="field">
                    <label>Apelyido <span class="req">*</span><span class="fil">Last Name</span></label>
                    <input type="text" name="lname" id="lname"
                           placeholder="DELA CRUZ" required
                           autocomplete="family-name" autocapitalize="characters">
                </div>
                <div class="field">
                    <label>Unang Pangalan <span class="req">*</span><span class="fil">First Name</span></label>
                    <input type="text" name="fname" id="fname"
                           placeholder="JUAN" required
                           autocomplete="given-name" autocapitalize="characters">
                </div>
                <div class="field">
                    <label>Gitnang Pangalan<span class="fil">Middle Name</span></label>
                    <input type="text" name="mname" id="mname"
                           placeholder="SANTOS"
                           autocomplete="additional-name" autocapitalize="characters">
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
                    <input type="text" name="street" placeholder="123 MABINI ST."
                           autocapitalize="characters">
                </div>
                <div class="field">
                    <label>Barangay <span class="req">*</span></label>
                    <select name="barangay" required>
                        <option value="">— Piliin —</option>
                        <?php
                        $brgy = ["Alicia","Amihan","Apolonio Samson","Aurora","Baesa","Bagbag","Bagong Pag-Asa","Bagong Silangan","Bagumbayan","Bagumbuhay","Bahay Toro","Balingasa","Balintawak","Bangkulasi","Batasan Hills","Bayanihan","Blue Ridge A","Blue Ridge B","Botocan","Bungad","Camp Aguinaldo","Capri","Commonwealth","Culiat","Damar","Damayan","Damayan Lagi","Damayang Lagi","Del Monte","Dioquino Zobel","Don Manuel","Dona Aurora","Dona Faustina I","Dona Faustina II","Dona Imelda","Dona Josefa","Duyan-Duyan","E. Rodriguez","East Kamias","Escopa I","Escopa II","Escopa III","Escopa IV","Fairview","Fernandez","Filinvest I","Filinvest II","Fuentebella","Gulod","Holy Spirit","Horseshoe","Immaculate Concepcion","Kaligayahan","Kalusugan","Kamuning","Katipunan","Kaunlaran","Kristong Hari","Krus na Ligas","Laging Handa","Libis","Lourdes","Loyola Heights","Maharlika","Malaya","Mangga","Manresa","Mariana","Mariblo","Marilag","Masagana","Masambong","Matalahib","Matandang Balara","Milagrosa","Model","Nagkaisang Nayon","Nayong Kanluran","New Era","Novaliches Proper","Obrero","Old Capitol Site","Pagasa","Pag-ibig sa Nayon","Palingon","Paraiso","Pasong Putik","Phil-Am","Pinagkaisahan","Pinyahan","Quirino 2-A","Quirino 2-B","Quirino 2-C","Quirino 3-A","Ramon Magsaysay","Roxas","Sacred Heart","Saint Ignatius","Saint Peter","Salvacion","San Agustin","San Antonio","San Bartolome","San Isidro","San Isidro Labrador","San Jose","San Martin de Porres","San Roque","San Vicente","Sangandaan","Santa Cruz","Santa Lucia","Santa Monica","Santa Teresita","Santo Cristo","Santo Domingo","Santo Niño","Santulan","Silangan","Soccorro","South Triangle","Talayan","Talipapa","Tandang Sora","Tatalon","Teachers Village East","Teachers Village West","U.P. Campus","Ugong Norte","Unang Sigaw","Valencia","Vasra","Veterans Village","Villa Maria Clara","West Kamias","West Triangle","White Plains"];
                        foreach ($brgy as $b) echo "<option value=\"" . htmlspecialchars($b) . "\">" . htmlspecialchars($b) . "</option>";
                        ?>
                    </select>
                </div>
                <div class="field">
                    <label>City<span class="fil">Lungsod</span></label>
                    <input type="text" name="city" value="QUEZON CITY" readonly>
                </div>
                <div class="field">
                    <label>Region</label>
                    <input type="text" name="region" value="NCR" readonly>
                </div>
            </div>

            <!-- Contact / Occupation -->
            <div class="field-row g2">
                <div class="field">
                    <label>Numero ng Telepono <span class="req">*</span><span class="fil">Mobile No.</span></label>
                    <input type="tel" name="cp_number" id="cp_number"
                           placeholder="09XX-XXX-XXXX" required>
                </div>
                <div class="field">
                    <label>Trabaho<span class="fil">Occupation, N/A if none</span></label>
                    <input type="text" name="occupation"
                           placeholder="TRABAHO"
                           autocapitalize="characters">
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

        <div class="err-banner" id="err2">
            <i class="fas fa-exclamation-circle"></i>
            <span id="err2msg">Pakiusap punan ang lahat ng may * bago magpatuloy.</span>
        </div>

        <div class="sec-header red">
            <i class="fas fa-hand-holding-medical"></i>
            Part II — Detalye ng Tulong
            <span class="sub">(Assistance Details)</span>
        </div>
        <div class="sec-body">

            <!-- Client Category -->
            <div class="field-row g2" style="margin-bottom:4px;">
                <div class="field">
                    <label>Kategorya ng Kliyente <span class="req">*</span><span class="fil">Client Category</span></label>
                    <select name="client_category" id="client_category" required>
                        <option value="">— Piliin —</option>
                        <option>Family Heads and Other Needy Adult</option>
                        <option>Persons with Disabilities</option>
                        <option>Senior Citizens</option>
                        <option>Men/Women in Specially Difficult Circumstances</option>
                    </select>
                </div>
            </div>

            <!-- Hidden fields to carry radio values -->
            <input type="hidden" name="client_subcategory" id="subcatHidden">
            <input type="hidden" name="medical_cause"      id="medCauseHidden">
            <input type="hidden" name="assistance_type"    id="assistHidden">

            <!-- Sub-category -->
            <div class="field" style="margin-bottom:16px;">
                <label>Pumili ng Sub-Kategorya: <span class="req">*</span>
                    <span class="fil">Isa lamang (Select one only)</span>
                </label>
                <div class="check-grid" id="subcatGrid">
                    <?php
                    $subcats = [
                        "Individuals with Cancer","Dialysis Patients","Chronic Illness / Geriatric Conditions",
                        "Tuberculosis Patients","Rare Disease / Disability caused by Rare Disease",
                        "Physical Disability / Orthopedically Handicapped","Visual Disability / Visually Impaired",
                        "Hearing/Speech Impaired","Psychosocial/Mental/Learning Disability",
                        "Intellectual Disability / Mentally Challenged","Non-apparent Speech and Language Impairment",
                        "Victims of Disaster","Internally Displaced Family",
                        "Person of Concerns - Asylum Seeker / Refugee / Stateless Persons",
                        "Physically-abused / maltreated / battered","Victims of involuntary prostitution",
                        "Recovering Person who used Drugs","Wounded in Action (WIA)","Solo Parent","4Ps Beneficiary"
                    ];
                    foreach ($subcats as $s):
                    ?>
                    <label class="check-item">
                        <input type="radio" name="client_subcategory_radio" class="subcat-check"
                               value="<?php echo htmlspecialchars($s); ?>">
                        <?php echo htmlspecialchars($s); ?>
                    </label>
                    <?php endforeach; ?>
                    <label class="check-item">
                        <input type="radio" name="client_subcategory_radio"
                               id="subcat_other_cb" value="__other__">
                        Others (specify)
                    </label>
                    <input type="text" class="check-other-input" id="subcat_other_input"
                           placeholder="PLEASE SPECIFY..." autocapitalize="characters">
                </div>
            </div>

            <!-- Medical Cause -->
            <div class="field" style="margin-bottom:16px;">
                <label>Dahilan ng Paghingi ng Tulong <span class="req">*</span>
                    <span class="fil">Medical Cause — Isa lamang (Select one only)</span>
                </label>
                <div class="check-grid">
                    <?php
                    $causes = ["Medical Checkup","Emergency Treatment","Maternity Care","Chemotherapy",
                               "Surgery","Hospitalization","Laboratory Tests","Accident Injury","Dialysis"];
                    foreach ($causes as $c):
                    ?>
                    <label class="check-item">
                        <input type="radio" name="medical_cause_radio" class="medcause-check"
                               value="<?php echo htmlspecialchars($c); ?>">
                        <?php echo htmlspecialchars($c); ?>
                    </label>
                    <?php endforeach; ?>
                    <label class="check-item">
                        <input type="radio" name="medical_cause_radio" id="medother_cb" value="__other__">
                        Others (specify)
                    </label>
                    <input type="text" class="check-other-input" id="medother_input"
                           placeholder="PLEASE SPECIFY..." autocapitalize="characters">
                </div>
            </div>

            <!-- Assistance Type -->
            <div class="field" style="margin-bottom:8px;">
                <label>Uri ng Tulong na Hinihiling <span class="req">*</span>
                    <span class="fil">Type of Assistance — Isa lamang (Select one only)</span>
                </label>
                <div class="check-grid">
                    <?php
                    $assists = ["Medical Assistance","Cash Guarantee","Surgery Financial Support",
                                "Laboratory Assistance","Dialysis Assistance","Medicine Assistance",
                                "Food Assistance","Funeral Assistance","Education Assistance","Transportation Assistance"];
                    foreach ($assists as $a):
                    ?>
                    <label class="check-item">
                        <input type="radio" name="assistance_type_radio" class="assist-check"
                               value="<?php echo htmlspecialchars($a); ?>">
                        <?php echo htmlspecialchars($a); ?>
                    </label>
                    <?php endforeach; ?>
                    <label class="check-item">
                        <input type="radio" name="assistance_type_radio"
                               id="assist_other_cb" value="__other__">
                        Others (specify)
                    </label>
                    <input type="text" class="check-other-input" id="assist_other_input"
                           placeholder="PLEASE SPECIFY..." autocapitalize="characters">
                </div>
            </div>

        </div><!-- end sec-body -->

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

        <div class="err-banner" id="err3">
            <i class="fas fa-exclamation-circle"></i>
            <span id="err3msg">Kailangan ninyong sumang-ayon sa Data Privacy Policy bago mag-submit.</span>
        </div>

        <div class="sec-header slate">
            <i class="fas fa-users"></i>
            Komposisyon ng Pamilya
            <span class="sub">(Family Composition)</span>
        </div>
        <div class="sec-body">
            <p style="font-size:12px; color:var(--muted); margin-bottom:12px;">
                <i class="fas fa-info-circle" style="margin-right:4px;"></i>
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
                            <td><input type="text" name="family_name[]"
                                       placeholder="FULL NAME" autocapitalize="characters"></td>
                            <td>
                                <select name="family_relation[]">
                                    <option value="">— Select —</option>
                                    <option>Spouse</option><option>Child</option><option>Parent</option>
                                    <option>Sibling</option><option>Grandchild</option><option>Grandparent</option><option>Other</option>
                                </select>
                            </td>
                            <td><input type="number" name="family_age[]" placeholder="Age" min="0" max="120"></td>
                            <td><input type="text" name="family_occupation[]"
                                       placeholder="OCCUPATION" autocapitalize="characters"></td>
                            <td><input type="number" name="family_salary[]"
                                       placeholder="0.00" min="0" step="0.01"></td>
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

</div><!-- end form-wrap -->
</form>

<!-- ════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════ -->
<script>
let currentStep = 1;

/* ── Force uppercase on every applicable text input (real value, not just CSS) ── */
function forceUpper(el) {
    const start = el.selectionStart;
    const end   = el.selectionEnd;
    el.value    = el.value.toUpperCase();
    try { el.setSelectionRange(start, end); } catch(e) {}
}

/* Apply to all existing text / tel inputs */
document.querySelectorAll('input[type="text"], input[type="tel"]').forEach(el => {
    if (el.readOnly) return;
    el.addEventListener('input', () => forceUpper(el));
    el.addEventListener('blur',  () => forceUpper(el));
});

/* Also applied to dynamically-added family rows via addFamilyRow() */

/* ── Auto-compute age from DOB ── */
document.getElementById('dob').addEventListener('change', function () {
    const dob = new Date(this.value);
    if (isNaN(dob)) return;
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
    document.getElementById('age').value = age >= 0 ? age : '';
});

/* ── "Others" text field toggle for radio groups ── */
function watchRadioOther(radioName, otherVal, inputId) {
    document.querySelectorAll('[name="' + radioName + '"]').forEach(r => {
        r.addEventListener('change', () => {
            const inp = document.getElementById(inputId);
            inp.style.display = (r.value === otherVal && r.checked) ? 'block' : 'none';
            if (r.value !== otherVal) inp.value = '';
        });
    });
}
watchRadioOther('client_subcategory_radio', '__other__', 'subcat_other_input');
watchRadioOther('medical_cause_radio',      '__other__', 'medother_input');
watchRadioOther('assistance_type_radio',    '__other__', 'assist_other_input');

/* ── Collect a radio group's value into a hidden field ── */
function collectRadio(radioName, otherVal, otherInputId, hiddenId) {
    const selected = document.querySelector('[name="' + radioName + '"]:checked');
    if (!selected) { document.getElementById(hiddenId).value = ''; return ''; }
    let val = selected.value === otherVal
        ? document.getElementById(otherInputId).value.toUpperCase().trim()
        : selected.value;
    document.getElementById(hiddenId).value = val;
    return val;
}

/* ── Step validation ── */
function validateStep(step) {
    if (step === 1) {
        const required = ['lname','fname','dob','sex','civil_status','barangay','cp_number'];
        let valid = true, firstBad = null;

        required.forEach(name => {
            const el = document.querySelector('[name="' + name + '"]');
            if (!el) return;
            if (!el.value.trim()) {
                el.classList.add('invalid'); valid = false;
                if (!firstBad) firstBad = el;
            } else { el.classList.remove('invalid'); }
        });

        /* Mobile validation */
        const mobile = document.getElementById('cp_number');
        if (mobile && mobile.value.trim() &&
            !/^(09|\+639)\d{9}$/.test(mobile.value.replace(/[-\s]/g, ''))) {
            mobile.classList.add('invalid'); valid = false;
            showErr(1, 'Pakiusap, maglagay ng tamang numero ng telepono (09XXXXXXXXX).');
            if (!firstBad) firstBad = mobile;
        }

        if (!valid) {
            if (document.getElementById('err1msg').textContent.includes('required'))
                document.getElementById('err1msg').textContent = 'Pakiusap punan ang lahat ng may * bago magpatuloy.';
            document.getElementById('err1').classList.add('show');
            if (firstBad) firstBad.scrollIntoView({ behavior:'smooth', block:'center' });
        } else {
            document.getElementById('err1').classList.remove('show');
        }
        return valid;
    }

    if (step === 2) {
        const cat = document.getElementById('client_category');
        if (!cat.value) { cat.classList.add('invalid'); showErr(2,'Piliin ang Client Category.'); return false; }
        cat.classList.remove('invalid');

        if (!collectRadio('client_subcategory_radio','__other__','subcat_other_input','subcatHidden'))
            { showErr(2,'Pumili ng isang Sub-Kategorya.'); return false; }
        if (!collectRadio('medical_cause_radio','__other__','medother_input','medCauseHidden'))
            { showErr(2,'Pumili ng isang Medical Cause.'); return false; }
        if (!collectRadio('assistance_type_radio','__other__','assist_other_input','assistHidden'))
            { showErr(2,'Pumili ng isang Uri ng Tulong.'); return false; }

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

/* ── Step navigation ── */
function goStep(target) {
    if (target > currentStep && !validateStep(currentStep)) return;

    if (currentStep === 2) {
        collectRadio('client_subcategory_radio','__other__','subcat_other_input','subcatHidden');
        collectRadio('medical_cause_radio','__other__','medother_input','medCauseHidden');
        collectRadio('assistance_type_radio','__other__','assist_other_input','assistHidden');
    }

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

/* ── Final submit ── */
document.getElementById('aicsForm').addEventListener('submit', function(e) {
    /* Re-collect hidden values */
    collectRadio('client_subcategory_radio','__other__','subcat_other_input','subcatHidden');
    collectRadio('medical_cause_radio','__other__','medother_input','medCauseHidden');
    collectRadio('assistance_type_radio','__other__','assist_other_input','assistHidden');

    if (!document.getElementById('privacyConsent').checked) {
        e.preventDefault();
        showErr(3,'Kailangan ninyong sumang-ayon sa Data Privacy Policy bago mag-submit.');
        return;
    }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Isinusumite...';
});

/* ── Add family member row ── */
function addFamilyRow() {
    const tbody = document.getElementById('familyBody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="family_name[]" placeholder="FULL NAME" autocapitalize="characters"></td>
        <td>
            <select name="family_relation[]">
                <option value="">— Select —</option>
                <option>Spouse</option><option>Child</option><option>Parent</option>
                <option>Sibling</option><option>Grandchild</option><option>Grandparent</option><option>Other</option>
            </select>
        </td>
        <td><input type="number" name="family_age[]" placeholder="Age" min="0" max="120"></td>
        <td><input type="text" name="family_occupation[]" placeholder="OCCUPATION" autocapitalize="characters"></td>
        <td><input type="number" name="family_salary[]" placeholder="0.00" min="0" step="0.01"></td>`;
    tbody.appendChild(tr);

    /* Apply uppercase listener to new text inputs */
    tr.querySelectorAll('input[type="text"]').forEach(el => {
        el.addEventListener('input', () => forceUpper(el));
        el.addEventListener('blur',  () => forceUpper(el));
    });
}

/* ── Clear invalid state on change ── */
document.querySelectorAll('input, select').forEach(el => {
    el.addEventListener('change', () => el.classList.remove('invalid'));
    el.addEventListener('input',  () => el.classList.remove('invalid'));
});
</script>

</body>
</html>