<?php

// Uso: FISCALAPI_API_KEY=... php examples/consultar-ie.php MT 12345678909

require __DIR__ . '/../vendor/autoload.php';

use FiscalApiBr\Exception\FiscalAPIException;
use FiscalApiBr\Exception\InsufficientBalanceException;
use FiscalApiBr\FiscalAPI;

[, $uf, $cpf] = $argv;
$api = new FiscalAPI(); // lê FISCALAPI_API_KEY

try {
    $resp = $api->consultarIe($uf, cpf: $cpf);
} catch (InsufficientBalanceException $e) {
    fwrite(STDERR, 'Sem saldo ou sem plano ativo: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} catch (FiscalAPIException $e) {
    fwrite(STDERR, "Erro {$e->statusCode} {$e->errorCode}: {$e->getMessage()} ({$e->requestId})" . PHP_EOL);
    exit(1);
}

foreach ($resp['results'] as $ie) {
    echo $ie['inscricao_estadual'], ' ', $ie['situacao_ie'] ?? '', ' ', $ie['municipio'] ?? '', PHP_EOL;
}
