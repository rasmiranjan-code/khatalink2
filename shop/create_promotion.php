<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=shop");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$current_page = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Promotion - KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; }
    </style>
</head>
<body>

<div class="flex min-h-screen">
    <?php include '../includes/shop_sidebar.php'; ?>

    <main class="flex-1 p-6 md:p-10 max-w-3xl mx-auto">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-3xl font-black text-slate-800">Create New Promotion</h1>
                <p class="text-sm text-slate-500">Design an offer and send it to your customers.</p>
            </div>
            <a href="promotions.php" class="bg-white border border-slate-200 text-slate-600 font-bold py-2 px-4 rounded-lg text-xs uppercase tracking-wider hover:bg-slate-50 transition-all">
                <i class="fas fa-arrow-left mr-2"></i> Back
            </a>
        </div>

        <form id="promotionForm" class="bg-white border border-slate-200 rounded-3xl p-8 shadow-sm space-y-6">
            <input type="hidden" name="shop_id" value="<?= $shop_id ?>">

            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Promotion Name</label>
                <input type="text" name="name" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl p-3 text-sm font-bold focus:border-blue-500 outline-none" placeholder="e.g., Monsoon Sale, Weekend Special" required>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Offer Type</label>
                    <select name="offer_type" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl p-3 text-sm font-bold outline-none cursor-pointer">
                        <option value="percentage">Percentage (%)</option>
                        <option value="flat">Flat (₹)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Offer Value</label>
                    <input type="number" name="offer_value" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl p-3 text-sm font-bold focus:border-blue-500 outline-none" placeholder="e.g., 10 or 50" required>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Target Customer Segment</label>
                <select name="target_segment" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl p-3 text-sm font-bold outline-none cursor-pointer">
                    <option value="all">All My Customers</option>
                    <option value="champions">Champions (Top Spenders)</option>
                    <option value="at_risk">At-Risk (Inactive Customers)</option>
                    <option value="new">New Customers (Joined in last 30 days)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Notification Message</label>
                <textarea name="message" rows="3" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl p-3 text-sm font-medium focus:border-blue-500 outline-none" placeholder="This message will be sent to customers." required></textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Start Date</label>
                    <input type="date" name="start_date" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl p-3 text-sm font-bold outline-none" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">End Date</label>
                    <input type="date" name="end_date" class="w-full bg-slate-50 border-2 border-slate-100 rounded-xl p-3 text-sm font-bold outline-none" required>
                </div>
            </div>

            <div class="pt-6 border-t border-slate-100">
                <button type="submit" class="w-full bg-blue-600 text-white font-bold py-4 rounded-xl text-sm uppercase tracking-wider hover:bg-blue-700 transition-all shadow-lg shadow-blue-500/20">
                    <i class="fas fa-paper-plane mr-2"></i> Save & Send to Customers
                </button>
            </div>
        </form>
    </main>
</div>

<script>
document.getElementById('promotionForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const form = this;
    const formData = new FormData(form);

    Swal.fire({
        title: 'Confirm & Send?',
        html: "This will send a notification to all targeted customers. This action cannot be undone.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, send it!'
    }).then(async (result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Processing...',
                html: 'Finding customers and sending notifications. Please wait.',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading() }
            });

            try {
                const response = await fetch('ajax_create_promotion.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: data.message,
                        icon: 'success'
                    }).then(() => {
                        window.location.href = 'promotions.php';
                    });
                } else {
                    Swal.fire('Error!', data.message, 'error');
                }
            } catch (error) {
                Swal.fire('Network Error!', 'Could not connect to the server.', 'error');
            }
        }
    });
});
</script>

</body>
</html>