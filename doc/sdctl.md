# sdctl

For advanced/lowlevel operations, the `sdctl` command is provided for local command-line administration.

## Usage

```bash
$ sdctl.php <command> [options]
```

Ensure `sdctl` is run as the same user as the web server / PHP user and using the same version of PHP as the main server,
i.e.:

```bash
$ sudo -u www-data sdctl.php cfg:print
```

## Commands

### DB

```
db:migrate                  explicitly run database migrations
```

### Cfg

```
cfg:print                   output combined configuration
```

### Storage

```
storage:list                list all defined storage backends and usage
storage:migrate             move/copy stored objects from one backend to another
```
