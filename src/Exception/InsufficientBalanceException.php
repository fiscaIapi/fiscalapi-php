<?php

declare(strict_types=1);

namespace FiscalApiBr\Exception;

/** Sem plano ativo ou saldo esgotado (HTTP 402). */
class InsufficientBalanceException extends FiscalAPIException
{
    /** Quantas consultas desse tipo o saldo ainda paga, quando informado. */
    public function getConsultasRestantes(): ?int
    {
        $v = \is_array($this->body) ? ($this->body['consultas_restantes'] ?? null) : null;
        return \is_int($v) ? $v : null;
    }
}
