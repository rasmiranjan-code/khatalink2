<?php
session_start();
require_once '../includes/db.php';
if(!isset($_SESSION['shop_id'])) { header("Location: ../auth/login.php?type=shop"); exit(); }

$shop_id = $_SESSION['shop_id'];
$common_problems = [
    "Cashfree settlement delayed beyond 24 hours.",
    "Voice POS not recognizing my accent/items.",
    "Inventory stock not deducting on Udhar entry.",
    "Customer unique ID search returning no results.",
    "Unable to generate legal bond for high-value credit.",
    "OCR Bill scanner failed to read supplier invoice.",
    "Stuck payment showing in admin reconciliation.",
    "Customer Tier discount logic miscalculation.",
    "Analytics graph showing incorrect credit trends.",
    "Low stock alert notification not received on WhatsApp.",
    "Barcode scanner not reading certain item codes.",
    "Duplicate customer account found in database.",
    "Failed to export daily collection report to CSV.",
    "Refund requested for an online payment.",
    "Other operational issue."
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Raise Support Ticket — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">
    <nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 shadow-sm">
        <a href="dashboard.php"><img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8"></a>
        <span class="text-[10px] font-black text-blue-600 uppercase tracking-widest">Merchant HelpDesk</span>
    </nav>
    <div class="flex">
        <?php include '../includes/shop_sidebar.php'; ?>
        <main class="flex-1 p-8 max-w-4xl mx-auto">
            <div class="mb-10">
                <h1 class="text-3xl font-black tracking-tight">Create Support Ticket</h1>
                <p class="text-slate-500">Select a common issue or write your own to get help from KhataLink Admin.</p>
            </div>
            <form id="ticketForm" class="bg-white border border-slate-200 rounded-[2.5rem] p-8 md:p-12 shadow-sm">
                <div class="mb-8">
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-4">Select Common Issue</label>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <?php foreach($common_problems as $problem): ?>
                        <label class="relative flex items-start p-4 border-2 border-slate-100 rounded-2xl cursor-pointer hover:border-blue-500 transition-all group">
                            <input type="radio" name="common_subject" value="<?= $problem ?>" class="mt-1 mr-3 accent-blue-600" onchange="updateSubject(this.value)">
                            <span class="text-xs font-bold text-slate-600 group-hover:text-slate-900"><?= $problem ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="space-y-6">
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Subject / Problem Title</label>
                        <input type="text" name="subject" id="subjectInput" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" placeholder="Enter problem summary..." required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Additional Description</label>
                        <textarea name="message" id="messageInput" rows="5" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold focus:bg-white focus:border-blue-500 outline-none transition-all" placeholder="Explain your problem in detail for faster resolution..." required></textarea>
                    </div>
                    <button type="button" onclick="submitTicket()" id="submitBtn" class="w-full bg-slate-900 text-white font-black py-5 rounded-2xl hover:bg-blue-600 transition-all shadow-xl shadow-slate-200 uppercase tracking-widest text-xs flex items-center justify-center gap-3">
                        <i class="fas fa-paper-plane"></i> Submit Ticket to Admin
                    </button>
                </div>
            </form>
        </main>
    </div>
    <script>
        function updateSubject(val) { document.getElementById('subjectInput').value = val; }
        async function submitTicket() {
            const sub = document.getElementById('subjectInput').value;
            const msg = document.getElementById('messageInput').value;
            const btn = document.getElementById('submitBtn');
            if(!sub || !msg) { alert("Please fill all fields"); return; }
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
            const formData = new URLSearchParams();
            formData.append('subject', sub);
            formData.append('message', msg);
            const res = await fetch('../includes/ajax_submit_query.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            });
            const data = await res.json();
            if(data.success) {
                alert(data.message);
                window.location.href = "queries.php";
            } else {
                alert(data.message);
                btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Ticket';
            }
        }
    </script>
</body>
</html>