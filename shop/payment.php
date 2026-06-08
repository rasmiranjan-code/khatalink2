<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
require_once '../includes/notification_service.php';
track_visitor($pdo);

// Helper function to process payment and update ledger (FIFO)
function processPayment(PDO $pdo, int $shop_id, int $customer_id, float $amount_paid, string $payment_mode) {
    $stmt_entries = $pdo->prepare("SELECT * FROM udhar_entries WHERE customer_id = ? AND shop_id = ? AND status = 'open' ORDER BY created_at ASC");
    // Fetch shop name for notification
    $stmt_shop_name = $pdo->prepare("SELECT shop_name FROM shop_owners WHERE id = ?");
    $stmt_shop_name->execute([$shop_id]);
    $shop_name = $stmt_shop_name->fetchColumn() ?: 'Your Shop';
    // Fetch customer name for notification
    $stmt_cust_name = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
    $stmt_shop_name->execute([$shop_id]);
    $shop_name = $stmt_shop_name->fetchColumn() ?: 'Your Shop';
    $stmt_entries->execute([$customer_id, $shop_id]);
    $open_entries = $stmt_entries->fetchAll();
    $remaining_payment = $amount_paid;

    $stmt_hist = $pdo->prepare("INSERT INTO payment_history (entry_id, shop_id, customer_id, amount_paid, payment_mode, remaining_after, payment_date) VALUES (?, ?, ?, ?, ?, ?, NOW())");

    foreach ($open_entries as $entry) {
        if ($remaining_payment <= 0) break;
        $entry_id        = $entry['id'];
        $entry_remaining = (float)$entry['total_remaining'];
        $amount_to_apply = min($remaining_payment, $entry_remaining);

        if ($remaining_payment >= $entry_remaining) {
            $remaining_payment -= $entry_remaining;
            $pdo->prepare("UPDATE udhar_entries SET total_remaining = 0, total_paid = total_paid + ?, status = 'closed' WHERE id = ?")->execute([$amount_to_apply, $entry_id]);
            $stmt_hist->execute([$entry_id, $shop_id, $customer_id, $amount_to_apply, $payment_mode, 0]);
        } else {
            $new_remaining = $entry_remaining - $amount_to_apply;
            $pdo->prepare("UPDATE udhar_entries SET total_remaining = ?, total_paid = total_paid + ? WHERE id = ?")->execute([$new_remaining, $amount_to_apply, $entry_id]);
            $stmt_hist->execute([$entry_id, $shop_id, $customer_id, $amount_to_apply, $payment_mode, $new_remaining]);
            $remaining_payment = 0;
        }
    }
    // Send notification to customer after processing payment
    if ($amount_paid > 0) {
        sendKhataPush($pdo, $customer_id, 'customer', "Payment Received! ✅", "Aapka ₹" . number_format($amount_paid, 2) . " ka payment " . $shop_name . " ne record kar liya hai. Dhanyawad!", ['type' => 'payment_received', 'shop_id' => (string)$shop_id]);
    }
}

// Helper to normalize payment mode
function normalizePaymentMode(string $mode) {
    $mode = trim($mode);
    $aliases = ['online' => 'UPI', 'google pay' => 'GPay', 'googlepay' => 'GPay', 'phonepe' => 'PhonePe', 'phone pe' => 'PhonePe'];
    $key = strtolower($mode);
    return $aliases[$key] ?? $mode;
}

// Define getPaymentIcon before use
function getPaymentIcon(string $mode, array $db_modes): string {
    $mode = trim($mode);
    $aliases = ['online' => 'UPI', 'google pay' => 'GPay', 'googlepay' => 'GPay', 'phonepe' => 'PhonePe', 'phone pe' => 'PhonePe'];
    $normalized = strtolower($mode);
    if (isset($aliases[$normalized])) $mode = $aliases[$normalized];

    foreach ($db_modes as $pm) {
        if (strcasecmp($mode, $pm['name']) === 0 || stripos($mode, $pm['name']) !== false) return $pm['icon'];
    }
    return '<i class="fas fa-wallet text-slate-400"></i>';
}

