#!/usr/bin/env bash
# Parallel Upload for Piwigo - installer / updater
#
#   curl -fsSL https://raw.githubusercontent.com/longblonde/piwigo-parallel-upload/main/install.sh | sudo bash
#   curl -fsSL .../install.sh | sudo bash -s -- --piwigo /var/www/piwigo --replace-web-form
#
# Re-run any time to update to the latest version.

set -euo pipefail

REPO_URL="https://github.com/longblonde/piwigo-parallel-upload.git"
REF="main"
PIWIGO=""
PHP_LIMIT_MB=""           # upload_max_filesize / post_max_size / client_max_body_size (default: plugin's proxy limit, else 100)
DO_PHP=1
DO_WEB=1
DO_ACTIVATE=1
REPLACE_WEB_FORM=""       # "" = leave as is, 1 = on, 0 = off
FORCE=0                   # reload services even while uploads are running
WAIT_MIN=30               # how long to wait for running uploads before reloading
PLUGIN_ID="ParallelUpload"

usage() {
  cat <<EOF
Usage: install.sh [options]

  --piwigo DIR          Piwigo root (auto-detected if omitted)
  --limit MB            PHP + web server request size limit to ensure
                        (default: the plugin's "Proxy request limit" setting, else 100)
  --no-php              don't touch PHP settings
  --no-webserver        don't touch nginx / apache settings
  --no-activate         copy files only; activate from Admin > Plugins yourself
  --replace-web-form    make Parallel Upload replace the core Web Form uploader
  --keep-web-form       turn that option off again
  --wait MIN            wait up to MIN minutes for running uploads before
                        reloading PHP / web server (default: $WAIT_MIN)
  --force               reload right away even if uploads are running
  --ref REF             git branch / tag to install (default: $REF)
  --repo URL            git repository (default: $REPO_URL)
  -h, --help
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --piwigo) PIWIGO="${2%/}"; shift 2 ;;
    --limit) PHP_LIMIT_MB="$2"; shift 2 ;;
    --no-php) DO_PHP=0; shift ;;
    --no-webserver) DO_WEB=0; shift ;;
    --no-activate) DO_ACTIVATE=0; shift ;;
    --replace-web-form) REPLACE_WEB_FORM=1; shift ;;
    --keep-web-form) REPLACE_WEB_FORM=0; shift ;;
    --ref) REF="$2"; shift 2 ;;
    --wait) WAIT_MIN="$2"; shift 2 ;;
    --force) FORCE=1; shift ;;
    --repo) REPO_URL="$2"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1"; usage; exit 1 ;;
  esac
done

c_ok()   { printf '\033[32m✔\033[0m %s\n' "$*"; }
c_info() { printf '\033[36m•\033[0m %s\n' "$*"; }
c_warn() { printf '\033[33m!\033[0m %s\n' "$*"; }
die()    { printf '\033[31m✘ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root (sudo)."
[ -z "$PHP_LIMIT_MB" ] || { [[ "$PHP_LIMIT_MB" =~ ^[0-9]+$ ]] && [ "$PHP_LIMIT_MB" -ge 8 ]; } || die "--limit must be a number of MB (>= 8)."

# ---------------------------------------------------------------- locate Piwigo
is_piwigo() { [ -f "$1/include/constants.php" ] && grep -q "PHPWG_VERSION" "$1/include/constants.php" 2>/dev/null; }

if [ -z "$PIWIGO" ]; then
  for d in /var/www/piwigo /var/www/html/piwigo /var/www/html /srv/piwigo /srv/www/piwigo /usr/share/piwigo /config/www/gallery /app/www/public; do
    if is_piwigo "$d"; then PIWIGO="$d"; break; fi
  done
fi
if [ -z "$PIWIGO" ]; then
  found=$(find /var/www /srv /opt /usr/share /config -maxdepth 4 -path '*/include/constants.php' -exec grep -l PHPWG_VERSION {} + 2>/dev/null | head -n 1 || true)
  [ -n "$found" ] && PIWIGO="$(dirname "$(dirname "$found")")"
fi
[ -n "$PIWIGO" ] && is_piwigo "$PIWIGO" || die "Piwigo not found. Pass --piwigo /path/to/piwigo"

PWG_VERSION=$(sed -n "s/.*PHPWG_VERSION', *'\([^']*\)'.*/\1/p" "$PIWIGO/include/constants.php")
c_ok "Piwigo $PWG_VERSION at $PIWIGO"
case "$PWG_VERSION" in 16.*|17.*) ;; *) c_warn "Built and tested on Piwigo 16.x; continuing anyway." ;; esac

