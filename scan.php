<?php
/**
 * scan.php — Barcode scan result page
 *
 * Called when a meal card barcode is scanned. Accepts either:
 *   ?value=MIFS-MF-2024-001   (raw barcode string)
 *   ?code=MIFS-MF-2024-001    (alias)
 *
 * Public scans show basic student identity only. Signed-in school staff and the
 * card holder can view the additional meal-card details.
 * Designed to be fast-loading on a tablet / scanner terminal.
 */

require_once __DIR__ . '/config.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

$allowedRoles = ['Administrator', 'Accountant', 'Teacher', 'Registrar', 'Head Teacher/Principal', 'Deputy Headteacher', 'Student'];
$authenticated = isset($_SESSION['user']);
$role = $authenticated ? (string) ($_SESSION['user']['role'] ?? '') : '';
if ($authenticated && !in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    exit('Access is restricted to school staff or the card holder.');
}

$connection = db();

// Accept ?value= or ?code=
$rawValue = $_GET['value'] ?? $_GET['code'] ?? '';
$raw = is_string($rawValue) ? trim($rawValue) : '';
if ($raw === '') {
    http_response_code(400);
    exit('No barcode value supplied. Scan or provide ?value=MIFS-xxxx.');
}

// Strip the SCAN/ prefix written into the barcode value (used internally)
$barcode = preg_replace('/^SCAN\//i', '', $raw);

// Lookup meal card → student
$cardStmt = $connection->prepare(
    'SELECT mc.id AS card_id, mc.barcode_value, mc.card_status, mc.balance,
            mc.downloaded_at, mc.created_at AS card_created,
            s.id AS student_id, s.admission_number,
            s.first_name, s.last_name,
            s.class_name, s.guardian_name, s.guardian_phone,
            s.created_at AS enrolled_at
     FROM meal_cards mc
     JOIN students   s  ON s.id  = mc.student_id
     WHERE mc.barcode_value = ?
     LIMIT 1'
);
$cardStmt->execute([$barcode]);
$data = $cardStmt->fetch();

// Also try a loose match on admission number embedded in barcode (MIFS-{admission})
if (!$data) {
    $admNo = preg_replace('/^MIFS-/i', '', $barcode);
    $cardStmt->execute(['MIFS-' . strtoupper($admNo)]);
    $data = $cardStmt->fetch();
}

$found = (bool) $data;

// If student is logged in, they may only look up their own card
if ($found && $role === 'Student') {
    if ((int) ($data['student_id'] ?? 0) !== (int) ($_SESSION['user']['student_id'] ?? -1)) {
        http_response_code(403);
        exit('You may only view your own meal card.');
    }
}

// Additional meal-card details are restricted to signed-in users.
$payInfo = ['cnt' => 0, 'total_paid' => 0, 'last_paid' => ''];
if ($found && $authenticated) {
    $payStmt = $connection->prepare(
        'SELECT COUNT(*) AS cnt,
                COALESCE(SUM(amount), 0) AS total_paid,
                COALESCE(MAX(payment_date), "") AS last_paid
         FROM fee_payments WHERE student_id = ?'
    );
    $payStmt->execute([(int) $data['student_id']]);
    $payInfo = $payStmt->fetch();
}

// Recent scan log
$recentScans = [];
if ($found && $authenticated) {
    $scanStmt = $connection->prepare(
        'SELECT ms.scan_date, ms.meal_type,
                COALESCE(u.full_name, "System") AS scanned_by_name
         FROM meal_scans ms
         LEFT JOIN users u ON u.id = ms.scanned_by
         WHERE ms.meal_card_id = ?
         ORDER BY ms.scan_date DESC LIMIT 10'
    );
    $scanStmt->execute([(int) $data['card_id']]);
    $recentScans = $scanStmt->fetchAll();
}

// Log this scan (only if staff, not self-view)
if ($found && in_array($role, ['Administrator', 'Accountant', 'Teacher', 'Registrar', 'Head Teacher/Principal', 'Deputy Headteacher'], true)) {
    try {
        // Ensure meal_scans table exists
        $connection->exec(
            'CREATE TABLE IF NOT EXISTS meal_scans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                meal_card_id INT NOT NULL,
                scanned_by INT NULL,
                scan_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                meal_type ENUM("Breakfast","Lunch","Supper") NOT NULL DEFAULT "Lunch",
                FOREIGN KEY (meal_card_id) REFERENCES meal_cards(id) ON DELETE CASCADE
            )'
        );
        $logStmt = $connection->prepare(
            'INSERT INTO meal_scans (meal_card_id, scanned_by, meal_type) VALUES (?, ?, ?)'
        );
        $mealType = $_GET['meal'] ?? 'Lunch';
        if (!in_array($mealType, ['Breakfast', 'Lunch', 'Supper'], true)) {
            $mealType = 'Lunch';
        }
        $logStmt->execute([(int) $data['card_id'], (int) $_SESSION['user']['id'], $mealType]);
    } catch (Throwable) {
        // Non-fatal — scan still shows even if logging fails
    }
}

