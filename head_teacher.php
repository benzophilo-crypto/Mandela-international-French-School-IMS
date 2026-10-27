<?php
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}
if (($_SESSION['user']['role'] ?? '') !== 'Head Teacher/Principal') {
    http_response_code(403);
    exit('Head Teacher/Principal access is required.');
}
$canApproveReports = has_system_permission('reports.approve');
$financialExportValue = $_GET['financial_export'] ?? '';
if (!is_string($financialExportValue)) {
    http_response_code(400);
    exit('Choose a supported financial report export format.');
}
$financialExport = strtolower(trim($financialExportValue));
$financialPrint = ($_GET['financial_print'] ?? '') === '1';
if ($financialExport !== '' && !in_array($financialExport, ['pdf', 'excel', 'csv'], true)) {
    http_response_code(400);
    exit('Choose a supported financial report export format.');
}

ensure_exam_tables();
ensure_staff_attendance_tables();
ensure_fee_assessment_table();
ensure_payment_method_column();
$connection = db();

$schoolCounts = $connection->query(
    "SELECT
        (SELECT COUNT(*) FROM students) AS students,
        (SELECT COUNT(*) FROM users
          JOIN roles ON roles.id = users.role_id
          WHERE roles.role_name NOT IN ('Student','Parent')) AS staff,
        (SELECT COUNT(*) FROM users
          JOIN roles ON roles.id = users.role_id
          WHERE roles.role_name = 'Teacher') AS teachers,
        (SELECT COUNT(*) FROM attendance WHERE attendance_date = CURRENT_DATE) AS student_attendance_today,
        (SELECT COUNT(*) FROM staff_attendance WHERE attendance_date = CURRENT_DATE) AS staff_attendance_today,
        (SELECT COUNT(*) FROM exams) AS exams,
        (SELECT COUNT(*) FROM exams WHERE status = 'Published') AS published_reports"
)->fetch();

$financialSummary = $connection->query(
    "SELECT
        (SELECT COALESCE(SUM(amount_due), 0) FROM student_fee_assessments) AS fees_assessed,
        (SELECT COALESCE(SUM(amount), 0) FROM fee_payments) AS fees_collected,
        (SELECT COALESCE(SUM(GREATEST(COALESCE(assessments.total_due, 0) - COALESCE(payments.total_paid, 0), 0)), 0)
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
         ) payments ON payments.student_id = students.id) AS outstanding_balance,
        (SELECT COUNT(*) FROM fee_payments) AS payment_count"
)->fetch();

$outstandingBalances = $connection->query(
    "SELECT students.first_name, students.last_name, students.admission_number, students.class_name,
            assessments.total_due, COALESCE(payments.total_paid, 0) AS total_paid,
            GREATEST(assessments.total_due - COALESCE(payments.total_paid, 0), 0) AS outstanding
     FROM students
     JOIN (
         SELECT student_id, SUM(amount_due) AS total_due
         FROM student_fee_assessments
         GROUP BY student_id
     ) assessments ON assessments.student_id = students.id
     LEFT JOIN (
         SELECT student_id, SUM(amount) AS total_paid
         FROM fee_payments
         GROUP BY student_id
     ) payments ON payments.student_id = students.id
     WHERE assessments.total_due > COALESCE(payments.total_paid, 0)
     ORDER BY outstanding DESC, students.last_name, students.first_name" .
     ($financialExport === '' && !$financialPrint ? ' LIMIT 30' : '')
)->fetchAll();

$paymentHistory = $connection->query(
    "SELECT fee_payments.amount, fee_payments.payment_date, fee_payments.receipt_number,
            COALESCE(fee_payments.payment_method, 'Cash') AS payment_method,
            students.first_name, students.last_name, students.admission_number,
            users.full_name AS recorded_by
     FROM fee_payments
     JOIN students ON students.id = fee_payments.student_id
     LEFT JOIN users ON users.id = fee_payments.recorded_by
     ORDER BY fee_payments.payment_date DESC, fee_payments.id DESC" .
     ($financialExport === '' && !$financialPrint ? ' LIMIT 25' : '')
)->fetchAll();

$monthlyCollections = $connection->query(
    "SELECT DATE_FORMAT(payment_date, '%Y-%m') AS period,
            DATE_FORMAT(payment_date, '%b %Y') AS month_label,
            COUNT(*) AS payment_count, SUM(amount) AS total_collected
     FROM fee_payments
     WHERE payment_date >= DATE_SUB(CURRENT_DATE, INTERVAL 11 MONTH)
     GROUP BY DATE_FORMAT(payment_date, '%Y-%m'), DATE_FORMAT(payment_date, '%b %Y')
     ORDER BY period DESC"
)->fetchAll();

