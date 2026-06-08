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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; line-height: 1.6; }</style>
</head>
<body class="bg-slate-50 text-slate-800">
    <main class="max-w-3xl mx-auto py-20 px-6">
        <h1 class="text-4xl font-black tracking-tight text-slate-900 mb-2">Privacy Policy</h1>
        <p class="text-slate-400 text-sm font-bold uppercase tracking-widest mb-12">Last Updated: May 2026</p>

        <div class="space-y-12 text-sm md:text-base">
            <section>
                <h2 class="text-xl font-black text-slate-900 uppercase tracking-tight mb-4">1. Data Collection</h2>
                <p>KhataLink collects information necessary to facilitate digital ledger services. This includes:</p>
                <ul class="list-disc pl-6 mt-4 space-y-2">
                    <li><strong>Personal Identity:</strong> Name, Email, WhatsApp Number, and KhataLink Unique ID.</li>
                    <li><strong>Transactional Data:</strong> Purchase history, payment logs, and credit balances.</li>
                    <li><strong>AI & Media:</strong> Voice recordings for AI Billing, uploaded bill images for OCR processing, and digital signatures for Legal Bonds.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-xl font-black text-slate-900 uppercase tracking-tight mb-4">2. Use of Information</h2>
                <p>Your data is used to:</p>
                <ul class="list-disc pl-6 mt-4 space-y-2">
                    <li>Maintain real-time synchronization between Shop Owners and Customers.</li>
                    <li>Process secure transactions via Cashfree Payment Gateway and Platform Pay.</li>
                    <li>Generate automated WhatsApp reminders and PDF statements.</li>
                    <li>Calculate Trust Scores for credit assessment within the KhataLink network.</li>
                </ul>
            </section>

            <section>
                <h2 class="text-xl font-black text-slate-900 uppercase tracking-tight mb-4">3. Data Security</h2>
                <p>We implement world-class cryptographic hashing for passwords and secure SSL encryption for all data transmissions. Financial sensitive information like Bank Account details are encrypted at rest.</p>
            </section>

            <section class="p-8 bg-blue-600 rounded-[2rem] text-white">
                <h2 class="text-xl font-black uppercase tracking-tight mb-4">4. Third-Party Sharing</h2>
                <p class="text-blue-100">We do not sell your personal data. Data is shared only with our primary payment partner (Cashfree Payments) and AI processing engines to fulfill requested services.</p>
            </section>

            <section>
                <h2 class="text-xl font-black text-slate-900 uppercase tracking-tight mb-4">5. Your Rights</h2>
                <p>Users have the right to request a copy of their ledger data, report discrepancies via our dispute module, and request account deactivation. Note that transactional records linked to active debts cannot be deleted until the balance is settled.</p>
            </section>
        </div>
    </main>
</body>
</html>