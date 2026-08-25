<?php
// generate_qr.php
// This page generates a printable QR poster for the AICS public application form.
// Place this file inside your /aics/ folder alongside your other PHP files.

require_once 'auth.php'; // Only logged-in Admin/Staff can access this generator

// -------------------------------------------------------
// CONFIGURATION — Change this URL to your actual server
// -------------------------------------------------------
$form_url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/public_form.php";

// Build the QR code image URL using a free API (no library needed)
$qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=400x400&margin=10&color=003893&bgcolor=ffffff&data=" . urlencode($form_url);

$current_date = date("F d, Y");
$branch = "Batasan Hills Branch";
?>
<!DOCTYPE html>
<html lang="fil">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AICS QR Code Poster — <?php echo $branch; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=Source+Serif+4:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy:   #003893;
            --gold:   #c8a94a;
            --red:    #ce1126;
            --light:  #f5f0e8;
            --white:  #ffffff;
            --ink:    #1a1a2e;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Source Serif 4', Georgia, serif;
            background: #d6cfc0;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 40px 20px;
            min-height: 100vh;
        }

        /* ── Screen-only controls ── */
        .controls {
            display: flex;
            gap: 12px;
            margin-bottom: 30px;
        }

        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 4px;
            font-family: 'Source Serif 4', serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            letter-spacing: 0.5px;
            transition: .2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-print  { background: var(--navy); color: var(--white); }
        .btn-print:hover  { background: #002a6d; }
        .btn-copy   { background: var(--gold);  color: var(--ink);  }
        .btn-copy:hover   { background: #b8992e; }

        .url-display {
            background: var(--white);
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 8px 16px;
            font-size: 12px;
            color: #555;
            margin-bottom: 20px;
            max-width: 640px;
            text-align: center;
            word-break: break-all;
        }

        /* ── POSTER ── */
        .poster {
            width: 640px;
            background: var(--light);
            border: 3px solid var(--navy);
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            position: relative;
            overflow: hidden;
        }

        /* Decorative corner flourishes */
        .poster::before,
        .poster::after {
            content: '';
            position: absolute;
            width: 120px;
            height: 120px;
            border: 3px solid var(--gold);
            opacity: 0.5;
            pointer-events: none;
        }
        .poster::before { top: 12px; left: 12px; border-right: none; border-bottom: none; }
        .poster::after  { bottom: 12px; right: 12px; border-left: none; border-top: none; }

        /* Header stripe */
        .poster-header {
            background: var(--navy);
            padding: 28px 36px 22px;
            text-align: center;
            position: relative;
        }

        .poster-header::after {
            content: '';
            position: absolute;
            bottom: -1px; left: 0; right: 0;
            height: 6px;
            background: repeating-linear-gradient(90deg, var(--gold) 0, var(--gold) 20px, var(--red) 20px, var(--red) 40px);
        }

        .header-logos {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            margin-bottom: 14px;
        }

        .gov-seal {
            width: 64px; height: 64px;
            background: var(--white);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }

        .header-title {
            color: var(--white);
        }

        .header-title .agency {
            font-family: 'Playfair Display', serif;
            font-size: 13px;
            font-weight: 400;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 4px;
        }

        .header-title h1 {
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            font-weight: 900;
            line-height: 1.1;
            color: var(--white);
        }

        .header-title .branch-tag {
            font-size: 11px;
            color: rgba(255,255,255,0.65);
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 6px;
        }

        /* Main content */
        .poster-body {
            padding: 36px 48px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .instruction-headline {
            font-family: 'Playfair Display', serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--navy);
            text-align: center;
            margin-bottom: 6px;
        }

        .instruction-sub {
            font-size: 13px;
            color: #666;
            text-align: center;
            margin-bottom: 28px;
            letter-spacing: 0.3px;
        }

        /* QR Frame */
        .qr-frame {
            background: var(--white);
            border: 2px solid var(--navy);
            padding: 16px;
            position: relative;
            margin-bottom: 28px;
            box-shadow: 6px 6px 0 var(--navy);
        }

        .qr-frame img {
            display: block;
            width: 220px;
            height: 220px;
        }

        .qr-label {
            position: absolute;
            bottom: -14px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--gold);
            color: var(--ink);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            padding: 3px 14px;
            white-space: nowrap;
        }

        /* Steps */
        .steps-title {
            font-family: 'Playfair Display', serif;
            font-size: 13px;
            font-weight: 700;
            color: var(--navy);
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .steps-title::before, .steps-title::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gold));
        }
        .steps-title::after {
            background: linear-gradient(90deg, var(--gold), transparent);
        }

        .steps-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            width: 100%;
            margin-bottom: 28px;
        }

        .step-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            background: var(--white);
            border: 1px solid #ddd;
            border-left: 4px solid var(--navy);
            padding: 12px 14px;
        }

        .step-num {
            width: 26px; height: 26px;
            background: var(--navy);
            color: var(--white);
            border-radius: 50%;
            font-family: 'Playfair Display', serif;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .step-text strong {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 2px;
        }

        .step-text span {
            font-size: 11px;
            color: #555;
            line-height: 1.4;
        }

        /* Note box */
        .note-box {
            width: 100%;
            background: #fff8e8;
            border: 1px solid var(--gold);
            border-left: 4px solid var(--gold);
            padding: 12px 16px;
            margin-bottom: 28px;
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }

        .note-box i { color: var(--gold); font-size: 16px; margin-top: 2px; }

        .note-box p {
            font-size: 12px;
            color: #5a4a1a;
            line-height: 1.6;
        }

        /* Footer */
        .poster-footer {
            background: var(--navy);
            padding: 14px 36px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .footer-left {
            font-size: 11px;
            color: rgba(255,255,255,0.6);
        }

        .footer-right {
            font-size: 11px;
            color: var(--gold);
            font-weight: 600;
            letter-spacing: 1px;
        }

        /* ── Print styles ── */
        @media print {
            body { background: white; padding: 0; }
            .controls, .url-display { display: none !important; }
            .poster {
                box-shadow: none;
                width: 100%;
                max-width: 640px;
                margin: 0 auto;
            }
        }
    </style>
</head>
<body>

    <!-- Screen-only controls -->
    <div class="controls">
        <button class="btn btn-print" onclick="window.print()">
            <i class="fas fa-print"></i> I-print ang Poster
        </button>
        <button class="btn btn-copy" onclick="copyURL()">
            <i class="fas fa-copy"></i> Kopyahin ang Link
        </button>
    </div>

    <div class="url-display" id="formURL">
        🔗 Form URL: <?php echo htmlspecialchars($form_url); ?>
    </div>

    <!-- ═══════════════════════════════════════════
         PRINTABLE POSTER
    ═══════════════════════════════════════════ -->
    <div class="poster" id="poster">

        <!-- Header -->
        <div class="poster-header">
            <div class="header-logos">
                <div class="gov-seal">🏛️</div>
                <div class="header-title">
                    <div class="agency">Department of Social Welfare and Development</div>
                    <h1>AICS Online<br>Application</h1>
                    <div class="branch-tag"><?php echo $branch; ?></div>
                </div>
                <div class="gov-seal">🇵🇭</div>
            </div>
        </div>

        <!-- Body -->
        <div class="poster-body">

            <div class="instruction-headline">I-scan para Mag-apply</div>
            <div class="instruction-sub">Gamitin ang iyong smartphone camera o QR scanner app</div>

            <!-- QR Code -->
            <div class="qr-frame">
                <img src="<?php echo $qr_api_url; ?>" alt="AICS Application QR Code" id="qrImage">
                <div class="qr-label">I-scan Dito</div>
            </div>

            <!-- Steps -->
            <div class="steps-title">Paano Gamitin</div>

            <div class="steps-grid">
                <div class="step-item">
                    <div class="step-num">1</div>
                    <div class="step-text">
                        <strong>I-scan ang QR Code</strong>
                        <span>Gamitin ang camera ng inyong cellphone</span>
                    </div>
                </div>
                <div class="step-item">
                    <div class="step-num">2</div>
                    <div class="step-text">
                        <strong>Punan ang Form</strong>
                        <span>I-fill up ang inyong impormasyon online</span>
                    </div>
                </div>
                <div class="step-item">
                    <div class="step-num">3</div>
                    <div class="step-text">
                        <strong>Kunin ang Code</strong>
                        <span>Lalabas ang inyong natatanging reference code</span>
                    </div>
                </div>
                <div class="step-item">
                    <div class="step-num">4</div>
                    <div class="step-text">
                        <strong>Pumunta sa Counter</strong>
                        <span>Ipakita ang code sa DSWD staff para sa proseso</span>
                    </div>
                </div>
            </div>

            <!-- Note -->
            <div class="note-box">
                <i class="fas fa-exclamation-circle"></i>
                <p>
                    <strong>PAALALA:</strong> Ang reference code ay may bisa lamang sa araw ng inyong pagbisita.
                    Siguraduhing may internet connection bago i-scan ang QR code.
                    Para sa tulong, lumapit sa pinakamalapit na DSWD staff.
                </p>
            </div>

        </div>

        <!-- Footer -->
        <div class="poster-footer">
            <div class="footer-left">
                Naka-print: <?php echo $current_date; ?> &nbsp;|&nbsp; Authorized Personnel Only
            </div>
            <div class="footer-right">
                AICS — Assistance to Individuals in Crisis Situation
            </div>
        </div>

    </div><!-- end .poster -->

    <script>
        function copyURL() {
            const url = "<?php echo addslashes($form_url); ?>";
            navigator.clipboard.writeText(url).then(() => {
                alert('Link nakopya na!\n\n' + url);
            }).catch(() => {
                prompt('Kopyahin ang link:', url);
            });
        }
    </script>

</body>
</html>