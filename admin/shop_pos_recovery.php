<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['admin_id'])) die("Admin Access Required");

$shop_id = (int)($_GET['shop_id'] ?? 0);
if (!$shop_id) die("Invalid Request: Shop ID missing.");

$search_uid = trim($_GET['uid'] ?? '');

// Restore Logic
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['restore_bill_id'])) {
    $bill_id = (int)$_POST['restore_bill_id'];
    $show_shop = isset($_POST['show_shop']) ? 0 : 1;
    $show_cust = isset($_POST['show_cust']) ? 0 : 1;
    
    $pdo->prepare("UPDATE pos_bills SET is_deleted_shop = ?, is_deleted_customer = ? WHERE id = ? AND shop_id = ?")
        ->execute([$show_shop, $show_cust, $bill_id, $shop_id]);
    
    header("Location: shop_pos_recovery.php?shop_id=$shop_id&uid=$search_uid&success=1");
    exit();
}

$query = "SELECT pb.*, c.name, c.unique_id as cust_uid 
          FROM pos_bills pb 
          LEFT JOIN customers c ON pb.customer_id = c.id 
          WHERE pb.shop_id = ? AND (pb.is_deleted_shop = 1 OR pb.is_deleted_customer = 1)";
$params = [$shop_id];

if($search_uid) {
    $query .= " AND c.unique_id LIKE ?";
    $params[] = "%$search_uid%";
}
$query .= " ORDER BY pb.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$deleted_bills = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>POS Recovery — Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 font-[Inter] p-8">
    <div class="max-w-4xl mx-auto">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-2xl font-black">Deleted POS Bills Recovery</h1>
            <span class="bg-blue-600 text-white px-4 py-2 rounded-xl text-xs font-bold uppercase tracking-widest">Shop ID: <?= $shop_id ?></span>
        </div>

        <!-- Search Form -->
        <form method="GET" class="mb-6 flex gap-3">
            <input type="hidden" name="shop_id" value="<?= $shop_id ?>">
            <input type="text" name="uid" value="<?= htmlspecialchars($search_uid) ?>" class="flex-1 bg-white border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:border-blue-500" placeholder="Search by Customer ID (e.g. CUST-...)">
            <button type="submit" class="bg-slate-900 text-white px-6 py-3 rounded-xl font-black text-[10px] uppercase tracking-widest">Search</button>
        </form>

        <div class="bg-white border border-slate-200 rounded-[2rem] overflow-hidden shadow-sm">
            <table class="w-full text-left">
                <thead class="bg-slate-50 border-b border-slate-100">
                    <tr>
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Bill No.</th>
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Customer</th>
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Amount</th>
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Recovery Options</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php if($deleted_bills): foreach($deleted_bills as $b): ?>
                    <tr>
                        <td class="px-6 py-4 font-bold text-xs"><?= $b['bill_number'] ?></td>
                        <td class="px-6 py-4">
                            <div class="text-sm font-bold"><?= htmlspecialchars($b['name'] ?? 'Guest') ?></div>
                            <div class="text-[9px] text-slate-400"><?= $b['cust_uid'] ?? 'N/A' ?></div>
                        </td>
                        <td class="px-6 py-4 text-right font-black">₹<?= number_format($b['final_net_amount'], 2) ?></td>
                        <td class="px-6 py-4">
                            <form method="POST" class="flex items-center justify-center gap-3">
                                <input type="hidden" name="restore_bill_id" value="<?= $b['id'] ?>">
                                <label class="flex items-center gap-1 text-[9px] font-bold uppercase"><input type="checkbox" name="show_shop" checked> Shop</label>
                                <label class="flex items-center gap-1 text-[9px] font-bold uppercase"><input type="checkbox" name="show_cust" checked> Customer</label>
                                <button type="submit" class="bg-emerald-600 text-white px-3 py-1.5 rounded-lg text-[9px] font-black uppercase tracking-widest">Restore</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="4" class="py-20 text-center text-slate-400 italic">No deleted records to recover.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Sidebar toggle functions for mobile
    function openSidebar() {
        document.getElementById('sidebar').classList.add('open');
        document.getElementById('overlay').classList.add('show');
    }

    function closeSidebar() {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('overlay').classList.remove('show');
    }
</script>
</body>
</html>