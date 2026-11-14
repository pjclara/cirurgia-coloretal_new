<?php

namespace App\Models;

use App\Models\Concerns\RegistaAuditoria;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable, RegistaAuditoria;

    protected $fillable = [
        'name',
        'username',
        'abrev',
        'email',
        'activo',
        'equipa',
        'sexo',
        'password',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        'last_login_ip',
    ];

    /** Colunas que não geram registos de auditoria. */
    protected array $naoAuditar = ['last_login_time', 'last_login_ip', 'two_factor_confirmed_at'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_time' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function eAdministrador(): bool
    {
        return $this->hasAnyRole(config('clinico.roles_administracao'));
    }
}
