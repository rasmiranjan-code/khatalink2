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
    <title>Terms and Conditions — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-800">
    <main class="max-w-4xl mx-auto py-20 px-6">
        <h1 class="text-4xl font-black tracking-tight text-slate-900 mb-12">Terms of Service</h1>

        <div class="space-y-8 bg-white border border-slate-200 rounded-[2.5rem] p-8 md:p-12 shadow-sm">
            <section>
                <h3 class="font-black text-blue-600 uppercase tracking-widest text-xs mb-4">1. Acceptance of Terms</h3>
                <p class="text-sm text-slate-600">By creating an account on KhataLink, you agree to be bound by these terms. KhataLink is a digital ledger platform and does not provide credit itself; it facilitates the recording of credit agreements between independent Merchants and Customers.</p>
            </section>

            <section>
                <h3 class="font-black text-blue-600 uppercase tracking-widest text-xs mb-4">2. Legal Security Bonds</h3>
                <p class="text-sm text-slate-600">The "Bond System" generates legally binding digital instruments. By signing a bond, the Debtor acknowledges a legal liability to pay the specified sum. KhataLink reserves the right to provide these records as evidence in judicial proceedings in case of defaults.</p>
            </section>

            <section>
                <h3 class="font-black text-blue-600 uppercase tracking-widest text-xs mb-4">3. Platform Pay & Commissions</h3>
                <p class="text-sm text-slate-600">KhataLink utilizes <b>Cashfree Payments</b> for all financial transactions. A platform convenience fee is applicable on all online settlements. 
                <br><br><b>Unlinked Merchant Accounts:</b> In the event that a Merchant has not linked a direct settlement account via the Cashfree Dashboard, KhataLink serves as the primary nodal facilitator. All payments collected for such merchants will be consolidated and disbursed to the merchant's verified bank account within <b>24 hours</b> of the transaction date.</p>
            </section>

            <section>
                <h3 class="font-black text-blue-600 uppercase tracking-widest text-xs mb-4">4. AI Voice & OCR Disclaimer</h3>
                <p class="text-sm text-slate-600">While our AI Billing and OCR systems are highly accurate, users are required to verify the final itemized bill before committing it to the ledger. KhataLink is not responsible for mathematical errors resulting from unprocessed voice or image data.</p>
            </section>

            <section>
                <h3 class="font-black text-blue-600 uppercase tracking-widest text-xs mb-4">5. Automated Reminders</h3>
                <p class="text-sm text-slate-600">By using our services, Customers consent to receiving transaction alerts and payment reminders via WhatsApp, Email, and SMS based on the records maintained by the Merchant.</p>
            </section>

            <section>
                <h3 class="font-black text-blue-600 uppercase tracking-widest text-xs mb-4">6. Jurisdiction</h3>
                <p class="text-sm text-slate-600">Any legal disputes arising out of the use of the platform shall be subject to the exclusive jurisdiction of the courts located in [Your City, State], India.</p>
            </section>
        </div>
    </main>
</body>
</html>