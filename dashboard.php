<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$user = $_SESSION['user'];
$role = $user['role'] ?? '';
$canViewFinance = $role !== 'Registrar' && has_system_permission('finance.access');
$schoolName = system_setting('school_name', 'Mandela International French School');
if ($role === 'Librarian') {
    header('Location: librarian.php');
    exit;
}
if ($role === 'Head Teacher/Principal') {
    header('Location: head_teacher.php');
    exit;
}
if ($role === 'Deputy Headteacher') {
    header('Location: deputy_headteacher.php');
    exit;
}
$connection = db();

$modules = [
    'Administrator' => [
        ['title' => 'Manage Users', 'description' => 'Create accounts and assign staff roles.', 'class' => 'blue'],
        ['title' => 'Manage Roles', 'description' => 'Review responsibilities and access levels.', 'class' => 'purple'],
        ['title' => 'Student Records', 'description' => 'View and maintain school records.', 'class' => 'green'],
        ['title' => 'Teachers & Subjects', 'description' => 'Create teacher accounts and assign school subjects and classes.', 'class' => 'blue'],
        ['title' => 'Exams & Results', 'description' => 'Create exams, validate marks, and review performance.', 'class' => 'orange'],
        ['title' => 'System Settings', 'description' => 'Configure school identity and academic details.', 'class' => 'blue'],
        ['title' => 'System Logs', 'description' => 'Review recent sign-ins and administrator actions.', 'class' => 'purple'],
        ['title' => 'Database Backup', 'description' => 'Download a secure SQL backup of school records.', 'class' => 'green']
    ],
    'Registrar' => [
        ['title' => 'Student Registration', 'description' => 'Register new students and update profiles.', 'class' => 'blue'],
        ['title' => 'Admissions', 'description' => 'Track applications and enrolment.', 'class' => 'purple'],
        ['title' => 'Class Assignment', 'description' => 'Organise students by class.', 'class' => 'green'],
        ['title' => 'Exams & Results', 'description' => 'Manage exam periods, subject allocation, and full result processing.', 'class' => 'orange']
    ],
    'Teacher' => [
        ['title' => 'My Classes', 'description' => 'See your assigned classes and students.', 'class' => 'blue'],
        ['title' => 'Attendance', 'description' => 'Record and review daily attendance.', 'class' => 'purple'],
        ['title' => 'Student Progress', 'description' => 'Follow learning progress over time.', 'class' => 'green'],
        ['title' => 'Student Records', 'description' => 'Review student records for your classes.', 'class' => 'orange']
    ],
    'Librarian' => [
        ['title' => 'Library Catalog', 'description' => 'Manage the school book catalogue.', 'class' => 'blue'],
        ['title' => 'Book Lending', 'description' => 'Record books borrowed by students and staff.', 'class' => 'purple'],
        ['title' => 'Returns', 'description' => 'Track returned books and outstanding loans.', 'class' => 'green'],
        ['title' => 'Library Reports', 'description' => 'Review library stock and lending activity.', 'class' => 'orange']
    ],
    'Accountant' => [
        ['title' => 'Fee Payments', 'description' => 'Record and review fee transactions.', 'class' => 'blue'],
        ['title' => 'Receipts', 'description' => 'Find and manage payment receipts.', 'class' => 'purple'],
        ['title' => 'Invoices', 'description' => 'Track outstanding school fees.', 'class' => 'green'],
        ['title' => 'Financial Reports', 'description' => 'Review payment summaries.', 'class' => 'orange']
    ],
    'Parent' => [
        ['title' => 'My Profile', 'description' => 'View your personal information.', 'class' => 'blue'],
        ['title' => 'Attendance', 'description' => 'Review attendance history.', 'class' => 'purple'],
        ['title' => 'Results', 'description' => 'View academic results and progress.', 'class' => 'green'],
        ['title' => 'Fee Status', 'description' => 'Check current fee payments.', 'class' => 'orange']
    ],
    'Student' => [
        ['title' => 'Term Registration', 'description' => 'Submit your reporting and enrolment details.', 'class' => 'blue'],
        ['title' => 'My Attendance', 'description' => 'Review daily attendance and status.', 'class' => 'purple'],
        ['title' => 'My Payments', 'description' => 'View payment history and receipts.', 'class' => 'green'],
        ['title' => 'Results', 'description' => 'View your published examination results.', 'class' => 'orange']
    ]
];

