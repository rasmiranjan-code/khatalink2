<?php
session_start();
require_once '../includes/db.php';

$shop_id = (int)($_SESSION['shop_id'] ?? 0);
if (!$shop_id) die("Access Denied");

// Filters
$target_date = $_GET['target_date'] ?? date('Y-m-d');
$customer_filter = (int)($_GET['customer_id'] ?? 0);

// Fetch Shop Info
$stmt_shop = $pdo->prepare("SELECT shop_name, name as owner_name FROM shop_owners WHERE id = ?");
$stmt_shop->execute([$shop_id]);
$shop_data = $stmt_shop->fetch();

// Fetch Customer Info if filtered
$customer_name_filter = "All Customers";
if ($customer_filter > 0) {
    $stmt_cust = $pdo->prepare("SELECT name, unique_id FROM customers WHERE id = ?");
    $stmt_cust->execute([$customer_filter]);
    $cust_data = $stmt_cust->fetch();
    if ($cust_data) {
        $customer_name_filter = htmlspecialchars($cust_data['name']) . " (" . $cust_data['unique_id'] . ")";
    }
}

// Build query for POS bills history
$query = "
    SELECT pb.*, c.name as customer_name, c.unique_id
    FROM pos_bills pb
    LEFT JOIN customers c ON pb.customer_id = c.id
    WHERE pb.shop_id = ?
    AND DATE(pb.created_at) = ?
    AND pb.payment_status != 'transferred_to_udhar'
";
$params = [$shop_id, $target_date];

if ($customer_filter > 0) {
    $query .= " AND pb.customer_id = ?";
    $params[] = $customer_filter;
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
    <title>POS Bills History - <?= htmlspecialchars($shop_data['shop_name']) ?></title>
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
                <h1 class="main-title"><?= htmlspecialchars($shop_data['shop_name']) ?></h1>
                <p class="subtitle">POS Bills History Report</p>
            </div>
        </div>
        <div class="customer-info" style="text-align: right;">
            <h3>Owner: <?= htmlspecialchars($shop_data['owner_name']) ?></h3>
            <p>Customer: <?= $customer_name_filter ?><br>Date: <?= date('d M Y') ?></p>
            <p>Report Date: <?= date('d M Y', strtotime($target_date)) ?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Bill No.</th>
                <th>Date</th>
                <th>Customer</th>
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
                    <div style="font-weight: 600;"><?= htmlspecialchars($bill['customer_name'] ?? 'Guest') ?></div>
                    <div style="font-size: 11px; color: #64748b;"><?= $bill['unique_id'] ?? 'N/A' ?></div>
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