<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/notification_service.php';
require_once '../includes/cashfree_config.php'; // For fee constants
if(!isset($_SESSION['shop_id'])) { header("Location: ../auth/login.php?type=shop"); exit(); }
$shop_id = $_SESSION['shop_id'];

// Add New Monthly Customer Start
if(isset($_POST['start_khata'])) {
    $customer_id = (int)$_POST['customer_id'];
    $start_date = date('Y-m-d');
    $stmt = $pdo->prepare("INSERT INTO monthly_khata (shop_id, customer_id, start_date) VALUES (?, ?, ?)");
    $stmt->execute([$shop_id, $customer_id, $start_date]);

    // Send notification to customer
    $stmt_cust_name = $pdo->prepare("SELECT name FROM customers WHERE id = ?");
    $stmt_cust_name->execute([$customer_id]); $cust_name = $stmt_cust_name->fetchColumn();
    sendKhataPush($pdo, (int)$customer_id, 'customer', "Naya Monthly Khata Shuru! 🗓️", "Namaste $cust_name! " . $_SESSION['shop_name'] . " ne aapke liye naya monthly khata shuru kiya hai. Ab aap apne kharche track kar sakte hain.", ['type' => 'monthly_khata_started', 'shop_id' => (string)$shop_id]);
    header("Location: monthly_khata.php?success=New Monthly Khata started!");
    exit();
}

// Handle Manual Settlement (Cash)
if(isset($_POST['settle_manual'])) {
    $khata_id = (int)$_POST['khata_id'];
    $stmt = $pdo->prepare("SELECT total_amount, customer_id FROM monthly_khata WHERE id = ?");
    $stmt->execute([$khata_id]);
    $mk_data = $stmt->fetch();
    $amt = (float)$mk_data['total_amount'];
    
    $stmt = $pdo->prepare("UPDATE monthly_khata SET status = 'closed', paid_amount = ?, razorpay_payment_id = 'Manual' WHERE id = ?");
    $stmt->execute([$amt, $khata_id]);

    // Send Settlement Notification
    sendKhataPush($pdo, (int)$mk_data['customer_id'], 'customer', "Bill Settled! ✅", "Aapka ₹" . number_format($amt, 2) . " ka monthly khata settle ho gaya hai. Dhanyawad!");

    header("Location: monthly_khata.php?success=Bill Settled via Cash (No Platform Fee applied)!");
    exit();
}

