<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$role = $_SESSION['user']['role'] ?? '';
if (!has_system_permission('finance.access')) {
    http_response_code(403);
    exit('Finance dashboard permission is required.');
}

$user       = $_SESSION['user'];
$connection = db();
ensure_pay_code_column();
ensure_payment_method_column();
ensure_fee_assessment_table();
$schoolName = system_setting('school_name', 'Mandela International French School');

// Inventory snapshot shown on the Accountant dashboard only.
$inventorySummary = null;
$inventoryAlerts  = [];
if ($role === 'Accountant') {
    ensure_inventory_tables();

    $inventorySummary = $connection->query(
        'SELECT COUNT(*) AS item_count,
                COALESCE(SUM(quantity_on_hand), 0) AS units,
                COALESCE(SUM(quantity_on_hand * unit_cost), 0) AS stock_value,
                COALESCE(SUM(is_active = 1 AND quantity_on_hand <= reorder_level), 0) AS reorder_count,
                COALESCE(SUM(is_active = 1 AND quantity_on_hand <= 0), 0) AS out_count
         FROM inventory_items'
    )->fetch();

    $inventoryAlerts = $connection->query(
        'SELECT item_code, item_name, quantity_on_hand, reorder_level, unit
         FROM inventory_items
         WHERE is_active = 1 AND quantity_on_hand <= reorder_level
         ORDER BY quantity_on_hand ASC, item_name LIMIT 6'
    )->fetchAll();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message      = '';
$error        = '';
$editPayment  = null;
$savedPaymentId = 0;

/* ── POST actions ─────────────────────────────────────────────────────── */
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Your session token expired. Refresh the page and try again.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'save_payment') {
            $id         = (int) ($_POST['id'] ?? 0);
            $studentId  = (int) ($_POST['student_id'] ?? 0);
            $amount     = filter_var($_POST['amount'] ?? '', FILTER_VALIDATE_FLOAT);
            $date       = $_POST['payment_date'] ?? '';
            $receipt    = trim($_POST['receipt_number'] ?? '');
            $method     = $_POST['payment_method'] ?? '';
            $validMethods = ['Cash', 'Mobile Money', 'Bank', 'Pay Code'];

            if ($studentId < 1 || $amount === false || $amount <= 0 || $receipt === '' || !DateTime::createFromFormat('Y-m-d', $date)) {
                throw new InvalidArgumentException('Select a student and enter a valid amount, date, and receipt number.');
            }
            if (!in_array($method, $validMethods, true)) {
                throw new InvalidArgumentException('Select a valid payment method.');
            }

            if ($method === 'Pay Code') {
                $pcRow = $connection->prepare('SELECT pay_code FROM students WHERE id = ? LIMIT 1');
                $pcRow->execute([$studentId]);
                $studentPayCode = $pcRow->fetchColumn();
                if (!$studentPayCode) {
                    throw new RuntimeException('This student has no pay code assigned. Generate one in the Student pay codes section first.');
                }

                if ($id > 0) {
                    $existingReceipt = $connection->prepare('SELECT receipt_number FROM fee_payments WHERE id = ? LIMIT 1');
                    $existingReceipt->execute([$id]);
                    $stored = (string) $existingReceipt->fetchColumn();
                    if (strpos($stored, (string) $studentPayCode . '-') !== 0 && $stored !== (string) $studentPayCode) {
                        throw new InvalidArgumentException('Pay code mismatch on edit. The stored receipt does not belong to this student\'s pay code.');
                    }
                    $receipt = $stored;
                } else {
                    if (trim($receipt) !== (string) $studentPayCode) {
                        throw new InvalidArgumentException('The receipt number does not match this student\'s pay code (' . $studentPayCode . '). It should have been auto-filled — reload the page and try again.');
                    }
                    $datePart = date('Ymd', strtotime($date));
                    $countRow = $connection->prepare('SELECT COUNT(*) FROM fee_payments WHERE receipt_number LIKE ?');
                    $countRow->execute([$studentPayCode . '-' . $datePart . '%']);
                    $seq     = (int) $countRow->fetchColumn() + 1;
                    $receipt = $studentPayCode . '-' . $datePart . '-' . $seq;
                }
            }

            if ($id > 0) {
                $stmt = $connection->prepare(
                    'UPDATE fee_payments SET student_id=?, amount=?, payment_date=?, receipt_number=?, payment_method=? WHERE id=?'
                );
                $stmt->execute([$studentId, $amount, $date, $receipt, $method, $id]);
                $savedPaymentId = $id;
                $message = 'Payment transaction updated successfully.';
            } else {
                $stmt = $connection->prepare(
                    'INSERT INTO fee_payments (student_id, amount, payment_date, receipt_number, payment_method, recorded_by) VALUES (?,?,?,?,?,?)'
                );
                $stmt->execute([$studentId, $amount, $date, $receipt, $method, $user['id']]);
                $savedPaymentId = (int) $connection->lastInsertId();
                $message = 'Payment transaction recorded successfully.';
            }

        } elseif ($action === 'save_fee_assessment') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $term = trim((string) ($_POST['term'] ?? ''));
            $academicYear = trim((string) ($_POST['academic_year'] ?? ''));
            $amountDue = filter_var($_POST['amount_due'] ?? '', FILTER_VALIDATE_FLOAT);
            if ($studentId < 1 || $term === '' || strlen($term) > 20
                || !preg_match('/^\d{4}\/\d{4}$/', $academicYear)
                || $amountDue === false || !is_finite((float) $amountDue)
                || $amountDue <= 0 || $amountDue > 9999999999.99) {
                throw new InvalidArgumentException('Select a student and enter a valid term, academic year (YYYY/YYYY), and fee amount.');
            }

            $assessment = $connection->prepare(
                'INSERT INTO student_fee_assessments (student_id, term, academic_year, amount_due, assessed_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE amount_due = VALUES(amount_due), assessed_by = VALUES(assessed_by), assessed_at = CURRENT_TIMESTAMP'
            );
            $assessment->execute([$studentId, $term, $academicYear, $amountDue, (int) $user['id']]);
            $message = 'Student fee assessment saved successfully.';

        } elseif ($action === 'delete_payment') {
            $stmt = $connection->prepare('DELETE FROM fee_payments WHERE id = ?');
            $stmt->execute([(int) ($_POST['id'] ?? 0)]);
            $message = 'Payment transaction deleted successfully.';

        } elseif ($action === 'regenerate_pay_code') {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            if ($studentId < 1) {
                throw new InvalidArgumentException('Invalid student.');
            }
            $newCode = generate_pay_code();
            $connection->prepare('UPDATE students SET pay_code = ? WHERE id = ?')->execute([$newCode, $studentId]);
            $message = 'Pay code regenerated successfully.';
        }

    }

    if (isset($_GET['edit'])) {
        $stmt = $connection->prepare(
            'SELECT id, student_id, amount, payment_date, receipt_number,
                    COALESCE(payment_method,"Cash") AS payment_method
             FROM fee_payments WHERE id = ?'
        );
        $stmt->execute([(int) $_GET['edit']]);
        $editPayment = $stmt->fetch();
    }

} catch (Throwable $ex) {
    $savedPaymentId = 0;
    $error = $ex instanceof PDOException && $ex->getCode() === '23000'
        ? 'That receipt number is already in use.'
        : $ex->getMessage();
}

