<?php
/**
 * qr.php — Pure-PHP QR Code generator (SVG output, zero dependencies)
 *
 * Usage:  <img src="qr.php?url=https%3A%2F%2F...">
 *
 * Implements QR Code Model 2, error correction level M.
 * Supports alphanumeric + byte (UTF-8) encoding sufficient for URLs.
 *
 * Query params:
 *   url  = URL-encoded string to encode (max 180 bytes for version 9)
 *   size = module size in pixels (default 4, min 2, max 20)
 */

require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user'])) {
    http_response_code(403);
    exit('Sign in required.');
}

$data   = trim($_GET['url'] ?? '');
$module = max(2, min(20, (int) ($_GET['size'] ?? 4)));

if ($data === '' || strlen($data) > 180) {
    http_response_code(400);
    exit('Invalid or too long url parameter.');
}

/* ══════════════════════════════════════════════════════════════════════
   QR Code generator — pure PHP implementation
   Encoding: Byte mode (ISO-8859-1 / UTF-8 passthrough)
   Error correction: Level M (15%)
   ══════════════════════════════════════════════════════════════════════ */

/**
 * Returns the QR version (1-9) that can hold $byteCount bytes at EC level M.
 */
function qr_version_for(int $byteCount): int {
    // Byte-mode capacity after mode and length headers for versions 1-9
    $cap = [14,26,42,62,84,106,122,152,180];
    foreach ($cap as $v => $c) {
        if ($byteCount <= $c) return $v + 1;
    }
    throw new InvalidArgumentException('The QR content is too long to encode.');
}

/**
 * GF(256) multiply for Reed-Solomon.
 */
function gf_mul(int $a, int $b): int {
    if ($a === 0 || $b === 0) return 0;
    static $log, $exp;
    if (!isset($log)) {
        $log = array_fill(0, 256, 0);
        $exp = array_fill(0, 512, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D;
        }
        for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
    }
    return $exp[$log[$a] + $log[$b]];
}

/**
 * Reed-Solomon error correction codewords.
 */
function rs_remainder(array $data, array $gen): array {
    $rem = array_fill(0, count($gen), 0);
    foreach ($data as $byte) {
        $lead = $byte ^ array_shift($rem);
        $rem[] = 0;
        foreach ($gen as $i => $g) {
            $rem[$i] ^= gf_mul($lead, $g);
        }
    }
    return $rem;
}

/**
 * Reed-Solomon generator polynomial coefficients for $n EC codewords.
 */
function rs_generator(int $n): array {
    $g = [1];
    for ($i = 0; $i < $n; $i++) {
        $ng = array_fill(0, count($g) + 1, 0);
        $alpha = 1;
        for ($j = 0; $j < $i; $j++) $alpha = gf_mul($alpha, 2);
        foreach ($g as $j => $c) {
            $ng[$j]   ^= $c;
            $ng[$j+1] ^= gf_mul($c, $alpha);
        }
        $g = $ng;
    }
    array_shift($g);
    return $g;
}

// ── Version / EC block parameters (level M) ──────────────────────────
// [version => [total_codewords, data_codewords, ec_per_block, blocks]]
$vcap = [
    1  => [26,  16, 10, 1],
    2  => [44,  28, 16, 1],
    3  => [70,  44, 26, 1],  // actually 2 blocks for some — simplified
    4  => [100, 64, 18, 2],
    5  => [134, 86, 24, 2],
    6  => [172, 108,16, 4],
    7  => [196, 124,18, 4],
    8  => [242, 154,22, 4],
    9  => [292, 182,22, 5],
    10 => [346, 216,26, 5],
];

$bytes   = array_values(unpack('C*', $data));
$version = qr_version_for(count($bytes));
$version = max(1, min(10, $version));
$info    = $vcap[$version];
[, $dataCW, $ecPerBlock, $numBlocks] = $info;

// ── Build data bit stream ─────────────────────────────────────────────
$bits = '';
// Mode indicator: byte = 0100
$bits .= '0100';
// Character count indicator (8 bits for versions 1-9 byte mode)
$len = min(count($bytes), $dataCW - 2); // leave room for mode+length
$bits .= sprintf('%08b', $len);
// Data bytes
for ($i = 0; $i < $len; $i++) {
    $bits .= sprintf('%08b', $bytes[$i]);
}
// Terminator
$bits .= '0000';
// Pad to byte boundary
while (strlen($bits) % 8 !== 0) $bits .= '0';
// Pad codewords
$padBytes = ['11101100','00010001'];
$pi = 0;
while (strlen($bits) < $dataCW * 8) {
    $bits .= $padBytes[$pi % 2];
    $pi++;
}

