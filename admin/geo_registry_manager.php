<?php
session_start();
require_once '../includes/db.php';

// Admin Auth Check
if(!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

// ─── PRG PATTERN HANDLING ───
$success = $_SESSION['success'] ?? ''; 
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ─── POST HANDLER (Add / Edit / Delete) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $id       = (int)($_POST['id'] ?? 0);
    $district = trim($_POST['district_name'] ?? '');
    $block    = trim($_POST['block_name'] ?? '');
    $village  = trim($_POST['village_name'] ?? '');
    $pincode  = trim($_POST['pincode'] ?? '');
    $lat      = (float)($_POST['latitude'] ?? 0);
    $lng      = (float)($_POST['longitude'] ?? 0);

    try {
        if ($action === 'save') {
            if (empty($village) || empty($pincode) || $lat == 0 || $lng == 0) {
                throw new Exception("All fields including coordinates are required.");
            }
            $geo_point_text = "POINT($lng $lat)";

            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE geo_registry SET district_name=?, block_name=?, village_name=?, pincode=?, latitude=?, longitude=?, geo_point=ST_GeomFromText(?, 4326) WHERE id=?");
                $stmt->execute([$district, $block, $village, $pincode, $lat, $lng, $geo_point_text, $id]);
                $_SESSION['success'] = "Location updated successfully!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO geo_registry (district_name, block_name, village_name, pincode, latitude, longitude, geo_point) VALUES (?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326))");
                $stmt->execute([$district, $block, $village, $pincode, $lat, $lng, $geo_point_text]);
                $_SESSION['success'] = "New location added to registry!";
            }
        } elseif ($action === 'delete') {
            $pdo->prepare("DELETE FROM geo_registry WHERE id = ?")->execute([$id]);
            $_SESSION['success'] = "Location removed from registry.";
        }

        header("Location: geo_registry_manager.php" . (!empty($_GET['search']) ? "?search=".urlencode($_GET['search']) : ""));
        exit();

    } catch (Exception $e) { 
        $_SESSION['error'] = $e->getMessage();
        header("Location: geo_registry_manager.php");
        exit();
    }
}

// ─── DATA FETCHING ───────────────────────────────────────────────────────────
$search          = trim($_GET['search'] ?? '');
$district_filter = trim($_GET['district'] ?? '');
$block_filter    = trim($_GET['block'] ?? '');

$query  = "SELECT id, district_name, block_name, village_name, pincode, latitude, longitude FROM geo_registry WHERE 1=1";
$params = [];

if ($search) {
    $query .= " AND (village_name LIKE ? OR block_name LIKE ? OR pincode LIKE ?)";
    $search_param = "%$search%";
    array_push($params, $search_param, $search_param, $search_param);
}
if ($district_filter) { $query .= " AND district_name = ?"; $params[] = $district_filter; }
if ($block_filter)    { $query .= " AND block_name = ?";    $params[] = $block_filter; }

$query .= " ORDER BY district_name ASC, block_name ASC, village_name ASC LIMIT 200";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$locations = $stmt->fetchAll();

// Summary Stats
$total_v = $pdo->query("SELECT COUNT(*) FROM geo_registry")->fetchColumn();
$total_b = $pdo->query("SELECT COUNT(DISTINCT block_name) FROM geo_registry")->fetchColumn();

