<?php
require_once 'includes/db.php';

$bond_id = (int)($_GET['id'] ?? 0);
$is_valid = false;
$b = null;

if ($bond_id > 0) {
    $stmt = $pdo->prepare("
        SELECT b.*, c.name as customer_name, c.unique_id as customer_uid, 
               s.shop_name, s.shop_category
        FROM bonds b
        JOIN customers c ON b.customer_id = c.id
        JOIN shop_owners s ON b.shop_id = s.id
        WHERE b.id = ?
    ");
    $stmt->execute([$bond_id]);
    $b = $stmt->fetch();
    if ($b) {
        $is_valid = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Legal Bond — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">

    <div class="max-w-md w-full bg-white rounded-[2.5rem] shadow-2xl overflow-hidden border border-slate-100">
        <!-- Header -->
        <div class="p-8 text-center border-b border-slate-50">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-10 mx-auto mb-4">
            <h1 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em]">Bond Verification System</h1>
        </div>

        <?php if ($is_valid): ?>
            <!-- Success Content -->
            <div class="p-8">
                <div class="flex justify-center mb-6">
                    <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center text-2xl shadow-inner animate-bounce">
                        <i class="fas fa-check-shield"></i>
                    </div>
                </div>
                
                <div class="text-center mb-8">
                    <h2 class="text-xl font-black text-slate-900 mb-1">Authentic Record Found</h2>
                    <p class="text-slate-500 text-xs font-medium">Verified on <?= date('d M Y, h:i A') ?></p>
                </div>

                <div class="space-y-4">
                    <div class="bg-slate-50 rounded-2xl p-4 border border-slate-100">
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-1">Merchant</label>
                        <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($b['shop_name']) ?></div>
                        <div class="text-[10px] font-bold text-blue-600 uppercase"><?= htmlspecialchars($b['shop_category']) ?></div>
                    </div>

                    <div class="bg-slate-50 rounded-2xl p-4 border border-slate-100">
                        <label class="block text-[9px] font-black text-slate-400 uppercase mb-1">Debtor / Customer</label>
                        <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($b['customer_name']) ?></div>
                        <div class="text-[10px] font-bold text-slate-500 uppercase"><?= $b['customer_uid'] ?></div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-slate-50 rounded-2xl p-4 border border-slate-100">
                            <label class="block text-[9px] font-black text-slate-400 uppercase mb-1">Bond Value</label>
                            <div class="text-lg font-black text-red-600">₹<?= number_format($b['amount'], 0) ?></div>
                        </div>
                        <div class="bg-slate-50 rounded-2xl p-4 border border-slate-100">
                            <label class="block text-[9px] font-black text-slate-400 uppercase mb-1">Status</label>
                            <div class="text-lg font-black <?= $b['status'] == 'closed' ? 'text-emerald-600' : 'text-blue-600' ?> uppercase"><?= $b['status'] ?></div>
                        </div>
                    </div>
                </div>

                <p class="mt-8 text-[10px] text-slate-400 text-center leading-relaxed">
                    Ye digital bond KhataLink platform par digitally sign aur secure kiya gaya hai. Is record ke sath koi chhed-chaad nahi ki ja sakti.
                </p>
            </div>
        <?php else: ?>
            <!-- Error Content -->
            <div class="p-8 text-center">
                <div class="flex justify-center mb-6">
                    <div class="w-16 h-16 bg-red-50 text-red-600 rounded-full flex items-center justify-center text-2xl">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
                <h2 class="text-xl font-black text-slate-900 mb-2">Invalid Bond ID</h2>
                <p class="text-slate-500 text-sm mb-6">Hame ye bond record apne system mein nahi mila. Kripya QR code check karein.</p>
                <a href="index.php" class="inline-block bg-slate-900 text-white font-black px-8 py-3 rounded-xl text-[10px] uppercase tracking-widest shadow-lg">Back to Home</a>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="p-6 bg-slate-900 text-center">
            <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">© <?= date('Y') ?> KhataLink Digital Ledger Ecosystem</p>
        </div>
    </div>

</body>
</html>