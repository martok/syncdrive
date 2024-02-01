# Using the PHP Development Server

For development use, the internal web server provided by PHP can be used together with the router script
provided by `Nepf2`, which simply routes all requests that don't match a static file to `index.php`.
Note that `php` binds to `::1` by default (as in, only IPv6), so specify "any address" explicitly:

```bash
php -S 0.0.0.0:8000 -t ./public ./3rdparty/nepf2/tools/server.php
```
