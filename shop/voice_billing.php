<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/track_visitor.php';
track_visitor($pdo);
if (!isset($_SESSION['shop_id'])) { header("Location: ../auth/login.php"); exit(); }
$shop_id  = (int) $_SESSION['shop_id'];
$shopName = htmlspecialchars($_SESSION['shop_name'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<?php
// Fetch all customers for the "Transfer to Udhar" dropdown
$stmt_customers = $pdo->prepare("SELECT c.id, c.name, c.unique_id FROM customers c JOIN shop_customers sc ON c.id = sc.customer_id WHERE sc.shop_id = ? ORDER BY c.name ASC");
$stmt_customers->execute([$shop_id]);
$all_customers = $stmt_customers->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Voice POS — KhataLink</title>
    <link rel="icon" type="image/png" href="../assets/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- SweetAlert2 for Animated Popups -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }

        /* Wave animation */
        .wave-bar { animation: wave-jump 1s infinite ease-in-out; }
        .wave-bar:nth-child(2) { animation-delay: .1s; }
        .wave-bar:nth-child(3) { animation-delay: .2s; }
        .wave-bar:nth-child(4) { animation-delay: .3s; }
        .wave-bar:nth-child(5) { animation-delay: .4s; }
        @keyframes wave-jump { 0%,100%{height:8px;opacity:.5} 50%{height:24px;opacity:1} }

        /* Wave hidden by default, shown when active */
        #waveContainer { visibility: hidden; }
        #waveContainer.active { visibility: visible; }

        /* Mic listening ring */
        #micBtn .ping-ring { display: none; }
        #micBtn.listening .ping-ring { display: block; }

        /* Bill table inputs */
        .item-name-input,
        .item-qty-input,
        .item-rate-input {
            background: transparent;
            border: none;
            outline: none;
            width: 100%;
            padding: 0.25rem 0.5rem;
            font-size: .875rem;
        }
        .item-qty-input,
        .item-rate-input { text-align: right; }

        .item-small-input { 
            width: 45px !important; 
            text-align: center !important;
            background: #f8fafc !important;
            border-radius: 6px !important;
        }

        /* Search dropdown */
        #searchDropdown { display: none; }
        #searchDropdown .search-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: .75rem 1.25rem;
            cursor: pointer;
            transition: background .15s;
        }
        #searchDropdown .search-item:hover { background: #f1f5f9; }

        .suggestions-box {
            display: none; position: absolute; width: 100%; z-index: 1050; background: white; border: 1px solid #e2e8f0; border-radius: 1rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); max-height: 200px; overflow-y: auto;
        }

        /* Fade-in new rows */
        @keyframes row-in { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }
        .item-row { animation: row-in .2s ease; }

        /* Unknown item highlight */
        .item-row.unknown td:first-child { border-left: 3px solid #f59e0b; }

        /* Mobile Compact Table - No Horizontal Scroll */
        @media (max-width: 640px) {
            .item-name-input { font-size: 11px; padding: 2px 4px !important; }
            .item-qty-input, .item-rate-input, .item-unit-input { font-size: 10px; padding: 2px !important; }
            th, td { padding: 6px 2px !important; font-size: 9px !important; }
            .row-total { font-size: 11px; min-width: 50px; white-space: nowrap; }
            .bill-card { padding: 1rem !important; border-radius: 1.5rem !important; overflow-x: auto; }
            .col-unit { width: 35px !important; } .col-qty { width: 40px !important; }
            .col-rate { width: 50px !important; } .col-total { width: 60px !important; }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

<!-- Overlay for mobile sidebar -->
<div class="fixed inset-0 bg-slate-900/40 z-[998] hidden backdrop-blur-sm transition-opacity" id="overlay" onclick="closeSidebar()"></div>

<!-- Navbar -->
<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-4 md:px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <button class="lg:hidden text-slate-600 p-2 hover:bg-slate-100 rounded-lg" onclick="openSidebar()" aria-label="Open sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <a href="dashboard.php" class="flex items-center">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-8 w-auto">
        </a>
    </div>
    <div class="hidden sm:flex items-center gap-2 bg-blue-50 border border-blue-100 text-blue-700 text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-wider">
        <i class="fas fa-store"></i>
        <?= $shopName ?>
    </div>
</nav>

<div class="flex min-h-[calc(100vh-64px)]">
    <?php include '../includes/shop_sidebar.php'; ?>

    <!-- Main content -->
    <div class="flex-1 p-4 md:p-8 max-w-5xl mx-auto w-full">
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl font-black tracking-tight text-slate-900">AI Voice POS</h1>
            <p class="text-slate-500 text-sm">Hands-free billing with real-time inventory matching.</p>
        </div>

        <div class="bg-white border border-slate-200 rounded-[2.5rem] p-4 md:p-10 shadow-xl shadow-slate-200/50 bill-card">

            <!-- Controls row -->
            <div class="flex flex-col md:flex-row justify-between items-center gap-4 mb-6 border-b border-slate-100 pb-6">
                <!-- Manual Search -->
                <div class="w-full md:w-2/3 relative">
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">
                        <i class="fas fa-search me-1"></i> Manual Search
                    </label>
                    <div class="flex gap-2">
                        <input type="text" id="manualSearch" autocomplete="off"
                               class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm focus:bg-white focus:border-blue-500 outline-none transition-all"
                               placeholder="Type item name if voice fails…">
                        <button type="button" onclick="startScanner()" class="bg-blue-600 text-white w-14 rounded-2xl flex items-center justify-center hover:bg-blue-700 shadow-lg shadow-blue-100 transition-all"><i class="fas fa-barcode"></i></button>
                    </div>
                    <div id="searchDropdown"
                         class="absolute w-full z-[1000] bg-white border border-slate-200 rounded-2xl shadow-xl mt-2 max-h-60 overflow-y-auto"
                         role="listbox"></div>
                </div>

                <!-- Language select -->
                <div class="w-full md:w-1/3">
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2 text-center md:text-right">Speech Language</label>
                    <select id="langSelect"
                            class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold text-slate-700 outline-none cursor-pointer">
                        <option value="hi-IN">Hindi (हिंदी)</option>
                        <option value="en-IN">English (India)</option>
                        <option value="or-IN">Odia (ଓଡ଼ିଆ)</option>
                    </select>
                </div>
            </div>

            <!-- Mic & Manual Controls - Reduced Spacing -->
            <div class="flex flex-col items-center justify-center py-2 mb-4 border-b border-slate-50 pb-4">
                <div id="waveContainer" class="flex items-center gap-1.5 h-6 mb-2" aria-hidden="true">
                    <div class="wave-bar w-1 bg-blue-600 rounded-full"></div>
                    <div class="wave-bar w-1 bg-blue-600 rounded-full"></div>
                    <div class="wave-bar w-1 bg-blue-600 rounded-full"></div>
                    <div class="wave-bar w-1 bg-blue-600 rounded-full"></div>
                    <div class="wave-bar w-1 bg-blue-600 rounded-full"></div>
                </div>

                <div class="flex items-center gap-6">
                    <button id="micBtn"
                            class="w-20 h-20 rounded-full bg-blue-600 text-white text-2xl flex items-center justify-center transition-all hover:scale-110 active:scale-95 shadow-2xl shadow-blue-200 relative"
                            onclick="toggleListening()"
                            aria-label="Toggle voice input">
                        <i class="fas fa-microphone"></i>
                        <div class="ping-ring absolute -inset-2 bg-blue-600/10 rounded-full animate-ping"></div>
                    </button>

                    <button id="manualEntryToggle"
                            class="w-14 h-14 rounded-full bg-slate-100 text-slate-500 text-lg flex items-center justify-center transition-all hover:bg-indigo-600 hover:text-white shadow-lg"
                            onclick="toggleManualForm()"
                            title="Manual Entry Mode">
                        <i class="fas fa-keyboard"></i>
                    </button>
                </div>

                <div class="text-center mt-6">
                    <div id="status" class="text-[9px] font-black text-slate-400 uppercase tracking-[.2em]">Click mic to start or type manually</div>
                    <div id="liveTranscript" class="text-blue-600 font-bold italic h-5 mt-1 text-xs" aria-live="polite"></div>
                </div>
            </div>
            
            <!-- Customer Select for Manual/Voice -->
            <div class="mb-6">
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Select Customer</label>
                <select id="billCustomerId" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-blue-500">
                    <option value="">Guest Customer</option>
                    <?php foreach($all_customers as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= $c['unique_id'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Manual Form Section -->
            <div id="manualFormSection" class="mb-10 p-6 bg-slate-50 border border-slate-200 rounded-[2rem] hidden">
                <div class="text-[10px] font-black text-indigo-600 uppercase tracking-widest mb-4 flex items-center gap-2">
                    <i class="fas fa-edit"></i> Structured Manual Entry
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="md:col-span-2 relative">
                        <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Item Name</label>
                        <input type="text" id="manItemName" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 outline-none" placeholder="Type item name...">
                        <div id="itemSuggestions" class="suggestions-box"></div>
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Qty</label>
                        <input type="number" id="manItemQty" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 outline-none" value="1" step="0.01">
                    </div>
                    <div>
                        <label class="block text-[9px] font-bold text-slate-400 uppercase mb-1">Unit</label>
                        <select id="manItemUnit" class="w-full bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-bold focus:border-indigo-500 outline-none">
                            <option value="NOS">NOS</option>
                            <option value="KGS">KGS</option>
                            <option value="PCS">PCS</option>
                            <option value="PKT">PKT</option>
                            <option value="LTR">LTR</option>
                            <option value="GM">GM</option>
                            <option value="BAG">BAG</option>
                            <option value="QTL">QTL</option>
                            <option value="BTL">BTL</option>
                            <option value="BOR">BOR</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex justify-end">
                    <button onclick="addManualEntryRow()" class="bg-indigo-600 text-white text-[10px] font-black px-6 py-2.5 rounded-xl uppercase tracking-widest hover:bg-indigo-700 transition-all">
                        Add to Bill
                    </button>
                </div>
            </div>

            <!-- Bill table -->
            <div class="overflow-x-auto bg-slate-50/50 rounded-3xl border border-slate-100 mb-8">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-slate-100">
                            <th class="px-2 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Item</th>
                            <th class="px-2 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest w-12 text-center col-unit">Unit</th>
                            <th class="px-2 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest w-16 text-right col-qty">Qty</th>
                            <th class="px-2 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest w-20 text-right col-rate">Rate</th>
                            <th class="px-2 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest w-12 text-right col-disc">Disc</th>
                            <th class="px-2 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest w-12 text-right col-gst">GST</th>
                            <th class="px-2 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right w-24 col-total">Net</th>
                            <th class="px-6 py-4 w-10"></th><!-- Delete col -->
                        </tr>
                    </thead>
                    <tbody id="billBody" class="divide-y divide-slate-100"></tbody>
                    <tfoot>
                        <tr class="bg-slate-900 text-white">
                            <td colspan="7" class="px-4 py-2 text-[10px] font-black uppercase tracking-[.2em] text-slate-400 text-right">Grand Total</td>
                            <td class="px-4 py-2 text-lg font-black text-right" id="grandTotalNet">₹0.00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- QR + Action buttons -->
            <div class="flex flex-col md:flex-row items-center gap-8 mt-8">
                <div class="flex-1 w-full grid grid-cols-1 md:grid-cols-3 gap-4">
                    <button type="button" class="bg-slate-100 text-slate-600 font-bold py-4 rounded-2xl hover:bg-slate-200 active:scale-95 transition-all flex items-center justify-center gap-2" onclick="resetPOS()">
                        <i class="fas fa-trash-alt text-xs"></i> Reset
                    </button>
                    <button type="button" class="bg-blue-600 text-white font-black py-4 rounded-2xl hover:bg-blue-700 active:scale-95 transition-all shadow-lg shadow-blue-100 flex items-center justify-center gap-2" onclick="openSaveBillModal('cash')">
                        <i class="fas fa-money-bill-wave text-xs"></i> Save & Pay
                    </button>
                    <button type="button" class="bg-red-600 text-white font-black py-4 rounded-2xl hover:bg-red-700 active:scale-95 transition-all shadow-lg shadow-red-100 flex items-center justify-center gap-2" onclick="openSaveBillModal('udhar')">
                        <i class="fas fa-hand-holding-usd text-xs"></i> Transfer to Udhar
                    </button>
                    <a href="pos_history.php" class="bg-slate-100 text-slate-600 font-black py-4 rounded-2xl hover:bg-slate-200 active:scale-95 transition-all shadow-lg shadow-slate-200 flex items-center justify-center gap-2">
                        <i class="fas fa-history text-xs"></i> View History
                    </a>
                </div>
            </div>
        </div><!-- /bill-card -->
<div id="saveBillModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm" onclick="if(event.target === this) closeSaveBillModal()">
    <div class="bg-white w-full max-w-md rounded-[2.5rem] p-8 shadow-2xl animate-[slideUp_0.3s_ease]">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-black text-slate-900 uppercase tracking-tight" id="saveBillModalTitle">Save Bill</h2>
            <button onclick="closeSaveBillModal()" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times"></i></button>
        </div>
        
        <div id="saveBillModalContent">
            <!-- Content will be dynamically loaded here -->
            <p class="text-slate-500 text-sm mb-4">Final Bill Amount: <span class="font-black text-slate-900 text-lg" id="finalBillAmountDisplay">₹0.00</span></p>
            
            <div id="udharCustomerSelect" class="mb-4 hidden">
                <label class="block text-[10px] font-black text-slate-400 uppercase mb-2">Select Customer for Udhar</label>
                <select id="udharCustomerId" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none focus:border-blue-500">
                    <option value="">-- Select Customer --</option>
                    <?php foreach($all_customers as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= $c['unique_id'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeSaveBillModal()" class="flex-1 bg-slate-100 text-slate-500 font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest">Cancel</button>
                <button type="button" onclick="confirmSaveBill()" class="flex-1 bg-emerald-600 text-white font-black py-4 rounded-2xl text-[10px] uppercase tracking-widest shadow-lg shadow-emerald-100" id="confirmSaveBillBtn">Confirm</button>
                </div>
            </div>
        </div><!-- /card -->
    </div><!-- /main -->
</div><!-- /flex wrapper -->

<!-- Hidden print form -->
<form id="printForm" action="export_pos_bill.php" method="POST" target="_blank" style="display:none">
    <input type="hidden" name="bill_data" id="billDataInput">
    <input type="hidden" name="cust_name"  id="custNamePost">
    <input type="hidden" name="bill_id" id="billIdPost">
</form>

<!-- Barcode Scanner Modal -->
<div id="posScannerModal" class="fixed inset-0 z-[3000] hidden items-center justify-center p-4 bg-slate-900/80 backdrop-blur-md">
    <div class="bg-white w-full max-w-md rounded-[2.5rem] overflow-hidden shadow-2xl">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center">
            <h3 class="font-black uppercase tracking-widest text-xs">Scan Item Barcode</h3>
            <button onclick="stopScanner()" class="text-slate-400"><i class="fas fa-times"></i></button>
        </div>
        <div id="posReader" class="w-full h-64 bg-black"></div>
        <div class="p-6 text-center">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Instant inventory lookup</p>
        </div>
    </div>
</div>

<script>
let recognition = null;
let isListening         = false;
let isManuallyStopped   = false;
let isWaitingForName    = false; // Still needed for voice input flow
let currentUtterance    = null;
let audioCtx = null, analyser = null, dataArray = null, animationId = null, micStream = null;
let currentBillMode = 'cash'; // 'cash' or 'udhar'

// ─── Helpers ──────────────────────────────────────────────────────────────────
/** Safely escape a string for insertion into innerHTML */
function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

/** Initialize AudioContext on first interaction if needed */
function initAudio() {
    if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    } else if (audioCtx.state === 'suspended') {
        audioCtx.resume();
    }
}

function setStatus(msg) {
    document.getElementById('status').textContent = msg;
}

// ─── Audio helpers ────────────────────────────────────────────────────────────
function playBeep(frequency, duration) {
    initAudio();
    if (!audioCtx || audioCtx.state === 'suspended') return;
    
    const osc  = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    
    // Use 'triangle' or 'square' for more audible sound if sine is too soft
    osc.type = 'triangle'; 
    osc.frequency.setValueAtTime(frequency, audioCtx.currentTime);
    
    // Ramping down the volume to avoid clicks
    gain.gain.setValueAtTime(0.1, audioCtx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + duration/1000);
    
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    osc.start();
    setTimeout(() => osc.stop(), duration);
}

async function startVolumeAnalysis() {
    try {
        if (!micStream) micStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const source = audioCtx.createMediaStreamSource(micStream);
        analyser = audioCtx.createAnalyser();
        analyser.fftSize = 256;
        source.connect(analyser);
        dataArray = new Uint8Array(analyser.frequencyBinCount);
        visualizeVolume();
    } catch (err) {
        console.warn('Mic glow unavailable:', err);
    }
}

function visualizeVolume() {
    if (!isListening) {
        document.getElementById('micBtn').style.boxShadow = '';
        document.querySelectorAll('.wave-bar').forEach(b => b.style.backgroundColor = '#2563eb');
        cancelAnimationFrame(animationId);
        return;
    }
    animationId = requestAnimationFrame(visualizeVolume);
    analyser.getByteFrequencyData(dataArray);
    const avg = dataArray.reduce((s, v) => s + v, 0) / dataArray.length;
    let rgb = '37,99,235';
    if (avg > 40) rgb = '220,38,38';
    else if (avg > 20) rgb = '217,119,6';
    document.getElementById('micBtn').style.boxShadow = `0 0 ${20 + avg * 1.5}px rgba(${rgb},.8)`;
    document.querySelectorAll('.wave-bar').forEach(b => b.style.backgroundColor = `rgb(${rgb})`);
}

// ─── TTS ──────────────────────────────────────────────────────────────────────
function speak(text, onComplete) {
    if (window.speechSynthesis.speaking) window.speechSynthesis.cancel();
    currentUtterance = new SpeechSynthesisUtterance(text);
    currentUtterance.lang = document.getElementById('langSelect').value;
    currentUtterance.onend  = () => { if (onComplete) onComplete(); currentUtterance = null; };
    currentUtterance.onerror = () => { if (onComplete) onComplete(); currentUtterance = null; };
    window.speechSynthesis.speak(currentUtterance);
}

// ─── Speech Recognition setup ─────────────────────────────────────────────────
if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    recognition = new SR();
    recognition.continuous     = true;
    recognition.interimResults = true;
    recognition.maxAlternatives = 1;

    recognition.onstart = () => {
        isListening = true;
        playBeep(660, 100);
        document.getElementById('micBtn').classList.add('listening');
        document.getElementById('waveContainer').classList.add('active');
        setStatus('Listening… speak now');
    };

    recognition.onresult = (event) => {
        let interim = '';
        for (let i = event.resultIndex; i < event.results.length; i++) {
            if (event.results[i].isFinal) {
                const transcript = event.results[i][0].transcript;
                if (isWaitingForName) handleNameCapture(transcript);
                else                  processSpeech(transcript);
            } else {
                interim += event.results[i][0].transcript;
            }
        }
        document.getElementById('liveTranscript').textContent = interim;
    };

    recognition.onend = () => {
        if (!isManuallyStopped) {
            // Auto-restart unless we deliberately stopped
            try { recognition.start(); } catch (e) { /* already started */ }
        } else {
            isListening = false;
            playBeep(440, 150);
            document.getElementById('micBtn').classList.remove('listening');
            document.getElementById('waveContainer').classList.remove('active');
            document.getElementById('micBtn').style.boxShadow = '';
            setStatus('Stopped. Click to speak again.');
        }
    };

    recognition.onerror = (event) => {
        console.error('Speech error:', event.error);
        if (event.error === 'not-allowed') {
            alert('Microphone access blocked. Please enable it in your browser settings.');
            isManuallyStopped = true; // Prevent restart loop
        }
        // 'no-speech' is harmless — recognition.onend will restart it
    };
}

// ─── Manual Form Logic ────────────────────────────────────────────────────────
function toggleManualForm() {
    const section = document.getElementById('manualFormSection');
    section.classList.toggle('hidden');
    if(!section.classList.contains('hidden')) {
        document.getElementById('manCustomerNumber').focus();
    }
}

// Smart Odia Transliteration
function transliterateToOdia(text) {
    const chattingMap = {
        'suji': 'ସୁଜି', 'sooji': 'ସୁଜି', 'chini': 'ଚିନି', 'chaula': 'ଚାଉଳ', 'tela': 'ତେଲ',
        'luna': 'ଲୁଣ', 'ata': 'ଅଟା', 'dali': 'ଡାଲି', 'khira': 'କ୍ଷୀର', 'alu': 'ଆଳୁ',
        'piaja': 'ପିଆଜ', 'rasuna': 'ରସୁଣ', 'ada': 'ଅଦା', 'haladi': 'ହଳଦୀ', 'jira': 'ଜିରା'
    };
    const charMapping = {
        'kh': 'ଖ', 'gh': 'ଘ', 'ch': 'ଚ', 'chh': 'ଛ', 'jh': 'ଝ', 'th': 'ଥ', 'dh': 'ଧ', 
        'ph': 'ଫ', 'bh': 'ଭ', 'sh': 'ଶ', 'ss': 'ଷ', 'a': 'ଅ', 'aa': 'ଆ', 'i': 'ଇ', 
        'u': 'ଉ', 'e': 'ଏ', 'o': 'ଓ', 'k': 'କ', 'g': 'ଗ', 'j': 'ଜ', 't': 'ଟ', 
        'd': 'ଡ', 'n': 'ନ', 'p': 'ପ', 'm': 'ମ', 'y': 'ଯ', 'r': 'ର', 'l': 'ଲ', 's': 'ସ', 'h': 'ହ'
    };
    let lowText = text.toLowerCase().trim();
    if (chattingMap[lowText]) return chattingMap[lowText];
    let result = lowText;
    Object.keys(charMapping).sort((a, b) => b.length - a.length).forEach(key => {
        result = result.replace(new RegExp(key, 'g'), charMapping[key]);
    });
    return result;
}

// Real-time Item Auto-suggestions
const manItemInput = document.getElementById('manItemName');
const itemSuggBox = document.getElementById('itemSuggestions');
manItemInput.addEventListener('input', async function() {
    const q = this.value.trim();
    if (q.length < 2) { itemSuggBox.classList.add('hidden'); return; }
    const res = await fetch(`ajax_product_search.php?q=${encodeURIComponent(q)}`); 
    const products = await res.json();

    if (products.length > 0) {
        itemSuggBox.innerHTML = products.map(p => `
            <div class="search-item" onclick="selectItemSugg('${escHtml(p.name)}', '${p.sale_price}', '${p.unit}', '${p.gst_percent}', '${p.id}', '${p.current_stock}')">
                <div class="font-bold text-slate-800 text-sm">${escHtml(p.name)}</div>
                <div class="text-[10px] text-emerald-600 font-bold">₹${p.sale_price} / ${p.unit}</div>
            </div>
        `).join('');
        itemSuggBox.style.display = 'block';
    } else { itemSuggBox.style.display = 'none'; }
});

manItemInput.addEventListener('keydown', function(e) {
    if (e.key === ' ' && document.getElementById('langSelect').value === 'or-IN') {
        let words = this.value.split(' ');
        let last = words[words.length - 1];
        if (last.length > 0) {
            words[words.length - 1] = transliterateToOdia(last);
            this.value = words.join(' ');
        }
    }
});

function selectItemSugg(name, rate, unit, gst_percent, productId) {
    const lang = document.getElementById('langSelect').value;
    manItemInput.value = (lang === 'or-IN') ? transliterateToOdia(name) : name;
    
    // Store data in input dataset for the addManualEntryRow function
    manItemInput.dataset.productId = productId;
    manItemInput.dataset.rate = rate;
    manItemInput.dataset.gstPercent = gst_percent;
    manItemInput.dataset.unit = unit;
    manItemInput.dataset.stock = p_stock; // p_stock comes from mapping below
}

// Modified selectItemSugg to include stock check
function selectItemSugg(name, rate, unit, gst_percent, productId, stock) {
    const lang = document.getElementById('langSelect').value;
    manItemInput.value = (lang === 'or-IN') ? transliterateToOdia(name) : name;
    
    manItemInput.dataset.productId = productId;
    manItemInput.dataset.rate = rate;
    manItemInput.dataset.gstPercent = gst_percent;
    manItemInput.dataset.unit = unit;
    manItemInput.dataset.stock = stock;

    document.getElementById('manItemUnit').value = unit;
    itemSuggBox.classList.add('hidden');
    document.getElementById('manItemQty').focus();
}

// Real-time Customer Auto-suggestions
const manCustInput = document.getElementById('manCustomerNumber');
const custSuggBox = document.getElementById('customerSuggestions');
manCustInput.addEventListener('input', async function() {
    // This section is removed as per new requirement for dropdown
});


function selectCustSugg(name) {
    document.getElementById('customerInfo').classList.remove('hidden');
    document.getElementById('custNameInput').value = name;
    customerNameCaptured = true;
    manCustInput.value = name;
    custSuggBox.classList.add('hidden');
    manItemInput.focus();
}

async function addManualEntryRow() {
    initAudio();
    const name = manItemInput.value.trim();
    const qtyInput = document.getElementById('manItemQty');
    const qty = parseFloat(qtyInput.value) || 1;
    const unitSelect = document.getElementById('manItemUnit');
    const unit = unitSelect.value;

    if(!name) return;

    let productId = manItemInput.dataset.productId || null;
    let rate = parseFloat(manItemInput.dataset.rate) || 0;
    let gst_percent = parseFloat(manItemInput.dataset.gstPercent) || 0;
    let finalUnit = manItemInput.dataset.unit || unit;
    let currentStock = parseFloat(manItemInput.dataset.stock) || 0;
    let found = !!productId;

    if (!found) {
        try {
            const res = await fetch(`ajax_product_search.php?q=${encodeURIComponent(name)}`);
            const products = await res.json();
            if (products.length > 0) {
                const p = products[0];
                productId = p.id;
                rate = p.sale_price;
                gst_percent = p.gst_percent;
                finalUnit = p.primary_unit;
                currentStock = parseFloat(p.current_stock);
                found = true;
            } else {
                // Nice toast for Not Found
                Swal.fire({
                    toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true,
                    icon: 'info', title: 'Item inventory mein nahi mila!', text: 'Guest item ki tarah add ho raha hai.',
                    background: '#f8fafc', color: '#1e293b'
                });
            }
        } catch (e) { console.error(e); }
    }

    // Stock check notification
    if (found && currentStock <= 0) {
        Swal.fire({
            toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true,
            icon: 'warning', title: 'Stock Khatam Ho Gaya Hai!', text: 'Lekin bill mein add kar rahe hain.',
            background: '#fffbeb', color: '#92400e'
        });
    }

    addRow({ name, unit: finalUnit, qty, rate, found, gst_percent, productId });
    
    manItemInput.value = '';
    delete manItemInput.dataset.productId;
    delete manItemInput.dataset.rate;
    delete manItemInput.dataset.gstPercent;
    delete manItemInput.dataset.unit;
    qtyInput.value = '1';
    manItemInput.focus();
}

// ─── Toggle listening ─────────────────────────────────────────────────────────
function toggleListening() {
    initAudio();
    if (!recognition) { alert('Voice recognition is not supported in this browser.'); return; }

    if (isListening) {
        isManuallyStopped = true;
        recognition.stop();
        return;
    }

    isManuallyStopped = false;
    setStatus('Starting…');

    const lang = document.getElementById('langSelect').value;
    recognition.lang = lang;

    if (!customerNameCaptured) {
        isWaitingForName = true;
        const greetings = {
            'hi-IN': 'नमस्ते, कृपया अपना नाम बताएं',
            'or-IN': 'ନମସ୍କାର, ଦୟାକରି ଆପଣଙ୍କ ନାମ କୁହନ୍ତୁ',
        };
        speak(greetings[lang] || 'Hello, please tell me your name.', () => {
            recognition.start();
            startVolumeAnalysis();
        });
    } else {
        recognition.start();
        startVolumeAnalysis();
    }
}

// ─── Name capture ─────────────────────────────────────────────────────────────
function handleNameCapture(text) {
    const fillers = ['mera naam','my name is','mora nama','mo naba','hai','is','achi','ati','achhi'];
    let name = text.trim().toLowerCase();
    fillers.forEach(f => {
        name = name.replace(new RegExp('\\b' + f + '\\b', 'gi'), '').trim();
    });
    
    // Try to match spoken name with dropdown options
    const customerSelect = document.getElementById('billCustomerId');
    let matchedCustomer = Array.from(customerSelect.options).find(option => option.textContent.toLowerCase().includes(name));

    if (matchedCustomer) {
        customerSelect.value = matchedCustomer.value; // Select the matched customer
        document.getElementById('customerInfo').classList.remove('hidden');
        document.getElementById('custNameInput').value = name.charAt(0).toUpperCase() + name.slice(1);
        customerNameCaptured = true;
        isWaitingForName     = false;

        const lang = document.getElementById('langSelect').value;
        const feedbacks = {
            'hi-IN': `धन्यवाद ${name}। अब अपना सामान बताएं।`,
            'or-IN': `ଧନ୍ୟବାଦ ${name}। ବର୍ତ୍ତମାନ ଆପଣ କଣ ନେବାକୁ ଚାହାଁନ୍ତି କୁହନ୍ତୁ।`,
        };
        speak(feedbacks[lang] || `Thank you ${name}. Please tell me what you need.`);
    } else {
        // If no match, keep isWaitingForName true and prompt again or ask to select manually
        speak("Customer not found. Please select from the list or try again.");
    }
}

// ─── Process speech for items ─────────────────────────────────────────────────
async function processSpeech(text) {
    setStatus('Processing: ' + text);

    const formData = new FormData();
    formData.append('speech_text', text);

    try {
        const res    = await fetch('ajax_voice_billing_handler.php', { method: 'POST', body: formData });
        if (!res.ok) throw new Error('Server error ' + res.status);
        const result = await res.json();
        // Add/remove items
        if (result.items && result.items.length > 0) {
            result.items.forEach(item => {
                if (item.action === 'remove') subtractOrRemoveItem(item);
                else addRow(item);
            });
        }
    } catch (err) {
        console.error('processSpeech error:', err);
        setStatus('Error processing. Please try again.');
        return;
    }

    if (isListening) {
        setStatus('Listening… speak now');
        document.getElementById('liveTranscript').textContent = '';
    }
}

// ─── Bill row helpers ─────────────────────────────────────────────────────────
function addRow(item) {
    playBeep(880, 100);
    const tbody = document.getElementById('billBody'); // Changed from 'billBody' to 'billTableBody'
    const row = document.createElement('tr');
    row.className = 'item-row' + (item.found === false ? ' unknown' : '');

    const itemDiscount = parseFloat(item.discount || 0);
    const itemGst = parseFloat(item.gst_percent || 0);
    const subtotal = ((item.qty || 0) * (item.rate || 0)) - ((item.qty || 0) * itemDiscount);
    const total = (subtotal * (1 + (itemGst / 100))).toFixed(2);

    // Use escHtml to prevent XSS from inventory data
    row.innerHTML = ` 
        <td class="px-2 sm:px-4 py-3">
            <input type="text" class="item-name-input" value="${escHtml(item.name)}"
                   title="${item.found === false ? 'Item not found in inventory' : ''}">
        </td>
        <td class="px-1 py-3">
            <input type="text" class="item-unit-input text-center text-[10px] font-black text-slate-500 uppercase" 
                   value="${escHtml(item.unit || 'NOS')}">
        </td>
        <td class="px-0.5 py-3">
            <input type="number" min="0" step="0.01" class="item-qty-input"
                   value="${parseFloat(item.qty || 1).toFixed(2)}" oninput="calculateTotal(this)">
        </td>
        <td class="px-0.5 py-3">
            <input type="number" min="0" step="0.01" class="item-rate-input"
                   value="${parseFloat(item.rate || 0).toFixed(2)}" oninput="calculateTotal(this)">
        </td>
        <td class="px-0.5 py-3">
            <input type="number" min="0" step="0.01" class="item-discount-input item-small-input"
                   value="${parseFloat(itemDiscount).toFixed(2)}" oninput="calculateTotal(this)">
        </td>
        <td class="px-0.5 py-3">
            <input type="number" min="0" step="0.01" class="item-gst-input item-small-input"
                   value="${parseFloat(itemGst).toFixed(2)}" oninput="calculateTotal(this)">
        </td>
        <td class="px-2 py-3 text-right font-bold row-total" data-item-id="${item.productId || ''}">₹${total}</td> 
        <td class="px-2 py-3 text-center">
            <button onclick="this.closest('tr').remove(); updateGrandTotal();"
                    class="text-rose-400 hover:text-rose-600 transition-colors text-xs" title="Remove row">
                <i class="fas fa-times-circle"></i>
            </button>
        </td>
    `;
    tbody.appendChild(row);
    updateGrandTotal();
}

function subtractOrRemoveItem(item) {
    document.querySelectorAll('.item-row').forEach(row => {
        const nameVal = row.querySelector('.item-name-input').value.toLowerCase();
        const itemName = item.name.toLowerCase();
        if (nameVal.includes(itemName) || itemName.includes(nameVal)) {
            playBeep(300, 150);
            const qtyInput  = row.querySelector('.item-qty-input');
            const currentQty = parseFloat(qtyInput.value) || 0;
            if (currentQty > item.qty) {
                qtyInput.value = (currentQty - item.qty).toFixed(2);
                calculateTotal(qtyInput);
            } else {
                row.remove();
                updateGrandTotal();
            }
        }
    });
}

function calculateTotal(input) {
    const row  = input.closest('tr');
    const qty  = parseFloat(row.querySelector('.item-qty-input').value)  || 0;
    const rate = parseFloat(row.querySelector('.item-rate-input').value) || 0;
    const discount = parseFloat(row.querySelector('.item-discount-input').value) || 0;
    const gst_percent = parseFloat(row.querySelector('.item-gst-input').value) || 0;

    const subtotal = (qty * rate) - (qty * discount);
    const netTotal = subtotal * (1 + (gst_percent / 100));
    row.querySelector('.row-total').textContent = '₹' + netTotal.toFixed(2);
    updateGrandTotal();
}

function updateGrandTotal() {
    let grand = 0;
    document.querySelectorAll('.row-total').forEach(cell => {
        grand += parseFloat(cell.textContent.replace('₹', '')) || 0;
    });
    // Only update grandTotalNet, the other ID doesn't exist in the footer
    document.getElementById('grandTotalNet').textContent = '₹' + grand.toFixed(2);
}

// ─── Reset ────────────────────────────────────────────────────────────────────
function resetPOS() {
    if (arguments[0] !== true && !confirm('Reset the current bill?')) return;
    // Stop mic first
    if (isListening) { isManuallyStopped = true; recognition.stop(); }
    location.reload();
}

// ─── Save Bill Modal ──────────────────────────────────────────────────────────
function openSaveBillModal(mode) {
    initAudio(); // Ensure audio context is resumed on user interaction
    const rows = document.querySelectorAll('.item-row');
    if (rows.length === 0) { alert('Please add at least one item.'); return; }

    currentBillMode = mode;
    const modal = document.getElementById('saveBillModal');
    const title = document.getElementById('saveBillModalTitle');
    const udharCustomerSelect = document.getElementById('udharCustomerSelect');
    const confirmBtn = document.getElementById('confirmSaveBillBtn');

    const grandTotal = parseFloat(document.getElementById('grandTotalNet').textContent.replace('₹', '')) || 0;
    document.getElementById('finalBillAmountDisplay').textContent = '₹' + grandTotal.toFixed(2);

    if (mode === 'udhar') {
        // Nice "Pop Pop" for Udhar
        Swal.fire({
            title: 'Transfer to Udhar?',
            text: "Kya aap is bill ko customer ke udhar khata mein dalna chahte hain?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Haan, Udhar Karo!',
            customClass: { popup: 'rounded-[2rem]' }
        }).then((result) => {
            if (!result.isConfirmed) {
                closeSaveBillModal();
            }
        });

        title.textContent = 'Transfer to Udhar';
        udharCustomerSelect.classList.remove('hidden');
        confirmBtn.textContent = 'Confirm Udhar';
        confirmBtn.classList.remove('bg-emerald-600');
        confirmBtn.classList.add('bg-red-600');
    } else { // cash
        title.textContent = 'Save Bill & Print';
        udharCustomerSelect.classList.add('hidden');
        confirmBtn.textContent = 'Confirm Cash';
        confirmBtn.classList.remove('bg-red-600');
        confirmBtn.classList.add('bg-emerald-600');
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeSaveBillModal() {
    document.getElementById('saveBillModal').classList.add('hidden');
    document.getElementById('saveBillModal').classList.remove('flex');
}

async function confirmSaveBill() {
    initAudio(); // Ensure audio context is resumed on user interaction
    const rows = document.querySelectorAll('.item-row');
    const billItems = [];
    rows.forEach(row => {
        billItems.push({
            product_id: row.querySelector('.row-total').dataset.itemId || null, // Pass product_id if known
            name:       row.querySelector('.item-name-input').value,
            unit:       row.querySelector('.item-unit-input').value,
            qty:        parseFloat(row.querySelector('.item-qty-input').value),
            rate:       parseFloat(row.querySelector('.item-rate-input').value),
            gst_percent: parseFloat(row.querySelector('.item-gst-input').value), // New: Item GST
            discount:   parseFloat(row.querySelector('.item-discount-input').value), // New: Item discount
        });
    });

    const customerId = (currentBillMode === 'udhar') ? document.getElementById('udharCustomerId').value : document.getElementById('billCustomerId').value; // Use billCustomerId for all modes
    const customerName = document.getElementById('billCustomerId').options[document.getElementById('billCustomerId').selectedIndex].text || 'Guest Customer';
    const grandTotalNet = parseFloat(document.getElementById('grandTotalNet').textContent.replace('₹', '')) || 0;

    if (currentBillMode === 'udhar' && !customerId) {
        alert('Please select a customer for Udhar transfer.');
        return;
    }

    // Disable button to prevent double submission
    const confirmBtn = document.getElementById('confirmSaveBillBtn');
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    try {
        const response = await fetch('ajax_save_pos_bill.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                customer_id: customerId || null, // Pass null if no customer selected (Guest Customer)
                customer_name: customerName,
                items: billItems,
                payment_status: (currentBillMode === 'udhar') ? 'transferred_to_udhar' : 'paid_cash',
                final_net_amount: grandTotalNet,
            })
        });
        const result = await response.json();

        if (result.success) {
            // Store bill data in session for PDF export
            sessionStorage.setItem('last_pos_bill_data', JSON.stringify(billItems));
            sessionStorage.setItem('last_pos_bill_cust', customerName);
            sessionStorage.setItem('last_pos_bill_id', result.bill_id); // Store the new bill ID

            // Fullscreen Professional Success Animation
            Swal.fire({
                title: 'Transaction Successful!',
                html: '<div class="text-slate-500 font-medium">Bill #' + result.bill_id + ' has been secured.<br>Launching digital invoice...</div>',
                icon: 'success',
                timer: 2000,
                showConfirmButton: false,
                customClass: { 
                    container: 'z-[3000]',
                    popup: 'rounded-[3rem] p-12 shadow-2xl border-none' 
                }
            }).then(() => {
                // Open PDF in new tab
                document.getElementById('billDataInput').value = JSON.stringify(billItems);
                document.getElementById('custNamePost').value = customerName;
                document.getElementById('billIdPost').value = result.bill_id; // Pass bill ID
                document.getElementById('printForm').submit();
                resetPOS(true); // Quiet reset after success
            });
            playBeep(880, 300); // Success sound
        } else {
            alert('Error saving bill: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An unexpected error occurred.');
    } finally {
        confirmBtn.disabled = false;
        confirmBtn.innerHTML = 'Confirm';
    }
}


// ─── Sidebar ──────────────────────────────────────────────────────────────────
function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }

// ─── Manual search ────────────────────────────────────────────────────────────
const searchInput = document.getElementById('manualSearch');
const searchDropdown = document.getElementById('searchDropdown');

searchInput.addEventListener('input', async (e) => {
    const q = e.target.value.trim();
    if (q.length < 2) { searchDropdown.style.display = 'none'; return; }

    try {
        const res = await fetch(`ajax_product_search.php?q=${encodeURIComponent(q)}`);
        if (!res.ok) throw new Error('Search failed');
        const products = await res.json();

        if (products.length > 0) {
            searchDropdown.innerHTML = products.map(p => `
                <div class="search-item" role="option"
                     onclick="addManualItem('${escHtml(p.name)}', ${parseFloat(p.sale_price)}, '${escHtml(p.unit)}', ${parseFloat(p.gst_percent || 0)}, ${p.id || 'null'}, ${p.current_stock})">
                    <span class="font-semibold text-slate-800">${escHtml(p.name)}</span>
                    <span class="text-emerald-600 text-sm font-bold">₹${parseFloat(p.sale_price).toFixed(2)}</span>
                </div>
            `).join('');
            searchDropdown.style.display = 'block';
        } else {
            searchDropdown.innerHTML = '<div class="px-5 py-3 text-sm text-slate-400">No items found</div>';
            searchDropdown.style.display = 'block';
        }
    } catch (err) {
        console.error('Search error:', err);
    }
});

function addManualItem(name, rate, unit, gst_percent, productId, stock) {
    initAudio();
    if (stock <= 0) {
        Swal.fire({
            toast: true, position: 'top-end', showConfirmButton: false, timer: 3000, timerProgressBar: true,
            icon: 'warning', title: 'Stock Khatam!', text: 'Item bill mein add ho gaya hai.',
            background: '#fff1f2', color: '#e11d48'
        });
    }
    addRow({ name, qty: 1, rate, unit, gst_percent, productId, found: true });
    searchInput.value = '';
    searchDropdown.style.display = 'none';
}

// Close dropdown on outside click
document.addEventListener('click', (e) => {
    if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target)) {
        searchDropdown.style.display = 'none';
    }
});

let posScanner = null;
function startScanner() {
    initAudio();
    document.getElementById('posScannerModal').classList.replace('hidden', 'flex');
    posScanner = new Html5Qrcode("posReader");
    posScanner.start({ facingMode: "environment" }, { fps: 15, qrbox: { width: 250, height: 150 } },
        async (code) => {
            stopScanner();
            try {
                const res = await fetch(`ajax_product_by_barcode.php?barcode=${encodeURIComponent(code)}`);
                const p = await res.json();
                if(p.id) {
                    addRow({ name: p.name, rate: p.sale_price, unit: p.primary_unit, gst_percent: p.gst_percent, productId: p.id, found: true });
                    playBeep(1000, 100);
                } else {
                    Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'Barcode not in inventory!', showConfirmButton: false, timer: 2000 });
                }
            } catch(e) { console.error(e); }
        },
        (err) => {}
    );
}