// Unique Districts & Blocks for Filters
$districts_list = $pdo->query("SELECT DISTINCT district_name FROM geo_registry ORDER BY district_name ASC")->fetchAll(PDO::FETCH_COLUMN);
$blocks_list    = $pdo->query("SELECT DISTINCT block_name FROM geo_registry ORDER BY block_name ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Geo Registry Manager — KhataLink Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php require_once '../includes/cashfree_config.php'; // For FIREBASE_API_KEY ?>
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-900">

<nav class="sticky top-0 z-[1000] bg-white border-b border-slate-200 h-16 flex items-center justify-between px-8 shadow-sm">
    <div class="flex items-center gap-4">
        <img src="https://i.ibb.co/VcCqLXtW/Chat-GPT-Image-Apr-27-2026-03-04-41-PM.png" class="h-8">
        <h1 class="text-sm font-black uppercase tracking-widest text-slate-400">Geo Registry System</h1>
    </div>
    <button onclick="openModal()" class="bg-blue-600 text-white px-6 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest shadow-lg shadow-blue-200">
        <i class="fas fa-plus mr-1"></i> Add New Village
    </button>
</nav>

<div class="flex">
    <?php include '../includes/admin_sidebar.php'; ?>

    <main class="flex-1 p-8">
        <div class="max-w-6xl mx-auto">

            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <div class="text-[9px] font-black text-slate-400 uppercase mb-1">Total Villages</div>
                    <div class="text-3xl font-black text-blue-600"><?= $total_v ?></div>
                </div>
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                    <div class="text-[9px] font-black text-slate-400 uppercase mb-1">Active Blocks</div>
                    <div class="text-3xl font-black text-slate-900"><?= $total_b ?></div>
                </div>
                <div class="bg-blue-600 p-6 rounded-3xl text-white shadow-xl shadow-blue-100">
                    <div class="text-[9px] font-black text-blue-200 uppercase mb-1">System Status</div>
                    <div class="text-xl font-black">High Precision Active</div>
                </div>
            </div>

            <!-- Search -->
            <div class="bg-white p-6 rounded-[2rem] border border-slate-200 mb-8 shadow-sm">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Keyword Search</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search Village, Block or Pincode..." class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-2 text-sm font-bold outline-none focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">District Filter</label>
                        <select name="district" class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-2 text-sm font-bold">
                            <option value="">All Districts</option>
                            <?php foreach($districts_list as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>" <?= $district_filter == $d ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Block Filter</label>
                        <select name="block" class="w-full bg-slate-50 border border-slate-100 rounded-xl px-4 py-2 text-sm font-bold">
                            <option value="">All Blocks</option>
                            <?php foreach($blocks_list as $b): ?>
                            <option value="<?= htmlspecialchars($b) ?>" <?= $block_filter == $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="bg-slate-900 text-white px-6 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest shadow-lg">Apply Filters</button>
                </form>
                <?php if($search || $district_filter || $block_filter): ?>
                    <a href="geo_registry_manager.php" class="inline-block mt-4 text-[10px] font-black text-red-500 uppercase hover:underline">Clear All Filters</a>
                <?php endif; ?>
            </div>

            <!-- Table -->
            <div class="bg-white border border-slate-200 rounded-[2rem] overflow-hidden shadow-sm">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-100">
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase">Village / Block</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase">District / Pin</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase">Precise Coordinates</th>
                            <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach($locations as $l): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="text-sm font-black text-slate-900"><?= htmlspecialchars($l['village_name']) ?></div>
                                <div class="text-[10px] font-bold text-slate-400 uppercase"><?= htmlspecialchars($l['block_name']) ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-xs font-bold text-slate-700"><?= htmlspecialchars($l['district_name']) ?></div>
                                <div class="text-[10px] font-black text-blue-600"><?= htmlspecialchars($l['pincode']) ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-[10px] font-mono text-slate-500">Lat: <?= $l['latitude'] ?></div>
                                <div class="text-[10px] font-mono text-slate-500">Lng: <?= $l['longitude'] ?></div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <!-- FIX: JSON_HEX flags prevent quote/tag breaking inside data attribute -->
                                <button
                                    onclick="editEntry(this)"
                                    data-json='<?= htmlspecialchars(json_encode($l, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG), ENT_QUOTES) ?>'
                                    class="text-blue-600 hover:text-blue-800 p-2">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" class="inline" onsubmit="return confirm('Delete this village?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                                    <button type="submit" class="text-red-400 hover:text-red-600 p-2"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($locations)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center text-slate-400 text-sm font-bold">No locations found. Add a new village to get started.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </main>
</div>

<!-- Add/Edit Modal -->
<div id="geoModal" class="fixed inset-0 z-[2000] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white w-full max-w-2xl rounded-[2.5rem] p-8 shadow-2xl overflow-hidden">
        <div class="flex justify-between items-center mb-8">
            <h2 id="modalTitle" class="text-xl font-black text-slate-900 uppercase tracking-tight">Add Registry Entry</h2>
            <button onclick="closeModal()" class="text-slate-400 hover:text-slate-600 transition-colors"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="space-y-4">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="formId" value="0">

                <div class="bg-blue-50 border border-blue-100 p-4 rounded-2xl flex items-start gap-2">
                    <i class="fas fa-magic text-blue-500 mt-0.5 text-xs"></i>
                    <p class="text-[9px] text-blue-700 font-bold uppercase leading-relaxed">Map Tip: Click or drag marker on map — District, Block, Village & Pincode will auto-fill instantly!</p>
                </div>
                <!-- Geocoding loader indicator -->
                <div id="geocodeLoader" class="hidden items-center gap-2 bg-amber-50 border border-amber-100 px-4 py-2.5 rounded-xl">
                    <svg class="animate-spin h-3 w-3 text-amber-500" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg>
                    <span class="text-[9px] font-black text-amber-600 uppercase">Fetching location details...</span>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-1">District</label>
                        <input type="text" name="district_name" id="formDistrict" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-bold focus:border-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-1">Block</label>
                        <input type="text" name="block_name" id="formBlock" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-bold focus:border-blue-500 outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-1">Village Name</label>
                        <input type="text" name="village_name" id="formVillage" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-bold focus:border-blue-500 outline-none" required>
                    </div>
                    <div>
                        <label class="block text-[10px] font-black uppercase text-slate-400 mb-1">Pincode</label>
                        <input type="text" name="pincode" id="formPincode" maxlength="6" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-bold focus:border-blue-500 outline-none" required>
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black uppercase text-slate-400 mb-1">Precision Latitude</label>
                    <input type="number" name="latitude" id="formLat" step="any" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:border-blue-500 outline-none" required>
                </div>
                <div>
                    <label class="block text-[10px] font-black uppercase text-slate-400 mb-1">Precision Longitude</label>
                    <input type="number" name="longitude" id="formLng" step="any" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm font-mono focus:border-blue-500 outline-none" required>
                </div>

                <button type="submit" class="w-full bg-blue-600 text-white font-black py-4 rounded-2xl uppercase tracking-widest text-xs shadow-lg shadow-blue-100 mt-4">
                    Save Location to Registry
                </button>
            </div>

            <div class="flex flex-col">
                <label class="block text-[10px] font-black uppercase text-slate-400 mb-2">Location Picker</label>
                <!-- Places Autocomplete Search -->
                <div class="relative mb-2">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs pointer-events-none"></i>
                    <input
                        id="mapSearchBox"
                        type="text"
                        placeholder="Search location, village, city..."
                        class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-8 pr-4 py-2.5 text-sm font-bold focus:border-blue-500 outline-none shadow-sm"
                        autocomplete="off"
                    >
                </div>
                <div id="map-picker" class="flex-1 rounded-[2rem] border-2 border-slate-100 overflow-hidden min-h-[300px]"></div>
            </div>
        </form>
    </div>
</div>

<script>
// ── Global State ──
let map            = null;
let marker         = null;
let mapInitialized = false;

// ── Called by Google Maps API on load (intentionally empty — lazy init) ──
function initMap() {
    // Map loads lazily when modal opens for the first time.
    console.log("Google Maps API ready.");
}

// ── Initialize or re-center map when modal opens ──
function initMapOnOpen(lat, lng) {
    const mapEl = document.getElementById("map-picker");
    if (!mapEl) return;

    const pos = { lat: parseFloat(lat) || 20.2961, lng: parseFloat(lng) || 85.8245 };

    if (!mapInitialized) {
        // First time — create map instance
        map = new google.maps.Map(mapEl, {
            zoom: lat ? 16 : 12,
            center: pos,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: false
        });

        marker = new google.maps.Marker({
            position: pos,
            map: map,
            draggable: true
        });

        map.addListener("click",    (e) => updateCoords(e.latLng));
        marker.addListener("dragend", (e) => updateCoords(e.latLng));

        // ── Places Autocomplete Search Box ──
        const searchInput = document.getElementById("mapSearchBox");
        const autocomplete = new google.maps.places.Autocomplete(searchInput, {
            componentRestrictions: { country: "in" }, // India only
            fields: ["geometry", "address_components", "name", "formatted_address"]
        });

        autocomplete.addListener("place_changed", () => {
            const place = autocomplete.getPlace();
            if (!place.geometry || !place.geometry.location) {
                Swal.fire({ icon: "warning", title: "Not Found", text: "No location found for this search. Try a different name.", timer: 2500, showConfirmButton: false });
                return;
            }

            const location = place.geometry.location;

            // Move map + marker
            map.setCenter(location);
            map.setZoom(15);
            marker.setPosition(location);

            // Update lat/lng fields
            document.getElementById("formLat").value = location.lat().toFixed(15);
            document.getElementById("formLng").value = location.lng().toFixed(15);

            // Auto-fill address fields from place components
            fillFromAddressComponents(place.address_components);
        });

        mapInitialized = true;
    } else {
        // Already exists — just resize + re-center
        google.maps.event.trigger(map, 'resize');
        const latLng = new google.maps.LatLng(pos.lat, pos.lng);
        marker.setPosition(latLng);
        map.setCenter(latLng);
        map.setZoom(lat ? 16 : 12);
    }
}

// ── Extract address fields from Google Places address_components ──
function fillFromAddressComponents(components) {
    if (!components || !components.length) return;

    const getComp = (types) => {
        for (const comp of components) {
            if (types.some(t => comp.types.includes(t))) return comp.long_name;
        }
        return '';
    };

    const village  = getComp(['sublocality_level_1', 'sublocality_level_2', 'neighborhood', 'locality']);
    const block    = getComp(['administrative_area_level_3', 'administrative_area_level_4']);
    const district = getComp(['administrative_area_level_2']);
    const pincode  = getComp(['postal_code']);

    if (village)  document.getElementById("formVillage").value  = village;
    if (block)    document.getElementById("formBlock").value    = block;
    if (district) document.getElementById("formDistrict").value = district;
    if (pincode)  document.getElementById("formPincode").value  = pincode;

    // Green flash on filled fields
    ['formVillage','formBlock','formDistrict','formPincode'].forEach(id => {
        const el = document.getElementById(id);
        if (!el || !el.value) return;
        el.classList.add('border-green-400', 'bg-green-50');
        setTimeout(() => el.classList.remove('border-green-400', 'bg-green-50'), 1500);
    });
}

function updateCoords(latLng) {
    if (!latLng) return;

    const lat = latLng.lat();
    const lng = latLng.lng();

    // Update marker + coord fields
    marker.setPosition(latLng);
    document.getElementById("formLat").value = lat.toFixed(15);
    document.getElementById("formLng").value = lng.toFixed(15);

    // Show loader
    const loader = document.getElementById("geocodeLoader");
    loader.classList.remove("hidden");
    loader.classList.add("flex");

    // Reverse Geocode using Google Geocoding API
    const geocoder = new google.maps.Geocoder();
    geocoder.geocode({ location: { lat, lng } }, (results, status) => {

        // Hide loader
        loader.classList.add("hidden");
        loader.classList.remove("flex");

        if (status !== "OK" || !results || results.length === 0) {
            console.warn("Geocoding failed:", status);
            return;
        }

        // Combine all address_components from all results for best coverage
        const allComponents = results.flatMap(r => r.address_components);
        fillFromAddressComponents(allComponents);
    });
}

// ── Open modal for ADD ──
function openModal() {
    document.getElementById('formId').value = "0";
    document.getElementById('modalTitle').innerText = "Add Registry Entry";
    document.querySelector('#geoModal form').reset();

    const modal = document.getElementById('geoModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    // Wait for modal to be visible, then init map
    setTimeout(() => initMapOnOpen(null, null), 150);
}

// ── Open modal for EDIT ──
function editEntry(btn) {
    let data;
    try {
        data = JSON.parse(btn.getAttribute('data-json'));
    } catch(e) {
        console.error("JSON Parse Error:", e);
        Swal.fire('Error', 'Could not load entry data. Please try again.', 'error');
        return;
    }

    const lat = parseFloat(data.latitude)  || null;
    const lng = parseFloat(data.longitude) || null;

    // Fill form fields
    document.getElementById('formId').value       = data.id       || 0;
    document.getElementById('formDistrict').value = data.district_name || '';
    document.getElementById('formBlock').value    = data.block_name   || '';
    document.getElementById('formVillage').value  = data.village_name || '';
    document.getElementById('formPincode').value  = data.pincode      || '';
    document.getElementById('formLat').value      = lat || '';
    document.getElementById('formLng').value      = lng || '';
    document.getElementById('modalTitle').innerText = "Edit Registry Entry";

    const modal = document.getElementById('geoModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    // Wait for modal to be visible, then init/update map
    setTimeout(() => initMapOnOpen(lat, lng), 150);
}

// ── Close modal ──
function closeModal() {
    const modal = document.getElementById('geoModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// ── SweetAlert notifications (PRG pattern) ──
<?php if (!empty($success)): ?>
Swal.fire({ icon: 'success', title: 'Success', text: '<?= addslashes($success) ?>', timer: 3000, showConfirmButton: false });
<?php endif; ?>
<?php if (!empty($error)): ?>
Swal.fire({ icon: 'error', title: 'Error', text: '<?= addslashes($error) ?>' });
<?php endif; ?>
</script>

<!-- Google Maps API — callback=initMap is required by the loader but we handle init lazily -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?= FIREBASE_API_KEY ?>&libraries=places&callback=initMap" async defer></script>

</body>
</html>