//update cacheName variable for cache refresh of app cache
var cacheName = 'webkul.sw.push.magento.dev.2.0.1';
var filesToCache = ['./','./index.php'];


/**
 * installing service worker
 */
self.addEventListener(
    'install',
    function (e) {
        Logging('[ServiceWorker] Installing');
        Logging(e);
        e.waitUntil(
            caches.open(cacheName).then(
                function (cache) {
                    Logging('[ServiceWorker] Caching app shell');
                    return cache.addAll(filesToCache);
                }
            )
        );
    }
);

/**
 * removing cache if the cache version in updated
 */
self.addEventListener(
    'activate',
    function (e) {
        Logging('[ServiceWorker] Activating');
        e.waitUntil(
            caches.keys().then(
                function (keyList) {
                    return Promise.all(
                        keyList.map(
                            function (key) {
                                if (key !== cacheName) {
                                    Logging('[ServiceWorker] Removing old cache', key);
                                    return caches.delete(key);
                                }
                            }
                        )
                    );
                }
            )
        );
        return self.clients.claim();
    }
);

/**
 * parsing request to the server in serice worker fetch event
 */
self.addEventListener(
    'fetch',
    function (e) {
        Logging('[Service Worker] Fetch', e.request.url);
        /*
        * When the request URL contains dataUrl, the app is asking for fresh
        * weather data. In this case, the service worker always goes to the
        * network and then caches the response. This is called the "Cache then
        * network" strategy:
        */
        var urlExtension = ext(e.request.url);
        var currentPageUrl = "";
        if (e.request.referrer == "") {
            currentPageUrl = e.request.url;
        }

        Logging(urlExtension);
        if (navigator.onLine) {
            Logging("Online Mode");
            var cacheExtensions = ['.css', '.js', '.woff', '.ttf', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.ttc', '.woff2', '.otf'];
            e.respondWith(
                caches.match(e.request).then(function (cache) {
                    if (cache && cacheExtensions.indexOf(urlExtension) >= 0) {
                        Logging("Cached response returned of static files");
                        return cache;
                    } else {
                        return caches.open(cacheName).then(function (savedCache) {
                            return fetch(e.request).then(
                                function (response) {
                                    if (response) {
                                        savedCache.put(e.request.url, response.clone());
                                        Logging("Page cached but original response returned");
                                        return response;
                                    } else {
                                        Logging("Page not cached and error page returned");
                                        return fetch(e.request);
                                    }
                                },
                                function (error) {
                                    Logging("Fetch error");
                                    return fetch(e.request);
                                }
                            );
                        });
                    }
                })
            );
        } else {
            Logging("Offline Mode");
            e.respondWith(
                caches.match(e.request).then(
                    function (response) {
                        return response || fetch(e.request);
                    }
                )
            );
        }
    }
);

/**
 * remove cache if service worker in updated
 */
self.addEventListener('activate', function (event) {
    Logging("Activate Event Removing Old Cache");
    event.waitUntil(
        caches.keys().then(function (cacheNames) {
        return Promise.all(
            cacheNames.filter(function (cacheName) {
            return cacheName;
            }).map(function (cacheName) {
            return caches.delete(cacheName);
            })
        );
        })
    );
  });
  


/**
 * listener for push notification
 */
self.addEventListener(
    'push',
    function (event) {
        var dataa = JSON.parse(event.data.json().data.notification);

        return self.registration.showNotification(
            dataa.title,
            {
                body: dataa.body,
                icon: dataa.icon,
                vibrate: 1,
                actions: dataa.actions
            }
        );
    }
);

/**
 * adding event listener for registring customer for push notification
 *
 * @param  {string} event) {               var url url to notify
 * @return object
 */
self.addEventListener(
    'notificationclick',
    function (event) {
        var url = event.notification.actions[0].action;
        if (url) {
            event.notification.close();
            event.waitUntil(
                clients.matchAll(
                    {
                        type: 'window'
                    }
                ).then(
                    function (windowClients) {
                        if (clients.openWindow) {
                            return clients.openWindow(url);
                        }
                    }
                )
            );
        }
    }
);

/**
 * Logging function to log
 *
 * @param {mixed} $log
 */
function Logging($log)
{
    var canLog = 0;
    if (canLog) {
        console.log($log);
    }
};

function ext(url)
{
    return (url = url.substr(1 + url.lastIndexOf("/")).split('?')[0]).split('#')[0].substr(url.lastIndexOf("."));
}
