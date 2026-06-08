<?php
session_start();
require_once 'includes/db.php';

// ── FEATURE LOCK: Partner page under maintenance ────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Coming Soon — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen p-6">
    <div class="max-w-md w-full bg-white rounded-[3rem] p-10 text-center shadow-2xl border border-slate-100">
        <div class="w-20 h-20 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center text-3xl mx-auto mb-6">
            <i class="fas fa-handshake-angle"></i>
        </div>
        <h1 class="text-2xl font-black text-slate-900 uppercase tracking-tight mb-3">Coming Soon</h1>
        <p class="text-slate-500 text-sm leading-relaxed mb-8">
            The Partner Portal is currently being upgraded. We are building a more powerful tech stack to empower thousands of local entrepreneurs.
        </p>
        <a href="index.php" class="inline-block bg-slate-900 text-white px-8 py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-slate-800 transition-all shadow-lg">
            Back to Home
        </a>
    </div>
</body>
</html>
<?php exit(); ?>
<?php
// Fetch banner for partner page from DB (Admin handles this via 'target')
$stmt_banner = $pdo->prepare("SELECT image_path FROM banners WHERE target IN ('partner', 'all') ORDER BY created_at DESC LIMIT 1");
$stmt_banner->execute();
$db_banner = $stmt_banner->fetchColumn();
$hero_banner = $db_banner ?: 'https://images.unsplash.com/photo-1582213726892-25b82855b45a?q=80&w=2070&auto=format&fit=crop';

// Fetch banner for opportunities section
$stmt_opportunities_banner = $pdo->prepare("SELECT image_path FROM banners WHERE target IN ('partner_opportunities', 'all') ORDER BY created_at DESC LIMIT 1");
$stmt_opportunities_banner->execute();
$opportunities_banner = $stmt_opportunities_banner->fetchColumn(); // No default fallback, will be hidden if not set

