## Nextcloud integration

### Testing

http://dav.localhost:8000

### See also

* [KaraDAV doc](https://github.com/kd2org/karadav/blob/main/doc/NEXTCLOUD.md)
* [KD2 Nextcloud](https://github.com/kd2org/karadav/blob/main/lib/KD2/WebDAV/NextCloud.php)
* [Protocol (Cernbox)](https://github.com/cernbox/smashbox/blob/master/protocol/protocol.md)
* [Chunking (Cernbox)](https://github.com/cernbox/smashbox/blob/master/protocol/chunking.md)

### Login flow - Desktop

* `status.php`
* `/ocs/v2.php/cloud/capabilities`
* Login:
  * `/index.php/login/v2`
  * `/index.php/login/v2/flow/<loginToken>`
  * `/index.php/login/v2/poll`

### Login flow - Android

* `/ocs/v2.php/cloud/capabilities`
* `remote.php/dav` (aka webdav-root from capabilities)
* Login
  * `/index.php/login/flow`
  * (which we then redirect to v2 flow)
  * Final URL: `nc://login/server:<server>&user:<urlencode(loginname)>&password:<urlencode(password)>`

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


### Chunked file uploads

#### V1

* Upload parts as `filename.ext-chunking-<partcount>-<part>`
* When all parts are uploaded and checksum passed, file is assembled and Etag returned. all others return nothing.

#### V2

* `MKCOL /remote.php/dav/upload/<transferid>`, `PROPFIND /remote.php/dav/upload/<transferid>`
* `PUT /remote.php/dav/upload/<transferid>/<part>`
* `MOVE /remote.php/dav/upload/<transferid>/.file Destination:<the-real-path>`
  also sends `If:` header

* `<transferid>` is supposed to be numeric
* `<part>` must be numeric and in ascending order

This is heavily broken/misimplemented in android < 3.26!

* `<transferid>` is md5 of the file (note this is not unique!)
* instead of `<part>`, we get `<startbyte>-<endbyte>`
