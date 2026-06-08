<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';

// ===== FLUTTER API =====
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    header('Content-Type: application/json');
    $token = $_SERVER['HTTP_AUTHORIZATION']?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id_api = $parts[0]?? 0;

    $customer_id_api = (int)($_GET['customer_id']?? 0);

    if(!$shop_id_api ||!$customer_id_api) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized or missing parameters.']);
        exit();
    }

    // ── NEW: Handle POST requests for Flutter ────────────────────────────────
    if($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        // 1. DELETE TRANSACTION
        if(isset($input['delete_transaction'])) {
            $delete_type = $input['delete_type']?? '';
            $delete_id = (int)($input['id']?? 0);

            if($delete_type == 'credit' && $delete_id > 0) {
                $check = $pdo->prepare("SELECT id FROM udhar_entries WHERE id =? AND shop_id =? AND customer_id =?");
                $check->execute([$delete_id, $shop_id_api, $customer_id_api]);
                if($check->rowCount() > 0) {
                    $pdo->prepare("DELETE FROM udhar_entries WHERE id =?")->execute([$delete_id]);
                    echo json_encode(['success' => true, 'message' => 'Udhar entry deleted']);
                    exit();
                }
            } elseif($delete_type == 'payment' && $delete_id > 0) {
                $check = $pdo->prepare("SELECT id FROM payment_history WHERE id =? AND shop_id =? AND customer_id =?");
                $check->execute([$delete_id, $shop_id_api, $customer_id_api]);
                if($check->rowCount() > 0) {
                    $pdo->prepare("DELETE FROM payment_history WHERE id =?")->execute([$delete_id]);
                    echo json_encode(['success' => true, 'message' => 'Payment deleted']);
                    exit();
                }
            }
            echo json_encode(['success' => false, 'message' => 'Invalid delete request or not authorized']);
            exit();
        }

        // 2. Toggle GST
        if(isset($input['toggle_gst'])) {
            $new_status = $input['current_status']? 0 : 1;
            $pdo->prepare("UPDATE shop_customers SET show_gst =? WHERE shop_id =? AND customer_id =?")
                ->execute([$new_status, $shop_id_api, $customer_id_api]);
            echo json_encode(['success' => true, 'message' => 'GST visibility updated']);
            exit();
        }

        // 3. Assign Tier
        if(isset($input['assign_tier'])) {
            $tier_id = $input['tier_id']?? null;
            $pdo->prepare("UPDATE shop_customers SET tier_id =? WHERE shop_id =? AND customer_id =?")
                ->execute([$tier_id, $shop_id_api, $customer_id_api]);
            echo json_encode(['success' => true, 'message' => 'Tier assigned']);
            exit();
        }
    }

    // ── GET: Fetch Data ──────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.unique_id, c.email, c.phone, c.truecaller_verified, sc.added_at, sc.show_gst, sc.tier_id, ct.tier_name, ct.discount_percentage
        FROM customers c
        JOIN shop_customers sc ON c.id = sc.customer_id
        LEFT JOIN customer_tiers ct ON sc.tier_id = ct.id
        WHERE sc.shop_id =? AND c.id =?
    ");
    $stmt->execute([$shop_id_api, $customer_id_api]);
    $customer = $stmt->fetch();

    if(!$customer) {
        echo json_encode(['success' => false, 'message' => 'Customer not found or not linked to this shop.']);
        exit();
    }

    $total_credit = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM udhar_entries WHERE shop_id =? AND customer_id =?");
    $total_credit->execute([$shop_id_api, $customer_id_api]);
    $total_credit = (float)$total_credit->fetchColumn();

    $total_paid = $pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM payment_history WHERE shop_id =? AND customer_id =?");
    $total_paid->execute([$shop_id_api, $customer_id_api]);
    $total_paid = (float)$total_paid->fetchColumn();

    $current_due = $pdo->prepare("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE shop_id =? AND customer_id =? AND status = 'open'");
    $current_due->execute([$shop_id_api, $customer_id_api]);
    $current_due = (float)$current_due->fetchColumn();

    $history_query = "
        (SELECT 'credit' as type, id, total_amount as amount, total_remaining, created_at as date, status, discount_percentage, pos_bill_id FROM udhar_entries WHERE shop_id =? AND customer_id =?)
        UNION ALL
        (SELECT 'payment' as type, MIN(id) as id, SUM(amount_paid) as amount, 0 as total_remaining, payment_date as date, payment_mode as status, 0 as discount_percentage, NULL as pos_bill_id FROM payment_history WHERE shop_id =? AND customer_id =? GROUP BY payment_date, payment_mode)
        ORDER BY date DESC
    ";
    $stmt_hist = $pdo->prepare($history_query);
    $stmt_hist->execute([$shop_id_api, $customer_id_api, $shop_id_api, $customer_id_api]);
    $transactions = $stmt_hist->fetchAll();

    echo json_encode([
        'success' => true,
        'customer' => [
            'id' => (int)$customer['id'],
            'name' => $customer['name'],
            'unique_id' => $customer['unique_id'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
            'added_at' => $customer['added_at'],
            'show_gst' => (bool)$customer['show_gst'],
            'tier_name' => $customer['tier_name'],
            'discount_percentage' => (float)$customer['discount_percentage'],
            'total_credit' => $total_credit,
            'total_paid' => $total_paid,
            'current_due' => $current_due,
        ],
        'transactions' => $transactions,
    ]);
    exit();
}
// ===== END FLUTTER API =====