$counts = [
    'students' => (int) $connection->query('SELECT COUNT(*) FROM students')->fetchColumn(),
    'users' => (int) $connection->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'parents' => (int) $connection->query(
        "SELECT COUNT(*) FROM users JOIN roles ON roles.id = users.role_id WHERE roles.role_name = 'Parent'"
    )->fetchColumn(),
    'roles' => (int) $connection->query('SELECT COUNT(*) FROM roles')->fetchColumn(),
    'enrollments' => (int) $connection->query('SELECT COUNT(*) FROM enrollments')->fetchColumn(),
    'pending_enrollments' => (int) $connection->query(
        "SELECT COUNT(*) FROM enrollments WHERE registration_status = 'Submitted'"
    )->fetchColumn(),
    'announcements' => (int) $connection->query(
        'SELECT COUNT(*) FROM announcements WHERE is_published = 1'
    )->fetchColumn(),
    'attendance' => (int) $connection->query(
        "SELECT COUNT(*) FROM attendance WHERE attendance_date = CURRENT_DATE"
    )->fetchColumn()
];

$recentStudents = $connection->query(
    'SELECT admission_number, first_name, last_name, class_name, created_at
     FROM students ORDER BY created_at DESC, id DESC LIMIT 5'
)->fetchAll();

$recentPayments = [];
$paymentTotal = 0.0;
if ($canViewFinance) {
    $counts['payments'] = (int) $connection->query('SELECT COUNT(*) FROM fee_payments')->fetchColumn();
    $recentPayments = $connection->query(
        'SELECT fee_payments.amount, fee_payments.payment_date, fee_payments.receipt_number,
                students.first_name, students.last_name
         FROM fee_payments
         JOIN students ON students.id = fee_payments.student_id
         ORDER BY fee_payments.payment_date DESC, fee_payments.id DESC LIMIT 5'
    )->fetchAll();
    $paymentTotal = (float) $connection->query('SELECT COALESCE(SUM(amount), 0) FROM fee_payments')->fetchColumn();
}

$parentRows = $connection->query(
    "SELECT users.full_name, users.email, students.first_name, students.last_name,
            students.admission_number, students.guardian_phone
     FROM users
     JOIN roles ON roles.id = users.role_id
     LEFT JOIN students ON students.id = users.student_id
     WHERE roles.role_name = 'Parent'
     ORDER BY users.full_name
     LIMIT 5"
)->fetchAll();

$enrollmentRows = $connection->query(
    'SELECT enrollments.term, enrollments.academic_year, enrollments.report_date,
            enrollments.registration_status, students.first_name, students.last_name
     FROM enrollments
     JOIN students ON students.id = enrollments.student_id
     ORDER BY enrollments.created_at DESC LIMIT 5'
)->fetchAll();

$announcementRows = $connection->query(
    'SELECT title, audience, published_at
     FROM announcements
     WHERE is_published = 1
     ORDER BY published_at DESC LIMIT 5'
)->fetchAll();

