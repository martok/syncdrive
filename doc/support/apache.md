# Using Apache2.4

Create a `.htaccess` in `public/` with the content below.

```htaccess
<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteCond %{REQUEST_FILENAME} -s [OR]
    RewriteCond %{REQUEST_FILENAME} -l [OR]
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^.*$ - [NC,L]

    RewriteRule ^.*$ index.php [NC,L]
</IfModule>
```
