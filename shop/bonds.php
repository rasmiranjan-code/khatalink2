<?php
session_start();
require_once '../includes/db.php';

// Ensure no output before headers
ob_start();

// ===== FLUTTER API HANDLING =====
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    ob_clean(); // Clear any accidental output before sending JSON header
    header('Content-Type: application/json');
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id_api = (int)($parts[0] ?? 0); // Cast to int immediately

    if (!$shop_id_api) { echo json_encode(['success' => false, 'message' => 'Unauthorized. Invalid token or shop ID missing.']); exit(); }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';
        $req_id = (int)($data['request_id'] ?? $data['bond_id'] ?? 0);

        if ($action === 'create_bond') {
            $customer_id = (int)($data['customer_id'] ?? 0);
            $amount = (float)($data['amount'] ?? 0);
            $initial_paid = (float)($data['initial_paid'] ?? 0);
            $due_date = $data['due_date'] ?? '';
            $nominee_name = trim($data['nominee_name'] ?? '');
            $nominee_phone = trim($data['nominee_phone'] ?? '');
            $repayment_type = $data['repayment_type'] ?? 'one-time';
            $installment_count = (int)($data['installment_count'] ?? 0);
            $terms = trim($data['terms'] ?? '');
            $cust_sig_base64 = $data['customer_signature_base64'] ?? null;
            $nom_sig_base64 = $data['nominee_signature_base64'] ?? null;

            $cust_sig = null; $nom_sig = null;
            $upload_dir = '../assets/img/bonds/';
            if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            if($cust_sig_base64) {
                $cust_sig = uniqid('cs_') . '.png';
                file_put_contents($upload_dir . $cust_sig, base64_decode($cust_sig_base64));
            }
            if($nom_sig_base64) {
                $nom_sig = uniqid('ns_') . '.png';
                file_put_contents($upload_dir . $nom_sig, base64_decode($nom_sig_base64));
            }

            $status = ($initial_paid >= $amount) ? 'closed' : 'active';
            $stmt = $pdo->prepare("INSERT INTO bonds (shop_id, customer_id, amount, initial_paid, paid_amount, due_date, nominee_name, nominee_phone, customer_signature, nominee_signature, terms, installment_count, repayment_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $success = $stmt->execute([$shop_id_api, $customer_id, $amount, $initial_paid, $initial_paid, $due_date, $nominee_name, $nominee_phone, $cust_sig, $nom_sig, $terms, $installment_count, $repayment_type, $status]);
            echo json_encode(['success' => $success, 'message' => $success ? 'Bond created!' : 'Failed']);
            exit();
        }

        if ($action === 'accept_payment') {
            $pay_amount = (float)($data['amount_paid'] ?? 0);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO bond_payments (bond_id, amount_paid, payment_status) VALUES (?, ?, 'completed')")->execute([$req_id, $pay_amount]);
            $pdo->prepare("UPDATE bonds SET paid_amount = paid_amount + ? WHERE id = ?")->execute([$pay_amount, $req_id]);
            $b = $pdo->prepare("SELECT amount, paid_amount FROM bonds WHERE id = ?");
            $b->execute([$req_id]); $bond = $b->fetch();
            if ($bond && (float)$bond['paid_amount'] >= (float)$bond['amount']) {
                $pdo->prepare("UPDATE bonds SET status = 'closed' WHERE id = ?")->execute([$req_id]);
            }
            $pdo->commit();
            
            // Fetch customer and shop name for notification
            $stmt_info = $pdo->prepare("SELECT b.customer_id, c.name as customer_name, s.shop_name FROM bonds b JOIN customers c ON b.customer_id = c.id JOIN shop_owners s ON b.shop_id = s.id WHERE b.id = ?");
            $stmt_info->execute([$req_id]); $info = $stmt_info->fetch();
            sendKhataPush($pdo, (int)$info['customer_id'], 'customer', "Bond Payment Received! ✅", "Aapka ₹" . number_format($pay_amount, 2) . " ka installment " . $info['shop_name'] . " ne record kar liya hai. Dhanyawad!", null, ['type' => 'bond_payment', 'bond_id' => (string)$req_id]);

            echo json_encode(['success' => true, 'message' => 'Payment recorded!']);
            exit();
        }

        if ($action === 'send_warning') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM bond_warnings WHERE bond_id = ?");
            $stmt->execute([$req_id]); $count = $stmt->fetchColumn() + 1;
            if($count <= 3) {
                $pdo->prepare("INSERT INTO bond_warnings (bond_id, warning_number) VALUES (?, ?)")->execute([$req_id, $count]);
                if($count == 3) $pdo->prepare("UPDATE bonds SET status = 'overdue' WHERE id = ?")->execute([$req_id]);
                
                // Fetch customer and shop name for notification
                $stmt_info = $pdo->prepare("SELECT b.customer_id, c.name as customer_name, s.shop_name FROM bonds b JOIN customers c ON b.customer_id = c.id JOIN shop_owners s ON b.shop_id = s.id WHERE b.id = ?");
                $stmt_info->execute([$req_id]); $info = $stmt_info->fetch();
                sendKhataPush($pdo, (int)$info['customer_id'], 'customer', "Bond Reminder! ⚠️", "Aapka Bond #" . str_pad($req_id, 5, '0', STR_PAD_LEFT) . " ka payment pending hai. Kripya jald se jald bhugtan karein.", null, ['type' => 'bond_warning', 'bond_id' => (string)$req_id]);
                echo json_encode(['success' => true, 'message' => "Warning #$count sent!"]);
            } else { echo json_encode(['success' => false, 'message' => 'Max warnings sent']); }
            exit();
        }

        if ($action === 'delete_bond') {
            $pdo->prepare("DELETE FROM bonds WHERE id = ? AND shop_id = ?")->execute([$req_id, $shop_id_api]);
            echo json_encode(['success' => true]);
            exit();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['bond_id'])) {
            $bid = (int)$_GET['bond_id'];
            $stmt = $pdo->prepare("SELECT * FROM bond_payments WHERE bond_id = ? AND payment_status = 'completed' ORDER BY payment_date DESC");
            $stmt->execute([$bid]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'payments' => array_map(function($p) {
                $p['id'] = (int)$p['id'];
                $p['amount_paid'] = (float)$p['amount_paid'];
                return $p;
            }, $payments)]);
            exit();
        }

        $search = $_GET['search'] ?? '';
        $query = "SELECT b.*, c.name as customer_name, c.unique_id, (SELECT COUNT(*) FROM bond_warnings WHERE bond_id = b.id) as warning_count FROM bonds b JOIN customers c ON b.customer_id = c.id WHERE b.shop_id = ?";
        $params = [$shop_id_api];
        if ($search) { $query .= " AND (c.name LIKE ? OR c.unique_id LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
        $query .= " ORDER BY b.created_at DESC";
        $stmt = $pdo->prepare($query); $stmt->execute($params);
        $bonds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'bonds' => array_map(function($bond) {
            $bond['id'] = (int)$bond['id'];
            $bond['shop_id'] = (int)$bond['shop_id'];
            $bond['customer_id'] = (int)$bond['customer_id'];
            $bond['amount'] = (float)$bond['amount'];
            $bond['initial_paid'] = (float)$bond['initial_paid'];
            $bond['paid_amount'] = (float)$bond['paid_amount'];
            $bond['installment_count'] = (int)$bond['installment_count'];
            $bond['warning_count'] = (int)$bond['warning_count'];
            return $bond;
        }, $bonds)]);
        exit();
    }
}

