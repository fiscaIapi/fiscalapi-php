<?php

declare(strict_types=1);

namespace FiscalApiBr\Exception;

/**
 * Erro base: toda exceção do SDK herda desta classe.
 */
class FiscalAPIException extends \RuntimeException
{
    /**
     * @param int|null              $statusCode Status HTTP (null em falha de conexão).
     * @param string|null           $errorCode  Código do erro devolvido pela API (ex.: PARAMETRO_AUSENTE).
     * @param string|null           $requestId  Identificador da requisição — informe-o ao suporte.
     * @param array|string|null     $body       Corpo da resposta decodificado (array) ou texto.
     * @param array<string, string> $headers    Cabeçalhos da resposta (nomes em minúsculas).
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
        public readonly array|string|null $body = null,
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }
}
