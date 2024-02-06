# Configuration

Configuration data is combined from the system-default file `app/config.base.php` and the user-defined
`data/config.user.php` (if present). On updates, only the base configuration is overwritten, while the user
configuration is never changed automatically. The base config also defines the configuration schema. Keys that are not
present in the base config are removed from the merged result, however lists can always be replaced.

The resulting merged config can be examined in the admin interface.

To get started, only the following `config.user.php` is really required:

```php
<?php

return [
    'site' => [
        'maintenance' => false,
    ],
];
```

# Value Types

| Shorthand     | Meaning                                             | Example       |
|---------------|-----------------------------------------------------|---------------|
| PATH_EXPANDED | Path expanded relative to the installation base dir | `'data/blob'` |
| SIZE          | PHP shorthand for file sizes, with suffix K/M/G/T   | `'64M'`       |
| `a \| b \| c` | Alternatives                                        | `a`           |
| STRING        | Arbitrary string                                    | `'SyncDrive'` |
| INT           | Integer number                                      | 42            |
| BOOL          | Boolean value                                       | true          |

# All Configuration Options

- `log`
  - `file`: `PATH_EXPANDED`  
    Default: `'data/application.log'`

- `db`
  - `type`: PDO driver name, `sqlite | pgsql | mysql | oci | odbc | sqlsrv | ...`  
    Default: `'sqlite'`
  - `host`: `STRING`  
    Default: `'localhost'`
  - `database`: `STRING` or `PATH_EXPANDED` for Sqlite  
    Default: `'data/database.sqlite'`
  - `username`: `STRING`  
    Default: `''`
  - `password`: `STRING`  
    Default: `''`

- `storage`
  - `checksums`: array of `SHA1 | SHA3-256 | SHA256 | MD5`  
    Default: `['SHA1']`
  - `chunkSize`: `SIZE`  
    Default: `'64M'`
  - `backends`: array of [Backend objects](#backend)  
    Default: a [Backend](#backend) for [FileBackend](#filebackend) with default options

- `site`
  - `title`: `STRING`  
    Default: `'SyncDrive'`
  - `byline`: `STRING`  
    Default: `'© 2023 Martok'`
  - `maintenance`: `BOOL`  
    Default: `true`
  - `readonly`: `BOOL`  
    Default: `false`
  - `registration`: `BOOL`  
    Default: `true`
  - `adminUsers`: array of `INT`  
    Default: `[]`

- `files`
  - `trash_days`: `INT`  
    Default: `7`
  - `versions`
    - `max_days`: `INT`  
      Default: `365`
    - `zero_byte_seconds`: `INT`  
      Default: `5`
    - `intervals`: array of `[interval, keep]` pairs  
      Default:
      ```php
      [             10,          2]
      [             60,         10]
      [           3600,         60]
      [          86400,       3600]
      [        2592000,      86400]
      [              -1,    604800]
      ```
- `thumbnails`
  - `enabled`: `BOOL`  
    Default: `true`
  - `maxFileSize`: `SIZE`  
    Default: `'10M'`
  - `resolutions`: array of `[width, height]` pairs  
    Default: `[ [256, 256] ]`

- `tasks`
  - `runMode`: `request | cron | webcron`  
    Default: `'request'`
  - `maxRunTime`: `INT`  
    Default: `100`
  - `webtoken`: `STRING`  
    Default: `'123456789'`

# Configuration Objects

## Backend

- `intent`: array of `temporary | storage`  
  Default: -
- `class`: name of class implementing `\App\ObjectStorage\IStorageBackend`  
  Default: -
- `config`: [FileBackend](#filebackend) or [B2Backend](#b2backend) object  
  Default: -

## FileBackend
Used with class: `App\ObjectStorage\FileBackend\FileBackend`

- `path`: `PATH_EXPANDED`  
  Default: `'data/blob'`

## B2Backend
Used with class: `App\ObjectStorage\B2Backend\B2Backend`

- `keyId`: `STRING`  
  Default: `''`
- `applicationKey`: `STRING`  
  Default: `''`
- `bucketName`: `STRING`  
  Default: `''`
- `bucketId`: `STRING`  
  Default: `''`
- `cache`
  - `maxSize`: `SIZE`  
    Default:  `'0'`
  - `path`: `PATH_EXPANDED`    
    Default: `'data/b2cache'`

