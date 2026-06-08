<?php
session_start();
require_once '../includes/db.php';
if (!isset($_SESSION['admin_id'])) { header("Location: ../auth/admin_login.php"); exit(); }

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_coupon'])) {
        $code = strtoupper(trim($_POST['code']));
        $desc = trim($_POST['description']);
        $type = $_POST['discount_type'];
        $val  = (float)$_POST['discount_value'];
        $min  = (float)($_POST['min_order_value'] ?? 0);
        $exp  = $_POST['expiry_date'];

        try {
            $stmt = $pdo->prepare("INSERT INTO coupons (code, description, discount_type, discount_value, min_order_value, expiry_date) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$code, $desc, $type, $val, $min, $exp])) {
                $success = "Coupon $code created successfully!";
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { $error = "Error: Coupon code '$code' already exists."; }
            else { $error = "Database error occurred."; }
        }
    } elseif (isset($_POST['delete_id'])) {
        $pdo->prepare("DELETE FROM coupons WHERE id = ?")->execute([$_POST['delete_id']]);
        $success = "Coupon removed.";
    }
}

$coupons = $pdo->query("SELECT * FROM coupons ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Coupons — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
</head>
<body class="bg-slate-50 font-[Inter]">
    <div class="flex">
        <?php include '../includes/admin_sidebar.php'; ?>
        <main class="flex-1 p-8 max-w-5xl mx-auto">
            <div class="mb-10">
                <h1 class="text-3xl font-black text-slate-900 tracking-tight">Coupon Engine</h1>
                <p class="text-slate-500 text-sm">Create promotional codes for the Groceries Mall.</p>
            </div>

            <?php if($success): ?>
                <div class="bg-emerald-50 text-emerald-600 p-4 rounded-2xl mb-8 font-bold text-sm border border-emerald-100"><i class="fas fa-check-circle me-2"></i> <?= $success ?></div>
            <?php endif; ?>
            <?php if($error): ?>
                <div class="bg-red-50 text-red-600 p-4 rounded-2xl mb-8 font-bold text-sm border border-red-100"><i class="fas fa-exclamation-circle me-2"></i> <?= $error ?></div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                <!-- Add Form -->
                <div class="lg:col-span-5">
                    <form method="POST" class="bg-white border border-slate-200 rounded-[2.5rem] p-8 shadow-sm space-y-4">
                        <h3 class="text-xs font-black uppercase tracking-widest text-slate-400 mb-4">Create New Coupon</h3>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Coupon Code</label>
                            <input type="text" name="code" placeholder="WELCOME50" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 font-black uppercase outline-none focus:border-blue-500" required>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Offer Label</label>
                            <input type="text" name="description" placeholder="Flat ₹50 OFF on first order" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-medium outline-none focus:border-blue-500" required>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Type</label>
                                <select name="discount_type" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold outline-none">
                                    <option value="flat">Flat ₹</option>
                                    <option value="percentage">Percentage %</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Value</label>
                                <input type="number" name="discount_value" placeholder="50" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-black outline-none" required>
                            </div>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Min Order Value (₹)</label>
                            <input type="number" name="min_order_value" placeholder="100" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-black outline-none" required>
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold text-slate-400 uppercase mb-1">Expiry Date</label>
                            <input type="date" name="expiry_date" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl px-4 py-2.5 text-sm font-bold outline-none" required>
                        </div>
                        <button type="submit" name="add_coupon" class="w-full bg-slate-900 text-white font-black py-4 rounded-2xl hover:bg-blue-600 transition-all uppercase tracking-widest text-[10px] shadow-lg">Generate Offer</button>
                    </form>
                </div>

                <!-- List -->
                <div class="lg:col-span-7">
                    <div class="bg-white border border-slate-200 rounded-[2.5rem] shadow-sm overflow-hidden">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 border-b border-slate-100">
                                <tr>
                                    <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase">Code</th>
                                    <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase">Discount</th>
                                    <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase">Usage</th>
                                    <th class="px-6 py-4 text-[9px] font-black text-slate-400 uppercase">Status</th>
                                    <th class="px-6 py-4"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach($coupons as $c): 
                                    $is_expired = $c['expiry_date'] < date('Y-m-d');
                                ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="px-6 py-4">
                                        <div class="font-black text-slate-900"><?= $c['code'] ?></div>
                                        <div class="text-[9px] text-slate-400 font-bold"><?= htmlspecialchars($c['description']) ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-xs font-black text-blue-600"><?= $c['discount_type']=='flat'?'₹':'' ?><?= (float)$c['discount_value'] ?><?= $c['discount_type']=='percentage'?'%':'' ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-xs font-black text-slate-900"><?= (int)$c['usage_count'] ?></div>
                                    </td>
                                    <td>
                                        <span class="px-2 py-1 rounded-md text-[8px] font-black uppercase <?= $is_expired ? 'bg-red-50 text-red-500' : 'bg-emerald-50 text-emerald-600' ?>">
                                            <?= $is_expired ? 'Expired' : 'Active' ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <form method="POST" onsubmit="return confirm('Delete coupon?')">
                                            <input type="hidden" name="delete_id" value="<?= $c['id'] ?>">
                                            <button type="submit" class="text-slate-300 hover:text-red-500"><i class="fas fa-trash-alt"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>