$firstName = trim((string) $user['full_name']);
$firstName = explode(' ', $firstName)[0];
$workspaceHref = [
    'Administrator' => 'admin.php',
    'Registrar' => 'registrar.php',
    'Teacher' => 'teacher.php',
    'Accountant' => 'finance.php',
    'Librarian' => 'librarian.php',
    'Parent' => 'student.php',
    'Student' => 'student_portal.php'
][$role] ?? 'dashboard.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | <?= e($schoolName) ?> IMS</title>
    <style>
        :root {
            --navy: #123b6d;
            --navy-dark: #0b2d55;
            --ink: #172b4d;
            --muted: #718096;
            --line: #e7edf4;
            --surface: #ffffff;
            --background: #f5f7fb;
        }
        * { box-sizing: border-box; }
        body { margin: 0; color: var(--ink); background: var(--background); font: 14px Arial, sans-serif; }
        a { color: inherit; text-decoration: none; }
        .layout { display: flex; min-height: 100vh; }
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
        .content { flex: 1; min-width: 0; }
        .topbar { display: flex; align-items: center; justify-content: space-between; min-height: 74px; padding: 0 4%; border-bottom: 1px solid var(--line); background: var(--surface); }
        .topbar p { margin: 4px 0 0; color: var(--muted); font-size: 12px; }
        .profile { display: flex; align-items: center; gap: 10px; }
        .avatar { display: grid; width: 36px; height: 36px; place-items: center; border-radius: 50%; color: #fff; background: var(--navy); font-weight: 700; }
        .profile strong { display: block; font-size: 13px; }
        .profile a { display: block; margin-top: 3px; color: #718096; font-size: 11px; }
        main { max-width: 1360px; margin: 0 auto; padding: 32px 4%; }
        .welcome { display: flex; align-items: end; justify-content: space-between; gap: 20px; margin-bottom: 27px; }
        h1 { margin: 0; color: var(--ink); font-size: 28px; }
        .welcome p { margin: 8px 0 0; color: var(--muted); }
        .date { padding: 10px 14px; border: 1px solid var(--line); border-radius: 7px; color: #64748b; background: #fff; font-size: 12px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .stat { position: relative; padding: 20px; overflow: hidden; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
        .stat::after { position: absolute; right: -20px; bottom: -30px; width: 90px; height: 90px; border-radius: 50%; background: #f0f5fb; content: ''; }
        .stat-label { position: relative; z-index: 1; color: var(--muted); font-size: 12px; }
        .stat-value { position: relative; z-index: 1; margin-top: 10px; color: var(--ink); font-size: 27px; font-weight: 700; }
        .stat-note { position: relative; z-index: 1; margin-top: 7px; color: #43a581; font-size: 11px; }
        .section-title { display: flex; align-items: center; justify-content: space-between; margin: 0 0 14px; }
        h2 { margin: 0; font-size: 17px; }
        .section-title span { color: var(--muted); font-size: 12px; }
        .panels { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); gap: 20px; }
        .panels-single { grid-template-columns: 1fr; }
        .panel { min-width: 0; padding: 20px; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
        table { width: 100%; border-collapse: collapse; }
        th { padding: 4px 0 12px; color: #97a4b5; font-size: 10px; letter-spacing: .5px; text-align: left; text-transform: uppercase; }
        td { padding: 12px 0; border-top: 1px solid #f0f3f7; color: #42526e; font-size: 12px; }
        td strong { display: block; color: var(--ink); font-size: 13px; }
        .badge { display: inline-block; padding: 5px 8px; border-radius: 12px; color: #367b62; background: #e8f7f0; font-size: 11px; }
        .money { color: var(--navy); font-weight: 700; text-align: right; }
        .modules { margin-top: 28px; }
        .module-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .module { padding: 18px; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
        .module-icon { display: grid; width: 34px; height: 34px; place-items: center; margin-bottom: 13px; border-radius: 8px; color: #fff; font-weight: 700; }
        .module-icon.blue { background: #4181c4; } .module-icon.purple { background: #7968bd; }
        .module-icon.green { background: #55a987; } .module-icon.orange { background: #dd9650; }
        .module h3 { margin: 0 0 7px; font-size: 14px; }
        .module p { min-height: 32px; margin: 0; color: var(--muted); font-size: 12px; line-height: 1.45; }
        .empty { padding: 18px 0; color: var(--muted); text-align: center; }
        @media (max-width: 1150px) { .sidebar { width: 205px; } .stats, .module-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 760px) { .layout { display: block; } .sidebar { width: auto; padding: 16px; } .brand { padding-bottom: 16px; } .nav { display: flex; gap: 6px; overflow: auto; } .nav-label, .sidebar-footer { display: none; } .nav a { white-space: nowrap; } .panels { grid-template-columns: 1fr; } }
        @media (max-width: 480px) { .topbar, .welcome { align-items: flex-start; flex-direction: column; } .topbar { gap: 12px; padding: 16px 5%; } main { padding: 24px 5%; } .stats, .module-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark">MF</div>
            <div><strong><?= e($schoolName) ?></strong><small>Information Management System</small></div>
        </div>
        <div class="nav-label">Main menu</div>
        <nav class="nav">
            <a class="active" href="dashboard.php"><span class="nav-icon">01</span>Dashboard</a>
            <?php if ($role === 'Accountant'): ?>
            <a href="finance.php"><span class="nav-icon">02</span>Finance Workspace</a>
            <a href="inventory.php"><span class="nav-icon">03</span>Inventory</a>
            <a href="dashboard.php#reports"><span class="nav-icon">04</span>Reports</a>
            <?php else: ?>
            <?php if ($role === 'Student'): ?><a href="student_portal.php"><span class="nav-icon">02</span>My Student Portal</a><?php elseif ($role === 'Parent'): ?><a href="student.php"><span class="nav-icon">02</span>My Child</a><?php elseif ($role === 'Teacher'): ?><a href="teacher.php"><span class="nav-icon">02</span>Teacher Workspace</a><?php elseif ($role === 'Registrar'): ?><a href="registrar.php"><span class="nav-icon">02</span>Registrar Workspace</a><?php elseif ($role === 'Librarian'): ?><a href="librarian.php"><span class="nav-icon">02</span>Library Workspace</a><?php elseif ($role === 'Administrator'): ?><a href="admin.php"><span class="nav-icon">02</span>Students and Users</a><?php endif; ?>
            <?php if (in_array($role, ['Administrator', 'Registrar'], true)): ?><a href="exams.php"><span class="nav-icon">03</span>Exams &amp; Results</a><?php elseif (in_array($role, ['Parent', 'Student'], true)): ?><a href="results.php"><span class="nav-icon">03</span>Published Results</a><?php else: ?><a href="<?= e($workspaceHref) ?>"><span class="nav-icon">03</span>Attendance</a><?php endif; ?>
            <?php if ($role === 'Administrator'): ?><a href="finance.php"><span class="nav-icon">04</span>Finance</a><?php endif; ?>
            <a href="<?= e($workspaceHref) ?>"><span class="nav-icon">05</span>Reports</a>
            <?php if ($role === 'Administrator'): ?><a href="admin.php"><span class="nav-icon">06</span>ICT Admin</a><?php endif; ?>
            <?php if ($role === 'Administrator'): ?><a href="#parents"><span class="nav-icon">07</span>Parents</a><?php endif; ?>
            <?php if ($role === 'Administrator'): ?><a href="registrar.php"><span class="nav-icon">08</span>Registrar</a><?php endif; ?>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">Logged in as<br><strong><?= e($role) ?></strong></div>
    </aside>

    <div class="content">
        <header class="topbar">
            <div><strong>School overview</strong><p>Monitor your school's activity and performance.</p></div>
            <div class="profile">
                <div class="avatar"><?= e(strtoupper(substr($firstName, 0, 1))) ?></div>
                <div><strong><?= e($user['full_name']) ?></strong><a href="logout.php">Sign out</a></div>
            </div>
        </header>

        <main>
            <section class="welcome">
                <div><h1>Good day, <?= e($firstName) ?>.</h1><p>Here is what is happening at <?= e($schoolName) ?>.</p></div>
                <div class="date"><?= e(date('l, F j, Y')) ?></div>
            </section>

            <section class="stats">
                <div class="stat"><div class="stat-label">Total students</div><div class="stat-value"><?= e((string) $counts['students']) ?></div><div class="stat-note">Active student records</div></div>
                <div class="stat"><div class="stat-label">Staff accounts</div><div class="stat-value"><?= e((string) $counts['users']) ?></div><div class="stat-note">Registered system users</div></div>
                <div class="stat"><div class="stat-label">Parent accounts</div><div class="stat-value"><?= e((string) $counts['parents']) ?></div><div class="stat-note">Linked family accounts</div></div>
                <div class="stat" id="attendance"><div class="stat-label">Today's attendance</div><div class="stat-value"><?= e((string) $counts['attendance']) ?></div><div class="stat-note">Records entered today</div></div>
                <?php if ($canViewFinance): ?><div class="stat"><div class="stat-label">Fees collected</div><div class="stat-value"><?= e(number_format($paymentTotal, 0)) ?></div><div class="stat-note"><?= e((string) $counts['payments']) ?> recorded payments</div></div><?php endif; ?>
            </section>

            <div class="panels<?= $canViewFinance ? '' : ' panels-single' ?>">
                <section class="panel" id="students">
                    <div class="section-title"><h2>Recent students</h2><span>Latest registrations</span></div>
                    <?php if ($recentStudents): ?>
                        <table><thead><tr><th>Student</th><th>Class</th><th>Admission no.</th></tr></thead><tbody>
                        <?php foreach ($recentStudents as $student): ?>
                            <tr><td><strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong><?= e($student['created_at']) ?></td><td><?= e($student['class_name'] ?: 'Not assigned') ?></td><td><span class="badge"><?= e($student['admission_number']) ?></span></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    <?php else: ?><div class="empty">No student records found.</div><?php endif; ?>
                </section>

                <?php if ($canViewFinance): ?><section class="panel" id="payments">
                    <div class="section-title"><h2>Recent payments</h2><span>Latest transactions</span></div>
                    <?php if ($recentPayments): ?>
                        <table><thead><tr><th>Student</th><th>Date</th><th>Amount</th></tr></thead><tbody>
                        <?php foreach ($recentPayments as $payment): ?>
                            <tr><td><strong><?= e($payment['first_name'] . ' ' . $payment['last_name']) ?></strong><?= e($payment['receipt_number']) ?></td><td><?= e($payment['payment_date']) ?></td><td class="money"><?= e(number_format((float) $payment['amount'], 0)) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    <?php else: ?><div class="empty">No payments found.</div><?php endif; ?>
                </section><?php endif; ?>
            </div>

            <div class="panels" style="margin-top: 20px;">
                <section class="panel" id="enrollments">
                    <div class="section-title"><h2>Term enrollments</h2><span><?= e((string) $counts['pending_enrollments']) ?> awaiting review &middot; <a href="registrar.php">Open registrar</a></span></div>
                    <?php if ($enrollmentRows): ?>
                        <table><thead><tr><th>Student</th><th>Term</th><th>Report date</th><th>Status</th></tr></thead><tbody>
                        <?php foreach ($enrollmentRows as $enrollment): ?>
                            <tr><td><strong><?= e($enrollment['first_name'] . ' ' . $enrollment['last_name']) ?></strong></td><td><?= e($enrollment['term'] . ' ' . $enrollment['academic_year']) ?></td><td><?= e($enrollment['report_date']) ?></td><td><span class="badge"><?= e($enrollment['registration_status']) ?></span></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    <?php else: ?><div class="empty">No term enrollments recorded.</div><?php endif; ?>
                </section>
                <section class="panel" id="communications">
                    <div class="section-title"><h2>School communications</h2><span><?= e((string) $counts['announcements']) ?> published</span></div>
                    <?php if ($announcementRows): ?>
                        <table><thead><tr><th>Notice</th><th>Audience</th><th>Published</th></tr></thead><tbody>
                        <?php foreach ($announcementRows as $announcement): ?>
                            <tr><td><strong><?= e($announcement['title']) ?></strong></td><td><?= e($announcement['audience']) ?></td><td><?= e($announcement['published_at']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table>
                    <?php else: ?><div class="empty">No published communications.</div><?php endif; ?>
                </section>
            </div>

            <section class="panel" id="parents" style="margin-top: 20px;">
                <div class="section-title"><h2>Parents and guardians</h2><span><a href="admin.php">Manage parent accounts</a></span></div>
                <?php if ($parentRows): ?>
                    <table><thead><tr><th>Parent</th><th>Linked student</th><th>Contact</th><th>Access</th></tr></thead><tbody>
                    <?php foreach ($parentRows as $parent): ?>
                        <tr>
                            <td><strong><?= e($parent['full_name']) ?></strong><?= e($parent['email']) ?></td>
                            <td><?= e($parent['first_name'] ? $parent['first_name'] . ' ' . $parent['last_name'] : 'Not linked') ?><?php if ($parent['admission_number']): ?><br><span class="badge"><?= e($parent['admission_number']) ?></span><?php endif; ?></td>
                            <td><?= e($parent['guardian_phone'] ?: 'Not provided') ?></td>
                            <td><span class="badge">Parent portal</span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                <?php else: ?><div class="empty">No parent accounts have been created yet. <a href="admin.php">Create a parent account</a>.</div><?php endif; ?>
            </section>

            <section class="modules" id="reports">
                <div class="section-title"><h2>Quick access</h2><span>Available for your role</span></div>
                <div class="module-grid">
                    <?php if ($role === 'Administrator'): ?>
                        <article class="module">
                            <div class="module-icon blue">00</div>
                            <h3><a href="#parents">Parents and Guardians</a></h3>
                            <p>View linked parent accounts, contacts, and student relationships.</p>
                        </article>
                    <?php endif; ?>
                    <?php foreach ($modules[$role] ?? [] as $index => $module): ?>
                        <?php
                        $adminModuleLinks = [
                            'Manage Users' => 'admin.php#accounts',
                            'Manage Roles' => 'admin.php#permissions',
                            'Student Records' => 'admin.php#students',
                            'Teachers & Subjects' => 'admin.php#teachers',
                            'Exams & Results' => 'exams.php',
                            'System Settings' => 'admin.php#settings',
                            'System Logs' => 'admin.php#logs',
                            'Database Backup' => 'admin.php#backup',
                        ];
                        $moduleHref = $role === 'Administrator'
                            ? $adminModuleLinks[$module['title']]
                            : ($module['title'] === 'Exams & Results' ? 'exams.php' : ($module['title'] === 'Results' ? 'results.php' : ($role === 'Librarian' ? 'dashboard.php#reports' : $workspaceHref)));
                        ?>
                        <article class="module">
                            <div class="module-icon <?= e($module['class']) ?>">0<?= e((string) ($index + 1)) ?></div>
                            <h3><a href="<?= e($moduleHref) ?>"><?= e($module['title']) ?></a></h3>
                            <p><?= e($module['description']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
</div>
</body>
</html>
