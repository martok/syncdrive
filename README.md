# SyncDrive File & Synchronization Server

## Info

Simple and easy file synchronization and sharing platform, built to be compatible with Nextcloud Desktop and Mobile
clients as well as allow full access using WebDAV.

SyncDrive supports file versioning, trash/undelete functionality and sharing of files with internal and external users.

## Installation

### Prerequisites

1. Set up a [supported](#requirements) database and client
2. Install a web server, such as nginx:
   `apt install nginx`
3. Install PHP and extensions:
   `apt install php-fpm`
   `apt install php composer php-curl php-imagick php-mysql php-pgsql php-sqlite3 php-xml`
   (PHP depends on "a cgi backend", so make sure to install `php-fpm` first, or it will likely install `apache2`)

### Install the code

1. Clone this repository somewhere: `git clone --recurse-submodules https://github.com/martok/syncdrive.git`
2. Set basic [configuration](doc/configuration.md) in `data/config.user.php`
3. Install dependencies: `composer install --no-dev -o`

### Set up your web server

1. Set up your web server to serve the contents of the `public/` folder with proper rewrites
   - Example for [nginx](doc/support/nginx.md)
   - Example for [PHP development server](doc/support/devel-server.md) (Not for production use!)
2. Check your setup by navigating to the server location. This will also initialize the database and auxiliary files.
3. If everything seems to be in order, set the configuration value `site.maintenance` to `false` to start running normally.

## Requirements

* PHP 8.2+
* `imagick` extension
* Any database supported by [PDO](https://www.php.net/manual/de/book.pdo.php)
* a directory writable by the web server process as the `data/` subdirectory 

## Updating

1. Set the configuration value `site.maintenance` to `true`.
2. Update the code: `git fetch --all && git reset --hard origin/main`
3. Update submodules: `git submodule update --force`
4. Install dependencies: `composer install --no-dev -o`
5. Set the configuration value `site.maintenance` to `false`. The next time the site is visited, all required migrations 
   will be run.