$paymentMethodSummary = $connection->query(
    "SELECT COALESCE(payment_method, 'Cash') AS payment_method,
            COUNT(*) AS payment_count, SUM(amount) AS total_collected
     FROM fee_payments
     GROUP BY COALESCE(payment_method, 'Cash')
     ORDER BY total_collected DESC"
)->fetchAll();

if ($financialExport !== '') {
    $summaryRows = [
        ['Total fees assessed', number_format((float) $financialSummary['fees_assessed'], 2, '.', '')],
        ['Fees collected', number_format((float) $financialSummary['fees_collected'], 2, '.', '')],
        ['Outstanding balances', number_format((float) $financialSummary['outstanding_balance'], 2, '.', '')],
        ['Payment transactions', (string) $financialSummary['payment_count']],
    ];
    $balanceRows = array_map(
        static fn (array $row): array => [
            $row['first_name'] . ' ' . $row['last_name'],
            $row['admission_number'],
            $row['class_name'] ?: 'Unassigned',
            number_format((float) $row['total_due'], 2, '.', ''),
            number_format((float) $row['total_paid'], 2, '.', ''),
            number_format((float) $row['outstanding'], 2, '.', ''),
        ],
        $outstandingBalances
    );
    $historyRows = array_map(
        static fn (array $row): array => [
            $row['payment_date'],
            $row['first_name'] . ' ' . $row['last_name'],
            $row['admission_number'],
            $row['receipt_number'],
            $row['payment_method'],
            $row['recorded_by'] ?: 'Account removed',
            number_format((float) $row['amount'], 2, '.', ''),
        ],
        $paymentHistory
    );
    $monthlyRows = array_map(
        static fn (array $row): array => [
            $row['month_label'],
            (string) $row['payment_count'],
            number_format((float) $row['total_collected'], 2, '.', ''),
        ],
        $monthlyCollections
    );
    $methodRows = array_map(
        static fn (array $row): array => [
            $row['payment_method'],
            (string) $row['payment_count'],
            number_format((float) $row['total_collected'], 2, '.', ''),
        ],
        $paymentMethodSummary
    );

    if ($financialExport === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="principal-financial-report.csv"');
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Unable to create the financial report export.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        $writeCsv = static function (array $row) use ($output): void {
            $safeRow = array_map(static function ($value): string {
                $value = (string) $value;
                return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'" . $value : $value;
            }, $row);
            if (fputcsv($output, $safeRow) === false) {
                throw new RuntimeException('Unable to write the CSV financial report.');
            }
        };
        $writeCsv(['Principal financial report', date('Y-m-d H:i:s')]);
        $writeCsv([]);
        $writeCsv(['Financial summary']);
        $writeCsv(['Measure', 'Amount / Count']);
        foreach ($summaryRows as $row) {
            $writeCsv($row);
        }
        $writeCsv([]);
        $writeCsv(['Outstanding balances']);
        $writeCsv(['Student', 'Admission number', 'Class', 'Fees assessed', 'Paid', 'Outstanding']);
        foreach ($balanceRows as $row) {
            $writeCsv($row);
        }
        $writeCsv([]);
        $writeCsv(['Payment history']);
        $writeCsv(['Date', 'Student', 'Admission number', 'Receipt', 'Method', 'Recorded by', 'Amount']);
        foreach ($historyRows as $row) {
            $writeCsv($row);
        }
        $writeCsv([]);
        $writeCsv(['Monthly collections']);
        $writeCsv(['Month', 'Transactions', 'Collected']);
        foreach ($monthlyRows as $row) {
            $writeCsv($row);
        }
        $writeCsv([]);
        $writeCsv(['Collections by payment method']);
        $writeCsv(['Payment method', 'Transactions', 'Collected']);
        foreach ($methodRows as $row) {
            $writeCsv($row);
        }
        fclose($output);
        exit;
    }

    if ($financialExport === 'excel') {
        $sheets = [
            'Summary' => [['Measure', 'Amount / Count'], ...$summaryRows],
            'Outstanding balances' => [['Student', 'Admission number', 'Class', 'Fees assessed', 'Paid', 'Outstanding'], ...$balanceRows],
            'Payment history' => [['Date', 'Student', 'Admission number', 'Receipt', 'Method', 'Recorded by', 'Amount'], ...$historyRows],
            'Monthly collections' => [['Month', 'Transactions', 'Collected'], ...$monthlyRows],
            'Payment methods' => [['Payment method', 'Transactions', 'Collected'], ...$methodRows],
        ];
        $columnName = static function (int $column): string {
            $name = '';
            while ($column > 0) {
                $column--;
                $name = chr(65 + ($column % 26)) . $name;
                $column = intdiv($column, 26);
            }
            return $name;
        };
        $worksheetXml = static function (array $rows) use ($columnName): string {
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            foreach ($rows as $rowIndex => $row) {
                $number = $rowIndex + 1;
                $xml .= '<row r="' . $number . '">';
                foreach ($row as $columnIndex => $value) {
                    $cell = $columnName($columnIndex + 1) . $number;
                    $value = (string) $value;
                    if ($value !== '' && is_numeric($value)) {
                        $xml .= '<c r="' . $cell . '" t="n"><v>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</v></c>';
                    } else {
                        $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t xml:space="preserve">'
                            . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                            . '</t></is></c>';
                    }
                }
                $xml .= '</row>';
            }
            return $xml . '</sheetData></worksheet>';
        };

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $workbookSheets = '';
        $workbookRelationships = '';
        foreach (array_keys($sheets) as $index => $sheetName) {
            $sheetId = $index + 1;
            $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $sheetId . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets .= '<sheet name="' . htmlspecialchars($sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="' . $sheetId . '" r:id="rId' . $sheetId . '"/>';
            $workbookRelationships .= '<Relationship Id="rId' . $sheetId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetId . '.xml"/>';
        }
        $contentTypes .= '</Types>';
        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $workbookSheets . '</sheets></workbook>';
        $relationshipsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $workbookRelationshipsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $workbookRelationships . '</Relationships>';

        $temporaryFile = tempnam(sys_get_temp_dir(), 'mifs-financial-');
        if ($temporaryFile === false) {
            throw new RuntimeException('Unable to prepare the Excel financial report.');
        }
        $zip = new ZipArchive();
        $zipOpen = false;
        try {
            if ($zip->open($temporaryFile, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the Excel financial report.');
            }
            $zipOpen = true;
            $zipEntries = [
                '[Content_Types].xml' => $contentTypes,
                '_rels/.rels' => $relationshipsXml,
                'xl/workbook.xml' => $workbookXml,
                'xl/_rels/workbook.xml.rels' => $workbookRelationshipsXml,
            ];
            foreach (array_values($sheets) as $index => $rows) {
                $zipEntries['xl/worksheets/sheet' . ($index + 1) . '.xml'] = $worksheetXml($rows);
            }
            foreach ($zipEntries as $entryName => $entryContents) {
                if (!$zip->addFromString($entryName, $entryContents)) {
                    throw new RuntimeException('Unable to add data to the Excel financial report.');
                }
            }
            if (!$zip->close()) {
                throw new RuntimeException('Unable to finish the Excel financial report.');
            }
            $zipOpen = false;
            $excelData = file_get_contents($temporaryFile);
            if ($excelData === false) {
                throw new RuntimeException('Unable to read the completed Excel financial report.');
            }
        } finally {
            if ($zipOpen) {
                $zip->close();
            }
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="principal-financial-report.xlsx"');
        header('Content-Length: ' . strlen($excelData));
        echo $excelData;
        exit;
    }

    $pdfLines = [
        'MANDELA INTERNATIONAL FRENCH SCHOOL',
        'PRINCIPAL FINANCIAL REPORT',
        'Generated: ' . date('Y-m-d H:i:s'),
        '',
        'FINANCIAL SUMMARY',
    ];
    foreach ($summaryRows as $row) {
        $pdfLines[] = $row[0] . ': ' . $row[1];
    }
    $pdfLines[] = '';
    $pdfLines[] = 'OUTSTANDING BALANCES';
    $pdfLines[] = 'Student | Admission | Class | Assessed | Paid | Outstanding';
    foreach ($balanceRows as $row) {
        $pdfLines[] = implode(' | ', $row);
    }
    $pdfLines[] = '';
    $pdfLines[] = 'PAYMENT HISTORY';
    $pdfLines[] = 'Date | Student | Admission | Receipt | Method | Recorded by | Amount';
    foreach ($historyRows as $row) {
        $pdfLines[] = implode(' | ', $row);
    }
    $pdfLines[] = '';
    $pdfLines[] = 'MONTHLY COLLECTIONS';
    foreach ($monthlyRows as $row) {
        $pdfLines[] = implode(' | ', $row);
    }
    $pdfLines[] = '';
    $pdfLines[] = 'COLLECTIONS BY PAYMENT METHOD';
    foreach ($methodRows as $row) {
        $pdfLines[] = implode(' | ', $row);
    }
    if (!$balanceRows) {
        $pdfLines[] = 'No outstanding balances are currently recorded.';
    }
    if (!$historyRows) {
        $pdfLines[] = 'No payment transactions have been recorded.';
    }

    $wrappedLines = [];
    foreach ($pdfLines as $line) {
        $asciiLine = preg_replace('/[^\x20-\x7E]/', '?', $line);
        foreach (explode("\n", wordwrap((string) $asciiLine, 115, "\n", true)) as $wrappedLine) {
            $wrappedLines[] = $wrappedLine;
        }
    }
    $pages = array_chunk($wrappedLines, 42);
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [' . implode(' ', array_map(
            static fn (int $index): string => (string) (4 + $index * 2) . ' 0 R',
            array_keys($pages)
        )) . '] /Count ' . count($pages) . ' >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    foreach ($pages as $index => $pageLines) {
        $pageObject = 4 + $index * 2;
        $contentObject = $pageObject + 1;
        $stream = "BT\n/F1 8 Tf\n30 550 Td\n";
        foreach ($pageLines as $lineIndex => $line) {
            if ($lineIndex > 0) {
                $stream .= "0 -12 Td\n";
            }
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $stream .= '(' . $escaped . ") Tj\n";
        }
        $stream .= "ET\n";
        $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $contentObject . ' 0 R >>';
        $objects[$contentObject] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';
    }
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $objectId => $object) {
        $offsets[$objectId] = strlen($pdf);
        $pdf .= $objectId . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($objectId = 1; $objectId <= count($objects); $objectId++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$objectId]);
    }
    $pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="principal-financial-report.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$performanceRows = $connection->query(
    "SELECT exams.id, exams.title, exams.class_name, exams.term, exams.academic_year,
            exams.exam_date, exams.status,
            COUNT(student_marks.id) AS validated_subject_marks,
            ROUND(AVG(student_marks.marks_obtained / exam_subjects.max_marks * 100), 2) AS average_percentage
     FROM exams
     LEFT JOIN exam_subjects ON exam_subjects.exam_id = exams.id
     LEFT JOIN student_marks
       ON student_marks.exam_id = exams.id
      AND student_marks.subject_id = exam_subjects.subject_id
      AND student_marks.status = 'Validated'
     GROUP BY exams.id, exams.title, exams.class_name, exams.term,
              exams.academic_year, exams.exam_date, exams.status
     ORDER BY exams.exam_date DESC, exams.id DESC
     LIMIT 12"
)->fetchAll();

