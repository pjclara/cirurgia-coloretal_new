<?php

namespace App\Http\Requests;

use App\Models\Utente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UtenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // a permissão é verificada na rota
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nome' => is_string($this->nome) ? Utente::normalizarNome($this->nome) : $this->nome,
            'numero_utente' => $this->numero_utente === '' ? null : $this->numero_utente,
        ]);
    }

    public function rules(): array
    {
        /** @var Utente|null $utente */
        $utente = $this->route('utente');

        $processo = ['required', 'integer', 'min:1', 'max:2147483647'];
        $numeroUtente = ['nullable', 'digits:9'];

        // A unicidade só é exigida em fichas novas ou quando o número muda,
        // para não impedir a edição das fichas duplicadas que já existem.
        if (! $utente || (string) $utente->numero_processo !== (string) $this->numero_processo) {
            $processo[] = Rule::unique('utentes', 'numero_processo')->whereNull('deleted_at');
        }
        if (! $utente || (string) $utente->numero_utente !== (string) $this->numero_utente) {
            $numeroUtente[] = Rule::unique('utentes', 'numero_utente')->whereNull('deleted_at');
        }

        return [
            'nome' => ['required', 'string', 'min:3', 'max:255'],
            'numero_processo' => $processo,
            'numero_utente' => $numeroUtente,
            'data_nascimento' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'sexo_id' => ['required', 'integer', Rule::exists('sexos', 'id')],
            'concelho_id' => ['required', 'integer', Rule::exists('concelhos', 'id')],
            'estado_civil_id' => ['nullable', 'integer', Rule::exists('estado_civils', 'id')],
            'etnia_id' => ['nullable', 'integer', Rule::exists('etnias', 'id')],
            'classe_social_id' => ['nullable', 'integer', Rule::exists('classe_socials', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Preencha :attribute.',
            'integer' => ':Attribute tem de ser um número.',
            'min.string' => ':Attribute tem de ter pelo menos :min caracteres.',
            'max.string' => ':Attribute não pode ter mais de :max caracteres.',
            'digits' => ':Attribute tem de ter :digits dígitos.',
            'date_format' => ':Attribute não é uma data válida.',
            'exists' => 'A opção escolhida em :attribute não existe.',
            'numero_processo.unique' => 'Já existe um utente com este número de processo. Pesquise-o antes de criar uma ficha nova.',
            'numero_utente.unique' => 'Já existe um utente com este número de utente.',
            'data_nascimento.after_or_equal' => 'A data de nascimento não pode ser anterior a 1900.',
            'data_nascimento.before_or_equal' => 'A data de nascimento não pode ser no futuro.',
        ];
    }

    public function attributes(): array
    {
        return [
            'nome' => 'o nome',
            'numero_processo' => 'o número de processo',
            'numero_utente' => 'o número de utente',
            'data_nascimento' => 'a data de nascimento',
            'sexo_id' => 'o sexo',
            'concelho_id' => 'o concelho',
            'estado_civil_id' => 'o estado civil',
            'etnia_id' => 'a etnia',
            'classe_social_id' => 'a classe social',
        ];
    }
}
