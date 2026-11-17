<?php

namespace App\Http\Requests;

use App\Models\Complicacao;
use App\Models\Internamento;
use App\Models\Morbilidade;
use App\Models\Utente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InternamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // a permissão é verificada na rota
    }

    protected function prepareForValidation(): void
    {
        $vazioParaNulo = fn ($valor) => $valor === '' ? null : $valor;

        $this->merge([
            'data_de_alta' => $vazioParaNulo($this->data_de_alta),
            'data_de_saida' => $vazioParaNulo($this->data_de_saida),
            'morbilidade' => collect($this->input('morbilidade', []))
                ->map(fn ($bloco) => is_array($bloco) ? array_merge($bloco, [
                    'clavien_dindo_id' => $vazioParaNulo($bloco['clavien_dindo_id'] ?? null),
                    'data_registo' => $vazioParaNulo($bloco['data_registo'] ?? null),
                ]) : $bloco)
                ->all(),
            'destino_id' => $vazioParaNulo($this->destino_id),
            'caso_social_id' => $vazioParaNulo($this->caso_social_id),
            'cama' => is_string($this->cama) ? trim($this->cama) : $this->cama,
            'motivo_internamento' => is_string($this->motivo_internamento) ? trim($this->motivo_internamento) : $this->motivo_internamento,
            'comentarios' => $this->comentarios ?? '',
        ]);
    }

    public function rules(): array
    {
        $minima = config('clinico.data_minima');

        return [
            'utente_id' => ['required', 'integer', Rule::exists('utentes', 'id')->whereNull('deleted_at')],
            'cama' => ['required', 'string', 'max:20'],
            'localizacao_id' => ['required', 'integer', Rule::exists('localizacaos', 'id')],
            'data_de_entrada' => ['required', 'date_format:Y-m-d', "after_or_equal:$minima", 'before_or_equal:today'],
            'origem_do_internamento_id' => ['required', 'integer', Rule::exists('origem_do_internamentos', 'id')],
            'responsavel_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'motivo_internamento' => ['required', 'string', 'max:255'],
            'caso_social_id' => ['nullable', 'integer', Rule::exists('caso_socials', 'id')],
            'comentarios' => ['nullable', 'string', 'max:20000'],

            'diagnosticos' => ['required', 'array', 'min:1'],
            'diagnosticos.*' => ['integer', 'distinct', Rule::exists('diagnosticos', 'id')],

            // Morbilidade por momento: na alta e aos 30 dias.
            'morbilidade' => ['present', 'array'],
            'morbilidade.*' => ['array'],
            'morbilidade.*.complicacoes' => ['nullable', 'array'],
            'morbilidade.*.complicacoes.*.id' => ['required', 'integer', Rule::exists('complicacaos', 'id')],
            'morbilidade.*.complicacoes.*.resolucoes' => ['nullable', 'array'],
            'morbilidade.*.complicacoes.*.resolucoes.*' => ['integer', Rule::exists('resolucao_complicacaos', 'id')],
            'morbilidade.*.clavien_dindo_id' => ['nullable', 'integer', Rule::exists('clavien_dindos', 'id')],
            'morbilidade.*.data_registo' => ['nullable', 'date_format:Y-m-d', "after_or_equal:$minima", 'before_or_equal:today'],

            'data_de_alta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_de_entrada', 'before_or_equal:today'],
            'data_de_saida' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_de_alta', 'before_or_equal:today'],
            // "Outro" já não se escolhe; só se mantém num registo antigo que ainda o tenha.
            'estado_da_alta_id' => ['required', 'integer', Rule::in(array_filter([
                Internamento::ALTA_PENDENTE,
                Internamento::ALTA_CONCLUIDA,
                $this->route('internamento')?->estado_da_alta_id,
            ]))],
            'destino_id' => ['nullable', 'required_with:data_de_alta', 'integer', Rule::exists('destinos', 'id')],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Internamento|null $internamento */
                $internamento = $this->route('internamento');
                $utente = Utente::find($this->integer('utente_id'));

                // Entrada anterior ao nascimento.
                if ($utente && $utente->data_nascimento && $this->data_de_entrada < $utente->data_nascimento->format('Y-m-d')) {
                    $validator->errors()->add('data_de_entrada', 'A data de entrada é anterior à data de nascimento do utente.');
                }

                // Um utente não pode ter dois internamentos abertos.
                if (! $this->data_de_alta) {
                    $outroAberto = Internamento::query()
                        ->where('utente_id', $this->integer('utente_id'))
                        ->whereNull('data_de_alta')
                        ->when($internamento, fn ($q) => $q->whereKeyNot($internamento->getKey()))
                        ->exists();

                    if ($outroAberto) {
                        $validator->errors()->add('utente_id', 'Este utente já tem um internamento sem alta. Dê alta a esse internamento primeiro.');
                    }
                }

                $this->validarMorbilidade($validator);

                if (! $this->data_de_alta) {
                    if ($this->data_de_saida) {
                        $validator->errors()->add('data_de_saida', 'Preencha a data de alta antes da data de saída.');
                    }
                    if ((int) $this->estado_da_alta_id === Internamento::ALTA_CONCLUIDA) {
                        $validator->errors()->add('estado_da_alta_id', 'A alta só pode ficar concluída depois de preenchida a data de alta.');
                    }
                }

                // As cirurgias já registadas têm de continuar dentro do internamento.
                // Só se verifica quando as datas mudam, para não bloquear a edição de registos antigos.
                $datasMudaram = $internamento && (
                    $internamento->data_de_entrada?->format('Y-m-d') !== $this->data_de_entrada
                    || $internamento->data_de_alta?->format('Y-m-d') !== $this->data_de_alta
                );

                if ($datasMudaram) {
                    $foraDoPeriodo = $internamento->getConnection()->table('bloco_operatorios')
                        ->where('internamento_id', $internamento->getKey())
                        ->whereNull('deleted_at')
                        ->where(function ($q) {
                            $q->where('data_de_inicio', '<', $this->data_de_entrada);
                            if ($this->data_de_alta) {
                                $q->orWhere('data_de_inicio', '>', $this->data_de_alta);
                            }
                        })
                        ->count();

                    if ($foraDoPeriodo > 0) {
                        $validator->errors()->add(
                            'data_de_entrada',
                            "Há $foraDoPeriodo cirurgia(s) deste internamento com data fora do período entre a entrada e a alta."
                        );
                    }
                }
            },
        ];
    }

    /** Cada momento é validado à parte; um momento sem complicações escolhidas conta como não registado. */
    private function validarMorbilidade(Validator $validator): void
    {
        foreach ($this->input('morbilidade', []) as $momento => $bloco) {
            if (! in_array($momento, Morbilidade::MOMENTOS, true)) {
                $validator->errors()->add('morbilidade', 'Momento de morbilidade desconhecido.');

                continue;
            }

            $ids = collect($bloco['complicacoes'] ?? [])->pluck('id')->map(fn ($id) => (int) $id);
            if ($ids->isEmpty()) {
                continue;
            }

            if ($ids->count() !== $ids->unique()->count()) {
                $validator->errors()->add("morbilidade.$momento.complicacoes", 'Há complicações repetidas.');
            }

            $temSem = $ids->contains(Complicacao::SEM_COMPLICACOES);
            $temReal = $ids->contains(fn ($id) => $id !== Complicacao::SEM_COMPLICACOES);

            if ($temSem && $temReal) {
                $validator->errors()->add("morbilidade.$momento.complicacoes", '"Sem complicações" não pode ser escolhida em conjunto com outras complicações.');
            }

            if ($momento === Morbilidade::AOS_30_DIAS && ! $this->data_de_alta) {
                $validator->errors()->add("morbilidade.$momento.complicacoes", 'A morbilidade aos 30 dias só pode ser registada depois da alta.');
            }

            if ($momento === Morbilidade::AOS_30_DIAS && $this->data_de_alta && ! empty($bloco['data_registo']) && $bloco['data_registo'] < $this->data_de_alta) {
                $validator->errors()->add("morbilidade.$momento.data_registo", 'A data do seguimento não pode ser anterior à alta.');
            }
        }

        // Sem cirurgia não é preciso registar complicações: só se exige a morbilidade da alta a quem foi operado.
        $operado = $this->route('internamento')?->temCirurgias() ?? false;
        if ($operado && $this->data_de_alta && collect($this->input('morbilidade.'.Morbilidade::NA_ALTA.'.complicacoes', []))->isEmpty()) {
            $validator->errors()->add('morbilidade.alta.complicacoes', 'Para dar alta, registe as complicações da alta ou escolha "Sem complicações".');
        }
    }

    public function messages(): array
    {
        $minima = date('d/m/Y', strtotime(config('clinico.data_minima')));

        return [
            'required' => 'Preencha :attribute.',
            'required_with' => 'Preencha :attribute.',
            'integer' => ':Attribute tem de ser um número.',
            'max.string' => ':Attribute não pode ter mais de :max caracteres.',
            'date_format' => ':Attribute não é uma data válida.',
            'exists' => 'A opção escolhida em :attribute não existe.',
            'distinct' => 'Há opções repetidas em :attribute.',
            'diagnosticos.required' => 'Escolha pelo menos um diagnóstico.',
            'diagnosticos.min' => 'Escolha pelo menos um diagnóstico.',
            'data_de_entrada.after_or_equal' => "A data de entrada não pode ser anterior a $minima. Confirme o ano.",
            'data_de_entrada.before_or_equal' => 'A data de entrada não pode ser no futuro.',
            'data_de_alta.after_or_equal' => 'A data de alta não pode ser anterior à data de entrada.',
            'data_de_alta.before_or_equal' => 'A data de alta não pode ser no futuro.',
            'data_de_saida.after_or_equal' => 'A data de saída não pode ser anterior à data de alta.',
            'data_de_saida.before_or_equal' => 'A data de saída não pode ser no futuro.',
        ];
    }

    public function attributes(): array
    {
        return [
            'utente_id' => 'o utente',
            'cama' => 'a cama',
            'localizacao_id' => 'a localização',
            'data_de_entrada' => 'a data de entrada',
            'origem_do_internamento_id' => 'a origem',
            'responsavel_id' => 'o responsável',
            'motivo_internamento' => 'o motivo de internamento',
            'caso_social_id' => 'o caso social',
            'comentarios' => 'os comentários',
            'diagnosticos' => 'os diagnósticos',
            'morbilidade' => 'a morbilidade',
            'morbilidade.*.complicacoes.*.id' => 'a complicação',
            'morbilidade.*.clavien_dindo_id' => 'o grau de Clavien-Dindo',
            'morbilidade.*.data_registo' => 'a data do registo',
            'data_de_alta' => 'a data de alta',
            'data_de_saida' => 'a data de saída',
            'estado_da_alta_id' => 'o estado da alta',
            'destino_id' => 'o destino',
        ];
    }
}