// ===== WEB SESSION CHECK =====
ob_clean(); // Clear any accidental output before sending Location header
if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$error = ''; $success = $_GET['success'] ?? '';

// Fetch Aggregated Bond Stats for Shop
$stats_stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT customer_id) as total_customers,
        SUM(amount) as total_bond_val,
        SUM(paid_amount) as total_paid_val,
        SUM(amount - paid_amount + fine_amount) as total_rem_val
    FROM bonds 
    WHERE shop_id = ?
");
$stats_stmt->execute([$shop_id]);
$bond_stats = $stats_stmt->fetch();

// Handle Installment Payment Submission (Manual Accept)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['pay_installment'])) {
    $bond_id = (int)$_POST['bond_id'];
    $pay_amount = (float)$_POST['pay_amount'];

    if ($pay_amount > 0) {
        $stmt = $pdo->prepare("SELECT amount, paid_amount, initial_paid, installment_count FROM bonds WHERE id = ? AND shop_id = ?");
        $stmt->execute([$bond_id, $shop_id]);
        $bond = $stmt->fetch();

        if ($bond) {
            $total_to_pay = (float)$bond['amount'];
            $already_paid = (float)$bond['paid_amount'];

            try {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO bond_payments (bond_id, amount_paid, payment_status) VALUES (?, ?, 'completed')")->execute([$bond_id, $pay_amount]);
                $pdo->prepare("UPDATE bonds SET paid_amount = paid_amount + ? WHERE id = ?")->execute([$pay_amount, $bond_id]);
                if(($already_paid + $pay_amount) >= $total_to_pay) {
                    $pdo->prepare("UPDATE bonds SET status = 'closed' WHERE id = ?")->execute([$bond_id]);
                }
                $pdo->commit();

                // Fetch customer and shop name for notification
                $stmt_info = $pdo->prepare("SELECT b.customer_id, c.name as customer_name, s.shop_name FROM bonds b JOIN customers c ON b.customer_id = c.id JOIN shop_owners s ON b.shop_id = s.id WHERE b.id = ?");
                $stmt_info->execute([$bond_id]); $info = $stmt_info->fetch();
                sendKhataPush($pdo, (int)$info['customer_id'], 'customer', "Bond Payment Received! ✅", "Aapka ₹" . number_format($pay_amount, 2) . " ka installment " . $info['shop_name'] . " ne record kar liya hai. Dhanyawad!", null, ['type' => 'bond_payment', 'bond_id' => (string)$bond_id]);
                header("Location: bonds.php?success=Payment of ₹$pay_amount recorded!");
                exit();
            } catch (Exception $e) { 
                $pdo->rollBack();
                $error = "Payment failed: " . $e->getMessage(); 
            }
        }
    }
}

