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
