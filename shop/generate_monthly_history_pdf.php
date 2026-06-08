<?php
session_start();
require_once '../includes/db.php';

// ── TOKEN AUTH FOR FLUTTER ──────────────────────────────────────────────────
$shop_id = 0;
$customer_id = 0;
$user_type = '';

if (isset($_GET['token'])) {
    $token = str_replace('Bearer ', '', $_GET['token']);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $user_id = (int)($parts[0]?? 0);
    $role = $parts[2]?? '';

    if ($role === 'shop') {
        $shop_id = $user_id;
        $user_type = 'shop';
    } elseif ($role === 'customer') {
        $customer_id = $user_id;
        $user_type = 'customer';
    }

    if (!$user_id) die("Access Denied: Invalid token");
} else {
    // Session auth for web
    if (isset($_SESSION['shop_id'])) {
        $shop_id = $_SESSION['shop_id'];
        $user_type = 'shop';
    } elseif (isset($_SESSION['customer_id'])) {
        $customer_id = $_SESSION['customer_id'];
        $user_type = 'customer';
    } else {
        die("Access Denied");
    }
}

// Filters
$from_date = $_GET['from_date']?? date('Y-m-01', strtotime('-1 year'));
$to_date = $_GET['to_date']?? date('Y-m-d');
$customer_filter = (int)($_GET['customer_id']?? 0);
$shop_filter = (int)($_GET['shop_id']?? 0);

// Fetch info based on user type
if ($user_type === 'shop') {
    $stmt_shop = $pdo->prepare("SELECT shop_name, name as owner_name FROM shop_owners WHERE id =?");
    $stmt_shop->execute([$shop_id]);
    $shop_data = $stmt_shop->fetch();
    $title_name = $shop_data['shop_name']?? 'Shop';
    $owner_name = $shop_data['owner_name']?? '';

    // Customer filter name
    $customer_name_filter = "All Customers";
    if ($customer_filter > 0) {
        $stmt_cust = $pdo->prepare("SELECT name, unique_id FROM customers WHERE id =?");
        $stmt_cust->execute([$customer_filter]);
        $cust_data = $stmt_cust->fetch();
        if ($cust_data) {
            $customer_name_filter = htmlspecialchars($cust_data['name']). " (". $cust_data['unique_id']. ")";
        }
    }

    // Query for shop
    $query = "
        SELECT mk.*, c.name as customer_name, c.unique_id,
               DATEDIFF(CURDATE(), mk.start_date) as days_passed
        FROM monthly_khata mk
        JOIN customers c ON mk.customer_id = c.id
        WHERE mk.shop_id =?
        AND mk.start_date BETWEEN? AND?
    ";
    $params = [$shop_id, $from_date, $to_date];

    if ($customer_filter > 0) {
        $query.= " AND mk.customer_id =?";
        $params[] = $customer_filter;
    }

} else {
    // Customer viewing
    $stmt_cust = $pdo->prepare("SELECT name, unique_id FROM customers WHERE id =?");
    $stmt_cust->execute([$customer_id]);
    $cust_data = $stmt_cust->fetch();
    $title_name = $cust_data['name']?? 'Customer';
    $owner_name = $cust_data['unique_id']?? '';

    // Shop filter name
    $customer_name_filter = "All Shops";
    if ($shop_filter > 0) {
        $stmt_shop = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id =?");
        $stmt_shop->execute([$shop_filter]);
        $shop = $stmt_shop->fetch();
        $customer_name_filter = $shop['shop_name']?? 'Unknown';
    }

    // Query for customer
    $query = "
        SELECT mk.*, s.shop_name, s.shop_category,
               DATEDIFF(CURDATE(), mk.start_date) as days_passed
        FROM monthly_khata mk
        JOIN shop_owners s ON mk.shop_id = s.id
        WHERE mk.customer_id =?
        AND mk.start_date BETWEEN? AND?
    ";
    $params = [$customer_id, $from_date, $to_date];

    if ($shop_filter > 0) {
        $query.= " AND mk.shop_id =?";
        $params[] = $shop_filter;
    }
}

