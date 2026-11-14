# Cirurgia Geral: base + internamento

Primeira entrega da nova aplicação (Laravel 13 + Inertia 3 + React 19), sobre a base de dados `homestead` existente.

Este pacote é uma **camada para copiar por cima de um projeto novo** criado com o starter kit React oficial do Laravel. Não inclui `vendor/` nem `node_modules/`.

> **Estado da verificação.** O código foi escrito sem acesso a rede: não foi possível instalar o Laravel nem correr a aplicação. Foi verificada a sintaxe de todos os ficheiros PHP e os tipos TypeScript contra definições simplificadas do React e do Inertia. As migrações **não foram executadas** contra MySQL. Faça o primeiro arranque numa cópia da base de dados.

## Instalação

```bash
# 1. Projeto novo: escolher "React" e a autenticação incluída do Laravel (Fortify), sem equipas
laravel new cirurgia
cd cirurgia

# 2. Única dependência adicional (as tabelas de roles e permissões já existem na base de dados)
composer require spatie/laravel-permission

# 3. Copiar este pacote por cima do projeto (substitui app/Models/User.php e as 3 migrações por omissão)
unzip -o ../cirurgia-app.zip -d .

# 4. As colunas de 2FA já existem em `users`: apagar a migração do kit que as cria, se existir
rm -f database/migrations/*two_factor*
```

5. Registar o provider em `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\FortifyServiceProvider::class,
    App\Providers\ClinicoServiceProvider::class, // acrescentar
];
```

6. `.env`:

```dotenv
DB_CONNECTION=mysql
DB_DATABASE=cirurgia
DB_USERNAME=...
DB_PASSWORD=...
SESSION_DRIVER=database
```

Em `config/app.php`, pôr `'timezone' => 'Europe/Lisbon'`: as validações de "hoje" dependem do fuso horário.

7. Importar os dados e migrar, **numa cópia** primeiro:

```bash
mysql -u UTILIZADOR -p cirurgia < homestead.sql
php artisan migrate
php artisan permission:cache-reset
php artisan dados:verificar --csv=storage/app/verificacao.csv
npm install && composer run dev
```

Entrar com o email e a password que cada pessoa já usava. A página inicial passa a ser `/quadro`.

## O que as migrações fazem

| Migração | Efeito | Reversível |
|---|---|---|
| `0001_01_01_*` | Substituem as do kit: só criam `sessions`, `cache`, `password_reset_tokens`, `job_batches` se faltarem | Sim |
| `000001_preparar_utilizadores` | Marca as contas com a password por omissão para alteração obrigatória; retira o valor por omissão da coluna | Parcial |
| `000002_limpar_passwords_da_auditoria` | Substitui por `[removido]` os hashes de password guardados em `audits` | **Não** |
| `000003_corrigir_tabelas_de_ligacao` | Copia os órfãos para `registos_orfaos`, remove-os, cria PK e FK em 5 tabelas de ligação | Sim (os órfãos ficam em `registos_orfaos`) |
| `000004_chaves_da_tabela_utentes` | FK e índices de pesquisa em `utentes` | Sim |
| `000005_proteger_registos_ao_apagar_utilizadores` | 28 FK para `users` deixam de apagar em cascata | Sim |

O que **não** é corrigido automaticamente, por exigir decisão clínica: datas com o ano errado, fichas de utente duplicadas, Clavien-Dindo em falta. O comando `dados:verificar` lista esses registos por identificador.

## O que está incluído

- **Quadro do serviço** (`/quadro`): doentes internados por localização e cama, com dias de internamento e filtro por equipa.
- **Utentes**: pesquisa, ficha com histórico de internamentos, criar e editar.
- **Internamentos**: lista com filtros, registo de entrada, diagnósticos, complicações com resolução, alta, bloqueio de registo, apagar (soft delete).
- **Contas**: contas desativadas não entram; alteração obrigatória da password inicial; `/register` responde 404.
- **Auditoria**: internamentos e utentes passam a ser auditados na tabela `audits` existente (trait própria, sem pacote externo).

Permissões usadas (as que já existem): `show internamento`, `create internamento`, `update internamento`, `delete internamento`.

## Regras de validação

- Data de entrada entre `CLINICO_DATA_MINIMA` (por omissão 2021-01-01) e hoje, e nunca anterior ao nascimento.
- Data de alta ≥ entrada; data de saída ≥ alta; nenhuma no futuro.
- Um utente não pode ter dois internamentos sem alta.
- Pelo menos um diagnóstico.
- Para dar alta: destino e complicações obrigatórios ("Sem complicações" conta).
- "Sem complicações" não se combina com outras.
- Com complicação: Clavien-Dindo obrigatório. Óbito com complicação: grau 5 automático. Sem complicação: Clavien-Dindo fica vazio.
- Alterar as datas não pode deixar cirurgias já registadas fora do internamento.
- Utente novo: número de processo e número de utente únicos; número de utente com 9 dígitos.

## Decisões a confirmar

1. **Óbito sem complicação registada** não recebe grau 5 (ex.: doente paliativo não operado). Se preferir grau 5 em todos os óbitos, altera-se em `app/Services/GuardarInternamento.php`.
2. **Estado da alta** continua a ser escolhido à mão; preencher a data de alta apenas propõe "Concluída". Há 252 registos com data de alta e estado "Pendente", o que sugere que o campo pode referir-se à nota de alta e não à saída do doente.
3. **Utentes** usam as permissões de internamento, porque não existem permissões próprias.

## Se o layout aparecer duplicado

As páginas trazem o seu próprio layout (`resources/js/layouts/clinico-layout.tsx`) e declaram `Pagina.layout = (pagina) => pagina` para o kit não aplicar o dele. Se mesmo assim aparecerem dois cabeçalhos, é porque `resources/js/app.tsx` do kit impõe um layout por omissão: exclua aí as páginas `quadro/`, `utentes/`, `internamentos/` e `conta/`.

## Próximas entregas

Bloco operatório, lista de espera e agendamento, centro de referência, indicadores, administração (utilizadores e tabelas de domínio).
