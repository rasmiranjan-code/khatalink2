importScripts('https://www.gstatic.com/firebasejs/9.22.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.22.1/firebase-messaging-compat.js');

firebase.initializeApp({
    apiKey: "AIzaSyCM8z5Y_lMephKKsP9U0AtdIisIyKkounE",
    authDomain: "khatalink-63041.firebaseapp.com",
    projectId: "khatalink-63041",
    messagingSenderId: "905429197043",
    appId: "1:905429197043:web:2a0cbefa0fa176fd2c5786"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage(function(payload) {
    console.log('[SW] Background message received:', payload);

    // FIX: removed spaces in optional chaining (?. not ? .)
    var notificationTitle = (payload.notification && payload.notification.title) ||
        (payload.data && payload.data.title) ||
        'KhataLink Update';

    var notificationOptions = {
        body: (payload.notification && payload.notification.body) ||
            (payload.data && payload.data.body) ||
            'Naya message aaya hai.',
        icon: '/khatalink/assets/favicon.png',
        badge: '/khatalink/assets/favicon.png',
        vibrate: [200, 100, 200],
        requireInteraction: true,
        tag: 'khatalink-notification',
        data: payload.data || {}
    };

    // FIX: removed spaces in optional chaining
    var imageUrl = (payload.notification && payload.notification.image) ||
        (payload.data && payload.data.image) ||
        null;

    if (imageUrl) {
        notificationOptions.image = imageUrl;
    }

    self.registration.showNotification(notificationTitle, notificationOptions);
});

// Notification click handle karo
self.addEventListener('notificationclick', function(event) {
    event.notification.close();

    var url = (event.notification.data && event.notification.data.url) ||
        '/khatalink/customer/dashboard.php';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
        .then(function(clientList) {
            // Already open tab mein focus karo
            for (var i = 0; i < clientList.length; i++) {
                if (clientList[i].url.indexOf('/khatalink') !== -1) {
                    return clientList[i].focus();
                }
            }
            // Nahi mila toh naya tab kholo
            return clients.openWindow(url);
        })
    );
});