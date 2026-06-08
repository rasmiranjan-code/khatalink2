<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';

// Include config for platform fee constants (needed for real tax calculation)
require_once '../includes/cashfree_config.php';

if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    ob_clean();

    // Handle POST: Submit Payment Notification
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        $data = json_decode(file_get_contents('php://input'), true);

        $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = str_replace('Bearer ', '', $token);
        $decoded = base64_decode($token);
        $parts = explode(':', $decoded);
        $customer_id_api = $parts[0] ?? 0;

        $shop_id = (int)($data['shop_id'] ?? 0);
        $amount = (float)($data['amount'] ?? 0);
        $mode = trim($data['mode'] ?? '');
        $screenshot_base64 = $data['screenshot_base64'] ?? null;

        if ($shop_id > 0 && $amount > 0 && !empty($mode)) {
            $screenshot = null;
            if ($screenshot_base64) {
                $upload_dir = '../assets/img/payments/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $image_data = base64_decode($screenshot_base64);
                $screenshot = uniqid('pay_') . '.jpg';
                file_put_contents($upload_dir . $screenshot, $image_data);
            }
            $stmt = $pdo->prepare("INSERT INTO payment_requests (shop_id, customer_id, amount, payment_mode, screenshot) VALUES (?, ?, ?, ?, ?)");
            $success = $stmt->execute([$shop_id, $customer_id_api, $amount, $mode, $screenshot]);
            echo json_encode(['success' => $success, 'message' => $success ? 'Notification sent!' : 'Failed to send']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid input']);
        }
        exit();
    }

    // Handle GET: Fetch Transactions
    header('Content-Type: application/json');
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $customer_id_api = $parts[0] ?? 0;

    $shop_id_filter = isset($_GET['shop_id']) ? (int)$_GET['shop_id'] : 0;
    $search_shop = $_GET['search_shop'] ?? '';

    $where_clause = "WHERE ue.customer_id = ?";
    $params_credit = [$customer_id_api];
    $params_payment = [$customer_id_api];

    if ($shop_id_filter > 0) {
        $where_clause .= " AND ue.shop_id = ?"; // This is for udhar_entries
        $params_credit[] = $shop_id_filter;
        $params_payment[] = $shop_id_filter;
    }

    $query = " 
        (SELECT 'credit' as type, ue.id, ue.total_amount as amount, ue.total_remaining, ue.discount_percentage, ue.created_at as date, ue.status, s.shop_name, s.shop_category, s.upi_id, ue.pos_bill_id, (SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE customer_id = ue.customer_id AND shop_id = ue.shop_id AND status = 'open') as shop_total_due
         FROM udhar_entries ue 
         JOIN shop_owners s ON ue.shop_id = s.id 
         $where_clause)
        UNION ALL
        (SELECT 'payment' as type, MIN(ph.id) as id, 
                SUM(ph.amount_paid * (CASE WHEN ph.razorpay_payment_id IS NOT NULL AND ph.razorpay_payment_id != 'Manual' THEN (1 + " . LEDGER_PLATFORM_COMMISSION_PERCENT . "/100) ELSE 1 END)) as amount, 
                0 as total_remaining, 0 as discount_percentage, ph.payment_date as date, ph.payment_mode as status, s.shop_name, s.shop_category, s.upi_id, NULL as pos_bill_id, 0 as shop_total_due
         FROM payment_history ph 
         JOIN shop_owners s ON ph.shop_id = s.id 
         " . str_replace('ue.', 'ph.', $where_clause) . "
         GROUP BY ph.payment_date, ph.payment_mode, s.shop_name, s.shop_category, s.upi_id)
        ORDER BY date DESC
    ";
    $params = array_merge($params_credit, $params_payment);

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions_raw = $stmt->fetchAll();

    $all_transactions = array_map(function($t) {
        return [
            'type' => $t['type'],
            'id' => (int)$t['id'],
            'amount' => (float)$t['amount'],
            'total_remaining' => (float)$t['total_remaining'],
            'discount_percentage' => (float)$t['discount_percentage'],
            'date' => $t['date'],
            'shop_name' => $t['shop_name'],
            'pos_bill_id' => (int)($t['pos_bill_id'] ?? 0), // Add pos_bill_id
            'shop_category' => $t['shop_category'],
            'upi_id' => $t['upi_id'] ?? '',
            'shop_total_due' => (float)$t['shop_total_due'],
            'payment_mode' => $t['type'] == 'payment' ? ($t['status'] ?? 'Online') : ''
        ];
    }, $transactions_raw);

    echo json_encode(['success' => true, 'transactions' => $all_transactions]);
    exit();
}
// ===== END FLUTTER API =====

