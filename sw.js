/* sw.js — Service Worker за Ени Тихолов */

/* ── Инсталация ── */
self.addEventListener('install', event => {
  self.skipWaiting();
});

/* ── Активация ── */
self.addEventListener('activate', event => {
  event.waitUntil(self.clients.claim());
});

/* ── Push нотификации ── */
self.addEventListener('push', event => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch(e) {
    data = { title: 'Ени Тихолов', body: event.data ? event.data.text() : 'Ново съобщение' };
  }
  const title = data.title || 'Ени Тихолов';
  const options = {
    body:    data.body  || '',
    icon:    data.icon  || '/loyalty/icon-192.png',
    badge:   data.badge || '/loyalty/icon-192.png',
    tag:     'eni-loyalty-push',
    renotify: true,
    vibrate: [200, 100, 200],
    data: { url: data.url || '/loyalty/' },
    actions: [
      { action: 'open',    title: 'Отвори картата' },
      { action: 'dismiss', title: 'Затвори' },
    ],
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

/* ── Клик върху нотификацията ── */
self.addEventListener('notificationclick', event => {
  event.notification.close();
  if (event.action === 'dismiss') return;
  const url = event.notification.data?.url || '/loyalty/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
      for (const client of clientList) {
        if (client.url.includes('/loyalty/') && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) return clients.openWindow(url);
    })
  );
});