require_once '../includes/track_visitor.php';
track_visitor($pdo);

if(!isset($_SESSION['shop_id'])) {
    header("Location:../auth/login.php?type=shop");
    exit();
}

if(!isset($_GET['customer_id'])) {
    header("Location: customers.php");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$customer_id = (int)$_GET['customer_id'];

// ── WEB: Handle GET Delete ──────────────────────────────────────────────────
if(isset($_GET['delete_type']) && isset($_GET['id'])) {
    $delete_type = $_GET['delete_type'];
    $delete_id = (int)$_GET['id'];

    if($delete_type == 'credit') {
        $check = $pdo->prepare("SELECT id FROM udhar_entries WHERE id =? AND shop_id =? AND customer_id =?");
        $check->execute([$delete_id, $shop_id, $customer_id]);
        if($check->rowCount() > 0) {
            $pdo->prepare("DELETE FROM udhar_entries WHERE id =?")->execute([$delete_id]);
        }
    } elseif($delete_type == 'payment') {
        $check = $pdo->prepare("SELECT id FROM payment_history WHERE id =? AND shop_id =? AND customer_id =?");
        $check->execute([$delete_id, $shop_id, $customer_id]);
        if($check->rowCount() > 0) {
            $pdo->prepare("DELETE FROM payment_history WHERE id =?")->execute([$delete_id]);
        }
    }
    header("Location: customer_details.php?customer_id=". $customer_id. "&success=Record deleted");
    exit();
}

// ── WEB: Handle POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_gst'])) {
    $status = (int)$_POST['current_status']? 0 : 1;
    $pdo->prepare("UPDATE shop_customers SET show_gst =? WHERE shop_id =? AND customer_id =?")
        ->execute([$status, $shop_id, $customer_id]);
    header("Location: customer_details.php?customer_id=". $customer_id);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_tier'])) {
    $tier_id =!empty($_POST['tier_id'])? (int)$_POST['tier_id'] : null;
    $pdo->prepare("UPDATE shop_customers SET tier_id =? WHERE shop_id =? AND customer_id =?")
        ->execute([$tier_id, $shop_id, $customer_id]);
    header("Location: customer_details.php?customer_id=". $customer_id. "&success=Tier assigned successfully!");
    exit();
}