/* ── Data queries ─────────────────────────────────────────────────────── */
$students = $connection->query(
    'SELECT id, admission_number, first_name, last_name, class_name, pay_code
     FROM students ORDER BY last_name, first_name'
)->fetchAll();

$filter = trim($_GET['q'] ?? '');
$pStmt  = $connection->prepare(
    'SELECT fp.id, fp.amount, fp.payment_date, fp.receipt_number,
            COALESCE(fp.payment_method,"Cash") AS payment_method,
            s.admission_number, s.first_name, s.last_name,
            u.full_name AS recorded_by_name
     FROM fee_payments fp
     JOIN students s ON s.id = fp.student_id
     JOIN users    u ON u.id = fp.recorded_by
     WHERE (? = "" OR s.first_name LIKE ? OR s.last_name LIKE ?
            OR s.admission_number LIKE ? OR fp.receipt_number LIKE ?
            OR fp.payment_method LIKE ?)
     ORDER BY fp.payment_date DESC, fp.id DESC'
);
$like = '%' . $filter . '%';
$pStmt->execute([$filter, $like, $like, $like, $like, $like]);
$payments = $pStmt->fetchAll();

$summary = $connection->query(
    'SELECT COUNT(*)  AS transaction_count,
            COALESCE(SUM(amount),0) AS total_collected,
            COALESCE(SUM(CASE WHEN payment_date = CURRENT_DATE THEN amount ELSE 0 END),0) AS today_collected,
            COALESCE(MAX(payment_date),"") AS last_payment,
            COALESCE(SUM(CASE WHEN payment_method="Cash"         THEN amount ELSE 0 END),0) AS by_cash,
            COALESCE(SUM(CASE WHEN payment_method="Mobile Money" THEN amount ELSE 0 END),0) AS by_mobile,
            COALESCE(SUM(CASE WHEN payment_method="Bank"         THEN amount ELSE 0 END),0) AS by_bank,
            COALESCE(SUM(CASE WHEN payment_method="Pay Code"     THEN amount ELSE 0 END),0) AS by_paycode
     FROM fee_payments'
)->fetch() ?: [
    'transaction_count' => 0, 'total_collected' => 0,
    'today_collected'   => 0, 'last_payment'    => '',
    'by_cash' => 0, 'by_mobile' => 0, 'by_bank' => 0, 'by_paycode' => 0,
];

