# PingPong machine agent

One small program per machine that tells PingPong how the machine itself is
doing: its load, memory, CPU, disk and pending updates, which Unix user runs
the processes that keep it busy, and how much room every site and database
takes. It runs outside every Laravel app, from cron, as one PHP archive with
nothing else to install: no Composer, no vendor directory. It needs PHP 8.2
or newer on the command line.

The Laravel agent in this repository reports one app. The machine agent
reports the machine those apps share, once, however many apps run on it, so
the Laravel agent leaves the machine out of its ticks (except inside an ECS
task, which cannot run this).

## Installing

On the server's page in PingPong, make its machine key: the page shows the
line to run on that machine, with the key in it. The installer comes from
PingPong itself and already knows its address:

```bash
curl -fsSL https://pingpong.kobaltdigital.nl/server/install.sh | sudo PINGPONG_KEY=pp_machine_... bash
```

That script runs `install.sh` from this directory with `PINGPONG_URL` set,
so running `install.sh` straight from GitHub with `--url` does the same.
The key belongs to that one server: PingPong files everything the agent
sends under it.

**As root** (a Forge server) it installs for the whole machine:

| What | Where |
|---|---|
| The phar | `/usr/local/lib/pingpong-machine/pingpong-machine.phar` |
| The config (key and URL, 0640) | `/etc/pingpong-machine/config.json` |
| What it remembers between runs | `/var/lib/pingpong-machine` |
| Cron | `/etc/cron.d/pingpong-machine`: `tick` every minute as the system user `pingpong-machine`, `self-update` once a day as root |

It runs as the unprivileged user `pingpong-machine`. To measure sites in home
directories that are closed to others (Forge's site isolation), it gets a copy
of `du` with the one capability to read every folder
(`cap_dac_read_search`), executable only by root and that user. Without
`setcap` (package `libcap2-bin`) those sites are not measured.

**Without root** (shared hosting) it installs for you alone, in
`~/.local/lib/pingpong-machine`, `~/.config/pingpong-machine` and your crontab.
It then sees only your own processes (when `/proc` hides the others) and
measures only your home directory. Every user of a shared host can install one
with the server's key; PingPong keeps them apart by Unix user under that
server.

Running the installer again replaces the key and the phar.

## What it sends

**Every minute, a tick** to `POST /api/machine/tick`:

- who sends it: the hostname, the machine id (`/etc/machine-id`), the operating
  system, the kernel, the number of cores and the Unix user it runs as;
- the machine: load, memory, CPU time, pressure stall information, and the
  free space and inodes of `/` (of your home directory without root), read
  exactly as the Laravel agent reads them;
- the host: uptime, a reboot it waits for, pending updates and, while there
  are any, the packages by name (`apt list --upgradable`, only when
  update-notifier counted again);
- the processes per Unix user: how many, the CPU seconds they used since the
  previous tick, their memory and swap, and their four most common names.
  Only the eight busiest users, the four largest and the two that swap most
  are sent, plus the totals. Kernel threads count as the user `kernel`.

Of a process only the name the kernel keeps for it is read, never its command
line, which can carry passwords and keys. php-fpm is the one exception: its
pool name, which tells the sites of one server apart.

Reading the process table costs about 45 microseconds a process (26 ms for
600 processes); each process's `status` file, for its swap, is only read while
the machine swaps.

**Once an hour, the sizes** to `POST /api/machine/sites`, after the tick and
in a request of its own: every home directory and site under `/home` and
`/var/www` (your home directory without root), two levels deep, plus the
folders that grow on their own at any depth (`storage/logs`, `storage/app`,
`wp-content/uploads`, `releases`), at most 400, each with its owner; and every
database by the size of its folder in `/var/lib/mysql`.

`du` is the expensive part: it looks at every file, 8 to 17 microseconds
each, so a server with a million files takes 8 to 20 seconds. To keep that
from hurting the sites:

- it runs at the lowest CPU priority (`nice 19`) and, where the IO scheduler
  honours it, the idle IO class (`ionice -c3`);
- every folder right under a root gets its own `du`, of at most 5 minutes,
  and the whole scan stops after 15; a folder it ran out of time on is sent
  as unmeasured, and the rest still arrives;
- network and FUSE filesystems (NFS, CIFS, sshfs) are skipped, since `du`
  can hang on one that stops answering;
- it waits while the machine is busy (IO pressure above 10%, or more load
  than cores), for six hours at most;
- a lock keeps two scans from ever running at once.

While PingPong has a disk Finding open for the server it asks for the sizes
in its answer to the tick, and gets them at most every 15 minutes.

## Commands

```bash
php pingpong-machine.phar tick         # read and send; cron runs this every minute
php pingpong-machine.phar sites        # measure the sites now
php pingpong-machine.phar self-update  # replace the phar with the newest one
php pingpong-machine.phar version
```

`PINGPONG_MACHINE_CONFIG` points it at another config file.

## Building

The phar in `dist/` is what `install.sh` and `self-update` download, so it is
rebuilt and committed with every change to the agent:

```bash
php -d phar.readonly=0 machine/build.php
```

A test fails while the committed phar does not hold the current code.
