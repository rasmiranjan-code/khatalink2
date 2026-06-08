<?php
session_start();
require_once '../includes/db.php';

// ── API MODE FOR FLUTTER ──────────────────────────────────────────────────
if (isset($_GET['api']) && $_GET['api'] == '1') {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    // Token auth for API
    $token = $_SERVER['HTTP_AUTHORIZATION']?? '';
    $token = str_replace('Bearer ', '', $token);

    if (empty($token)) {
        echo json_encode(['success' => false, 'message' => 'Auth token missing']);
        exit();
    }

    $decoded = base64_decode($token);
    $parts = explode(':', $decoded);
    $shop_id = (int)($parts[0]?? 0);
    $role = $parts[2]?? '';

    if (!$shop_id || $role!== 'shop') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    $from_date = $_GET['from_date']?? date('Y-m-01', strtotime('-1 year'));
    $to_date = $_GET['to_date']?? date('Y-m-d');
    $customer_filter = (int)($_GET['customer_id']?? 0);

    try {
        $query = "
            SELECT
                mk.id,
                mk.shop_id,
                mk.customer_id,
                mk.start_date,
                mk.total_amount,
                mk.status,
                mk.paid_amount,
                mk.razorpay_payment_id,
                mk.created_at,
                c.name as customer_name,
                c.unique_id,
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

        $query.= " ORDER BY mk.start_date DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_bill = 0;
        $formatted_cycles = array_map(function($row) use (&$total_bill) {
            $days = (int)$row['days_passed'];
            $is_paid = ($row['status'] == 'closed');
            $total_bill += (float)$row['total_amount'];

            return [
                'id' => (int)$row['id'],
                'shop_id' => (int)$row['shop_id'],
                'customer_id' => (int)$row['customer_id'],
                'customer_name' => $row['customer_name'],
                'unique_id' => $row['unique_id'],
                'start_date' => $row['start_date'], // ← YE IMPORTANT HAI
                'total_amount' => (float)$row['total_amount'],
                'paid_amount' => (float)($row['paid_amount']?? 0),
                'status' => $row['status'],
                'razorpay_payment_id' => $row['razorpay_payment_id'],
                'days_passed' => $days,
                'is_overdue' => $days >= 25 &&!$is_paid,
                'is_paid' => $is_paid,
                'payment_mode' => ($row['razorpay_payment_id'] === 'Manual')? 'Cash' : (empty($row['razorpay_payment_id'])? 'PENDING' : 'ONLINE'),
                'created_at' => $row['created_at'] // ← YE BHI IMPORTANT
            ];
        }, $cycles);

        echo json_encode([
            'success' => true,
            'cycles' => $formatted_cycles,
            'total_bill_amount' => $total_bill
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: '. $e->getMessage()]);
    }
    exit();
}

// ── NORMAL HTML PAGE ──────────────────────────────────────────────────
if(!isset($_SESSION['shop_id'])) {
    header("Location:../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];

// Filters
$from_date = $_GET['from_date']?? date('Y-m-01', strtotime('-1 year'));
$to_date = $_GET['to_date']?? date('Y-m-d');
$customer_filter = (int)($_GET['customer_id']?? 0);

// Fetch all customers for filter dropdown
$stmt_customers = $pdo->prepare("SELECT c.id, c.name, c.unique_id FROM customers c JOIN shop_customers sc ON c.id = sc.customer_id WHERE sc.shop_id =? ORDER BY c.name ASC");
$stmt_customers->execute([$shop_id]);
$all_customers = $stmt_customers->fetchAll();

// Build query for monthly khata history
$query = "
    SELECT mk.*,
           c.name as customer_name,
           c.unique_id,
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

$query.= " ORDER BY mk.start_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$cycles = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monthly Khata History — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 font-[Inter]">
<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <a href="monthly_khata.php" class="text-slate-400 hover:text-blue-600"><i class="fas fa-arrow-left"></i></a>
        <span class="text-xs font-black text-blue-700 uppercase tracking-widest">Monthly Khata History</span>
    </div>
</nav>
<div class="flex">
    <?php include '../includes/shop_sidebar.php';?>
    <main class="flex-1 p-4 md:p-8">
        <div class="max-w-5xl mx-auto">
            <div class="mb-10">
                <h1 class="text-3xl font-black text-slate-900">Monthly Khata History</h1>
                <p class="text-slate-500 text-sm">View all past and current monthly cycles.</p>
            </div>

            <!-- Filter Form -->
            <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm mb-8">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">From Date</label>
                        <input type="date" name="from_date" value="<?= htmlspecialchars($from_date)?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">To Date</label>
                        <input type="date" name="to_date" value="<?= htmlspecialchars($to_date)?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">Customer</label>
                        <select name="customer_id" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold">
                            <option value="0">All Customers</option>
                            <?php foreach($all_customers as $cust):?>
                                <option value="<?= $cust['id']?>" <?= $customer_filter == $cust['id']? 'selected' : ''?>>
                                    <?= htmlspecialchars($cust['name'])?> (<?= $cust['unique_id']?>)
                                </option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 text-white font-black px-6 py-2.5 rounded-xl text-[10px] uppercase tracking-widest">Filter</button>
                        <a href="generate_monthly_history_pdf.php?from_date=<?= urlencode($from_date)?>&to_date=<?= urlencode($to_date)?>&customer_id=<?= $customer_filter?>" target="_blank" class="bg-emerald-600 text-white font-black px-6 py-2.5 rounded-xl text-[10px] uppercase tracking-widest">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                    </div>
                </form>
            </div>

            <!-- History Table -->
            <div class="bg-white border border-slate-200 rounded-[2rem] overflow-x-auto shadow-sm">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Customer</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Cycle Start</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Days</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Bill Amount</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Payment Mode</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php if(count($cycles) > 0):?>
                            <?php foreach($cycles as $mk):
                                $is_paid = ($mk['status'] == 'closed');
                                $payment_mode = ($mk['razorpay_payment_id'] === 'Manual')? 'Cash' : (empty($mk['razorpay_payment_id'])? '—' : 'Online');
                                $days = (int)$mk['days_passed'];
                                $is_overdue = $days >= 25 &&!$is_paid;
                         ?>
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-6 py-4 text-sm font-black text-slate-900"><?= htmlspecialchars($mk['customer_name'])?></td>
                                <td class="px-6 py-4 text-xs font-bold text-slate-500"><?= date('d M Y', strtotime($mk['start_date']))?></td>
                                <td class="px-6 py-4 text-center">
                                    <span class="text-xs font-bold text-slate-600"><?= $days?></span>
                                    <?php if($is_overdue):?>
                                        <span class="ml-1 text-[8px] font-black px-1.5 py-0.5 rounded bg-red-100 text-red-600">DUE</span>
                                    <?php endif;?>
                                </td>
                                <td class="px-6 py-4 text-sm font-black text-slate-900 text-right">₹<?= number_format($mk['total_amount'], 2)?></td>
                                <?php
                                    $display_bill_amount = ($mk['razorpay_payment_id'] !== null && $mk['razorpay_payment_id'] !== 'Manual') ? ($mk['total_amount'] * (1 + (MONTHLY_PLATFORM_COMMISSION_PERCENT / 100))) : $mk['total_amount'];
                                ?>
                                <td class="px-6 py-4 text-sm font-black text-slate-900 text-right">₹<?= number_format($display_bill_amount, 2)?></td>
                                <td class="px-6 py-4 text-center">
                                    <span class="text-[9px] font-black px-2 py-1 rounded-full uppercase <?= $is_paid? 'bg-emerald-50 text-emerald-600' : 'bg-red-50 text-red-600'?>">
                                        <?= $is_paid? 'Settled' : 'Open'?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center text-[9px] font-black uppercase text-slate-600">
                                    <?= $payment_mode?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <a href="manage_monthly_khata.php?id=<?= $mk['id']?>" class="text-blue-600 hover:underline text-xs font-bold">View</a>
                                </td>
                            </tr>
                            <?php endforeach;?>
                        <?php else:?>
                            <tr><td colspan="7" class="py-20 text-center text-slate-400 italic text-sm">No monthly khata cycles found for this period.</td></tr>
                        <?php endif;?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<script>
function openSidebar() {
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('overlay').classList.remove('hidden');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.add('hidden');
}
</script>
</body>
</html>