WEB_USER=$(stat -c '%U' "$PIWIGO/_data" 2>/dev/null || stat -c '%U' "$PIWIGO")
WEB_GROUP=$(stat -c '%G' "$PIWIGO/_data" 2>/dev/null || stat -c '%G' "$PIWIGO")
c_info "Files will be owned by $WEB_USER:$WEB_GROUP"

# ---------------------------------------------------------------- fetch plugin
command -v git >/dev/null || {
  c_info "Installing git"
  if command -v apt-get >/dev/null; then apt-get update -qq && apt-get install -y -qq git >/dev/null
  elif command -v apk >/dev/null; then apk add --quiet git
  else die "git is required"; fi
}

DEST="$PIWIGO/plugins/$PLUGIN_ID"
# the checkout is owned by the web user; let root's git work in it
g() { git -c safe.directory="$DEST" -C "$DEST" "$@"; }
if [ -d "$DEST/.git" ]; then
  c_info "Updating existing checkout"
  g remote set-url origin "$REPO_URL"
  g fetch -q --depth 1 origin "$REF"
  g reset -q --hard FETCH_HEAD
elif [ -e "$DEST" ]; then
  BAK="$DEST.bak-$(date +%Y%m%d-%H%M%S)"
  c_warn "$DEST exists but is not a git checkout; moving it to $BAK"
  mv "$DEST" "$BAK"
  git clone -q --depth 1 --branch "$REF" "$REPO_URL" "$DEST"
else
  git clone -q --depth 1 --branch "$REF" "$REPO_URL" "$DEST"
fi
chown -R "$WEB_USER:$WEB_GROUP" "$DEST"
PLUGIN_VERSION=$(sed -n 's/^Version: *//p' "$DEST/main.inc.php" | head -n 1)
c_ok "Plugin files: $DEST (version $PLUGIN_VERSION, commit $(g rev-parse --short HEAD))"

RELOAD=()

# ---------------------------------------------------------------- request size to allow
# Follow the plugin's "Proxy request limit" (Cloudflare Free/Pro: 100 MB) unless --limit was given.
if [ -z "$PHP_LIMIT_MB" ]; then
  PHP_LIMIT_MB=100
  if command -v php >/dev/null; then
    cfg_limit=$(php -r '
      $conf = array(); $prefixeTable = "piwigo_"; define("PHPWG_ROOT_PATH", $argv[1]."/");
      @include $argv[1]."/local/config/database.inc.php";
      if (empty($conf["db_host"])) exit;
      $h = $conf["db_host"]; $port = null; $sock = null;
      if (strpos($h, ":") !== false) { list($h, $x) = explode(":", $h, 2); if (ctype_digit($x)) $port = (int)$x; else $sock = $x; }
      mysqli_report(MYSQLI_REPORT_OFF);
      $db = @new mysqli($h, $conf["db_user"], $conf["db_password"], $conf["db_base"], $port, $sock);
      if ($db->connect_errno) exit;
      $r = $db->query("SELECT value FROM {$prefixeTable}config WHERE param = \"parallel_upload\"");
      $row = $r ? $r->fetch_assoc() : null;
      $c = $row ? json_decode($row["value"], true) : null;
      if (!empty($c["proxy_limit_mb"])) echo (int)$c["proxy_limit_mb"];
    ' "$PIWIGO" 2>/dev/null || true)
    if [[ "$cfg_limit" =~ ^[0-9]+$ ]] && [ "$cfg_limit" -gt "$PHP_LIMIT_MB" ]; then
      PHP_LIMIT_MB=$cfg_limit
      c_info "Using the plugin's proxy request limit: ${PHP_LIMIT_MB} MB"
    fi
  fi
fi

# ---------------------------------------------------------------- PHP limits
to_mb() {   # "100M" / "2G" / "512K" / "104857600" -> MB (integer, rounded down)
  local v="${1^^}"; v="${v// /}"
  case "$v" in
    *G) echo $(( ${v%G} * 1024 )) ;;
    *M) echo "${v%M}" ;;
    *K) echo $(( ${v%K} / 1024 )) ;;
    "" ) echo 0 ;;
    *) echo $(( v / 1048576 )) ;;
  esac
}