// Handle Warning Trigger
if(isset($_GET['send_warning'])) {
    $bond_id = (int)$_GET['send_warning'];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bond_warnings WHERE bond_id = ?");
    $stmt->execute([$bond_id]);
    $count = $stmt->fetchColumn() + 1;

    if($count <= 3) {
        $pdo->prepare("INSERT INTO bond_warnings (bond_id, warning_number) VALUES (?, ?)")->execute([$bond_id, $count]);
        if($count == 3) {
            // Fetch customer and shop name for notification
            $stmt_info = $pdo->prepare("SELECT b.customer_id, c.name as customer_name, s.shop_name FROM bonds b JOIN customers c ON b.customer_id = c.id JOIN shop_owners s ON b.shop_id = s.id WHERE b.id = ?");
            $stmt_info->execute([$bond_id]); $info = $stmt_info->fetch();
            sendKhataPush($pdo, (int)$info['customer_id'], 'customer', "Bond Reminder! ⚠️", "Aapka Bond #" . str_pad($bond_id, 5, '0', STR_PAD_LEFT) . " ka payment pending hai. Kripya jald se jald bhugtan karein.", null, ['type' => 'bond_warning', 'bond_id' => (string)$bond_id]);

            $pdo->prepare("UPDATE bonds SET status = 'overdue' WHERE id = ?")->execute([$bond_id]);
        }
        header("Location: bonds.php?success=Warning #$count sent!");
        exit();
    }
}

// Handle Bond Deletion
if(isset($_GET['delete_bond_id'])) {
    $del_bond_id = (int)$_GET['delete_bond_id'];

    // Fetch bond details to get signature filenames
    $stmt_bond_details = $pdo->prepare("SELECT customer_signature, nominee_signature FROM bonds WHERE id = ? AND shop_id = ?");
    $stmt_bond_details->execute([$del_bond_id, $shop_id]);
    $bond_to_delete = $stmt_bond_details->fetch();

    if($bond_to_delete) {
        // Delete signature image files if they exist
        $upload_dir = '../assets/img/bonds/';
        if($bond_to_delete['customer_signature'] && file_exists($upload_dir . $bond_to_delete['customer_signature'])) {
            unlink($upload_dir . $bond_to_delete['customer_signature']);
        }
        if($bond_to_delete['nominee_signature'] && file_exists($upload_dir . $bond_to_delete['nominee_signature'])) {
            unlink($upload_dir . $bond_to_delete['nominee_signature']);
        }
        // Delete the bond record (ON DELETE CASCADE will handle bond_payments and bond_warnings)
        $pdo->prepare("DELETE FROM bonds WHERE id = ? AND shop_id = ?")->execute([$del_bond_id, $shop_id]);
        header("Location: bonds.php?success=Bond and all associated data deleted successfully.");
        exit();
    }
}

