#!/usr/bin/env bash
#
# Installs the machine agent of PingPong, or updates its key.
#
#   curl -fsSL https://pingpong.example/server/install.sh | sudo PINGPONG_KEY=pp_machine_... bash
#
# PingPong serves a short script that sets PINGPONG_URL and runs this one.
# Run straight from GitHub, pass --url and --key yourself.
#
# As root (a Forge server) it installs for the whole machine: a system user
# pingpong-machine runs it from cron every minute, and a copy of du that may
# read every home directory measures the sites. Without root (shared hosting)
# it installs for you alone, in your home directory and your crontab.
#
# The key can also come from PINGPONG_KEY, so it stays out of your shell
# history.

set -euo pipefail

# The phar of the newest release, never of a branch: what reaches a server
# is what someone released.
BASE="${PINGPONG_DIST:-https://github.com/kobalt-digital/pingpong-agent/releases/latest/download}"
URL="${PINGPONG_URL:-}"
KEY="${PINGPONG_KEY:-}"
PHP="${PINGPONG_PHP:-}"

while [ $# -gt 0 ]; do
    case "$1" in
        --url) URL="$2"; shift 2 ;;
        --key) KEY="$2"; shift 2 ;;
        --php) PHP="$2"; shift 2 ;;
        *) echo "Unknown option $1" >&2; exit 1 ;;
    esac
done

if [ -z "$URL" ] || [ -z "$KEY" ]; then
    echo "Usage: install.sh --url https://pingpong.example --key pp_machine_..." >&2
    exit 1
fi

PHP="${PHP:-$(command -v php || true)}"

if [ -z "$PHP" ] || ! "$PHP" -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'; then
    echo "The machine agent needs PHP 8.2 or newer on the command line. Pass --php /path/to/php." >&2
    exit 1
fi

download() {
    if command -v curl >/dev/null; then
        curl -fsSL "$1" -o "$2"
    else
        wget -qO "$2" "$1"
    fi
}

# Where the download waits for its checks; removed however the script ends.
TEMPORARY=""
trap '[ -z "$TEMPORARY" ] || rm -rf "$TEMPORARY"' EXIT

# Downloads the phar into the given directory, checks it against its
# checksum and that it names its version within 30 seconds, as self-update
# does.
fetch_phar() {
    local directory="$1" version
    TEMPORARY="$(mktemp -d)"
    download "$BASE/pingpong-machine.phar" "$TEMPORARY/pingpong-machine.phar"
    download "$BASE/pingpong-machine.phar.sha256" "$TEMPORARY/pingpong-machine.phar.sha256"
    (cd "$TEMPORARY" && sha256sum -c --quiet pingpong-machine.phar.sha256)
    if ! command -v timeout >/dev/null; then
        echo "The installer needs timeout (coreutils) to try the machine agent before installing it." >&2
        exit 1
    fi
    # It has to end well within 30 seconds (a kill a second later if it
    # ignores the first signal) and name its version, as self-update asks.
    if ! version="$(timeout --kill-after=1s 30s "$PHP" "$TEMPORARY/pingpong-machine.phar" version 2>/dev/null)" \
        || ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]]; then
        echo "The downloaded machine agent does not run with $PHP." >&2
        exit 1
    fi
    install -m 0755 "$TEMPORARY/pingpong-machine.phar" "$directory/pingpong-machine.phar"
    rm -rf "$TEMPORARY"
    TEMPORARY=""
}

# Writes the config as JSON, with PHP so the key needs no escaping by hand.
write_config() {
    PINGPONG_URL="$URL" PINGPONG_KEY="$KEY" "$PHP" -r '
        echo json_encode(array_filter([
            "url" => getenv("PINGPONG_URL"),
            "key" => getenv("PINGPONG_KEY"),
            "state_dir" => $argv[1] !== "" ? $argv[1] : null,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    ' "${2:-}" > "$1"
}

if [ "$(id -u)" -eq 0 ]; then
    LIBRARY=/usr/local/lib/pingpong-machine
    CONFIG=/etc/pingpong-machine
    STATE=/var/lib/pingpong-machine

    id pingpong-machine >/dev/null 2>&1 || useradd --system --no-create-home --home-dir "$STATE" --shell /usr/sbin/nologin pingpong-machine

    # The phar belongs to pingpong-machine, in a folder of its own, so the
    # nightly self-update never runs as root: whatever it downloads runs with
    # no more rights than the agent has anyway.
    AGENT="$LIBRARY/agent"
    install -d -m 0755 "$LIBRARY"
    install -d -m 0755 -o pingpong-machine -g pingpong-machine "$AGENT"
    install -d -m 0750 -g pingpong-machine "$CONFIG"
    install -d -m 0750 -o pingpong-machine -g pingpong-machine "$STATE"

    fetch_phar "$AGENT"
    chown pingpong-machine:pingpong-machine "$AGENT/pingpong-machine.phar"

    write_config "$CONFIG/config.json"
    chown root:pingpong-machine "$CONFIG/config.json"
    chmod 0640 "$CONFIG/config.json"

    # A du that may read every file, so the agent can measure home
    # directories closed to others without running as root. That reaches
    # further than folder sizes (du can print what it reads in its errors),
    # so only root and pingpong-machine may run it, and it stays root's.
    install -m 0750 -g pingpong-machine "$(command -v du)" "$LIBRARY/du"

    if command -v setcap >/dev/null; then
        setcap cap_dac_read_search+ep "$LIBRARY/du"
    else
        echo "setcap is missing (apt install libcap2-bin): sites in home directories closed to others are not measured." >&2
    fi

    MINUTE=$((RANDOM % 60))
    cat > /etc/cron.d/pingpong-machine <<CRON
# The machine agent of PingPong: https://github.com/kobalt-digital/pingpong-agent
* * * * * pingpong-machine $PHP $AGENT/pingpong-machine.phar tick >/dev/null 2>&1
$MINUTE 4 * * * pingpong-machine $PHP $AGENT/pingpong-machine.phar self-update >/dev/null 2>&1
CRON
    chmod 0644 /etc/cron.d/pingpong-machine

    echo "Installed for the whole machine. Sending the first tick..."
    runuser -u pingpong-machine -- "$PHP" "$AGENT/pingpong-machine.phar" tick --without-sites && echo "PingPong took it. The sites are measured by the next run of cron."
else
    LIBRARY="$HOME/.local/lib/pingpong-machine"
    CONFIG="$HOME/.config/pingpong-machine"

    install -d -m 0700 "$LIBRARY" "$CONFIG"

    fetch_phar "$LIBRARY"

    (umask 077 && write_config "$CONFIG/config.json")

    MINUTE=$((RANDOM % 60))
    { crontab -l 2>/dev/null | grep -v 'pingpong-machine.phar' || true
      echo "* * * * * $PHP $LIBRARY/pingpong-machine.phar tick >/dev/null 2>&1"
      echo "$MINUTE 4 * * * $PHP $LIBRARY/pingpong-machine.phar self-update >/dev/null 2>&1"
    } | crontab -

    echo "Installed for $(id -un). Sending the first tick..."
    "$PHP" "$LIBRARY/pingpong-machine.phar" tick --without-sites && echo "PingPong took it. The sites are measured by the next run of cron."
fi
