## Nextcloud integration

### Testing

http://dav.localhost:8000

### See also

* [KaraDAV doc](https://github.com/kd2org/karadav/blob/main/doc/NEXTCLOUD.md)
* [KD2 Nextcloud](https://github.com/kd2org/karadav/blob/main/lib/KD2/WebDAV/NextCloud.php)
* [Protocol (Cernbox)](https://github.com/cernbox/smashbox/blob/master/protocol/protocol.md)
* [Chunking (Cernbox)](https://github.com/cernbox/smashbox/blob/master/protocol/chunking.md)

### Login flow

* `status.php`
* `/ocs/v2.php/cloud/capabilities`
* Login:
  * `/index.php/login/v2`
  * `/index.php/login/v2/flow/<loginToken>`
  * `/index.php/login/v2/poll`

### Post-Login-API

* `/ocs/v1.php/cloud/user?format=json`
  Gets the display name (as opposed to the loginName given to the API token and used in DAV urls)
* `/remote.php/dav/files/{login_name}/`
* `/remote.php/dav/avatars/{login_name}/32.png`
* `/ocs/v2.php/apps/notifications/api/v2/notifications?format=json`
* `/ocs/v2.php/core/navigation/apps?absolute=true&format=json`

### Props

Important to respond to those:

* `{http://owncloud.org/ns}id` FileID
* `{DAV:}getetag` also on Directory
* `{http://owncloud.org/ns}permissions` effective permissions + flags (shared, mountpoint)
