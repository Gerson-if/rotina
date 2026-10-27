#!/usr/bin/env bash
# =============================================================================
#  Instalador do Sistema de Verificação de Rotina   (Apache/Nginx + PHP + MySQL)
#
#  Uso simples:   sudo ./install.sh
#  Opções:        --apache  cria o site Apache (porta 80, por IP)
#                 --cli     conclui pelo terminal em vez do navegador
#                 --web-user=USUARIO   usuário do servidor web (detecta sozinho)
#  Não instala pacotes e não mexe no servidor web sem você pedir.
# =============================================================================
set -Eeuo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"
ROOT="$(pwd)"

c_ok=$'\033[32m'; c_warn=$'\033[33m'; c_err=$'\033[31m'; c_b=$'\033[1m'; c_dim=$'\033[2m'; c_cy=$'\033[36m'; c_off=$'\033[0m'
[ -t 1 ] || { c_ok=; c_warn=; c_err=; c_b=; c_dim=; c_cy=; c_off=; }
ok()   { echo "  ${c_ok}✔${c_off} $*"; }
info() { echo "  ${c_dim}$*${c_off}"; }
warn() { echo "  ${c_warn}⚠${c_off} $*" >&2; }
die()  { echo "  ${c_err}✘ $*${c_off}" >&2; exit 1; }
step() { echo; echo "${c_cy}${c_b}[$1/3]${c_off} ${c_b}$2${c_off}"; }

WEB_USER=""; APACHE=0; MODE=web
for a in "$@"; do
  case "$a" in
    --web-user=*) WEB_USER="${a#*=}" ;;
    --apache) APACHE=1 ;;
    --cli) MODE=cli ;;
    --web) MODE=web ;;
    --help|-h) sed -n '2,11p' "$0" | sed 's/^# \{0,2\}//'; exit 0 ;;
    *) die "Opção desconhecida: $a (use --help)" ;;
  esac
done

echo
echo "${c_b}  📋  Sistema de Verificação de Rotina${c_off}  ${c_dim}v$(cat VERSION)${c_off}"
echo "${c_dim}  ───────────────────────────────────────${c_off}"

# ---- 1. servidor -------------------------------------------------------------
step 1 "Verificando o servidor"
PHP="$(command -v php || true)"
[ -n "$PHP" ] || die "PHP não encontrado. Instale: apt install php-cli php-mysql php-mbstring php-zip"
"$PHP" -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' || die "PHP $("$PHP" -r 'echo PHP_VERSION;') é antigo (precisa do 8.0 ou superior)."
MODS="$("$PHP" -m 2>/dev/null)"
has_ext() { grep -qix "$1" <<<"$MODS"; }
for ext in pdo_mysql json; do has_ext "$ext" || die "Falta a extensão PHP '$ext' (apt install php-mysql)."; done
ok "PHP $("$PHP" -r 'echo PHP_VERSION;') com as extensões necessárias"
for ext in mbstring zlib zip; do has_ext "$ext" || warn "Extensão '$ext' ausente (recomendada; 'zip' é usada na atualização pela tela)"; done

# ---- 2. permissões e site ---------------------------------------------------
step 2 "Preparando arquivos"
if [ -z "$WEB_USER" ]; then
  for u in www-data apache nginx http www wwwrun; do id "$u" >/dev/null 2>&1 && { WEB_USER="$u"; break; }; done
fi
if [ "$(id -u)" -eq 0 ]; then
  [ -n "$WEB_USER" ] && id "$WEB_USER" >/dev/null 2>&1 || die "Não achei o usuário do servidor web. Use --web-user=NOME."
  chown -R "$WEB_USER": "$ROOT"
else
  WEB_USER="$(id -un)"
  warn "Sem root não consigo trocar o dono dos arquivos. Use 'sudo ./install.sh' se o site rodar com outro usuário."
fi
find "$ROOT" -type d -exec chmod 755 {} + ; find "$ROOT" -type f -exec chmod 644 {} +
chmod 755 install.sh rotina bin/rotina.php 2>/dev/null || true
chmod 750 config storage; chmod -R u+rwX,g+rX,o-rwx config storage
ok "Permissões ajustadas (usuário do site: $WEB_USER)"

if [ "$APACHE" -eq 0 ] && [ -t 0 ] && [ "$(id -u)" -eq 0 ] && command -v a2ensite >/dev/null 2>&1 && [ ! -e /etc/apache2/sites-enabled/rotina.conf ]; then
  read -r -p "  Apache encontrado. Criar o site do sistema agora (porta 80)? [S/n] " r
  [[ "${r,,}" == n* ]] || APACHE=1
fi
if [ "$APACHE" -eq 1 ]; then
  [ "$(id -u)" -eq 0 ] || die "--apache precisa de root."
  command -v a2ensite >/dev/null 2>&1 || die "a2ensite não encontrado. Em RHEL/Rocky use deploy/apache-ip.conf em /etc/httpd/conf.d/."
  sed "s#/var/www/rotina#$ROOT#g" deploy/apache-ip.conf > /etc/apache2/sites-available/rotina.conf
  a2enmod headers >/dev/null 2>&1 || true
  a2ensite rotina >/dev/null
  apache2ctl configtest >/dev/null 2>&1 || { a2dissite rotina >/dev/null; die "Configuração do Apache inválida; desfeita. Veja: apache2ctl configtest"; }
  systemctl reload apache2 2>/dev/null || apache2ctl graceful
  ok "Site Apache ativado"
  [ -e /etc/apache2/sites-enabled/000-default.conf ] && info "Se abrir a página padrão do Apache, rode: a2dissite 000-default && systemctl reload apache2"
else
  info "Servidor web não alterado. DocumentRoot deve apontar para: $ROOT/public  (modelos em deploy/)"
fi

# ---- 3. instalação ----------------------------------------------------------
step 3 "Concluindo"
if [ -f config/installed.lock ]; then ok "O sistema já está instalado."; exit 0; fi
IP="$( (hostname -I 2>/dev/null || true) | awk '{print $1}')"; IP="${IP:-IP-DO-SERVIDOR}"
if [ "$MODE" = "cli" ]; then
  RUN=("$PHP" "$ROOT/bin/rotina.php" install)
  if [ "$(id -u)" -eq 0 ] && [ "$WEB_USER" != "root" ]; then
    command -v runuser >/dev/null 2>&1 && RUN=(runuser -u "$WEB_USER" -- "$PHP" "$ROOT/bin/rotina.php" install) \
                                       || RUN=(su -s /bin/sh "$WEB_USER" -c "$PHP $ROOT/bin/rotina.php install")
  fi
  "${RUN[@]}"
else
  echo
  echo "  ${c_ok}${c_b}Tudo pronto!${c_off} Abra no navegador para terminar em 1 minuto:"
  echo
  echo "      ${c_b}${c_cy}http://$IP/install.php${c_off}"
  echo
  info "(em subpasta: http://$IP/rotina/install.php)"
fi
echo
info "Depois de entrar, use a aba 'Sistema' para backups e atualizações."
echo
