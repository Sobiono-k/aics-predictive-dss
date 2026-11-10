<?php
ob_start();

date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['role'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/auth.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Applicant - General Intake Sheet</title>
    <link rel="icon" type="image/png" href="<?php echo defined('BASE_URL') ? BASE_URL : '../'; ?>images/dswdlogo2.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --dswd-dark: #2c3e50;
            --sidebar-bg: #1e293b;
            --bg-color: #f8fafc;
            --card-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            --sidebar-width: 260px;
            --primary: #3b82f6;
            --success: #10b981;
            --dswd-blue: #0038a8;
            --border: #d1d9e6;
            --muted: #64748b;
        }

        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            background: var(--bg-color);
            display: grid;
            grid-template-columns: var(--sidebar-width) 1fr;
            min-height: 100vh;
            scrollbar-gutter: stable;
        }

        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            position: fixed;
            left: 0;
            top: 0;
            color: #fff;
            display: flex;
            flex-direction: column;
            z-index: 1000;
        }

        .sidebar-header {
            padding: 30px 20px;
            text-align: center;
            background: rgba(0,0,0,0.2);
        }

        .sidebar a {
            padding: 15px 25px;
            font-size: 17px;
            text-decoration: none;
            color: #94a3b8;
            display: flex;
            align-items: center;
            transition: 0.3s;
            border-left: 4px solid transparent;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #334155;
            color: #fff;
            border-left: 4px solid #3b82f6;
        }

        .main {
            grid-column: 2;
            padding: 40px;
            width: 100%;
            box-sizing: border-box;
            animation: fadeIn 0.4s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #bbf7d0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 600;
        }

        .page-content {
            display: flex;
            gap: 30px;
            align-items: flex-start;
        }

        .form-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            flex: 2;
            min-width: 0;
        }

        .header-area {
            margin-bottom: 25px;
            border-bottom: 2px solid var(--dswd-blue);
            padding-bottom: 20px;
        }

        .header-area h4 {
            font-size: 12px;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 1px;
            margin: 0 0 4px;
        }

        .header-area h1 {
            color: var(--dswd-dark);
            font-size: 24px;
            margin: 0;
        }

        .header-area p {
            color: #8392ab;
            font-size: 12px;
            margin: 5px 0 0;
        }

        .intake-class-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr 1.2fr 2fr;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #ffffff;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 20px;
            overflow: hidden;
        }

        .intake-class-grid > div {
            padding: 12px 14px;
            border-right: 1px solid #e2e8f0;
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
            color: #334155;
        }

        .date-box-wrap {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .date-box {
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            height: 24px;
            text-align: center;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
        }

        .form-section-title {
            font-size: 12px;
            font-weight: 800;
            color: #1e293b;
            border-bottom: 2px solid #f1f5f9;
            padding-bottom: 5px;
            margin: 25px 0 15px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }

        .col {
            flex: 1;
            min-width: 0;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
        }

        input, select {
            width: 100%;
            padding: 10px;
            border: 1px solid #d2d6da;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 13px;
        }

        .analysis-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .option-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #344767;
            cursor: pointer;
        }

        .option-item input {
            width: auto;
            margin: 0;
        }

        .btn-submit {
            background: var(--primary);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            font-size: 15px;
            margin-top: 25px;
            transition: background 0.2s;
        }

        .btn-submit:hover {
            background: #2563eb;
        }

        .checklist-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            width: 450px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            flex-shrink: 0;
        }

        .checklist-header {
            background: #3b82f6;
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .checklist-body {
            padding: 20px;
            font-size: 11.5px;
            height: 800px;
            overflow-y: auto;
            line-height: 1.4;
        }

        .cat-title {
            font-weight: 800;
            color: #1e293b;
            margin: 15px 0 8px 0;
            font-size: 10.5px;
            text-transform: uppercase;
            border-left: 3px solid #3b82f6;
            padding-left: 8px;
        }

        .item {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
            color: #475569;
        }

        .field-error {
            color: #ef4444;
            font-size: 10.5px;
            display: none;
            margin-top: 4px;
        }

        /* FAMILY COMPOSITION */
        .family-member {
            display: grid;
            grid-template-columns: 1.5fr 1fr 0.6fr 1.2fr 1fr auto;
            gap: 8px;
            align-items: end;
            background: #f8fafc;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 12px;
        }

        .family-member label {
            font-size: 10px;
        }

        .family-member input {
            background: #fff;
            min-width: 0;
        }

        .remove-family-btn {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-radius: 6px;
            height: 38px;
            width: 38px;
            cursor: pointer;
            transition: 0.2s;
        }

        .remove-family-btn:hover:not(:disabled) {
            background: #fecaca;
        }

        .remove-family-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .add-family-btn {
            background: #10b981;
            color: white;
            border: none;
            padding: 12px 16px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.2s;
            margin-bottom: 15px;
        }

        .add-family-btn:hover {
            background: #059669;
        }

        @media (max-width: 1200px) {
            .page-content {
                flex-direction: column;
            }

            .checklist-card {
                width: 100%;
                box-sizing: border-box;
            }
        }

        @media (max-width: 900px) {
            .family-member {
                grid-template-columns: 1fr 1fr;
            }

            .intake-class-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 600px) {
            .main {
                padding: 15px;
            }

            .form-card {
                padding: 15px;
            }

            .row {
                flex-direction: column;
            }

            .family-member {
                grid-template-columns: 1fr;
            }

            .intake-class-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media print {
            .sidebar,
            .checklist-card,
            .print-btn,
            .alert-success,
            .add-family-btn,
            .remove-family-btn {
                display: none !important;
            }

            body {
                display: block;
            }

            .main {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            .form-card {
                box-shadow: none !important;
                border: none !important;
                width: 100% !important;
            }
        }
    </style>
</head>

<body>

<?php if (file_exists('sidebar.php')) include 'sidebar.php'; ?>

<div class="main">

    <div class="header-area">
        <h4>Republic of the Philippines</h4>
        <h1>New Applicant Registration</h1>
        <p>Batasan Hills AICS - Department of Social Welfare and Development</p>
        <p style="font-style: italic; margin-top: 4px;">Encoding Portal - Medical Assistance Program</p>
    </div>

    <?php if (isset($_GET['status']) && $_GET['status'] == 'success'): ?>
        <div class="alert-success">
            <i class="fas fa-check-circle"></i>
            Applicant successfully registered and data synced.
        </div>
    <?php endif; ?>

    <div class="page-content">

        <div class="form-card">

            <!-- INTAKE CLASSIFICATION GRID -->
            <div class="intake-class-grid">

                <div>
                    <div class="intake-row">
                        <label class="option-item">
                            <input type="radio" name="program_type" value="AICS" checked>
                            AICS
                        </label>
                    </div>

                    <div class="intake-row">
                        <label class="option-item">
                            <input type="radio" name="program_type" value="AKAP">
                            AKAP
                        </label>
                    </div>

                    <div style="border-bottom: 1.5px solid #cbd5e1; width: 100%; margin-top:2px;"></div>
                </div>

                <div>
                    <div class="intake-row">
                        <label class="option-item">
                            <input type="radio" name="client_nature" value="New" checked>
                            New
                        </label>
                    </div>

                    <div class="intake-row" style="margin-top:4px;">
                        <label class="option-item">
                            <input type="radio" name="client_nature" value="Returning">
                            Returning
                        </label>
                    </div>
                </div>

                <div>
                    <div class="intake-row">
                        <label class="option-item">
                            <input type="radio" name="intake_venue" value="Onsite" checked>
                            Onsite
                        </label>
                    </div>

                    <div class="intake-row">
                        <label class="option-item">
                            <input type="radio" name="intake_venue" value="Malasakit Center">
                            Malasakit Center
                        </label>
                    </div>

                    <div class="intake-row">
                        <label class="option-item">
                            <input type="radio" name="intake_venue" value="Offsite">
                            Offsite
                        </label>
                    </div>
                </div>

                <div style="flex-direction:row; align-items:center; justify-content:space-between;">

                    <div style="display:flex; flex-direction:column; gap:6px;">
                        <div class="intake-row">
                            <label class="option-item">
                                <input type="radio" name="intake_mode" value="Walk-in" checked>
                                Walk-in
                            </label>
                        </div>

                        <div class="intake-row">
                            <label class="option-item">
                                <input type="radio" name="intake_mode" value="Referral">
                                Referral
                            </label>
                        </div>
                    </div>

                    <div style="text-align:center;">
                        <div style="margin-bottom:4px; font-size:11px;">Date :</div>

                        <div class="date-box-wrap">
                            <div style="display:flex; flex-direction:column; align-items:center;">
                                <div class="date-box" style="width:28px;"><?php echo date('m'); ?></div>
                                <span style="font-size:8px; font-style:italic; font-weight:400; color:#555; margin-top:2px;">MM</span>
                            </div>

                            <div style="display:flex; flex-direction:column; align-items:center;">
                                <div class="date-box" style="width:28px;"><?php echo date('d'); ?></div>
                                <span style="font-size:8px; font-style:italic; font-weight:400; color:#555; margin-top:2px;">DD</span>
                            </div>

                            <div style="display:flex; flex-direction:column; align-items:center;">
                                <div class="date-box" style="width:42px;"><?php echo date('Y'); ?></div>
                                <span style="font-size:8px; font-style:italic; font-weight:400; color:#555; margin-top:2px;">YYYY</span>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <form action="save_applicant.php" method="POST" id="applicantForm">

                <div class="row">
                    <div class="col">
                        <label>Date of Request</label>
                        <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="col">
                        <label>Email Address</label>
                        <input
                            type="email"
                            name="email"
                            id="email_input"
                            placeholder="email@example.com"
                            pattern="[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$"
                        >
                        <small id="email_error" class="field-error">Please enter a valid email address.</small>
                    </div>
                </div>

                <!-- PERSONAL INFORMATION -->
                <div class="form-section-title">Patient's Personal Information</div>

                <div class="row">
                    <div class="col">
                        <label>First Name</label>
                        <input type="text" name="fname" required>
                    </div>

                    <div class="col">
                        <label>Middle Name</label>
                        <input type="text" name="mname">
                    </div>

                    <div class="col">
                        <label>Last Name</label>
                        <input type="text" name="lname" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col">
                        <label>Date of Birth</label>
                        <input type="date" name="dob" id="dob_input" required>
                    </div>

                    <div class="col">
                        <label>Age</label>
                        <input type="number" name="age" id="age_input" required>
                    </div>

                    <div class="col">
                        <label>Sex</label>
                        <select name="sex" required>
                            <option value="">Select</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>

                    <div class="col">
                        <label>Civil Status</label>
                        <select name="civil_status" required>
                            <option value="">Select</option>
                            <option value="Single">Single</option>
                            <option value="Married">Married</option>
                            <option value="Widowed">Widowed</option>
                            <option value="Separated">Separated</option>
                            <option value="Common-law">Common-law / Live-in</option>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col">
                        <label>Contact Number</label>
                        <input
                            type="tel"
                            name="contact"
                            id="contact_input"
                            maxlength="11"
                            placeholder="09XXXXXXXXX"
                            inputmode="numeric"
                            pattern="[0-9]{11}"
                            required
                        >
                        <small id="contact_error" class="field-error">
                            Contact number must be 11 digits, numbers only.
                        </small>
                    </div>
                </div>

                <!-- ADDRESS WITH CITY -->
                <div class="row">

                    <div class="col" style="position:relative;">
                        <label>Barangay</label>

                        <input
                            type="text"
                            name="barangay"
                            id="barangay_search"
                            placeholder="Type to search barangay..."
                            autocomplete="off"
                            required
                        >

                        <div
                            id="brgy_suggestions"
                            style="position:absolute; width:100%; background:white; border:1px solid #d2d6da; border-top:none; border-radius:0 0 6px 6px; z-index:100; display:none; box-shadow:0 4px 6px rgba(0,0,0,0.1);"
                        ></div>
                    </div>

                    <div class="col">
                        <label>City / Municipality</label>
                        <input
                            type="text"
                            name="city"
                            placeholder="Enter city or municipality"
                            value="Quezon City"
                            required
                        >
                    </div>

                </div>

                <div class="row">
                    <div class="col">
                        <label>House No. / Street / Village</label>
                        <input
                            type="text"
                            name="street"
                            placeholder="Unit/House No, Street Name"
                            required
                        >
                    </div>
                </div>

                <!-- CLIENT CATEGORY -->
                <div class="form-section-title">Client Category / Sector</div>

                <div class="analysis-grid" style="margin-bottom:15px;">
                    <?php
                    $categories = [
                        "Family Heads and Other Needy Adult",
                        "Persons with Disabilities",
                        "Senior Citizens",
                        "Men/Women in Specially Difficult Circumstances"
                    ];

                    foreach ($categories as $cat):
                    ?>
                        <label class="option-item">
                            <input
                                type="radio"
                                name="client_category"
                                value="<?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>"
                                required
                            >
                            <?php echo htmlspecialchars($cat); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <!-- CLIENT SUBCATEGORY -->
                <div class="form-section-title">Client Subcategory</div>

                <div class="analysis-grid" style="margin-bottom:15px;">
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

                    foreach ($subcategories as $sub):
                    ?>
                        <label class="option-item">
                            <input
                                type="radio"
                                name="client_subcategory"
                                value="<?php echo htmlspecialchars($sub, ENT_QUOTES, 'UTF-8'); ?>"
                                required
                            >
                            <?php echo htmlspecialchars($sub); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="row" id="subcat_others_wrap" style="display:none;">
                    <div class="col">
                        <label>Please specify Subcategory</label>
                        <input
                            type="text"
                            name="client_subcategory_other"
                            id="subcat_others_input"
                            placeholder="Enter specific subcategory"
                        >
                    </div>
                </div>

                <!-- FAMILY COMPOSITION -->
                <div class="form-section-title">
                    Komposisyon ng Pamilya / Family Composition
                </div>

                <div id="family-container">

                    <!-- HOUSEHOLD MEMBER 1 -->
                    <div class="family-member">

                        <div>
                            <label>Complete Name</label>
                            <input type="text" name="family_name[]" placeholder="Full name">
                        </div>

                        <div>
                            <label>Relationship</label>
                            <input type="text" name="family_relation[]" placeholder="e.g. Spouse">
                        </div>

                        <div>
                            <label>Age</label>
                            <input type="number" name="family_age[]" min="0" placeholder="Age">
                        </div>

                        <div>
                            <label>Occupation</label>
                            <input type="text" name="family_occupation[]" placeholder="Occupation">
                        </div>

                        <div>
                            <label>Monthly Salary (₱)</label>
                            <input type="number" name="family_salary[]" min="0" step="0.01" placeholder="0.00">
                        </div>

                        <div>
                            <button type="button" class="remove-family-btn" onclick="removeFamilyMember(this)" disabled>
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>

                    </div>

                    <!-- HOUSEHOLD MEMBER 2 -->
                    <div class="family-member">

                        <div>
                            <label>Complete Name</label>
                            <input type="text" name="family_name[]" placeholder="Full name">
                        </div>

                        <div>
                            <label>Relationship</label>
                            <input type="text" name="family_relation[]" placeholder="e.g. Child">
                        </div>

                        <div>
                            <label>Age</label>
                            <input type="number" name="family_age[]" min="0" placeholder="Age">
                        </div>

                        <div>
                            <label>Occupation</label>
                            <input type="text" name="family_occupation[]" placeholder="Occupation">
                        </div>

                        <div>
                            <label>Monthly Salary (₱)</label>
                            <input type="number" name="family_salary[]" min="0" step="0.01" placeholder="0.00">
                        </div>

                        <div>
                            <button type="button" class="remove-family-btn" onclick="removeFamilyMember(this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>

                    </div>

                    <!-- HOUSEHOLD MEMBER 3 -->
                    <div class="family-member">

                        <div>
                            <label>Complete Name</label>
                            <input type="text" name="family_name[]" placeholder="Full name">
                        </div>

                        <div>
                            <label>Relationship</label>
                            <input type="text" name="family_relation[]" placeholder="e.g. Parent">
                        </div>

                        <div>
                            <label>Age</label>
                            <input type="number" name="family_age[]" min="0" placeholder="Age">
                        </div>

                        <div>
                            <label>Occupation</label>
                            <input type="text" name="family_occupation[]" placeholder="Occupation">
                        </div>

                        <div>
                            <label>Monthly Salary (₱)</label>
                            <input type="number" name="family_salary[]" min="0" step="0.01" placeholder="0.00">
                        </div>

                        <div>
                            <button type="button" class="remove-family-btn" onclick="removeFamilyMember(this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>

                    </div>

                </div>

                <button type="button" class="add-family-btn" id="add-family-btn">
                    <i class="fas fa-plus"></i> ADD HOUSEHOLD MEMBER
                </button>

                <!-- ASSISTANCE TYPE -->
                <div class="form-section-title">Assistance Type</div>

                <div class="analysis-grid">
                    <?php
                    $assistance_categories = [
                        "Medical Assistance",
                        "Cash Assistance"
                    ];

                    foreach ($assistance_categories as $a):
                    ?>
                        <label class="option-item">
                            <input
                                type="radio"
                                name="assistance_type"
                                value="<?php echo htmlspecialchars($a, ENT_QUOTES, 'UTF-8'); ?>"
                                required
                            >
                            <?php echo htmlspecialchars($a); ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="btn-submit">
                    Submit & Register Applicant
                </button>

            </form>
        </div>

        <!-- REQUIREMENTS CHECKLIST -->
        <div class="checklist-card">

            <div class="checklist-header">
                <div>
                    <div style="font-weight:800; font-size:14px;">MEDICAL ASSISTANCE</div>
                    <div style="font-size:11px; opacity:0.9;">REQUIREMENTS LIST</div>
                </div>

                <button
                    onclick="window.open('print_intake_form.php', '_blank')"
                    class="print-btn"
                    style="background:white; color:#3b82f6; border:none; padding:5px 12px; border-radius:4px; font-weight:700; cursor:pointer; font-size:11px;"
                >
                    <i class="fas fa-print"></i> PRINT SHEET
                </button>
            </div>

            <div class="checklist-body">

                <div class="item"><span>• Valid ID ng taong maglalakad/iinterviewhin</span></div>
                <div class="item"><span>• Authorization Letter (if applicable)</span></div>
                <div class="item"><span>• Personal letter to the Senator (for SPAO)</span></div>
                <div class="item"><span>• Brgy. Certificate of Indigency</span></div>

                <div class="cat-title">Para sa Babayarang Hospital Bill:</div>

                <div class="item">
                    <span>• Medical Certificate/Clinical Abstract/Discharge Summary with diagnosis and signature of the Physician (issued within 3 months)</span>
                </div>

                <div class="item">
                    <span>• Hospital Bill or Statement of Account with signature of billing clerk</span>
                </div>

                <div class="item">
                    <span>• Social Case Study Report or Case Summary</span>
                </div>

                <div class="cat-title">Para sa Gamot o Assistive Device:</div>

                <div class="item">
                    <span>• Medical Certificate/Clinical Abstract (issued within 3 months)</span>
                </div>

                <div class="item">
                    <span>• Prescription with date, complete name, license number and signature of Physician</span>
                </div>

                <div style="font-size:10.5px; color:#64748b; padding-left:20px; font-style:italic;">
                    Kung hihigit sa PhP 10,000: Quotation and Social Case Study Report needed
                </div>

                <div style="margin-top:20px; padding:15px; border-radius:8px; background:#fee2e2; border:1px solid #fecaca;">

                    <div style="color:#991b1b; font-weight:800; font-size:11px; margin-bottom:5px;">
                        PAALALA:
                    </div>

                    <div style="font-size:10px; color:#b91c1c;">
                        <strong>NO FIXER:</strong>
                        Ang lahat ng pinansyal na tulong mula sa DSWD ay buong matatanggap ng mga benepisyaryo.

                        <br><br>

                        <strong>FAKE DOCUMENTS:</strong>
                        Submission of fake documents is punishable by law.
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<script>
// ==========================================
// DATE OF BIRTH - AUTOMATIC AGE COMPUTATION
// ==========================================
const dobInput = document.getElementById('dob_input');
const ageField = document.getElementById('age_input');

dobInput.addEventListener('change', function() {
    if (!this.value) return;

    const dob = new Date(this.value + 'T00:00:00');
    const today = new Date();

    let age = today.getFullYear() - dob.getFullYear();
    const monthDifference = today.getMonth() - dob.getMonth();

    if (
        monthDifference < 0 ||
        (monthDifference === 0 && today.getDate() < dob.getDate())
    ) {
        age--;
    }

    ageField.value = age >= 0 ? age : '';
});

// ==========================================
// BARANGAY AUTOCOMPLETE
// ==========================================
const barangays = [
    "Alicia", "Amihan", "Apolonio Samson", "Aurora", "Baesa",
    "Bagbag", "Bagong Lipunan ng Crame", "Bagong Pag-asa",
    "Bagong Silangan", "Bagumbayan", "Bagumbuhay", "Bahay Toro",
    "Balingasa", "Balong Bato", "Batasan Hills", "Bayanihan",
    "Blue Ridge A", "Blue Ridge B", "Botocan", "Bungad",
    "Camp Aguinaldo", "Capri", "Central", "Claro", "Commonwealth",
    "Culiat", "Damar", "Damayan", "Damayang Lagi", "Del Monte",
    "Dioquino Zobel", "Doña Aurora", "Doña Imelda", "Doña Josefa",
    "Don Manuel", "Duyan-duyan", "E. Rodriguez", "East Kamias",
    "Escopa I", "Escopa II", "Escopa III", "Escopa IV",
    "Fairview", "Greater Lagro", "Gulod", "Holy Spirit",
    "Horseshoe", "Immaculate Concepcion", "Kaligayahan",
    "Kalusugan", "Kamuning", "Katipunan", "Kaunlaran",
    "Kristong Hari", "Krus na Ligas", "Laging Handa", "Libis",
    "Lourdes", "Loyola Heights", "Maharlika", "Malaya",
    "Mangga", "Manresa", "Mariana", "Mariblo", "Marilag",
    "Masagana", "Masambong", "Matandang Balara", "Milagrosa",
    "N.S. Amoranto", "Nagkaisang Nayon", "Nayong Kanluran",
    "New Era", "North Fairview", "Novaliches Proper", "Obrero",
    "Old Capitol Site", "Paang Bundok", "Pag-ibig sa Nayon",
    "Paligsahan", "Paltok", "Pansol", "Paraiso",
    "Pasong Putik Proper", "Pasong Tamo", "Payatas", "Phil-Am",
    "Pinagkaisahan", "Pinyahan", "Project 6", "Quirino 2-A",
    "Quirino 2-B", "Quirino 2-C", "Quirino 3-A",
    "Ramon Magsaysay", "Roxas", "Sacred Heart", "Saint Ignatius",
    "Saint Peter", "Salvacion", "San Agustin", "San Antonio",
    "San Bartolome", "San Isidro", "San Isidro Labrador",
    "San Jose", "San Martin de Porres", "San Roque", "San Vicente",
    "Sangandaan", "Santa Cruz", "Santa Lucia", "Santa Monica",
    "Santa Teresita", "Santol", "Santo Cristo", "Santo Domingo",
    "Santo Niño", "Sauyo", "Sienna", "Sikatuna Village",
    "Silangan", "Socorro", "South Triangle", "Tagumpay",
    "Talayan", "Talipapa", "Tandang Sora", "Tatalon",
    "Teachers Village East", "Teachers Village West",
    "U.P. Campus", "U.P. Village", "Ugong Norte",
    "Unang Sigaw", "Valencia", "Vasra", "Veterans Village",
    "Villa Maria Clara", "West Kamias", "West Triangle",
    "White Plains"
];

const barangayInput = document.getElementById('barangay_search');
const suggestionBox = document.getElementById('brgy_suggestions');

barangayInput.addEventListener('input', function() {
    const value = this.value.toLowerCase();

    suggestionBox.innerHTML = '';

    if (!value) {
        suggestionBox.style.display = 'none';
        return;
    }

    const matches = barangays
        .filter(b => b.toLowerCase().includes(value))
        .slice(0, 5);

    if (matches.length > 0) {
        matches.forEach(name => {
            const div = document.createElement('div');

            div.textContent = name;
            div.style.padding = '10px';
            div.style.cursor = 'pointer';
            div.style.fontSize = '13px';

            div.addEventListener('mouseover', () => {
                div.style.background = '#f1f5f9';
            });

            div.addEventListener('mouseout', () => {
                div.style.background = 'white';
            });

            div.addEventListener('click', () => {
                barangayInput.value = name;
                suggestionBox.style.display = 'none';
            });

            suggestionBox.appendChild(div);
        });

        suggestionBox.style.display = 'block';
    } else {
        suggestionBox.style.display = 'none';
    }
});

document.addEventListener('click', function(e) {
    if (
        e.target !== barangayInput &&
        !suggestionBox.contains(e.target)
    ) {
        suggestionBox.style.display = 'none';
    }
});

// ==========================================
// FAMILY COMPOSITION - ADD HOUSEHOLD MEMBER
// ==========================================
const familyContainer = document.getElementById('family-container');
const addFamilyButton = document.getElementById('add-family-btn');

function updateFamilyRemoveButtons() {
    const members = familyContainer.querySelectorAll('.family-member');

    members.forEach(member => {
        const removeButton = member.querySelector('.remove-family-btn');

        removeButton.disabled = members.length === 1;
    });
}

function addFamilyMember() {
    const member = document.createElement('div');

    member.className = 'family-member';

    member.innerHTML = `
        <div>
            <label>Complete Name</label>
            <input type="text" name="family_name[]" placeholder="Full name">
        </div>

        <div>
            <label>Relationship</label>
            <input type="text" name="family_relation[]" placeholder="e.g. Spouse">
        </div>

        <div>
            <label>Age</label>
            <input type="number" name="family_age[]" min="0" placeholder="Age">
        </div>

        <div>
            <label>Occupation</label>
            <input type="text" name="family_occupation[]" placeholder="Occupation">
        </div>

        <div>
            <label>Monthly Salary (₱)</label>
            <input type="number" name="family_salary[]" min="0" step="0.01" placeholder="0.00">
        </div>

        <div>
            <button type="button" class="remove-family-btn" onclick="removeFamilyMember(this)">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;

    familyContainer.appendChild(member);
    updateFamilyRemoveButtons();
}

function removeFamilyMember(button) {
    const members = familyContainer.querySelectorAll('.family-member');

    if (members.length <= 1) {
        return;
    }

    const member = button.closest('.family-member');

    if (member) {
        member.remove();
    }

    updateFamilyRemoveButtons();
}

addFamilyButton.addEventListener('click', addFamilyMember);

updateFamilyRemoveButtons();

// ==========================================
// EMAIL VALIDATION
// ==========================================
const emailInput = document.getElementById('email_input');
const emailError = document.getElementById('email_error');

const emailPattern = /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/;

emailInput.addEventListener('input', function() {
    if (this.value.trim() === '') {
        emailError.style.display = 'none';
        this.style.borderColor = '#d2d6da';
        return;
    }

    const valid = emailPattern.test(this.value.trim());

    emailError.style.display = valid ? 'none' : 'block';
    this.style.borderColor = valid ? '#10b981' : '#ef4444';
});

// ==========================================
// CONTACT NUMBER VALIDATION
// ==========================================
const contactInput = document.getElementById('contact_input');
const contactError = document.getElementById('contact_error');

contactInput.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '').slice(0, 11);

    const valid = this.value.length === 11;

    contactError.style.display =
        (this.value.length > 0 && !valid) ? 'block' : 'none';

    this.style.borderColor =
        this.value.length === 0
            ? '#d2d6da'
            : (valid ? '#10b981' : '#ef4444');
});

contactInput.addEventListener('keypress', function(e) {
    if (!/[0-9]/.test(e.key)) {
        e.preventDefault();
    }
});

// ==========================================
// SUBCATEGORY OTHERS TOGGLE
// ==========================================
const subcatRadios = document.getElementsByName('client_subcategory');
const othersWrap = document.getElementById('subcat_others_wrap');
const othersInput = document.getElementById('subcat_others_input');

subcatRadios.forEach(radio => {
    radio.addEventListener('change', function() {
        if (this.value === 'Others specify') {
            othersWrap.style.display = 'flex';
            othersInput.required = true;
        } else {
            othersWrap.style.display = 'none';
            othersInput.required = false;
            othersInput.value = '';
        }
    });
});

// ==========================================
// FORM SUBMISSION VALIDATION
// ==========================================
document.getElementById('applicantForm').addEventListener('submit', function(e) {
    let hasError = false;

    if (
        emailInput.value.trim() !== '' &&
        !emailPattern.test(emailInput.value.trim())
    ) {
        emailError.style.display = 'block';
        emailInput.style.borderColor = '#ef4444';
        hasError = true;
    }

    if (contactInput.value.length !== 11) {
        contactError.style.display = 'block';
        contactInput.style.borderColor = '#ef4444';
        hasError = true;
    }

    if (hasError) {
        e.preventDefault();
        alert('Please fix the highlighted fields before submitting.');
    }
});
</script>

</body>
</html>
