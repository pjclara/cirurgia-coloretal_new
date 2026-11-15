#!/usr/bin/env bash
# Atualiza a aplicação neste servidor quando há versão nova no GitHub.
# Corre de 5 em 5 minutos pelo cron (ver DOCKER.md). Não precisa de SSH nem de ninguém ligado.
#
# A produção segue o ramo "producao" (por omissão). Para publicar uma versão:
#     git push origin main:producao
#
# Variáveis (opcionais, no ambiente ou no ficheiro .atualizar.env ao lado deste script):
#   RAMO=producao          ramo que o servidor segue
#   AUTO_MIGRAR=true       correr as migrações novas (sempre depois de um backup)
#   BACKUPS=~/backups-cirurgia   onde guardar os backups feitos antes de cada atualização
#   MANTER_DIAS=30         apagar backups mais antigos do que isto
# Uso manual:  ./atualizar.sh          (só atualiza se houver novidades)
#              ./atualizar.sh --forcar (reconstrói mesmo sem novidades)

set -euo pipefail
export PATH="/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin:$PATH"

cd "$(dirname "$(readlink -f "$0")")"
[ -f .atualizar.env ] && . ./.atualizar.env

RAMO="${RAMO:-producao}"
AUTO_MIGRAR="${AUTO_MIGRAR:-true}"
BACKUPS="${BACKUPS:-$HOME/backups-cirurgia}"
MANTER_DIAS="${MANTER_DIAS:-30}"
LOG="${LOG:-$PWD/atualizar.log}"
FORCAR=false
[ "${1:-}" = "--forcar" ] && FORCAR=true

log() { echo "$(date '+%F %T') $*" | tee -a "$LOG"; }

# Só uma execução de cada vez
exec 9>/tmp/cirurgia-atualizar.lock
flock -n 9 || exit 0

git fetch --quiet origin "$RAMO" || { log "ERRO: não consegui contactar o GitHub (git fetch)."; exit 1; }

ANTES="$(git rev-parse HEAD)"
NOVO="$(git rev-parse "origin/$RAMO")"

if [ "$ANTES" = "$NOVO" ] && [ "$FORCAR" = false ]; then
  exit 0   # nada de novo
fi

log "A atualizar ${ANTES:0:7} -> ${NOVO:0:7} (ramo $RAMO)"

# 1) Backup da base de dados. Se falhar, não se atualiza nada.
mkdir -p "$BACKUPS"
FICHEIRO="$BACKUPS/cr_base_$(date +%F_%H%M)_antes_de_${NOVO:0:7}.sql.gz"
if ! docker compose exec -T db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines cr_base' | gzip > "$FICHEIRO" \
   || [ "$(stat -c %s "$FICHEIRO")" -lt 10000 ]; then
  rm -f "$FICHEIRO"
  log "ERRO: o backup falhou; a atualização foi cancelada (nada foi alterado)."
  exit 1
fi
log "Backup guardado em $FICHEIRO"
find "$BACKUPS" -name 'cr_base_*.sql.gz' -mtime +"$MANTER_DIAS" -delete

# 2) Código novo
git checkout --quiet -B "$RAMO" "origin/$RAMO"

# A versão aparece na app (rodapé do menu e página de login)
export APP_VERSION="${NOVO:0:7}"
export APP_BUILD="$(date '+%d/%m/%Y %H:%M')"

# 3) Imagem nova e migrações (com o código novo, antes de a app nova começar a servir)
if ! docker compose build --quiet app; then
  log "ERRO: a construção da imagem falhou; a repor a versão anterior."
  git checkout --quiet -B "$RAMO" "$ANTES"
  exit 1
fi

docker compose up -d db >/dev/null
if [ "$AUTO_MIGRAR" = "true" ]; then
  log "A correr migrações (se houver)..."
  docker compose run --rm app php artisan migrate --force 2>&1 | tee -a "$LOG"
else
  if docker compose run --rm app php artisan migrate:status 2>/dev/null | grep -q Pending; then
    log "AVISO: há migrações pendentes e AUTO_MIGRAR=false. Corra: docker compose exec app php artisan migrate --force"
  fi
fi

# 4) Arrancar a versão nova e confirmar que responde
docker compose up -d >/dev/null
PORTA="$(grep -E '^APP_PORT=' .env 2>/dev/null | cut -d= -f2 || true)"
PORTA="${PORTA:-8080}"
for _ in $(seq 1 30); do
  if curl -fs -o /dev/null "http://127.0.0.1:${PORTA}/up"; then
    log "Atualização concluída: a app responde na porta ${PORTA} (versão ${NOVO:0:7})."
    exit 0
  fi
  sleep 2
done

# 5) Não respondeu: voltar ao código anterior
log "ERRO: a app não respondeu depois da atualização. A repor a versão anterior (${ANTES:0:7})."
git checkout --quiet -B "$RAMO" "$ANTES"
export APP_VERSION="${ANTES:0:7}"
docker compose build --quiet app && docker compose up -d >/dev/null
log "Versão anterior reposta. As migrações NÃO são revertidas; o backup está em $FICHEIRO."
exit 1
