<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'Student') {
    http_response_code(403);
    exit('Only the student account may download this meal card.');
}

$connection = db();
$account = $connection->prepare(
    'SELECT users.student_id, students.admission_number, students.first_name, students.last_name,
            students.class_name, students.guardian_name
     FROM users JOIN students ON students.id = users.student_id
     WHERE users.id = ? LIMIT 1'
);
$account->execute([(int) $_SESSION['user']['id']]);
$student = $account->fetch();
if (!$student) {
    http_response_code(409);
    exit('Your student account is not linked to a student record.');
}

$payment = $connection->prepare(
    'SELECT COUNT(*) AS transaction_count FROM fee_payments WHERE student_id = ?'
);
$payment->execute([(int) $student['student_id']]);
if ((int) $payment->fetchColumn() < 1) {
    http_response_code(403);
    exit('The meal card becomes available after the Accounts Office records a payment.');
}

$connection->exec(
    'CREATE TABLE IF NOT EXISTS meal_cards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL UNIQUE,
        barcode_value VARCHAR(80) NOT NULL UNIQUE,
        balance INT NOT NULL DEFAULT 0,
        card_status ENUM("Active", "Suspended") NOT NULL DEFAULT "Active",
        downloaded_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    )'
);
$connection->exec('ALTER TABLE meal_cards ADD COLUMN IF NOT EXISTS downloaded_at DATETIME NULL');

$cardNumber = 'MIFS-' . $student['admission_number'];
$save = $connection->prepare(
    'INSERT INTO meal_cards (student_id, barcode_value, downloaded_at) VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE barcode_value = VALUES(barcode_value), downloaded_at = NOW()'
);
$save->execute([(int) $student['student_id'], $cardNumber]);

$card = $connection->prepare(
    'SELECT card_status, downloaded_at FROM meal_cards WHERE student_id = ? LIMIT 1'
);
$card->execute([(int) $student['student_id']]);
$card = $card->fetch();

// Encode a complete URL so a phone camera opens the student verification page.
$scanUrl = app_absolute_url('scan.php?value=' . rawurlencode($cardNumber));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Meal Card | MFIS</title>
    <style>
        body{margin:0;padding:30px;background:#eef3f8;color:#172b4d;font:14px Arial,sans-serif}
        .actions{max-width:700px;margin:0 auto 18px;text-align:right}
        .actions button,.actions a{padding:10px 14px;border:0;border-radius:6px;color:#fff;background:#1763a6;text-decoration:none;cursor:pointer}
        .meal-card{max-width:700px;margin:auto;padding:16px;border:4px solid #15803d;border-radius:12px;background:#f0fdf4;box-shadow:0 8px 24px rgba(21,128,61,.14)}
        .heading{display:flex;align-items:center;gap:10px;padding-bottom:10px;border-bottom:2px solid #4ade80}
        .crest{display:grid;width:44px;height:44px;place-items:center;border-radius:8px;overflow:hidden;background:#fff}
        .crest img{width:100%;height:100%;object-fit:contain}
        .heading h1{margin:0;font-size:18px;color:#166534}
        .heading p{margin:3px 0 0;color:#166534}
        .details{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin:12px 0}
        .detail{padding:7px;border-radius:5px;background:#dcfce7}
        .detail small{display:block;margin-bottom:3px;color:#166534;font-size:11px}
        .detail strong{font-size:12px}
        /* QR code section */
        .qr-section{text-align:center;margin-top:10px;padding:10px 0}
        .qr-section img{width:180px;height:180px;image-rendering:pixelated}
        .qr-label{margin-top:6px;font-family:monospace;font-size:11px;color:#166534;font-weight:700;letter-spacing:.5px}
        .qr-hint{margin-top:3px;font-size:11px;color:#4ade80}
        /* Service record */
        .service-record{margin-top:10px;padding-top:8px;border-top:1px solid #bbf7d0}
        .service-record h2{margin:0 0 6px;color:#166534;font-size:13px;text-align:center}
        .month-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
        .month{padding:5px;border:1px solid #bbf7d0;border-radius:5px;background:#fff}
        .month h3{margin:0 0 4px;padding-bottom:3px;border-bottom:1px solid #dcfce7;color:#166534;font-size:11px;text-align:center}
        .days-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
        .day{display:flex;flex-direction:column;align-items:center;gap:1px;color:#52637a;font-size:8px}
        .mark-box{width:8px;height:8px;border:1px solid #52637a;border-radius:1px;background:#fff}
        .approval{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:10px;padding-top:8px;border-top:1px solid #bbf7d0}
        .approval-field{min-height:25px;padding-top:12px;border-bottom:1px solid #52637a;color:#52637a;font-size:11px}
        .approval-label{display:block;margin-top:3px;color:#166534;font-size:10px;text-align:center}
        @media print{body{padding:0;background:#fff}.actions{display:none}.meal-card{border:2px solid #15803d;box-shadow:none;page-break-inside:avoid}}
        @media(max-width:550px){.details{grid-template-columns:1fr}.month-grid{grid-template-columns:1fr}.approval{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="actions">
    <a href="student_portal.php">Back to portal</a>
    <button type="button" onclick="window.print()">Print card</button>
</div>
<article class="meal-card">
    <div class="heading">
        <div class="crest"><img src="assets/logo.png" alt="MFIS logo"></div>
        <div>
            <h1>Mandela International French School</h1>
            <p>Official Student Meal Card</p>
        </div>
    </div>
    <div class="details">
        <div class="detail"><small>Student name</small><strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong></div>
        <div class="detail"><small>Admission number</small><strong><?= e($student['admission_number']) ?></strong></div>
        <div class="detail"><small>Class</small><strong><?= e($student['class_name'] ?: 'Not assigned') ?></strong></div>
        <div class="detail"><small>Guardian</small><strong><?= e($student['guardian_name'] ?: 'Not provided') ?></strong></div>
        <div class="detail"><small>Card status</small><strong><?= e($card['card_status']) ?></strong></div>
        <div class="detail"><small>Downloaded</small><strong><?= e($card['downloaded_at']) ?></strong></div>
    </div>

    <!-- QR code — encodes the full scan URL so any phone camera opens scan.php -->
    <div class="qr-section">
        <img src="qr.php?url=<?= rawurlencode($scanUrl) ?>"
             alt="QR code for <?= e($cardNumber) ?>">
        <div class="qr-label"><?= e($cardNumber) ?></div>
        <div class="qr-hint">&#128247; Scan using a phone connected to the same Wi-Fi. The verification page displays the student name, class and admission number.</div>
    </div>

    <section class="service-record">
        <h2>Meal service record</h2>
        <div class="month-grid">
            <?php for ($month = 1; $month <= 3; $month++): ?>
                <div class="month">
                    <h3>Month <?= $month ?></h3>
                    <div class="days-grid">
                        <?php for ($day = 1; $day <= 31; $day++): ?>
                            <div class="day"><span><?= $day ?></span><span class="mark-box" aria-label="Day <?= $day ?> served mark"></span></div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </section>
    <section class="approval">
        <div><div class="approval-field"></div><span class="approval-label">Dean signature / approval</span></div>
        <div><div class="approval-field"></div><span class="approval-label">Card expiry date</span></div>
    </section>
</article>
</body>
</html>