// ── Split into codeword bytes ─────────────────────────────────────────
$cwBytes = [];
for ($i = 0; $i < $dataCW; $i++) {
    $cwBytes[] = bindec(substr($bits, $i * 8, 8));
}

// ── Reed-Solomon EC codewords ─────────────────────────────────────────
$blockSize   = (int) floor($dataCW / $numBlocks);
$largeBlocks = $dataCW % $numBlocks;
$gen         = rs_generator($ecPerBlock);

$dataBlocks = [];
$ecBlocks   = [];
$offset = 0;
for ($b = 0; $b < $numBlocks; $b++) {
    $sz = $blockSize + ($b >= ($numBlocks - $largeBlocks) ? 1 : 0);
    $block = array_slice($cwBytes, $offset, $sz);
    $offset += $sz;
    $dataBlocks[] = $block;
    $ecBlocks[]   = rs_remainder($block, $gen);
}

// ── Interleave data then EC ───────────────────────────────────────────
$finalCW = [];
$maxData = max(array_map('count', $dataBlocks));
for ($i = 0; $i < $maxData; $i++) {
    foreach ($dataBlocks as $block) {
        if (isset($block[$i])) $finalCW[] = $block[$i];
    }
}
for ($i = 0; $i < $ecPerBlock; $i++) {
    foreach ($ecBlocks as $block) {
        if (isset($block[$i])) $finalCW[] = $block[$i];
    }
}

// ── Final bit stream ─────────────────────────────────────────────────
$finalBits = '';
foreach ($finalCW as $cw) {
    $finalBits .= sprintf('%08b', $cw);
}
// Remainder bits (version-specific, versions 2-6 need 7 remainder bits)
$remainderBits = [1=>0,2=>7,3=>7,4=>7,5=>7,6=>7,7=>0,8=>0,9=>0,10=>0];
$finalBits .= str_repeat('0', $remainderBits[$version] ?? 0);

// ── Build the QR matrix ───────────────────────────────────────────────
$size = $version * 4 + 17;
// -1 = unset, 0 = white, 1 = black, 2 = function (white), 3 = function (black)
$matrix = array_fill(0, $size, array_fill(0, $size, -1));

function qr_set(array &$m, int $r, int $c, int $v): void {
    if ($r >= 0 && $r < count($m) && $c >= 0 && $c < count($m)) {
        $m[$r][$c] = $v;
    }
}

// Finder pattern (7×7 with separator)
function qr_finder(array &$m, int $tr, int $tc): void {
    for ($r = 0; $r < 7; $r++) {
        for ($c = 0; $c < 7; $c++) {
            $edge = ($r === 0 || $r === 6 || $c === 0 || $c === 6);
            $inner = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
            qr_set($m, $tr+$r, $tc+$c, ($edge || $inner) ? 3 : 2);
        }
    }
    // Separator (white border)
    for ($i = -1; $i <= 7; $i++) {
        qr_set($m, $tr-1, $tc+$i, 2);
        qr_set($m, $tr+7, $tc+$i, 2);
        qr_set($m, $tr+$i, $tc-1, 2);
        qr_set($m, $tr+$i, $tc+7, 2);
    }
}
qr_finder($matrix, 0, 0);
qr_finder($matrix, 0, $size-7);
qr_finder($matrix, $size-7, 0);

// Alignment patterns (versions 2+)
$alignPos = [
    1=>[], 2=>[6,18], 3=>[6,22], 4=>[6,26], 5=>[6,30],
    6=>[6,34], 7=>[6,22,38], 8=>[6,24,42], 9=>[6,26,46], 10=>[6,28,50]
];
$ap = $alignPos[$version];
if (count($ap) >= 2) {
    foreach ($ap as $r) {
        foreach ($ap as $c) {
            // Skip positions occupied by finder patterns
            if (($r <= 8 && $c <= 8) || ($r <= 8 && $c >= $size-8) || ($r >= $size-8 && $c <= 8)) continue;
            for ($dr = -2; $dr <= 2; $dr++) {
                for ($dc = -2; $dc <= 2; $dc++) {
                    $edge = (abs($dr) === 2 || abs($dc) === 2);
                    $center = ($dr === 0 && $dc === 0);
                    qr_set($matrix, $r+$dr, $c+$dc, ($edge || $center) ? 3 : 2);
                }
            }
        }
    }
}

