<?php

declare(strict_types=1);

namespace FiscalApiBr\Exception;

/** Limite de requisições por minuto do plano excedido (HTTP 429). */
class RateLimitException extends FiscalAPIException
{
    /** Segundos a esperar antes de tentar de novo, quando informado. */
    public function getRetryAfter(): ?int
    {
        $raw = $this->headers['retry-after']
            ?? (\is_array($this->body) ? ($this->body['retry_after'] ?? null) : null);
        return is_numeric($raw) ? (int) $raw : null;
    }
}