require_once '../includes/track_visitor.php';
track_visitor($pdo);

if(!isset($_SESSION['customer_id'])) {
    header("Location: ../auth/login.php?type=customer");
    exit();
}

$customer_id = $_SESSION['customer_id'];

$stmt_c = $pdo->prepare("SELECT email, phone, name FROM customers WHERE id = ?");
$stmt_c->execute([$customer_id]);
$cust_meta = $stmt_c->fetch();

$stmt_modes = $pdo->query("SELECT name, icon FROM payment_modes WHERE is_active = 1");
$db_modes = $stmt_modes->fetchAll();

function getPaymentIcon(string $mode, array $db_modes): string {
    $mode = trim($mode);
    $aliases = [
        'online'     => 'UPI',
        'google pay' => 'GPay',
        'googlepay'  => 'GPay',
        'phonepe'    => 'PhonePe',
        'phone pe'   => 'PhonePe',
    ];
    $normalized = strtolower($mode);
    if (isset($aliases[$normalized])) {
        $mode = $aliases[$normalized];
    }
    foreach ($db_modes as $pm) {
        if (
            strcasecmp($mode, $pm['name']) === 0 ||
            stripos($mode, $pm['name']) !== false ||
            stripos($pm['name'], $mode) !== false
        ) {
            return $pm['icon'];
        }
    }
    return '<i class="fas fa-wallet text-slate-400"></i>';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_payment'])) {
    $shop_id_req = (int)$_POST['shop_id'];
    $amt_req = (float)$_POST['amount'];
    $mode_req = $_POST['mode'];
    $screenshot = null;

    if (isset($_FILES['screenshot']) && $_FILES['screenshot']['error'] == 0) {
        $upload_dir = '../assets/img/payments/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $ext = pathinfo($_FILES['screenshot']['name'], PATHINFO_EXTENSION);
        $screenshot = uniqid('pay_') . '.' . $ext;
        move_uploaded_file($_FILES['screenshot']['tmp_name'], $upload_dir . $screenshot);
    }

    if ($amt_req > 0 && $shop_id_req > 0) {
        $stmt = $pdo->prepare("INSERT INTO payment_requests (shop_id, customer_id, amount, payment_mode, screenshot) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$shop_id_req, $customer_id, $amt_req, $mode_req, $screenshot]);
        header("Location: ledger.php?success=Payment notification sent to shop!");
        exit();
    }
}

$search_shop = isset($_GET['shop']) ? trim($_GET['shop']) : '';

$query = "
    (SELECT 'credit' as type, ue.id, ue.total_amount as amount, ue.total_remaining, ue.discount_percentage, ue.created_at as date, ue.status, s.shop_name, s.shop_category, s.upi_id, ue.pos_bill_id, (SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE customer_id = ue.customer_id AND shop_id = ue.shop_id AND status = 'open') as shop_total_due
     FROM udhar_entries ue 
     JOIN shop_owners s ON ue.shop_id = s.id 
     WHERE ue.customer_id = ?)
    UNION ALL
    (SELECT 'payment' as type, MIN(ph.id) as id, 
            SUM(ph.amount_paid * (CASE WHEN ph.razorpay_payment_id IS NOT NULL AND ph.razorpay_payment_id != 'Manual' THEN (1 + " . LEDGER_PLATFORM_COMMISSION_PERCENT . "/100) ELSE 1 END)) as amount, 
            0 as total_remaining, 0 as discount_percentage, ph.payment_date as date, ph.payment_mode as status, s.shop_name, s.shop_category, s.upi_id, NULL as pos_bill_id, 0 as shop_total_due
     FROM payment_history ph 
     JOIN shop_owners s ON ph.shop_id = s.id 
     WHERE ph.customer_id = ? 
     GROUP BY ph.payment_date, ph.payment_mode, s.shop_name, s.shop_category, s.upi_id)
    ORDER BY date DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$customer_id, $customer_id]);
