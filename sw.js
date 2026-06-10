/* sw.js — KILL SWITCH (S20.05.2026)
 * Force unregister на всички стари service workers
 * след recovery от компрометиран droplet
 */
self.addEventListener('install', event => {
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    (async () => {
      // 1. Изтрий всички cache-ове
      const cacheNames = await caches.keys();
      await Promise.all(cacheNames.map(name => caches.delete(name)));
      
      // 2. Unregister себе си
      await self.registration.unregister();
      
      // 3. Force reload на всички tabs
      const clients = await self.clients.matchAll();
      clients.forEach(client => client.navigate(client.url));
    })()
  );
});
