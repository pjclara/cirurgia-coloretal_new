<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Proteção: os testes só correm na base de testes, nunca na de trabalho.
        $base = DB::connection()->getDatabaseName();
        if (! str_ends_with((string) $base, '_teste')) {
            throw new \RuntimeException("Os testes recusam-se a correr na base `$base`. Use `php artisan testes:preparar` e a base *_teste.");
        }
    }
}