$approvalRows = $connection->query(
    "SELECT exams.id, exams.title, exams.class_name, exams.term, exams.academic_year,
            exams.exam_date, exams.status,
            (SELECT COUNT(*) FROM students WHERE students.class_name = exams.class_name) AS student_count,
            (SELECT COUNT(*) FROM exam_subjects WHERE exam_subjects.exam_id = exams.id) AS subject_count,
            (SELECT COUNT(*) FROM student_marks
             JOIN students ON students.id = student_marks.student_id
             JOIN exam_subjects ON exam_subjects.exam_id = student_marks.exam_id
                               AND exam_subjects.subject_id = student_marks.subject_id
             WHERE student_marks.exam_id = exams.id
               AND students.class_name = exams.class_name) AS marks_count,
            (SELECT COUNT(*) FROM student_marks
             JOIN students ON students.id = student_marks.student_id
             JOIN exam_subjects ON exam_subjects.exam_id = student_marks.exam_id
                               AND exam_subjects.subject_id = student_marks.subject_id
             WHERE student_marks.exam_id = exams.id
               AND students.class_name = exams.class_name
               AND student_marks.status = 'Validated') AS validated_count,
            (SELECT ROUND(AVG(student_marks.marks_obtained / exam_subjects.max_marks * 100), 2)
             FROM student_marks
             JOIN students ON students.id = student_marks.student_id
             JOIN exam_subjects ON exam_subjects.exam_id = student_marks.exam_id
                               AND exam_subjects.subject_id = student_marks.subject_id
             WHERE student_marks.exam_id = exams.id
               AND students.class_name = exams.class_name
               AND student_marks.status = 'Validated') AS average_percentage
     FROM exams
     ORDER BY exams.exam_date DESC, exams.id DESC"
)->fetchAll();
$readyReports = 0;
foreach ($approvalRows as $report) {
    $expectedMarks = (int) $report['student_count'] * (int) $report['subject_count'];
    if ($report['status'] !== 'Published' && $expectedMarks > 0
        && (int) $report['marks_count'] === $expectedMarks
        && (int) $report['validated_count'] === $expectedMarks) {
        $readyReports++;
    }
}

