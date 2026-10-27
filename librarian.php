<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

$role = $_SESSION['user']['role'] ?? '';
if (!has_system_permission('library.access')) {
    http_response_code(403);
    exit('Librarian access is required.');
}

ensure_librarian_role();
ensure_library_tables();
$connection = db();
$userId = (int) $_SESSION['user']['id'];
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Your session token expired. Refresh the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add_book') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $author = trim((string) ($_POST['author'] ?? ''));
            $category = trim((string) ($_POST['category'] ?? ''));
            $isbn = trim((string) ($_POST['isbn'] ?? ''));
            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($title === '' || $author === '' || $category === '' || $quantity === false || $quantity < 1) {
                throw new InvalidArgumentException('Enter a title, author, category, and a quantity of at least one.');
            }
            $statement = $connection->prepare(
                'INSERT INTO library_books (title, author, category, isbn, quantity, available_copies, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$title, $author, $category, $isbn !== '' ? $isbn : null, $quantity, $quantity, $userId]);
            $message = 'Book added to the library catalogue.';
        } elseif ($action === 'issue_book') {
            $bookId = (int) ($_POST['book_id'] ?? 0);
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $dueDate = trim((string) ($_POST['due_date'] ?? ''));
            $parsedDueDate = DateTime::createFromFormat('!Y-m-d', $dueDate);
            if ($bookId < 1 || $studentId < 1 || !$parsedDueDate || $parsedDueDate->format('Y-m-d') !== $dueDate || $dueDate < date('Y-m-d')) {
                throw new InvalidArgumentException('Select a book and student, and enter a valid due date that is today or later.');
            }

            $connection->beginTransaction();
            $bookStatement = $connection->prepare(
                'SELECT available_copies FROM library_books WHERE id = ? FOR UPDATE'
            );
            $bookStatement->execute([$bookId]);
            $availableCopies = $bookStatement->fetchColumn();
            if ($availableCopies === false || (int) $availableCopies < 1) {
                throw new InvalidArgumentException('That book is not currently available for issue.');
            }
            $studentStatement = $connection->prepare('SELECT COUNT(*) FROM students WHERE id = ?');
            $studentStatement->execute([$studentId]);
            if ((int) $studentStatement->fetchColumn() !== 1) {
                throw new InvalidArgumentException('Select a valid student borrower.');
            }

            $statement = $connection->prepare(
                'INSERT INTO library_loans (book_id, student_id, issued_by, issue_date, due_date)
                 VALUES (?, ?, ?, CURRENT_DATE, ?)'
            );
            $statement->execute([$bookId, $studentId, $userId, $dueDate]);
            $connection->prepare(
                'UPDATE library_books SET available_copies = available_copies - 1 WHERE id = ?'
            )->execute([$bookId]);
            $connection->commit();
            $message = 'Book issued successfully.';
        } elseif ($action === 'close_loan') {
            $loanId = (int) ($_POST['loan_id'] ?? 0);
            $outcome = (string) ($_POST['outcome'] ?? '');
            if ($loanId < 1 || !in_array($outcome, ['Returned', 'Lost'], true)) {
                throw new InvalidArgumentException('Select a valid loan and return or lost status.');
            }

            $connection->beginTransaction();
            $loanStatement = $connection->prepare(
                'SELECT book_id, status FROM library_loans WHERE id = ? FOR UPDATE'
            );
            $loanStatement->execute([$loanId]);
            $loan = $loanStatement->fetch();
            if (!$loan || $loan['status'] !== 'Issued') {
                throw new InvalidArgumentException('This loan is no longer active.');
            }
            $connection->prepare(
                'UPDATE library_loans SET status = ?, closed_at = NOW() WHERE id = ?'
            )->execute([$outcome, $loanId]);
            if ($outcome === 'Returned') {
                $connection->prepare(
                    'UPDATE library_books SET available_copies = available_copies + 1 WHERE id = ?'
                )->execute([(int) $loan['book_id']]);
            }
            $connection->commit();
            $message = $outcome === 'Returned'
                ? 'Book return recorded and available copies updated.'
                : 'Book marked as lost. It remains unavailable in the catalogue.';
        } else {
            throw new InvalidArgumentException('Choose a valid library action.');
        }
    }
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    $error = $exception instanceof PDOException && $exception->getCode() === '23000'
        ? 'That ISBN is already registered in the library catalogue.'
        : $exception->getMessage();
}