to_bytes() {
  local v="${1^^}"
  case "$v" in
    *G) echo $(( ${v%G} * 1073741824 )) ;;
    *M) echo $(( ${v%M} * 1048576 )) ;;
    *K) echo $(( ${v%K} * 1024 )) ;;
    "" ) echo 0 ;;
    *) echo $(( v )) ;;
  esac
}

if [ "$DO_PHP" -eq 1 ]; then
  shopt -s nullglob
  confdirs=(/etc/php/*/fpm/conf.d /etc/php/*/apache2/conf.d /etc/php*/conf.d /usr/local/etc/php/conf.d)
  shopt -u nullglob
  if [ ${#confdirs[@]} -eq 0 ]; then
    c_warn "No PHP conf.d found; set upload_max_filesize and post_max_size to ${PHP_LIMIT_MB}M yourself."
  fi
  for d in "${confdirs[@]}"; do
    [ -d "$d" ] || continue
    ini="$d/99-parallel-upload.ini"
    # Effective values for this SAPI without our own file: its php.ini, then
    # conf.d/*.ini in load order (later wins). FPM pool overrides are checked below.
    inis=()
    [ -f "$(dirname "$d")/php.ini" ] && inis+=("$(dirname "$d")/php.ini")
    for f in "$d"/*.ini; do [ -f "$f" ] && [ "$f" != "$ini" ] && inis+=("$f"); done
    ini_get() {   # last value of a key across the files, empty if never set
      local key="$1" f v out=""
      for f in "${inis[@]}"; do
        v=$(sed -nE "s/^[[:space:]]*${key}[[:space:]]*=[[:space:]]*\"?([^\";[:space:]]*)\"?.*/\1/p" "$f" | tail -n 1)
        [ -n "$v" ] && out="$v"
      done
      echo "$out"
    }
    cur_up=$(to_mb "$(ini_get upload_max_filesize)")
    cur_post=$(to_mb "$(ini_get post_max_size)")
    cur_exec=$(ini_get max_execution_time)
    cur_input=$(ini_get max_input_time)

    # write only what has to go up; never touch a value that is already higher or unlimited
    lines=(); summary=()
    if [ "$cur_up" -lt "$PHP_LIMIT_MB" ]; then
      lines+=("upload_max_filesize = ${PHP_LIMIT_MB}M"); summary+=("upload_max_filesize ${cur_up}M→${PHP_LIMIT_MB}M")
    fi
    # post_max_size 0 means unlimited
    if [ "$(ini_get post_max_size)" != "0" ] && [ "$cur_post" -lt "$PHP_LIMIT_MB" ]; then
      lines+=("post_max_size = ${PHP_LIMIT_MB}M"); summary+=("post_max_size ${cur_post}M→${PHP_LIMIT_MB}M")
    fi
    # max_execution_time: 0 = unlimited (PHP's default when unset is 30)
    exec_now=${cur_exec:-30}
    if [[ "$exec_now" =~ ^[0-9]+$ ]] && [ "$exec_now" -ne 0 ] && [ "$exec_now" -lt 300 ]; then
      lines+=("max_execution_time = 300"); summary+=("max_execution_time ${exec_now}→300")
    fi
    # max_input_time: -1 = same as max_execution_time, 0 = unlimited (default when unset is -1)
    input_now=${cur_input:--1}
    if [[ "$input_now" =~ ^[0-9]+$ ]] && [ "$input_now" -ne 0 ] && [ "$input_now" -lt 300 ]; then
      lines+=("max_input_time = 300"); summary+=("max_input_time ${input_now}→300")
    fi

    if [ ${#lines[@]} -eq 0 ]; then
      if [ -f "$ini" ]; then
        rm -f "$ini"
        c_ok "PHP ($d): existing limits are already high enough; removed $ini"
      else
        c_ok "PHP ($d): existing limits are already high enough; nothing written"
        continue
      fi
    else
      { echo "; Written by Parallel Upload installer: only settings that were lower than needed."
        echo "; Delete this file to undo."
        printf '%s\n' "${lines[@]}"; } > "$ini.new"
      if cmp -s "$ini.new" "$ini"; then
        rm -f "$ini.new"
        c_ok "PHP: $ini already in place"
        continue
      fi
      mv -f "$ini.new" "$ini"
      c_ok "PHP: $ini ($(IFS=,; echo "${summary[*]}" | sed 's/,/, /g'))"
    fi
    [[ "$d" =~ /etc/php/([0-9.]+)/fpm/ ]] && RELOAD+=("php${BASH_REMATCH[1]}-fpm")
    [[ "$d" == */apache2/* ]] && RELOAD+=("apache2")
    [[ "$d" =~ ^/etc/php([0-9]+)/conf.d ]] && RELOAD+=("php-fpm${BASH_REMATCH[1]}")
  done
  # each chunk in flight occupies one PHP-FPM worker
  for pool in /etc/php/*/fpm/pool.d/*.conf; do
    [ -f "$pool" ] || continue
    mc=$(sed -nE 's/^\s*pm\.max_children\s*=\s*([0-9]+).*/\1/p' "$pool" | head -n 1)
    if [ -n "$mc" ]; then
      if [ "$mc" -lt 10 ]; then
        c_warn "$pool: pm.max_children = $mc. Each parallel chunk uses one PHP worker; keep the plugin's"
        c_warn "  max parallel connections at $(( mc > 3 ? mc - 2 : 1 )) or raise pm.max_children (10+ for 8 connections)."
      else
        c_ok "PHP-FPM: pm.max_children = $mc ($pool)"
      fi
    fi
  done
  # PHP as an Apache module: each chunk occupies one Apache worker
  for mpm in /etc/apache2/mods-enabled/mpm_*.conf; do
    [ -f "$mpm" ] || continue
    mrw=$(sed -nE 's/^\s*MaxRequestWorkers\s+([0-9]+).*/\1/p' "$mpm" | head -n 1)
    [ -n "$mrw" ] || continue
    if [ "$mrw" -lt 10 ]; then
      c_warn "$mpm: MaxRequestWorkers = $mrw; raise it to 10+ for 8 parallel connections."
    else
      c_ok "Apache: MaxRequestWorkers = $mrw ($mpm)"
    fi
  done
  # FPM pool files can override conf.d with php_admin_value / php_value
  for pool in /etc/php/*/fpm/pool.d/*.conf /etc/php*/php-fpm.d/*.conf /usr/local/etc/php-fpm.d/*.conf; do
    [ -f "$pool" ] || continue
    while IFS= read -r line; do
      val=$(echo "$line" | sed -E 's/.*=\s*//')
      if [ "$(to_mb "$val")" -lt "$PHP_LIMIT_MB" ]; then
        c_warn "$pool overrides it lower: $(echo "$line" | sed 's/^\s*//')  -> raise to ${PHP_LIMIT_MB}M"
      fi
    done < <(grep -E '^\s*php(_admin)?_value\[(upload_max_filesize|post_max_size)\]' "$pool" || true)
  done
fi

# ---------------------------------------------------------------- web server limits
if [ "$DO_WEB" -eq 1 ]; then
  if command -v nginx >/dev/null && [ -d /etc/nginx ]; then
    low=$(grep -rhoE '^\s*client_max_body_size\s+[0-9]+[kKmMgG]?' /etc/nginx 2>/dev/null \
          | grep -v parallel-upload | awk '{print $2}' || true)
    small=""
    # the largest request the plugin ever sends is a 95 MB chunk plus a few kB of form data
    need=$(( PHP_LIMIT_MB < 96 ? PHP_LIMIT_MB : 96 ))
    for v in $low; do
      b=$(to_bytes "$v"); [ "$b" -gt 0 ] && [ "$b" -lt $(( need * 1000000 )) ] && small="$small $v"
    done
    NGX=/etc/nginx/conf.d/parallel-upload.conf
    NGX_BODY="# Written by Parallel Upload installer (http context). Delete to undo.
client_max_body_size ${PHP_LIMIT_MB}m;"
    if [ -d /etc/nginx/conf.d ] && [ -f "$NGX" ] && [ "$(cat "$NGX")" = "$NGX_BODY" ]; then
      c_ok "nginx: $NGX already in place"
    elif [ -d /etc/nginx/conf.d ]; then
      printf '%s\n' "$NGX_BODY" > "$NGX"
      if nginx -t >/dev/null 2>&1; then
        c_ok "nginx: client_max_body_size ${PHP_LIMIT_MB}m (/etc/nginx/conf.d/parallel-upload.conf)"
        RELOAD+=("nginx")
      else
        # already set in http{} elsewhere -> duplicate directive; drop ours
        rm -f /etc/nginx/conf.d/parallel-upload.conf
        c_warn "nginx already sets client_max_body_size in http{}; left it alone."
      fi
    fi
    if [ -n "$small" ]; then
      c_warn "nginx has smaller client_max_body_size values that may apply to Piwigo:$small"
      grep -rnE '^\s*client_max_body_size' /etc/nginx | grep -v parallel-upload | sed 's/^/    /'
      c_warn "Raise the one in Piwigo's server/location block to ${PHP_LIMIT_MB}m, then: systemctl reload nginx"
    fi
  fi
  if command -v apache2 >/dev/null || command -v httpd >/dev/null; then
    if grep -rqsE '^\s*LimitRequestBody\s+[0-9]+' /etc/apache2 /etc/httpd 2>/dev/null; then
      c_warn "Apache LimitRequestBody is set; make sure it is at least $((PHP_LIMIT_MB*1048576)) bytes:"
      grep -rnsE '^\s*LimitRequestBody' /etc/apache2 /etc/httpd | sed 's/^/    /'
    else
      c_ok "Apache: no LimitRequestBody override (default 1 GiB)"
    fi
  fi
fi

# ---------------------------------------------------------------- reload services
reload_svc() {
  local s="$1"
  if [ -d /run/systemd/system ] && systemctl is-active --quiet "$s" 2>/dev/null; then
    systemctl reload "$s" 2>/dev/null || systemctl restart "$s"
  elif command -v rc-service >/dev/null && rc-service "$s" status >/dev/null 2>&1; then
    rc-service "$s" reload || rc-service "$s" restart
  elif command -v service >/dev/null && service "$s" status >/dev/null 2>&1; then
    service "$s" reload >/dev/null || service "$s" restart >/dev/null
  else
    case "$s" in
      php*fpm*) pkill -USR2 -o -f "php-fpm: master" 2>/dev/null || pkill -USR2 -o php-fpm 2>/dev/null || return 1 ;;
      nginx)    nginx -s reload 2>/dev/null || return 1 ;;
      *)        return 1 ;;
    esac
  fi
}
# A PHP-FPM reload kills requests that are mid-way through PHP (php-fpm's
# default process_control_timeout is 0), e.g. the final step of an upload.
# Wait until no upload has touched Piwigo's buffer for 2 minutes.
uploads_active() {
  local buf="$PIWIGO/upload/buffer"
  [ -d "$buf" ] || return 1
  [ -n "$(find "$buf" -type f ! -name 'index.*' ! -name '*.json' -mmin -2 -print -quit 2>/dev/null)" ] && return 0
  # a Parallel Upload finish still registering a file
  local d
  for d in "$buf"/parallel/pu_*; do
    [ -e "$d/finishing" ] && [ ! -e "$d/result.json" ] && \
      [ -n "$(find "$d/finishing" -mmin -60 -print 2>/dev/null)" ] && return 0
  done
  return 1
}
SKIP_RELOAD=0
if [ ${#RELOAD[@]} -gt 0 ] && [ "$FORCE" -eq 0 ] && uploads_active; then
  c_warn "Uploads are in progress. Waiting for them to finish before reloading (up to $WAIT_MIN min; Ctrl-C to stop, --force to skip)."
  deadline=$(( $(date +%s) + WAIT_MIN * 60 ))
  while uploads_active; do
    if [ "$(date +%s)" -ge "$deadline" ]; then SKIP_RELOAD=1; break; fi
    sleep 15
  done
  [ "$SKIP_RELOAD" -eq 0 ] && c_ok "Uploads idle"
fi
if [ "$SKIP_RELOAD" -eq 1 ]; then
  c_warn "Uploads still running after $WAIT_MIN min; not reloading. New limits apply after: systemctl reload $(printf '%s\n' "${RELOAD[@]}" | sort -u | tr '\n' ' ')"
  RELOAD=()
fi
[ ${#RELOAD[@]} -eq 0 ] && [ "$SKIP_RELOAD" -eq 0 ] && c_ok "No service reload needed"

for s in $(printf '%s\n' "${RELOAD[@]}" | sort -u); do
  if reload_svc "$s"; then c_ok "Reloaded $s"; else c_warn "Could not reload $s - restart it yourself"; fi
done

# ---------------------------------------------------------------- activate in Piwigo's database
if [ "$DO_ACTIVATE" -eq 1 ]; then
  command -v php >/dev/null || die "php CLI not found; re-run with --no-activate and activate from Admin > Plugins"
  php -d display_errors=stderr -r '
    $root = $argv[1]; $id = $argv[2]; $ver = $argv[3]; $replace = $argv[4];
    define("PHPWG_ROOT_PATH", $root."/");
    $conf = array(); $prefixeTable = "piwigo_";
    @include $root."/local/config/database.inc.php";
    if (empty($conf["db_host"])) { fwrite(STDERR, "cannot read local/config/database.inc.php\n"); exit(2); }
    $host = $conf["db_host"]; $port = null; $sock = null;
    if (strpos($host, ":") !== false) { list($host, $p) = explode(":", $host, 2); if (ctype_digit($p)) $port = (int)$p; else $sock = $p; }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
      $db = new mysqli($host, $conf["db_user"], $conf["db_password"], $conf["db_base"], $port, $sock);
    } catch (Throwable $e) {
      fwrite(STDERR, "cannot connect to the database (".$e->getMessage().")\n"); exit(3);
    }
    $db->set_charset("utf8mb4");
    $t = $prefixeTable;
    $row = $db->query("SELECT state, version FROM {$t}plugins WHERE id = \"".$db->real_escape_string($id)."\"")->fetch_assoc();
    if (!$row) {
      $st = $db->prepare("INSERT INTO {$t}plugins (id, state, version) VALUES (?, \"active\", ?)");
      $st->bind_param("ss", $id, $ver); $st->execute();
      echo "activated\n";
    } elseif ($row["state"] != "active") {
      // version left as recorded so Piwigo runs its own update step if the files are newer
      $db->query("UPDATE {$t}plugins SET state = \"active\" WHERE id = \"".$db->real_escape_string($id)."\"");
      echo "re-activated (was ".$row["state"].")\n";
    } else {
      echo "already active (recorded version ".$row["version"].")\n";
    }
    $defaults = array("chunk_size_mb"=>32,"concurrency"=>4,"max_retries"=>6,"verify_sha256"=>true,"stale_hours"=>24,"replace_web_form"=>false);
    $cur = $db->query("SELECT value FROM {$t}config WHERE param = \"parallel_upload\"")->fetch_assoc();
    $cfg = $cur ? (json_decode($cur["value"], true) ?: array()) : array();
    $cfg = array_merge($defaults, $cfg);
    if ($replace !== "") $cfg["replace_web_form"] = ($replace === "1");
    $json = json_encode($cfg);
    $st = $db->prepare("INSERT INTO {$t}config (param, value, comment) VALUES (\"parallel_upload\", ?, \"Parallel Upload plugin\") ON DUPLICATE KEY UPDATE value = VALUES(value)");
    $st->bind_param("s", $json); $st->execute();
    echo "replace_web_form=".($cfg["replace_web_form"] ? "on" : "off")."\n";
  ' "$PIWIGO" "$PLUGIN_ID" "$PLUGIN_VERSION" "$REPLACE_WEB_FORM" > /tmp/pu-activate.$$ 2>&1 && act_ok=1 || act_ok=0
  while read -r line; do
    if [ "$act_ok" -eq 1 ]; then c_ok "Piwigo: $line"; else c_warn "Piwigo: $line"; fi
  done < /tmp/pu-activate.$$
  rm -f /tmp/pu-activate.$$
  if [ "$act_ok" -ne 1 ]; then
    c_warn "Plugin files are in place but it was not activated; activate it in Admin > Plugins."
  fi
  # Piwigo caches its config in the session/template cache; clearing compiled templates is harmless
  rm -rf "$PIWIGO/_data/templates_c/"* 2>/dev/null || true
fi

echo
c_ok "Done. Open  Admin > Photos > Add > Parallel Upload"
[ "$REPLACE_WEB_FORM" = "1" ] && c_info "Photos > Add now opens Parallel Upload (untick in its settings to restore the Web Form)."
c_info "Chunk size defaults to 32 MB; Cloudflare's per-request cap is 100 MB, the plugin never exceeds 95 MB."