$studentAttendance = $connection->query(
    "SELECT students.first_name, students.last_name, students.admission_number,
            students.class_name, attendance.attendance_date, attendance.attendance_status
     FROM attendance
     JOIN students ON students.id = attendance.student_id
     WHERE attendance.attendance_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY)
     ORDER BY attendance.attendance_date DESC, students.class_name, students.last_name
     LIMIT 30"
)->fetchAll();

$staffAttendance = $connection->query(
    "SELECT staff_attendance.attendance_date, staff_attendance.attendance_status,
            staff_attendance.check_in, staff_attendance.check_out, staff_attendance.source,
            staff.full_name, roles.role_name
     FROM staff_attendance
     JOIN users staff ON staff.id = staff_attendance.staff_id
     JOIN roles ON roles.id = staff.role_id
     WHERE staff_attendance.attendance_date >= DATE_SUB(CURRENT_DATE, INTERVAL 6 DAY)
     ORDER BY staff_attendance.attendance_date DESC, staff.full_name
     LIMIT 30"
)->fetchAll();

$studentRows = $connection->query(
    "SELECT admission_number, first_name, last_name, class_name, created_at
     FROM students ORDER BY created_at DESC, id DESC LIMIT 10"
)->fetchAll();
$staffRows = $connection->query(
    "SELECT users.full_name, users.email, roles.role_name
     FROM users JOIN roles ON roles.id = users.role_id
     WHERE roles.role_name NOT IN ('Student','Parent')
     ORDER BY roles.role_name, users.full_name
     LIMIT 20"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Head Teacher Dashboard | MIFS IMS</title>
    <style>
        :root{--ink:#172b4d;--muted:#718096;--line:#e5edf5;--navy:#0b2d55}
        *{box-sizing:border-box}
        body{margin:0;color:var(--ink);background:#f2f6fb;font:14px Arial,sans-serif}
        header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:17px max(5%,calc((100% - 1280px)/2));color:#fff;background:linear-gradient(110deg,#092b4c,#15558a 70%,#218c89)}
        header nav{display:flex;gap:8px}
        header a{padding:9px 12px;border:1px solid rgba(255,255,255,.22);border-radius:7px;color:#fff;text-decoration:none}
        header a:hover{background:rgba(255,255,255,.13)}
        main{max-width:1280px;margin:auto;padding:28px 26px 50px}
        .hero{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:25px 30px;border-radius:14px;color:#fff;background:linear-gradient(115deg,#124b75,#158d8a);box-shadow:0 10px 24px rgba(17,68,119,.15)}
        .eyebrow{margin:0 0 8px;color:#ffe29b;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase}
        h1{margin:0 0 8px;font-size:30px}h2{margin:0 0 14px;font-size:18px}
        .hero p:last-child{margin:0;color:#d8edf0;line-height:1.5}
        .date{padding:12px 15px;border:1px solid rgba(255,255,255,.25);border-radius:8px;background:rgba(255,255,255,.1);white-space:nowrap}
        .stats{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:12px;margin:18px 0 23px}
        .stat{padding:15px;border:1px solid var(--line);border-top:4px solid var(--accent,#4389c4);border-radius:10px;background:#fff;box-shadow:0 4px 13px rgba(27,55,90,.04)}
        .stat:nth-child(2){--accent:#7866c1}.stat:nth-child(3){--accent:#42a17e}.stat:nth-child(4){--accent:#e3a232}.stat:nth-child(5){--accent:#dc6d72}.stat:nth-child(6){--accent:#218c89}.stat:nth-child(7){--accent:#e7b63f}
        .stat small{display:block;min-height:28px;color:var(--muted);font-size:11px;font-weight:700}.stat strong{display:block;margin-top:7px;font-size:25px}
        .financial-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0 20px}
        .financial-stat{padding:15px;border:1px solid var(--line);border-left:4px solid var(--accent,#218c89);border-radius:9px;background:#f9fbfd}
        .financial-stat:nth-child(2){--accent:#42a17e}.financial-stat:nth-child(3){--accent:#dc6d72}.financial-stat:nth-child(4){--accent:#7866c1}
        .financial-stat small{display:block;min-height:26px;color:var(--muted);font-size:11px;font-weight:700}.financial-stat strong{display:block;margin-top:7px;font-size:21px}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
        .card{min-width:0;margin-bottom:18px;padding:20px;border:1px solid var(--line);border-radius:11px;background:#fff;box-shadow:0 4px 15px rgba(27,55,90,.04)}
        .card h2{display:flex;align-items:center;gap:9px}.card h2:before{width:4px;height:19px;border-radius:4px;background:#e7b63f;content:''}
        .subtext{margin:-5px 0 14px;color:var(--muted);line-height:1.5}
        .table-wrap{overflow-x:auto}
        table{width:100%;border-collapse:collapse}
        th{padding:10px;color:#76879c;background:#f6f9fc;font-size:10px;letter-spacing:.5px;text-align:left;text-transform:uppercase;white-space:nowrap}
        td{padding:11px 10px;border-bottom:1px solid #edf1f5;color:#40536d;vertical-align:middle}
        td strong{display:block;color:var(--ink)}
        .badge{display:inline-block;padding:4px 8px;border-radius:20px;color:#1763a6;background:#eaf3fb;font-size:11px;font-weight:700}
        .published,.present{color:#267153;background:#e8f7f0}.pending,.late{color:#94621f;background:#fff4df}.absent{color:#a33a3a;background:#fff0f0}
        .button{display:inline-block;padding:8px 10px;border-radius:6px;color:#fff;background:#1763a6;text-decoration:none;font-size:12px;font-weight:700}
        .financial-actions{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}
        .financial-actions button{border:0;cursor:pointer;font:inherit}
        .empty{padding:20px;color:var(--muted);text-align:center}
        .report-status{white-space:nowrap}
        @media(max-width:1200px){.stats{grid-template-columns:repeat(4,minmax(0,1fr))}}
        @media(max-width:900px){.stats{grid-template-columns:repeat(3,minmax(0,1fr))}.financial-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:760px){main{padding:20px 5% 36px}.hero{align-items:flex-start;flex-direction:column;padding:22px}.grid{grid-template-columns:1fr}header{align-items:flex-start;flex-direction:column;padding:15px 5%}header nav{flex-wrap:wrap}}
        @media(max-width:480px){.stats,.financial-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.card{padding:15px}}
        @media print{body{background:#fff}body *{visibility:hidden}#financial-report,#financial-report *{visibility:visible}#financial-report{position:absolute;left:0;top:0;width:100%;margin:0;border:0;box-shadow:none}.financial-actions{display:none}.financial-stats{grid-template-columns:repeat(4,minmax(0,1fr))}.grid{grid-template-columns:1fr}.card{break-inside:avoid;box-shadow:none}}
    </style>
</head>
<body>
<header>
    <strong>MIFS IMS &middot; Head Teacher / Principal</strong>
    <nav><a href="dashboard.php">Dashboard</a><a href="#financial-report">Financial reports</a><a href="logout.php">Sign out</a></nav>
</header>
<main>
    <section class="hero">
        <div><p class="eyebrow">School leadership</p><h1>Head Teacher Dashboard</h1><p>Monitor school performance, attendance, staff and students, and approve completed academic reports.</p></div>
        <div class="date"><?= e(date('l, F j, Y')) ?></div>
    </section>

    <section class="stats">
        <div class="stat"><small>Students</small><strong><?= e((string)$schoolCounts['students']) ?></strong></div>
        <div class="stat"><small>Staff accounts</small><strong><?= e((string)$schoolCounts['staff']) ?></strong></div>
        <div class="stat"><small>Exams</small><strong><?= e((string)$schoolCounts['exams']) ?></strong></div>
        <div class="stat"><small>Published reports</small><strong><?= e((string)$schoolCounts['published_reports']) ?></strong></div>
        <div class="stat"><small>Student attendance records today</small><strong><?= e((string)$schoolCounts['student_attendance_today']) ?></strong></div>
        <div class="stat"><small>Staff attendance records today</small><strong><?= e((string)$schoolCounts['staff_attendance_today']) ?></strong></div>
        <div class="stat"><small><?= $canApproveReports ? 'Reports ready to approve' : 'Report approvals' ?></small><strong><?= e($canApproveReports ? (string)$readyReports : 'Disabled') ?></strong></div>
    </section>

    <section class="card" id="financial-report">
        <h2>Financial reports</h2>
        <p class="subtext">Read-only financial oversight from recorded student fee assessments and payment transactions. Outstanding balances are calculated per student across all assessed terms, so one student's overpayment does not reduce another student's balance.</p>
        <div class="financial-actions" aria-label="Export financial report">
            <a class="button" href="?financial_export=pdf">Download PDF</a>
            <a class="button" href="?financial_export=excel">Export to Excel</a>
            <a class="button" href="?financial_export=csv">Export CSV</a>
            <a class="button" href="?financial_print=1">Print report</a>
        </div>
        <div class="financial-stats">
            <div class="financial-stat"><small>Total fees assessed</small><strong><?= e(number_format((float)$financialSummary['fees_assessed'], 0)) ?></strong></div>
            <div class="financial-stat"><small>Fees collected</small><strong><?= e(number_format((float)$financialSummary['fees_collected'], 0)) ?></strong></div>
            <div class="financial-stat"><small>Outstanding balances</small><strong><?= e(number_format((float)$financialSummary['outstanding_balance'], 0)) ?></strong></div>
            <div class="financial-stat"><small>Payment transactions</small><strong><?= e((string)$financialSummary['payment_count']) ?></strong></div>
        </div>
        <div class="grid">
            <section>
                <h2>Outstanding balances by student</h2>
                <div class="table-wrap"><table><thead><tr><th>Student</th><th>Class</th><th>Fees assessed</th><th>Paid</th><th>Outstanding</th></tr></thead><tbody>
                <?php foreach ($outstandingBalances as $balance): ?>
                    <tr><td><strong><?= e($balance['first_name'].' '.$balance['last_name']) ?></strong><?= e($balance['admission_number']) ?></td><td><?= e($balance['class_name'] ?: 'Unassigned') ?></td><td><?= e(number_format((float)$balance['total_due'], 0)) ?></td><td><?= e(number_format((float)$balance['total_paid'], 0)) ?></td><td><strong><?= e(number_format((float)$balance['outstanding'], 0)) ?></strong></td></tr>
                <?php endforeach; ?>
                <?php if (!$outstandingBalances): ?><tr><td colspan="5" class="empty">No outstanding fee balances are currently recorded.</td></tr><?php endif; ?>
                </tbody></table></div>
                <?php if (!$financialPrint && count($outstandingBalances) === 30): ?><p class="subtext">Showing the 30 students with the highest outstanding balances.</p><?php endif; ?>
            </section>
            <section>
                <h2>Payment history</h2>
                <div class="table-wrap"><table><thead><tr><th>Date</th><th>Student</th><th>Receipt / Method</th><th>Recorded by</th><th>Amount</th></tr></thead><tbody>
                <?php foreach ($paymentHistory as $payment): ?>
                    <tr><td><?= e($payment['payment_date']) ?></td><td><strong><?= e($payment['first_name'].' '.$payment['last_name']) ?></strong><?= e($payment['admission_number']) ?></td><td><?= e($payment['receipt_number']) ?><br><span class="badge"><?= e($payment['payment_method']) ?></span></td><td><?= e($payment['recorded_by'] ?: 'Account removed') ?></td><td><strong><?= e(number_format((float)$payment['amount'], 0)) ?></strong></td></tr>
                <?php endforeach; ?>
                <?php if (!$paymentHistory): ?><tr><td colspan="5" class="empty">No payment transactions have been recorded.</td></tr><?php endif; ?>
                </tbody></table></div>
                <?php if (count($paymentHistory) === 25): ?><p class="subtext">Showing the 25 most recent payment transactions.</p><?php endif; ?>
            </section>
            <section>
                <h2>Monthly collections</h2>
                <p class="subtext">Collections recorded during the last 12 months.</p>
                <div class="table-wrap"><table><thead><tr><th>Month</th><th>Transactions</th><th>Fees collected</th></tr></thead><tbody>
                <?php foreach ($monthlyCollections as $month): ?>
                    <tr><td><?= e($month['month_label']) ?></td><td><?= e((string)$month['payment_count']) ?></td><td><strong><?= e(number_format((float)$month['total_collected'], 0)) ?></strong></td></tr>
                <?php endforeach; ?>
                <?php if (!$monthlyCollections): ?><tr><td colspan="3" class="empty">No collections were recorded during the last 12 months.</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
            <section>
                <h2>Collections by payment method</h2>
                <div class="table-wrap"><table><thead><tr><th>Method</th><th>Transactions</th><th>Fees collected</th></tr></thead><tbody>
                <?php foreach ($paymentMethodSummary as $method): ?>
                    <tr><td><?= e($method['payment_method']) ?></td><td><?= e((string)$method['payment_count']) ?></td><td><strong><?= e(number_format((float)$method['total_collected'], 0)) ?></strong></td></tr>
                <?php endforeach; ?>
                <?php if (!$paymentMethodSummary): ?><tr><td colspan="3" class="empty">No payment methods have collection records.</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
        </div>
    </section>

    <section class="card" id="reports">
        <h2>Approve academic reports</h2>
        <p class="subtext">Reports can be approved once every student has a validated mark for each exam subject. Approval publishes results to student and parent accounts.</p>
        <div class="table-wrap"><table><thead><tr><th>Report</th><th>Class</th><th>Term / Year</th><th>Validated marks</th><th>Average</th><th>Status</th><th>Approval</th></tr></thead><tbody>
        <?php foreach ($approvalRows as $report):
            $expectedMarks = (int)$report['student_count'] * (int)$report['subject_count'];
            $isReady = $report['status'] !== 'Published' && $expectedMarks > 0
                && (int)$report['marks_count'] === $expectedMarks
                && (int)$report['validated_count'] === $expectedMarks;
        ?>
            <tr>
                <td><strong><?= e($report['title']) ?></strong><?= e($report['exam_date']) ?></td>
                <td><?= e($report['class_name']) ?></td>
                <td><?= e($report['term'].' '.$report['academic_year']) ?></td>
                <td><?= e((string)$report['validated_count']) ?> / <?= e((string)$expectedMarks) ?></td>
                <td><?= $report['average_percentage'] !== null ? e(number_format((float)$report['average_percentage'], 2).'%') : '-' ?></td>
                <td class="report-status"><span class="badge <?= $report['status'] === 'Published' ? 'published' : ($isReady ? 'present' : 'pending') ?>"><?= e($report['status']) ?></span></td>
                <td><?php if ($report['status'] === 'Published'): ?><span class="badge published">Approved</span><?php elseif ($isReady && $canApproveReports): ?><a class="button" href="exams.php?exam_id=<?= e((string)$report['id']) ?>">Review &amp; approve</a><?php elseif ($isReady): ?><span class="badge pending">Approval permission disabled</span><?php else: ?><span class="badge pending">Awaiting marks</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$approvalRows): ?><tr><td colspan="7" class="empty">There are no academic reports yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <section class="card">
        <h2>Academic performance</h2>
        <p class="subtext">Average percentage is based on validated student marks only. Unvalidated marks are not included.</p>
        <div class="table-wrap"><table><thead><tr><th>Exam</th><th>Class</th><th>Term / Year</th><th>Average</th><th>Validated marks</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($performanceRows as $performance): ?>
            <tr><td><strong><?= e($performance['title']) ?></strong></td><td><?= e($performance['class_name']) ?></td><td><?= e($performance['term'].' '.$performance['academic_year']) ?></td><td><?= $performance['average_percentage'] !== null ? e(number_format((float)$performance['average_percentage'], 2).'%') : '-' ?></td><td><?= e((string)$performance['validated_subject_marks']) ?></td><td><span class="badge <?= $performance['status'] === 'Published' ? 'published' : 'pending' ?>"><?= e($performance['status']) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$performanceRows): ?><tr><td colspan="6" class="empty">Academic performance data will appear after exams are created and marks are validated.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>

    <div class="grid">
        <section class="card" id="attendance">
            <h2>Student attendance</h2>
            <p class="subtext">Attendance records for the last seven days.</p>
            <div class="table-wrap"><table><thead><tr><th>Date</th><th>Student</th><th>Class</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($studentAttendance as $record): ?>
                <tr><td><?= e($record['attendance_date']) ?></td><td><strong><?= e($record['first_name'].' '.$record['last_name']) ?></strong><?= e($record['admission_number']) ?></td><td><?= e($record['class_name'] ?: 'Unassigned') ?></td><td><span class="badge <?= strtolower($record['attendance_status']) ?>"><?= e($record['attendance_status']) ?></span></td></tr>
            <?php endforeach; ?>
            <?php if (!$studentAttendance): ?><tr><td colspan="4" class="empty">No student attendance recorded during the last seven days.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>

        <section class="card" id="staff-attendance">
            <h2>Staff attendance</h2>
            <p class="subtext">Staff records show whether they were entered manually or captured by a machine.</p>
            <div class="table-wrap"><table><thead><tr><th>Date</th><th>Staff</th><th>Role</th><th>Status</th><th>Check in / out</th><th>Source</th></tr></thead><tbody>
            <?php foreach ($staffAttendance as $record): ?>
                <tr><td><?= e($record['attendance_date']) ?></td><td><strong><?= e($record['full_name']) ?></strong></td><td><?= e($record['role_name']) ?></td><td><span class="badge <?= strtolower($record['attendance_status']) ?>"><?= e($record['attendance_status']) ?></span></td><td><?= e($record['check_in'] ? substr($record['check_in'],0,5) : '-') ?> / <?= e($record['check_out'] ? substr($record['check_out'],0,5) : '-') ?></td><td><span class="badge"><?= e($record['source']) ?></span></td></tr>
            <?php endforeach; ?>
            <?php if (!$staffAttendance): ?><tr><td colspan="6" class="empty">No staff attendance recorded during the last seven days.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>

        <section class="card" id="students">
            <h2>Monitor students</h2>
            <div class="table-wrap"><table><thead><tr><th>Student</th><th>Class</th><th>Admission</th><th>Registered</th></tr></thead><tbody>
            <?php foreach ($studentRows as $student): ?>
                <tr><td><strong><?= e($student['first_name'].' '.$student['last_name']) ?></strong></td><td><?= e($student['class_name'] ?: 'Unassigned') ?></td><td><?= e($student['admission_number']) ?></td><td><?= e($student['created_at']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$studentRows): ?><tr><td colspan="4" class="empty">No students found.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>

        <section class="card" id="staff">
            <h2>Monitor staff</h2>
            <div class="table-wrap"><table><thead><tr><th>Staff member</th><th>Email</th><th>Role</th></tr></thead><tbody>
            <?php foreach ($staffRows as $staff): ?>
                <tr><td><strong><?= e($staff['full_name']) ?></strong></td><td><?= e($staff['email']) ?></td><td><span class="badge"><?= e($staff['role_name']) ?></span></td></tr>
            <?php endforeach; ?>
            <?php if (!$staffRows): ?><tr><td colspan="3" class="empty">No staff accounts found.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section>
    </div>
</main>
<?php if ($financialPrint): ?><script>window.addEventListener('load', function () { window.print(); });</script><?php endif; ?>
</body>
</html>
