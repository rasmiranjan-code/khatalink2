<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
require_once '../includes/db.php';

$shop_id = 0;
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);

if (!empty($token)) {
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0] ?? 0);
} elseif (isset($_SESSION['shop_id'])) {
    $shop_id = (int)$_SESSION['shop_id'];
}

if ($shop_id <= 0) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}
$text     = $_POST['speech_text'] ?? '';
if (empty(trim($text))) {
    exit(json_encode(['customer' => null, 'items' => [], 'prompts' => get_prompts()]));
}

// ─── 1. Normalise text ────────────────────────────────────────────────────────
$text = mb_strtolower(trim($text), 'UTF-8');

// Map spoken number words → digits  (NOTE: 'do'/'dui' removed from here;
// they are handled AFTER junk-word removal so they don't clash with the junk list)
$num_map = [
    'ek'     => '1',  'eka'    => '1',  'one'    => '1',
    'two'    => '2',  'teen'   => '3',  'tini'   => '3',  'three'  => '3',
    'char'   => '4',  'chari'  => '4',  'four'   => '4',
    'paanch' => '5',  'pancha' => '5',  'five'   => '5',
    'chah'   => '6',  'six'    => '6',
    'saat'   => '7',  'seven'  => '7',
    'aath'   => '8',  'eight'  => '8',
    'nau'    => '9',  'nine'   => '9',
    'das'    => '10', 'ten'    => '10',
    'half'   => '0.5','aadha'  => '0.5','adha'   => '0.5',
    'pao'    => '0.25','pav'   => '0.25',
];
foreach ($num_map as $word => $val) {
    // whole-word replace only
    $text = preg_replace('/\b' . preg_quote($word, '/') . '\b/u', $val, $text);
}

// ─── 2. Keyword lists ─────────────────────────────────────────────────────────
$units = [
    'kilo','kg','packet','pkt','liter','litre','unit','piece','pcs',
    'gram','gm','tala','ta','pau','pou','dozen','darjan','bhaga','hali','muda',
];

// 'do'/'dui' intentionally kept OUT of junk so they convert to '2' via num_map above
$junk = [
    'and','aur','ebong','chahiye','please','dedo','the',
    'name','naam','naba','mera','is','dia','athu','kar',
    'kara','add','plus','lelo','mora','ama','apananka',
    'kuhantu','batayein','tell',
];

$remove_keywords = [
    'remove','delete','subtract','minus','hatao','nikalo','kam',
    'bahar','kaat','kado','hata','kati','del','kadidiantu','feraidia',
];

// Detect removal intent before wiping keywords from text
$is_removal = false;
foreach ($remove_keywords as $kw) {
    if (mb_strpos($text, $kw) !== false) {
        $is_removal = true;
        break;
    }
}

// Merge remove keywords into junk for cleaning purposes
$junk_all = array_merge($junk, $remove_keywords);

// ─── 3. Extract customer name ──────────────────────────────────────────────────
$customer_data     = null;
$potential_customer = '';

// Pattern A: explicit name declarations
if (preg_match(
    '/^(?:my name is|mera naam|mora nama|naba|name|ei naba)\s+([\p{L}\s]+?)(?:\s+(?:hai|is|achi|ati|items|add|remove|\d)|$)/ui',
    $text,
    $mc
)) {
    $potential_customer = trim($mc[1]);
    $text = trim(str_replace($mc[0], '', $text));

// Pattern B: first word followed immediately by a digit  e.g. "Ramesh 2 sugar"
} elseif (preg_match('/^([\p{L}]+)\s+(?=\d)/u', $text, $mc)) {
    $potential_customer = trim($mc[1]);
    // Don't strip yet — confirm match in DB first
}

if ($potential_customer !== '') {
    // Sanitise before using in SQL LIKE
    $safe_customer = preg_replace('/[^\p{L}\s]/u', '', $potential_customer);

    $stmt_c = $pdo->prepare(
        "SELECT c.id, c.name, c.unique_id
         FROM customers c
         JOIN shop_customers sc ON c.id = sc.customer_id
         WHERE sc.shop_id = ?
           AND (c.name LIKE ? OR c.unique_id = ?)
         LIMIT 1"
    );
    $stmt_c->execute([$shop_id, "%{$safe_customer}%", $safe_customer]);
    $customer_data = $stmt_c->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($customer_data) {
        // Remove name from text only if confirmed in DB
        $text = trim(preg_replace(
            '/^' . preg_quote($potential_customer, '/') . '\s+/ui',
            '',
            $text
        ));
    }
}

