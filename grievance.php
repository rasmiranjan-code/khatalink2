<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/track_visitor.php';
track_visitor($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Grievance Redressal — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">
    <main class="max-w-3xl mx-auto py-20 px-6">
        <div class="bg-white border border-slate-200 rounded-[3rem] p-10 md:p-16 shadow-xl shadow-slate-200/50">
            <div class="w-16 h-16 bg-red-50 text-red-600 rounded-2xl flex items-center justify-center text-2xl mb-8">
                <i class="fas fa-gavel"></i>
            </div>
            <h1 class="text-4xl font-black tracking-tight mb-4">Grievance Redressal</h1>
            <p class="text-slate-500 mb-12">In compliance with the Information Technology Act 2000 and rules made thereunder.</p>

            <div class="space-y-10">
                <section>
                    <h3 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em] mb-4">The Process</h3>
                    <p class="text-sm text-slate-600 leading-relaxed">If you have any complaints regarding incorrect ledger entries, unauthorized transactions, or platform issues, please follow our reporting module inside your dashboard first. If the issue remains unresolved by the merchant, you may escalate to our Nodal Officer.</p>
                </section>

                <div class="p-8 bg-slate-900 rounded-3xl text-white">
                    <h3 class="text-xs font-black text-blue-400 uppercase tracking-[0.2em] mb-6">Nodal Officer Details</h3>
                    <div class="space-y-4 text-sm">
                        <div class="flex justify-between border-b border-white/10 pb-2">
                            <span class="text-slate-400">Name:</span>
                            <span class="font-bold">[Insert Name]</span>
                        </div>
                        <div class="flex justify-between border-b border-white/10 pb-2">
                            <span class="text-slate-400">Designation:</span>
                            <span class="font-bold">Grievance Officer</span>
                        </div>
                        <div class="flex justify-between border-b border-white/10 pb-2">
                            <span class="text-slate-400">Email:</span>
                            <span class="font-bold text-blue-400">grievance@khatalink.com</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Address:</span>
                            <span class="font-bold text-right">[Insert Office Address, India]</span>
                        </div>
                    </div>
                </div>

                <section>
                    <h3 class="text-xs font-black text-slate-400 uppercase tracking-[0.2em] mb-4">Resolution Timeline</h3>
                    <p class="text-sm text-slate-600">We aim to acknowledge your grievance within 48 hours and provide a resolution within 15 to 30 working days from the date of receipt of the complaint.</p>
                </section>
            </div>
        </div>
    </main>
</body>
</html>