<?php
session_start(); // Move session_start to the very top
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

require_once '../includes/db.php';

$shop_id = 0;
$auth_source = 'unknown';

$token = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_GET['token'] ?? '');
$token = str_replace('Bearer ', '', $token);

if (!empty($token)) {
      $auth_source = 'token';
    ob_clean();
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0] ?? 0);
} else {
    $auth_source = 'session';
    $shop_id = (int)($_SESSION['shop_id'] ?? 0);
}

if (!$shop_id) {
    error_log("DEBUG: export_daily_report.php - Dying because shop_id is falsey. Auth Source: " . $auth_source);
    die("Unauthorized access.");
}
// ===== END FLUTTER API =====
// Fetch Shop Details
$stmt = $pdo->prepare("SELECT * FROM shop_owners WHERE id = ?");
$stmt->execute([$shop_id]);
$shop = $stmt->fetch();

if (!$shop) die("Shop details not found.");

// Date filtering logic
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// Fetch Payments within date range
$stmt = $pdo->prepare("
    SELECT ph.*, c.name as customer_name, c.unique_id as customer_uid
    FROM payment_history ph
    JOIN customers c ON ph.customer_id = c.id
    WHERE ph.shop_id = ? AND DATE(ph.payment_date) BETWEEN ? AND ?
    ORDER BY ph.payment_date DESC
");
$stmt->execute([$shop_id, $from_date, $to_date]);
$payments = $stmt->fetchAll();

$total_collected = 0;
foreach ($payments as $p) {
    $total_collected += (float)$p['amount_paid'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Collection Report - <?= date('d M Y', strtotime($from_date)) ?> to <?= date('d M Y', strtotime($to_date)) ?></title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; color: #1e293b; padding: 40px; line-height: 1.5; background: #fff; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #f1f5f9; padding-bottom: 25px; margin-bottom: 30px; }
        .logo-section img { height: 45px; margin-bottom: 10px; }
        .shop-info h1 { margin: 0; color: #0f172a; font-size: 20px; font-weight: 800; }
        .shop-info p { margin: 3px 0 0 0; color: #64748b; font-size: 13px; }
        .report-title { background: #0f172a; color: #fff; padding: 6px 15px; border-radius: 8px; font-weight: 800; font-size: 12px; display: inline-block; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { text-align: left; padding: 12px; border-bottom: 2px solid #e2e8f0; font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 800; }
        td { padding: 12px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        .text-end { text-align: right; }
        .summary { margin-top: 30px; text-align: right; border-top: 2px solid #0f172a; padding-top: 15px; }
        .total-val { font-size: 20px; font-weight: 900; color: #059669; }
        .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); opacity: 0.03; z-index: -100; width: 60%; pointer-events: none; }
        
        .filter-form { display: flex; gap: 15px; align-items: flex-end; background: #f8fafc; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 30px; }
        .filter-form div { display: flex; flex-direction: column; gap: 5px; }
        .filter-form label { font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
        .filter-form input { padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-weight: 600; color: #1e293b; outline: none; }
        .filter-form input:focus { border-color: #0f172a; }

        @media print { .no-print { display: none; } body { padding: 0; } .watermark { display: block; } }
        .btn-print { background: #0f172a; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-print:hover { background: #1d4ed8; }
        .btn-filter { background: #2563eb; color: #fff; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-filter:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="watermark" alt="">

    <div class="no-print" style="margin-bottom: 30px;">
        <form method="GET" class="filter-form">
            <div>
                <label>From Date</label>
                <input type="date" name="from_date" value="<?= $from_date ?>">
            </div>
            <div>
                <label>To Date</label>
                <input type="date" name="to_date" value="<?= $to_date ?>">
            </div>
            <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply Filter</button>
            <button type="button" onclick="window.print()" class="btn-print" style="margin-left: auto;"><i class="fas fa-print"></i> Print Report</button>
        </form>
    </div>

    <div class="header">
        <div class="logo-section">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo">
            <div class="shop-info">
                <h1><?= htmlspecialchars($shop['shop_name']) ?></h1>
                <p><?= htmlspecialchars($shop['shop_category']) ?> Store<br>
                <?php if(!empty($shop['gst_number'])): ?>
                    GSTIN: <?= htmlspecialchars($shop['gst_number']) ?>
                <?php endif; ?></p>
            </div>
        </div>
        <div style="text-align: right;">
            <div class="report-title">COLLECTION REPORT</div>
            <p style="margin: 0; font-size: 12px; color: #64748b;">
                Period: <strong><?= date('d M Y', strtotime($from_date)) ?> - <?= date('d M Y', strtotime($to_date)) ?></strong><br>
                Generated: <strong><?= date('d M Y, h:i A') ?></strong>
            </p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Time</th>
                <th>Customer</th>
                <th>ID</th>
                <th>Method</th>
                <th class="text-end">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php if(count($payments) > 0): ?>
                <?php foreach($payments as $p): ?>
                <tr>
                    <td><?= date('d M, h:i A', strtotime($p['payment_date'])) ?></td>
                    <td style="font-weight: 700;"><?= htmlspecialchars($p['customer_name']) ?></td>
                    <td><?= htmlspecialchars($p['customer_uid']) ?></td>
                    <td style="text-transform: capitalize;"><?= htmlspecialchars($p['payment_method'] ?? 'Cash') ?></td>
                    <td class="text-end" style="font-weight: 700;">₹<?= number_format($p['amount_paid'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5" style="text-align: center; padding: 40px; color: #94a3b8;">No payments found for this period.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="summary">
        <span style="font-size: 12px; font-weight: 800; color: #64748b; text-transform: uppercase;">Total Collection:</span>
        <span class="total-val" style="margin-left: 10px;">₹<?= number_format($total_collected, 2) ?></span>
    </div>

    <div style="margin-top: 50px; font-size: 10px; color: #94a3b8; text-align: center;">
        Generated via KhataLink Dashboard.
    </div>
</body>
</html>