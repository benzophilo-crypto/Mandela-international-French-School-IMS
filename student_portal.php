<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}
if (($_SESSION['user']['role'] ?? '') !== 'Student') {
    header('Location: dashboard.php');
    exit;
}

$connection = db();
ensure_exam_tables();
ensure_pay_code_column();
$connection->exec(
    'CREATE TABLE IF NOT EXISTS meal_cards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL UNIQUE,
        barcode_value VARCHAR(80) NOT NULL UNIQUE,
        balance INT NOT NULL DEFAULT 0,
        card_status ENUM("Active", "Suspended") NOT NULL DEFAULT "Active",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    )'
);
$connection->exec('ALTER TABLE meal_cards ADD COLUMN IF NOT EXISTS downloaded_at DATETIME NULL');
$connection->exec(
    'CREATE TABLE IF NOT EXISTS meal_scans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        meal_card_id INT NOT NULL,
        scanned_by INT NULL,
        scan_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        meal_type ENUM("Breakfast", "Lunch", "Supper") NOT NULL DEFAULT "Lunch",
        FOREIGN KEY (meal_card_id) REFERENCES meal_cards(id) ON DELETE CASCADE
    )'
);
$account = $connection->prepare(
    'SELECT users.student_id, students.admission_number, students.first_name, students.last_name,
            students.class_name, students.guardian_name, students.pay_code
     FROM users JOIN students ON students.id = users.student_id
     WHERE users.id = ? LIMIT 1'
);
$account->execute([(int) $_SESSION['user']['id']]);
$student = $account->fetch();
if (!$student) {
    http_response_code(409);
    exit('Your student account is not linked to a student record. Contact ICT.');
}
if (empty($student['pay_code'])) {
    $payCode = generate_pay_code();
    $savePayCode = $connection->prepare(
        'UPDATE students SET pay_code = ? WHERE id = ? AND (pay_code IS NULL OR pay_code = "")'
    );
    $savePayCode->execute([$payCode, (int) $student['student_id']]);
    $account->execute([(int) $_SESSION['user']['id']]);
    $student = $account->fetch();
    if (!$student || empty($student['pay_code'])) {
        throw new RuntimeException('Unable to assign a payment code to your student record. Contact the Accounts Office.');
    }
}
$schoolName = system_setting('school_name', 'Mandela International French School');
$paymentEligibility = $connection->prepare(
    'SELECT COUNT(*) AS transaction_count, COALESCE(SUM(amount), 0) AS total_paid
     FROM fee_payments WHERE student_id = ?'
);
$paymentEligibility->execute([(int) $student['student_id']]);
$paymentEligibility = $paymentEligibility->fetch();
$mealCardAvailable = (int) $paymentEligibility['transaction_count'] > 0;
$mealCardStatement = $connection->prepare(
    'INSERT INTO meal_cards (student_id, barcode_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE barcode_value = VALUES(barcode_value)'
);
$mealCardStatement->execute([(int) $student['student_id'], 'MIFS-' . $student['admission_number']]);
$mealCard = $connection->prepare(
    'SELECT barcode_value, balance, card_status, downloaded_at FROM meal_cards WHERE student_id = ? LIMIT 1'
);
$mealCard->execute([(int) $student['student_id']]);
$mealCard = $mealCard->fetch();

// Encode a complete URL so a phone camera opens the student verification page.
$_scanUrl = app_absolute_url('scan.php?value=' . rawurlencode((string) $mealCard['barcode_value']));

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Your session token expired. Refresh and try again.');
        }
        $term = trim($_POST['term'] ?? '');
        $year = trim($_POST['academic_year'] ?? '');
        $reportDate = $_POST['report_date'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        if (!in_array($term, ['Term 1', 'Term 2', 'Term 3'], true) || !preg_match('/^\d{4}\/\d{4}$/', $year) || !DateTime::createFromFormat('Y-m-d', $reportDate)) {
            throw new InvalidArgumentException('Select a valid term, academic year, and reporting date.');
        }
        $statement = $connection->prepare(
            'INSERT INTO enrollments (student_id, term, academic_year, report_date, notes)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE report_date = VALUES(report_date), notes = VALUES(notes), registration_status = "Submitted"'
        );
        $statement->execute([(int) $student['student_id'], $term, $year, $reportDate, $notes]);
        $message = 'Your term registration has been submitted for review.';
    }
} catch (Throwable $exception) {
    $error = $exception instanceof PDOException && $exception->getCode() === '23000'
        ? 'This term registration already exists.'
        : $exception->getMessage();
}

