<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['shop_id'])) {
    header("Location: ../auth/login.php?type=restaurant");
    exit();
}

$shop_id = $_SESSION['shop_id'];
$success = '';
$error = '';

// ─── HANDLE FORM SUBMISSION (ADD / EDIT) ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_item'])) {
    $item_id      = (int)($_POST['item_id'] ?? 0);
    $item_name    = trim($_POST['item_name']);
    $category     = trim($_POST['category']);
    $price        = (float)$_POST['price'];
    $description  = trim($_POST['description']);
    $ingredients  = trim($_POST['ingredients']);
    $weight_packet = trim($_POST['weight_packet']);
    $is_veg       = isset($_POST['is_veg']) ? 1 : 0;
    $is_available = isset($_POST['is_available']) ? 1 : 0;
    $custom_opts  = $_POST['custom_opts'] ?? null; // JSON format expected

    if (empty($item_name) || $price <= 0) {
        $error = "Item name and valid price are required.";
    } else {
        // Handle Image Uploads (Max 5)
        $uploaded_images = [];
        if ($item_id > 0) {
            // If editing, keep old images unless replaced or handle append
            $stmt_old = $pdo->prepare("SELECT image_paths FROM restaurant_menu_items WHERE id = ? AND shop_id = ?");
            $stmt_old->execute([$item_id, $shop_id]);
            $old_paths = $stmt_old->fetchColumn();
            if ($old_paths) $uploaded_images = json_decode($old_paths, true);
        }

        if (!empty($_FILES['menu_images']['name'][0])) {
            $upload_dir = '../assets/img/menu/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            // Limit to 5 images total
            $files = $_FILES['menu_images'];
            foreach ($files['tmp_name'] as $key => $tmp_name) {
                if (count($uploaded_images) >= 5) break;
                if ($files['error'][$key] === 0) {
                    $ext = pathinfo($files['name'][$key], PATHINFO_EXTENSION);
                    $filename = uniqid('dish_') . '_' . $key . '.' . $ext;
                    if (move_uploaded_file($tmp_name, $upload_dir . $filename)) {
                        $uploaded_images[] = 'assets/img/menu/' . $filename;
                    }
                }
            }
        }
        $image_json = json_encode($uploaded_images);

        if ($item_id > 0) {
            // Update
            $stmt = $pdo->prepare("UPDATE restaurant_menu_items SET item_name=?, category=?, price=?, description=?, ingredients=?, weight_packet=?, is_veg=?, is_available=?, image_paths=?, customizable_options=? WHERE id=? AND shop_id=?");
            $stmt->execute([$item_name, $category, $price, $description, $ingredients, $weight_packet, $is_veg, $is_available, $image_json, $custom_opts, $item_id, $shop_id]);
            $success = "Dish updated successfully!";
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO restaurant_menu_items (shop_id, item_name, category, price, description, ingredients, weight_packet, is_veg, is_available, image_paths, customizable_options) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$shop_id, $item_name, $category, $price, $description, $ingredients, $weight_packet, $is_veg, $is_available, $image_json, $custom_opts]);
            $success = "New dish added to menu!";
        }
    }
}

// ─── HANDLE DELETE ───
if (isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    $stmt = $pdo->prepare("DELETE FROM restaurant_menu_items WHERE id = ? AND shop_id = ?");
    $stmt->execute([$del_id, $shop_id]);
    header("Location: restaurant_menu.php?msg=Deleted");
    exit();
}

