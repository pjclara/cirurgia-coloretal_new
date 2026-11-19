# Servidor – lembrete rápido

Notas para não me esquecer. Mais detalhe em `DOCKER.md`.

## Como está montado

- A app corre em Docker, **na pasta onde fiz o `git clone`** (a do `docker-compose.yml` do projeto, não a da outra stack que já lá existe). Para ver o caminho: `pwd` dentro dessa pasta.
- Serviços do projeto (nome `cirurgia`): `app` (nginx + php-fpm), `db` (MySQL 8), `phpmyadmin`.
- Portas: **app 8082** · **phpMyAdmin 8083** · MySQL só interno (`127.0.0.1:3307`). A 8080 e a 8081 são da outra stack.
- Ficheiros só do servidor (não vão para o git):
  - `.env` → `COMPOSE_PROJECT_NAME=cirurgia`, `APP_PORT=8082`, `PMA_BIND`, `PMA_PORT`
  - `.env.docker` → `APP_KEY`, `APP_URL`, `DB_PASSWORD` e `MYSQL_ROOT_PASSWORD` (a mesma password nas duas)
  - `docker-compose.override.yml` → sub-rede própria (`172.241.110.0/24`), por causa do erro "all predefined address pools have been fully subnetted"
- Os dados ficam nos volumes do Docker (`db_data`, `app_storage`), por isso sobrevivem às atualizações.

## Pôr a base de dados original em uso

Antes de apontar a aplicação à base de dados original (ou de repor uma cópia dela):

```bash
# 1) backup (ver acima) e, de preferência, ensaiar numa cópia
# 2) conferir que a estrutura e os registos de referência de que a app depende existem (só leitura)
docker compose exec app php artisan bd:verificar-estrutura
# 3) ver o que vai correr e correr
docker compose exec app php artisan migrate:status | grep Pending
docker compose exec app php artisan migrate --force
# 4) listar registos que precisam de correção manual (só leitura)
docker compose exec app php artisan dados:verificar
```

O que as migrações recentes fazem à base de dados (todas reversíveis e repetíveis; correm em ordem):

| Migração | Efeito |
|---|---|
| `…_clavien_da_alta_passa_a_definitivo` | O grau de Clavien-Dindo passa a ser o definitivo aos 30 dias |
| `…_associar_diagnosticos_cr_as_referenciacoes` | Copia os diagnósticos «centro de referência» dos internamentos para a referenciação ativa de cada utente (modo «Outro», histologia por preencher) |
| `…_remover_permissoes_sem_uso` | Apaga as permissões que a app já não verifica (as `edit …` e as de diagnóstico, intervenção, complicação, resolução e reunião) |
| `…_data_consulta_seguinte_na_referenciacao` | Acrescenta a data da consulta seguinte à referenciação (campo opcional) |
| `…_utentes_com_dados_por_completar` | Permite utentes sem data de nascimento, sexo e concelho (a completar na ficha; continuam obrigatórios ao guardar). Necessária para a importação da triagem |
| `…_importar_triagem_cruzada` | Referencia ao centro de referência os doentes de `excels/triagem_proxima_consulta.csv` (por número de processo), **cria os utentes que não existem na base** (só nome e processo) e preenche a data da consulta seguinte. `php artisan dados:verificar` lista os utentes por completar. **O ficheiro não está no git**: copiar para `excels/` no servidor antes de reconstruir a imagem e migrar; sem ele não faz nada. O que ficou por importar vai para `storage/app/private/importacoes/` |

Depois de migrar, em **Administração > Perfis e permissões** (superAdmin) conferir o que cada perfil pode fazer.
Com `AUTO_MIGRAR=true` o `atualizar.sh` já faz o backup e corre as migrações a cada versão publicada.

## Atualizar a app (manual)

No servidor, na pasta do projeto:

```bash
# 1) backup antes de tudo
docker compose exec -T db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction cr_base' | gzip > backup_$(date +%F_%H%M).sql.gz

# 2) código novo + reconstruir (com o número da versão)
git pull
APP_VERSION=$(git rev-parse --short HEAD) APP_BUILD="$(date '+%d/%m/%Y %H:%M')" docker compose up -d --build

# 3) só se a atualização trouxer migrações novas
docker compose exec app php artisan migrate:status | grep Pending
docker compose exec app php artisan migrate --force
```

