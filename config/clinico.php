<?php

return [

    /*
    | Data mais antiga aceite para entradas, altas e cirurgias.
    | Impede erros de digitação no ano (ex.: 0026 em vez de 2026).
    */
    'data_minima' => env('CLINICO_DATA_MINIMA', '2021-01-01'),

    /*
    | Página para onde o utilizador vai depois de iniciar sessão.
    */
    'inicio' => '/quadro',

    /*
    | Roles que podem editar registos bloqueados e desbloqueá-los.
    */
    'roles_administracao' => ['superAdmin', 'admin'],

];
