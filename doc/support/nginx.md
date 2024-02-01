# Using nginx

Site config for the local server:

```nginx
server {
    listen *:80 default_server;
    server_name localhost;
    server_tokens off;
    root /var/www/syncdrive/public;

    client_max_body_size 1G;

    access_log  /var/log/nginx/syncdrive_access.log;
    error_log   /var/log/nginx/syncdrive_error.log;

    location / {
        try_files $uri /index.php$uri$is_args$args;
    }

    location ~ [^/].php(/|$) {
        # regex to split $uri to $fastcgi_script_name and $fastcgi_path
        fastcgi_split_path_info ^(.+?\.php)(/.*)$;

        # Check that the PHP script exists, otherwise go back to request router
        try_files $fastcgi_script_name /index.php$uri$is_args$args;

        # Bypass the fact that try_files resets $fastcgi_path_info
        # see: http://trac.nginx.org/nginx/ticket/321
        set $path_info $fastcgi_path_info;
        fastcgi_param PATH_INFO $path_info;

        fastcgi_index index.php;
        include fastcgi.conf;

        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```
