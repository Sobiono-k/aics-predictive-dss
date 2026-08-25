<?php
//sidebar.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_page = basename($_SERVER['PHP_SELF']);

if (!isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

$user_role = $_SESSION['role'];

require_once(__DIR__ . '/../db.php');

?>
<div class="sidebar">
    <div class="sidebar-header" style="padding: 30px 20px;">
        <div style="margin-bottom: -10px;">
            <img src="../images/dswdlogo.png" alt="QC Logo" style="width: 200px; height: auto; filter: drop-shadow(0 4px 6px rgba(43, 42, 42, 0.61));">
        </div>
        <div style="font-size: 0.65rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 4px;">Batasan Hills - Main Office</div>
    </div>

    <nav style="display: flex; flex-direction: column; flex-grow: 1; margin-top: 0px;">

        <?php if ($user_role === 'Admin'): ?>
        <a href="index.php" class="<?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">
            <i class="fas fa-chart-pie" style="margin-right: 12px; width: 20px;"></i> Dashboard
        </a>
        <?php endif; ?>

        <a href="new_applicant.php" class="<?php echo ($current_page == 'new_applicant.php') ? 'active' : ''; ?>">
            <i class="fas fa-user-plus" style="margin-right: 12px; width: 20px;"></i> New Applicant
        </a>

        <a href="lookup_applicant.php" class="<?php echo ($current_page == 'lookup_applicant.php') ? 'active' : ''; ?>"
           style="display: flex; align-items: center; justify-content: space-between;">
            <span>
                <i class="fas fa-clock" style="margin-right: 8px; width: 20px;"></i> Pending Applicants
            </span>
            <?php if ($pending_count > 0): ?>
            <span style="
                background: #ef4444;
                color: #fff;
                font-size: 10px;
                font-weight: 800;
                min-width: 20px;
                height: 20px;
                border-radius: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 0 6px;
                animation: pulse-badge 2s infinite;
            "><?php echo $pending_count; ?></span>
            <?php endif; ?>
        </a>

        <?php if ($user_role === 'Admin'): ?>
        <a href="forecast_analysis.php" class="<?php echo ($current_page == 'forecast_analysis.php') ? 'active' : ''; ?>">
            <i class="fas fa-microchip" style="margin-right: 12px; width: 20px;"></i> Forecast Analysis
        </a>
        <?php endif; ?>

        <a href="records.php" class="<?php echo ($current_page == 'records.php') ? 'active' : ''; ?>">
            <i class="fas fa-folder-open" style="margin-right: 12px; width: 20px;"></i> Records
        </a>

        <?php if ($user_role === 'Admin'): ?>
        <a href="reports.php" class="<?php echo ($current_page == 'reports.php') ? 'active' : ''; ?>">
            <i class="fas fa-file-invoice" style="margin-right: 12px; width: 20px;"></i> Reports
        </a>
        <?php endif; ?>
    </nav>

    <div style="padding: 20px; border-top: 1px solid rgba(255,255,255,0.05); background: rgba(0,0,0,0.1);">
        <div style="font-size: 10px; color: #64748b; margin-bottom: 8px; padding-left: 5px; text-transform: uppercase;">
            Current Session
        </div>
        <div style="display: flex; align-items: center; gap: 10px; padding-left: 5px; margin-bottom: 15px;">
            <div style="width: 8px; height: 8px; background: #10b981; border-radius: 50%; box-shadow: 0 0 8px #10b981;"></div>
            <span style="color: #fff; font-weight: 600; font-size: 13px;"><?php echo htmlspecialchars($user_role); ?></span>
        </div>

        <a href="login.php" style="padding: 12px; color: #f87171; border-radius: 8px; transition: 0.3s; display: flex; align-items: center; text-decoration: none; font-size: 14px; font-weight: 600;">
            <i class="fas fa-sign-out-alt" style="margin-right: 12px;"></i> Logout
        </a>
    </div>
</div>

<style>
@keyframes pulse-badge {
    0%, 100% { transform: scale(1);   opacity: 1; }
    50%       { transform: scale(1.15); opacity: 0.85; }
}
</style>