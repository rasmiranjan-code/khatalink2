<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
track_visitor($pdo);

if(!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$success = '';
$error = '';

// Handle Add/Edit Tier
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_tier'])) {
    $tier_name = trim($_POST['tier_name']);
    $discount_percentage = (float)$_POST['discount_percentage'];
    $tier_id = isset($_POST['tier_id']) ? (int)$_POST['tier_id'] : 0;

    if(empty($tier_name)) {
        $error = "Tier name cannot be empty.";
    } elseif($discount_percentage < 0 || $discount_percentage > 100) {
        $error = "Discount percentage must be between 0 and 100.";
    } else {
        if($tier_id > 0) {
            // Edit existing tier
            $stmt = $pdo->prepare("UPDATE customer_tiers SET tier_name = ?, discount_percentage = ? WHERE id = ? AND shop_id = ?");
            $stmt->execute([$tier_name, $discount_percentage, $tier_id, $shop_id]);
            $success = "Tier updated successfully!";
        } else {
            // Add new tier
            $stmt = $pdo->prepare("INSERT INTO customer_tiers (shop_id, tier_name, discount_percentage) VALUES (?, ?, ?)");
            $stmt->execute([$shop_id, $tier_name, $discount_percentage]);
            $success = "New tier added successfully!";
        }
    }
}

// Handle Delete Tier
if(isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    // First, remove this tier from any customers
    $pdo->prepare("UPDATE shop_customers SET tier_id = NULL WHERE tier_id = ? AND shop_id = ?")->execute([$del_id, $shop_id]);
    // Then delete the tier
    $pdo->prepare("DELETE FROM customer_tiers WHERE id = ? AND shop_id = ?")->execute([$del_id, $shop_id]);
    $success = "Tier deleted successfully!";
}

// Fetch all tiers for this shop
$tiers_stmt = $pdo->prepare("SELECT * FROM customer_tiers WHERE shop_id = ? ORDER BY discount_percentage DESC, tier_name ASC");
$tiers_stmt->execute([$shop_id]);
$tiers = $tiers_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Tiers — KhataLink</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
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

    <div class="flex-1 p-4 md:p-8 max-w-7xl mx-auto w-full">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl font-black tracking-tight text-slate-900">Customer Tiers</h1>
            <p class="text-slate-500 text-sm">Manage loyalty tiers and default discounts for your customers.</p>
        </div>

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

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
            <div class="lg:col-span-4">
                <div class="bg-white border border-slate-200 rounded-[2rem] p-6 md:p-8 shadow-sm">
                    <h5 class="text-sm font-black text-slate-900 uppercase tracking-tight mb-6 flex items-center gap-2">
                        <i class="fas fa-plus-circle text-blue-600"></i> <span id="formTitle">Add New Tier</span>
                    </h5>
                    <form method="POST" class="space-y-5">
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Tier Identity</label>
                            <input type="text" name="tier_name" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" placeholder="e.g. Gold, VIP, Regular" required>
                        </div>
                        <div>
                            <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Default Discount (%)</label>
                            <input type="number" step="0.01" min="0" max="100" name="discount_percentage" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:bg-white focus:border-emerald-500 outline-none transition-all" value="0.00" required>
                        </div>
                        <button type="submit" name="submit_tier" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl hover:bg-blue-600 transition-all shadow-lg shadow-slate-200 flex items-center justify-center gap-2 uppercase tracking-widest text-[10px]">
                            <i class="fas fa-save"></i> Commit Tier
                        </button>
                    </form>
                </div>
            </div>
            <div class="lg:col-span-8">
                <div class="bg-white border border-slate-200 rounded-[2rem] overflow-hidden shadow-sm shadow-slate-200/50">
                    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50">
                        <h5 class="text-sm font-black text-slate-900 uppercase tracking-tight flex items-center gap-2">
                            <i class="fas fa-list text-emerald-600"></i> Active Tiers Index
                        </h5>
                    </div>
                    <?php if(count($tiers) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead>
                                    <tr class="bg-slate-50/50 border-b border-slate-100">
                                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tier Name</th>
                                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Incentive (%)</th>
                                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-50">
                                    <?php foreach($tiers as $tier): ?>
                                        <tr class="hover:bg-slate-50 transition-colors">
                                            <td class="px-6 py-5">
                                                <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($tier['tier_name']) ?></div>
                                            </td>
                                            <td class="px-6 py-5">
                                                <span class="bg-emerald-50 text-emerald-700 px-3 py-1 rounded-lg font-black text-xs"><?= number_format($tier['discount_percentage'], 2) ?>% Off</span>
                                            </td>
                                            <td class="px-6 py-5 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <button type="button" class="w-8 h-8 flex items-center justify-center rounded-lg text-blue-600 bg-blue-50 hover:bg-blue-600 hover:text-white transition-all shadow-sm" onclick="editTier(<?= $tier['id'] ?>, '<?= htmlspecialchars($tier['tier_name']) ?>', <?= $tier['discount_percentage'] ?>)">
                                                        <i class="fas fa-edit text-xs"></i>
                                                    </button>
                                                    <a href="customer_tiers.php?delete_id=<?= $tier['id'] ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-red-600 bg-red-50 hover:bg-red-600 hover:text-white transition-all shadow-sm" onclick="return confirm('Are you sure you want to delete this tier? Customers assigned to this tier will be unassigned.')">
                                                        <i class="fas fa-trash text-xs"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-20">
                            <div class="w-16 h-16 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center text-3xl mx-auto mb-6">
                                <i class="fas fa-medal"></i>
                            </div>
                            <h3 class="font-black text-slate-900 uppercase tracking-widest text-xs mb-2">No Tiers Cataloged</h3>
                            <p class="text-slate-400 text-xs font-medium">Add your first loyalty tier to start categorizing customers.</p>
                        </div>
                    <?php endif; ?>
                </div>
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
function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }

function editTier(id, name, discount) {
    const form = document.querySelector('form');
    form.querySelector('input[name="tier_name"]').value = name;
    form.querySelector('input[name="discount_percentage"]').value = discount;
    
    let hiddenIdInput = form.querySelector('input[name="tier_id"]');
    if (!hiddenIdInput) {
        hiddenIdInput = document.createElement('input');
        hiddenIdInput.type = 'hidden';
        hiddenIdInput.name = 'tier_id';
        form.appendChild(hiddenIdInput);
    }
    hiddenIdInput.value = id;
    
    form.querySelector('button[name="submit_tier"]').innerHTML = '<i class="fas fa-save"></i> Commit Updates';
    document.getElementById('formTitle').innerHTML = 'Edit Existing Tier';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
</script>
</body>
</html>