$stmt = $pdo->prepare("
    SELECT c.*, sc.added_at, sc.show_gst, sc.tier_id, ct.tier_name, ct.discount_percentage
    FROM customers c
    JOIN shop_customers sc ON c.id = sc.customer_id
    LEFT JOIN customer_tiers ct ON sc.tier_id = ct.id
    WHERE sc.shop_id =? AND c.id =?
");
$stmt->execute([$shop_id, $customer_id]);
$customer = $stmt->fetch();

$tiers_stmt = $pdo->prepare("SELECT id, tier_name, discount_percentage FROM customer_tiers WHERE shop_id =? ORDER BY tier_name ASC");
$tiers_stmt->execute([$shop_id]);
$available_tiers = $tiers_stmt->fetchAll();

if(!$customer) {
    header("Location: customers.php");
    exit();
}

$stmt_s = $pdo->prepare("SELECT upi_id FROM shop_owners WHERE id =?");
$stmt_s->execute([$shop_id]);
$shop_info = $stmt_s->fetch();

$total_credit = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM udhar_entries WHERE shop_id =? AND customer_id =?");
$total_credit->execute([$shop_id, $customer_id]);
$total_credit = $total_credit->fetchColumn();

$total_paid = $pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM payment_history WHERE shop_id =? AND customer_id =?");
$total_paid->execute([$shop_id, $customer_id]);
$total_paid = $total_paid->fetchColumn();

$current_due = $pdo->prepare("SELECT COALESCE(SUM(total_remaining), 0) FROM udhar_entries WHERE shop_id =? AND customer_id =? AND status = 'open'");
$current_due->execute([$shop_id, $customer_id]);
$current_due = $current_due->fetchColumn();

$history_query = "
    (SELECT 'credit' as type, id, total_amount as amount, total_remaining, created_at as date, status, discount_percentage, pos_bill_id FROM udhar_entries WHERE shop_id =? AND customer_id =?)
    UNION ALL
    (SELECT 'payment' as type, MIN(id) as id, SUM(amount_paid) as amount, 0 as total_remaining, payment_date as date, payment_mode as status, 0 as discount_percentage, NULL as pos_bill_id FROM payment_history WHERE shop_id =? AND customer_id =? GROUP BY payment_date, payment_mode)
    ORDER BY date DESC
";
$stmt_hist = $pdo->prepare($history_query);
$stmt_hist->execute([$shop_id, $customer_id, $shop_id, $customer_id]);
$transactions = $stmt_hist->fetchAll();

$base_url = (isset($_SERVER['HTTPS'])? "https" : "http"). "://$_SERVER[HTTP_HOST]/khatalink";
$statement_link = $base_url. "/shop/generate_statement.php?customer_id=". $customer_id;
$wp_message = "Hi *". $customer['name']. "*, this is a reminder from *KhataLink*.\n\nYour current balance due at *". $_SESSION['shop_name']. "* is *₹". number_format($current_due, 2). "*.\n\nView statement: ". $statement_link. "\n\nThank you!";
$wp_link = "https://api.whatsapp.com/send?phone=". ($customer['phone']?? ''). "&text=". urlencode($wp_message);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($customer['name'])?> — Ledger</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 text-slate-900 font-[Inter]">

<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-9 w-auto">
        </a>
    </div>
    <div class="hidden sm:flex items-center gap-2 bg-blue-50 border border-blue-100 text-blue-700 text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-wider">
        <i class="fas fa-store"></i>
        <?= htmlspecialchars($_SESSION['shop_name'])?>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/shop_sidebar.php';?>
    <div class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">
        <a href="customers.php" class="inline-flex items-center gap-2 text-slate-500 hover:text-blue-600 font-bold text-xs uppercase tracking-widest mb-6 transition-all">
            <i class="fas fa-arrow-left text-[10px]"></i> Back to Database
        </a>

        <?php if(isset($_GET['success'])):?>
            <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-medium mb-6 flex items-center gap-3">
                <i class="fas fa-check-circle me-1"></i> <?= htmlspecialchars($_GET['success'])?>
            </div>
        <?php endif;?>

        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-10 shadow-sm mb-8">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div>
                    <div class="bg-blue-50 text-blue-700 text-[10px] font-black px-3 py-1 rounded-lg uppercase tracking-[0.2em] mb-3 inline-block">Customer Identity</div>
                    <div class="flex items-center gap-3 mb-2">
                        <h1 class="text-3xl md:text-4xl font-black tracking-tight text-slate-900"><?= htmlspecialchars($customer['name'])?></h1>
                        <?php if($customer['truecaller_verified']): ?>
                            <div class="flex items-center gap-1 bg-blue-600 text-white px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest shadow-lg shadow-blue-200">
                                <i class="fas fa-shield-check"></i> Identity Verified
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-wrap items-center gap-y-2 gap-x-4 text-slate-400 text-sm font-medium">
                        <span class="flex items-center gap-1.5"><i class="fas fa-fingerprint text-xs"></i> <?= $customer['unique_id']?></span>
                        <span class="w-1.5 h-1.5 rounded-full bg-slate-200 hidden md:block"></span>
                        <span class="flex items-center gap-1.5"><i class="far fa-envelope text-xs"></i> <?= htmlspecialchars($customer['email'])?></span>
                        <span class="w-1.5 h-1.5 rounded-full bg-slate-200 hidden md:block"></span>
                        <span class="flex items-center gap-1.5"><i class="far fa-calendar-alt text-xs"></i> Since <?= date('d M Y', strtotime($customer['added_at']))?></span>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row gap-2 w-full md:w-auto mt-4 md:mt-0">
                    <a href="udhar.php?customer_id=<?= $customer_id?>" class="bg-red-600 text-white font-bold px-4 py-2.5 rounded-2xl hover:bg-red-700 transition-all shadow-lg shadow-red-100 flex items-center justify-center gap-2 text-xs uppercase tracking-widest">
                        <i class="fas fa-plus text-xs"></i> Credit
                    </a>
                    <a href="payment.php?customer_id=<?= $customer_id?>" class="bg-emerald-600 text-white font-bold px-4 py-2.5 rounded-2xl hover:bg-emerald-700 transition-all shadow-lg shadow-emerald-100 flex items-center justify-center gap-2 text-xs uppercase tracking-widest">
                        <i class="fas fa-hand-holding-usd text-xs"></i> Payment
                    </a>
                    <a href="<?= $wp_link?>" target="_blank" class="bg-slate-900 text-white font-bold px-4 py-2.5 rounded-2xl hover:bg-blue-600 transition-all shadow-lg shadow-slate-200 flex items-center justify-center gap-2 text-xs uppercase tracking-widest">
                        <i class="fab fa-whatsapp text-xs"></i> WhatsApp
                    </a>
                </div>
            </div>

            <div class="mt-10 pt-8 border-t border-slate-100 grid grid-cols-2 md:grid-cols-4 gap-4">
                 <a href="generate_statement.php?customer_id=<?= $customer_id?>" target="_blank" class="bg-slate-50 text-slate-600 font-bold p-3 rounded-2xl text-center hover:bg-blue-50 hover:text-blue-600 transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-file-pdf"></i> Statement
                </a>
                <a href="fields.php?customer_id=<?= $customer_id?>" class="bg-slate-50 text-slate-600 font-bold p-3 rounded-2xl text-center hover:bg-blue-50 hover:text-blue-600 transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-sliders-h"></i> Custom Fields
                </a>
                <form method="POST" class="contents">
                    <input type="hidden" name="current_status" value="<?= $customer['show_gst']?>">
                    <button type="submit" name="toggle_gst" class="bg-slate-50 text-slate-600 font-bold p-3 rounded-2xl text-center hover:bg-blue-50 hover:text-blue-600 transition-all flex items-center justify-center gap-2 w-full">
                         <i class="fas fa-file-invoice"></i> GST: <?= $customer['show_gst']? 'ON' : 'OFF'?>
                    </button>
                </form>
                <form method="POST" class="contents">
                    <select name="tier_id" class="bg-slate-50 text-slate-600 font-bold p-3 rounded-2xl text-center hover:bg-blue-50 hover:text-blue-600 transition-all flex items-center justify-center gap-2 w-full cursor-pointer appearance-none outline-none border-none" onchange="this.form.submit()">
                        <option value="">+ Assign Tier</option>
                         <?php foreach($available_tiers as $tier):?>
                            <option value="<?= $tier['id']?>" <?= $customer['tier_id'] == $tier['id']? 'selected' : ''?>>
                                <?= htmlspecialchars($tier['tier_name'])?> (<?= (int)$tier['discount_percentage']?>%)
                            </option>
                        <?php endforeach;?>
                    </select>
                    <input type="hidden" name="assign_tier" value="1">
                </form>
            </div>
        </div>

        <div class="grid grid-cols-3 gap-2 sm:gap-4 mb-8">
            <div class="bg-white border-2 border-red-100 rounded-xl sm:rounded-2xl p-2 sm:p-5 shadow-xl shadow-red-100/20 overflow-hidden">
                <div class="flex flex-col h-full justify-between">
                    <div>
                        <div class="text-slate-400 text-[8px] sm:text-[10px] font-black uppercase tracking-widest mb-1 truncate">Outstanding</div>
                        <div class="text-sm sm:text-2xl font-black text-red-600 tracking-tight">₹<?= number_format($current_due, 0)?></div>
                    </div>
                    <?php if($current_due > 0 &&!empty($shop_info['upi_id'])):?>
                        <a href="upi://pay?pa=<?= htmlspecialchars($shop_info['upi_id'])?>&pn=<?= urlencode($_SESSION['shop_name'])?>&am=<?= number_format($current_due, 2, '.', '')?>&cu=INR" class="mt-2 bg-red-600 text-white text-[7px] sm:text-[9px] font-black py-1.5 rounded-lg text-center uppercase tracking-widest upi-pay-btn">Pay Full Balance</a>
                    <?php endif;?>
                </div>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl sm:rounded-2xl p-2 sm:p-5 shadow-sm overflow-hidden">
                <div class="text-slate-400 text-[8px] sm:text-[10px] font-black uppercase tracking-widest mb-1 truncate">Total Credit</div>
                <div class="text-sm sm:text-2xl font-black text-slate-900 tracking-tight">₹<?= number_format($total_credit, 0)?></div>
            </div>
            <div class="bg-white border border-slate-200 rounded-xl sm:rounded-2xl p-2 sm:p-5 shadow-sm overflow-hidden">
                <div class="text-slate-400 text-[8px] sm:text-[10px] font-black uppercase tracking-widest mb-1 truncate">Recovered</div>
                <div class="text-sm sm:text-2xl font-black text-emerald-600 tracking-tight">₹<?= number_format($total_paid, 0)?></div>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-[2rem] overflow-hidden shadow-sm shadow-slate-200/50 ledger-table">
            <div class="p-6 border-b border-slate-100 bg-slate-50/50 flex flex-col md:flex-row md:items-center justify-between gap-4">
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2 uppercase tracking-tight">
                    <i class="fas fa-book text-blue-600"></i> Master Ledger
                </h3>
                <div class="flex items-center gap-3">
                    <input type="text" id="ledgerSearch" onkeyup="filterLedger()" placeholder="Quick filter..."
                        class="bg-white border border-slate-200 rounded-xl px-4 py-2 text-xs focus:ring-4 focus:ring-blue-500/10 outline-none w-40">
                    <button type="button" id="printSelectedBtn" class="hidden items-center gap-2 bg-slate-900 text-white text-[10px] font-black px-4 py-2.5 rounded-xl hover:bg-blue-600 transition-all uppercase tracking-widest" onclick="printSelected()">
                        <i class="fas fa-print"></i> Export (<span id="selectedCount">0</span>)
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-50/50 border-b border-slate-100">
                            <th class="px-3 sm:px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest w-12 text-center hidden sm:table-cell">
                                <input type="checkbox" id="selectAll" onclick="toggleAll(this)" class="rounded accent-blue-600">
                            </th>
                            <th class="px-3 sm:px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest min-w-[80px]">Date</th>
                            <th class="px-3 sm:px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden md:table-cell min-w-[120px]">Description</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Debit (-)</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Credit (+)</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php if(count($transactions) > 0):?>
                        <?php foreach($transactions as $t):
                            $entry_discount = ($t['type'] == 'credit')? ($t['amount'] * ($t['discount_percentage'] / 100)) : 0;
                            $net_amt = $t['amount'] - $entry_discount;
                       ?>
                        <tr class="hover:bg-slate-50 transition-colors whitespace-nowrap">
                            <td class="px-3 sm:px-6 py-4 text-center hidden sm:table-cell">
                                <?php if($t['type'] == 'credit'):?>
                                <input type="checkbox" class="entry-checkbox rounded accent-blue-600" value="<?= $t['id']?>" onclick="updatePrintButton()">
                                <?php endif;?>
                            </td>
                            <td class="px-3 sm:px-6 py-4">
                                <div class="text-[11px] font-bold text-slate-900"><?= date('d M Y', strtotime($t['date']))?></div>
                                <div class="text-[9px] font-medium text-slate-400"><?= date('h:i A', strtotime($t['date']))?></div>
                            </td>
                            <td class="px-6 py-5 hidden md:table-cell">
                                <?php if($t['type'] == 'credit'):
                                    $items_stmt = $pdo->prepare("SELECT field_name, quantity, rate, amount FROM udhar_items WHERE entry_id =?");
                                    $items_stmt->execute([$t['id']]);
                                    $items = $items_stmt->fetchAll();
                                    if ($t['pos_bill_id']) {
                                        echo '<a href="export_pos_bill.php?bill_id='. $t['pos_bill_id']. '" target="_blank" class="text-[9px] font-bold text-blue-600 hover:underline me-2"><i class="fas fa-file-invoice"></i> View Bill</a>';
                                    }

                                    if($items):?>
                                <div class="flex flex-wrap gap-1.5 mt-1">
                                    <?php foreach($items as $item):?>
                                    <span class="bg-slate-100 text-slate-600 text-[9px] font-black px-2 py-0.5 rounded-md uppercase tracking-tight"><?= htmlspecialchars($item['field_name'])?> (<?= (float)$item['quantity']?>)</span>
                                    <?php endforeach;?>
                                </div>
                                <?php if($t['discount_percentage'] > 0):
                                    $disc_amt = $t['amount'] * ($t['discount_percentage'] / 100);
                               ?>
                                    <div class="text-[9px] font-bold text-emerald-600 mt-1 uppercase">
                                        <i class="fas fa-tag"></i> <?= (int)$t['discount_percentage']?>% Tier Discount Applied (-₹<?= number_format($disc_amt, 2)?>)
                                    </div>
                                <?php endif;?>
                                <?php else:?>
                                <span class="text-[10px] text-slate-400 italic">Bill #<?= $t['id']?></span>
                                <?php endif;?>
                                <?php else:?>
                                <span class="text-xs font-black text-emerald-600 flex items-center gap-1.5"><i class="fas fa-check-circle text-[10px]"></i> RECEIVED VIA <?= strtoupper($t['status'])?></span>
                                <?php endif;?>
                            </td>
                            <td class="px-3 sm:px-6 py-4 text-right font-black text-xs text-red-600">
                                <?php if($t['type'] == 'credit'):?>
                                    <div>₹<?= number_format($net_amt, 2)?></div>
                                    <?php if($t['discount_percentage'] > 0):?>
                                        <div class="text-[9px] line-through text-slate-400 font-medium">₹<?= number_format($t['amount'], 2)?></div>
                                    <?php endif;?>
                                <?php else: echo '—'; endif;?>
                            </td>
                            <td class="px-3 sm:px-6 py-4 text-right font-black text-xs text-emerald-600">
                                <?= $t['type'] == 'payment'? '₹'.number_format($t['amount'], 2) : '—'?>
                            </td>
                            <td class="px-3 sm:px-6 py-4 text-right">
                                <div class="flex justify-end gap-2">
                                    <?php if($t['type'] == 'credit'):?>
                                        <?php if($t['status'] == 'closed'):?>
                                            <span class="bg-emerald-50 text-emerald-600 text-[9px] font-black px-2 py-1 rounded-lg uppercase tracking-widest">Cleared</span>
                                        <?php endif;?>
                                    <?php else:?>
                                        <span class="bg-blue-50 text-blue-600 text-[9px] font-black px-2 py-1 rounded-lg uppercase tracking-widest italic">Received</span>
                                    <?php endif;?>
                                    <a href="customer_details.php?customer_id=<?= $customer_id?>&delete_type=<?= $t['type']?>&id=<?= $t['id']?>" class="text-slate-300 hover:text-red-600 transition-colors" onclick="return confirm('Delete this record?')">
                                        <i class="fas fa-trash text-[10px]"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach;?>
                        <?php else:?>
                        <tr>
                            <td colspan="6" class="px-6 py-20 text-center">
                                <div class="text-slate-300 text-4xl mb-4"><i class="fas fa-history"></i></div>
                                <div class="text-slate-400 text-sm font-medium italic">No ledger history found for this account.</div>
                            </td>
                        </tr>
                        <?php endif;?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function filterLedger() {
    const input = document.getElementById('ledgerSearch');
    const filter = input.value.toLowerCase();
    const table = document.querySelector('.ledger-table');
    const tr = table.getElementsByTagName('tr');

    for (let i = 1; i < tr.length; i++) {
        const detailsTd = tr[i].getElementsByTagName('td')[3];
        const creditTd = tr[i].getElementsByTagName('td')[4];
        const paymentTd = tr[i].getElementsByTagName('td')[5];

        if (detailsTd || creditTd || paymentTd) {
            const text = (detailsTd.textContent || detailsTd.innerText) + ' ' +
                         (creditTd.textContent || creditTd.innerText) + ' ' +
                         (paymentTd.textContent || paymentTd.innerText);

            tr[i].style.display = text.toLowerCase().indexOf(filter) > -1? "" : "none";
        }
    }
}

function toggleAll(source) {
    document.querySelectorAll('.entry-checkbox').forEach(cb => cb.checked = source.checked);
    updatePrintButton();
}

function updatePrintButton() {
    const selected = document.querySelectorAll('.entry-checkbox:checked');
    const btn = document.getElementById('printSelectedBtn');
    btn.style.display = selected.length > 0? 'flex' : 'none';
    document.getElementById('selectedCount').innerText = selected.length;
}

function printSelected() {
    const selected = Array.from(document.querySelectorAll('.entry-checkbox:checked')).map(cb => cb.value);
    if(selected.length > 0) {
        window.open('generate_statement.php?customer_id=<?= $customer_id?>&ids=' + selected.join(','), '_blank');
    }
}

function openSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    if (sidebar) { sidebar.classList.add('open'); sidebar.classList.remove('hidden'); }
    if (overlay) { overlay.classList.remove('hidden'); setTimeout(() => overlay.classList.add('opacity-100'), 10); }
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('overlay');
    if (sidebar) { sidebar.classList.remove('open'); }
    if (overlay) { overlay.classList.remove('opacity-100'); setTimeout(() => overlay.classList.add('hidden'), 300); }
}

document.querySelectorAll('.upi-pay-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        if (!/Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
            e.preventDefault();
            alert("Online Payment only works on mobile phones with UPI apps (PhonePe, GPay, etc.) installed.");
        }
    });
});
</script>

</body>
</html>