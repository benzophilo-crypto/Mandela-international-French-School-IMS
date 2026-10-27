<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$role = $_SESSION['user']['role'] ?? '';
if (!in_array($role, ['Accountant', 'Administrator'], true)) {
    http_response_code(403);
    exit('Accountant or administrator access is required to print payment receipts.');
}

$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$paymentId || $paymentId < 1) {
    http_response_code(400);
    exit('A valid payment transaction is required.');
}

$statement = db()->prepare(
    'SELECT fee_payments.id, fee_payments.amount, fee_payments.payment_date,
            fee_payments.receipt_number,
            COALESCE(fee_payments.payment_method, "Cash") AS payment_method,
            users.full_name AS recorded_by_name,
            students.admission_number, students.first_name, students.last_name,
            students.class_name
     FROM fee_payments
     JOIN students ON students.id = fee_payments.student_id
     JOIN users ON users.id = fee_payments.recorded_by
     WHERE fee_payments.id = ?
     LIMIT 1'
);
$statement->execute([$paymentId]);
$payment = $statement->fetch();

if (!$payment) {
    http_response_code(404);
    exit('Payment receipt not found.');
}

$amount = number_format((float) $payment['amount'], 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?= e($payment['receipt_number']) ?> | MIFS IMS</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px; color: #172b4d; background: #f5f7fb; font: 14px Arial, sans-serif; }
        .receipt { width: min(100%, 760px); margin: 0 auto; padding: 38px; border: 1px solid #dce4ee; background: #fff; }
        .school { padding-bottom: 20px; border-bottom: 3px solid #0b2d55; text-align: center; }
        .school h1 { margin: 0; color: #0b2d55; font-size: 23px; }
        .school p { margin: 7px 0 0; color: #718096; }
        .receipt-heading { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin: 24px 0; }
        .receipt-heading h2 { margin: 0; font-size: 20px; text-transform: uppercase; }
        .receipt-number { color: #52637a; text-align: right; }
        .details { width: 100%; border-collapse: collapse; }
        .details th, .details td { padding: 13px 10px; border-bottom: 1px solid #edf1f5; text-align: left; }
        .details th { width: 38%; color: #718096; font-weight: 600; }
        .paid { margin-top: 24px; padding: 18px; border: 1px solid #d4eadf; border-radius: 7px; color: #267153; background: #f0faf5; }
        .paid small { display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .7px; }
        .paid strong { font-size: 26px; }
        .footer { margin-top: 36px; padding-top: 16px; border-top: 1px dashed #cbd5e1; color: #718096; line-height: 1.6; }
        .controls { width: min(100%, 760px); margin: 16px auto; text-align: right; }
        button, .back { display: inline-block; padding: 10px 15px; border: 0; border-radius: 6px; color: #fff; background: #1763a6; cursor: pointer; font: inherit; text-decoration: none; }
        .back { color: #38516e; background: #edf2f7; }
        @media print {
            @page { size: A4; margin: 15mm; }
            body { padding: 0; background: #fff; }
            .receipt { width: 100%; padding: 20px; border: 0; }
            .controls { display: none; }
        }
        @media (max-width: 560px) {
            body { padding: 12px; }
            .receipt { padding: 22px; }
            .receipt-heading { align-items: flex-start; flex-direction: column; }
            .receipt-number { text-align: left; }
        }
    </style>
</head>
<body>
    <main class="receipt">
        <header class="school">
            <h1>Mandela International French School</h1>
            <p>Official school fee payment receipt</p>
        </header>
        <div class="receipt-heading">
            <h2>Payment receipt</h2>
            <div class="receipt-number">Receipt no.<br><strong><?= e($payment['receipt_number']) ?></strong></div>
        </div>
        <table class="details">
            <tbody>
                <tr><th>Student</th><td><?= e($payment['first_name'] . ' ' . $payment['last_name']) ?></td></tr>
                <tr><th>Admission number</th><td><?= e($payment['admission_number']) ?></td></tr>
                <tr><th>Class</th><td><?= e($payment['class_name'] ?: 'Not assigned') ?></td></tr>
                <tr><th>Payment date</th><td><?= e($payment['payment_date']) ?></td></tr>
                <tr><th>Payment method</th><td><?= e($payment['payment_method']) ?></td></tr>
                <tr><th>Recorded by</th><td><?= e($payment['recorded_by_name']) ?></td></tr>
            </tbody>
        </table>
        <section class="paid">
            <small>Payment received</small>
            <strong><?= e($amount) ?></strong>
        </section>
        <footer class="footer">
            <strong>Thank you.</strong><br>
            This receipt confirms the payment recorded in the school finance ledger.
        </footer>
    </main>
    <div class="controls">
        <a class="back" href="finance.php">Back to finance</a>
        <button type="button" onclick="window.print()">Print receipt</button>
    </div>
</body>
</html>