$query.= " ORDER BY mk.start_date DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$cycles = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Khata History - <?= htmlspecialchars($title_name)?></title>
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
        @media print {.no-print { display: none; } body { padding: 0; } }
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
                <h1 class="main-title"><?= htmlspecialchars($title_name)?></h1>
                <p class="subtitle">Monthly Khata History Report</p>
            </div>
        </div>
        <div class="customer-info" style="text-align: right;">
            <h3><?= $user_type === 'shop'? 'Owner: '. htmlspecialchars($owner_name) : 'ID: '. htmlspecialchars($owner_name)?></h3>
            <p><?= $user_type === 'shop'? 'Filter: '. $customer_name_filter : 'Filter: '. $customer_name_filter?><br>Date: <?= date('d M Y')?></p>
            <p>Period: <?= date('d M Y', strtotime($from_date))?> - <?= date('d M Y', strtotime($to_date))?></p>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Cycle Start</th>
                <th><?= $user_type === 'shop'? 'Customer' : 'Shop'?></th>
                <th class="text-center">Days</th>
                <th class="text-end">Bill Amount</th>
                <th class="text-center">Status</th>
                <th class="text-center">Payment Mode</th>
                <th class="text-center">Razorpay ID</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $total_bill_amount = 0;
            foreach($cycles as $mk):
                $is_paid = ($mk['status'] == 'closed');
                $is_manual = ($mk['razorpay_payment_id'] === 'Manual');
                $payment_mode_display = $is_manual? 'CASH' : (empty($mk['razorpay_payment_id'])? 'PENDING' : 'ONLINE');
                $days = (int)$mk['days_passed'];
                $is_overdue = $days >= 25 &&!$is_paid;
                $total_bill_amount += (float)$mk['total_amount'];
                $display_name = $user_type === 'shop'? $mk['customer_name'] : $mk['shop_name'];
                $display_sub = $user_type === 'shop'? 'ID: '. $mk['unique_id'] : $mk['shop_category'];
         ?>
            <tr>
                <td><?= date('d M Y', strtotime($mk['start_date']))?></td>
                <td>
                    <div style="font-weight: 600;"><?= htmlspecialchars($display_name)?></div>
                    <div style="font-size: 11px; color: #64748b;"><?= htmlspecialchars($display_sub)?></div>
                </td>
                <td class="text-center">
                    <span style="font-weight: 600;"><?= $days?></span>
                    <?php if($is_overdue):?>
                        <span style="margin-left: 4px; font-size: 9px; font-weight: 900; padding: 2px 6px; border-radius: 4px; background: #fee2e2; color: #dc2626;">DUE</span>
                    <?php endif;?>
                </td>
                <td class="text-end">₹<?= number_format($mk['total_amount'], 2)?></td>
                <td class="text-center">
                    <span style="font-weight: 700; color: <?= $is_paid? '#059669' : '#dc2626'?>;">
                        <?= $is_paid? 'SETTLED' : 'OPEN'?>
                    </span>
                </td>
                <td class="text-center">
                    <span style="font-weight: 600; color: <?= $is_manual? '#d97706' : '#2563eb'?>;">
                        <?= $payment_mode_display?>
                    </span>
                </td>
                <td class="text-center" style="font-size: 10px; color: #64748b;">
                    <?= $mk['razorpay_payment_id']?: '—'?>
                </td>
            </tr>
            <?php endforeach;?>
            <?php if(empty($cycles)):?>
                <tr><td colspan="7" class="text-center" style="padding: 30px; color: #94a3b8;">No monthly khata cycles found for the selected filters.</td></tr>
            <?php endif;?>
        </tbody>
        <tfoot>
            <tr style="background: #0f172a; color: #fff;">
                <td colspan="3" style="font-weight: 900; font-size: 16px;">TOTAL BILLS</td>
                <td class="text-end" style="font-weight: 900; font-size: 16px;">₹<?= number_format($total_bill_amount, 2)?></td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>

    <div class="footer" style="margin-top: 50px;">
        <div style="margin-bottom: 10px; font-size: 10px; color: #94a3b8;">
            * Online payments include a 3% platform convenience fee.
        </div>
        This is a computer-generated document. Generated via <strong>KhataLink</strong> Digital Ledger.
    </div>
</body>
</html>