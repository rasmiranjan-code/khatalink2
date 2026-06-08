<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

session_start();
require_once '../includes/db.php';
date_default_timezone_set('Asia/Kolkata');

// ===== AUTHENTICATION LAYER (App & Web) =====
$customer_id = 0;
$is_api = false;
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'FlutterApp') {
    $is_api = true;
    header('Content-Type: application/json');
    $token      = get_auth_token();
    $parts      = verify_secure_token($token);
    $customer_id = (int)($parts[0] ?? 0);
} else {
    $customer_id = $_SESSION['customer_id'] ?? 0;
}

if (!$customer_id) {
    if ($is_api) exit(json_encode(['success' => false, 'message' => 'Unauthorized']));
    header("Location: ../auth/login.php?type=customer"); exit();
}

// Fetch All Grocery Marketplace Orders
$stmt = $pdo->prepare("
    SELECT o.*, s.shop_name, s.shop_category, s.average_rating, s.total_ratings_count,
           (SELECT COUNT(*) FROM shop_ratings sr WHERE sr.order_id = o.id) as has_rated
    FROM orders o 
    JOIN shop_owners s ON o.shop_id = s.id
    WHERE o.customer_id = ? AND o.is_marketplace_order = 1 AND s.shop_type = 'grocery'
    ORDER BY o.created_at DESC
");
$stmt->execute([$customer_id]);
$orders = $stmt->fetchAll();

// For API requests, return JSON orders list
if ($is_api) {
    exit(json_encode([
        'success' => true,
        'orders'  => $orders
    ]));
}

function getStatusColor(string $s): string {
    $colors = [
        'pending'   => 'bg-amber-50 text-amber-600',
        'delivered' => 'bg-emerald-50 text-emerald-600',
        'cancelled' => 'bg-red-50 text-red-600',
        'cancel_requested' => 'bg-rose-50 text-red-500 border border-red-100'
    ];
    return $colors[$s] ?? 'bg-blue-50 text-blue-600';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Mall Orders — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-100 py-4 px-6 flex items-center gap-4 shadow-sm">
    <a href="Groceries_home.php" class="text-slate-400"><i class="fas fa-arrow-left"></i></a>
    <h1 class="text-lg font-black uppercase tracking-tight">Mall Order History</h1>
</nav>

<main class="p-4 md:p-8 max-w-3xl mx-auto">

    <?php if(empty($orders)): ?>
        <div class="text-center py-24 bg-white rounded-[2.5rem] border border-dashed border-slate-200">
            <div class="w-20 h-20 bg-slate-50 text-slate-200 rounded-full flex items-center justify-center text-3xl mx-auto mb-4"><i class="fas fa-shopping-basket"></i></div>
            <p class="text-slate-400 font-black text-xs uppercase tracking-widest">Aapne abhi tak mall se order nahi kiya hai.</p>
            <a href="Groceries_home.php" class="mt-6 inline-block bg-emerald-600 text-white px-8 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest">Go to Mall</a>
        </div>
    <?php endif; ?>

    <div class="space-y-4">
        <?php foreach($orders as $o): ?>
        <div class="bg-white border border-slate-100 rounded-[2rem] p-6 shadow-sm hover:shadow-lg transition-all group">
            <div class="flex justify-between items-start mb-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-emerald-50 text-emerald-600 rounded-2xl flex items-center justify-center text-lg"><i class="fas fa-box"></i></div>
                    <div>
                        <h3 class="font-black text-slate-900 leading-none"><?= htmlspecialchars($o['shop_name']) ?></h3>
                        <?php if($o['average_rating'] > 0): ?>
                        <p class="text-[9px] font-bold text-amber-500 mt-1"><i class="fas fa-star me-1"></i> <?= number_format($o['average_rating'], 1) ?> (<?= $o['total_ratings_count'] ?> ratings)</p>
                        <?php endif; ?>
                        <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-1.5"><?= date('d M Y, h:i A', strtotime($o['created_at'])) ?></p>
                    </div>
                </div>
                <div class="text-right">
                    <div class="text-lg font-black text-slate-900">₹<?= number_format($o['total_amount'], 2) ?></div>
                    <span class="inline-block px-3 py-1 rounded-full text-[8px] font-black uppercase tracking-widest <?= getStatusColor($o['order_status']) ?>">
                        <?= str_replace('_', ' ', $o['order_status']) ?>
                    </span>
                </div>
            </div>
            <div class="pt-4 border-t border-slate-50 flex gap-2">
                <?php if($o['order_status'] !== 'delivered' && $o['order_status'] !== 'cancelled'): ?>
                    <a href="Groceries_order_tracking.php?order_id=<?= $o['id'] ?>" class="flex-1 bg-slate-900 text-white text-center py-3 rounded-xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-slate-200 transition-all active:scale-95">Track Progress</a>
                <?php endif; ?>
                <?php if($o['order_status'] === 'delivered' && !$o['has_rated']): // Only show if delivered and not yet rated ?>
                    <button onclick="openRatingModal(<?= $o['shop_id'] ?>, <?= $o['id'] ?>)" class="flex-1 bg-amber-50 text-amber-600 text-center py-3 rounded-xl text-[10px] font-black uppercase tracking-widest border border-amber-100">Rate Shop</button>
                <?php endif; ?>
                <a href="Groceries_order_details.php?id=<?= $o['id'] ?>" class="flex-1 bg-slate-100 text-slate-600 text-center py-3 rounded-xl text-[10px] font-black uppercase tracking-widest">Order Details</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

</main>

<footer class="text-center py-10">
    <p class="text-[10px] font-black text-slate-300 uppercase tracking-[0.3em]">KhataLink Marketplace History</p>
</footer>

<!-- Rating Modal -->
<div id="ratingModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white w-full max-w-md rounded-[2.5rem] p-8 shadow-2xl">
        <h3 class="text-xl font-black text-slate-900 mb-2">Rate your Experience</h3>
        <p class="text-xs text-slate-400 font-bold uppercase tracking-widest mb-6">How was the service and quality?</p>
        <div class="flex justify-center gap-3 mb-8">
            <?php for($i=1; $i<=5; $i++): ?>
                <button onclick="setRating(<?= $i ?>)" class="star-btn text-3xl text-slate-200 hover:scale-110 transition-transform" data-value="<?= $i ?>">
                    <i class="fas fa-star"></i>
                </button>
            <?php endfor; ?>
        </div>
        <textarea id="ratingComment" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl p-4 text-sm font-medium focus:bg-white focus:border-amber-500 outline-none transition-all mb-6" rows="3" placeholder="Any comments? (Optional)"></textarea>
        <div class="flex gap-3">
            <button onclick="closeRatingModal()" class="flex-1 py-4 text-[10px] font-black uppercase tracking-widest text-slate-400">Cancel</button>
            <button onclick="submitRating()" class="flex-1 bg-amber-500 text-white py-4 rounded-2xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-amber-100">Submit Rating</button>
        </div>
    </div>
</div>

<script>
let currentRating = 0;
let ratingShopId = 0;
let ratingOrderId = 0;

function openRatingModal(shopId, orderId) {
    ratingShopId = shopId;
    ratingOrderId = orderId;
    document.getElementById('ratingModal').classList.replace('hidden', 'flex');
}

function closeRatingModal() {
    document.getElementById('ratingModal').classList.replace('flex', 'hidden');
    currentRating = 0;
    document.querySelectorAll('.star-btn').forEach(btn => btn.classList.replace('text-amber-400', 'text-slate-200'));
    document.getElementById('ratingComment').value = '';
}

function setRating(val) {
    currentRating = val;
    document.querySelectorAll('.star-btn').forEach(btn => {
        const btnVal = parseInt(btn.dataset.value);
        btn.classList.toggle('text-amber-400', btnVal <= val);
        btn.classList.toggle('text-slate-200', btnVal > val);
    });
}

async function requestCancel(orderId) {
    const result = await Swal.fire({
        title: 'Cancel Order?',
        text: "Dukandaar ko cancellation request bheji jayegi.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Bhejo Request',
        confirmButtonColor: '#dc2626'
    });

    if(result.isConfirmed) {
        try {
            const res = await fetch('ajax_cancel_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: orderId })
            });
            const data = await res.json();
            if(data.success) {
                Swal.fire('Requested', data.message, 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        } catch(e) { Swal.fire('Error', 'Connection failed', 'error'); }
    }
}

async function submitRating() {
    if(currentRating === 0) return Swal.fire('Wait!', 'Please select a star rating.', 'warning');
    const comment = document.getElementById('ratingComment').value.trim();
    try {
        const res = await fetch('ajax_submit_shop_rating.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ shop_id: ratingShopId, order_id: ratingOrderId, rating: currentRating, comment })
        });
        const data = await res.json();
        if(data.success) {
            Swal.fire('Thank You!', data.message, 'success').then(() => location.reload());
        } else {
            Swal.fire('Error', data.message, 'error');
        }
    } catch(e) { Swal.fire('Error', 'Could not connect to server.', 'error'); }
}
</script>
</body>
</html>