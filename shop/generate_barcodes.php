<?php
session_start();
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

require_once '../includes/db.php';

$shop_id = 0;
$product_id = (int)($_GET['product_id']?? 0);
$quantity = (int)($_GET['quantity']?? 1);

// ✅ Method 1: Authorization Header se
$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']?? '');

// ✅ Method 2: GET param se - Flutter browser ke liye important
if (empty($token) && isset($_GET['token'])) {
    $token = $_GET['token'];
}

if (!empty($token)) {
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0]?? 0);
} else {
    $shop_id = (int)($_SESSION['shop_id']?? 0);
}

if (!$shop_id) {
    die("Access Denied: Please login first. Token missing.");
}

if ($product_id <= 0) {
    die("Invalid product ID.");
}

$list = [];
$stmt = $pdo->prepare("SELECT name, barcode FROM inventory_products WHERE shop_id = ? AND id = ? AND barcode IS NOT NULL AND barcode != ''");
$stmt->execute([$shop_id, $product_id]);
$product = $stmt->fetch();

if (!$product) {
    die("Product not found or barcode not available.");
}

for ($i = 0; $i < $quantity; $i++) {
    $list[] = $product;
}

$page_title = "Print Barcodes - " . htmlspecialchars($product['name']);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= $page_title?> — KhataLink</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; padding: 20px; text-align: center; margin: 0; background: #fff; }
        .barcode-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 20px; max-width: 1200px; margin: 0 auto; }
        .barcode-card { border: 1px solid #e5e7eb; padding: 15px; border-radius: 8px; page-break-inside: avoid; background: #fff; }
        .item-name { font-size: 11px; font-weight: 700; margin-top: 8px; text-transform: uppercase; color: #1f2937; }
        .no-print { margin-bottom: 30px; padding: 20px; background: #f9fafb; border-radius: 12px; }
        .no-print h1 { margin: 0 0 10px 0; color: #111827; font-size: 24px; font-weight: 800; }
        .no-print p { margin: 0 0 20px 0; color: #6b7280; font-size: 14px; }
        button { padding: 12px 28px; background: #6224A3; color: #fff; border: none; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 15px; }
        button:hover { background: #4A197D; }
        @media print {
            .no-print { display: none; }
            body { padding: 10px; }
            .barcode-card { border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <h1>Barcode Sticker Sheet</h1>
        <p>Product: <strong><?= htmlspecialchars($product['name'])?></strong> | Barcode: <strong><?= htmlspecialchars($product['barcode'])?></strong> | Quantity: <strong><?= count($list)?></strong></p>
        <button onclick="window.print()">🖨️ Print Now</button>
    </div>

    <div class="barcode-grid">
        <?php foreach($list as $p):?>
        <div class="barcode-card">
            <svg class="barcode" data-value="<?= htmlspecialchars($p['barcode'])?>"></svg>
            <div class="item-name"><?= htmlspecialchars($p['name'])?></div>
        </div>
        <?php endforeach;?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const barcodes = document.querySelectorAll('.barcode');
            barcodes.forEach(function(el) {
                try {
                    JsBarcode(el, el.dataset.value, {
                        format: "CODE128",
                        width: 1.5,
                        height: 40,
                        displayValue: true,
                        fontSize: 10,
                        margin: 5,
                        background: "#ffffff",
                        lineColor: "#000000"
                    });
                } catch(e) {
                    console.error('Barcode error:', e);
                }
            });
        });
    </script>
</body>
</html>