$all_transactions = $stmt->fetchAll();

if ($search_shop) {
    $all_transactions = array_filter($all_transactions, function($t) use ($search_shop) {
        return stripos($t['shop_name'], $search_shop) !== false;
    });
}

$total_due = $pdo->prepare("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE customer_id = ? AND status = 'open'");
$total_due->execute([$customer_id]);
$total_due = $total_due->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Full Ledger — KhataLink</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
       body { font-family: 'Inter', sans-serif; }
        .glass-banner { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); position: relative; overflow: hidden; }
        .glass-banner::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.03) 0%, transparent 70%); pointer-events: none; }
        .mode-dropdown-panel { position:absolute; z-index:1050; width:100%; top:100%; left:0; display:none; }
        .mode-dropdown-panel.show { display:block; }
    </style>
</head>
   <body class="bg-slate-50 text-slate-900">
    <!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="flex items-center gap-3">
        <div class="bg-red-50 border border-red-100 text-red-700 text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-wider">
            Total Due: ₹<?= number_format($total_due, 0) ?>
        </div>
    </div>
</nav>
<div class="min-h-[calc(100vh-64px)]">
    <main class="p-4 md:p-8 max-w-7xl mx-auto w-full">
        <div class="glass-banner rounded-3xl p-8 mb-8 text-white shadow-xl shadow-slate-200">
            <h1 class="text-2xl md:text-3xl font-black tracking-tight mb-1">Digital Ledger</h1>
            <p class="text-slate-400 text-sm">Unified transaction history across all shops.</p>
        </div>
        <?php if(isset($_GET['success'])): ?>
        <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-bold mb-8 flex items-center gap-3">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['success']) ?>
                </div>
                <?php endif; ?>
                <?php if($total_due > 0): ?>
                  <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-8 shadow-sm mb-8">
                    <h5 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-6">Notify Shop About Payment</h5>
                    <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="request_payment" value="1">
                                 <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                                <div class="md:col-span-3">
                                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Shop</label>
                                    <select name="shop_id" id="shopSelect" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all cursor-pointer" required>
                                        <option value="">Choose shop...</option>
                                        <?php 
                                        $stmt_shops = $pdo->prepare("SELECT s.id, s.shop_name, s.upi_id FROM shop_customers sc JOIN shop_owners s ON sc.shop_id = s.id WHERE sc.customer_id = ? AND (SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE shop_id = s.id AND customer_id = ? AND status = 'open') > 0");
                                        $stmt_shops->execute([$customer_id, $customer_id]);
                                        $my_shops = $stmt_shops->fetchAll();
                                        foreach($my_shops as $ms): ?>
                                            <option value="<?= $ms['id'] ?>" data-upi="<?= htmlspecialchars($ms['upi_id']) ?>"><?= htmlspecialchars($ms['shop_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Amount (₹)</label>
                                    <input type="number" step="0.01" name="amount" id="payAmount" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-2.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" placeholder="0.00" required oninput="updatePayBreakdown()" onkeydown="return event.key != 'Enter';">
                                </div>

                                <div class="md:col-span-2 relative" id="modeWrapper">
                                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Mode</label>
                                    <select name="mode" id="payModeSelect" class="hidden" required>
                                        <?php foreach($db_modes as $dm): ?>
                                            <option value="<?= htmlspecialchars($dm['name']) ?>"><?= htmlspecialchars($dm['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-2.5 text-sm font-bold flex justify-between items-center" id="modeBtn" onclick="toggleModeDD()">
                                        <?php 
                                            $def_icon = '<i class="fas fa-wallet"></i>';
                                            $def_label = 'Select Mode';
                                            if(!empty($db_modes)) {
                                                $def_icon = $db_modes[0]['icon'];
                                                $def_label = $db_modes[0]['name'];
                                            }
                                        ?>
                                        <span class="flex items-center gap-2 overflow-hidden"><span id="modeIcon"><?= $def_icon ?></span><span id="modeLabel" class="truncate"><?= htmlspecialchars($def_label) ?></span></span>
                                        <i class="fas fa-chevron-down text-slate-400" id="modeChevron"></i>
                                    </button>
                                       <div class="mode-dropdown-panel bg-white border border-slate-200 rounded-2xl shadow-2xl overflow-hidden mt-1" id="modeDDPanel">
                                        <?php foreach($db_modes as $m): ?>
                                            <div class="px-4 py-3 hover:bg-slate-50 cursor-pointer flex items-center gap-3 border-b border-slate-50 last:border-0" data-value="<?= htmlspecialchars($m['name']) ?>" data-label="<?= htmlspecialchars($m['name']) ?>" onclick="selectModeDD(this)">
                                                <span><?= $m['icon'] ?></span> <span class="text-xs font-bold text-slate-700"><?= htmlspecialchars($m['name']) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="md:col-span-3">
                                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Screenshot (Optional)</label>
                                    <input type="file" name="screenshot" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-4 py-2 text-[10px] font-bold focus:bg-white focus:border-blue-500 outline-none transition-all cursor-pointer" accept="image/*">
                                </div>

                                <div class="md:col-span-2" id="notifyBtnWrapper">
                                    <button type="submit" name="request_payment" class="w-full bg-slate-900 text-white font-black py-3 rounded-2xl hover:bg-blue-600 transition-all uppercase tracking-widest text-[10px]" onclick="return confirm('Dukandaar ko manual payment ki notification bhejein?')">Notify</button>
                                </div>
                                </div>

                        <!-- Detailed Fee Breakdown Display -->
                        <div id="ledgerPayBreakdown" class="hidden mt-4 p-5 bg-blue-50 border-2 border-blue-100 rounded-[1.5rem]">
                            <div class="flex justify-between text-[10px] font-bold text-slate-500 mb-1">
                                <span>Base Payout to Shop:</span>
                                <span id="baseAmtDisp">₹0.00</span>
                            </div>
                            <div class="flex justify-between text-[10px] font-bold text-slate-400 mb-1">
                                <span>Payment Gateway Fees (<?= PG_FEE_PERCENT ?>%):</span>
                                <span id="pgFeeAmtDisp">₹0.00</span>
                            </div>
                            <div class="flex justify-between text-[10px] font-bold text-slate-400 mb-2">
                                <span>KhataLink Service Fees (<?= (LEDGER_PLATFORM_COMMISSION_PERCENT - PG_FEE_PERCENT) ?>%):</span>
                                <span id="klFeeAmtDisp">₹0.00</span>
                            </div>
                            <div class="flex justify-between border-t border-dashed border-blue-200 pt-2 text-xs font-black text-blue-700 uppercase">
                                <span>Final Total Amount:</span>
                                <span id="totalPayableDisp">₹0.00</span>
                            </div>
                        </div>
                                <button type="button" id="upiRedirectBtn" class="hidden mt-4 bg-blue-600 text-white font-black px-6 py-3 rounded-xl text-[10px] uppercase tracking-widest shadow-lg shadow-blue-200" onclick="payViaKhataLinkFromLedger()" title="Secure Payment">
                                    <i class="fas fa-shield-alt me-2"></i> Pay Securely via KhataLink
                                </button>
                    </form>
                </div>
                <?php else: ?>
                <div class="bg-emerald-50 border-2 border-emerald-100 rounded-[2.5rem] p-10 mb-8 text-center shadow-sm">
                    <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center text-2xl mx-auto mb-4"><i class="fas fa-check-double"></i></div>
                    <h3 class="text-lg font-black text-emerald-900 uppercase tracking-tight">Accounts Fully Settled</h3>
                    <p class="text-emerald-600 text-sm font-medium">Aapka koi bhi udhar pending nahi hai. Payment notification system abhi disabled hai.</p>
                </div>
                <?php endif; ?>

                <!-- Filter Bar -->
                <div class="bg-white border border-slate-200 rounded-3xl p-4 mb-6 flex flex-col md:flex-row gap-4 items-center shadow-sm">
                    <form method="GET" class="flex-1 w-full flex gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                            <input type="text" name="shop" placeholder="Filter by shop name..." value="<?= htmlspecialchars($search_shop) ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl pl-10 pr-4 py-2.5 text-sm font-bold outline-none focus:bg-white focus:border-blue-500 transition-all">
                        </div>
                        <button type="submit" class="bg-slate-900 text-white px-5 py-2.5 rounded-2xl hover:bg-blue-600 transition-all text-xs font-black uppercase tracking-widest">Filter</button>
                    </form>
                    <?php if($search_shop): ?>
                        <a href="ledger.php" class="text-[10px] font-black text-slate-400 uppercase tracking-widest hover:text-red-500"><i class="fas fa-times me-1"></i> Clear</a>
                    <?php endif; ?>
                </div>

                <!-- Transaction Table -->
                <div class="bg-white border border-slate-200 rounded-[2.5rem] shadow-sm shadow-slate-200/50 overflow-hidden mb-8">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead>
                                    <tr class="bg-slate-50 border-b border-slate-100">
                                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Transaction</th>
                                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Source</th>
                                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Details</th>
                                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Debit</th>
                                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Credit</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php if($all_transactions): foreach($all_transactions as $t): 
                                        $entry_discount = ($t['type'] == 'credit') ? ($t['amount'] * ($t['discount_percentage'] / 100)) : 0;
                                        $net_amt = $t['amount'] - $entry_discount;
                                    ?>
                                    <tr class="hover:bg-slate-50/80 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-black text-slate-900"><?= date('d M Y', strtotime($t['date'])) ?></div>
                                            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-tight"><?= date('h:i A', strtotime($t['date'])) ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($t['shop_name']) ?></div>
                                            <div class="text-[10px] font-black text-blue-500 uppercase tracking-widest"><?= htmlspecialchars($t['shop_category']) ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex flex-wrap gap-1 mb-2">
                                                <?php if($t['type'] == 'credit'): ?>
                                                    <span class="text-[9px] font-black bg-red-50 text-red-600 px-2 py-1 rounded-md uppercase tracking-widest">Purchase</span>
                                                    <?php if($t['discount_percentage'] > 0): ?>
                                                        <span class="text-[9px] font-black bg-emerald-50 text-emerald-600 px-2 py-1 rounded-md uppercase tracking-widest"><?= number_format($t['discount_percentage'], 0) ?>% OFF</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-[9px] font-black bg-emerald-50 text-emerald-600 px-2 py-1 rounded-md uppercase tracking-widest">Payment</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php 
                                            if($t['type'] == 'credit') {
                                                $items_stmt = $pdo->prepare("SELECT field_name, quantity, rate, amount FROM udhar_items WHERE entry_id = ?");
                                                $items_stmt->execute([$t['id']]);
                                                $items = $items_stmt->fetchAll();
                                                if($items) {
                                                    echo '<div class="flex flex-wrap gap-1">';
                                                    foreach($items as $item) {
                                                        echo '<span class="text-[9px] font-bold text-slate-500 bg-slate-100 px-2 py-0.5 rounded">' . htmlspecialchars($item['field_name']) . ' x' . (float)$item['quantity'] . '</span>';
                                                    }
                                                    echo '</div>';
                                                }
                                            } else {
                                                echo '<div class="flex items-center gap-2 text-[10px] font-bold text-slate-400">';
                                                echo getPaymentIcon($t['status'], $db_modes);
                                                echo '<span class="uppercase">' . htmlspecialchars($t['status']) . '</span>';
                                                echo '</div>';
                                            }
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <?php if($t['type'] == 'credit'): ?>
                                                <div class="text-sm font-black text-red-600">₹<?= number_format($net_amt, 2) ?></div>
                                                <?php if($t['discount_percentage'] > 0): ?>
                                                    <div class="text-[10px] font-bold text-slate-300 line-through">₹<?= number_format($t['amount'], 2) ?></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-slate-200">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <?php if($t['type'] == 'payment'): ?>
                                                <div class="text-sm font-black text-emerald-600">₹<?= number_format($t['amount'], 2) ?></div>
                                            <?php else: ?>
                                                <span class="text-slate-200">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-20 text-center">
                                            <div class="flex flex-col items-center gap-4">
                                                <div class="w-16 h-16 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center text-3xl"><i class="fas fa-receipt"></i></div>
                                                <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest">No Transactions Found</h3>
                                                <p class="text-xs text-slate-400 font-medium">Your credit history with shops will appear here automatically.</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                </div>

                <div class="lg:hidden bg-white border border-slate-200 rounded-[2rem] p-6 flex items-center justify-between shadow-sm mb-8">
                    <div>
                        <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Outstanding</div>
                        <div class="text-2xl font-black text-red-600">₹<?= number_format($total_due, 2) ?></div>
                    </div>
                    <div class="w-12 h-12 bg-red-50 text-red-600 rounded-2xl flex items-center justify-center text-lg"><i class="fas fa-wallet"></i></div>
                </div>
    </main>
</div>

<footer class="bg-white border-t border-slate-200 py-6 text-center">
    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">© <?= date('Y') ?> KhataLink — Premium Digital Ledger</div>
</footer>

<script>
const LEDGER_FEE_PCT = <?= LEDGER_PLATFORM_COMMISSION_PERCENT ?>;
const PG_FEE_PCT = <?= PG_FEE_PERCENT ?>;
const SHOP_FEE_PCT = <?= SHOP_SERVICE_FEE_PERCENT ?>;

function updatePayBreakdown() {
    const mode = document.getElementById('payModeSelect').value;
    const isOnline = ['PhonePe', 'Paytm', 'GPay', 'UPI'].includes(mode);
    const amt = parseFloat(document.getElementById('payAmount').value) || 0;
    const breakdown = document.getElementById('ledgerPayBreakdown');
    
    if (amt > 0 && isOnline) {
        const totalFee = amt * (LEDGER_FEE_PCT / 100);
        const pgFee = (amt + totalFee) * (PG_FEE_PCT / 100);
        const klFee = totalFee + (amt * (SHOP_FEE_PCT / 100)); // Combined margin
        const total = amt + totalFee;

        document.getElementById('baseAmtDisp').innerText = '₹' + amt.toFixed(2);
        document.getElementById('pgFeeAmtDisp').innerText = '₹' + pgFee.toFixed(2);
        document.getElementById('klFeeAmtDisp').innerText = '₹' + klFee.toFixed(2);
        document.getElementById('totalPayableDisp').innerText = '₹' + total.toFixed(2);
        breakdown.classList.remove('hidden');
    } else {
        breakdown.classList.add('hidden');
    }
}

function toggleModeDD() {
    const panel = document.getElementById('modeDDPanel');
    const chevron = document.getElementById('modeChevron');
    panel.classList.toggle('show');
    chevron.classList.toggle('chevron-open');
}

function selectModeDD(el) {
    const value = el.getAttribute('data-value');
    const label = el.getAttribute('data-label');
    const iconSpan = el.querySelector('span').innerHTML;
    document.getElementById('payModeSelect').value = value;
    document.getElementById('modeIcon').innerHTML = iconSpan;
    document.getElementById('modeLabel').textContent = label;
    toggleModeDD();
    syncUPIBtn();
}

function syncUPIBtn() {
    const val = document.getElementById('payModeSelect').value;
    const upiBtn = document.getElementById('upiRedirectBtn');
    const notifyBtn = document.getElementById('notifyBtnWrapper');
    
    if (['PhonePe', 'Paytm', 'GPay', 'UPI'].includes(val)) {
        upiBtn.classList.remove('hidden');
        notifyBtn.classList.add('hidden');
    } else {
        upiBtn.classList.add('hidden');
        notifyBtn.classList.remove('hidden');
    }
    updatePayBreakdown();
}

document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('modeWrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        document.getElementById('modeDDPanel').classList.remove('show');
    }
});

// Initialize Cashfree Production
const cashfree = Cashfree({ mode: "<?= (CF_MODE === 'PROD') ? 'production' : 'sandbox' ?>" });

/**
 * Notify Shop about payment events (Intent, Cancel, Exit)
 */
function notifyPaymentEvent(shopId, amount, event) {
    fetch('ajax_payment_event_notify.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ shop_id: shopId, amount: amount, event: event })
    });
}

function payViaKhataLinkFromLedger() {
    const shopSelect = document.getElementById('shopSelect');
    const amount = document.getElementById('payAmount').value;
    const shopId = shopSelect.value;

    if (!shopId || !amount || parseFloat(amount) <= 0) {
        Swal.fire('Input Required', 'Please select a shop and enter a valid amount.', 'warning');
        return;
    }
    startCashfreePayment(document.getElementById('upiRedirectBtn'), null, shopId, amount);
}

function startCashfreePayment(btn, bondId = null, shopId = null, customAmount = null, monthlyId = null, platformPay = false) {
    if (!btn.hasAttribute('data-original')) {
        btn.setAttribute('data-original', btn.innerHTML);
    }
    const originalHtml = btn.getAttribute('data-original');

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Initializing...';

    const bodyParams = new URLSearchParams();
    if(bondId) bodyParams.append('bond_id', bondId);
    if(shopId) bodyParams.append('shop_id', shopId);
    if(monthlyId) bodyParams.append('monthly_id', monthlyId);
    if(customAmount) bodyParams.append('amount', customAmount);
    if(platformPay === true) bodyParams.append('platform_pay', '1');

    fetch('cashfree_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: bodyParams.toString()
    })
    .then(async res => {
        const responseClone = res.clone();
        let data;
        try {
            data = await res.json();
        } catch (e) {
            const errText = await responseClone.text();
            console.error("Non-JSON Error:", errText);
            throw new Error("Server communication error.");
        }
        
        if (!data.success && data.needs_platform_pay === true) {
            btn.disabled = false; btn.innerHTML = originalHtml;
            Swal.fire({
                title: '<span class="text-blue-600">Dukandaar Offline Hai</span>',
                html: `<div class="text-sm font-medium text-slate-600"><b>${data.shop_name}</b> ne online payment setup nahi kiya hai. Kya aap <b>KhataLink Platform</b> ko pay karna chahte hain?</div>`,
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-shield-check"></i> Pay to KhataLink',
                cancelButtonText: 'Nahi, Cancel',
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#64748b',
                customClass: {
                    popup: 'rounded-[2.5rem] p-8',
                    confirmButton: 'rounded-xl font-black uppercase tracking-widest text-[10px] px-6 py-3',
                    cancelButton: 'rounded-xl font-black uppercase tracking-widest text-[10px] px-6 py-3'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    startCashfreePayment(btn, bondId, shopId, customAmount, monthlyId, true);
                } else {
                    // Fixed: Use passed parameters because data might be partially defined
                    notifyPaymentEvent(shopId || (data ? data.shop_id : 0), customAmount || (data ? data.amount : 0), 'cancel');
                }
            });
            return;
        }

        if (!data.success) {
            Swal.fire({ title: "Payment Error", text: data.message || "Order creation failed.", icon: "error", confirmButtonColor: "#2563eb" });
            btn.disabled = false; btn.innerHTML = originalHtml;
            return;
        }

        // Notify Intent
        notifyPaymentEvent(shopId || data.shop_id, customAmount || data.amount, 'intent');

        // Open Cashfree Checkout
        let checkoutOptions = {
            paymentSessionId: data.payment_session_id,
            redirectTarget: "_self"
        };
        cashfree.checkout(checkoutOptions);
    })
    .catch(err => { 
        Swal.fire({
            title: 'Request Failed',
            text: err.message || 'Server connection error.',
            icon: 'error',
            confirmButtonColor: '#2563eb'
        });
        btn.disabled = false; 
        btn.innerHTML = originalHtml; 
    });
}

</script>

</body>
</html>