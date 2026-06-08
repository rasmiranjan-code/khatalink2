<?php
session_start();
require_once '../includes/db.php';

if(!isset($_SESSION['customer_id'])) {
    header("Location: ../auth/login.php?type=customer");
    exit();
}

// ── FEATURE LOCK: Marketplace & History moved to Mall ────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Coming Soon — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-6">
    <div class="max-w-md w-full bg-white rounded-[3rem] p-10 text-center shadow-2xl border border-slate-100">
        <div class="w-20 h-20 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center text-3xl mx-auto mb-6">
            <i class="fas fa-lock"></i>
        </div>
        <h1 class="text-2xl font-black text-slate-900 uppercase tracking-tight mb-3">Coming Soon</h1>
        <p class="text-slate-500 text-sm leading-relaxed mb-8">
            The Marketplace and Order History features have moved to the <span class="font-bold text-slate-900">Mall section</span>. This page is currently under maintenance.
        </p>
        <a href="dashboard.php" class="inline-block bg-slate-900 text-white px-8 py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-slate-800 transition-all shadow-lg">
            Back to Dashboard
        </a>
    </div>
</body>
</html>
<?php exit(); ?>
<?php
$customer_id = $_SESSION['customer_id'];

// Fetch All Orders
$stmt = $pdo->prepare("
    SELECT o.*, s.shop_name, s.shop_category
    FROM orders o
    JOIN shop_owners s ON o.shop_id = s.id
    WHERE o.customer_id = ? AND o.is_deleted_customer = 0
    ORDER BY o.created_at DESC
");
$stmt->execute([$customer_id]);
$orders = $stmt->fetchAll();

function getStatusBadge(string $status): string {
    $config = [
        'pending'   => 'bg-slate-100 text-slate-600',
        'accepted'  => 'bg-blue-50 text-blue-600',
        'assigned'  => 'bg-amber-50 text-amber-600',
        'picked_up' => 'bg-purple-50 text-purple-600',
        'delivered' => 'bg-emerald-50 text-emerald-600',
        'cancelled' => 'bg-red-50 text-red-600'
    ];
    $cls = $config[$status] ?? 'bg-slate-100 text-slate-600';
    return '<span class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-widest ' . $cls . '">' . $status . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Order History — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-6 shadow-sm">
    <div class="flex items-center gap-4">
        <a href="dashboard.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8"></a>
        <h2 class="text-sm font-black text-slate-900 uppercase tracking-widest">Order History</h2>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/customer_sidebar.php'; ?>

    <main class="flex-1 p-4 md:p-8 max-w-5xl mx-auto w-full">
        <div class="mb-10">
            <h1 class="text-3xl font-black text-slate-900">My Orders</h1>
            <p class="text-slate-500 text-sm">Full history of your marketplace transactions.</p>
        </div>

        <?php if(empty($orders)): ?>
            <div class="text-center py-20 bg-white border border-dashed border-slate-200 rounded-[3rem]">
                <div class="w-16 h-16 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center text-3xl mx-auto mb-4"><i class="fas fa-box-open"></i></div>
                <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">No orders found</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach($orders as $o): ?>
                <div class="bg-white border border-slate-200 rounded-[2.5rem] p-6 hover:border-blue-500 transition-all flex flex-col gap-4 shadow-sm group relative">
                    <!-- Delete Icon -->
                    <button onclick="deleteOrder(<?= $o['id'] ?>)" class="absolute top-5 right-5 text-slate-300 hover:text-red-500 transition-colors" title="Remove from history">
                        <i class="fas fa-trash-alt text-xs"></i>
                    </button>
                    <div class="flex justify-between items-start">
                        <div>
                            <h3 class="text-lg font-black text-slate-900"><?= htmlspecialchars($o['shop_name']) ?></h3>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?= htmlspecialchars($o['shop_category']) ?></p>
                        </div>
                        <?= getStatusBadge($o['order_status']) ?>
                    </div>
                    
                    <div class="bg-slate-50 rounded-2xl p-4 flex justify-between items-center">
                        <div>
                            <p class="text-[8px] font-black text-slate-400 uppercase">Amount</p>
                            <p class="text-sm font-black text-slate-900">₹<?= number_format($o['total_amount'], 2) ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[8px] font-black text-slate-400 uppercase">Date</p>
                            <p class="text-[10px] font-bold text-slate-700"><?= date('d M Y, h:i A', strtotime($o['created_at'])) ?></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button onclick="viewItems(<?= $o['id'] ?>)" class="bg-white border-2 border-slate-100 text-slate-600 font-black py-3 rounded-2xl hover:border-blue-500 hover:text-blue-600 transition-all text-[10px] uppercase tracking-widest">
                            <i class="fas fa-list me-1"></i> Items
                        </button>
                        <a href="order_status.php?order_id=<?= $o['id'] ?>" class="bg-slate-900 text-white font-black py-3 rounded-2xl hover:bg-blue-600 transition-all flex items-center justify-center gap-2 text-[10px] uppercase tracking-widest shadow-lg shadow-slate-100">
                            <i class="fas fa-eye"></i> Track
                        </a>
                        <?php if($o['order_status'] === 'delivered'): ?>
                        <a href="export_order_receipt.php?order_id=<?= $o['id'] ?>" target="_blank" class="col-span-2 bg-emerald-50 text-emerald-600 font-black py-3 rounded-2xl hover:bg-emerald-600 hover:text-white transition-all flex items-center justify-center gap-2 text-[10px] uppercase tracking-widest border border-emerald-100">
                            <i class="fas fa-file-invoice"></i> Download Receipt
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<!-- Items Modal -->
<div id="itemsModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" onclick="if(event.target === this) closeItemsModal()">
    <div class="bg-white w-full max-w-lg rounded-[2.5rem] flex flex-col max-h-[80vh] shadow-2xl overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <h3 class="text-sm font-black text-slate-900 uppercase tracking-widest">Order Details</h3>
            <button type="button" class="w-8 h-8 flex items-center justify-center bg-white border border-slate-200 rounded-full text-slate-500" onclick="closeItemsModal()"><i class="fas fa-times"></i></button>
        </div>
        <div id="modalContent" class="p-6 overflow-y-auto">
            <div class="flex justify-center py-10">
                <i class="fas fa-circle-notch fa-spin text-blue-600 text-2xl"></i>
            </div>
        </div>
        <div class="p-6 border-t border-slate-100 bg-slate-50/50">
            <button type="button" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl uppercase tracking-widest text-xs shadow-lg" onclick="closeItemsModal()">Close Window</button>
        </div>
    </div>
</div>

<script>
function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }

async function viewItems(orderId) {
    const modal = document.getElementById('itemsModal');
    const content = document.getElementById('modalContent');
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    content.innerHTML = '<div class="flex justify-center py-10"><i class="fas fa-circle-notch fa-spin text-blue-600 text-2xl"></i></div>';

    try {
        const res = await fetch(`../includes/ajax_order_items.php?order_id=${orderId}`);
        const data = await res.json();

        if(data.success) {
            content.innerHTML = `
                <div class="space-y-4">
                    ${data.items.map(it => `
                        <div class="flex justify-between items-center bg-slate-50 p-4 rounded-2xl">
                            <div>
                                <div class="text-sm font-black text-slate-900">${it.item_name}</div>
                                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">${it.quantity} ${it.unit} @ ₹${it.price_per_unit}</div>
                            </div>
                            <div class="text-sm font-black text-slate-900">₹${it.total_price}</div>
                        </div>
                    `).join('')}
                </div>
            `;
        } else {
            content.innerHTML = `<div class="text-center py-10 text-red-500 font-bold">${data.message}</div>`;
        }
    } catch (e) {
        content.innerHTML = `<div class="text-center py-10 text-red-500 font-bold">Failed to load items.</div>`;
    }
}

function closeItemsModal() {
    const modal = document.getElementById('itemsModal');
    modal.classList.remove('flex');
    modal.classList.add('hidden');
}

async function deleteOrder(orderId) {
    if(!confirm("Are you sure you want to remove this order from your history?")) return;
    
    try {
        const res = await fetch('ajax_delete_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: orderId })
        });
        const data = await res.json();
        if(data.success) {
            location.reload();
        } else {
            alert("Failed to delete order. Please try again.");
        }
    } catch (e) {
        console.error("Delete Error:", e);
    }
}
</script>
</body>
</html>