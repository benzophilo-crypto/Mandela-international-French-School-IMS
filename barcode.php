<?php
/**
 * barcode.php — dual-mode barcode utility
 *
 * MODE 1 — SVG image (default):
 *   barcode.php?value=MIFS-MF-2024-001
 *   Returns a Code 39 barcode as an inline SVG image.
 *   Used by <img src="barcode.php?value=..."> tags.
 *
 * MODE 2 — Scan redirect:
 *   barcode.php?redirect=MIFS-MF-2024-001
 *   Redirects the browser to scan.php?value=MIFS-MF-2024-001
 *   Used when a QR/barcode scanner app opens the URL directly
 *   or staff click the "Scan" link printed on the card.
 *   Requires a logged-in session; bounces to login if none.
 */

require_once __DIR__ . '/config.php';

/* ── MODE 2: redirect to scan page ──────────────────────────────────── */
if (isset($_GET['redirect'])) {
    if (!isset($_SESSION['user'])) {
        header('Location: index.php');
        exit;
    }
    $target = trim($_GET['redirect']);
    // Basic sanity — only allow recognised MIFS card format
    if ($target === '' || strlen($target) > 80) {
        http_response_code(400);
        exit('Invalid barcode value.');
    }
    header('Location: scan.php?value=' . rawurlencode($target));
    exit;
}

/* ── MODE 1: generate SVG barcode image ─────────────────────────────── */
if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit('Sign in required.');
}

$value = strtoupper(trim($_GET['value'] ?? ''));
if ($value === '' || strlen($value) > 80 || !preg_match('/^[0-9A-Z\-\. \$\/\+\%]+$/', $value)) {
    http_response_code(400);
    exit('Invalid barcode value.');
}

// Code 39 character patterns (narrow=1, wide=0 encoded as bit strings)
$patterns = [
    '0'=>'101001101101','1'=>'110100101011','2'=>'101100101011','3'=>'110110010101',
    '4'=>'101001101011','5'=>'110100110101','6'=>'101100110101','7'=>'101001011011',
    '8'=>'110100101101','9'=>'101100101101','A'=>'110101001011','B'=>'101101001011',
    'C'=>'110110100101','D'=>'101011001011','E'=>'110101100101','F'=>'101101100101',
    'G'=>'101010011011','H'=>'110101001101','I'=>'101101001101','J'=>'101011001101',
    'K'=>'110101010011','L'=>'101101010011','M'=>'110110101001','N'=>'101011010011',
    'O'=>'110101101001','P'=>'101101101001','Q'=>'101010110011','R'=>'110101011001',
    'S'=>'101101011001','T'=>'101011011001','U'=>'110010101011','V'=>'100110101011',
    'W'=>'110011010101','X'=>'100101101011','Y'=>'110010110101','Z'=>'100110110101',
    '-'=>'100101011011','.'=>'110010101101',' '=>'100110101101','$'=>'100100100101',
    '/'=>'100100101001','+'=>'100101001001','%'=>'101001001001'
];

// Start marker
$encoded = '100101101101';
foreach (str_split($value) as $char) {
    if (!isset($patterns[$char])) {
        continue; // skip any character not in Code 39 set
    }
    $encoded .= $patterns[$char] . '0'; // inter-character gap
}
// Stop marker
$encoded .= '110100101001';

$module = 3;   // px per narrow bar
$height = 72;  // bar height in px
$width  = strlen($encoded) * $module;

// Cache for 1 hour — barcodes don't change
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="100"'
   . ' viewBox="0 0 ' . $width . ' 100" role="img"'
   . ' aria-label="Barcode for ' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">';
echo '<rect width="100%" height="100%" fill="#ffffff"/>';

for ($i = 0; $i < strlen($encoded); $i++) {
    if ($encoded[$i] === '1') {
        echo '<rect x="' . ($i * $module) . '" y="8"'
           . ' width="' . $module . '" height="' . $height . '"'
           . ' fill="#000000"/>';
    }
}

// Human-readable text below bars
echo '<text x="' . ($width / 2) . '" y="96"'
   . ' text-anchor="middle" font-family="Arial,sans-serif" font-size="11" fill="#000000">'
   . htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
   . '</text>';

echo '</svg>';
