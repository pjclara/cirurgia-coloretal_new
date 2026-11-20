<?php

namespace App\Support;

use Generator;
use Illuminate\Support\Carbon;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Lê uma folha de cálculo (.xlsx ou .csv) linha a linha, sem dependências externas.
 * A primeira linha são os cabeçalhos: cada linha devolve-se como [cabeçalho => valor].
 */
class LeitorDeFolha
{
    /** @return Generator<int, array<string, string|null>> */
    public static function linhas(string $ficheiro): Generator
    {
        if (! is_file($ficheiro)) {
            throw new RuntimeException("Ficheiro não encontrado: $ficheiro");
        }

        $linhas = strtolower(pathinfo($ficheiro, PATHINFO_EXTENSION)) === 'csv' ? self::csv($ficheiro) : self::xlsx($ficheiro);

        $cabecalhos = null;
        foreach ($linhas as $celulas) {
            if ($cabecalhos === null) {
                $cabecalhos = array_map(fn ($c) => trim((string) $c), $celulas);

                continue;
            }
            $linha = [];
            foreach ($cabecalhos as $i => $nome) {
                if ($nome !== '') {
                    $linha[$nome] = isset($celulas[$i]) && $celulas[$i] !== '' ? (string) $celulas[$i] : null;
                }
            }
            yield $linha;
        }
    }

    /** Converte uma data da folha (texto ISO, dd/mm/aaaa ou número de série do Excel) em Y-m-d; null se não for data. */
    public static function data(?string $valor): ?string
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $valor)) {
            return substr($valor, 0, 10);
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $valor, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (is_numeric($valor) && (float) $valor > 20000 && (float) $valor < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $valor))->format('Y-m-d');
        }

        return null;
    }

    private static function csv(string $ficheiro): Generator
    {
        $f = fopen($ficheiro, 'r');
        $primeira = fgets($f);
        rewind($f);
        $separador = substr_count((string) $primeira, ';') > substr_count((string) $primeira, ',') ? ';' : ',';
        while (($linha = fgetcsv($f, 0, $separador)) !== false) {
            yield array_map(fn ($c) => is_string($c) ? preg_replace('/^\xEF\xBB\xBF/', '', $c) : $c, $linha);
        }
        fclose($f);
    }

    private static function xlsx(string $ficheiro): Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($ficheiro) !== true) {
            throw new RuntimeException("Não consegui abrir $ficheiro como .xlsx.");
        }

        $textos = self::textosPartilhados($zip);

        $folha = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $nome) && ($folha === null || strnatcmp($nome, $folha) < 0)) {
                $folha = $nome;
            }
        }
        if ($folha === null) {
            throw new RuntimeException('O ficheiro não tem folhas.');
        }

        $leitor = new XMLReader;
        $leitor->XML(self::semPrefixo((string) $zip->getFromName($folha)));
        while ($leitor->read()) {
            if ($leitor->nodeType !== XMLReader::ELEMENT || $leitor->name !== 'row') {
                continue;
            }
            $linha = [];
            $posicao = 0;
            $celulas = new XMLReader;
            $celulas->XML($leitor->readOuterXml());
            while ($celulas->read()) {
                if ($celulas->nodeType !== XMLReader::ELEMENT || $celulas->name !== 'c') {
                    continue;
                }
                // Alguns exportadores não põem a referência (r="A1") nas células: contam-se pela ordem.
                $referencia = (string) $celulas->getAttribute('r');
                $coluna = $referencia !== '' ? self::indiceDaColuna($referencia) : $posicao;
                $posicao = $coluna + 1;
                $tipo = (string) $celulas->getAttribute('t');
                $no = simplexml_load_string($celulas->readOuterXml());
                $valor = match ($tipo) {
                    's' => $textos[(int) ($no->v ?? 0)] ?? null,
                    'inlineStr' => self::textoInline($no->is ?? null),
                    default => isset($no->v) ? (string) $no->v : null,
                };
                $linha[$coluna] = $valor;
            }
            $celulas->close();

            if ($linha !== []) {
                $maximo = max(array_keys($linha));
                yield array_replace(array_fill(0, $maximo + 1, null), $linha);
            }
        }
        $leitor->close();
        $zip->close();
    }

    /** Alguns exportadores escrevem o XML com prefixo (<x:row>); tira-o para o resto do leitor não depender dele. */
    private static function semPrefixo(string $xml): string
    {
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml); // BOM
        if (preg_match('/<(\w+):worksheet\b/', substr($xml, 0, 2000), $m)) {
            $xml = preg_replace(['/<(\/?)'.$m[1].':/', '/\sxmlns:'.$m[1].'="[^"]*"/'], ['<$1', ''], $xml);
        }

        return $xml;
    }

    /** @return array<int, string> */
    private static function textosPartilhados(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $textos = [];
        $leitor = new XMLReader;
        $leitor->XML($xml);
        while ($leitor->read()) {
            if ($leitor->nodeType === XMLReader::ELEMENT && $leitor->name === 'si') {
                $textos[] = self::textoInline(simplexml_load_string($leitor->readOuterXml()));
            }
        }
        $leitor->close();

        return $textos;
    }

    /** Texto de um <si> ou <is>: um <t> simples ou vários pedaços <r><t>. */
    private static function textoInline($no): string
    {
        if ($no === null) {
            return '';
        }
        if (isset($no->t)) {
            return (string) $no->t;
        }
        $texto = '';
        foreach ($no->r ?? [] as $pedaco) {
            $texto .= (string) $pedaco->t;
        }

        return $texto;
    }

    /** "A1" -> 0, "B7" -> 1, "AA3" -> 26. */
    private static function indiceDaColuna(string $referencia): int
    {
        preg_match('/^[A-Z]+/', strtoupper($referencia), $m);
        $n = 0;
        foreach (str_split($m[0] ?? 'A') as $letra) {
            $n = $n * 26 + (ord($letra) - 64);
        }

        return $n - 1;
    }
}