$stmt = $pdo->prepare("
    SELECT mk.*, c.name, c.unique_id,
    DATEDIFF(CURDATE(), mk.start_date) as days_passed
    FROM monthly_khata mk
    JOIN customers c ON mk.customer_id = c.id
    WHERE mk.shop_id = ? AND mk.status = 'open'
    ORDER BY mk.start_date DESC
");
$stmt->execute([$shop_id]);
$active_khatas = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Khata — KhataLink</title>
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
        <a href="dashboard.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8"></a>
    </div>
    <span class="text-xs font-black text-blue-700 uppercase tracking-widest">Monthly Khata System</span>
</nav>
<div class="flex">
    <?php include '../includes/shop_sidebar.php'; ?>
    <main class="flex-1 p-4 md:p-8">
        <div class="max-w-5xl mx-auto">
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-8">
                <div>
                    <h1 class="text-3xl font-black text-slate-900">Monthly Customers</h1>
                    <p class="text-slate-500 text-sm">Customers who pay on a monthly cycle.</p>
                </div>
                <div class="flex gap-2"><button onclick="openAddModal()" class="bg-slate-900 text-white font-black px-6 py-3 rounded-2xl text-[10px] uppercase tracking-widest shadow-lg">Start New Month</button><a href="monthly_khata_history.php" class="bg-slate-100 text-slate-600 font-black px-6 py-3 rounded-2xl text-[10px] uppercase tracking-widest shadow-lg"><i class="fas fa-history me-1"></i> History</a></div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach($active_khatas as $mk): 
                    $is_overdue = ($mk['days_passed'] >= 30);
                ?>
                <div class="bg-white border-2 <?= $is_overdue ? 'border-red-200' : 'border-slate-100' ?> rounded-[2rem] p-6 shadow-sm hover:shadow-xl transition-all">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h3 class="text-lg font-black text-slate-900"><?= htmlspecialchars($mk['name']) ?></h3>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?= $mk['unique_id'] ?></p>
                        </div>
                        <?php if($is_overdue): ?>
                            <span class="bg-red-50 text-red-600 text-[9px] font-black px-3 py-1 rounded-full uppercase animate-pulse">1 Month Complete</span>
                        <?php endif; ?>
                    </div>

                    <div class="bg-slate-50 rounded-2xl p-4 mb-4 grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-[8px] font-black text-slate-400 uppercase mb-1">Started On</label>
                            <div class="text-sm font-black text-slate-900"><?= date('d M Y', strtotime($mk['start_date'])) ?></div>
                        </div>
                        <div class="text-right">
                            <label class="block text-[8px] font-black text-slate-400 uppercase mb-1">Current Bill (Cash)</label>
                            <div class="text-sm font-black text-blue-600">₹<?= number_format((float)$mk['total_amount'], 2) ?></div>
                        </div>
                        <div class="col-span-2 pt-2 border-t border-slate-200">
                            <div class="flex justify-between text-[9px] font-bold">
                                <span class="text-slate-400 uppercase">If Paid Online:</span>
                                <?php
                                $cust_pays = $mk['total_amount'] * (1 + (MONTHLY_PLATFORM_COMMISSION_PERCENT / 100));
                                $shop_gets = $mk['total_amount'] * (1 - (SHOP_SERVICE_FEE_PERCENT / 100));
                                ?>
                                <span class="text-slate-700">Cust Pays: ₹<?= number_format($cust_pays, 2) ?> | You Get: ₹<?= number_format($shop_gets, 2) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <div class="grid grid-cols-2 gap-2">
                            <a href="manage_monthly_khata.php?id=<?= $mk['id'] ?>" class="bg-blue-600 text-white font-black py-3 rounded-xl text-[9px] uppercase tracking-widest text-center"><i class="fas fa-edit"></i> Manage</a>
                            <button onclick="settleCash(<?= $mk['id'] ?>, <?= (float)$mk['total_amount'] ?>)" class="bg-emerald-600 text-white font-black py-3 rounded-xl text-[9px] uppercase tracking-widest"><i class="fas fa-money-bill-wave"></i> Settle Cash</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>

<!-- Premium Start Khata Modal -->
<div id="addModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm" onclick="if(event.target===this) this.classList.add('hidden')">
    <div class="bg-white w-full max-w-md rounded-[2.5rem] p-8 md:p-10 shadow-2xl animate-[slideUp_0.3s_ease]">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-xl font-black text-slate-900 uppercase tracking-tight">Start New Cycle</h2>
            <button onclick="document.getElementById('addModal').classList.add('hidden')" class="text-slate-400"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="space-y-6">
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Select Customer</label>
                <select name="customer_id" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold outline-none focus:bg-white focus:border-blue-500 transition-all appearance-none cursor-pointer" required>
                    <option value="">-- Choose From Linked --</option>
                    <?php
                    $avail_stmt = $pdo->prepare("SELECT c.id, c.name, c.unique_id FROM shop_customers sc JOIN customers c ON sc.customer_id = c.id WHERE sc.shop_id = ? AND c.id NOT IN (SELECT customer_id FROM monthly_khata WHERE shop_id = ? AND status = 'open')");
                    $avail_stmt->execute([$shop_id, $shop_id]);
                    foreach($avail_stmt->fetchAll() as $c) echo "<option value='{$c['id']}'>".htmlspecialchars($c['name'])." ({$c['unique_id']})</option>";
                    ?>
                </select>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="document.getElementById('addModal').classList.add('hidden')" class="flex-1 bg-slate-100 text-slate-500 font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest">Cancel</button>
                <button type="submit" name="start_khata" class="flex-1 bg-slate-900 text-white font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest">Start Cycle</button>
            </div>
        </form>
    </div>
</div>

<!-- Settle Cash Modal -->
<div id="settleModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm">
    <div class="bg-white w-full max-w-sm rounded-[2.5rem] p-8 shadow-2xl text-center">
        <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center text-2xl mx-auto mb-4">
            <i class="fas fa-cash-register"></i>
        </div>
        <h2 class="text-xl font-black text-slate-900 mb-2">Settle Monthly Bill</h2>
        <p class="text-slate-500 text-sm mb-6">Receive <span class="font-black text-slate-900" id="settleAmt">₹0.00</span> in cash? <br><small>No platform tax will be applied.</small></p>
        
        <form method="POST">
            <input type="hidden" name="khata_id" id="settleKhataId">
            <div class="flex gap-3">
                <button type="button" onclick="document.getElementById('settleModal').classList.add('hidden')" class="flex-1 bg-slate-100 text-slate-500 font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest">Cancel</button>
                <button type="submit" name="settle_manual" class="flex-1 bg-emerald-600 text-white font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest shadow-lg shadow-emerald-100">Confirm Cash</button>
            </div>
        </form>
    </div>
</div>

<script>
function settleCash(id, amt) {
    document.getElementById('settleKhataId').value = id;
    document.getElementById('settleAmt').innerText = '₹' + amt.toFixed(2);
    document.getElementById('settleModal').classList.remove('hidden');
    document.getElementById('settleModal').classList.add('flex');
}

function openAddModal() { document.getElementById('addModal').classList.remove('hidden'); document.getElementById('addModal').classList.add('flex'); }
function closeAddModal() { document.getElementById('addModal').classList.add('hidden'); document.getElementById('addModal').classList.remove('flex'); }

function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }
</script>

</body>
</html>