// Fetch banners for individual opportunity cards
$card_banners = [];
$stmt_cards = $pdo->prepare("SELECT target, image_path FROM banners WHERE target IN ('partner_card_store', 'partner_card_rent', 'partner_card_seller', 'partner_card_deliver') ORDER BY created_at DESC");
$stmt_cards->execute();
while($row = $stmt_cards->fetch()) {
    if(!isset($card_banners[$row['target']])) $card_banners[$row['target']] = $row['image_path'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner with Us — KhataLink</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; scroll-behavior: smooth; }
        .text-gradient { background: linear-gradient(135deg, #059669 0%, #10b981 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .opportunity-card:hover { transform: translateY(-8px); border-color: #10b981; }
        
        /* 100% Match Custom Blinkit-Style Gradients and Layouts */
        .blinkit-hero-bg {
            background: linear-gradient(180deg, #FAD95F 0%, #F5C443 35%, #7CB438 75%, #278235 100%);
        }
        .blinkit-card-bg {
            background: linear-gradient(180deg, #FAD759 0%, #E3BC36 25%, #76AF35 75%, #247F33 100%);
        }
        
        /* Smooth color transition on scrolling layout */
        .nav-transition {
            transition: background-color 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }
    </style>
</head>
<body class="bg-white text-slate-900">

<nav id="blinkitNav" class="absolute top-0 left-0 right-0 z-[1000] bg-transparent border-b border-transparent nav-transition">
    <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
        <a href="index.php" class="flex items-center gap-2">
            <img src="https://i.ibb.co/Xxy2GWtG/Chat-GPT-Image-May-22-2026-12-18-12-AM.png" alt="KhataLink" class="h-10">
            <span class="font-extrabold text-xl tracking-tighter uppercase text-slate-900">Partner</span>
        </a>
        
        <div class="flex items-center gap-8 font-medium text-[15px] text-slate-900">
            <a href="index.php" class="hover:text-amber-900 transition-all hidden sm:inline-block">Home</a>
            <a href="#" class="hover:text-amber-900 transition-all hidden sm:inline-block">About</a>
            <a href="#" class="hover:text-amber-900 transition-all hidden sm:inline-block">Careers</a>
            <a href="#opportunities" class="hover:text-amber-900 transition-all hidden sm:inline-block">Partner</a>
            <a href="#" class="hover:text-amber-900 transition-all hidden sm:inline-block">Blog</a>
            
            <button class="text-slate-900 text-2xl focus:outline-none pl-2 flex items-center">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>
    </div>
</nav>

<header class="relative min-h-[500px] flex flex-col items-center justify-between overflow-hidden text-center bg-cover bg-center bg-no-repeat <?= !$db_banner ? 'blinkit-hero-bg' : '' ?>" style="<?php if($db_banner): ?> background-image: url('<?= htmlspecialchars($hero_banner) ?>'); <?php else: ?> background-image: linear-gradient(180deg, rgba(250,217,95,0.85) 0%, rgba(39,130,53,0.85) 100%), url('<?= htmlspecialchars($hero_banner) ?>'); <?php endif; ?>">
    
    <div class="absolute inset-0 z-0 pointer-events-none">
        <?php if($hero_banner): ?>
            <img src="<?= htmlspecialchars($hero_banner) ?>" class="w-full h-full object-cover <?= !$db_banner ? 'opacity-30 mix-blend-overlay' : '' ?>" alt="Partner Banner">
        <?php endif; ?>
    </div>

    <div class="w-full max-w-6xl mx-auto px-6 pt-32 relative z-10 flex flex-col items-center">
        <div class="w-full max-w-4xl mt-4 mb-2 flex justify-center">
            <div class="h-44 w-1"></div>
        </div>
    </div>

    <div class="w-full bg-transparent pb-16 pt-2 px-4 relative z-20">
        <h1 class="text-3xl md:text-5xl lg:text-6xl font-extrabold text-white tracking-tight drop-shadow-sm">
            
        </h1>
    </div>
</header>

<section class="py-16 bg-white">
    <div class="max-w-5xl mx-auto px-6">
        <h2 class="text-2xl md:text-3xl font-bold text-slate-900 mb-6">Come build with us</h2>
        <p class="text-slate-700 text-[15px] md:text-[16px] leading-relaxed font-normal max-w-5xl">
            We believe that our tech stack and operational backbone can empower thousands of local entrepreneurs to serve the needs of millions of Indians. Our vision of a marketplace where anyone can open their storefront on KhataLink, will enable us to deliver anything from groceries, to medicines, to beauty and health care products or even electronic items at your doorstep. For this we are looking for passionate entrepreneurs who want an opportunity to join the instant-commerce revolution in India. If this is exciting partner with us!
        </p>
    </div>
</section>

<section id="opportunities" class="pb-24 bg-white">
    <div class="max-w-5xl mx-auto px-6">
        <div class="mb-8">
            <h2 class="text-2xl md:text-3xl font-bold text-slate-900 tracking-tight">Opportunities to grow with KhataLink</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <?php 
                $b_store = $card_banners['partner_card_store'] ?? null;
                $s_store = $b_store ? "background-image: linear-gradient(180deg, rgba(0,0,0,0.3) 0%, rgba(0,0,0,0.7) 100%), url('".htmlspecialchars($b_store)."'); background-size: cover; background-position: center;" : "";
            ?>
            <div class="<?= $b_store ? '' : 'blinkit-card-bg' ?> p-8 rounded-2xl shadow-sm flex flex-col justify-between min-h-[260px] text-white relative overflow-hidden group" style="<?= $s_store ?>">
                <div class="max-w-[65%] z-10">
                    <h3 class="text-2xl font-bold text-white mb-3">Partner store</h3>
                    <p class="text-[14px] font-medium text-white/90 leading-snug mb-8">Run partner stores for KhataLink</p>
                </div>
                <div class="absolute right-4 bottom-6 w-[35%] flex justify-end items-end z-0">
                    <i class="fas fa-store text-7xl text-white/20 group-hover:scale-105 transition-transform duration-300"></i>
                </div>
                <div class="z-10">
                    <a href="shop/register.php" class="inline-block text-white font-bold text-[14px] underline underline-offset-4 tracking-wide hover:text-slate-100 transition-all">know more</a>
                </div>
            </div>

            <?php 
                $b_rent = $card_banners['partner_card_rent'] ?? null;
                $s_rent = $b_rent ? "background-image: linear-gradient(180deg, rgba(0,0,0,0.3) 0%, rgba(0,0,0,0.7) 100%), url('".htmlspecialchars($b_rent)."'); background-size: cover; background-position: center;" : "";
            ?>
            <div class="<?= $b_rent ? '' : 'blinkit-card-bg' ?> p-8 rounded-2xl shadow-sm flex flex-col justify-between min-h-[260px] text-white relative overflow-hidden group" style="<?= $s_rent ?>">
                <div class="max-w-[65%] z-10">
                    <h3 class="text-2xl font-bold text-white mb-3">Rent your property</h3>
                    <p class="text-[14px] font-medium text-white/90 leading-snug mb-8">Your property can become our next store!</p>
                </div>
                <div class="absolute right-4 bottom-6 w-[35%] flex justify-end items-end z-0">
                    <i class="fas fa-building text-7xl text-white/20 group-hover:scale-105 transition-transform duration-300"></i>
                </div>
                <div class="z-10">
                    <a href="guide.php?topic=rent" class="inline-block text-white font-bold text-[14px] underline underline-offset-4 tracking-wide hover:text-slate-100 transition-all">know more</a>
                </div>
            </div>

            <?php 
                $b_seller = $card_banners['partner_card_seller'] ?? null;
                $s_seller = $b_seller ? "background-image: linear-gradient(180deg, rgba(0,0,0,0.3) 0%, rgba(0,0,0,0.7) 100%), url('".htmlspecialchars($b_seller)."'); background-size: cover; background-position: center;" : "";
            ?>
            <div class="<?= $b_seller ? '' : 'blinkit-card-bg' ?> p-8 rounded-2xl shadow-sm flex flex-col justify-between min-h-[260px] text-white relative overflow-hidden group" style="<?= $s_seller ?>">
                <div class="max-w-[65%] z-10">
                    <h3 class="text-2xl font-bold text-white mb-3">Seller</h3>
                    <p class="text-[14px] font-medium text-white/90 leading-snug mb-8">List your products on KhataLink and reach your customers</p>
                </div>
                <div class="absolute right-4 bottom-6 w-[35%] flex justify-end items-end z-0">
                    <i class="fas fa-boxes-packing text-7xl text-white/20 group-hover:scale-105 transition-transform duration-300"></i>
                </div>
                <div class="z-10">
                    <a href="shop/inventory.php" class="inline-block text-white font-bold text-[14px] underline underline-offset-4 tracking-wide hover:text-slate-100 transition-all">know more</a>
                </div>
            </div>

            <?php 
                $b_deliver = $card_banners['partner_card_deliver'] ?? null;
                $s_deliver = $b_deliver ? "background-image: linear-gradient(180deg, rgba(0,0,0,0.3) 0%, rgba(0,0,0,0.7) 100%), url('".htmlspecialchars($b_deliver)."'); background-size: cover; background-position: center;" : "";
            ?>
            <div class="<?= $b_deliver ? '' : 'blinkit-card-bg' ?> p-8 rounded-2xl shadow-sm flex flex-col justify-between min-h-[260px] text-white relative overflow-hidden group" style="<?= $s_deliver ?>">
                <div class="max-w-[65%] z-10">
                    <h3 class="text-2xl font-bold text-white mb-3">Deliver</h3>
                    <p class="text-[14px] font-medium text-white/90 leading-snug mb-8">Deliver items from a KhataLink partner store to customers</p>
                </div>
                <div class="absolute right-4 bottom-6 w-[35%] flex justify-end items-end z-0">
                    <i class="fas fa-motorcycle text-7xl text-white/20 group-hover:scale-105 transition-transform duration-300"></i>
                </div>
                <div class="z-10">
                    <a href="delivery/register.php" class="inline-block text-white font-bold text-[14px] underline underline-offset-4 tracking-wide hover:text-slate-100 transition-all">know more</a>
                </div>
            </div>

        </div>
    </div>
</section>

<section class="pt-12 pb-6 bg-white">
    <div class="max-w-5xl mx-auto px-6">
        <h2 class="text-2xl md:text-3xl font-bold text-slate-900 tracking-tight">Partner testimonials</h2>
    </div>
</section>

<section class="pb-16 bg-white">
    <div class="max-w-5xl mx-auto px-6 space-y-10">
        <div>
            <h4 class="text-base font-bold text-slate-900 mb-3">#1 instant delivery service in India</h4>
            <p class="text-slate-600 text-[14px] leading-relaxed">
                Shop on the go and get anything delivered at your doorstep. Buy everything from groceries to fresh fruits & vegetables, cakes and bakery items, to meats & seafood, cosmetics, mobiles & accessories, electronics, baby care products and much more. We get it delivered at your doorstep in the fastest and the safest way possible.
            </p>
        </div>
        
        <div>
            <h4 class="text-base font-bold text-slate-900 mb-3">single app for all your daily needs</h4>
            <p class="text-slate-600 text-[14px] leading-relaxed">
                Order thousands of products at just a tap - milk, eggs, bread, cooking oil, ghee, atta, rice, fresh fruits & vegetables, spices, chocolates, chips, biscuits, noodles, cold drinks, shampoos, soaps, body wash, pet food, diapers, electronics, other organic and gourmet products from your neighbourhood stores and a lot more.
            </p>
        </div>
    </div>
</section>

<section class="py-12 bg-slate-50 overflow-hidden">
    <div class="max-w-7xl mx-auto px-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="bg-white p-10 rounded-[3rem] relative shadow-sm">
                <i class="fas fa-quote-left absolute top-8 right-10 text-slate-100 text-6xl"></i>
                <p class="text-slate-600 font-bold leading-relaxed mb-8 relative z-10 italic">"Being a partner store owner has completely changed my business. The tech KhataLink provides makes inventory management a breeze."</p>
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-emerald-200 rounded-2xl flex items-center justify-center font-black text-emerald-700">R</div>
                    <div>
                        <div class="font-black text-slate-900">Rajesh Kumar</div>
                        <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Store Partner, Patna</div>
                    </div>
                </div>
            </div>

            <div class="bg-slate-900 p-10 rounded-[3rem] text-white relative shadow-2xl shadow-emerald-900/20">
                <i class="fas fa-quote-left absolute top-8 right-10 text-white/5 text-6xl"></i>
                <p class="text-slate-300 font-bold leading-relaxed mb-8 relative z-10 italic">"I rented out my small basement for a dark store. Now I get regular monthly income without any hassle. Great platform!"</p>
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-blue-400 rounded-2xl flex items-center justify-center font-black text-slate-900">A</div>
                    <div>
                        <div class="font-black">Anjali Sharma</div>
                        <div class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Property Partner, Delhi</div>
                    </div>
                </div>
            </div>

            <div class="bg-white p-10 rounded-[3rem] relative shadow-sm">
                <i class="fas fa-quote-left absolute top-8 right-10 text-slate-100 text-6xl"></i>
                <p class="text-slate-600 font-bold leading-relaxed mb-8 relative z-10 italic">"I joined as a delivery partner part-time. The payouts are transparent and the app is very easy to use for navigation."</p>
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-amber-200 rounded-2xl flex items-center justify-center font-black text-amber-700">V</div>
                    <div>
                        <div class="font-black text-slate-900">Vikram Singh</div>
                        <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Delivery Partner, Mumbai</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="py-20 bg-emerald-600 text-white text-center relative overflow-hidden">
    <div class="absolute inset-0 opacity-10 pointer-events-none">
        <i class="fas fa-store text-[300px] absolute -left-20 -bottom-20 rotate-12"></i>
        <i class="fas fa-motorcycle text-[300px] absolute -right-20 -top-20 -rotate-12"></i>
    </div>
    <div class="max-w-3xl mx-auto px-6 relative z-10">
        <h2 class="text-3xl md:text-5xl font-black mb-8">Ready to grow your income?</h2>
        <p class="text-emerald-100 text-lg mb-10 font-medium">Join thousands of entrepreneurs who are already part of the KhataLink network. Let's build the future of Indian commerce together.</p>
        <div class="flex flex-col sm:flex-row justify-center gap-4">
            <a href="shop/register.php" class="bg-slate-900 text-white px-12 py-5 rounded-2xl font-black uppercase text-xs tracking-widest shadow-2xl hover:scale-105 transition-all">Register Shop</a>
            <a href="delivery/register.php" class="bg-white text-emerald-700 px-12 py-5 rounded-2xl font-black uppercase text-xs tracking-widest shadow-2xl hover:scale-105 transition-all">Become a Rider</a>
        </div>
    </div>
</section>

<footer class="bg-slate-900 text-white py-12 border-t border-white/5">
    <div class="max-w-7xl mx-auto px-6 flex flex-col md:flex-row justify-between items-center gap-8">
        <div class="flex items-center gap-3">
            <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" alt="KhataLink" class="h-8 brightness-0 invert">
            <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">© <?= date('Y') ?> KhataLink Logistics</span>
        </div>
        <nav class="flex gap-8">
            <a href="#" class="text-[10px] font-black uppercase text-slate-400 hover:text-white transition-all">Privacy</a>
            <a href="#" class="text-[10px] font-black uppercase text-slate-400 hover:text-white transition-all">Terms</a>
            <a href="guide.php" class="text-[10px] font-black uppercase text-slate-400 hover:text-white transition-all">Help Center</a>
        </nav>
        <div class="flex gap-4">
            <a href="#" class="w-10 h-10 rounded-full bg-white/5 flex items-center justify-center text-slate-400 hover:bg-emerald-600 hover:text-white transition-all"><i class="fab fa-instagram"></i></a>
            <a href="#" class="w-10 h-10 rounded-full bg-white/5 flex items-center justify-center text-slate-400 hover:bg-emerald-600 hover:text-white transition-all"><i class="fab fa-linkedin-in"></i></a>
        </div>
    </div>
</footer>

<script>
    // Handles seamless transition and distinct border separation when scroll trigger happens
    window.addEventListener('scroll', function() {
        const nav = document.getElementById('blinkitNav');
        if (window.scrollY > 40) {
            // Scroll hone par background sticky behavior aur custom border separation details apply honge
            nav.classList.remove('absolute', 'bg-transparent', 'border-transparent');
            nav.classList.add('fixed', 'bg-[#FAD95F]', 'shadow-md', 'border-slate-200/70');
        } else {
            // Unscrolled top position par 100% flat uniform single continuous canvas blend hold rahega
            nav.classList.remove('fixed', 'bg-[#FAD95F]', 'shadow-md', 'border-slate-200/70');
            nav.classList.add('absolute', 'bg-transparent', 'border-transparent');
        }
    });
</script>

</body>
</html>