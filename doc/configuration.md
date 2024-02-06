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
  - `only_errors`: `BOOL`  
    Default: `false`

    Only log errors if a message of at least WARNING level occurred, otherwise print nothing.

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
    
    The checksums to pre-generate on upload for use with sync clients.

  - `chunkSize`: `SIZE`  
    Default: `'64M'`

    Split files into blocks of the given size for storage. Making this larger slightly reduces the overhead, but
    increases memory requirement during reading. Fetching large chunks from remote object storages can also take significant
    time.

  - `backends`: array of [Backend objects](#backend)  
    Default: a [Backend](#backend) for [FileBackend](#filebackend) with default options

    Note that there must be at least one backend with `temporary` intent and at least one with `storage` intent. This
    can be the same backend.

- `site`
  - `title`: `STRING`  
    Default: `'SyncDrive'`

    The site title used in the web interface, DAV and client login prompts.

  - `byline`: `STRING`  
    Default: `'© 2023 Martok'`

    Notice line placed at the bottom of web pages, can be used to denote the organization running an instance.

  - `maintenance`: `BOOL`  
    Default: `true`

    Suppress any request handling and present only a static notification page. Intended to be set during upgrades.
    See also `site.readonly`.

  - `readonly`: `BOOL`  
    Default: `false`

    Reject any request that would modify data, but otherwise run normally. This can be used during no-downtime backups.
    See also `site.maintenance`.

  - `registration`: `BOOL`  
    Default: `true`

    Allow self-registration of new users.

  - `adminUsers`: array of `INT`  
    Default: `[]`

    Numeric IDs of users with access to the admin interface.

- `files`
  - `trash_days`: `INT`  
    Default: `7`

    Keep deleted files around for that many days after deletion, then automatically remove them.

  - `versions`
    - `max_days`: `INT`  
      Default: `365`

      Oldest age of any non-current file version to keep.

    - `zero_byte_seconds`: `INT`  
      Default: `5`

      When a zero-byte file is created and immediately replaced within this many seconds, remove the empty file.

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

      The logic is "for the first *interval* seconds, one file every *keep* seconds". The default reads as:
      For 10 seconds, keep one revision every 2 seconds.
      For 1 minute, keep one revision every 10 seconds.
      For 1 hour, keep one revision every minute.
      For 1 day, keep one revision every hour.
      For 1 month, keep one revision every day.
      Until `files.versions.max_days`, keep one revision every week.

- `thumbnails`
  - `enabled`: `BOOL`  
    Default: `true`

    Create thumbnails for media files after upload.

  - `maxFileSize`: `SIZE`  
    Default: `'10M'`

    Only create thumbnails for files smaller than this size.

  - `resolutions`: array of `[width, height]` pairs  
    Default: `[ [256, 256] ]`

    Create thumbnails of these resolutions.

- `tasks`
  - `runMode`: `request | cron | webcron`  
    Default: `'request'`

    Perform asynchronous tasks on each `request`, by calling `taskrunner.php` with a `cron` job, or by calling it using
    a `webcron` service.

  - `maxRunTime`: `?INT`  
    Default: `100`

    Allowed time spent running tasks. Set to `null` for unlimited runtime, set small to keep the site responsive with the
    `request` mechanism.

  - `webtoken`: `STRING`  
    Default: `'123456789'`

    When using the `webcron` mechanism, only run if this token is given as a GET parameter `token`.


# Configuration Objects

## Backend

- `intent`: array of `temporary | storage`  
  Default: -

  What this backend is supposed to store (reading always cascades in definition order). Temporary objects are created
  during upload before the final object name can be found, then moved to the top-most backend with intent `storage`.

- `class`: name of class implementing `\App\ObjectStorage\IStorageBackend`  
  Default: -
- `config`: [FileBackend](#filebackend) or [B2Backend](#b2backend) object  
  Default: -

## FileBackend
Used with class: `App\ObjectStorage\FileBackend\FileBackend`

- `path`: `PATH_EXPANDED`  
  Default: `'data/blob'`

  The root of the object store. Must be writable by user running PHP.

## B2Backend
Used with class: `App\ObjectStorage\B2Backend\B2Backend`

- `keyId`: `STRING`  
  Default: `''`

  The identifier for the `applicationKey`. This is the user id if the master key is used (not recommended) or the
  key id if a restricted key is used.

- `applicationKey`: `STRING`  
  Default: `''`
- `bucketName`: `STRING`  
  Default: `''`

  Unique name of the storage bucket.

- `bucketId`: `STRING`  
  Default: `''`

  ID of the storage bucket.

- `cache`
  - `maxSize`: `SIZE`  
    Default:  `'0'`

    Enable a local chunk cache. This is recommended to avoid re-downloading chunks for repeated requests, which would
    incur costs for class B requests and egress volume. This cache is kept as an MRU cache and chunks not recently used
    are automatically removed when a new one is requested.

    There is no reason not to make this as large as the available disk space (minus temporary object storage for uploads)
    allows and just keep every chunk locally in addition to remote "cold storage".

  - `path`: `PATH_EXPANDED`    
    Default: `'data/b2cache'`
 
    The root of the chunk cache, if enabled. Must be writable by user running PHP.