// ===== FLUTTER API HANDLING =====
if(isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    header('Content-Type: application/json');
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);
    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id_api = $parts[0] ?? 0;

    if (!$shop_id_api) { echo json_encode(['success' => false, 'message' => 'Unauthorized.']); exit(); }

    // Common: Fetch modes for API
    $stmt_modes = $pdo->query("SELECT name, icon FROM payment_modes WHERE is_active = 1");
    $db_modes = $stmt_modes->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';
        $req_id = (int)($data['request_id'] ?? 0);

        if ($action === 'approve' && $req_id > 0) {
            $stmt_req = $pdo->prepare("SELECT * FROM payment_requests WHERE id = ? AND shop_id = ? AND status = 'pending'");
            $stmt_req->execute([$req_id, $shop_id_api]);
            $r = $stmt_req->fetch();
            if ($r) {
                $pdo->beginTransaction();
                processPayment($pdo, $shop_id_api, (int)$r['customer_id'], (float)$r['amount'], normalizePaymentMode($r['payment_mode']));
                $pdo->prepare("UPDATE payment_requests SET status = 'approved', screenshot = NULL WHERE id = ?")->execute([$req_id]);
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Payment approved!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Request not found.']);
                echo json_encode(['success' => true, 'message' => 'Approved!']);
            }
            exit();
        }

        if ($action === 'reject' && $req_id > 0) {
            $stmt_req = $pdo->prepare("SELECT screenshot FROM payment_requests WHERE id = ? AND shop_id = ? AND status = 'pending'");
            $stmt_req->execute([$req_id, $shop_id_api]);
            $r = $stmt_req->fetch();
            if ($r) {
                // Delete screenshot file if exists
                if ($r['screenshot'] && file_exists('../assets/img/payments/' . $r['screenshot'])) {
                    unlink('../assets/img/payments/' . $r['screenshot']);
                }
                $pdo->prepare("UPDATE payment_requests SET status = 'rejected', screenshot = NULL WHERE id = ? AND shop_id = ?")->execute([$req_id, $shop_id_api]);
                echo json_encode(['success' => true, 'message' => 'Payment rejected.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Request not found.']);
            }
            exit();
        }

        if ($action === 'record_manual') {
            $customer_id  = (int)($data['customer_id'] ?? 0);
            $amount_paid  = (float)($data['amount_paid'] ?? 0);
            $payment_mode = normalizePaymentMode($data['payment_mode'] ?? 'Cash');

            if ($customer_id <= 0 || $amount_paid <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid customer or amount.']);
                exit();
            }
            try {
                $pdo->beginTransaction();
                processPayment($pdo, $shop_id_api, $customer_id, $amount_paid, $payment_mode);
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Payment of ₹' . number_format($amount_paid, 2) . ' recorded!']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Failed: ' . $e->getMessage()]);
            }
            exit();
        }

        // If no valid action is found
        echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        exit();
        // Add other POST actions (reject, manual) similarly...
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt_cust = $pdo->prepare("SELECT c.id, c.name, c.unique_id, COALESCE(SUM(ue.total_remaining), 0) as total_due FROM customers c JOIN shop_customers sc ON c.id = sc.customer_id LEFT JOIN udhar_entries ue ON c.id = ue.customer_id AND ue.shop_id = ? AND ue.status = 'open' WHERE sc.shop_id = ? GROUP BY c.id HAVING total_due > 0 ORDER BY c.name ASC");
        $stmt_cust->execute([$shop_id_api, $shop_id_api]);
        $raw_customers = $stmt_cust->fetchAll();
        
        // Explicitly cast numeric values for Flutter
        $customers_list = [];
        foreach($raw_customers as $c) {
            $customers_list[] = [
                'id' => (int)$c['id'],
                'name' => (string)$c['name'],
                'unique_id' => (string)$c['unique_id'],
                'total_due' => (float)$c['total_due']
            ];
        }

        $pending = $pdo->prepare("SELECT pr.*, c.name FROM payment_requests pr JOIN customers c ON pr.customer_id = c.id WHERE pr.shop_id = ? AND pr.status = 'pending' AND pr.razorpay_order_id IS NULL ORDER BY pr.created_at DESC");
        $pending->execute([$shop_id_api]);
        $raw_pending = $pending->fetchAll();

        // Explicitly cast numeric values for Flutter
        $pending_requests = [];
        foreach($raw_pending as $r) {
            $pending_requests[] = [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'amount' => (float)$r['amount'],
                'payment_mode' => (string)$r['payment_mode'],
                'screenshot' => (string)$r['screenshot'],
                'created_at' => (string)$r['created_at']
            ];
        }

        echo json_encode([
            'success' => true, 
            'customers' => $customers_list, 
            'pending_requests' => $pending_requests,
            'payment_modes' => $db_modes
        ]);
        exit();
    }
}

