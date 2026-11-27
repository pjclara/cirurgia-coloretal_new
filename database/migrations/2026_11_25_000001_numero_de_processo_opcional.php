<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O n.º de processo deixa de ser obrigatório na base: as importações (lista de referenciação) trazem doentes que ainda
 * não têm processo nem n.º de utente conhecido. A ficha continua a exigi-lo ao ser criada ou editada na aplicação,
 * por isso quem foi importado sem processo completa-se na própria ficha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utentes', function (Blueprint $tabela) {
            $tabela->integer('numero_processo')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Não se volta atrás: pode haver fichas sem processo e a coluna não aceitaria o valor nulo.
    }
};