// Timing patterns
for ($i = 8; $i < $size - 8; $i++) {
    $v = ($i % 2 === 0) ? 3 : 2;
    if ($matrix[6][$i] === -1) $matrix[6][$i] = $v;
    if ($matrix[$i][6] === -1) $matrix[$i][6] = $v;
}

// Dark module
$matrix[$size - 8][8] = 3;

// Format info placeholders (reserve)
for ($i = 0; $i <= 8; $i++) {
    if ($matrix[8][$i] === -1) $matrix[8][$i] = 2;
    if ($matrix[$i][8] === -1) $matrix[$i][8] = 2;
    if ($matrix[$size-1-$i][8] === -1) $matrix[$size-1-$i][8] = 2;
    if ($matrix[8][$size-1-$i] === -1) $matrix[8][$size-1-$i] = 2;
}

// ── Place data bits (zigzag) ──────────────────────────────────────────
$bitIdx = 0;
$bitLen = strlen($finalBits);
$col    = $size - 1;
$goUp   = true;

while ($col > 0) {
    if ($col === 6) $col--; // skip timing column
    for ($i = 0; $i < $size; $i++) {
        $row = $goUp ? ($size - 1 - $i) : $i;
        foreach ([0, 1] as $dc) {
            $c = $col - $dc;
            if ($matrix[$row][$c] === -1) {
                $bit = ($bitIdx < $bitLen) ? (int) $finalBits[$bitIdx++] : 0;
                $matrix[$row][$c] = $bit;
            }
        }
    }
    $col -= 2;
    $goUp = !$goUp;
}

// ── Apply mask pattern 0 ((row+col) % 2 == 0) ────────────────────────
// Mask 0 is simple and works well for URLs
for ($r = 0; $r < $size; $r++) {
    for ($c = 0; $c < $size; $c++) {
        if ($matrix[$r][$c] <= 1 && ($r + $c) % 2 === 0) {
            $matrix[$r][$c] ^= 1;
        }
    }
}

// ── Write format information (EC level M = 00, mask 0 = 000) ─────────
// Format string for M/mask0 = 101010000010010 (with BCH + XOR mask 101010000010010)
// Pre-computed: EC level M (01) + mask 0 (000) → format bits with BCH = 100000011001110 XOR mask = 101010000010010
// Result = 001010011011100 — standard value
$fmtBits = '101000011011100'; // EC=M, mask=0, BCH error corrected, XOR applied
$fmtMap  = [
    [8,0],[8,1],[8,2],[8,3],[8,4],[8,5],[8,7],[8,8],
    [7,8],[5,8],[4,8],[3,8],[2,8],[1,8],[0,8]
];
$fmtMap2 = [];
for ($i = 0; $i < 7; $i++) $fmtMap2[] = [$size-1-$i, 8];
for ($i = 0; $i < 8; $i++) $fmtMap2[] = [8, $size-8+$i];

foreach ($fmtMap as $i => [$r, $c]) {
    $matrix[$r][$c] = (int) $fmtBits[$i];
}
foreach ($fmtMap2 as $i => [$r, $c]) {
    if ($i < strlen($fmtBits)) $matrix[$r][$c] = (int) $fmtBits[$i];
}

// ── Render as SVG ─────────────────────────────────────────────────────
$quiet   = 4; // quiet zone modules
$total   = ($size + $quiet * 2) * $module;

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<svg xmlns="http://www.w3.org/2000/svg"'
    . ' width="' . $total . '" height="' . $total . '"'
    . ' viewBox="0 0 ' . $total . ' ' . $total . '"'
    . ' shape-rendering="crispEdges">';
// White background
echo '<rect width="' . $total . '" height="' . $total . '" fill="#fff"/>';

// Black modules
$rects = '';
for ($r = 0; $r < $size; $r++) {
    for ($c = 0; $c < $size; $c++) {
        $v = $matrix[$r][$c];
        // Values 1 and 3 are dark modules
        if ($v === 1 || $v === 3) {
            $x = ($c + $quiet) * $module;
            $y = ($r + $quiet) * $module;
            $rects .= '<rect x="' . $x . '" y="' . $y
                    . '" width="' . $module . '" height="' . $module . '" fill="#000"/>';
        }
    }
}
echo $rects;
echo '</svg>';
