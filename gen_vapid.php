#!/usr/bin/env php
<?php
/*
 * gen_vapid.php — Генерира VAPID key pair за Web Push
 * Пусни ВЕДНЪЖ. Записва private в vapid_private.txt (0600 permissions).
 * Public key се съгласува с този в card.php.
 */
require_once __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();

$publicKey  = $keys['publicKey'];
$privateKey = $keys['privateKey'];

// Записваме private key в защитен файл
$privatePath = __DIR__ . '/vapid_private.txt';
$publicPath  = __DIR__ . '/vapid_public.txt';

file_put_contents($privatePath, $privateKey);
file_put_contents($publicPath,  $publicKey);
chmod($privatePath, 0600);
chmod($publicPath,  0644);

echo "═══════════════════════════════════════════════\n";
echo " VAPID KEYS GENERATED\n";
echo "═══════════════════════════════════════════════\n\n";
echo "PUBLIC KEY  (сложи в card.php):\n";
echo $publicKey . "\n\n";
echo "PRIVATE KEY (в vapid_private.txt, 0600):\n";
echo $privateKey . "\n\n";
echo "═══════════════════════════════════════════════\n";
echo " ВАЖНО:\n";
echo " 1. ПРОВЕРИ дали public key в card.php съвпада\n";
echo "    с новия. Ако НЕ съвпада — всички текущи\n";
echo "    push subscriptions ще станат невалидни.\n";
echo " 2. НИКОГА не commit-вай vapid_private.txt в git.\n";
echo "═══════════════════════════════════════════════\n";
