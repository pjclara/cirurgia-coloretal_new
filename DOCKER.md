# Correr tudo em Docker

Serviços: `app` (nginx + php-fpm, com os assets já compilados) e `db` (MySQL 8).

```bash
docker compose up -d --build     # arrancar (a primeira vez demora)
docker compose logs -f app       # ver logs
docker compose down              # parar (os dados ficam no volume db_data)
```

- Aplicação: http://localhost:8080 · MySQL exposto em `localhost:3307`
- Configuração: `.env.docker` (fora do git; contém APP_KEY e password da BD).
- **Primeiro arranque**: o MySQL importa `docker/dados/*.sql` (só se o volume estiver vazio). Para repor a BD do zero: `docker compose down -v` e voltar a subir.
- Exportar a BD actual do Laragon para o Docker:
  `mysqldump -uroot --single-transaction cr_base > docker/dados/cr_base.sql` (ficheiros `.sql` não vão para o git).
- Migrações: não correm sozinhas. Para as correr: `docker compose exec app php artisan migrate`.
- Testes: `docker compose exec app php artisan testes:preparar` não funciona na imagem (sem dev); os testes continuam a correr no ambiente local.
- Depois de alterar código: `docker compose up -d --build`.
- Backup: `docker compose exec db sh -c 'mysqldump -uroot -p$MYSQL_ROOT_PASSWORD cr_base' > backup.sql`

## Instalar num servidor

1. Servidor com Docker Engine + plugin Compose e git; clonar o repositório.
2. `cp .env.docker.example .env.docker` e preencher `APP_KEY`, `APP_URL` e a password (em `DB_PASSWORD` **e** `MYSQL_ROOT_PASSWORD`).
3. Copiar a base de dados para `docker/dados/cr_base.sql` (não vai para o git). Exportar no PC com
   `mysqldump -uroot --single-transaction --default-character-set=utf8mb4 cr_base > cr_base.sql` e enviar com `scp`.
4. `docker compose up -d --build`. O MySQL importa o `.sql` só na primeira vez (volume vazio).
5. Abrir `http://SERVIDOR:8080` (ou pôr um proxy HTTPS à frente e usar `TRUSTED_PROXIES=*`, `SESSION_SECURE_COOKIE=true`).
6. Actualizar: `git pull && docker compose up -d --build`; se houver migrações novas, fazer backup e
   `docker compose exec app php artisan migrate --force`.
7. Backup diário: `docker compose exec -T db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" cr_base' | gzip > backup_$(date +%F).sql.gz`.
