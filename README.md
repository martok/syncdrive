# SyncDrive File & Synchronization Server

## Info

Simple and easy file synchronization and sharing platform, built to be compatible with Nextcloud Desktop and Mobile
clients as well as allow full access using WebDAV.

SyncDrive supports file versioning, trash/undelete functionality and sharing of files with internal and external users.

## Installation

1. Set up a [supported](#requirements) database
2. Clone this repository somewhere: `git clone https://github.com/martok/syncdrive.git`
3. Initialize Submodules: `git submodule update --init`
4. Set basic [configuration](doc/configuration.md) in `data/config.user.php`
5. Install dependencies: `composer install --no-dev -o`
6. Set up your web server to serve the contents of the `public/` folder with proper rewrites

You can now check your setup by navigating to the server location. This will also initialize the database and auxiliary
files. If everything seems to be in order, set the configuration value `site.maintenance` to `false` to start running normally.

## Requirements

* PHP 8.2+
* `imagick` extension
* Any database supported by [PDO](https://www.php.net/manual/de/book.pdo.php)
* a directory writable by the web server process as the `data/` subdirectory 