$search = trim((string) ($_GET['q'] ?? ''));
$searchBookId = preg_match('/^BOOK-(\d+)$/i', $search, $bookIdMatch)
    ? (int) $bookIdMatch[1]
    : 0;
$booksStatement = $connection->prepare(
    'SELECT id, title, author, category, isbn, quantity, available_copies
     FROM library_books
     WHERE ? = "" OR title LIKE ? OR author LIKE ? OR category LIKE ? OR isbn LIKE ? OR CAST(id AS CHAR) LIKE ? OR id = ?
     ORDER BY title, author'
);
$likeSearch = '%' . $search . '%';
$booksStatement->execute([$search, $likeSearch, $likeSearch, $likeSearch, $likeSearch, $likeSearch, $searchBookId]);
$books = $booksStatement->fetchAll();
$issueBooks = $connection->query(
    'SELECT id, title, available_copies FROM library_books
     WHERE available_copies > 0 ORDER BY title'
)->fetchAll();

$students = $connection->query(
    'SELECT id, admission_number, first_name, last_name, class_name
     FROM students ORDER BY last_name, first_name'
)->fetchAll();

$activeLoans = $connection->query(
    "SELECT library_loans.id, library_loans.issue_date, library_loans.due_date,
            library_loans.book_id, library_books.title, library_books.author,
            students.first_name, students.last_name, students.admission_number,
            CASE WHEN library_loans.due_date < CURRENT_DATE THEN 1 ELSE 0 END AS is_overdue
     FROM library_loans
     JOIN library_books ON library_books.id = library_loans.book_id
     JOIN students ON students.id = library_loans.student_id
     WHERE library_loans.status = 'Issued'
     ORDER BY is_overdue DESC, library_loans.due_date, library_books.title"
)->fetchAll();
$lostLoans = $connection->query(
    "SELECT library_loans.id, library_loans.issue_date, library_loans.due_date,
            library_books.title, library_books.author,
            students.first_name, students.last_name, students.admission_number
     FROM library_loans
     JOIN library_books ON library_books.id = library_loans.book_id
     JOIN students ON students.id = library_loans.student_id
     WHERE library_loans.status = 'Lost'
     ORDER BY library_loans.updated_at DESC"
)->fetchAll();
$report = $connection->query(
    "SELECT
        (SELECT COUNT(*) FROM library_books) AS titles,
        (SELECT COALESCE(SUM(quantity), 0) FROM library_books) AS total_copies,
        (SELECT COALESCE(SUM(available_copies), 0) FROM library_books) AS available_copies,
        (SELECT COUNT(*) FROM library_loans WHERE status = 'Issued') AS issued_loans,
        (SELECT COUNT(*) FROM library_loans WHERE status = 'Issued' AND due_date < CURRENT_DATE) AS overdue_loans,
        (SELECT COUNT(*) FROM library_loans WHERE status = 'Lost') AS lost_loans"
)->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Library Management | MIFS IMS</title>
    <style>
        :root { --ink:#172b4d; --muted:#718096; --line:#e5edf5; --navy:#0b2d55; --teal:#178b85; }
        * { box-sizing:border-box; }
        body { margin:0; color:var(--ink); background:#f2f6fb; font:14px Arial,sans-serif; }
        header { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:17px max(5%, calc((100% - 1240px)/2)); color:#fff; background:linear-gradient(110deg,#092b4c,#15558a 70%,#218c89); }
        header nav { display:flex; gap:8px; }
        header a { color:#f2f8ff; text-decoration:none; padding:9px 12px; border:1px solid rgba(255,255,255,.2); border-radius:7px; }
        header a:hover { background:rgba(255,255,255,.13); }
        main { max-width:1240px; margin:auto; padding:30px 26px 50px; }
        .hero { padding:25px 30px; border-radius:14px; color:#fff; background:linear-gradient(115deg,#124b75,#158d8a); box-shadow:0 10px 24px rgba(17,68,119,.15); }
        .eyebrow { margin:0 0 8px; color:#ffe29b; font-size:11px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; }
        h1 { margin:0 0 8px; font-size:30px; } h2 { margin:0 0 14px; font-size:18px; }
        .hero p:last-child { margin:0; color:#d8edf0; line-height:1.5; }
        .stats { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:12px; margin:18px 0 24px; }
        .stat { padding:15px; border:1px solid var(--line); border-top:4px solid var(--stat,#4389c4); border-radius:10px; background:#fff; box-shadow:0 4px 13px rgba(27,55,90,.04); }
        .stat:nth-child(2){--stat:#7866c1}.stat:nth-child(3){--stat:#42a17e}.stat:nth-child(4){--stat:#e3a232}.stat:nth-child(5){--stat:#dc6d72}.stat:nth-child(6){--stat:#cf873d}
        .stat small { display:block; min-height:28px; color:var(--muted); font-size:11px; font-weight:700; }
        .stat strong { display:block; margin-top:7px; font-size:25px; }
        .grid { display:grid; grid-template-columns:minmax(280px,.85fr) minmax(0,1.4fr); gap:18px; }
        .card { min-width:0; margin-bottom:18px; padding:20px; border:1px solid var(--line); border-radius:11px; background:#fff; box-shadow:0 4px 15px rgba(27,55,90,.04); }
        label { display:block; margin:12px 0 5px; color:#52637a; font-size:12px; font-weight:700; }
        input,select { width:100%; padding:10px 11px; border:1px solid #d8e2ed; border-radius:7px; color:var(--ink); background:#fbfdff; font:inherit; }
        input:focus,select:focus { border-color:#4b8dc4; outline:none; box-shadow:0 0 0 3px rgba(75,141,196,.13); }
        button,.button { display:inline-block; padding:9px 13px; border:0; border-radius:7px; color:#fff; background:linear-gradient(120deg,#1763a6,#2382a5); cursor:pointer; font:inherit; font-weight:700; text-decoration:none; }
        button:hover,.button:hover { filter:brightness(1.06); }
        button.lost { background:#b94747; }
        .notice { padding:12px 15px; margin:16px 0; border:1px solid #c8e9d9; border-radius:8px; color:#267153; background:#eaf8f1; }
        .notice.error { border-color:#f1d1d1; color:#a33a3a; background:#fff1f1; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; }
        th { padding:10px; color:#76879c; background:#f6f9fc; font-size:10px; letter-spacing:.5px; text-align:left; text-transform:uppercase; white-space:nowrap; }
        td { padding:11px 10px; border-bottom:1px solid #edf1f5; color:#40536d; vertical-align:middle; }
        td strong { display:block; color:var(--ink); }
        .muted { color:var(--muted); font-size:12px; }
        .badge { display:inline-block; padding:4px 8px; border-radius:20px; color:#1763a6; background:#eaf3fb; font-size:11px; font-weight:700; }
        .badge.overdue { color:#a33a3a; background:#fff0f0; }
        .badge.lost { color:#9b3d3d; background:#fde8e8; }
        .inline { display:inline-flex; gap:6px; align-items:center; }
        .inline button { padding:7px 9px; font-size:11px; }
        .search-row { display:flex; gap:8px; margin:0 0 12px; }
        .search-row input { flex:1; }
        .empty { padding:20px; color:var(--muted); text-align:center; }
        .section-link { display:flex; flex-wrap:wrap; gap:8px; margin-top:17px; }
        .section-link a { padding:8px 10px; border-radius:20px; color:#145d77; background:#e8f4f7; font-size:12px; font-weight:700; text-decoration:none; }
        @media(max-width:1000px){.stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media(max-width:760px){main{padding:20px 5% 36px}.grid{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}header{align-items:flex-start;flex-direction:column;padding:15px 5%}header nav{flex-wrap:wrap}}
        @media(max-width:390px){.stats{grid-template-columns:1fr 1fr}.card{padding:15px}.hero{padding:21px}}
    </style>
</head>
<body>
<header>
    <strong>MIFS IMS &middot; Library Management</strong>
    <nav><a href="dashboard.php">Dashboard</a><?php if ($role === 'Administrator'): ?><a href="admin.php">ICT Admin</a><?php endif; ?><a href="logout.php">Sign out</a></nav>
</header>
<main>
    <section class="hero">
        <p class="eyebrow">Library workspace</p>
        <h1>Library Management</h1>
        <p>Manage the book catalogue, issue and return books, follow overdue and lost books, and review library activity.</p>
        <nav class="section-link" aria-label="Library sections">
            <a href="#books">Books</a><a href="#search">Search books</a><a href="#issue">Issue books</a><a href="#loans">Returns</a><a href="#overdue">Overdue books</a><a href="#lost">Lost books</a><a href="#reports">Library reports</a>
        </nav>
    </section>

    <?php if ($message): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

    <section class="stats" id="reports">
        <div class="stat"><small>Book titles</small><strong><?= e((string)$report['titles']) ?></strong></div>
        <div class="stat"><small>Total copies</small><strong><?= e((string)$report['total_copies']) ?></strong></div>
        <div class="stat"><small>Available copies</small><strong><?= e((string)$report['available_copies']) ?></strong></div>
        <div class="stat"><small>Books on loan</small><strong><?= e((string)$report['issued_loans']) ?></strong></div>
        <div class="stat"><small>Overdue books</small><strong><?= e((string)$report['overdue_loans']) ?></strong></div>
        <div class="stat"><small>Lost books</small><strong><?= e((string)$report['lost_loans']) ?></strong></div>
    </section>

    <div class="grid">
        <section class="card" id="books">
            <h2>Add books</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="add_book">
                <label for="title">Title</label><input id="title" name="title" maxlength="180" required>
                <label for="author">Author</label><input id="author" name="author" maxlength="120" required>
                <label for="category">Category</label><input id="category" name="category" maxlength="80" required>
                <label for="isbn">ISBN</label><input id="isbn" name="isbn" maxlength="30">
                <label for="quantity">Quantity</label><input id="quantity" name="quantity" type="number" min="1" step="1" required>
                <p class="muted">Book ID is assigned automatically. Available copies start at the entered quantity.</p>
                <button type="submit">Add book</button>
            </form>
        </section>

        <section class="card" id="search">
            <h2>Search books</h2>
            <form method="get" class="search-row">
                <input name="q" value="<?= e($search) ?>" aria-label="Search by book ID, title, author, category or ISBN" placeholder="Book ID, title, author, category or ISBN">
                <button type="submit">Search</button>
            </form>
            <div class="table-wrap">
                <table><thead><tr><th>Book ID</th><th>Title</th><th>Author</th><th>Category</th><th>ISBN</th><th>Quantity</th><th>Available</th></tr></thead><tbody>
                <?php foreach ($books as $book): ?>
                    <tr><td><?= e('BOOK-' . str_pad((string)$book['id'], 5, '0', STR_PAD_LEFT)) ?></td><td><strong><?= e($book['title']) ?></strong></td><td><?= e($book['author']) ?></td><td><?= e($book['category']) ?></td><td><?= e($book['isbn'] ?: '-') ?></td><td><?= e((string)$book['quantity']) ?></td><td><span class="badge"><?= e((string)$book['available_copies']) ?></span></td></tr>
                <?php endforeach; ?>
                <?php if (!$books): ?><tr><td colspan="7" class="empty">No books matched this search.</td></tr><?php endif; ?>
                </tbody></table>
            </div>
        </section>
    </div>

    <section class="card" id="issue">
        <h2>Issue books</h2>
        <?php if ($issueBooks && $students): ?>
            <form method="post" class="grid">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="issue_book">
                <div><label for="book_id">Available book</label><select id="book_id" name="book_id" required><option value="">Select a book</option><?php foreach ($issueBooks as $book): ?><option value="<?= e((string)$book['id']) ?>"><?= e('BOOK-' . str_pad((string)$book['id'], 5, '0', STR_PAD_LEFT) . ' - ' . $book['title'] . ' (' . $book['available_copies'] . ' available)') ?></option><?php endforeach; ?></select></div>
                <div><label for="student-search">Student borrower</label><input id="student-search" type="text" data-student-search="student_id" placeholder="Enter student full name" autocomplete="off" required><select id="student_id" name="student_id" hidden><option value="">Select a student</option><?php foreach ($students as $student): ?><option value="<?= e((string)$student['id']) ?>" data-student-name="<?= e($student['first_name'] . ' ' . $student['last_name']) ?>"><?= e($student['first_name'] . ' ' . $student['last_name']) ?></option><?php endforeach; ?></select></div>
                <div><label for="due_date">Due date</label><input id="due_date" name="due_date" type="date" min="<?= e(date('Y-m-d')) ?>" required></div>
                <div style="align-self:end;padding-bottom:1px"><button type="submit">Issue book</button></div>
            </form>
        <?php else: ?>
            <p class="empty">Add at least one book and ensure student records exist before issuing books.</p>
        <?php endif; ?>
    </section>

    <section class="card" id="loans">
        <h2>Returns and active loans</h2>
        <div class="table-wrap"><table><thead><tr><th>Book</th><th>Borrower</th><th>Issued</th><th>Due</th><th>Status</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($activeLoans as $loan): ?>
            <tr><td><strong><?= e($loan['title']) ?></strong><span class="muted"><?= e($loan['author']) ?></span></td><td><?= e($loan['first_name'] . ' ' . $loan['last_name']) ?><br><span class="muted"><?= e($loan['admission_number']) ?></span></td><td><?= e($loan['issue_date']) ?></td><td><?= e($loan['due_date']) ?></td><td><?php if ((int)$loan['is_overdue']): ?><span class="badge overdue">Overdue</span><?php else: ?><span class="badge">Issued</span><?php endif; ?></td><td><form method="post" class="inline"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="close_loan"><input type="hidden" name="loan_id" value="<?= e((string)$loan['id']) ?>"><button name="outcome" value="Returned" type="submit">Return</button><button class="lost" name="outcome" value="Lost" type="submit">Mark lost</button></form></td></tr>
        <?php endforeach; ?>
        <?php if (!$activeLoans): ?><tr><td colspan="6" class="empty">There are no active book loans.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="card" id="overdue">
        <h2>Overdue books</h2>
        <div class="table-wrap"><table><thead><tr><th>Book</th><th>Borrower</th><th>Due date</th><th>Days overdue</th></tr></thead><tbody>
        <?php $overdueCount = 0; foreach ($activeLoans as $loan): if (!(int)$loan['is_overdue']) { continue; } $overdueCount++; ?>
            <tr><td><?= e($loan['title']) ?></td><td><?= e($loan['first_name'] . ' ' . $loan['last_name'] . ' - ' . $loan['admission_number']) ?></td><td><?= e($loan['due_date']) ?></td><td><span class="badge overdue"><?= e((string)(new DateTime($loan['due_date']))->diff(new DateTime('today'))->days) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$overdueCount): ?><tr><td colspan="4" class="empty">No books are overdue.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="card" id="lost">
        <h2>Lost books</h2>
        <div class="table-wrap"><table><thead><tr><th>Book</th><th>Borrower</th><th>Issued</th><th>Due date</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($lostLoans as $loan): ?>
            <tr><td><?= e($loan['title']) ?></td><td><?= e($loan['first_name'] . ' ' . $loan['last_name'] . ' - ' . $loan['admission_number']) ?></td><td><?= e($loan['issue_date']) ?></td><td><?= e($loan['due_date']) ?></td><td><span class="badge lost">Lost</span></td></tr>
        <?php endforeach; ?>
        <?php if (!$lostLoans): ?><tr><td colspan="5" class="empty">No lost books have been recorded.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</main>
<script src="assets/student-search.js" defer></script>
</body>
</html>
