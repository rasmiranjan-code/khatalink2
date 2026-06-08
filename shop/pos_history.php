<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];

// Filters
$target_date = $_GET['target_date'] ?? date('Y-m-d');
$customer_filter = (int)($_GET['customer_id'] ?? 0);

// Fetch all customers for filter dropdown
$stmt_customers = $pdo->prepare("SELECT c.id, c.name, c.unique_id FROM customers c JOIN shop_customers sc ON c.id = sc.customer_id WHERE sc.shop_id = ? ORDER BY c.name ASC");
$stmt_customers->execute([$shop_id]);
$all_customers = $stmt_customers->fetchAll();

// Build query for POS bills history
$query = "
    SELECT pb.*, c.name as customer_name, c.unique_id
    FROM pos_bills pb
    LEFT JOIN customers c ON pb.customer_id = c.id
    WHERE pb.shop_id = ?
    AND pb.is_deleted_shop = 0
    AND pb.is_deleted_shop = 0 
    AND DATE(pb.created_at) = ?
    AND pb.payment_status != 'transferred_to_udhar'
";
$params = [$shop_id, $target_date];

if ($customer_filter > 0) {
    // --- DEBUG LOGGING START ---
    error_log("SHOP_POS_HISTORY_DEBUG: Applying customer filter: " . $customer_filter);
    // --- DEBUG LOGGING END ---

    $query .= " AND pb.customer_id = ?";
    $params[] = $customer_filter;
}

$query .= " ORDER BY pb.created_at DESC";

$stmt = $pdo->prepare($query);
// --- DEBUG LOGGING START ---
error_log("SHOP_POS_HISTORY_DEBUG: Executing query: " . $query);
error_log("SHOP_POS_HISTORY_DEBUG: Parameters: " . json_encode($params));
// --- DEBUG LOGGING END ---
$stmt->execute($params);
$bills = $stmt->fetchAll();

// Fetch Low Stock Items for Modal
$stmt_stock = $pdo->prepare("SELECT name, current_stock, primary_unit, low_stock_alert FROM inventory_products WHERE shop_id = ? AND current_stock <= low_stock_alert ORDER BY current_stock ASC");
$stmt_stock->execute([$shop_id]);
$low_stock_items = $stmt_stock->fetchAll() ?? []; // Ensure it's an array to prevent TypeError in count()


