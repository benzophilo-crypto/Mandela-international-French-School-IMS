<?php

require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['user'];
if (($user['role'] ?? '') !== 'Parent') {
    header('Location: dashboard.php');
    exit;
}

$connection = db();
ensure_exam_tables();
$connection->exec(
    "CREATE TABLE IF NOT EXISTS announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(160) NOT NULL,
        message TEXT NOT NULL,
        audience ENUM('All', 'Parents', 'Students') NOT NULL DEFAULT 'All',
        published_at DATETIME NOT NULL,
        is_published TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);
$account = $connection->prepare(
    'SELECT users.student_id, students.admission_number, students.first_name, students.last_name,
            students.class_name, students.guardian_name, students.guardian_phone
     FROM users
     LEFT JOIN students ON students.id = users.student_id
     WHERE users.id = ? LIMIT 1'
);
$account->execute([(int) $user['id']]);
$student = $account->fetch();

if (!$student || !$student['student_id']) {
    http_response_code(409);
    exit('Your account is not linked to a student record. Please contact the ICT administrator.');
}

$attendance = $connection->prepare(
    'SELECT attendance_date, attendance_status
     FROM attendance WHERE student_id = ? ORDER BY attendance_date DESC, id DESC'
);
$attendance->execute([(int) $student['student_id']]);
$attendanceRows = $attendance->fetchAll();

$payments = $connection->prepare(
    'SELECT amount, payment_date, receipt_number
     FROM fee_payments WHERE student_id = ? ORDER BY payment_date DESC, id DESC'
);
$payments->execute([(int) $student['student_id']]);
$paymentRows = $payments->fetchAll();

$attendanceSummary = ['Present' => 0, 'Absent' => 0, 'Late' => 0];
foreach ($attendanceRows as $row) {
    if (isset($attendanceSummary[$row['attendance_status']])) {
        $attendanceSummary[$row['attendance_status']]++;
    }
}
$attendanceTotal = array_sum($attendanceSummary);
$attendanceRate = $attendanceTotal > 0
    ? round(($attendanceSummary['Present'] / $attendanceTotal) * 100)
    : 0;