$feeAssessments = $connection->query(
    'SELECT assessment.id, assessment.student_id, assessment.term, assessment.academic_year,
            assessment.amount_due, assessment.assessed_at, students.first_name, students.last_name,
            students.admission_number, users.full_name AS assessed_by_name
     FROM student_fee_assessments assessment
     JOIN students ON students.id = assessment.student_id
     LEFT JOIN users ON users.id = assessment.assessed_by
     ORDER BY assessment.academic_year DESC, assessment.term, students.last_name, students.first_name'
)->fetchAll();

$studentFeeBalances = $connection->query(
    'SELECT students.id AS student_id, students.first_name, students.last_name,
            students.admission_number, students.class_name,
            COALESCE(assessments.total_due, 0) AS total_due,
            COALESCE(payments.total_paid, 0) AS total_paid,
            GREATEST(COALESCE(assessments.total_due, 0) - COALESCE(payments.total_paid, 0), 0) AS outstanding
     FROM students
     LEFT JOIN (
         SELECT student_id, SUM(amount_due) AS total_due
         FROM student_fee_assessments
         GROUP BY student_id
     ) assessments ON assessments.student_id = students.id
     LEFT JOIN (
         SELECT student_id, SUM(amount) AS total_paid
         FROM fee_payments
         GROUP BY student_id
     ) payments ON payments.student_id = students.id
     ORDER BY outstanding DESC, students.last_name, students.first_name'
)->fetchAll();