- Confirmar: a página de login mostra **"Versão xxxxxxx"** igual ao último `git log --oneline -1`.
- Atualizar reconstrói a imagem e **termina as sessões abertas** (todos têm de voltar a entrar). Melhor fora das horas de maior uso.
- Ver logs: `docker compose logs -f app` · `docker compose ps`

## Publicar uma versão (no meu PC)

```powershell
git add . ; git commit -m "mensagem" ; git push origin main
git push origin main:producao      # só quando quiser que o servidor possa seguir esta versão
```

O servidor, por enquanto, atualiza-se à mão com `git pull`. Para ser automático: `atualizar.sh` + `crontab` (instruções em `DOCKER.md`, secção "Atualização automática").

## phpMyAdmin

- Abrir `http://IP-DO-SERVIDOR:8083` (se `PMA_BIND=0.0.0.0` no `.env`) ou, só no servidor, por túnel SSH: `ssh -L 8083:127.0.0.1:8083 utilizador@servidor` e `http://localhost:8083`.
- Utilizador `root`; password = `MYSQL_ROOT_PASSWORD` do `.env.docker` (`grep MYSQL_ROOT_PASSWORD .env.docker`).
- ⚠️ Mexer na BD por aqui salta as regras da app (ex.: alta pendente com cirurgia). Backup antes de alterações em massa.

## Repor um backup

```bash
gunzip -c backup_AAAA-MM-DD_HHMM.sql.gz | docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" cr_base'
```

## Se algo correr mal

| Sintoma | O que fazer |
|---|---|
| A 8082 não aparece | `docker compose ps -a` e `docker compose logs app --tail 50` |
| `db` não fica "healthy" | `docker compose logs db --tail 50` (password vazia no `.env.docker`? erro no `.sql`?) |
| "port is already allocated" | A porta está ocupada: mudar `APP_PORT` no `.env` |
| `invalid hostPort` | O `.env` está mal formatado (uma linha por variável, cada uma na sua linha) |
| Página 500 | `docker compose exec app tail -n 50 storage/logs/laravel.log` |
| Página "419 Page expired" | Recarregar a página de login (acontece logo a seguir a uma atualização) |
| Voltar à versão anterior | `git log --oneline`, `git checkout <commit>`, depois o comando de reconstruir; as migrações já feitas **não** se revertem (usar o backup) |

## Segurança (a lembrar à informática)

- Fazer HTTPS (proxy com certificado) e pôr `TRUSTED_PROXIES=*` e `SESSION_SECURE_COOKIE=true` no `.env.docker`.
- Firewall: 8082 só para a rede da ULS; 8083 (phpMyAdmin) só para o IP de quem gere.
- A stack antiga tem MySQL `3306` com `root/secret` e phpMyAdmin `8081` com `PMA_ARBITRARY`: mudar a password e restringir o acesso.
- Backups diários, guardados **fora** do servidor.

## Cruzar o Excel do bloco operatório com os agendamentos

Comando `agendamentos:cruzar-bloco` (lê `.xlsx` ou `.csv` com as colunas `NUM_PROCESSO` e `DTA_INTERVENCAO`).
Por omissão **só mostra** o que mudaria; com `--aplicar` grava (agendamento e lista de espera passam a "Operado") e guarda um CSV do que mudou em `storage/app/private/`.
Tolerância por omissão: 1 dia (`--tolerancia=N`). Cancelados e agendamentos que o ficheiro não confirma ficam como estão.

```bash
docker compose cp /caminho/bloco_2022_2026.xlsx app:/tmp/bloco.xlsx
docker compose exec app php artisan agendamentos:cruzar-bloco /tmp/bloco.xlsx            # simulação
# backup da BD, e depois:
docker compose exec app php artisan agendamentos:cruzar-bloco /tmp/bloco.xlsx --aplicar
docker compose exec app rm /tmp/bloco.xlsx                                               # o ficheiro tem dados de doentes
```