// Calculate Day's Total Collection
$total_collection = 0;
foreach($bills as $b) {
    $total_collection += (float)$b['final_net_amount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Bills History — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        <a href="voice_billing.php" class="text-slate-400 hover:text-blue-600"><i class="fas fa-arrow-left"></i></a>
        <span class="text-xs font-black text-blue-700 uppercase tracking-widest">POS Bills History</span>
    </div>
</nav>
<div class="flex">
    <?php include '../includes/shop_sidebar.php'; ?>
    <main class="flex-1 p-4 md:p-8">
        <div class="max-w-5xl mx-auto">
            <div class="mb-10">
                <h1 class="text-3xl font-black text-slate-900">POS Bills History</h1>
                <p class="text-slate-500 text-sm">View all past Point-of-Sale transactions.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <!-- Summary Card -->
                <div class="md:col-span-2 bg-blue-600 rounded-[2rem] p-6 text-white shadow-xl shadow-blue-200 flex items-center justify-between">
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-[0.2em] opacity-80 mb-1">Total Collection (<?= date('d M Y', strtotime($target_date)) ?>)</div>
                        <div class="text-3xl font-black">₹<?= number_format($total_collection, 2) ?></div>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-2xl flex items-center justify-center text-xl">
                        <i class="fas fa-cash-register"></i>
                    </div>
                </div>
                <!-- Inventory Alert Trigger -->
                <button onclick="document.getElementById('stockModal').classList.remove('hidden')" class="bg-white border border-slate-200 rounded-[2rem] p-6 flex items-center justify-between hover:border-red-500 transition-all group shadow-sm">
                    <div class="text-left">
                        <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Stock Alerts</div>
                        <div class="text-xl font-black text-red-600"><?= count($low_stock_items) ?> Items</div>
                    </div>
                    <div class="w-10 h-10 bg-red-50 text-red-600 rounded-xl flex items-center justify-center group-hover:bg-red-600 group-hover:text-white transition-all"><i class="fas fa-box-open"></i></div>
                </button>
            </div>

             <!-- Filter Form -->
             <div class="bg-white border border-slate-200 rounded-[2rem] p-6 shadow-sm mb-8">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">Select Date</label>
                        <input type="date" name="target_date" value="<?= htmlspecialchars($target_date) ?>" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold">
                    </div>
                    <div>
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-2">Customer</label>
                        <select name="customer_id" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold">
                            <option value="0">All Customers</option>
                            <?php foreach($all_customers as $cust): ?>
                                <option value="<?= $cust['id'] ?>" <?= $customer_filter == $cust['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cust['name']) ?> (<?= $cust['unique_id'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 text-white font-black px-6 py-2.5 rounded-xl text-[10px] uppercase tracking-widest">Filter</button>
                        <a href="generate_pos_history_pdf.php?target_date=<?= urlencode($target_date) ?>&customer_id=<?= $customer_filter ?>" target="_blank" class="bg-emerald-600 text-white font-black px-6 py-2.5 rounded-xl text-[10px] uppercase tracking-widest">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                    </div>
                </form>
            </div>

            <!-- Bills Table -->
            <div class="bg-white border border-slate-200 rounded-[2rem] overflow-x-auto shadow-sm">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Bill No.</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest">Customer</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-right">Net Amount</th>
                            <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase tracking-widest text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php if(count($bills) > 0): ?>
                            <?php foreach($bills as $bill):
                                $is_udhar = ($bill['payment_status'] === 'transferred_to_udhar');
                                $status_class = $is_udhar ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600';
                                $status_label = $is_udhar ? 'Udhar' : 'Paid';
                            ?>
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-6 py-4 text-sm font-black text-slate-900"><?= htmlspecialchars($bill['bill_number']) ?></td>
                                <td class="px-6 py-4 text-xs font-bold text-slate-500"><?= date('d M Y', strtotime($bill['created_at'])) ?></td>
                                <td class="px-6 py-4 text-sm font-black text-slate-900"><?= htmlspecialchars($bill['customer_name'] ?? 'Guest Customer') ?></td>
                                <td class="px-6 py-4 text-sm font-black text-slate-900 text-right">₹<?= number_format($bill['final_net_amount'], 2) ?></td>
                                <td class="px-6 py-4 text-center flex items-center justify-center gap-2">
                                    <a href="export_pos_bill.php?bill_id=<?= $bill['id'] ?>" target="_blank" class="text-blue-600 hover:underline text-xs font-bold">View PDF</a>
                                    <span class="text-slate-200">|</span>
                                    <button onclick="deleteBill(<?= $bill['id'] ?>)" class="text-red-400 hover:text-red-600 transition-colors">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="py-20 text-center text-slate-400 italic text-sm">No POS bills found for this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Stock Alerts Modal -->
<div id="stockModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" onclick="if(event.target === this) this.classList.add('hidden')">
    <div class="bg-white w-full max-w-lg rounded-[2.5rem] flex flex-col max-h-[85vh] shadow-2xl overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between">
            <h3 class="text-lg font-black text-slate-900 uppercase tracking-tight">Stock Warning Center</h3>
            <button class="w-8 h-8 flex items-center justify-center bg-slate-100 rounded-full text-slate-500" onclick="document.getElementById('stockModal').classList.add('hidden')"><i class="fas fa-times"></i></button>
        </div>
        <div class="flex-1 overflow-y-auto p-4">
            <?php if($low_stock_items): ?>
                <div class="space-y-3">
                    <?php foreach($low_stock_items as $item): 
                        $is_out = ($item['current_stock'] <= 0);
                    ?>
                    <div class="flex items-center justify-between p-4 rounded-2xl border <?= $is_out ? 'bg-red-50 border-red-100' : 'bg-amber-50 border-amber-100' ?>">
                        <div>
                            <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($item['name']) ?></div>
                            <div class="text-[10px] font-bold text-slate-500 uppercase mt-0.5">Threshold: <?= (float)$item['low_stock_alert'] ?> <?= $item['primary_unit'] ?></div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-black <?= $is_out ? 'text-red-600' : 'text-amber-600' ?>"><?= (float)$item['current_stock'] ?> Left</div>
                            <div class="text-[9px] font-black uppercase <?= $is_out ? 'text-red-400' : 'text-amber-400' ?>"><?= $is_out ? 'Out of Stock' : 'Low Stock' ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="py-20 text-center text-slate-400 font-medium italic text-sm">All inventory items are well stocked!</div>
            <?php endif; ?>
        </div>
        <div class="p-6 bg-slate-50 border-t border-slate-100">
            <a href="inventory.php" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest flex items-center justify-center gap-2">Manage Inventory <i class="fas fa-arrow-right"></i></a>
        </div>
    </div>
</div>

<script>
function deleteBill(id) {
    Swal.fire({
        title: 'Delete Bill?',
        text: "Yeh bill interface se hat jayega lekin admin se wapas laya ja sakta hai.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Yes, Delete',
        customClass: { popup: 'rounded-[2rem]' }
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('ajax_delete_pos_bill.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'bill_id=' + id
            })
            .then(res => res.json())
            .then(data => {
                if(data.success) location.reload();
                else alert(data.message);
            });
        }
    });
}
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