$enrollments = $connection->prepare(
    'SELECT term, academic_year, report_date, registration_status, notes, created_at
     FROM enrollments WHERE student_id = ? ORDER BY created_at DESC'
);
$enrollments->execute([(int) $student['student_id']]);
$enrollments = $enrollments->fetchAll();

$attendance = $connection->prepare(
    'SELECT attendance_date, attendance_status FROM attendance WHERE student_id = ? ORDER BY attendance_date DESC LIMIT 8'
);
$attendance->execute([(int) $student['student_id']]);
$attendance = $attendance->fetchAll();

$payments = $connection->prepare(
    'SELECT amount, payment_date, receipt_number FROM fee_payments WHERE student_id = ? ORDER BY payment_date DESC LIMIT 8'
);
$payments->execute([(int) $student['student_id']]);
$payments = $payments->fetchAll();
$publishedResults = $connection->prepare(
    'SELECT e.id, e.title, e.term, e.academic_year, e.exam_date,
            SUM(sm.marks_obtained) AS total_marks, SUM(es.max_marks) AS maximum_marks
     FROM exams e
     JOIN exam_subjects es ON es.exam_id = e.id
     JOIN student_marks sm ON sm.exam_id = e.id AND sm.subject_id = es.subject_id
                          AND sm.student_id = ? AND sm.status = "Validated"
     WHERE e.status = "Published" AND e.class_name = ?
     GROUP BY e.id, e.title, e.term, e.academic_year, e.exam_date
     HAVING COUNT(DISTINCT sm.subject_id) = (
         SELECT COUNT(*) FROM exam_subjects WHERE exam_id = e.id
     )
     ORDER BY e.exam_date DESC, e.id DESC'
);
$publishedResults->execute([(int) $student['student_id'], (string) $student['class_name']]);
$publishedResults = $publishedResults->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Portal | MIFS IMS</title>
    <style>
        :root{--navy:#092d55;--blue:#1763a6;--teal:#0d9488;--violet:#6d28d9;--amber:#d97706;--rose:#be123c;--emerald:#15803d;--ink:#172b4d;--muted:#718096;--line:#e7edf4;--shell:#f6f8fc;--radius:14px;--shadow:0 4px 18px rgba(15,40,70,.07);--shadow-hi:0 14px 34px rgba(15,40,70,.13)}
        *{box-sizing:border-box}
        body{margin:0;color:var(--ink);font:14px/1.55 Arial,Helvetica,sans-serif;background:var(--shell);background-image:radial-gradient(1100px 420px at 8% -8%,#dbeafe 0%,rgba(219,234,254,0) 62%),radial-gradient(900px 400px at 100% 0%,#ede9fe 0%,rgba(237,233,254,0) 58%),linear-gradient(180deg,#eef4ff 0,#f6f8fc 38%,#f6f8fc 100%);background-attachment:fixed;min-height:100vh}
        header{display:flex;justify-content:space-between;align-items:center;padding:18px 5%;color:#fff;background:linear-gradient(115deg,#092d55 0%,#12467f 38%,#1763a6 68%,#0d9488 100%);box-shadow:0 8px 26px rgba(9,45,85,.28)}header strong{display:flex;align-items:center;gap:10px}header nav{display:flex;align-items:center;gap:10px}header a{padding:8px 15px;border-radius:999px;color:#eaf4ff;background:rgba(255,255,255,.14);text-decoration:none;font-weight:700;transition:background .18s,transform .18s}header a:hover{background:rgba(255,255,255,.28);transform:translateY(-1px)}.portal-logo{width:38px;height:38px;object-fit:contain;border-radius:50%;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.28)}main{max-width:1250px;margin:auto;padding:28px 5% 46px}h1{margin:0 0 7px}.intro{color:var(--muted)}
        .hero,.card,.stat{position:relative;padding:20px 22px;border:1px solid var(--line);border-radius:var(--radius);background:#fff;box-shadow:var(--shadow);transition:transform .2s,box-shadow .2s}.hero{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;overflow:hidden}.hero::before{content:"";position:absolute;inset:0 auto 0 0;width:6px;background:linear-gradient(180deg,#1763a6,#0d9488)}.hero:hover,.card:hover,.stat:hover{transform:translateY(-3px);box-shadow:var(--shadow-hi)}.code{padding:9px 14px;border:1px solid #cfe2f6;border-radius:999px;color:#0f4c81;background:linear-gradient(135deg,#eaf3fb,#e0f2f1);font-size:12px;font-weight:700;letter-spacing:.4px}
        .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:20px}.stat{overflow:hidden}.stat::before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#1763a6,#38bdf8)}.stat:nth-child(2)::before{background:linear-gradient(90deg,#0d9488,#5eead4)}.stat:nth-child(3)::before{background:linear-gradient(90deg,#6d28d9,#c4b5fd)}.stat::after{content:"";position:absolute;right:-26px;top:-26px;width:96px;height:96px;border-radius:50%;background:#f1f6fc;z-index:0;pointer-events:none}.stat .label,.stat .value{position:relative;z-index:1}.label{color:var(--muted);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px}.value{margin-top:8px;font-size:24px;font-weight:700;color:#0f3557}.columns{display:grid;grid-template-columns:1fr 1fr;gap:20px}.card{margin-bottom:20px}.card h2{margin:0 0 8px;font-size:17px;color:#0f3557}.card h2::after{content:"";display:block;width:44px;height:3px;margin-top:7px;border-radius:2px;background:linear-gradient(90deg,#1763a6,#0d9488)}
        label{display:block;margin:13px 0 5px;color:#42607d;font-size:12px;font-weight:700}input,select,textarea{width:100%;padding:10px 12px;border:1px solid #d8e0ea;border-radius:8px;background:#fbfdff;font:inherit;transition:border-color .18s,box-shadow .18s,background .18s}input:hover,select:hover,textarea:hover{border-color:#b6c9de}input:focus,select:focus,textarea:focus{outline:0;border-color:var(--blue);background:#fff;box-shadow:0 0 0 4px rgba(23,99,166,.14)}textarea{min-height:70px;resize:vertical}
        button{padding:11px 18px;border:0;border-radius:8px;color:#fff;background:linear-gradient(135deg,#1763a6,#0d9488);box-shadow:0 6px 16px rgba(23,99,166,.28);cursor:pointer;font:inherit;font-weight:700;letter-spacing:.2px;transition:transform .18s,box-shadow .18s,filter .18s}button:hover{transform:translateY(-2px);filter:saturate(1.12);box-shadow:0 10px 22px rgba(23,99,166,.34)}button:active{transform:translateY(0)}
        .notice{padding:13px 16px;margin-bottom:18px;border:1px solid #bfe7d6;border-left:5px solid var(--emerald);border-radius:9px;color:#1d6b4c;background:#eafaf3;box-shadow:0 4px 14px rgba(21,128,61,.09)}.error{border-color:#f3c9c9;border-left-color:var(--rose);color:#a33a3a;background:#fff1f1}
        .table-wrap{overflow:auto;border-radius:9px}table{width:100%;border-collapse:collapse}th{padding:11px 9px;color:#5c7290;background:linear-gradient(180deg,#f2f7fc,#eaf1f9);font-size:11px;text-align:left;text-transform:uppercase;letter-spacing:.6px}th:first-child{border-top-left-radius:9px}th:last-child{border-top-right-radius:9px}td{padding:12px 9px;border-top:1px solid #edf1f5;color:#42607d}tbody tr{transition:background .15s}tbody tr:hover{background:#f4f9ff}
        .status{display:inline-block;padding:4px 11px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.3px}.Submitted{color:#8a5200;background:#fff3d6}.Approved{color:#17603f;background:#dcfce7}.Rejected,.Absent{color:#9f1239;background:#ffe4e6}.Present{color:#17603f;background:#dcfce7}.Late{color:#8a5200;background:#fff3d6}.amount{color:var(--blue);font-weight:700;text-align:right}.empty{padding:18px;color:#8290a3;text-align:center;background:#fafcff;border:1px dashed #dbe4ef;border-radius:9px}
        td.Submitted,td.Approved,td.Rejected,td.Absent,td.Present,td.Late{width:1%;white-space:nowrap}
        /* Student avatar */
        .avatar{width:68px;height:68px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(140deg,#fff,#e8f1fb);box-shadow:0 8px 20px rgba(15,40,70,.16);border:2px solid #fff;overflow:hidden}.avatar img{width:100%;height:100%;object-fit:contain}.avatar svg{display:block}
        .card-action{display:inline-block;padding:11px 17px;border-radius:8px;color:#fff;background:linear-gradient(135deg,#1763a6,#0d9488);box-shadow:0 6px 16px rgba(23,99,166,.28);text-decoration:none;font-weight:700;transition:transform .18s,box-shadow .18s}.card-action:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(23,99,166,.34)}.downloaded{padding:10px 13px;border-radius:8px;color:#17603f;background:#dcfce7;font-weight:700}.pending-download{padding:10px 13px;border:1px solid #f5dda2;border-radius:8px;color:#8a5200;background:#fff8e6}
        .meal-card{border:2px solid #15803d;background:linear-gradient(160deg,#f2fdf5,#ffffff 46%);box-shadow:0 10px 28px rgba(21,128,61,.15)}.meal-card::before{content:"";position:absolute;top:0;left:0;right:0;height:5px;background:linear-gradient(90deg,#15803d,#4ade80,#15803d)}.meal-card h2{color:#14532d}.meal-card .card-action{background:linear-gradient(135deg,#15803d,#16a34a);box-shadow:0 6px 16px rgba(21,128,61,.3)}
        .payment-reference{border:2px solid #c4b5fd;background:linear-gradient(145deg,#faf8ff,#fff 58%)}.payment-reference h2{color:#4c1d95}.pay-code-value{display:inline-block;margin:6px 0;padding:9px 13px;border:1px dashed #8b5cf6;border-radius:8px;color:#4c1d95;background:#f5f3ff;font-family:monospace;font-size:20px;font-weight:700;letter-spacing:1px}.payment-particulars{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:12px 0}.payment-particular{padding:10px;border-radius:8px;background:#f5f3ff}.payment-particular small{display:block;color:#6b5b89;font-size:10px;font-weight:700;text-transform:uppercase}.payment-particular strong{display:block;margin-top:3px;color:#35245b}
        .card a:not(.card-action){color:var(--blue);font-weight:700;text-decoration:none}.card a:not(.card-action):hover{color:#0d9488;text-decoration:underline}
        @media(max-width:800px){.stats,.columns{grid-template-columns:1fr}}@media(max-width:500px){header,.hero{align-items:flex-start;flex-direction:column;gap:12px}header{padding:16px 5%}.value{font-size:21px}}
        @media(hover:none){.hero:hover,.card:hover,.stat:hover,button:hover,.card-action:hover,header a:hover{transform:none}}
        @media print{body{background:#fff}header{background:none;color:#000;box-shadow:none}button,.card-action,.nav-hide{display:none}.card,.stat{box-shadow:none}}
    </style>
</head>
<body>
<header><strong><img class="portal-logo" src="assets/logo.png" alt="Mandela International French School logo">MIFS IMS &middot; Student Portal</strong><nav><a href="logout.php">Sign out</a></nav></header>
<main>
    <section class="hero">
        <div style="display:flex;align-items:center;gap:14px">
            <div class="avatar"><img src="assets/logo.png" alt="Mandela International French School logo"></div>
            <div>
                <h1>Welcome, <?= e($student['first_name']) ?>.</h1>
                <p class="intro">Register for each term, report to school, and follow your personal records.</p>
            </div>
        </div>
        <div class="code"><?= e($student['admission_number']) ?></div>
    </section>
    <?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($mealCardAvailable): ?><div class="notice">Your Accounts Office transaction has been confirmed. Your meal card is now available to download and print.</div><?php endif; ?>
    <section class="card payment-reference" id="payment-reference">
        <h2>Student payment details</h2>
        <p class="intro">Use the pay code as the payment reference. Before confirming with your bank, mobile-money provider, or Accounts Office, verify that the recipient details show this school and student.</p>
        <div class="pay-code-value"><?= e((string) $student['pay_code']) ?></div>
        <div class="payment-particulars">
            <div class="payment-particular"><small>School</small><strong><?= e($schoolName) ?></strong></div>
            <div class="payment-particular"><small>Student</small><strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong></div>
            <div class="payment-particular"><small>Class</small><strong><?= e($student['class_name'] ?: 'Not assigned') ?></strong></div>
            <div class="payment-particular"><small>Admission number</small><strong><?= e($student['admission_number']) ?></strong></div>
        </div>
        <p class="intro">If the payment screen does not show these particulars after entering the pay code, do not complete the transfer; contact the Accounts Office. The school system records payments after receipt and does not itself initiate bank or mobile-money transfers.</p>
    </section>
    <section class="card meal-card"><h2>Student meal card</h2><?php if ($mealCardAvailable): ?><p><strong>Student:</strong> <?= e($student['first_name'] . ' ' . $student['last_name']) ?><br><strong>Admission number:</strong> <?= e($student['admission_number']) ?><br><strong>Class:</strong> <?= e($student['class_name'] ?: 'Not assigned') ?><br><strong>Card status:</strong> <?= e($mealCard['card_status']) ?></p><div style="text-align:center;margin:12px 0;"><img src="qr.php?url=<?= rawurlencode($_scanUrl) ?>" alt="QR code for <?= e($mealCard['barcode_value']) ?>" width="180" height="180" style="image-rendering:pixelated;display:block;margin:0 auto;"><span style="display:block;margin-top:5px;font-family:monospace;font-size:11px;color:#166534;font-weight:700;"><?= e($mealCard['barcode_value']) ?></span><span style="display:block;margin-top:2px;font-size:11px;color:#4ade80;">&#128247; Scan with a phone connected to the same Wi-Fi; the verification page shows the student name, class and admission number.</span></div><p><a class="card-action" href="meal_card.php" target="_blank">Download / print meal card</a></p><?php if ($mealCard['downloaded_at']): ?><p class="downloaded">Meal card downloaded on <?= e($mealCard['downloaded_at']) ?></p><?php else: ?><p class="pending-download">Meal card has not been downloaded yet.</p><?php endif; ?><?php else: ?><p class="intro">The meal card will become available after the Accounts Office records a payment transaction for your student account.</p><p class="pending-download">No qualifying Accounts Office transaction found yet.</p><?php endif; ?></section>
    <section class="stats"><div class="stat"><div class="label">Student</div><div class="value"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></div></div><div class="stat"><div class="label">Class</div><div class="value"><?= e($student['class_name'] ?: 'Not assigned') ?></div></div><div class="stat"><div class="label">Registrations submitted</div><div class="value"><?= e((string) count($enrollments)) ?></div></div></section>
    <div class="columns">
        <section class="card"><h2>Term registration and reporting</h2><p class="intro">Submit this form when you report for a new term. ICT or the Registrar can review your submission.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><label>Term</label><select name="term" required><option value="">Choose term</option><option>Term 1</option><option>Term 2</option><option>Term 3</option></select><label>Academic year</label><input name="academic_year" placeholder="2026/2027" pattern="\d{4}/\d{4}" required><label>Reporting date</label><input type="date" name="report_date" value="<?= e(date('Y-m-d')) ?>" required><label>Additional information</label><textarea name="notes" placeholder="Transport, accommodation, health, or other information for the school"></textarea><br><br><button type="submit">Submit term registration</button></form></section>
        <section class="card"><h2>My term registrations</h2><?php if ($enrollments): ?><div class="table-wrap"><table><thead><tr><th>Term</th><th>Year</th><th>Report date</th><th>Status</th></tr></thead><tbody><?php foreach ($enrollments as $enrollment): ?><tr><td><?= e($enrollment['term']) ?></td><td><?= e($enrollment['academic_year']) ?></td><td><?= e($enrollment['report_date']) ?></td><td class="<?= e($enrollment['registration_status']) ?>"><?= e($enrollment['registration_status']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty">No term registration submitted yet.</div><?php endif; ?></section>
    </div>
    <div class="columns">
        <section class="card"><h2>Recent attendance</h2><?php if ($attendance): ?><table><thead><tr><th>Date</th><th>Status</th></tr></thead><tbody><?php foreach ($attendance as $row): ?><tr><td><?= e($row['attendance_date']) ?></td><td class="<?= e($row['attendance_status']) ?>"><?= e($row['attendance_status']) ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="empty">No attendance records yet.</div><?php endif; ?></section>
        <section class="card"><h2>Recent payments</h2><?php if ($payments): ?><table><thead><tr><th>Date</th><th>Receipt</th><th>Amount</th></tr></thead><tbody><?php foreach ($payments as $payment): ?><tr><td><?= e($payment['payment_date']) ?></td><td><?= e($payment['receipt_number']) ?></td><td class="amount"><?= e(number_format((float) $payment['amount'], 0)) ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="empty">No payment records yet.</div><?php endif; ?></section>
    </div>
    <section class="card"><h2>Academic results</h2><?php if ($publishedResults): ?><p class="intro">Examination reports published by the Registrar.</p><div class="table-wrap"><table><thead><tr><th>Examination</th><th>Term</th><th>Total</th><th>Average</th><th>Report</th></tr></thead><tbody><?php foreach ($publishedResults as $result): $average = (float) $result['maximum_marks'] > 0 ? ((float) $result['total_marks'] / (float) $result['maximum_marks']) * 100 : 0; ?><tr><td><?= e($result['title']) ?></td><td><?= e($result['term'] . ' ' . $result['academic_year']) ?></td><td><?= e(number_format((float) $result['total_marks'], 2) . ' / ' . number_format((float) $result['maximum_marks'], 2)) ?></td><td><?= e(number_format($average, 2)) ?>%</td><td><a href="results.php">View full report</a></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="notice">Published results for your student record will appear here.</p><?php endif; ?></section>
</main>
</body>
</html>
