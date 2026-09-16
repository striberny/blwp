# Permissions, in one page

Unix file permissions, explained with this project's own paths. Nothing more than you need.

## Reading `ls -l`

```
drwx---rwx 2 deploy deploy 4096 Jan 19 2026 config
│└┬┘└┬┘└┬┘   │      │      │
│ │  │  │    │      │      └── size in bytes
│ │  │  │    │      └───────── group that owns it
│ │  │  │    └──────────────── user that owns it
│ │  │  └───────────────────── what everyone ELSE may do
│ │  └──────────────────────── what the GROUP may do
│ └─────────────────────────── what the OWNER may do
└───────────────────────────── d = directory, - = file
```

Three letters per class, and each letter is either granted or shown as `-`:

| Letter | On a file           | On a directory                                      |
| ------ | ------------------- | --------------------------------------------------- |
| `r`    | read its contents   | list what is inside it                              |
| `w`    | change its contents | **create, delete or rename entries inside it**      |
| `x`    | run it as a program | enter it — needed for anything inside to be reached |

Every process belongs to exactly one user and one group. If it is the owner, the first
triplet applies. If it is only in the group, the middle one. Otherwise the last one. **There
is no "deny" list** — a permission is simply granted or not.

## The numbers

`r` = 4, `w` = 2, `x` = 1, added up per class.

| Octal | Symbolic    | Meaning                                  |
| ----- | ----------- | ---------------------------------------- |
| `755` | `rwxr-xr-x` | owner full, everyone else read           |
| `750` | `rwxr-x---` | owner full, group read, no others        |
| `700` | `rwx------` | owner only                               |
| `644` | `rw-r--r--` | owner writes, everyone reads             |
| `640` | `rw-r-----` | owner writes, group reads                |
| `600` | `rw-------` | owner only                               |
| `707` | `rwx---rwx` | owner full, group nothing, everyone full |

`chmod 750 somefile` sets mode `750`. `chmod -R` recurses.
`chmod -R go-rwx dir` removes read, write **and** execute for group and other, leaving the
owner untouched — a common way to lock a directory down to its owner.

## The one rule that matters most

> **Write permission on a _directory_ lets you delete or replace the files inside it — even
> files you have no write permission on yourself.**

Writing on a _file_ changes its contents. Writing on the _directory_ changes its _list of
entries_: you can delete a file and create a new one with the same name. The file's own mode
never comes into it.

This is why a world-writable directory is worse than a world-writable file, and why
`0707` on the backend was worth fixing.

## Example: why `0707` on the backend mattered

The cron runs `cron/fetch_all.php` as the user `deploy` every 3 minutes, and that file
includes `lib/utils.php`.

With `lib/` at `0707` — `drwx---rwx`, owner full, group nothing, **everyone else full** — any
other user on the server could do this:

```bash
rm /home/deploy/blwp/lib/utils.php
cat > /home/deploy/blwp/lib/utils.php <<'EOF'
<?php system('... anything they like ...');
EOF
```

`utils.php` was `0664` — not writable by other users. It made no difference: the _directory_
was writable, so the file could be deleted and replaced with one of the same name. Three
minutes later the cron loaded it, and their code ran **as `deploy`** — with access to
`~/.ssh` and `~/credentials.txt`.

Nothing about the API requires this. The API is served by the php-fpm pool `deploy`, the cron
runs as `deploy`, and the files are owned by `deploy`. The owner bits are enough, which is
exactly why the fix is:

```bash
chmod -R go-rwx /home/deploy/blwp
```

## What this project uses

| Path                                   | Mode | Why                                                                             |
| -------------------------------------- | ---- | ------------------------------------------------------------------------------- |
| `/home/deploy/blwp` and below          | 700  | only `deploy` touches it — the cron, the API and the owner are the same account |
| `/var/www/.../htdocs`                  | 755  | nginx must read the PHP files it serves                                         |
| `/var/www/.../htdocs/data`             | 755  | payloads are public by design; written by `deploy`, read by nginx               |
| `/home/deploy/blwp/config/secrets.php` | 600  | credentials — owner read/write only                                             |

## Commands worth knowing

```bash
ls -la /path/to/thing          # the mode, owner and group
stat -c '%a %U:%G %n' file     # just the octal, e.g. "600 deploy:www-data secrets.php"
namei -l /a/b/c                # the mode of every directory on the way to a path
id                             # which user and groups am I?
```

`namei -l` is the one to reach for when something "should" be accessible but isn't: it shows
each parent directory, and a missing `x` on any of them blocks everything below it.
