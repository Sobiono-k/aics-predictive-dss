<?php
// lookup_applicant.php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'auth.php';

require_once(__DIR__ . '/../db.php');

$msg = null;

// --- REMOVE APPLICANT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_code'])) {
    $code = $conn->real_escape_string($_POST['delete_code']);

    // optional: check first if exists
    $check = $conn->query("SELECT id FROM pending_applications WHERE application_code = '$code' AND is_claimed = 0");

    if ($check && $check->num_rows > 0) {
        $conn->query("DELETE FROM pending_applications WHERE application_code = '$code' AND is_claimed = 0");
        header("Location: lookup_applicant.php?msg=deleted");
        exit();
    } else {
        $msg = 'delete_failed';
    }
}

// --- CONFIRM & MOVE TO MAIN RECORDS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_code'])) {
    $code = $conn->real_escape_string($_POST['confirm_code']);

    $res = $conn->query("SELECT * FROM pending_applications WHERE application_code = '$code' AND is_claimed = 0");
    $data = $res->fetch_assoc();

    if ($data) {
        $conn->query("INSERT INTO aics_sample_data 
            (request_date, fname, mname, lname, birth_date, sex, civil_status,
             barangay, medical_cause, assistance_type, client_category, client_subcategory, status, application_code)
            VALUES (
                NOW(),
                '{$data['fname']}', '{$data['mname']}', '{$data['lname']}',
                '{$data['birth_date']}', '{$data['sex']}', '{$data['civil_status']}',
                '{$data['barangay']}', '{$data['medical_cause']}', '{$data['assistance_type']}',
                '{$data['client_category']}', '{$data['client_subcategory']}',
                'Pending', '$code'
            )");
        $newID = $conn->insert_id;
        $conn->query("UPDATE aics_sample_data SET id_number = 'QC-$newID' WHERE id = $newID");
        $conn->query("UPDATE pending_applications SET is_claimed = 1 WHERE application_code = '$code'");
        header("Location: records.php?msg=success&new_id=$newID");
        exit();
    } else {
        $msg = 'not_found';
    }
}

// --- SEARCH BY CODE ---
$found_applicant = null;
$search_code = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lookup_code'])) {
    $search_code = trim($conn->real_escape_string($_POST['lookup_code']));
    $res = $conn->query("SELECT * FROM pending_applications WHERE application_code = '$search_code' AND is_claimed = 0");
    $found_applicant = $res->fetch_assoc();
    if (!$found_applicant) $msg = 'code_not_found';
}

// --- FETCH ALL PENDING (for the table) ---
$search_name = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$where = "is_claimed = 0";
if (!empty($search_name)) {
    $where .= " AND (fname LIKE '%$search_name%' OR lname LIKE '%$search_name%' OR application_code LIKE '%$search_name%' OR medical_cause LIKE '%$search_name%')";
}

$limit = 20;
$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$total_res   = $conn->query("SELECT COUNT(*) AS cnt FROM pending_applications WHERE $where");
$total_count = $total_res->fetch_assoc()['cnt'];
$total_pages = ceil($total_count / $limit);

$pending_res = $conn->query("SELECT * FROM pending_applications WHERE $where ORDER BY submitted_at DESC LIMIT $offset, $limit");
$pending_rows = [];
while ($r = $pending_res->fetch_assoc()) $pending_rows[] = $r;

// ── Flag potential duplicates in the displayed list ──
$name_counts = [];
foreach ($pending_rows as $r) {
    $key = strtolower(trim($r['fname'] . $r['lname'] . $r['birth_date']));
    $name_counts[$key] = ($name_counts[$key] ?? 0) + 1;
}