// Fetch Bonds
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$query = "
    SELECT b.*, c.name as customer_name, c.phone as customer_phone, c.unique_id, 
    (SELECT COUNT(*) FROM bond_warnings WHERE bond_id = b.id) as warning_count
    FROM bonds b
    JOIN customers c ON b.customer_id = c.id
    WHERE b.shop_id = ?
";
$params = [$shop_id];

if ($search) {
    $query .= " AND (c.name LIKE ? OR c.unique_id LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$query .= " ORDER BY b.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$bonds = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Active Bonds — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- SweetAlert2 for Premium Popups -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * { font-family: 'Inter', sans-serif; }
        
        /* Desktop Table: visible on lg+ */
        .desktop-table { display: none; }
        @media (min-width: 1024px) {
            .desktop-table { display: block; }
            .mobile-cards { display: none; }
        }

        /* Mobile card action buttons */
        .action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 10px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            transition: all 0.2s ease;
            border: none;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-accept  { background: #059669; color: white; flex: 1; }
        .btn-remind  { background: #111827; color: white; flex: 1; }
        .btn-pdf     { background: #eff6ff; color: #2563eb; border: 1.5px solid #bfdbfe; flex: 1; }
        .btn-warning { background: #fff1f2; color: #dc2626; border: 1.5px solid #fecaca; flex: 1; }

        .btn-accept:active  { background: #047857; }
        .btn-remind:active  { background: #1f2937; }

        /* Progress bar */
        .progress-track {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 99px;
            overflow: hidden;
            margin-top: 8px;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #34d399);
            border-radius: 99px;
            transition: width 0.5s ease;
        }

        /* Bond card */
        .bond-card {
            background: white;
            border-radius: 20px;
            border: 1.5px solid #f1f5f9;
            overflow: hidden;
            box-shadow: 0 1px 8px rgba(0,0,0,0.04);
            transition: box-shadow 0.2s;
        }
        .bond-card:active { box-shadow: 0 4px 20px rgba(0,0,0,0.08); }

        /* Warning dots */
        .warn-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
        }
        .warn-dot.active { background: #ef4444; }
        .warn-dot.inactive { background: #e2e8f0; }

        /* Status badge */
        .badge {
            font-size: 9px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 999px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .badge-active  { background: #eff6ff; color: #2563eb; }
        .badge-closed  { background: #f0fdf4; color: #16a34a; }
        .badge-overdue { background: #fef2f2; color: #dc2626; }
        .badge-action  { background: #dc2626; color: white; }
    </style>
</head>
<body class="bg-slate-50">

<!-- Sidebar Overlay -->
<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-14 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars text-lg"></i>
        </button>
        <a href="dashboard.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-7"></a>
    </div>
    <span class="text-[10px] font-black text-blue-700 uppercase tracking-widest">Bond System</span>
    <a href="create_bond.php" class="bg-slate-900 text-white font-black px-4 py-2 rounded-xl text-[10px] uppercase tracking-widest hover:bg-blue-600 transition-all">
        <i class="fas fa-plus mr-1"></i> New Bond
    </a>
</nav>

<div class="flex">
    <?php include '../includes/shop_sidebar.php'; ?>
    <main class="flex-1 px-4 py-6 md:px-8 md:py-8 max-w-screen-xl mx-auto w-full">

        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-2xl md:text-3xl font-black text-slate-900">Bond System</h1>
            <p class="text-slate-500 text-sm mt-0.5">Monitoring high-value credit commitments.</p>
        </div>

        <!-- Bond Summary Dashboard -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-4 mb-8">
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm hover:border-blue-500 transition-colors">
                <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Bond Holders</div>
                <div class="text-xl font-black text-slate-900"><?= number_format($bond_stats['total_customers'] ?? 0) ?></div>
                <div class="text-[8px] font-bold text-slate-400 mt-1 uppercase">Active customers</div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Bond Value</div>
                <div class="text-xl font-black text-slate-900">₹<?= number_format($bond_stats['total_bond_val'] ?? 0, 0) ?></div>
                <div class="text-[8px] font-bold text-slate-400 mt-1 uppercase">Capital out on bonds</div>
            </div>
            <div class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm">
                <div class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-1">Total Recovered</div>
                <div class="text-xl font-black text-emerald-600">₹<?= number_format($bond_stats['total_paid_val'] ?? 0, 0) ?></div>
                <?php 
                    $perc = ($bond_stats['total_bond_val'] > 0) ? ($bond_stats['total_paid_val'] / $bond_stats['total_bond_val']) * 100 : 0;
                ?>
                <div class="text-[8px] font-bold text-emerald-500 mt-1 uppercase"><?= round($perc, 1) ?>% Collected</div>
            </div>
            <div class="bg-white border border-red-100 rounded-2xl p-4 shadow-sm">
                <div class="text-[9px] font-black text-red-500 uppercase tracking-widest mb-1">Pending Recovery</div>
                <div class="text-xl font-black text-red-600">₹<?= number_format($bond_stats['total_rem_val'] ?? 0, 0) ?></div>
                <div class="text-[8px] font-bold text-red-400 mt-1 uppercase">Future receivables</div>
            </div>
        </div>

        <!-- Search Bar -->
        <form method="GET" class="bg-white border border-slate-200 rounded-[2rem] p-4 mb-8 flex flex-col md:flex-row gap-4 items-end shadow-sm">
            <div class="flex-1 w-full">
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Quick Search</label>
                <input type="text" name="search" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all"
                    placeholder="Search by customer name or Unique ID..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="flex gap-2 w-full md:w-auto">
                <button type="submit" class="flex-1 md:flex-none bg-slate-900 text-white font-bold px-8 py-3 rounded-2xl hover:bg-blue-600 active:scale-95 transition-all flex items-center justify-center gap-2 text-[10px] uppercase tracking-widest shadow-lg shadow-slate-200">
                    <i class="fas fa-search"></i> Filter
                </button>
                <?php if($search): ?>
                <a href="bonds.php" class="flex-1 md:flex-none bg-slate-100 text-slate-600 font-bold px-6 py-3 rounded-2xl hover:bg-slate-200 active:scale-95 transition-all text-center text-[10px] uppercase tracking-widest">
                    Reset
                </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Alerts -->
        <?php if($error): ?>
        <div class="mb-4 bg-red-50 text-red-600 p-4 rounded-2xl border border-red-100 font-bold text-xs"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if($success): ?>
        <div class="mb-4 bg-emerald-50 text-emerald-600 p-4 rounded-2xl border border-emerald-100 font-bold text-xs"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        

        <!-- =================== MOBILE CARDS =================== -->
        <div class="mobile-cards flex flex-col gap-4">
            <?php if(empty($bonds)): ?>
            <div class="p-12 text-center text-slate-400 italic text-sm bg-white rounded-3xl border border-slate-100">No active bonds found.</div>
            <?php endif; ?>

            <?php foreach($bonds as $b): 
                $initial_val = (float)($b['initial_paid'] ?? 0);
                $total_bond  = (float)$b['amount'];
                $paid_so_far = (float)$b['paid_amount'];
                $remaining_at_start = max(0, $total_bond - $initial_val);
                $kist_val = ($b['installment_count'] > 0 && $remaining_at_start > 0) ? ($remaining_at_start / $b['installment_count']) : 0;
                $progress = ($total_bond > 0) ? min(($paid_so_far / $total_bond) * 100, 100) : 0;
                $extra_paid = max(0, $paid_so_far - $initial_val);
                $kists_done = ($kist_val > 0) ? min((int)floor(($extra_paid + ($kist_val * 0.1)) / $kist_val), (int)$b['installment_count']) : 0;
                $rem_bal = max(0, $total_bond - $paid_so_far + $b['fine_amount']);
                $wa_msg = "Bond Payment Reminder\n\nDear " . $b['customer_name'] . ",\n\nBond #" . str_pad($b['id'], 5, '0', STR_PAD_LEFT) . "\nPending: ₹" . number_format($rem_bal, 2) . "\nDue: " . date('d M Y', strtotime($b['due_date'])) . "\n\nPlease settle at " . $_SESSION['shop_name'] . ".\n\nThank you,\nKhataLink";
                $wa_url = "https://api.whatsapp.com/send?phone=" . preg_replace('/[^0-9]/', '', $b['customer_phone'] ?? '') . "&text=" . urlencode($wa_msg);
                $status_class = $b['warning_count']>=3 ? 'badge-action' : ($b['status']==='closed' ? 'badge-closed' : ($b['status']==='overdue' ? 'badge-overdue' : 'badge-active'));
                $status_label = $b['warning_count']>=3 ? 'Action Needed' : $b['status'];
            ?>
            <div class="bond-card">
                <!-- Card Header -->
                <div class="px-4 pt-4 pb-3 flex items-start justify-between">
                    <div class="flex-1 min-w-0 pr-2">
                        <div class="text-sm font-black text-slate-900 truncate"><?= htmlspecialchars($b['customer_name']) ?></div>
                        <div class="text-[10px] font-bold text-slate-400 uppercase mt-0.5"><?= $b['unique_id'] ?> &nbsp;·&nbsp; Bond #<?= str_pad($b['id'], 5, '0', STR_PAD_LEFT) ?></div>
                    </div>
                    <div class="flex flex-col items-end gap-1 flex-shrink-0">
                        <span class="badge <?= $status_class ?>"><?= $status_label ?></span>
                        <div class="text-[10px] font-bold text-slate-400"><?= date('d M Y', strtotime($b['due_date'])) ?></div>
                    </div>
                </div>

                <!-- Amount Strip -->
                <div class="mx-4 mb-3 bg-slate-50 rounded-2xl px-4 py-3 border border-slate-100">
                    <div class="flex justify-between items-center mb-1">
                        <div>
                            <div class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Bond Total</div>
                            <div class="text-lg font-black text-slate-900 leading-tight">₹<?= number_format($total_bond, 2) ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-[9px] font-black text-emerald-500 uppercase tracking-widest">Paid</div>
                            <div class="text-base font-black text-emerald-600 leading-tight">₹<?= number_format($paid_so_far, 2) ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-[9px] font-black text-red-400 uppercase tracking-widest">Remaining</div>
                            <div class="text-base font-black text-red-500 leading-tight">₹<?= number_format($rem_bal, 2) ?></div>
                        </div>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" style="width:<?= $progress ?>%"></div>
                    </div>
                    <?php if($b['repayment_type'] == 'installments' && $b['installment_count'] > 0): ?>
                    <div class="flex justify-between items-center mt-2">
                        <span class="text-[10px] font-bold text-blue-600"><?= $kists_done ?>/<?= $b['installment_count'] ?> Kist Done</span>
                        <span class="text-[10px] font-black text-slate-600">₹<?= number_format($kist_val, 0) ?>/mo</span>
                    </div>
                    <?php else: ?>
                    <div class="mt-2 text-[10px] font-bold text-slate-400 uppercase"><?= htmlspecialchars($b['repayment_type']) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Warning Dots + Action Buttons -->
                <div class="px-4 pb-4 flex flex-col gap-2.5">
                    <!-- Warning indicators -->
                    <div class="flex items-center gap-1.5">
                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest mr-1">Warnings</span>
                        <?php for($i=1;$i<=3;$i++): ?>
                        <div class="warn-dot <?= ($i<=$b['warning_count'])?'active':'inactive' ?>"></div>
                        <?php endfor; ?>
                        <?php if($b['warning_count']>0): ?>
                        <span class="text-[9px] font-bold text-red-500 ml-1"><?= $b['warning_count'] ?>/3 sent</span>
                        <?php endif; ?>
                    </div>

                    <!-- Primary actions row -->
                    <div class="flex gap-2">
                        <?php if($b['status']!='closed'): ?>
                        <button onclick="acceptPayment(<?= $b['id'] ?>, <?= $kist_val ?>)" class="action-btn btn-accept">
                            <i class="fas fa-check-circle text-xs"></i> Accept Kist
                        </button>
                        <?php endif; ?>
                        <a href="<?= $wa_url ?>" target="_blank" class="action-btn btn-remind">
                            <i class="fab fa-whatsapp text-xs"></i> WhatsApp
                        </a>
                    </div>

                    <!-- Secondary actions row -->
                    <div class="flex gap-2">
                        <a href="export_bond.php?id=<?= $b['id'] ?>" target="_blank" class="action-btn btn-pdf">
                            <i class="fas fa-file-pdf text-xs"></i> PDF
                        </a>
                        <button type="button" class="action-btn btn-warning" onclick="confirmDelete(<?= $b['id'] ?>)">
                            <i class="fas fa-trash text-xs"></i> Delete
                        </button>
                        <a href="?send_warning=<?= $b['id'] ?>" class="action-btn btn-warning">
                            <i class="fas fa-exclamation-triangle text-xs"></i> Warning
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </main>
</div>

<!-- Hidden Payment Form -->
<form id="payForm" method="POST" style="display:none;">
    <input type="hidden" name="pay_installment" value="1">
    <input type="hidden" name="bond_id" id="pay_bond_id">
    <input type="hidden" name="pay_amount" id="pay_amount_field">
</form>

<script>
function acceptPayment(id, suggested) {
    Swal.fire({
        title: 'Record Installment Payment',
        text: 'Enter the payment amount (₹):',
        input: 'number',
        inputValue: suggested.toFixed(2),
        inputAttributes: {
            min: 0,
            step: 0.01,
            autofocus: true
        },
        showCancelButton: true,
        confirmButtonText: 'Record Payment',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#059669', // Emerald-600
        cancelButtonColor: '#64748b',  // Slate-500
        customClass: {
            popup: 'rounded-[2rem] p-6',
            input: 'rounded-xl border-2 border-slate-100 font-bold px-4 py-2',
            confirmButton: 'rounded-xl font-bold uppercase tracking-widest text-[10px] px-6 py-3',
            cancelButton: 'rounded-xl font-bold uppercase tracking-widest text-[10px] px-6 py-3'
        }
    }).then((result) => {
        if (result.isConfirmed && result.value > 0) {
            document.getElementById('pay_bond_id').value = id;
            document.getElementById('pay_amount_field').value = result.value;
            document.getElementById('payForm').submit();
        } else if (result.isConfirmed && result.value <= 0) {
            Swal.fire('Error', 'कृपया सही राशि दर्ज करें।', 'error');
        }
    });
}

function confirmDelete(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This will permanently delete the bond and all its payment history.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626', // Red-600
        cancelButtonColor: '#64748b',  // Slate-500
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'No, keep it',
        customClass: {
            popup: 'rounded-[2rem] p-6',
            confirmButton: 'rounded-xl font-bold uppercase tracking-widest text-[10px] px-6 py-3',
            cancelButton: 'rounded-xl font-bold uppercase tracking-widest text-[10px] px-6 py-3'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            // Redirect to the delete URL
            window.location.href = '?delete_bond_id=' + id;
        }
    });
}

function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('overlay').classList.remove('hidden');
    document.getElementById('overlay').classList.add('flex');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.add('hidden');
    document.getElementById('overlay').classList.remove('flex');
}
</script>

<!-- Firebase Professional Notifications -->
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-messaging-compat.js"></script>
<script>
    const firebaseConfig = {
        apiKey: "AIzaSyCM8z5Y_lMephKKsP9U0AtdIisIyKkounE",
        authDomain: "khatalink-63041.firebaseapp.com",
        projectId: "khatalink-63041",
        messagingSenderId: "905429197043",
        appId: "1:905429197043:web:2a0cbefa0fa176fd2c5786"
    };
    if (!firebase.apps.length) firebase.initializeApp(firebaseConfig);
    const messaging = firebase.messaging();
    async function syncToken() {
        try {
            const registration = await navigator.serviceWorker.register('../firebase-messaging-sw.js');
            await navigator.serviceWorker.ready;
            await messaging.getToken({ 
                vapidKey: 'BGixP4kke2vi5l1mpqb_P-GI5xh2OM4KcPQ_8lzQmJqvdJXHG4xFpkYvexfpD_lX7LvBQ1ORR3asE1LQkFeWFHo',
                serviceWorkerRegistration: registration
            });
        } catch (e) { console.error("FCM Sync Error:", e); }
    }
    syncToken();
    messaging.onMessage((payload) => {
        const title = payload.notification?.title || 'Bond Alert';
        const body = payload.notification?.body || 'Kist received or warning sent';
        if (Notification.permission === "granted") {
            const n = new Notification(title, {
                body: body,
                icon: '../assets/favicon.png'
            });
            n.onclick = function() { window.focus(); this.close(); };
        }
    });
</script>

</body>
</html>