function stopScanner() {
    if(posScanner) {
        posScanner.stop().then(() => {
            document.getElementById('posScannerModal').classList.replace('flex', 'hidden');
        });
    }
}
</script>

<!-- Firebase Professional Notifications -->
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.22.1/firebase-messaging-compat.js"></script>
<script>
    const firebaseConfig = {
        apiKey: "AIzaSyCM8z5Y_lMephKKsP9U0AtdIisIyKkounE",
        authDomain: "khatalink-63041.firebaseapp.com",
        projectId: "khatalink-63041",
        messagingSenderId: "905429197043",
        appId: "1:905429197043:web:2a0cbefa0fa176fd2c5786"
    };
    if (!firebase.apps.length) firebase.initializeApp(firebaseConfig);
    const messaging = firebase.messaging();
    async function syncToken() {
        try {
            const registration = await navigator.serviceWorker.register('../firebase-messaging-sw.js');
            await navigator.serviceWorker.ready;
            await messaging.getToken({ 
                vapidKey: 'BGixP4kke2vi5l1mpqb_P-GI5xh2OM4KcPQ_8lzQmJqvdJXHG4xFpkYvexfpD_lX7LvBQ1ORR3asE1LQkFeWFHo',
                serviceWorkerRegistration: registration
            });
        } catch (e) { console.error("FCM Sync Error:", e); }
    }
    syncToken();
    messaging.onMessage((payload) => {
        const title = payload.notification?.title || 'POS Billing Update';
        const body = payload.notification?.body || 'Transaction recorded';
        const image = payload.notification?.image;
        if (Notification.permission === "granted") {
            const options = {
                body: body,
                icon: '../assets/favicon.png'
            };
            if (image) {
                options.image = image;
            }
            const n = new Notification(title, options);
            n.onclick = function() { window.focus(); this.close(); };
        }
    });
</script>

</body>
</html>