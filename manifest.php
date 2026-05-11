<?php
/*
 * manifest.php — Динамичен Web App Manifest
 * Генерира manifest с start_url специфичен за картата на клиента.
 *
 * Използване: вместо статичен manifest.webmanifest,
 * card.php зарежда този файл като манифест.
 *
 * URL: /loyalty/manifest.php?card=ET664429
 */
 
// Без кеш — манифестът трябва винаги да е актуален
header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
 
$card = preg_replace('/[^A-Za-z0-9\-]/', '', trim((string)($_GET['card'] ?? '')));
 
// Ако няма карта — използваме празен start_url (ще го попълни localStorage)
$startUrl = $card !== ''
    ? '/card.php?card=' . $card . '&source=pwa'
    : '/card.php';
 
$manifest = [
    'name'             => 'Ени Тихолов — Лоялна карта',
    'short_name'       => 'Ени карта',
    'description'      => 'Лоялна програма Ени Тихолов — бонуси, ваучери и награди',
    'start_url'        => $startUrl,
    'scope'            => '/',
    'display'          => 'standalone',
    'orientation'      => 'portrait',
    'background_color' => '#E8B800',
    'theme_color'      => '#E8B800',
    'lang'             => 'bg',
    'icons'            => [
        [
            'src'     => '/icon-192.png',
            'sizes'   => '192x192',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
        [
            'src'     => '/icon-512.png',
            'sizes'   => '512x512',
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ],
    ],
    'screenshots' => [],
    'categories'  => ['shopping', 'lifestyle'],
];
 
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);