// Fetch Menu
$stmt = $pdo->prepare("SELECT * FROM restaurant_menu_items WHERE shop_id = ? ORDER BY category ASC, item_name ASC");
$stmt->execute([$shop_id]);
$menu_items = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Management — KhataLink Kitchen</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .dish-card { border-radius: 1.5rem; transition: all 0.3s ease; }
        .dish-card:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }

        /* --- Smooth Modal Animations --- */
        @keyframes modalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes modalSlideUp {
            from { transform: translateY(40px) scale(0.95); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }
        .animate-modal-backdrop { animation: modalFadeIn 0.3s ease-out forwards; }
        .animate-modal-content { animation: modalSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        
        /* Exit Animations */
        .modal-exit-active { opacity: 0; transition: opacity 0.3s ease-in; }
        .modal-exit-content { transform: translateY(40px); opacity: 0; transition: all 0.3s ease-in; }
    </style>
</head>
<body class="text-slate-900">

<nav class="sticky top-0 z-50 bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between shadow-sm">
    <div class="flex items-center gap-3">
        <a href="restaurant_dashboard.php" class="w-10 h-10 bg-slate-100 text-slate-500 rounded-xl flex items-center justify-center hover:bg-slate-200 transition-all">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h1 class="text-lg font-black uppercase tracking-tight">Menu Management</h1>
    </div>
    <button onclick="openModal()" class="bg-emerald-600 text-white px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-emerald-100 hover:bg-emerald-700 transition-all">
        <i class="fas fa-plus mr-1"></i> Add Dish
    </button>
</nav>

<main class="p-4 md:p-8 max-w-6xl mx-auto">
    
    <?php if($success): ?>
        <div class="bg-emerald-50 border border-emerald-100 text-emerald-600 p-4 rounded-2xl mb-6 text-sm font-bold flex items-center gap-3 animate-pulse">
            <i class="fas fa-check-circle"></i> <?= $success ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php if(empty($menu_items)): ?>
            <div class="col-span-full py-20 text-center bg-white border-2 border-dashed border-slate-200 rounded-[3rem]">
                <div class="w-20 h-20 bg-slate-50 text-slate-300 rounded-full flex items-center justify-center text-4xl mx-auto mb-4">
                    <i class="fas fa-utensils"></i>
                </div>
                <p class="text-slate-400 font-bold text-xs uppercase tracking-widest">Aapka menu khali hai. Dishes add karein!</p>
            </div>
        <?php endif; ?>

        <?php foreach($menu_items as $item): 
            $imgs = json_decode($item['image_paths'], true) ?: [];
            $main_img = !empty($imgs) ? '../' . $imgs[0] : 'https://placehold.co/400x400?text=No+Image';
        ?>
        <div class="bg-white dish-card border border-slate-100 overflow-hidden flex flex-col">
            <div class="relative h-48 bg-slate-100">
                <img src="<?= $main_img ?>" class="w-full h-full object-cover">
                <div class="absolute top-4 left-4">
                    <span class="px-2 py-1 rounded-md text-[8px] font-black uppercase border <?= $item['is_veg'] ? 'bg-emerald-50 text-emerald-600 border-emerald-200' : 'bg-red-50 text-red-600 border-red-200' ?>">
                        <?= $item['is_veg'] ? '● VEG' : '● NON-VEG' ?>
                    </span>
                </div>
                <?php if(!$item['is_available']): ?>
                <div class="absolute inset-0 bg-white/60 backdrop-blur-[2px] flex items-center justify-center">
                    <span class="bg-slate-900 text-white px-4 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest">Sold Out</span>
                </div>
                <?php endif; ?>
            </div>

            <div class="p-6 flex-1 flex flex-col">
                <div class="flex justify-between items-start mb-2">
                    <h3 class="font-black text-slate-900 text-lg leading-tight"><?= htmlspecialchars($item['item_name']) ?></h3>
                    <span class="font-black text-emerald-600">₹<?= number_format($item['price'], 2) ?></span>
                </div>
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-3"><?= htmlspecialchars($item['category']) ?></p>
                <p class="text-xs text-slate-500 line-clamp-2 mb-4"><?= htmlspecialchars($item['description']) ?></p>
                
                <div class="flex items-center gap-2 mb-6">
                    <span class="text-[9px] bg-slate-100 px-2 py-1 rounded font-bold text-slate-500 italic"><?= htmlspecialchars($item['weight_packet']) ?></span>
                    <span class="text-[9px] text-slate-300 font-bold"><?= count($imgs) ?> Images</span>
                </div>

                <div class="mt-auto pt-4 border-t border-slate-50 flex gap-2">
                    <button onclick='editItem(<?= json_encode($item) ?>)' class="flex-1 bg-slate-900 text-white py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest">Edit</button>
                    <a href="?delete_id=<?= $item['id'] ?>" onclick="return confirm('Dish delete karein?')" class="w-10 h-10 bg-red-50 text-red-500 rounded-xl flex items-center justify-center hover:bg-red-500 hover:text-white transition-all">
                        <i class="fas fa-trash-alt text-xs"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</main>

<!-- ADD/EDIT MODAL -->
<div id="menuModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md">
    <div id="modalContent" class="bg-white w-full max-w-2xl rounded-[2.5rem] p-8 md:p-10 shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-8">
            <h2 id="modalTitle" class="text-2xl font-black text-slate-900 tracking-tight uppercase">Add New Dish</h2>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600"><i class="fas fa-times text-xl"></i></button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="item_id" id="form_item_id" value="0">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Dish Name</label>
                    <input type="text" name="item_name" id="form_name" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:border-emerald-500 outline-none transition-all" placeholder="e.g. Paneer Butter Masala" required>
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Price (₹)</label>
                    <input type="number" step="0.01" name="price" id="form_price" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-black text-emerald-600 focus:border-emerald-500 outline-none transition-all" placeholder="0.00" required>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Category</label>
                    <select name="category" id="form_category" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold outline-none cursor-pointer">
                        <option value="Starters">Starters</option>
                        <option value="Main Course">Main Course</option>
                        <option value="Biryani">Biryani</option>
                        <option value="Tandoor">Tandoor & Breads</option>
                        <option value="Chinese">Chinese</option>
                        <option value="Desserts">Desserts</option>
                        <option value="Beverages">Beverages</option>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Weight / Portions</label>
                    <input type="text" name="weight_packet" id="form_weight" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-bold focus:border-emerald-500 outline-none transition-all" placeholder="e.g. 500g, 1 Plate, 2 Pcs">
                </div>
            </div>

            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Description</label>
                <textarea name="description" id="form_desc" rows="2" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-medium focus:border-emerald-500 outline-none transition-all" placeholder="Taste notes, spice level etc."></textarea>
            </div>

            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Ingredients (Optional)</label>
                <input type="text" name="ingredients" id="form_ingredients" class="w-full bg-slate-50 border-2 border-slate-100 rounded-2xl px-5 py-3 text-sm font-medium focus:border-emerald-500 outline-none transition-all" placeholder="e.g. Paneer, Cream, Cashew paste">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 py-4 border-y border-slate-100">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Dietary Preference</div>
                        <div class="text-xs font-bold mt-1" id="vegLabel">Pure Vegetarian?</div>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="is_veg" value="1" id="form_is_veg" class="sr-only peer" checked onchange="updateVegLabel(this)">
                        <div class="w-11 h-6 bg-red-500 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                    </label>
                </div>
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Kitchen Status</div>
                        <div class="text-xs font-bold mt-1">Available for order?</div>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="is_available" value="1" id="form_is_available" class="sr-only peer" checked>
                        <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                    </label>
                </div>
            </div>

            <!-- Customization Section -->
            <div class="p-6 bg-slate-50 border border-slate-100 rounded-3xl">
                <div class="flex justify-between items-center mb-4">
                    <h4 class="text-[10px] font-black uppercase tracking-widest text-slate-400">Customizations (Add-ons)</h4>
                    <button type="button" onclick="addOptionGroup()" class="text-blue-600 text-[10px] font-black uppercase">+ Add Group</button>
                </div>
                <div id="optionGroupsContainer" class="space-y-4">
                    <!-- Groups injected by JS -->
                </div>
                <input type="hidden" name="custom_opts" id="custom_opts_json">
                <p class="text-[9px] text-slate-400 mt-3 italic">* Spicy levels, Extra Cheese, Portion sizes yahan manage karein.</p>
            </div>

            <div>
                <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-2">Dish Images (Max 5)</label>
                <div class="flex items-center justify-center w-full">
                    <label class="flex flex-col items-center justify-center w-full h-32 border-2 border-slate-200 border-dashed rounded-3xl cursor-pointer bg-slate-50 hover:bg-slate-100 transition-all">
                        <div class="flex flex-col items-center justify-center pt-5 pb-6">
                            <i class="fas fa-cloud-upload-alt text-2xl text-slate-400 mb-2"></i>
                            <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">Click to upload images</p>
                        </div>
                        <input type="file" name="menu_images[]" multiple class="hidden" accept="image/*">
                    </label>
                </div>
            </div>

            <button type="submit" name="save_item" class="w-full bg-emerald-600 text-white py-5 rounded-2xl font-black uppercase tracking-widest text-xs shadow-xl shadow-emerald-100 hover:bg-emerald-700 transition-all">
                Save Menu Item
            </button>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('menuModal');
    const modalContent = document.getElementById('modalContent');
    const form  = modal.querySelector('form');

    function openModal() {
        document.getElementById('modalTitle').innerText = "Add New Dish";
        document.getElementById('form_item_id').value = "0";
        form.reset();
        document.getElementById('optionGroupsContainer').innerHTML = '';
        updateVegLabel(document.getElementById('form_is_veg'));
        
        // Show and Animate
        modal.classList.remove('hidden', 'modal-exit-active');
        modal.classList.add('flex', 'animate-modal-backdrop');
        modalContent.classList.remove('modal-exit-content');
        modalContent.classList.add('animate-modal-content');
    }

    function updateVegLabel(chk) {
        const label = document.getElementById('vegLabel');
        label.innerText = chk.checked ? "Pure Vegetarian?" : "Non-Vegetarian Item";
        label.classList.toggle('text-emerald-600', chk.checked);
        label.classList.toggle('text-red-600', !chk.checked);
    }

    function closeModal() {
        // Trigger Exit Animations
        modal.classList.add('modal-exit-active');
        modalContent.classList.add('modal-exit-content');
        
        setTimeout(() => {
            modal.classList.add('hidden');
            modal.classList.remove('flex', 'animate-modal-backdrop', 'modal-exit-active');
            modalContent.classList.remove('animate-modal-content', 'modal-exit-content');
        }, 300);
    }

    function editItem(item) {
        document.getElementById('modalTitle').innerText = "Edit Dish Details";
        document.getElementById('form_item_id').value = item.id;
        document.getElementById('form_name').value = item.item_name;
        document.getElementById('form_price').value = item.price;
        document.getElementById('form_category').value = item.category;
        document.getElementById('form_weight').value = item.weight_packet;
        document.getElementById('form_desc').value = item.description;
        document.getElementById('form_ingredients').value = item.ingredients;
        document.getElementById('form_is_veg').checked = (parseInt(item.is_veg) === 1);
        document.getElementById('form_is_available').checked = (parseInt(item.is_available) === 1);
        updateVegLabel(document.getElementById('form_is_veg'));

        // Handle Custom Options Load
        const container = document.getElementById('optionGroupsContainer');
        container.innerHTML = '';
        if(item.customizable_options) {
            try {
                const groups = JSON.parse(item.customizable_options);
                if(Array.isArray(groups)) groups.forEach(g => addOptionGroup(g));
            } catch(e) { console.error("Parse Error", e); }
        }

        modal.classList.remove('hidden', 'modal-exit-active');
        modal.classList.add('flex', 'animate-modal-backdrop');
        modalContent.classList.remove('modal-exit-content');
        modalContent.classList.add('animate-modal-content');
    }

    function addOptionGroup(data = null) {
        const id = Date.now() + Math.floor(Math.random() * 1000);
        const html = `
            <div class="bg-white p-4 rounded-2xl border border-slate-200 group-box mb-4 shadow-sm" id="group-${id}">
                <div class="flex gap-2 mb-3">
                    <input type="text" placeholder="Group Name (e.g. Extra Toppings)" class="g-name flex-1 bg-slate-50 border-none rounded-lg p-2 text-xs font-bold" value="${data ? data.name : ''}">
                    <select class="g-type bg-slate-50 border-none rounded-lg p-2 text-[10px] font-black cursor-pointer">
                        <option value="radio" ${data && data.type === 'radio' ? 'selected' : ''}>Single (Radio)</option>
                        <option value="checkbox" ${data && data.type === 'checkbox' ? 'selected' : ''}>Multi (Check)</option>
                    </select>
                    <button type="button" onclick="this.closest('.group-box').remove()" class="text-red-400 hover:text-red-600 px-2 transition-colors"><i class="fas fa-trash"></i></button>
                </div>
                <div class="values-list space-y-2">
                    ${data ? data.values.map(v => createValueRow(v.label, v.price)).join('') : createValueRow()}
                </div>
                <button type="button" onclick="addValueToGroup(${id})" class="text-[9px] font-black uppercase text-blue-600 mt-3 hover:underline">+ Add Option Value</button>
            </div>
        `;
        document.getElementById('optionGroupsContainer').insertAdjacentHTML('beforeend', html);
    }

    function createValueRow(label = '', price = 0) {
        return `<div class="flex gap-2 value-row">
            <input type="text" placeholder="Label (e.g. Cheese)" class="v-label flex-1 bg-slate-50 border-none rounded-lg p-1.5 text-[10px] font-medium" value="${label}">
            <input type="number" step="0.01" placeholder="₹ Price" class="v-price w-16 bg-slate-50 border-none rounded-lg p-1.5 text-[10px] font-black text-emerald-600" value="${price}">
            <button type="button" onclick="if(this.closest('.values-list').children.length > 1) this.parentElement.remove()" class="text-slate-300 hover:text-red-500 px-1"><i class="fas fa-times"></i></button>
        </div>`;
    }

    function addValueToGroup(groupId) {
        const container = document.querySelector(`#group-${groupId} .values-list`);
        if(container) container.insertAdjacentHTML('beforeend', createValueRow());
    }

    form.onsubmit = () => {
        const groups = [];
        document.querySelectorAll('.group-box').forEach(gb => {
            const groupName = gb.querySelector('.g-name').value.trim();
            if(!groupName) return;

            const values = [];
            gb.querySelectorAll('.value-row').forEach(vr => {
                const label = vr.querySelector('.v-label').value.trim();
                const price = parseFloat(vr.querySelector('.v-price').value || 0);
                if(label) values.push({ label, price });
            });
            groups.push({ name: groupName, type: gb.querySelector('.g-type').value, values });
        });
        document.getElementById('custom_opts_json').value = groups.length > 0 ? JSON.stringify(groups) : '';
    }

    // Sidebar helper
    function openSidebar() { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.remove('hidden'); }
    function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.add('hidden'); }
</script>

</body>
</html>
