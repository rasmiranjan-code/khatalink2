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
$product_id = 0;

// ✅ Method 1: Authorization Header se token - API calls ke liye
$token = str_replace('Bearer ', '', $_SERVER['HTTP_AUTHORIZATION']?? '');

// ✅ Method 2: GET param se token - Flutter browser open ke liye
if (empty($token) && isset($_GET['token'])) {
    $token = $_GET['token'];
}

if (!empty($token)) {
    // Token decode karo
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0]?? 0);
    $product_id = (int)($_GET['id']?? 0);
} else {
    // Web session se
    $shop_id = (int)($_SESSION['shop_id']?? 0);
    $product_id = (int)($_GET['id']?? 0);
}

if (!$shop_id ||!$product_id) {
    die("Unauthorized access or missing parameters. shop_id: $shop_id, product_id: $product_id");
}

// Fetch Product and Shop Details
$stmt = $pdo->prepare("
    SELECT p.*, s.shop_name, s.name as owner_name, s.upi_id, s.gst_number
    FROM inventory_products p
    JOIN shop_owners s ON p.shop_id = s.id
    WHERE p.shop_id =? AND p.id =?
");
$stmt->execute([$shop_id, $product_id]);
$p = $stmt->fetch();

if(!$p) die("Product not found.");

// Generate QR Code data
$qr_data = "Product: ". $p['name']. " | Price: INR ". $p['sale_price']. " | ID: ". $p['id'];
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=". urlencode($qr_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Product Details - <?= htmlspecialchars($p['name'])?></title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; color: #1e293b; padding: 40px; line-height: 1.5; background: #fff; }
       .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #f1f5f9; padding-bottom: 30px; margin-bottom: 30px; }
       .logo-section img { height: 50px; margin-bottom: 15px; }
       .shop-info h1 { margin: 0; color: #0f172a; font-size: 24px; font-weight: 800; }
       .shop-info p { margin: 5px 0 0 0; color: #64748b; font-size: 14px; }
       .product-header { display: flex; gap: 30px; margin-bottom: 40px; }
       .product-img { width: 180px; height: 180px; border-radius: 16px; object-fit: cover; border: 1px solid #e2e8f0; background: #f8fafc; }
       .product-main-info { flex: 1; }
       .product-title { font-size: 28px; font-weight: 800; color: #0f172a; margin: 0 0 10px 0; }
       .badge { display: inline-block; padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; text-transform: uppercase; margin-bottom: 20px; }
       .badge-stock { background: #ecfdf5; color: #059669; }
       .badge-low { background: #fef2f2; color: #dc2626; }
       .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
       .info-item { background: #f8fafc; padding: 20px; border-radius: 14px; border: 1px solid #e2e8f0; }
       .info-label { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 6px; display: block; }
       .info-value { font-size: 18px; font-weight: 800; color: #0f172a; }
       .qr-code-box { text-align: center; border: 1px solid #e2e8f0; border-radius: 12px; padding: 8px; background: #fff; display: inline-block; margin-top: 15px; }
       .qr-code-box img { width: 85px; height: 80px; display: block; margin: 0 auto; }
       .qr-code-box span { font-size: 8px; color: #94a3b8; font-weight: 800; text-transform: uppercase; margin-top: 4px; display: block; letter-spacing: 0.5px; }
       .price-hl { color: #059669; }
       .footer { margin-top: 60px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 30px; }
       .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); opacity: 0.04; z-index: -100; width: 70%; pointer-events: none; }
        @media print {
           .no-print { display: none; }
            body { padding: 20px; }
        }
    </style>
</head>
<body>
    <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="watermark" alt="">

    <div class="no-print" style="margin-bottom: 30px; text-align: right;">
        <button onclick="window.print()" style="padding: 12px 24px; background: #2563eb; color: #fff; border: none; border-radius: 10px; cursor: pointer; font-weight: 700;">
            <i class="fas fa-download"></i> Save as PDF
        </button>
    </div>

    <div class="header">
        <div class="logo-section">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo">
            <div class="shop-info">
                <h1><?= htmlspecialchars($p['shop_name'])?></h1>
                <p>Inventory Product Specification<br>
                <?php if(!empty($p['gst_number'])):?>
                    GSTIN: <?= htmlspecialchars($p['gst_number'])?>
                <?php endif;?></p>
            </div>
        <div style="text-align: right; color: #64748b; font-size: 13px;">
            <strong>Date:</strong> <?= date('d M Y')?><br>
            <strong>Ref ID:</strong> PRD-<?= str_pad($p['id'], 5, '0', STR_PAD_LEFT)?>
            <div class="qr-code-box">
                <img src="<?= $qr_url?>" alt="Product QR">
                <span>Product ID: <?= $p['id']?></span>
            </div>
        </div>
    </div>

    <div class="product-header">
        <img src="<?= $p['photo']? '../assets/img/products/'.$p['photo'] : 'https://ui-avatars.com/api/?name='.urlencode($p['name']).'&background=random&size=200'?>" class="product-img">
        <div class="product-main-info">
            <h2 class="product-title"><?= htmlspecialchars($p['name'])?></h2>
            <span class="badge <?= $p['current_stock'] <= $p['low_stock_alert']? 'badge-low' : 'badge-stock'?>">
                Status: <?= $p['current_stock'] <= $p['low_stock_alert']? 'Low Stock' : 'In Stock'?> (<?= (float)$p['current_stock']?> <?= $p['primary_unit']?>)
            </span>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Sale Price</span>
                    <span class="info-value price-hl">₹<?= number_format($p['sale_price'], 2)?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Primary Unit</span>
                    <span class="info-value"><?= htmlspecialchars($p['primary_unit'])?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="info-grid">
        <div class="info-item">
            <span class="info-label">Purchase Price</span>
            <span class="info-value">₹<?= number_format($p['purchase_price'], 2)?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Opening Stock</span>
            <span class="info-value"><?= (float)$p['opening_stock']?> <?= $p['primary_unit']?></span>
        </div>
        <div class="info-item">
            <span class="info-label">HSN Code</span>
            <span class="info-value"><?= htmlspecialchars($p['hsn_code']?: '—')?></span>
        </div>
        <div class="info-item">
            <span class="info-label">GST Rate</span>
            <span class="info-value"><?= (float)$p['gst_percent']?>%</span>
        </div>
        <?php if(!empty($p['barcode'])):?>
        <div class="info-item">
            <span class="info-label">Barcode</span>
            <span class="info-value"><?= htmlspecialchars($p['barcode'])?></span>
        </div>
        <?php endif;?>
    </div>

    <div class="footer">
        This is an official inventory record generated by <strong>KhataLink</strong> Digital Ledger.<br>
        © 2026 KhataLink — Helping small businesses go digital.
    </div>
</body>
</html>