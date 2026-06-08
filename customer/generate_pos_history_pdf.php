<?php
session_start();
require_once '../includes/db.php';

$customer_id = (int)($_SESSION['customer_id'] ?? 0);
if (!$customer_id) die("Access Denied");

// Filters
$from_date = $_GET['from_date'] ?? date('Y-m-01', strtotime('-1 year'));
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$shop_filter = (int)($_GET['shop_id'] ?? 0);

// Fetch Customer Info
$stmt_cust = $pdo->prepare("SELECT name, unique_id FROM customers WHERE id = ?");
$stmt_cust->execute([$customer_id]);
$cust_data = $stmt_cust->fetch();

// Fetch Shop Info if filtered
$shop_name_filter = "All Shops";
if ($shop_filter > 0) {
    $stmt_shop = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
    $stmt_shop->execute([$shop_filter]);
    $shop_data = $stmt_shop->fetch();
    if ($shop_data) {
        $shop_name_filter = htmlspecialchars($shop_data['shop_name']);
    }
}

// Build query for POS bills history
$query = "
    SELECT pb.*, s.shop_name, s.shop_category
    FROM pos_bills pb
    JOIN shop_owners s ON pb.shop_id = s.id
    WHERE pb.customer_id = ? 
    AND DATE(pb.created_at) BETWEEN ? AND ?
";
$params = [$customer_id, $from_date, $to_date];

if ($shop_filter > 0) {
    $query .= " AND pb.shop_id = ?";
    $params[] = $shop_filter;
}

$query .= " ORDER BY pb.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$bills = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>POS Bills History - <?= htmlspecialchars($cust_data['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; color: #1e293b; padding: 40px; line-height: 1.5; background: #fff; }
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #f1f5f9; padding-bottom: 30px; margin-bottom: 30px; }
        .logo-section img { height: 50px; margin-bottom: 15px; }
        .main-title { margin: 0; color: #0f172a; font-size: 28px; font-weight: 800; }
        .subtitle { margin: 5px 0 0 0; color: #64748b; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { background: #eff6ff; text-align: left; padding: 14px 15px; border-bottom: 2px solid #2563eb; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #1e4ed8; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: top; }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        .footer { margin-top: 60px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #f1f5f9; padding-top: 20px; }
        .watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); opacity: 0.04; z-index: -100; width: 70%; pointer-events: none; }
        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="watermark" alt="KhataLink Watermark">

    <div class="no-print" style="margin-bottom: 20px; text-align: right;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #2563eb; color: #fff; border: none; border-radius: 5px; cursor: pointer;">
            Print Report / Save as PDF
        </button>
    </div>

    <div class="header">
        <div class="logo-section">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink Logo">
            <div class="shop-info">
                <h1 class="main-title">KhataLink POS Bills History</h1>
                <p class="subtitle">Your Point-of-Sale Transactions</p>
            </div>
        </div>
        <div class="customer-info" style="text-align: right;">
            <h3>Customer: <?= htmlspecialchars($cust_data['name']) ?></h3>
            <p>ID: <?= $cust_data['unique_id'] ?><br>Date: <?= date('d M Y') ?></p>
            <p>Period: <?= date('d M Y', strtotime($from_date)) ?> - <?= date('d M Y', strtotime($to_date)) ?></p>
            <p>Shop: <?= $shop_name_filter ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Bill No.</th>
                <th>Date</th>
                <th>Shop</th>
                <th class="text-end">Gross Amount</th>
                <th class="text-end">Discount</th>
                <th class="text-end">Net Amount</th>
                <th class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $total_gross_amount_sum = 0;
            $total_discount_amount_sum = 0;
            $total_net_amount_sum = 0;
            foreach($bills as $bill):
                $is_udhar = ($bill['payment_status'] === 'transferred_to_udhar');
                $status_label = $is_udhar ? 'UDHAR' : 'PAID';
                $status_color = $is_udhar ? '#dc2626' : '#059669';

                $total_gross_amount_sum += (float)$bill['total_gross_amount'];
                $total_discount_amount_sum += (float)$bill['total_discount_amount'];
                $total_net_amount_sum += (float)$bill['final_net_amount'];
            ?>
            <tr>
                <td><?= htmlspecialchars($bill['bill_number']) ?></td>
                <td><?= date('d M Y', strtotime($bill['created_at'])) ?></td>
                <td>
                    <div style="font-weight: 600;"><?= htmlspecialchars($bill['shop_name']) ?></div>
                    <div style="font-size: 11px; color: #64748b;"><?= $bill['shop_category'] ?></div>
                </td>
                <td class="text-end">₹<?= number_format($bill['total_gross_amount'], 2) ?></td>
                <td class="text-end">₹<?= number_format($bill['total_discount_amount'], 2) ?></td>
                <td class="text-end">₹<?= number_format($bill['final_net_amount'], 2) ?></td>
                <td class="text-center">
                    <span style="font-weight: 700; color: <?= $status_color ?>;">
                        <?= $status_label ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($bills)): ?>
                <tr><td colspan="7" class="text-center" style="padding: 30px; color: #94a3b8;">No POS bills found for the selected filters.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr style="background: #0f172a; color: #fff;">
                <td colspan="3" style="font-weight: 900; font-size: 16px;">TOTALS</td>
                <td class="text-end" style="font-weight: 900; font-size: 16px;">₹<?= number_format($total_gross_amount_sum, 2) ?></td>
                <td class="text-end" style="font-weight: 900; font-size: 16px;">₹<?= number_format($total_discount_amount_sum, 2) ?></td>
                <td class="text-end" style="font-weight: 900; font-size: 16px;">₹<?= number_format($total_net_amount_sum, 2) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <div class="footer" style="margin-top: 50px;">
        This is a computer-generated document. Generated via <strong>KhataLink</strong> Digital Ledger.
    </div>
</body>
</html>