// ─── 4. Parse item–quantity pairs ────────────────────────────────────────────
$unit_pattern = implode('|', array_map('preg_quote', $units));

$entries = [];

/*
 * Two sub-patterns:
 *   A)  <qty>  [unit]  <name>   e.g. "2 kg sugar"
 *   B)  <name> <qty>  [unit]    e.g. "sugar 2 kg"
 * We run them separately so the capture groups are unambiguous.
 */

// Pattern A: quantity-first
preg_match_all(
    '/(\d+(?:\.\d+)?)\s*(?:' . $unit_pattern . ')?\s*([\p{L}][\p{L}\s]*)/ui',
    $text,
    $mA,
    PREG_SET_ORDER
);
foreach ($mA as $m) {
    $entries[] = ['n' => trim($m[2]), 'q' => (float) $m[1]];
}

// Pattern B: name-first (only when no qty-first match found)
if (empty($entries)) {
    preg_match_all(
        '/([\p{L}][\p{L}\s]*?)\s*(\d+(?:\.\d+)?)\s*(?:' . $unit_pattern . ')?/ui',
        $text,
        $mB,
        PREG_SET_ORDER
    );
    foreach ($mB as $m) {
        $entries[] = ['n' => trim($m[1]), 'q' => (float) $m[2]];
    }
}

// Fallback: no digits found — treat whole text as one item, qty = 1
if (empty($entries) && trim($text) !== '') {
    $entries[] = ['n' => $text, 'q' => 1.0];
}

// ─── 5. Clean names & look up inventory ──────────────────────────────────────
$items_to_return = [];

foreach ($entries as $entry) {
    $rawName = trim($entry['n']);
    $qty     = $entry['q'] > 0 ? $entry['q'] : 1.0;

    // Remove junk/unit words from item name
    $words      = preg_split('/\s+/u', mb_strtolower($rawName, 'UTF-8'));
    $finalWords = array_filter($words, function ($w) use ($junk_all, $units) {
        return $w !== ''
            && !in_array($w, $junk_all, true)
            && !in_array($w, $units, true);
    });

    $itemName = !empty($finalWords)
        ? implode(' ', $finalWords)
        : $rawName;

    $itemName = trim($itemName);
    if ($itemName === '') continue;

    // DB lookup — use parameterised query
    $stmt = $pdo->prepare(
        "SELECT id, name, sale_price, gst_percent, primary_unit
         FROM inventory_products
         WHERE shop_id = ? AND name LIKE ?
         LIMIT 1"
    );
    $stmt->execute([$shop_id, "%{$itemName}%"]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $items_to_return[] = [
        'productId'   => $product ? (int)$product['id'] : null,
        'name'        => $product
                        ? $product['name']
                        : mb_convert_case($itemName, MB_CASE_TITLE, 'UTF-8'), // Fallback to title case
        'qty'         => $qty,
        'unit'        => $product ? $product['primary_unit'] : 'NOS', // New: Return unit
        'rate'        => $product ? (float) $product['sale_price'] : 0.0,
        'gst_percent' => $product ? (float) $product['gst_percent'] : 0.0,
        'action'      => $is_removal ? 'remove' : 'add',
        'found'       => (bool) $product,
    ];
}

// ─── 6. Respond ───────────────────────────────────────────────────────────────
echo json_encode([
    'customer' => $customer_data,
    'items'    => $items_to_return,
    'prompts'  => get_prompts(),
]);

function get_prompts(): array {
    return [
        'en' => 'Please tell me your name',
        'hi' => 'कृपया अपना naam बताएं',
        'or' => 'ଦୟାକରି ଆପଣଙ୍କ ନାମ କୁହନ୍ତୁ',
    ];
}