$scanTime = date('l, d F Y \a\t H:i:s');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scan Result | MFIS Meal Card</title>
    <style>
        :root {
            --green:  #15803d;
            --green-l:#f0fdf4;
            --green-b:#bbf7d0;
            --red:    #b91c1c;
            --red-l:  #fff1f2;
            --red-b:  #fecaca;
            --ink:    #172b4d;
            --muted:  #718096;
            --line:   #e7edf4;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px 16px; background: #eef3f8; color: var(--ink); font: 14px Arial, sans-serif; }

        .topbar { max-width: 680px; margin: 0 auto 16px; display: flex; justify-content: space-between; align-items: center; }
        .topbar a { padding: 8px 14px; border-radius: 6px; color: #38516e; background: #dde6f0; text-decoration: none; font-size: 13px; }
        .scan-time { color: var(--muted); font-size: 12px; }

        /* ── Result card ── */
        .result-card { max-width: 680px; margin: auto; border-radius: 14px; overflow: hidden; box-shadow: 0 8px 28px rgba(0,0,0,.12); }

        /* Found state */
        .banner-found { display: flex; align-items: center; gap: 14px; padding: 18px 22px; background: var(--green); color: #fff; }
        .banner-notfound { display: flex; align-items: center; gap: 14px; padding: 18px 22px; background: var(--red); color: #fff; }
        .banner-icon { font-size: 36px; line-height: 1; }
        .banner-title { font-size: 20px; font-weight: 700; margin: 0; }
        .banner-sub   { font-size: 13px; margin: 3px 0 0; opacity: .85; }

        .card-body { padding: 22px; background: #fff; }

        /* Status pill */
        .status-pill {
            display: inline-block; padding: 4px 12px; border-radius: 20px;
            font-size: 12px; font-weight: 700;
        }
        .pill-Active    { color: #14532d; background: #bbf7d0; }
        .pill-Suspended { color: #7f1d1d; background: #fecaca; }

        /* Suspended warning */
        .suspended-warn {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px; margin-bottom: 18px;
            border-radius: 8px; background: var(--red-l); border: 1px solid var(--red-b);
            color: var(--red); font-weight: 700;
        }

        /* ── Details grid ── */
        .section-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--muted); margin: 18px 0 10px; }
        .details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .detail { padding: 10px 12px; border-radius: 8px; background: var(--green-l); border: 1px solid var(--green-b); }
        .detail small { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--green); margin-bottom: 3px; }
        .detail strong { font-size: 13px; color: #14532d; }
        .detail.full { grid-column: 1 / -1; }

        /* ── Payment summary ── */
        .pay-row { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; }
        .pay-stat { padding: 10px; border-radius: 8px; background: #eff6ff; border: 1px solid #bfdbfe; text-align: center; }
        .pay-stat small { display: block; font-size: 10px; font-weight: 700; color: #1d4ed8; text-transform: uppercase; margin-bottom: 4px; }
        .pay-stat strong { font-size: 16px; color: #1e3a8a; }

        /* ── Scan log ── */
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { padding: 4px 8px 10px; color: #8290a3; font-size: 10px; text-align: left; text-transform: uppercase; }
        td { padding: 9px 8px; border-top: 1px solid var(--line); color: #52637a; font-size: 12px; }

        /* ── Barcode mini ── */
        .barcode-wrap { text-align: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--line); }
        .barcode-wrap img { max-width: 100%; height: 50px; }
        .barcode-wrap span { display: block; margin-top: 4px; font-family: monospace; font-size: 12px; color: var(--green); font-weight: 700; letter-spacing: 1px; }

        .empty { padding: 14px; text-align: center; color: var(--muted); font-size: 13px; }

        @media (max-width: 520px) {
            .details-grid, .pay-row { grid-template-columns: 1fr; }
            .detail.full { grid-column: 1; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <?php if ($authenticated): ?>
    <a href="<?= $role === 'Student' ? 'student_portal.php' : 'dashboard.php' ?>">&#8592; Back</a>
    <?php else: ?>
    <a href="index.php">School sign in</a>
    <?php endif; ?>
    <span class="scan-time">&#128337; <?= e($scanTime) ?></span>
</div>

<div class="result-card">

    <?php if ($found): ?>
    <!-- ── FOUND banner ── -->
    <div class="banner-found">
        <div class="banner-icon">&#9989;</div>
        <div>
            <p class="banner-title"><?= e($data['first_name'] . ' ' . $data['last_name']) ?></p>
            <p class="banner-sub">Card verified &mdash; <?= e($data['admission_number']) ?> &middot; <?= e($data['class_name'] ?: 'No class') ?></p>
        </div>
        <span class="status-pill pill-<?= e($data['card_status']) ?>" style="margin-left:auto;">
            <?= e($data['card_status']) ?>
        </span>
    </div>

    <div class="card-body">

        <?php if ($data['card_status'] === 'Suspended'): ?>
        <div class="suspended-warn">
            <span>&#9888;</span>
            <span>This meal card is SUSPENDED. Do not serve this student until the card is reactivated by the Accounts Office.</span>
        </div>
        <?php endif; ?>

        <!-- Student particulars -->
        <div class="section-label">Student particulars</div>
        <div class="details-grid">
            <div class="detail full">
                <small>Full name</small>
                <strong><?= e($data['first_name'] . ' ' . $data['last_name']) ?></strong>
            </div>
            <div class="detail">
                <small>Admission number</small>
                <strong><?= e($data['admission_number']) ?></strong>
            </div>
            <div class="detail">
                <small>Class / Year</small>
                <strong><?= e($data['class_name'] ?: 'Not assigned') ?></strong>
            </div>
            <?php if ($authenticated): ?>
            <div class="detail">
                <small>Guardian name</small>
                <strong><?= e($data['guardian_name'] ?: 'Not provided') ?></strong>
            </div>
            <div class="detail">
                <small>Guardian phone</small>
                <strong><?= e($data['guardian_phone'] ?: 'Not provided') ?></strong>
            </div>
            <div class="detail">
                <small>Enrolled since</small>
                <strong><?= e(date('d M Y', strtotime($data['enrolled_at']))) ?></strong>
            </div>
            <div class="detail">
                <small>Card issued</small>
                <strong><?= e(date('d M Y', strtotime($data['card_created']))) ?></strong>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($authenticated): ?>
        <!-- Payment summary -->
        <div class="section-label">Fee payment status</div>
        <div class="pay-row">
            <div class="pay-stat">
                <small>Transactions</small>
                <strong><?= e((string) $payInfo['cnt']) ?></strong>
            </div>
            <div class="pay-stat">
                <small>Total paid</small>
                <strong><?= e(number_format((float) $payInfo['total_paid'], 0)) ?></strong>
            </div>
            <div class="pay-stat">
                <small>Last payment</small>
                <strong><?= e($payInfo['last_paid'] ?: '—') ?></strong>
            </div>
        </div>

        <!-- Recent scan log -->
        <div class="section-label">Recent meal scans</div>
        <?php if ($recentScans): ?>
        <table>
            <thead><tr><th>Date &amp; time</th><th>Meal</th><th>Verified by</th></tr></thead>
            <tbody>
            <?php foreach ($recentScans as $scan): ?>
            <tr>
                <td><?= e(date('d M Y H:i', strtotime($scan['scan_date']))) ?></td>
                <td><?= e($scan['meal_type']) ?></td>
                <td><?= e($scan['scanned_by_name']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty">No scan history recorded yet.</div>
        <?php endif; ?>

        <!-- Barcode -->
        <div class="barcode-wrap">
            <img src="barcode.php?value=<?= rawurlencode($data['barcode_value']) ?>"
                 alt="Barcode <?= e($data['barcode_value']) ?>">
            <span><?= e($data['barcode_value']) ?></span>
        </div>
        <?php else: ?>
        <p class="empty">This card is verified. Sign in to view additional meal-card details.</p>
        <?php endif; ?>

    </div><!-- /.card-body -->

    <?php else: ?>
    <!-- ── NOT FOUND banner ── -->
    <div class="banner-notfound">
        <div class="banner-icon">&#10060;</div>
        <div>
            <p class="banner-title">Card not recognised</p>
            <p class="banner-sub">No student record matched barcode: <?= e($raw) ?></p>
        </div>
    </div>
    <div class="card-body">
        <p style="color:#718096;">The scanned barcode does not match any meal card in the system.
        Possible reasons:</p>
        <ul style="color:#718096;line-height:1.8;">
            <li>The card belongs to another school system.</li>
            <li>The student's meal card was not yet generated (no recorded payment).</li>
            <li>The barcode was damaged or misread &mdash; try scanning again.</li>
        </ul>
        <p style="margin-top:18px;">
            <a href="<?= $authenticated ? 'dashboard.php' : 'index.php' ?>" style="color:#1763a6;"><?= $authenticated ? 'Go to dashboard' : 'School sign in' ?></a>
        </p>
    </div>
    <?php endif; ?>

</div><!-- /.result-card -->

</body>
</html>