function pgUrl($p) {
    $params = $_GET; $params['page'] = $p;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Applicants</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { overflow-y: scroll; }
        :root { --dswd-dark: #2c3e50; --sidebar-bg: #1e293b; --bg-color: #f8fafc; --card-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); --sidebar-width: 260px; }
        body { font-family: 'Inter', sans-serif; margin: 0; background: var(--bg-color); display: flex; color: #334155; }
        .sidebar { width: var(--sidebar-width); height: 100vh; background: var(--sidebar-bg); position: fixed; left: 0; top: 0; color: #fff; display: flex; flex-direction: column; z-index: 1000; }
        .sidebar-header { padding: 30px 20px; text-align: center; background: rgba(0,0,0,0.2); }
        .sidebar a {
            padding: 15px 25px;
            text-decoration: none;
            color: #94a3b8;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            border-left: 4px solid transparent; 
        }

        .sidebar a:hover, .sidebar a.active {
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            border-left: 4px solid #3b82f6; 
        }
        .main { margin-left: 260px; padding: 40px; width: calc(100% - 260px); min-height: 100vh; }

        /* ── Stat cards ── */
        .stat-card {
            background: #fff; border-radius: 12px; padding: 20px 24px;
            border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 15px;
        }
        .stat-icon {
            width: 46px; height: 46px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center; font-size: 20px;
        }

        /* ── Table card ── */
        .table-container {
            background: #fff; border-radius: 12px;
            box-shadow: var(--card-shadow); overflow: hidden; border: 1px solid #e2e8f0;
        }
        .filter-header { padding: 20px; background: #fff; border-bottom: 1px solid #f1f5f9; }
        .controls-row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .search-wrapper { position: relative; flex: 2; min-width: 220px; }
        .search-wrapper i { position: absolute; left: 12px; top: 11px; color: #94a3b8; }
        .input-field {
            padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px;
            font-size: 14px; background: #fff; font-family: inherit; width: 100%;
        }
        .search-box { padding-left: 35px; }

        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left; padding: 14px 20px;
            background: #f8fafc; color: #64748b;
            font-size: 11px; text-transform: uppercase;
            border-bottom: 1px solid #e2e8f0; font-weight: 700; letter-spacing: .5px;
        }
        td { padding: 15px 20px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #fafbfc; }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; background: #eff6ff; color: #1d4ed8; }
        .code-badge {
            font-family: 'Courier New', monospace; font-size: 12px; font-weight: 700;
            background: #f1f5f9; color: #334155; padding: 4px 10px; border-radius: 6px;
            border: 1px solid #e2e8f0; letter-spacing: .5px;
        }

        /* Action buttons */
        .action-btn {
            padding: 6px 10px; border-radius: 6px; border: 1px solid #e2e8f0;
            color: #64748b; transition: .2s; cursor: pointer;
            text-decoration: none; font-size: 12px; background: #fff;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .btn-confirm { color: #10b981; border-color: #a7f3d0; }
        .btn-confirm:hover { background: #f0fdf4; }
        .btn-view-pending { color: #6366f1; border-color: #c7d2fe; }
        .btn-view-pending:hover { background: #eef2ff; }

        /* Pagination */
        .pagination-footer {
            padding: 14px 20px; display: flex; justify-content: space-between;
            align-items: center; background: #f8fafc; border-top: 1px solid #e2e8f0;
        }
        .pagination-btns { display: flex; gap: 4px; }
        .pg-btn {
            padding: 6px 12px; border: 1px solid #e2e8f0; background: #fff;
            border-radius: 6px; text-decoration: none; color: #1e293b;
            font-size: 13px; font-weight: 600; transition: .1s; min-width: 32px; text-align: center;
        }
        .pg-btn.active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
        .pg-btn.disabled { color: #cbd5e1; pointer-events: none; background: #f8fafc; }

        /* Modals */
        .modal-overlay {
            display: none; position: fixed; z-index: 9999;
            left: 0; top: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); backdrop-filter: blur(4px);
        }
        .modal-box {
            background: #fff; margin: 6% auto; padding: 32px;
            border-radius: 12px; width: 520px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15);
            border: 1px solid #e2e8f0; position: relative;
        }
        .modal-close {
            position: absolute; right: 18px; top: 14px;
            font-size: 22px; cursor: pointer; color: #94a3b8; background: none; border: none;
        }
        .modal-close:hover { color: #334155; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
        .info-item label { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; display: block; margin-bottom: 3px; }
        .info-item p { font-size: 14px; color: #1e293b; font-weight: 500; margin: 0; }

        /* Lookup input box */
        .lookup-box {
            background: #fff; border-radius: 12px; padding: 24px 28px;
            border: 1px solid #e2e8f0; margin-bottom: 24px;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .lookup-box input {
            flex: 1; min-width: 240px; padding: 11px 16px;
            border: 2px solid #e2e8f0; border-radius: 8px;
            font-size: 15px; font-family: 'Courier New', monospace;
            font-weight: 700; letter-spacing: 1px; outline: none; transition: .2s;
        }
        .lookup-box input:focus { border-color: #3b82f6; }
        .btn-search {
            padding: 11px 22px; background: #003893; color: #fff; border: none;
            border-radius: 8px; font-weight: 700; font-size: 14px;
            cursor: pointer; transition: .2s; display: flex; align-items: center; gap: 8px;
        }
        .btn-search:hover { background: #002a6d; }

        /* Toast */
        .toast-msg {
            position: fixed; top: 20px; right: 20px;
            background: #10b981; color: white; padding: 14px 22px;
            border-radius: 8px; z-index: 10000; font-weight: 600;
            display: flex; align-items: center; gap: 8px;
        }
        .toast-msg.error { background: #ef4444; }

        /* Empty state */
        .empty-state { text-align: center; padding: 60px 20px; color: #94a3b8; }
        .empty-state i { font-size: 48px; margin-bottom: 16px; display: block; }
        .empty-state p { font-size: 15px; }
    </style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main">

    <?php if ($msg === 'code_not_found'): ?>
    <div class="toast-msg error" id="toast"><i class="fas fa-times-circle"></i> Code not found or already claimed.</div>
    <script>setTimeout(() => document.getElementById('toast').style.display = 'none', 4000);</script>
    <?php endif; ?>

    <!-- Page Header -->
    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom: 28px;">
        <div>
            <h1 style="margin:0; font-size:28px; color:var(--dswd-dark);">Pending Applicants</h1>
            <p style="color:#64748b; margin-top:5px;">Online QR submissions awaiting counter confirmation</p>
        </div>
        <button onclick="document.getElementById('qrModal').style.display='block'"
                style="display:flex; align-items:center; gap:10px; padding:12px 22px; background:#003893; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; transition:.2s;"
                onmouseover="this.style.background='#002a6d'" onmouseout="this.style.background='#003893'">
            <i class="fas fa-qrcode" style="font-size:18px;"></i> Show QR Code
        </button>
    </div>

    <!-- Stat Card -->
    <div style="display:flex; gap:16px; margin-bottom:24px;">
        <div class="stat-card" style="min-width:220px;">
            <div class="stat-icon" style="background:#fef3c7; color:#d97706;">
                <i class="fas fa-clock"></i>
            </div>
            <div>
                <div style="font-size:12px; color:#94a3b8; text-transform:uppercase; font-weight:700;">Awaiting Confirmation</div>
                <div style="font-size:26px; font-weight:800; color:#1e293b;"><?php echo number_format($total_count); ?></div>
            </div>
        </div>
    </div>

    <!-- Code Lookup Bar -->
    <form method="POST">
        <div class="lookup-box">
            <i class="fas fa-qrcode" style="font-size:22px; color:#003893;"></i>
            <div style="flex:1;">
                <div style="font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; margin-bottom:6px;">
                    Enter or Scan Applicant Code
                </div>
                <input type="text" name="lookup_code" id="lookupCodeInput"
                    placeholder="AICS-XXXXXXXX-XXXX"
                    value="<?php echo htmlspecialchars($search_code); ?>"
                    autocomplete="off" autofocus
                    maxlength="18"
                    style="text-transform:uppercase; letter-spacing:1px;">
            </div>
            <button type="submit" class="btn-search">
                <i class="fas fa-search"></i> Search Code
            </button>
        </div>
    </form>

    <!-- Pending Records Table -->
    <form method="GET">
        <div class="table-container">
            <div class="filter-header">
                <div class="controls-row">
                    <div class="search-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" class="input-field search-box"
                               placeholder="Search by name, code, or medical cause..."
                               value="<?php echo htmlspecialchars($search_name); ?>">
                    </div>
                    <button type="submit" class="btn-search" style="white-space:nowrap;">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <?php if (!empty($search_name)): ?>
                    <a href="lookup_applicant.php" style="padding:11px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; text-decoration:none; color:#64748b; font-size:13px; font-weight:600; display:flex; align-items:center; gap:6px;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:18%;">Reference Code</th>
                        <th style="width:22%;">Full Name</th>
                        <th style="width:16%;">Date of Birth</th>
                        <th style="width:15%;">Barangay</th>
                        <th style="width:15%;">Medical Cause</th>
                        <th style="width:14%;">Submitted</th>
                        <th style="width:10%; text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($pending_rows)): ?>
                        <?php foreach ($pending_rows as $row):
                            $fullname = trim($row['fname'] . ' ' . $row['mname'] . ' ' . $row['lname']);
                            $bdate = (!empty($row['birth_date']) && $row['birth_date'] !== '0000-00-00')
                                ? date("M d, Y", strtotime($row['birth_date'])) : '—';
                            $submitted = date("M d, Y g:i A", strtotime($row['submitted_at']));
                            $dup_key    = strtolower(trim($row['fname'] . $row['lname'] . $row['birth_date']));
                            $is_dup     = ($name_counts[$dup_key] ?? 0) > 1;    
                        ?>
                        <tr>
                            <td><span class="code-badge"><?php echo htmlspecialchars($row['application_code']); ?></span></td>
                            <td style="font-weight:600; color:#1e293b;">
                                <?php echo htmlspecialchars(ucwords(strtolower($fullname))); ?>
                                <?php if ($is_dup): ?>
                                    <span style="margin-left:6px;padding:2px 7px;background:#fef2f2;border:1px solid #fca5a5;
                                                color:#b91c1c;font-size:10px;font-weight:800;border-radius:10px;
                                                text-transform:uppercase;letter-spacing:.5px;">
                                        <i class="fas fa-exclamation-circle"></i> Duplicate
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600; color:#1e293b;"><?php echo htmlspecialchars(ucwords(strtolower($fullname))); ?></td>
                            
                            <td><?php echo $bdate; ?></td>
                            <td><?php echo htmlspecialchars($row['barangay'] ?? '—'); ?></td>
                            <td><span class="badge"><?php echo htmlspecialchars($row['medical_cause']); ?></span></td>
                            <td style="font-size:12px; color:#64748b;"><?php echo $submitted; ?></td>
                            <td style="text-align:center;">
                                <a href="view_pending_profile.php?id=<?php echo $row['id']; ?>"
                                   target="_blank"
                                   class="action-btn btn-view-pending"
                                   title="View & Print Full Profile">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button type="button" class="action-btn btn-confirm"
                                        onclick="openConfirmModal('<?php echo htmlspecialchars($row['application_code']); ?>', '<?php echo htmlspecialchars(ucwords(strtolower($fullname))); ?>')"
                                        title="Confirm & Save to Records">
                                    <i class="fas fa-check"></i>
                                </button>
                                <button type="button" class="action-btn"
                                        style="color:#ef4444; border-color:#fecaca;"
                                        onclick="openDeleteModal('<?php echo htmlspecialchars($row['application_code']); ?>', '<?php echo htmlspecialchars(ucwords(strtolower($fullname))); ?>')"
                                        title="Remove Applicant">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <p><?php echo !empty($search_name) ? 'No results found for your search.' : 'No pending online applicants at the moment.'; ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
            <div class="pagination-footer">
                <div style="font-size:13px; color:#64748b;">
                    <?php echo ($offset + 1) . ' – ' . min($offset + $limit, $total_count) . ' of ' . $total_count; ?>
                </div>
                <div class="pagination-btns">
                    <a href="<?php echo pgUrl($page - 1); ?>" class="pg-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <a href="<?php echo pgUrl($page + 1); ?>" class="pg-btn <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ═══ VIEW DETAILS MODAL ═══ -->
<div id="viewModal" class="modal-overlay">
    <div class="modal-box">
        <button class="modal-close" onclick="document.getElementById('viewModal').style.display='none'">&times;</button>
        <h2 style="margin:0; color:var(--dswd-dark); font-size:18px;">
            <i class="fas fa-user-circle" style="margin-right:8px; color:#3b82f6;"></i>Applicant Details
        </h2>
        <hr style="border:0; border-top:1px solid #e2e8f0; margin:14px 0;">
        <div id="viewContent" style="color:#64748b; font-size:14px;">Loading...</div>
    </div>
</div>

<!-- ═══ CONFIRM MODAL ═══ -->
<div id="confirmModal" class="modal-overlay">
    <div class="modal-box" style="width:400px; text-align:center;">
        <button class="modal-close" onclick="document.getElementById('confirmModal').style.display='none'">&times;</button>
        <div style="width:60px;height:60px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:26px;color:#16a34a;">
            <i class="fas fa-user-check"></i>
        </div>
        <h3 style="margin:0 0 6px; color:#1e293b;">Confirm Applicant</h3>
        <p style="color:#64748b; font-size:13px; margin-bottom:20px;">
            This will move <strong id="confirmName"></strong> to the official records with status <em>Pending</em>.
        </p>
        <form method="POST">
            <input type="hidden" name="confirm_code" id="confirmCodeInput">
            <div style="display:flex; gap:10px; justify-content:center;">
                <button type="button" onclick="document.getElementById('confirmModal').style.display='none'"
                        style="padding:10px 20px; border:1px solid #e2e8f0; background:#fff; border-radius:8px; font-weight:600; cursor:pointer; color:#334155;">
                    Cancel
                </button>
                <button type="submit"
                        style="padding:10px 24px; background:#003893; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-check"></i> Confirm & Save
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ DELETE MODAL ═══ -->
<!-- ═══ DELETE MODAL ═══ -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-box" style="width:400px; text-align:center;">
        <button class="modal-close" onclick="document.getElementById('deleteModal').style.display='none'">&times;</button>

        <div style="width:60px;height:60px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:26px;color:#ef4444;">
            <i class="fas fa-trash"></i>
        </div>

        <h3 style="margin:0 0 6px; color:#1e293b;">Remove Applicant</h3>
        <p style="color:#64748b; font-size:13px; margin-bottom:20px;">
            Are you sure you want to delete <strong id="deleteName"></strong>? This action cannot be undone.
        </p>

        <form method="POST">
            <input type="hidden" name="delete_code" id="deleteCodeInput">

            <div style="display:flex; gap:10px; justify-content:center;">
                <button type="button"
                        onclick="document.getElementById('deleteModal').style.display='none'"
                        style="padding:10px 20px; border:1px solid #e2e8f0; background:#fff; border-radius:8px; font-weight:600; cursor:pointer; color:#334155;">
                    Cancel
                </button>

                <button type="submit"
                        style="padding:10px 24px; background:#ef4444; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer;">
                    Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// View details modal — fetches from pending_applications via a simple inline loader
function openViewModal(id) {
    const modal = document.getElementById('viewModal');
    const content = document.getElementById('viewContent');
    modal.style.display = 'block';
    content.innerHTML = '<p style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;color:#94a3b8;"></i></p>';

    fetch('fetch_pending_details.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + id
    })
    .then(r => r.text())
    .then(html => content.innerHTML = html)
    .catch(() => content.innerHTML = '<p style="color:#ef4444;text-align:center;">Failed to load details.</p>');
}

function openConfirmModal(code, name) {
    document.getElementById('confirmCodeInput').value = code;
    document.getElementById('confirmName').textContent = name;
    document.getElementById('confirmModal').style.display = 'block';
}

function openDeleteModal(code, name) {
    document.getElementById('deleteCodeInput').value = code;
    document.getElementById('deleteName').textContent = name;
    document.getElementById('deleteModal').style.display = 'block';
}

// Close modals on backdrop click
window.onclick = function(e) {
    ['viewModal','confirmModal','qrModal'].forEach(id => {
        const m = document.getElementById(id);
        if (e.target === m) m.style.display = 'none';
    });
};

function printQR() {
    const w = window.open('', '_blank');
    const qrSrc = document.getElementById('qrImg').src;
    const url   = document.getElementById('qrUrlText').textContent;
    w.document.write(`
        <html><head><title>AICS QR Code</title>
        <style>
            body { font-family: 'Segoe UI', sans-serif; text-align: center; padding: 40px; }
            h2 { color: #003893; margin-bottom: 4px; }
            p  { color: #64748b; font-size: 13px; margin-bottom: 20px; }
            img { border: 3px solid #003893; padding: 10px; border-radius: 8px; }
            .url { margin-top: 16px; font-size: 11px; color: #94a3b8; word-break: break-all; }
        </style></head>
        <body onload="window.print()">
            <h2>AICS Online Application</h2>
            <p>I-scan para mag-apply — Batasan Hills Branch</p>
            <img src="${qrSrc}" width="260" height="260"><br>
            <div class="url">${url}</div>
        </body></html>`);
    w.document.close();
}
</script>

<!-- ═══ QR CODE MODAL ═══ -->
<?php
$form_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/public_form.php";
$qr_api   = "https://api.qrserver.com/v1/create-qr-code/?size=280x280&margin=10&color=003893&bgcolor=ffffff&data=" . urlencode($form_url);
?>
<div id="qrModal" class="modal-overlay">
    <div class="modal-box" style="width:380px; text-align:center;">
        <button class="modal-close" onclick="document.getElementById('qrModal').style.display='none'">&times;</button>

        <div style="width:52px;height:52px;background:#eff6ff;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:24px;color:#003893;">
            <i class="fas fa-qrcode"></i>
        </div>
        <h3 style="margin:0 0 4px; color:#1e293b; font-size:17px;">Application QR Code</h3>
        <p style="color:#64748b; font-size:12px; margin-bottom:20px;">I-scan para mapunta sa online form</p>

        <!-- QR Image -->
        <div style="display:inline-block; border:3px solid #003893; border-radius:10px; padding:10px; background:#fff; box-shadow:4px 4px 0 #003893; margin-bottom:18px;">
            <img id="qrImg" src="<?php echo $qr_api; ?>" width="200" height="200" alt="QR Code" style="display:block;">
        </div>

        <!-- URL display -->
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; margin-bottom:18px; word-break:break-all;">
            <div style="font-size:10px; font-weight:800; color:#94a3b8; text-transform:uppercase; margin-bottom:3px;">Form URL</div>
            <span id="qrUrlText" style="font-size:11px; color:#334155; font-family:'Courier New',monospace;"><?php echo htmlspecialchars($form_url); ?></span>
        </div>

        <!-- Buttons -->
        <div style="display:flex; gap:10px; justify-content:center;">
            <button onclick="navigator.clipboard.writeText('<?php echo addslashes($form_url); ?>').then(()=>alert('Link copied!'))"
                    style="padding:10px 18px; border:1px solid #e2e8f0; background:#fff; border-radius:8px; font-weight:600; cursor:pointer; color:#334155; font-size:13px; display:flex; align-items:center; gap:6px;">
                <i class="fas fa-copy"></i> Copy Link
            </button>
            <button onclick="printQR()"
                    style="padding:10px 18px; background:#003893; color:#fff; border:none; border-radius:8px; font-weight:700; cursor:pointer; font-size:13px; display:flex; align-items:center; gap:6px;">
                <i class="fas fa-print"></i> Print QR
            </button>
        </div>

        <p style="font-size:11px; color:#94a3b8; margin-top:14px; margin-bottom:0;">
            <i class="fas fa-info-circle"></i>
            Make sure the URL uses your server's IP, not localhost, para ma-scan ng phone.
        </p>
    </div>
</div>

<script>
document.getElementById('lookupCodeInput').addEventListener('input', function () {
    let raw = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    let out = '';

    // prefix: AICS
    if (raw.length > 0)  out = raw.slice(0, Math.min(4, raw.length));
    if (raw.length >= 4) out = 'AICS-' + raw.slice(4, Math.min(12, raw.length));
    if (raw.length >= 12) out = 'AICS-' + raw.slice(4, 12) + '-' + raw.slice(12, 16);

    // Keep cursor from jumping
    const pos = this.selectionStart;
    this.value = out;
    try { this.setSelectionRange(pos, pos); } catch(e) {}
});
</script>

</body>
</html>