$firstName  = explode(' ', trim((string) $user['full_name']))[0];
$workspaceHref = 'finance.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Finance | MFIS IMS</title>
    <style>
        :root {
            --navy: #123b6d;
            --navy-dark: #0b2d55;
            --ink: #172b4d;
            --muted: #718096;
            --line: #e7edf4;
            --surface: #ffffff;
            --bg: #f5f7fb;
        }
        * { box-sizing: border-box; }
        body { margin: 0; color: var(--ink); background: var(--bg); font: 14px Arial, sans-serif; }
        a { color: inherit; text-decoration: none; }

        /* ── Layout ── */
        .layout { display: flex; min-height: 100vh; }

        /* ── Sidebar ── */
        .sidebar { width: 245px; flex-shrink: 0; padding: 26px 18px; color: #dce8f7; background: var(--navy-dark); }
        .brand { display: flex; align-items: center; gap: 11px; padding: 0 10px 30px; color: #fff; }
        .brand-mark { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 9px; color: var(--navy-dark); background: #f6c453; font-weight: 700; }
        .brand strong { display: block; font-size: 14px; }
        .brand small { display: block; margin-top: 4px; color: #9fb6d3; font-size: 10px; }
        .nav-label { padding: 0 11px 9px; color: #7f9abb; font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
        .nav a { display: block; margin: 4px 0; padding: 12px 11px; border-radius: 7px; color: #c8d8eb; }
        .nav a.active, .nav a:hover { color: #fff; background: #1d4c82; }
        .nav-icon { display: inline-block; width: 25px; color: #9fbbe0; font-weight: 700; }
        .sidebar-footer { margin-top: 40px; padding: 17px 11px; border-top: 1px solid #28517f; color: #9fb6d3; font-size: 12px; line-height: 1.5; }

        /* ── Main content ── */
        .content { flex: 1; min-width: 0; }
        .topbar { display: flex; align-items: center; justify-content: space-between; min-height: 74px; padding: 0 4%; border-bottom: 1px solid var(--line); background: var(--surface); }
        .topbar p { margin: 4px 0 0; color: var(--muted); font-size: 12px; }
        .profile { display: flex; align-items: center; gap: 10px; }
        .avatar { display: grid; width: 36px; height: 36px; place-items: center; border-radius: 50%; color: #fff; background: var(--navy); font-weight: 700; }
        .profile strong { display: block; font-size: 13px; }
        .profile a { display: block; margin-top: 3px; color: var(--muted); font-size: 11px; }
        main { max-width: 1360px; margin: 0 auto; padding: 32px 4%; }

        /* ── Alerts ── */
        .notice { padding: 12px 16px; margin-bottom: 20px; border-radius: 8px; color: #267153; background: #e8f7f0; font-size: 13px; }
        .notice.error { color: #a33a3a; background: #fff0f0; }

        /* ── Summary stat cards ── */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
        .stat { position: relative; overflow: hidden; padding: 20px; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
        .stat::after { position: absolute; right: -20px; bottom: -30px; width: 90px; height: 90px; border-radius: 50%; background: #f0f5fb; content: ''; }
        .stat-label { position: relative; z-index: 1; color: var(--muted); font-size: 12px; }
        .stat-value { position: relative; z-index: 1; margin-top: 10px; font-size: 26px; font-weight: 700; }
        .stat-sub   { position: relative; z-index: 1; margin-top: 6px; color: #43a581; font-size: 11px; }
        .stat.cash   { border-top: 3px solid #48bb78; }
        .stat.mobile { border-top: 3px solid #ecc94b; }
        .stat.bank   { border-top: 3px solid #4299e1; }
        .stat.paycode{ border-top: 3px solid #9f7aea; }

        /* ── Workspace (form + table) ── */
        .workspace { display: grid; grid-template-columns: 340px 1fr; gap: 20px; }

        /* ── Cards ── */
        .card { padding: 24px; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
        .card h2 { margin: 0 0 18px; font-size: 17px; }

        /* ── Form elements ── */
        label { display: block; margin: 12px 0 5px; color: #52637a; font-size: 12px; font-weight: 700; }
        input, select { width: 100%; padding: 10px 12px; border: 1px solid #d8e0ea; border-radius: 6px; font: inherit; color: var(--ink); background: #fff; }
        input:focus, select:focus { outline: none; border-color: #3b82b8; box-shadow: 0 0 0 3px rgba(59,130,184,.12); }
        input[readonly] { color: #52637a; background: #f5f7fb; cursor: not-allowed; }
        button, .btn { display: inline-block; padding: 10px 16px; border: 0; border-radius: 6px; color: #fff; background: #1763a6; cursor: pointer; font: inherit; text-decoration: none; }
        .btn-secondary, button.secondary { color: #38516e; background: #edf2f7; }
        button.danger  { background: #b94747; }
        .btn-sm { padding: 7px 11px; font-size: 12px; }

        /* ── Table / ledger ── */
        .section-head { display: flex; justify-content: space-between; align-items: center; gap: 15px; margin-bottom: 14px; }
        .section-head h2 { margin: 0; font-size: 17px; }
        .section-head .muted { color: var(--muted); font-size: 12px; }
        .search-bar { display: flex; gap: 8px; margin-bottom: 16px; }
        .search-bar input { flex: 1; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 4px 8px 12px; color: #8290a3; font-size: 11px; text-align: left; text-transform: uppercase; letter-spacing: .4px; }
        td { padding: 11px 8px; border-top: 1px solid #edf1f5; color: #52637a; }
        td strong { display: block; color: var(--ink); }
        td .muted  { color: #8290a3; font-size: 12px; }
        .amount { color: #1763a6; font-weight: 700; text-align: right; }
        .actions { display: flex; gap: 6px; align-items: center; }
        form.inline { display: inline; }
        .empty { padding: 24px; color: var(--muted); text-align: center; }

        /* ── Method badges ── */
        .badge { display: inline-block; padding: 3px 9px; border-radius: 10px; font-size: 11px; font-weight: 700; }
        .badge-cash    { color: #276749; background: #c6f6d5; }
        .badge-mobile  { color: #744210; background: #fefcbf; }
        .badge-bank    { color: #1a365d; background: #bee3f8; }
        .badge-paycode { color: #553c9a; background: #e9d8fd; }

        /* ── Pay-code hint ── */
        #f-paycode-hint { display: none; margin: 6px 0 0; font-size: 12px; color: #553c9a; }
        #f-paycode-preview { display: none; margin-top: 12px; padding: 13px; border: 1px solid #d8c9f4; border-radius: 8px; color: #45346e; background: #faf7ff; font-size: 13px; line-height: 1.6; white-space: pre-line; }
        #f-paycode-preview strong { color: #30204f; }

        /* ── Responsive ── */
        @media (max-width: 1150px) {
            .sidebar { width: 205px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 900px) {
            .workspace { grid-template-columns: 1fr; }
        }
        @media (max-width: 760px) {
            .layout { display: block; }
            .sidebar { width: auto; padding: 16px; }
            .brand { padding-bottom: 16px; }
            .nav { display: flex; gap: 6px; overflow: auto; }
            .nav-label, .sidebar-footer { display: none; }
            .nav a { white-space: nowrap; }
        }
        @media (max-width: 480px) {
            .topbar { align-items: flex-start; flex-direction: column; gap: 12px; padding: 16px 5%; }
            main { padding: 24px 5%; }
            .stats-grid { grid-template-columns: 1fr; }
            .search-bar { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="layout">

    <!-- ── Sidebar ─────────────────────────────────────────── -->
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">MF</div>
            <div>
                <strong>Mandela French</strong>
                <small>Information Management System</small>
            </div>
        </div>
        <div class="nav-label">Main menu</div>
        <nav class="nav">
            <a href="dashboard.php"><span class="nav-icon">01</span>Dashboard</a>
            <a class="active" href="finance.php"><span class="nav-icon">02</span>Finance</a>
            <a href="inventory.php"><span class="nav-icon">03</span>Inventory</a>
            <?php if (in_array($role, ['Administrator'], true)): ?>
            <a href="admin.php"><span class="nav-icon">04</span>Students &amp; Users</a>
            <a href="registrar.php"><span class="nav-icon">05</span>Registrar</a>
            <a href="exams.php"><span class="nav-icon">06</span>Exams &amp; Results</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            Logged in as<br>
            <strong><?= e($user['full_name']) ?></strong><br>
            <span><?= e($role) ?></span>
        </div>
    </aside>

    <!-- ── Content ─────────────────────────────────────────── -->
    <div class="content">
        <header class="topbar">
            <div>
                <strong>Finance &amp; Transactions</strong>
                <p>Record, adjust, and review student fee payments.</p>
            </div>
            <div class="profile">
                <div class="avatar"><?= e(strtoupper(substr($firstName, 0, 1))) ?></div>
                <div>
                    <strong><?= e($user['full_name']) ?></strong>
                    <a href="logout.php">Sign out</a>
                </div>
            </div>
        </header>

        <main>

            <?php if ($message): ?>
            <div class="notice">
                <?= e($message) ?>
                <?php if ($savedPaymentId > 0): ?>
                &nbsp;<a class="btn btn-secondary btn-sm" href="receipt_print.php?id=<?= e((string) $savedPaymentId) ?>" target="_blank" rel="noopener">&#128438; Print receipt</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="notice error"><?= e($error) ?></div>
            <?php endif; ?>

            <!-- ── Summary stats ── -->
            <section class="stats-grid">
                <div class="stat">
                    <div class="stat-label">Total collected</div>
                    <div class="stat-value"><?= e(number_format((float) $summary['total_collected'], 0)) ?></div>
                    <div class="stat-sub"><?= e((string) $summary['transaction_count']) ?> transactions</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Today&rsquo;s collection</div>
                    <div class="stat-value"><?= e(number_format((float) $summary['today_collected'], 0)) ?></div>
                    <div class="stat-sub"><?= e(date('d M Y')) ?></div>
                </div>
                <div class="stat">
                    <div class="stat-label">Total transactions</div>
                    <div class="stat-value"><?= e((string) $summary['transaction_count']) ?></div>
                    <div class="stat-sub">All time</div>
                </div>
                <div class="stat">
                    <div class="stat-label">Last payment date</div>
                    <div class="stat-value" style="font-size:18px;"><?= $summary['last_payment'] ? e((string) $summary['last_payment']) : '&mdash;' ?></div>
                    <div class="stat-sub">Most recent</div>
                </div>
            </section>

            <!-- ── Payment method breakdown ── -->
            <section class="stats-grid">
                <div class="stat cash">
                    <div class="stat-label">&#128181; Cash</div>
                    <div class="stat-value" style="font-size:20px;"><?= e(number_format((float) $summary['by_cash'], 0)) ?></div>
                </div>
                <div class="stat mobile">
                    <div class="stat-label">&#128242; Mobile Money</div>
                    <div class="stat-value" style="font-size:20px;"><?= e(number_format((float) $summary['by_mobile'], 0)) ?></div>
                </div>
                <div class="stat bank">
                    <div class="stat-label">&#127963; Bank</div>
                    <div class="stat-value" style="font-size:20px;"><?= e(number_format((float) $summary['by_bank'], 0)) ?></div>
                </div>
                <div class="stat paycode">
                    <div class="stat-label">&#128274; Pay Code</div>
                    <div class="stat-value" style="font-size:20px;"><?= e(number_format((float) $summary['by_paycode'], 0)) ?></div>
                </div>
            </section>

            <!-- ── Form + Ledger workspace ── -->
            <div class="workspace">

                <!-- Record / Edit form -->
                <section class="card">
                    <h2><?= $editPayment ? 'Adjust payment' : 'Record payment' ?></h2>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="save_payment">
                        <input type="hidden" name="id" value="<?= e((string) ($editPayment['id'] ?? 0)) ?>">

                        <label for="f-student-search">Student</label>
                        <input id="f-student-search" type="text" data-student-search="f-student"
                               placeholder="Enter student full name" autocomplete="off" required>
                        <select name="student_id" id="f-student" hidden>
                            <option value="">Select student</option>
                            <?php foreach ($students as $s): ?>
                            <option value="<?= e((string) $s['id']) ?>"
                                    data-paycode="<?= e((string) ($s['pay_code'] ?? '')) ?>"
                                    data-student-name="<?= e($s['first_name'] . ' ' . $s['last_name']) ?>"
                                    data-admission-number="<?= e($s['admission_number']) ?>"
                                    data-class-name="<?= e($s['class_name'] ?: 'Not assigned') ?>"
                                    <?= (int) ($editPayment['student_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                                <?= e($s['first_name'] . ' ' . $s['last_name'] . ' (' . $s['admission_number'] . ')') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>

                        <label>Amount</label>
                        <input type="number" name="amount" min="0.01" step="0.01" required
                               value="<?= e((string) ($editPayment['amount'] ?? '')) ?>">

                        <label>Payment date</label>
                        <input type="date" name="payment_date" required
                               value="<?= e($editPayment['payment_date'] ?? date('Y-m-d')) ?>">

                        <label>Receipt number</label>
                        <input id="f-receipt" name="receipt_number" required
                               value="<?= e($editPayment['receipt_number'] ?? '') ?>">

                        <label>Payment method</label>
                        <select name="payment_method" id="f-method" required>
                            <?php
                            $currentMethod = ($editPayment['payment_method'] ?? null) ?: 'Cash';
                            foreach (['Cash', 'Mobile Money', 'Bank', 'Pay Code'] as $m):
                            ?>
                            <option value="<?= e($m) ?>" <?= $currentMethod === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <p id="f-paycode-hint">Verify the student and school particulars below before confirming a payment against this code. The pay code is the payment reference; this form records a payment after receipt and does not initiate a bank or mobile-money transfer.</p>
                        <div id="f-paycode-preview" data-school="<?= e($schoolName) ?>" role="status" aria-live="polite"></div>

                        <br>
                        <button type="submit" style="width:100%;margin-top:8px;">
                            <?= $editPayment ? 'Save adjustment' : 'Record payment' ?>
                        </button>
                        <?php if ($editPayment): ?>
                        <a class="btn btn-secondary" href="finance.php" style="display:block;margin-top:8px;text-align:center;">Cancel</a>
                        <?php endif; ?>
                    </form>
                </section>

                <!-- Transaction ledger -->
                <section class="card">
                    <div class="section-head">
                        <h2>Transaction ledger</h2>
                        <span class="muted"><?= count($payments) ?> record<?= count($payments) !== 1 ? 's' : '' ?></span>
                    </div>

                    <form class="search-bar" method="get">
                        <input name="q" placeholder="Search name, admission no., receipt, or method…" value="<?= e($filter) ?>">
                        <button type="submit">Search</button>
                        <?php if ($filter): ?>
                        <a class="btn btn-secondary" href="finance.php">Clear</a>
                        <?php endif; ?>
                    </form>

                    <?php if ($payments): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Date</th>
                                    <th>Receipt</th>
                                    <th>Method</th>
                                    <th>Recorded by</th>
                                    <th style="text-align:right;">Amount</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($payments as $p): ?>
                            <tr>
                                <td>
                                    <strong><?= e($p['first_name'] . ' ' . $p['last_name']) ?></strong>
                                    <span class="muted"><?= e($p['admission_number']) ?></span>
                                </td>
                                <td><?= e($p['payment_date']) ?></td>
                                <td><?= e($p['receipt_number']) ?></td>
                                <td>
                                    <?php
                                    $badgeClass = match($p['payment_method']) {
                                        'Cash'         => 'badge-cash',
                                        'Mobile Money' => 'badge-mobile',
                                        'Bank'         => 'badge-bank',
                                        'Pay Code'     => 'badge-paycode',
                                        default        => 'badge-cash',
                                    };
                                    ?>
                                    <span class="badge <?= e($badgeClass) ?>"><?= e($p['payment_method']) ?></span>
                                </td>
                                <td><?= e($p['recorded_by_name']) ?></td>
                                <td class="amount"><?= e(number_format((float) $p['amount'], 0)) ?></td>
                                <td>
                                    <div class="actions">
                                        <a class="btn btn-secondary btn-sm" href="receipt_print.php?id=<?= e((string) $p['id']) ?>" target="_blank" rel="noopener">Print</a>
                                        <a class="btn btn-secondary btn-sm" href="?edit=<?= e((string) $p['id']) ?>">Edit</a>
                                        <form class="inline" method="post" onsubmit="return confirm('Delete this payment transaction?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete_payment">
                                            <input type="hidden" name="id" value="<?= e((string) $p['id']) ?>">
                                            <button class="danger btn-sm" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty">No transactions match your search.</div>
                    <?php endif; ?>
                </section>

            </div><!-- /.workspace -->

            <section class="card" style="margin-top:20px;">
                <div class="section-head">
                    <div>
                        <h2>Student fee assessments</h2>
                        <span class="muted">Assessments are recorded per student, term, and academic year. Saving an existing period updates its assessed amount.</span>
                    </div>
                </div>
                <div class="workspace">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="save_fee_assessment">

                        <label for="assessment-student-search">Student</label>
                        <input id="assessment-student-search" type="text"
                               data-student-search="assessment-student"
                               placeholder="Enter student full name" autocomplete="off" required>
                        <select name="student_id" id="assessment-student" hidden>
                            <option value="">Select student</option>
                            <?php foreach ($students as $s): ?>
                            <option value="<?= e((string) $s['id']) ?>"
                                    data-student-name="<?= e($s['first_name'] . ' ' . $s['last_name']) ?>"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label>Term</label>
                        <input name="term" maxlength="20" placeholder="Term 1" required>

                        <label>Academic year</label>
                        <input name="academic_year" pattern="\d{4}/\d{4}" placeholder="2026/2027" required>

                        <label>Fee amount due</label>
                        <input type="number" name="amount_due" min="0.01" step="0.01" required>

                        <button type="submit" style="width:100%;margin-top:16px;">Save fee assessment</button>
                    </form>

                    <div class="table-wrap">
                        <?php if ($studentFeeBalances): ?>
                        <table>
                            <thead>
                                <tr><th>Student</th><th>Class</th><th>Total fees due</th><th>Total paid</th><th>Outstanding balance</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($studentFeeBalances as $balance): ?>
                            <tr>
                                <td><strong><?= e($balance['first_name'] . ' ' . $balance['last_name']) ?></strong><span class="muted"><?= e($balance['admission_number']) ?></span></td>
                                <td><?= e($balance['class_name'] ?: 'Unassigned') ?></td>
                                <td><?= e(number_format((float) $balance['total_due'], 2)) ?></td>
                                <td><?= e(number_format((float) $balance['total_paid'], 2)) ?></td>
                                <td class="amount"><?= e(number_format((float) $balance['outstanding'], 2)) ?></td>
                                <td>
                                    <?php if ((float) $balance['total_due'] <= 0): ?>
                                    <span class="badge">No fees assessed</span>
                                    <?php elseif ((float) $balance['outstanding'] > 0): ?>
                                    <span class="badge badge-mobile">Balance due</span>
                                    <?php else: ?>
                                    <span class="badge badge-cash">Paid</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <div class="empty">No student records are available.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <p style="margin:14px 0 0;color:var(--muted);font-size:12px;">Outstanding is calculated as all recorded fees due across terms minus all payments recorded for that student, with balances below zero shown as zero. Record an assessment to show an amount due.</p>
                <?php if ($feeAssessments): ?>
                <details style="margin-top:16px;">
                    <summary style="cursor:pointer;font-weight:700;">View fee assessments by term</summary>
                    <div class="table-wrap" style="margin-top:12px;">
                        <table>
                            <thead><tr><th>Student</th><th>Term / Year</th><th>Assessed amount</th><th>Assessed by</th><th>Updated</th></tr></thead>
                            <tbody>
                            <?php foreach ($feeAssessments as $assessment): ?>
                            <tr>
                                <td><strong><?= e($assessment['first_name'] . ' ' . $assessment['last_name']) ?></strong><span class="muted"><?= e($assessment['admission_number']) ?></span></td>
                                <td><?= e($assessment['term'] . ' / ' . $assessment['academic_year']) ?></td>
                                <td><?= e(number_format((float) $assessment['amount_due'], 2)) ?></td>
                                <td><?= e($assessment['assessed_by_name'] ?: 'Account removed') ?></td>
                                <td><?= e($assessment['assessed_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </details>
                <?php endif; ?>
            </section>

            <!-- ── Pay codes table ── -->
            <section class="card" style="margin-top:20px;">
                <h2>Student pay codes</h2>
                <p style="margin:0 0 18px;color:var(--muted);font-size:13px;">
                    Each student is assigned a unique pay code at admission. Use <em>Regenerate</em> only if a code needs to be reissued.
                </p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Admission no.</th>
                                <th>Class</th>
                                <th>Pay code</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($students as $s): ?>
                        <tr>
                            <td><strong><?= e($s['first_name'] . ' ' . $s['last_name']) ?></strong></td>
                            <td><?= e($s['admission_number']) ?></td>
                            <td><?= e($s['class_name'] ?: 'Not assigned') ?></td>
                            <td>
                                <?php if ($s['pay_code']): ?>
                                <span style="font-family:monospace;font-weight:700;color:#1763a6;"><?= e($s['pay_code']) ?></span>
                                <?php else: ?>
                                <span class="muted">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form class="inline" method="post"
                                      onsubmit="return confirm('Regenerate pay code for <?= e($s['first_name'] . ' ' . $s['last_name']) ?>? The old code will stop working immediately.');">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="regenerate_pay_code">
                                    <input type="hidden" name="student_id" value="<?= e((string) $s['id']) ?>">
                                    <button class="btn-secondary btn-sm" type="submit">Regenerate</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$students): ?>
                        <tr><td colspan="5" class="empty">No students found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        <?php if ($inventorySummary): ?>
            <section class="card" style="margin-top:20px;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px;">
                    <h2 style="margin:0;">Inventory and stores</h2>
                    <a class="btn-secondary btn-sm" href="inventory.php">Open inventory dashboard</a>
                </div>

                <div class="stats-grid" style="margin-bottom:18px;">
                    <div class="stat bank"><div class="stat-label">Active items</div><div class="stat-value"><?= e((string) $inventorySummary['item_count']) ?></div><div class="stat-sub">Stock records on file</div></div>
                    <div class="stat paycode"><div class="stat-label">Units in stock</div><div class="stat-value"><?= e(number_format((float) $inventorySummary['units'], 0)) ?></div><div class="stat-sub">Total on-hand quantity</div></div>
                    <div class="stat mobile"><div class="stat-label">Stock value</div><div class="stat-value"><?= e(number_format((float) $inventorySummary['stock_value'], 0)) ?></div><div class="stat-sub">Quantity &times; unit cost</div></div>
                    <div class="stat cash"><div class="stat-label">Needs reorder</div><div class="stat-value"><?= e((string) $inventorySummary['reorder_count']) ?></div><div class="stat-sub"><?= e((string) $inventorySummary['out_count']) ?> out of stock</div></div>
                </div>

                <?php if ($inventoryAlerts): ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Code</th>
                                <th>On hand</th>
                                <th>Reorder at</th>
                                <th>Condition</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($inventoryAlerts as $alert): ?>
                        <tr>
                            <td><strong><?= e($alert['item_name']) ?></strong></td>
                            <td><?= e($alert['item_code']) ?></td>
                            <td><?= e((string) $alert['quantity_on_hand']) ?> <?= e($alert['unit']) ?></td>
                            <td><?= e((string) $alert['reorder_level']) ?></td>
                            <td>
                                <span class="badge <?= (int) $alert['quantity_on_hand'] <= 0 ? 'badge-cash' : 'badge-mobile' ?>">
                                    <?= (int) $alert['quantity_on_hand'] <= 0 ? 'Out of stock' : 'Low stock' ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty">Every active inventory item is above its reorder level.</div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

        </main>
    </div><!-- /.content -->
</div><!-- /.layout -->

<script src="assets/student-search.js" defer></script>
<script>
(function () {
    var student = document.getElementById('f-student');
    var receipt = document.getElementById('f-receipt');
    var method  = document.getElementById('f-method');
    var hint    = document.getElementById('f-paycode-hint');
    var preview = document.getElementById('f-paycode-preview');

    function getPayCode() {
        var opt = student.options[student.selectedIndex];
        return opt ? (opt.dataset.paycode || '') : '';
    }

    function updatePayCodePreview() {
        var isPayCode = method.value === 'Pay Code';
        var opt = student.options[student.selectedIndex];
        if (!isPayCode || !opt || !opt.value) {
            preview.style.display = 'none';
            preview.textContent = '';
            return;
        }

        var code = opt.dataset.paycode || '';
        preview.style.display = 'block';
        preview.textContent = code
            ? 'Verify these payment details before confirming:\nSchool: ' + preview.dataset.school
                + '\nStudent: ' + opt.dataset.studentName
                + '\nClass: ' + opt.dataset.className
                + '\nAdmission number: ' + opt.dataset.admissionNumber
                + '\nPay code / reference: ' + code
            : 'No pay code is assigned to this student. Do not accept a payment against this student until the Accounts Office assigns a code.';
    }

    function syncPayCode(isInitial) {
        var isPayCode = method.value === 'Pay Code';
        var code = getPayCode();
        if (isPayCode) {
            if (!isInitial) { receipt.value = code; }
            receipt.readOnly = true;
            hint.style.display = 'block';
        } else {
            receipt.readOnly = false;
            hint.style.display = 'none';
            if (!isInitial && receipt.value === code && code !== '') {
                receipt.value = '';
            }
        }
        updatePayCodePreview();
    }

    method.addEventListener('change', function () { syncPayCode(false); });
    student.addEventListener('change', function () { syncPayCode(false); });
    syncPayCode(true);
}());
</script>
</body>
</html>
