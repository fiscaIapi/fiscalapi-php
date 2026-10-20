<?php

declare(strict_types=1);

namespace FiscalApiBr\Exception;

/** Chave de API ausente, inválida ou revogada (HTTP 401/403). */
class AuthenticationException extends FiscalAPIException
{
}
