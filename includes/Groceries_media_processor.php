<?php
/**
 * KhataLink Groceries - Media Pipeline
 * Mandatory Image Processing: Resizing & WebP Conversion
 */

function groceries_process_image(string $file_tmp, int $product_id, int $shop_id): array {
    if (!function_exists('imagecreatefromjpeg')) {
        throw new Exception("PHP GD Extension is not enabled on this server. Please enable 'extension=gd' in php.ini and restart Apache.");
    }

    $upload_dir = "../assets/img/products/shop_$shop_id/";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $base_name = "prod_" . $product_id . "_" . time();
    $paths = [
        'hero'  => $upload_dir . $base_name . "_hero.webp",
        'thumb' => $upload_dir . $base_name . "_thumb.webp"
    ];

    // Create Image Resource
    $info = getimagesize($file_tmp);
    $mime = $info['mime'];

    switch($mime) {
        case 'image/jpeg': $img = imagecreatefromjpeg($file_tmp); break;
        case 'image/png':  $img = imagecreatefrompng($file_tmp); break;
        default: return [];
    }

    // 1. Generate Hero (600px)
    $hero = imagescale($img, 600);
    imagewebp($hero, $paths['hero'], 80);

    // 2. Generate Thumb (200px)
    $thumb = imagescale($img, 200);
    imagewebp($thumb, $paths['thumb'], 70);

    // 3. Generate Tiny Base64 (20px blur)
    $tiny = imagescale($img, 20);
    ob_start();
    imagewebp($tiny, null, 10);
    $tiny_data = ob_get_clean();
    $tiny_base64 = 'data:image/webp;base64,' . base64_encode($tiny_data);

    imagedestroy($img);
    imagedestroy($hero);
    imagedestroy($thumb);
    imagedestroy($tiny);

    return [
        'hero'  => str_replace('../', '', $paths['hero']),
        'thumb' => str_replace('../', '', $paths['thumb']),
        'tiny'  => $tiny_base64
    ];
}