// ===== WEB SESSION CHECK =====
if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$error = '';
$success = '';

// Fetch modes from DB for consistency
$stmt_modes = $pdo->query("SELECT name, icon FROM payment_modes WHERE is_active = 1");
$db_modes = $stmt_modes->fetchAll();

// Auto-clean: Delete rejected requests older than 7 days
$pdo->prepare("DELETE FROM payment_requests WHERE status = 'rejected' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->execute();

// Auto-clean: Delete approved requests older than 30 days
$pdo->prepare("DELETE FROM payment_requests WHERE status = 'approved' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->execute();

// Capture customer_id from URL if coming from ledger
$url_customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

// Fetch customers who have outstanding dues
$stmt_cust = $pdo->prepare("
    SELECT c.id, c.name, c.unique_id, COALESCE(SUM(ue.total_remaining), 0) as total_due
    FROM customers c
    JOIN shop_customers sc ON c.id = sc.customer_id
    LEFT JOIN udhar_entries ue ON c.id = ue.customer_id AND ue.shop_id = ? AND ue.status = 'open'
    WHERE sc.shop_id = ?
    GROUP BY c.id
    HAVING total_due > 0
    ORDER BY c.name ASC
");
$stmt_cust->execute([$shop_id, $shop_id]);
$customers = $stmt_cust->fetchAll();

// Handle Approval of Manual Payment Requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['approve_request_id'])) {
    $req_id = (int)$_POST['approve_request_id'];
    
    $stmt_req = $pdo->prepare("SELECT * FROM payment_requests WHERE id = ? AND shop_id = ? AND status = 'pending'");
    $stmt_req->execute([$req_id, $shop_id]);
    $request_data = $stmt_req->fetch();

    if ($request_data) {
        $customer_id         = $request_data['customer_id'];
        $amount_paid         = (float)$request_data['amount'];
        // ── FIXED: normalize "Online" → "UPI" before storing in payment_history
        $payment_mode        = normalizePaymentMode($request_data['payment_mode']);
        $screenshot_filename = $request_data['screenshot'];

        try {
            $pdo->beginTransaction(); // Start transaction
            processPayment($pdo, $shop_id, $customer_id, $amount_paid, $payment_mode); // Use helper
            $pdo->prepare("UPDATE payment_requests SET status = 'approved', screenshot = NULL WHERE id = ?")->execute([$req_id]);
            if ($screenshot_filename && file_exists('../assets/img/payments/' . $screenshot_filename)) {
                unlink('../assets/img/payments/' . $screenshot_filename);
            }

            $pdo->commit();
            header("Location: payment.php?success=Payment request approved and ledger updated!");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: payment.php?error=Failed to approve: " . urlencode($e->getMessage()));
            exit();
        }
    }
}

// Handle Rejection
if (isset($_POST['reject_request_id'])) {
    $req_id = (int)$_POST['reject_request_id'];

    $stmt_req = $pdo->prepare("SELECT screenshot FROM payment_requests WHERE id = ? AND shop_id = ? AND status = 'pending'");
    $stmt_req->execute([$req_id, $shop_id]);
    $request_data = $stmt_req->fetch();

    if ($request_data && $request_data['screenshot']) {
        if (file_exists('../assets/img/payments/' . $request_data['screenshot'])) {
            unlink('../assets/img/payments/' . $request_data['screenshot']);
            header("Location: payment.php?success=Payment rejected and screenshot deleted.");
            exit();
        }
    }
    $pdo->prepare("UPDATE payment_requests SET status = 'rejected', screenshot = NULL WHERE id = ? AND shop_id = ?")->execute([$req_id, $shop_id]);
}

// Handle manual payment form
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['approve_request_id']) && !isset($_POST['reject_request_id'])) {
    $customer_id  = $_POST['customer_id'] ?? '';
    $amount_paid  = (float)($_POST['amount_paid'] ?? 0);
    // ── FIXED: normalize mode from form too
    $payment_mode = normalizePaymentMode($_POST['payment_mode'] ?? 'Cash');

    if (empty($customer_id)) {
        $error = "Please select a customer.";
    } elseif ($amount_paid <= 0) {
        $error = "Please enter a valid payment amount.";
    } else {
        try {
            $pdo->beginTransaction(); // Start transaction
            processPayment($pdo, $shop_id, $customer_id, $amount_paid, $payment_mode); // Use helper
            $pdo->commit();
            header("Location: payment.php?success=Payment of ₹" . number_format($amount_paid, 2) . " recorded successfully!");
            exit();
            
            $stmt_cust->execute([$shop_id, $shop_id]);
            $customers = $stmt_cust->fetchAll();
            exit(); // Ensure script stops after redirect
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to record payment: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Payment — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .chevron-open { transform: rotate(180deg); }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

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
        <?= htmlspecialchars($_SESSION['shop_name']) ?>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/shop_sidebar.php'; ?>

    <div class="flex-1 p-4 md:p-8 max-w-5xl mx-auto w-full">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl font-black tracking-tight text-slate-900">Record Payment</h1>
            <p class="text-slate-500 text-sm">Record money received from a customer to clear their dues.</p>
        </div>

        <!-- Pending Approvals Section -->
        <?php
        // Filter: razorpay_order_id IS NULL ensures online payments don't clutter the shop's notifications
        $pending_reqs = $pdo->prepare("SELECT pr.*, c.name FROM payment_requests pr JOIN customers c ON pr.customer_id = c.id WHERE pr.shop_id = ? AND pr.status = 'pending' AND pr.razorpay_order_id IS NULL ORDER BY pr.created_at DESC");
        $pending_reqs->execute([$shop_id]);
        $reqs = $pending_reqs->fetchAll();
        if ($reqs):
        ?>
        <div class="bg-blue-50 border-2 border-blue-100 rounded-[2.5rem] p-6 mb-8">
            <h3 class="text-xs font-black text-blue-700 uppercase tracking-widest mb-4 flex items-center gap-2">
                <i class="fas fa-clock"></i> Customer Payment Notifications
            </h3>
            <div class="space-y-3">
                <?php foreach($reqs as $r): ?>
                <div class="bg-white p-4 rounded-2xl shadow-sm flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($r['name']) ?> claims to have paid <span class="text-emerald-600">₹<?= number_format($r['amount'], 2) ?></span></div>
                        <div class="text-[10px] text-slate-400 font-bold uppercase tracking-tight flex items-center gap-2 mt-1 flex-wrap">
                            <span>Mode:</span>
                            <?= getPaymentIcon($r['payment_mode'], $db_modes) ?>
                            <span>• Sent: <?= date('d M, h:i A', strtotime($r['created_at'])) ?></span>
                            <?php if($r['screenshot']): ?>
                                <span>•</span>
                                <a href="../assets/img/payments/<?= $r['screenshot'] ?>" target="_blank" class="text-blue-600 hover:underline font-black">View Screenshot</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex gap-2 flex-shrink-0">
                        <form method="POST">
                            <input type="hidden" name="approve_request_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="bg-emerald-600 text-white text-[10px] font-black px-4 py-2 rounded-xl uppercase tracking-widest hover:bg-emerald-700 transition-all">Accept</button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="reject_request_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="bg-slate-200 text-slate-600 text-[10px] font-black px-4 py-2 rounded-xl uppercase tracking-widest hover:bg-slate-300 transition-all">Reject</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Main Form Card -->
        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 md:p-10 shadow-sm shadow-slate-200/50 mb-12">
            <?php if($error): ?>
                <div class="bg-red-50 border border-red-100 text-red-600 rounded-2xl p-4 text-sm font-medium mb-6 flex items-center gap-3">
                    <i class="fas fa-exclamation-circle"></i> <?= $error ?>
                </div>
            <?php endif; ?>
            <?php if($success): ?>
                <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 rounded-2xl p-4 text-sm font-medium mb-6 flex items-center gap-3">
                    <i class="fas fa-check-circle"></i> <?= $success ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">

                <!-- Customer Select -->
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Select Customer</label>
                    <select name="customer_id" id="customerSelect"
                        class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-emerald-500 outline-none transition-all cursor-pointer appearance-none"
                        required onchange="updateDueInfo()">
                        <option value="" data-due="0">-- Choose Customer --</option>
                        <?php foreach($customers as $c): ?>
                            <option value="<?= $c['id'] ?>" data-due="<?= $c['total_due'] ?>" <?= ($url_customer_id == $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?> (₹<?= number_format($c['total_due'], 0) ?> due)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if(empty($customers)): ?>
                        <p class="text-[10px] font-bold text-slate-400 mt-2 flex items-center gap-1.5">
                            <i class="fas fa-info-circle"></i> No customers with outstanding dues found.
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Due Info Box -->
                <div id="dueInfoBox" class="bg-amber-50 border border-amber-100 rounded-2xl p-4 hidden transition-all">
                    <div class="text-[9px] font-black text-amber-600 uppercase tracking-widest mb-1">Current Outstanding</div>
                    <div class="text-2xl font-black text-amber-900 tracking-tight" id="displayDue">₹0</div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                    <!-- Payment Mode Custom Dropdown -->
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Payment Mode</label>

                        <!-- Hidden real select for form submission -->
                        <select name="payment_mode" id="paymentModeSelect" class="sr-only" required>
                            <?php foreach($db_modes as $dm): ?>
                                <option value="<?= htmlspecialchars($dm['name']) ?>"><?= htmlspecialchars($dm['name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <!-- Custom visual dropdown -->
                        <div class="relative" id="paymentModeWrapper">
                            <button type="button" id="modeDropdownBtn"
                                class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3.5 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all cursor-pointer flex items-center gap-3 hover:border-slate-300"
                                onclick="toggleModeDropdown()">
                                <span id="modeSelectedIcon" class="flex items-center shrink-0">
                                    <?php if(!empty($db_modes)) echo $db_modes[0]['icon']; ?>
                                </span>
                                <span id="modeSelectedLabel" class="flex-1 text-left text-slate-800">
                                    <?php if(!empty($db_modes)) echo htmlspecialchars($db_modes[0]['name']); ?>
                                </span>
                                <svg id="modeChevron"
                                    class="h-4 w-4 text-slate-400 transition-transform duration-200 shrink-0"
                                    viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/>
                                </svg>
                            </button>

                            <div id="modeDropdownPanel"
                                class="hidden absolute z-50 mt-2 w-full bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden">
                                <?php foreach ($db_modes as $mode): ?>
                                <button type="button"
                                    class="mode-option w-full flex items-center gap-3 px-5 py-3.5 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors border-b border-slate-50 last:border-0"
                                    data-value="<?= htmlspecialchars($mode['name']) ?>"
                                    data-label="<?= htmlspecialchars($mode['name']) ?>"
                                    onclick="selectMode(this)">
                                    <span class="flex items-center w-6 shrink-0"><?= $mode['icon'] ?></span>
                                    <span><?= htmlspecialchars($mode['name']) ?></span>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Amount -->
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Amount Received (₹)</label>
                        <div class="relative">
                            <span class="absolute left-5 top-1/2 -translate-y-1/2 font-black text-emerald-600">₹</span>
                            <input type="number" step="0.01" name="amount_paid" id="amountPaidInput"
                                class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl pl-10 pr-5 py-3.5 text-sm font-black text-emerald-700 focus:bg-white focus:border-emerald-500 outline-none transition-all"
                                placeholder="0.00" required>
                        </div>
                    </div>
                </div>

                <button type="submit"
                    class="w-full bg-slate-900 text-white font-black py-5 rounded-2xl hover:bg-emerald-600 transition-all shadow-xl shadow-slate-200 flex items-center justify-center gap-3 uppercase tracking-widest text-xs"
                    <?= empty($customers) ? 'disabled' : '' ?>>
                    <i class="fas fa-check-double text-sm"></i> Commit Ledger Entry
                </button>
            </form>
        </div>

        <!-- Recent Payments History -->
        <div class="bg-white border border-slate-200 rounded-[2.5rem] overflow-hidden shadow-sm shadow-slate-200/50">
            <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50">
                <h3 class="text-sm font-black text-slate-900 flex items-center gap-2 uppercase tracking-tight">
                    <i class="fas fa-history text-blue-600"></i> Last 5 Collections
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-50/50 border-b border-slate-100">
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Customer Entity</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount Received</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php
                        $recent = $pdo->prepare("
                            SELECT ph.*, c.name 
                            FROM payment_history ph 
                            JOIN customers c ON ph.customer_id = c.id 
                            WHERE ph.shop_id = ? 
                            ORDER BY ph.payment_date DESC LIMIT 5
                        ");
                        $recent->execute([$shop_id]);
                        $pay_list = $recent->fetchAll();
                        
                        if($pay_list):
                            foreach($pay_list as $p):
                        ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="px-6 py-5">
                                    <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($p['name']) ?></div>
                                    <div class="flex items-center gap-1.5 mt-1">
                                        <?= getPaymentIcon($p['payment_mode'], $db_modes) ?>
                                        <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest"><?= htmlspecialchars($p['payment_mode']) ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-5">
                                    <span class="bg-emerald-50 text-emerald-700 px-3 py-1 rounded-lg font-black text-xs">₹<?= number_format($p['amount_paid'], 2) ?></span>
                                </td>
                                <td class="px-6 py-5 text-right">
                                    <div class="text-xs font-bold text-slate-500"><?= date('d M Y', strtotime($p['payment_date'])) ?></div>
                                    <div class="text-[10px] font-medium text-slate-400 uppercase"><?= date('h:i A', strtotime($p['payment_date'])) ?></div>
                                </td>
                            </tr>
                        <?php 
                            endforeach;
                        else:
                        ?>
                            <tr>
                                <td colspan="3" class="px-6 py-12 text-center text-slate-400 text-xs font-medium italic">
                                    No ledger activity found in recent sessions.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<footer class="bg-white border-t border-slate-200 py-6 text-center">
    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-[0.2em]">
        © <?= date('Y') ?> KhataLink — Premium Digital Ledger
    </div>
</footer>

<script>
// ── Customer Due Info ─────────────────────────────────────
function updateDueInfo() {
    const select = document.getElementById('customerSelect');
    const dueBox = document.getElementById('dueInfoBox');
    const displayDue = document.getElementById('displayDue');
    const amountInput = document.getElementById('amountPaidInput');
    const selectedOption = select.options[select.selectedIndex];
    const dueAmount = parseFloat(selectedOption.getAttribute('data-due') || 0);

    if (dueAmount > 0) {
        dueBox.classList.remove('hidden');
        displayDue.innerText = '₹' + dueAmount.toLocaleString();
        amountInput.value = dueAmount;
    } else {
        dueBox.classList.add('hidden');
        amountInput.value = '';
    }
}

document.addEventListener('DOMContentLoaded', updateDueInfo);

// ── Payment Mode Custom Dropdown ──────────────────────────
function toggleModeDropdown() {
    const panel   = document.getElementById('modeDropdownPanel');
    const chevron = document.getElementById('modeChevron');
    const opening = panel.classList.contains('hidden');
    panel.classList.toggle('hidden');
    chevron.classList.toggle('chevron-open', opening);
}

function selectMode(el) {
    const value    = el.getAttribute('data-value');
    const label    = el.getAttribute('data-label');
    const iconSpan = el.querySelector('span.flex');

    document.getElementById('paymentModeSelect').value       = value;
    document.getElementById('modeSelectedIcon').innerHTML    = iconSpan ? iconSpan.innerHTML : '';
    document.getElementById('modeSelectedLabel').textContent = label;
    document.getElementById('modeDropdownPanel').classList.add('hidden');
    document.getElementById('modeChevron').classList.remove('chevron-open');
}

// Close on outside click
document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('paymentModeWrapper');
    if (wrapper && !wrapper.contains(e.target)) {
        document.getElementById('modeDropdownPanel').classList.add('hidden');
        document.getElementById('modeChevron').classList.remove('chevron-open');
    }
});

// ── Sidebar ───────────────────────────────────────────────
function openSidebar()  {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('overlay').classList.remove('hidden');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.add('hidden');
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
        const title = payload.notification?.title || 'Payment Received';
        const body = payload.notification?.body || 'Ledger updated successfully';
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