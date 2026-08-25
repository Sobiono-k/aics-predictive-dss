<?php
// view_pending_profile.php
// Opens in a new window — printable DSWD-styled profile of a pending applicant
require_once 'auth.php';

require_once(__DIR__ . '/../db.php');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { echo "Invalid request."; exit(); }

$stmt = $conn->prepare("SELECT * FROM pending_applications WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) { echo "<p style='font-family:sans-serif;padding:40px;color:#ef4444;'>Record not found.</p>"; exit(); }

$fullname  = ucwords(strtolower(trim($row['fname'] . ' ' . ($row['mname'] ? $row['mname'] . ' ' : '') . $row['lname'])));
$bdate     = (!empty($row['birth_date']) && $row['birth_date'] !== '0000-00-00') ? date("F d, Y", strtotime($row['birth_date'])) : 'Not specified';
$submitted = date("F d, Y g:i A", strtotime($row['submitted_at']));
$printed   = date("F d, Y g:i A");
$status    = $row['is_claimed'] ? 'Claimed' : 'Pending';

// Family composition (stored as JSON if available)
$family = [];
if (!empty($row['family_composition'])) {
    $decoded = json_decode($row['family_composition'], true);
    if (is_array($decoded)) $family = $decoded;
}

// Barangay list for edit dropdown
$brgy_list = ["Alicia","Amihan","Apolonio Samson","Aurora","Baesa","Bagbag","Bagong Pag-Asa","Bagong Silangan","Bagumbayan","Bagumbuhay","Bahay Toro","Balingasa","Balintawak","Bangkulasi","Batasan Hills","Bayanihan","Blue Ridge A","Blue Ridge B","Botocan","Bungad","Camp Aguinaldo","Capri","Commonwealth","Culiat","Damar","Damayan","Damayan Lagi","Damayang Lagi","Del Monte","Dioquino Zobel","Don Manuel","Dona Aurora","Dona Faustina I","Dona Faustina II","Dona Imelda","Dona Josefa","Duyan-Duyan","E. Rodriguez","East Kamias","Escopa I","Escopa II","Escopa III","Escopa IV","Fairview","Fernandez","Filinvest I","Filinvest II","Fuentebella","Gulod","Holy Spirit","Horseshoe","Immaculate Concepcion","Kaligayahan","Kalusugan","Kamuning","Katipunan","Kaunlaran","Kristong Hari","Krus na Ligas","Laging Handa","Libis","Lourdes","Loyola Heights","Maharlika","Malaya","Mangga","Manresa","Mariana","Mariblo","Marilag","Masagana","Masambong","Matalahib","Matandang Balara","Milagrosa","Model","Nagkaisang Nayon","Nayong Kanluran","New Era","Novaliches Proper","Obrero","Old Capitol Site","Pagasa","Pag-ibig sa Nayon","Palingon","Paraiso","Pasong Putik","Phil-Am","Pinagkaisahan","Pinyahan","Quirino 2-A","Quirino 2-B","Quirino 2-C","Quirino 3-A","Ramon Magsaysay","Roxas","Sacred Heart","Saint Ignatius","Saint Peter","Salvacion","San Agustin","San Antonio","San Bartolome","San Isidro","San Isidro Labrador","San Jose","San Martin de Porres","San Roque","San Vicente","Sangandaan","Santa Cruz","Santa Lucia","Santa Monica","Santa Teresita","Santo Cristo","Santo Domingo","Santo Niño","Santulan","Silangan","Soccorro","South Triangle","Talayan","Talipapa","Tandang Sora","Tatalon","Teachers Village East","Teachers Village West","U.P. Campus","Ugong Norte","Unang Sigaw","Valencia","Vasra","Veterans Village","Villa Maria Clara","West Kamias","West Triangle","White Plains"];
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
            flex-wrap: wrap;
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
            text-decoration: none;
        }
        .btn-print            { background: var(--navy); color: #fff; }
        .btn-print:hover      { background: #002a6d; }
        .btn-close            { background: #fff; color: var(--muted); border: 1px solid var(--border); }
        .btn-close:hover      { background: #f1f5f9; }
        .btn-confirm-toolbar  { background: #10b981; color: #fff; }
        .btn-confirm-toolbar:hover { background: #059669; }
        .btn-edit             { background: #f59e0b; color: #fff; }
        .btn-edit:hover       { background: #d97706; }
        .btn-save             { background: #10b981; color: #fff; }
        .btn-save:hover       { background: #059669; }

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
            font-size: 26px; flex-shrink: 0;
        }
        .doc-header h1  { font-size: 15px; font-weight: 800; line-height: 1.2; }
        .doc-header p   { font-size: 10px; opacity: .75; margin-top: 3px; letter-spacing: .5px; }
        .doc-header .right-info { text-align: right; font-size: 11px; opacity: .85; line-height: 1.7; }

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
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 10px;
        }
        .code-banner .code {
            font-family: 'Courier New', monospace;
            font-size: 22px; font-weight: 800;
            color: var(--navy); letter-spacing: 2px;
        }
        .status-pill {
            padding: 5px 14px; border-radius: 20px;
            font-size: 12px; font-weight: 800;
            text-transform: uppercase; letter-spacing: .5px;
            background: #fef9c3; color: #854d0e; border: 1px solid #fde68a;
        }
        .status-pill.claimed { background: #dcfce7; color: #166534; border-color: #bbf7d0; }

        /* ── Section titles ── */
        .section-title {
            background: var(--navy); color: #fff;
            padding: 8px 28px; font-size: 11px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 1px;
            display: flex; align-items: center; gap: 8px;
        }
        .section-title.red   { background: var(--red); }
        .section-title.slate { background: #475569; }
        .section-title span.sub {
            font-weight: 400; font-size: 10px; opacity: .8;
            font-style: italic; text-transform: none; letter-spacing: 0;
        }

        /* ── Info grid ── */
        .info-body { padding: 20px 28px; }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px 24px;
        }
        .info-grid.cols-2    { grid-template-columns: 1fr 1fr; }
        .info-grid.cols-4    { grid-template-columns: repeat(4, 1fr); }
        .info-grid.cols-full { grid-template-columns: 1fr; }

        .info-item label {
            font-size: 9px; font-weight: 800; color: var(--muted);
            text-transform: uppercase; letter-spacing: .8px;
            display: block; margin-bottom: 3px;
        }
        .info-item .value {
            font-size: 14px; color: var(--text); font-weight: 600;
            border-bottom: 1.5px solid #e2e8f0;
            padding-bottom: 5px; min-height: 26px;
        }
        .info-item .value.highlight { color: var(--navy); }
        .info-item .value.green     { color: #059669; }
        .info-item .value.mono      { font-family: 'Courier New', monospace; color: var(--navy); font-size: 13px; }

        /* ── Inline edit inputs ── */
        .edit-input {
            display: none;
            width: 100%;
            padding: 6px 10px;
            border: 1.5px solid var(--navy);
            border-radius: 6px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            color: var(--text);
            background: #f0f4ff;
            text-transform: capitalize;
        }
        .edit-input:focus { outline: none; box-shadow: 0 0 0 3px rgba(0,56,147,.15); }
        .edit-input[type="date"] { text-transform: none; }
        select.edit-input        { text-transform: none; cursor: pointer; }

        /* ── Family table ── */
        .family-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .family-table th {
            background: #f8fafc; padding: 8px 12px; text-align: left;
            font-size: 9px; font-weight: 800; color: var(--muted);
            text-transform: uppercase; border-bottom: 1.5px solid var(--border); letter-spacing: .5px;
        }
        .family-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; color: var(--text); }
        .family-table tr:last-child td { border-bottom: none; }
        .family-table .empty-row td { color: #cbd5e1; font-style: italic; text-align: center; padding: 20px; }

        /* ── Signature area ── */
        .sig-area {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 40px; padding: 24px 28px;
            border-top: 1.5px solid var(--border);
        }
        .sig-box  { text-align: center; }
        .sig-line { border-bottom: 1.5px solid var(--text); margin-bottom: 6px; height: 40px; }
        .sig-label { font-size: 10px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }

        /* ── Footer ── */
        .doc-footer {
            background: var(--navy); padding: 10px 28px;
            display: flex; justify-content: space-between; align-items: center;
            font-size: 10px; color: rgba(255,255,255,.65);
        }
        .doc-footer span.gold { color: var(--gold); font-weight: 700; }

        /* ── Divider ── */
        .hr { border: 0; border-top: 1px solid #e2e8f0; margin: 0; }

        /* ── Print styles ── */
        @media print {
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            body { background: #fff; padding: 0; }
            .toolbar  { display: none !important; }
            .edit-input { display: none !important; }
            .view-val   { display: block !important; }
            .document   { box-shadow: none; border: none; max-width: 100%; }
            @page       { margin: 12mm 14mm; size: A4; }
        }

        @media (max-width: 600px) {
            body { padding: 10px; }
            .info-grid        { grid-template-columns: 1fr 1fr; }
            .info-grid.cols-4 { grid-template-columns: 1fr 1fr; }
            .sig-area         { grid-template-columns: 1fr; gap: 20px; }
            .doc-header       { flex-direction: column; }
            .doc-header .right-info { text-align: left; }
        }
    </style>
</head>
<body>

<!-- ── Screen toolbar ── -->
<div class="toolbar">

    <?php if (!$row['is_claimed']): ?>
        <button class="btn-toolbar btn-edit" id="btnEdit" onclick="toggleEdit()">
            <i class="fas fa-edit"></i> Edit Record
        </button>
        <button class="btn-toolbar btn-save" id="btnSave" style="display:none;" onclick="saveEdits()">
            <i class="fas fa-save"></i> Save Changes
        </button>
        <button class="btn-toolbar btn-close" id="btnCancelEdit" style="display:none;" onclick="cancelEdit()">
            <i class="fas fa-times"></i> Cancel
        </button>
    <?php endif; ?>

    <button class="btn-toolbar btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Profile
    </button>

    <?php if (!$row['is_claimed']): ?>
        <form method="POST" action="lookup_applicant.php" style="display:inline;">
            <input type="hidden" name="confirm_code" value="<?php echo htmlspecialchars($row['application_code']); ?>">
            <button type="submit" class="btn-toolbar btn-confirm-toolbar">
                <i class="fas fa-check-circle"></i> Confirm & Save to Records
            </button>
        </form>
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
            <span class="status-pill <?php echo $row['is_claimed'] ? 'claimed' : ''; ?>">
                <?php echo $status; ?>
            </span>
            <div style="font-size:10px; color:var(--muted); margin-top:6px;">
                Submitted: <?php echo $submitted; ?>
            </div>
        </div>
    </div>

    <!-- ══ PART I: BENEFICIARY INFORMATION ══ -->
    <div class="section-title">
        <i class="fas fa-user"></i>
        Part I: Beneficiary's Identifying Information
        <span class="sub">— Impormasyon ng Benepisyaryo</span>
    </div>

    <div class="info-body">

        <!-- Row 1: Full name display + Last + First -->
        <div class="info-grid cols-4" style="margin-bottom:18px;">

            <!-- Full Name — read-only display, auto-updates on save -->
            <div class="info-item" style="grid-column: span 2;">
                <label>Buong Pangalan / Full Name</label>
                <div class="value highlight" id="displayFullName"><?php echo htmlspecialchars($fullname); ?></div>
            </div>

            <!-- Last Name -->
            <div class="info-item">
                <label>Apelyido / Last Name</label>
                <div class="value view-val" data-field="lname"><?php echo htmlspecialchars(ucwords(strtolower($row['lname']))); ?></div>
                <input class="edit-input" data-field="lname" type="text"
                       value="<?php echo htmlspecialchars($row['lname']); ?>"
                       placeholder="Last Name">
            </div>

            <!-- First Name -->
            <div class="info-item">
                <label>Unang Pangalan / First Name</label>
                <div class="value view-val" data-field="fname"><?php echo htmlspecialchars(ucwords(strtolower($row['fname']))); ?></div>
                <input class="edit-input" data-field="fname" type="text"
                       value="<?php echo htmlspecialchars($row['fname']); ?>"
                       placeholder="First Name">
            </div>

        </div>

        <!-- Row 2: Middle Name + Extension -->
        <div class="info-grid cols-4" style="margin-bottom:18px;">

            <div class="info-item" style="grid-column: span 2;">
                <label>Gitnang Pangalan / Middle Name</label>
                <div class="value view-val" data-field="mname"><?php echo htmlspecialchars(ucwords(strtolower($row['mname'] ?? ''))); ?></div>
                <input class="edit-input" data-field="mname" type="text"
                       value="<?php echo htmlspecialchars($row['mname'] ?? ''); ?>"
                       placeholder="Middle Name">
            </div>

            <div class="info-item">
                <label>Extension</label>
                <div class="value view-val" data-field="ext"><?php echo htmlspecialchars($row['ext'] ?? '—'); ?></div>
                <select class="edit-input" data-field="ext">
                    <option value="">—</option>
                    <?php foreach (['Sr.','Jr.','II','III','IV'] as $ext): ?>
                        <option value="<?php echo $ext; ?>" <?php echo (($row['ext'] ?? '') === $ext) ? 'selected' : ''; ?>><?php echo $ext; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>

        <!-- Row 3: Birth Date, Sex, Civil Status -->
        <div class="info-grid" style="margin-bottom:18px;">

            <div class="info-item">
                <label>Kapanganakan / Date of Birth</label>
                <div class="value view-val" data-field="birth_date"><?php echo $bdate; ?></div>
                <input class="edit-input" data-field="birth_date" type="date"
                       value="<?php echo (!empty($row['birth_date']) && $row['birth_date'] !== '0000-00-00') ? $row['birth_date'] : ''; ?>">
            </div>

            <div class="info-item">
                <label>Kasarian / Sex</label>
                <div class="value view-val" data-field="sex"><?php echo htmlspecialchars($row['sex'] ?? '—'); ?></div>
                <select class="edit-input" data-field="sex">
                    <option value="">— Select —</option>
                    <?php foreach (['Male','Female'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo (($row['sex'] ?? '') === $s) ? 'selected' : ''; ?>><?php echo $s; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="info-item">
                <label>Katayuang Sibil / Civil Status</label>
                <div class="value view-val" data-field="civil_status"><?php echo htmlspecialchars($row['civil_status'] ?? '—'); ?></div>
                <select class="edit-input" data-field="civil_status">
                    <option value="">— Select —</option>
                    <?php foreach (['Single','Married','Widowed','Separated','Cohabiting'] as $cs): ?>
                        <option value="<?php echo $cs; ?>" <?php echo (($row['civil_status'] ?? '') === $cs) ? 'selected' : ''; ?>><?php echo $cs; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>

        <!-- Row 4: Barangay, City, Region -->
        <div class="info-grid" style="margin-bottom:18px;">

            <div class="info-item">
                <label>Barangay</label>
                <div class="value view-val" data-field="barangay"><?php echo htmlspecialchars($row['barangay'] ?? '—'); ?></div>
                <select class="edit-input" data-field="barangay">
                    <option value="">— Select —</option>
                    <?php foreach ($brgy_list as $b): ?>
                        <option value="<?php echo htmlspecialchars($b); ?>" <?php echo (($row['barangay'] ?? '') === $b) ? 'selected' : ''; ?>><?php echo htmlspecialchars($b); ?></option>
                    <?php endforeach; ?>
                </select>
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

    </div><!-- end info-body Part I -->

    <hr class="hr">

    <!-- ══ PART II: ASSISTANCE DETAILS ══ -->
    <div class="section-title red">
        <i class="fas fa-hand-holding-medical"></i>
        Part II: Assistance Details
        <span class="sub">— Detalye ng Tulong</span>
    </div>

    <div class="info-body">

        <!-- Assistance Type + Client Category -->
        <div class="info-grid cols-2" style="margin-bottom:18px;">

            <div class="info-item">
                <label>Uri ng Tulong / Type of Assistance</label>
                <div class="value green view-val" data-field="assistance_type"><?php echo htmlspecialchars($row['assistance_type'] ?? '—'); ?></div>
                <select class="edit-input" data-field="assistance_type">
                    <option value="">— Select —</option>
                    <?php foreach (["Medical Assistance","Cash Guarantee","Surgery Financial Support","Laboratory Assistance","Dialysis Assistance","Medicine Assistance","Food Assistance","Funeral Assistance","Education Assistance","Transportation Assistance"] as $a): ?>
                        <option value="<?php echo htmlspecialchars($a); ?>" <?php echo (($row['assistance_type'] ?? '') === $a) ? 'selected' : ''; ?>><?php echo htmlspecialchars($a); ?></option>
                    <?php endforeach; ?>
                    <option value="Others" <?php echo (!in_array($row['assistance_type'] ?? '', ["Medical Assistance","Cash Guarantee","Surgery Financial Support","Laboratory Assistance","Dialysis Assistance","Medicine Assistance","Food Assistance","Funeral Assistance","Education Assistance","Transportation Assistance"]) && !empty($row['assistance_type'])) ? 'selected' : ''; ?>>Others</option>
                </select>
            </div>

            <div class="info-item">
                <label>Kategorya ng Kliyente / Client Category</label>
                <div class="value view-val" data-field="client_category"><?php echo htmlspecialchars($row['client_category'] ?? '—'); ?></div>
                <select class="edit-input" data-field="client_category">
                    <option value="">— Select —</option>
                    <?php foreach (["Family Heads and Other Needy Adult","Persons with Disabilities","Senior Citizens","Men/Women in Specially Difficult Circumstances"] as $cc): ?>
                        <option value="<?php echo htmlspecialchars($cc); ?>" <?php echo (($row['client_category'] ?? '') === $cc) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cc); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

        </div>

        <!-- Sub-Category -->
        <div class="info-grid cols-full" style="margin-bottom:18px;">
            <div class="info-item">
                <label>Sub-Kategorya / Sub-Category</label>
                <div class="value view-val" data-field="client_subcategory"><?php echo htmlspecialchars($row['client_subcategory'] ?? '—'); ?></div>
                <select class="edit-input" data-field="client_subcategory">
                    <option value="">— Select —</option>
                    <?php foreach (["Individuals with Cancer","Dialysis Patients","Chronic Illness / Geriatric Conditions","Tuberculosis Patients","Rare Disease / Disability caused by Rare Disease","Physical Disability / Orthopedically Handicapped","Visual Disability / Visually Impaired","Hearing/Speech Impaired","Psychosocial/Mental/Learning Disability","Intellectual Disability / Mentally Challenged","Non-apparent Speech and Language Impairment","Victims of Disaster","Internally Displaced Family","Person of Concerns - Asylum Seeker / Refugee / Stateless Persons","Physically-abused / maltreated / battered","Victims of involuntary prostitution","Recovering Person who used Drugs","Wounded in Action (WIA)","Solo Parent","4Ps Beneficiary"] as $sc): ?>
                        <option value="<?php echo htmlspecialchars($sc); ?>" <?php echo (($row['client_subcategory'] ?? '') === $sc) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sc); ?></option>
                    <?php endforeach; ?>
                    <option value="Others">Others</option>
                </select>
            </div>
        </div>

        <!-- Medical Cause -->
        <div class="info-grid cols-full">
            <div class="info-item">
                <label>Dahilan ng Kahilingan / Medical Cause</label>
                <?php
                $cause   = $row['medical_cause'] ?? '—';
                $decoded = json_decode($cause, true);
                $causeDisplay = htmlspecialchars(is_array($decoded) ? implode(', ', $decoded) : $cause);
                ?>
                <div class="value view-val" data-field="medical_cause" style="white-space:pre-line; line-height:1.6; font-size:13px;">
                    <?php echo $causeDisplay; ?>
                </div>
                <select class="edit-input" data-field="medical_cause">
                    <option value="">— Select —</option>
                    <?php foreach (["Medical Checkup","Emergency Treatment","Maternity Care","Chemotherapy","Surgery","Hospitalization","Laboratory Tests","Accident Injury","Dialysis"] as $mc): ?>
                        <option value="<?php echo htmlspecialchars($mc); ?>" <?php echo (($row['medical_cause'] ?? '') === $mc) ? 'selected' : ''; ?>><?php echo htmlspecialchars($mc); ?></option>
                    <?php endforeach; ?>
                    <option value="Others" <?php echo (!in_array($row['medical_cause'] ?? '', ["Medical Checkup","Emergency Treatment","Maternity Care","Chemotherapy","Surgery","Hospitalization","Laboratory Tests","Accident Injury","Dialysis"]) && !empty($row['medical_cause'])) ? 'selected' : ''; ?>>Others</option>
                </select>
            </div>
        </div>

    </div><!-- end info-body Part II -->

    <hr class="hr">

    <!-- ══ FAMILY COMPOSITION ══ -->
    <div class="section-title slate">
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
                        <td><?php echo !empty($member['salary']) ? '₱ ' . number_format((float)$member['salary'], 2) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row">
                        <td colspan="5">No family composition data provided.</td>
                    </tr>
                <?php endif; ?>
                <!-- Blank rows for manual writing when printed -->
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

    <!-- ══ SOCIAL WORKER'S ASSESSMENT ══ -->
    <div class="section-title red">
        <i class="fas fa-clipboard-check"></i>
        Social Worker's Assessment
        <span class="sub">— Para sa DSWD Personnel</span>
    </div>

    <div class="info-body">
        <div style="border: 1.5px dashed var(--border); border-radius:8px; padding:16px; min-height:80px; background:#fafbff; font-size:12px; color:#94a3b8; font-style:italic;">
            Accordingly, the client has no means of income due to unexpected circumstances. Hence, the client is eligible for financial assistance to sustain his/her basic necessities.
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
            <div style="font-size:12px; font-weight:700; color:var(--text);" id="sigName"><?php echo htmlspecialchars($fullname); ?></div>
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
const RECORD_ID = <?php echo (int)$row['id']; ?>;

/* ── Toggle edit mode on/off ── */
function toggleEdit() {
    document.querySelectorAll('.view-val').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.edit-input').forEach(el => el.style.display = 'block');
    document.getElementById('btnEdit').style.display = 'none';
    document.getElementById('btnSave').style.display = 'flex';
    document.getElementById('btnCancelEdit').style.display = 'flex';
}

function cancelEdit() {
    document.querySelectorAll('.view-val').forEach(el => el.style.display = 'block');
    document.querySelectorAll('.edit-input').forEach(el => el.style.display = 'none');
    document.getElementById('btnEdit').style.display = 'flex';
    document.getElementById('btnSave').style.display = 'none';
    document.getElementById('btnCancelEdit').style.display = 'none';
}

/* ── Title-case helper ── */
function toTitleCase(str) {
    return str.replace(/\b\w/g, c => c.toUpperCase());
}

/* ── Save edits via fetch ── */
function saveEdits() {
    const btn = document.getElementById('btnSave');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    btn.disabled = true;

    const payload = new URLSearchParams({ id: RECORD_ID });
    document.querySelectorAll('.edit-input').forEach(el => {
        payload.append(el.dataset.field, el.value.trim());
    });

    fetch('update_pending.php', { method: 'POST', body: payload })
        .then(r => r.json())
        .then(result => {
            btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
            btn.disabled = false;

            if (result.ok) {
                /* Update each view-val with the new value */
                document.querySelectorAll('.edit-input').forEach(el => {
                    const viewEl = document.querySelector(`.view-val[data-field="${el.dataset.field}"]`);
                    if (!viewEl) return;

                    let display = '';
                    if (el.tagName === 'SELECT') {
                        display = el.options[el.selectedIndex]?.text || el.value;
                    } else {
                        display = el.value.trim();
                    }

                    /* Apply title-case only for plain text fields */
                    if (el.type === 'text') display = toTitleCase(display);

                    /* Format date nicely */
                    if (el.type === 'date' && el.value) {
                        const d = new Date(el.value + 'T00:00:00');
                        display = d.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'2-digit' });
                    }

                    viewEl.textContent = display || '—';
                });

                /* Rebuild full name display */
                const fname = toTitleCase((document.querySelector('.edit-input[data-field="fname"]')?.value || '').trim());
                const mname = toTitleCase((document.querySelector('.edit-input[data-field="mname"]')?.value || '').trim());
                const lname = toTitleCase((document.querySelector('.edit-input[data-field="lname"]')?.value || '').trim());
                const fullName = [fname, mname, lname].filter(Boolean).join(' ');
                const fnEl = document.getElementById('displayFullName');
                const sigEl = document.getElementById('sigName');
                if (fnEl)  fnEl.textContent  = fullName;
                if (sigEl) sigEl.textContent  = fullName;

                cancelEdit();
                showToast('✓ Changes saved successfully!', '#10b981');
            } else {
                showToast('✗ Save failed. Please try again.', '#ef4444');
            }
        })
        .catch(() => {
            btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
            btn.disabled = false;
            showToast('✗ Network error. Could not save.', '#ef4444');
        });
}

/* ── Toast notification ── */
function showToast(msg, color) {
    const el = document.createElement('div');
    el.textContent = msg;
    el.style.cssText = `
        position:fixed; top:20px; right:20px;
        background:${color}; color:#fff;
        padding:13px 22px; border-radius:8px;
        font-weight:700; font-size:14px;
        z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,.2);
        transition: opacity .4s;
    `;
    document.body.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 3000);
}

/* ── Auto-capitalize text edit-inputs on blur ── */
document.querySelectorAll('.edit-input[type="text"]').forEach(el => {
    el.addEventListener('blur', function () {
        this.value = toTitleCase(this.value);
    });
});
</script>

</body>
</html>
<?php $conn->close(); ?>