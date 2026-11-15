<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Aceita o email completo ou só o nome antes do @ (ver identificador()).
            'email' => ['required', 'string', 'max:255', fn ($atributo, $valor, $falhar) => str_contains((string) $valor, '@') && ! filter_var($valor, FILTER_VALIDATE_EMAIL) ? $falhar('O email não é válido.') : null],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->identificador(), 'password' => $this->string('password')->toString(), 'activo' => true], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * O email com que se tenta entrar: o escrito, ou, se só se escreveu o nome antes do @,
     * o único utilizador cujo email começa por esse nome. Se não houver ou houver mais do que um, devolve o texto
     * tal como está (o início de sessão falha com a mensagem normal, sem dizer se o nome existe).
     */
    private function identificador(): string
    {
        $escrito = trim($this->string('email')->toString());
        if ($escrito === '' || str_contains($escrito, '@')) {
            return $escrito;
        }

        $emails = User::query()->where('email', 'like', addcslashes($escrito, '%_\\').'@%')->limit(2)->pluck('email');

        return $emails->count() === 1 ? $emails->first() : $escrito;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
