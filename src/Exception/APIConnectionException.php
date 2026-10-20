<?php

declare(strict_types=1);

namespace FiscalApiBr\Exception;

/** Não foi possível falar com a API (DNS, TLS, conexão, timeout). */
class APIConnectionException extends FiscalAPIException
{
}
