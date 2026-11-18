<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\DatabaseTransactions::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Auxiliares clínicos
|--------------------------------------------------------------------------
*/

use App\Models\Agendamento;
use App\Models\Internamento;
use App\Models\ListaDeEspera;
use App\Models\User;
use App\Models\Utente;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/** Utilizador com as permissões indicadas (por omissão, todas as de internamento e agendamento). */
function utilizadorClinico(?array $permissoes = null): User
{
    $permissoes ??= Permission::where('name', 'like', '%internamento%')->orWhere('name', 'like', '%agendamento%')->pluck('name')->all();
    $utilizador = User::factory()->create(['activo' => true]);
    $utilizador->givePermissionTo($permissoes);

    return $utilizador;
}

function novoUtente(array $atributos = []): Utente
{
    static $n = 0;
    $n++;

    return Utente::create($atributos + [
        'nome' => "Utente de Teste $n",
        'numero_processo' => 9000000 + $n,
        'sexo_id' => 1,
        'concelho_id' => DB::table('concelhos')->value('id'),
        'data_nascimento' => '1950-01-01',
    ]);
}

/** Internamento mínimo válido; `$extra` sobrepõe campos. */
function novoInternamento(Utente $utente, User $responsavel, array $extra = []): Internamento
{
    $internamento = new Internamento($extra + [
        'utente_id' => $utente->id,
        'cama' => '1',
        'localizacao_id' => DB::table('localizacaos')->value('id'),
        'origem_do_internamento_id' => DB::table('origem_do_internamentos')->value('id'),
        'responsavel_id' => $responsavel->id,
        'motivo_internamento' => 'Motivo de teste',
        'data_de_entrada' => now()->subDays(10)->format('Y-m-d'),
        'estado_da_alta_id' => Internamento::ALTA_PENDENTE,
    ]);
    $internamento->save();
    $internamento->diagnosticos()->sync([DB::table('diagnosticos')->value('id')]);

    return $internamento;
}

/** Corpo de um pedido de edição válido para o internamento; `$extra` sobrepõe campos. */
function payloadInternamento(Internamento $i, array $extra = []): array
{
    return $extra + [
        'utente_id' => $i->utente_id,
        'cama' => $i->cama,
        'localizacao_id' => $i->localizacao_id,
        'data_de_entrada' => $i->data_de_entrada->format('Y-m-d'),
        'origem_do_internamento_id' => $i->origem_do_internamento_id,
        'responsavel_id' => $i->responsavel_id,
        'motivo_internamento' => $i->motivo_internamento,
        'diagnosticos' => $i->diagnosticos()->pluck('diagnosticos.id')->all(),
        'estado_da_alta_id' => Internamento::ALTA_PENDENTE,
        'morbilidade' => [],
    ];
}

function novaEspera(Utente $utente, User $responsavel): ListaDeEspera
{
    $espera = new ListaDeEspera;
    $espera->utente_id = $utente->id;
    $espera->responsavel_id = $responsavel->id;
    $espera->data_de_lista = now()->subDays(30)->format('Y-m-d');
    $espera->prioridade_id = 1;
    $espera->estado_lista_espera = ListaDeEspera::ESTADO_PENDENTE;
    $espera->save();

    return $espera;
}

/** Agendamento ativo numa sala; `$extra` sobrepõe campos. */
function novoAgendamento(ListaDeEspera $espera, User $responsavel, string $data, array $extra = []): Agendamento
{
    $agendamento = new Agendamento($extra + [
        'start' => "$data 00:00:00",
        'end' => "$data 00:00:00",
        'responsavel_id' => $responsavel->id,
        'tipo_de_agendamento_id' => DB::table('tipo_de_agendamentos')->value('id'),
        'local_de_agendamento_id' => DB::table('local_de_agendamentos')->value('id'),
        'sala_de_agendamento_id' => DB::table('sala_de_agendamentos')->value('id'),
        'periodo_de_agendamento_id' => DB::table('periodo_de_agendamentos')->value('id'),
        'estado_de_agendamento_id' => ListaDeEspera::ESTADO_AGENDADO,
        'estado_de_agendamento' => (string) ListaDeEspera::ESTADO_AGENDADO,
    ]);
    $agendamento->lista_de_espera_id = $espera->id;
    $agendamento->save();

    return $agendamento;
}

/** Regista uma cirurgia no dia de entrada (quem foi operado tem seguimento aos 30 dias). */
function internamentoOperado(object $teste, Internamento $internamento): Internamento
{
    $teste->actingAs($teste->utilizador)->post("/internamentos/{$internamento->id}/cirurgias", [
        'data_de_inicio' => $internamento->data_de_entrada->format('Y-m-d'),
        'tipo_de_cirurgia_id' => DB::table('tipo_de_cirurgias')->value('id'),
        'tipo_de_abordagem_id' => DB::table('tipo_de_abordagems')->value('id'),
        're_intervencao_nao_programada_id' => 1,
        'intervencoes' => [DB::table('intervencaos')->value('id')],
    ])->assertSessionHasNoErrors();

    return $internamento->refresh();
}

/** Consulta de análise por trimestres: do trimestre de há `$dias` dias ao trimestre em curso. */
function trimestresDesde(int $dias): string
{
    $rotulo = fn ($d) => $d->format('Y').' T'.$d->quarter;

    return '?'.http_build_query(['de' => $rotulo(now()->subDays($dias)), 'ate' => $rotulo(now())]);
}

/** Permissões de internamento e agendamento mais a de ver referenciações. */
function permissoesComReferenciacao(): array
{
    return Permission::where('name', 'like', '%internamento%')->orWhere('name', 'like', '%agendamento%')->orWhere('name', 'show referenciacao')->pluck('name')->all();
}