$paymentTotal = array_sum(array_map(static function (array $payment): float {
    return (float) $payment['amount'];
}, $paymentRows));
$announcements = $connection->query(
    "SELECT title, message, published_at
     FROM announcements
     WHERE is_published = 1 AND audience IN ('All', 'Parents')
     ORDER BY published_at DESC LIMIT 8"
)->fetchAll();
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Student Portal | MIFS IMS</title>
    <style>
        * { box-sizing: border-box; } body { margin: 0; color: #172b4d; background: #f5f7fb; font: 14px Arial, sans-serif; }
        header { display: flex; justify-content: space-between; align-items: center; padding: 20px 5%; color: #fff; background: #0b2d55; } header a { color: #dce8f7; text-decoration: none; }
        main { max-width: 1250px; margin: auto; padding: 30px 5%; } h1 { margin: 0 0 7px; } h2 { font-size: 18px; } .intro { margin: 0 0 25px; color: #718096; }
        .hero, .card, .stat { padding: 20px; border: 1px solid #e7edf4; border-radius: 10px; background: #fff; } .hero { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .student-code { padding: 9px 12px; border-radius: 15px; color: #1763a6; background: #eaf3fb; font-size: 12px; font-weight: 700; } .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; } .stat-label { color: #718096; font-size: 12px; } .stat-value { margin-top: 8px; font-size: 24px; font-weight: 700; }
        .columns { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; } .card { margin-bottom: 20px; } .profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; } .profile-grid div { padding: 12px; border-radius: 7px; background: #f7f9fc; } .profile-grid small { display: block; margin-bottom: 5px; color: #8290a3; } .profile-grid strong { font-size: 13px; }
        table { width: 100%; border-collapse: collapse; } th { padding: 3px 0 11px; color: #8290a3; font-size: 11px; text-align: left; text-transform: uppercase; } td { padding: 12px 0; border-top: 1px solid #edf1f5; color: #52637a; } td strong { display: block; color: #172b4d; } .status { display: inline-block; padding: 5px 8px; border-radius: 12px; font-size: 11px; } .Present { color: #267153; background: #e8f7f0; } .Absent { color: #a33a3a; background: #fff0f0; } .Late { color: #94621f; background: #fff6df; } .amount { color: #1763a6; font-weight: 700; text-align: right; } .empty { padding: 18px; color: #8290a3; text-align: center; }
        .notice { padding: 14px; border-left: 4px solid #4d91c7; color: #4b627d; background: #eef6fc; line-height: 1.5; } .announcement { padding: 15px 0; border-top: 1px solid #edf1f5; } .announcement:first-child { padding-top: 0; border-top: 0; } .announcement h3 { margin: 0 0 6px; font-size: 14px; } .announcement p { margin: 0 0 7px; color: #52637a; line-height: 1.55; } .announcement small { color: #8290a3; } @media (max-width: 800px) { .stats { grid-template-columns: repeat(2, 1fr); } .columns { grid-template-columns: 1fr; } } @media (max-width: 500px) { header, .hero { align-items: flex-start; flex-direction: column; gap: 12px; } main { padding: 24px 5%; } .stats, .profile-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header><strong>MIFS IMS &middot; Student Portal</strong><nav><a href="dashboard.php">Dashboard</a> &nbsp; | &nbsp; <a href="logout.php">Sign out</a></nav></header>
<main>
    <section class="hero"><div><h1>Welcome, <?= e($student['first_name']) ?>.</h1><p class="intro">Your personal school information, attendance, and payment history.</p></div><div class="student-code"><?= e($student['admission_number']) ?></div></section>
    <section class="card"><h2>School communications</h2><?php if ($announcements): ?><?php foreach ($announcements as $announcement): ?><article class="announcement"><h3><?= e($announcement['title']) ?></h3><p><?= nl2br(e($announcement['message'])) ?></p><small><?= e($announcement['published_at']) ?></small></article><?php endforeach; ?><?php else: ?><div class="empty">There are no new school communications at this time.</div><?php endif; ?></section>
    <section class="stats">
        <div class="stat"><div class="stat-label">Attendance rate</div><div class="stat-value"><?= e((string) $attendanceRate) ?>%</div></div>
        <div class="stat"><div class="stat-label">Days present</div><div class="stat-value"><?= e((string) $attendanceSummary['Present']) ?></div></div>
        <div class="stat"><div class="stat-label">Attendance records</div><div class="stat-value"><?= e((string) $attendanceTotal) ?></div></div>
        <div class="stat"><div class="stat-label">Payments made</div><div class="stat-value"><?= e((string) count($paymentRows)) ?></div></div>
    </section>
    <div class="columns">
        <section class="card"><h2>My profile</h2><div class="profile-grid"><div><small>Full name</small><strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong></div><div><small>Class</small><strong><?= e($student['class_name'] ?: 'Not assigned') ?></strong></div><div><small>Guardian</small><strong><?= e($student['guardian_name'] ?: 'Not provided') ?></strong></div><div><small>Guardian phone</small><strong><?= e($student['guardian_phone'] ?: 'Not provided') ?></strong></div></div></section>
        <section class="card"><h2>Fee status</h2><div class="profile-grid"><div><small>Total paid</small><strong><?= e(number_format($paymentTotal, 0)) ?></strong></div><div><small>Payment records</small><strong><?= e((string) count($paymentRows)) ?></strong></div></div><p class="notice">Payments are recorded and adjusted by the Accounts Office. Contact the Accounts Officer if a payment or receipt needs correction.</p></section>
    </div>
    <div class="columns">
        <section class="card"><h2>Daily attendance</h2><?php if ($attendanceRows): ?><table><thead><tr><th>Date</th><th>Status</th></tr></thead><tbody><?php foreach ($attendanceRows as $row): ?><tr><td><?= e($row['attendance_date']) ?></td><td><span class="status <?= e($row['attendance_status']) ?>"><?= e($row['attendance_status']) ?></span></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="empty">No attendance has been recorded yet.</div><?php endif; ?></section>
        <section class="card"><h2>Payment history</h2><?php if ($paymentRows): ?><table><thead><tr><th>Date</th><th>Receipt</th><th>Amount</th></tr></thead><tbody><?php foreach ($paymentRows as $payment): ?><tr><td><?= e($payment['payment_date']) ?></td><td><?= e($payment['receipt_number']) ?></td><td class="amount"><?= e(number_format((float) $payment['amount'], 0)) ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="empty">No payment has been recorded yet.</div><?php endif; ?></section>
    </div>
    <section class="card"><h2>Academic results</h2><?php if ($publishedResults): ?><p class="notice">The Registrar has released these examination reports for your child.</p><table><thead><tr><th>Examination</th><th>Term</th><th>Total</th><th>Average</th><th>Report</th></tr></thead><tbody><?php foreach ($publishedResults as $result): $average = (float) $result['maximum_marks'] > 0 ? ((float) $result['total_marks'] / (float) $result['maximum_marks']) * 100 : 0; ?><tr><td><?= e($result['title']) ?></td><td><?= e($result['term'] . ' ' . $result['academic_year']) ?></td><td><?= e(number_format((float) $result['total_marks'], 2) . ' / ' . number_format((float) $result['maximum_marks'], 2)) ?></td><td><?= e(number_format($average, 2)) ?>%</td><td><a href="results.php">View full report</a></td></tr><?php endforeach; ?></tbody></table><?php else: ?><p class="notice">Published results for your child's student record will appear here.</p><?php endif; ?></section>
    <section class="card"><h2>What you can access</h2><p class="intro">This portal is read-only for your personal records. You can view your profile, daily attendance, fee payments, receipts, and current attendance summary. Teachers record attendance, while the Accounts Office records and adjusts payments.</p></section>
</main>
</body>
</html>
