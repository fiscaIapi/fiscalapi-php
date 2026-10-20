<?php

declare(strict_types=1);

namespace FiscalApiBr;

use FiscalApiBr\Exception\APIConnectionException;
use FiscalApiBr\Exception\AuthenticationException;
use FiscalApiBr\Exception\BadRequestException;
use FiscalApiBr\Exception\FiscalAPIException;
use FiscalApiBr\Exception\InsufficientBalanceException;
use FiscalApiBr\Exception\RateLimitException;
use FiscalApiBr\Exception\ServerException;

/**
 * Cliente da FiscalAPI. Usa apenas a extensão curl.
 *
 * Exemplo:
 *   $api = new \FiscalApiBr\FiscalAPI('sua-chave');
 *   $resp = $api->consultarIe('MT', cpf: '12345678909');
 */
final class FiscalAPI
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.fiscalapi.com.br';
    /** Algumas consultas (NF-e, SIGEF) podem levar até 180 s no servidor. */
    public const DEFAULT_TIMEOUT = 200;
    public const DEFAULT_MAX_RETRIES = 3;

    private const RETRYABLE_ANY_METHOD = [429];
    private const RETRYABLE_IDEMPOTENT = [500, 502, 503, 504];
    private const MAX_BACKOFF = 30.0;
    private const MAX_RETRY_AFTER = 60.0;

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;
    private string $userAgent;

    /**
     * @param string|null $apiKey     Chave de API. Se nula, lê FISCALAPI_API_KEY do ambiente.
     * @param string      $baseUrl    URL base da API.
     * @param int         $timeout    Tempo máximo de cada tentativa, em segundos.
     * @param int         $maxRetries Novas tentativas em 429 e (para GET) em 5xx/falha de rede.
     */
    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        ?string $userAgent = null,
    ) {
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException('A extensão curl do PHP é obrigatória.');
        }
        $key = $apiKey ?? (getenv('FISCALAPI_API_KEY') ?: null);
        if ($key === null || $key === '') {
            throw new \InvalidArgumentException(
                "Informe a chave de API: new FiscalAPI('sua-chave') ou a variável FISCALAPI_API_KEY."
            );
        }
        $this->apiKey = $key;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->maxRetries = max(0, $maxRetries);
        $ua = 'fiscalapi-br-php/' . self::VERSION;
        $this->userAgent = $userAgent !== null ? $ua . ' ' . $userAgent : $ua;
    }

    // ------------------------------------------------------------------ IE

    /** Inscrição Estadual em uma UF (GET /api/consultar). Informe um entre cpf, cnpj e ie. */
    public function consultarIe(
        string $uf,
        ?string $cpf = null,
        ?string $cnpj = null,
        ?string $ie = null,
        bool $secundarias = false,
    ): array {
        return $this->get('/api/consultar', [
            'uf' => self::uf($uf),
            ...self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj, 'ie' => $ie]),
            'secundarias' => $secundarias ?: null,
        ]);
    }

    /** Inscrição Estadual em todos os estados (GET /api/consultar-ie-todos). */
    public function consultarIeTodos(
        ?string $cpf = null,
        ?string $cnpj = null,
        ?string $ie = null,
        bool $secundarias = false,
    ): array {
        return $this->get('/api/consultar-ie-todos', [
            ...self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj, 'ie' => $ie]),
            'secundarias' => $secundarias ?: null,
        ]);
    }

    // ---------------------------------------------------------------- CNPJ

    /** Dados cadastrais na Receita Federal (GET /api/consultar-cnpj). */
    public function consultarCnpj(string $cnpj, bool $forceFresh = false): array
    {
        if (trim($cnpj) === '') {
            throw new \InvalidArgumentException('Informe o CNPJ.');
        }
        return $this->get('/api/consultar-cnpj', [
            'cnpj' => trim($cnpj),
            'force_fresh' => $forceFresh ?: null,
        ]);
    }

    // ----------------------------------------------------------- Certidões

    /** CND estadual (ICMS) em uma UF (GET /api/consultar-cnd). */
    public function consultarCnd(string $uf, ?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/consultar-cnd', [
            'uf' => self::uf($uf),
            ...self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]),
        ]);
    }

    /** CND estadual em todos os estados disponíveis (GET /api/consultar-cnd-todos). */
    public function consultarCndTodos(?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/consultar-cnd-todos', self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]));
    }

    /** CND Federal — Receita Federal + PGFN (GET /api/consultar-cnd-federal). */
    public function consultarCndFederal(?string $cpf = null, ?string $cnpj = null, bool $emitirNova = false): array
    {
        return $this->get('/api/consultar-cnd-federal', [
            ...self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]),
            'emitir_nova' => $emitirNova ?: null,
        ]);
    }

    /** CNDT — débitos trabalhistas (GET /api/consultar-cndt). */
    public function consultarCndt(?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/consultar-cndt', self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]));
    }

    /** CRF — regularidade do FGTS (GET /api/consultar-crf-fgts). */
    public function consultarCrfFgts(?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/consultar-crf-fgts', self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]));
    }

    /** Todas as certidões em uma chamada (GET /api/certidoes-todas). */
    public function consultarCertidoesTodas(
        ?string $cpf = null,
        ?string $cnpj = null,
        bool $skipEstaduais = false,
    ): array {
        return $this->get('/api/certidoes-todas', [
            ...self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]),
            'skip_estaduais' => $skipEstaduais ?: null,
        ]);
    }

    // ---------------------------------------------------------------- NF-e

    /**
     * XML completo de uma NF-e pela chave de acesso (POST /api/consultar-nfe-xml).
     *
     * @param string $chave       Chave de acesso com 44 dígitos.
     * @param string $certificado Caminho do arquivo .pfx/.p12 ou o conteúdo em base64.
     *                            O certificado não é armazenado pela API.
     * @param string $senha       Senha do certificado.
     * @param bool   $manifestar  Registrar a manifestação do destinatário quando só houver o resumo.
     */
    public function consultarNfeXml(string $chave, string $certificado, string $senha, bool $manifestar = true): array
    {
        if (trim($chave) === '') {
            throw new \InvalidArgumentException('Informe a chave de acesso.');
        }
        return $this->request('POST', '/api/consultar-nfe-xml', [], [
            'chave' => preg_replace('/\D/', '', $chave),
            'certificado' => self::certificadoBase64($certificado),
            'senha' => $senha,
            'manifestar' => $manifestar,
        ]);
    }

    // ------------------------------------------------------- Imóvel rural

    /** Parcelas certificadas no SIGEF/INCRA (GET /api/consultar-sigef). */
    public function consultarSigef(?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/consultar-sigef', self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]));
    }

    /** Uma parcela SIGEF pelo UUID (GET /api/consultar-sigef-parcela). */
    public function consultarSigefParcela(string $uuid, bool $forceFresh = false): array
    {
        if (trim($uuid) === '') {
            throw new \InvalidArgumentException('Informe o UUID da parcela.');
        }
        return $this->get('/api/consultar-sigef-parcela', [
            'uuid' => trim($uuid),
            'force_fresh' => $forceFresh ?: null,
        ]);
    }

    /** Imóveis rurais no CNIR/CAFIR (GET /api/consultar-cnir). */
    public function consultarCnir(?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/consultar-cnir', self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]));
    }

    // ------------------------------------------------------------- Perfil

    /** Perfil consolidado do produtor rural (GET /api/perfil-produtor). */
    public function perfilProdutor(?string $cpf = null, ?string $cnpj = null): array
    {
        return $this->get('/api/perfil-produtor', self::umDocumento(['cpf' => $cpf, 'cnpj' => $cnpj]));
    }

    // ---------------------------------------------------------- Transporte

    /** @param array<string, string|bool|null> $query */
    private function get(string $path, array $query): array
    {
        return $this->request('GET', $path, $query);
    }

    /**
     * @param array<string, string|bool|null> $query
     * @param array<string, mixed>|null       $body
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $path . self::encodeQuery($query);
        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: ' . $this->userAgent,
        ];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        $idempotent = $method === 'GET';

        for ($attempt = 0; ; $attempt++) {
            $responseHeaders = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (\count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return \strlen($line);
                },
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch); // curl_close() é no-op desde o PHP 8.0 e foi depreciado no 8.5

            if ($raw === false || $errno !== 0) {
                if ($idempotent && $attempt < $this->maxRetries) {
                    self::sleep(self::backoff($attempt, null));
                    continue;
                }
                throw new APIConnectionException('Falha ao conectar à FiscalAPI: ' . $error);
            }

            if ($status >= 200 && $status < 300) {
                return self::decodeSuccess((string) $raw);
            }

            $retryable = \in_array($status, self::RETRYABLE_ANY_METHOD, true)
                || ($idempotent && \in_array($status, self::RETRYABLE_IDEMPOTENT, true));
            if ($retryable && $attempt < $this->maxRetries) {
                self::sleep(self::backoff($attempt, $responseHeaders['retry-after'] ?? null));
                continue;
            }
            throw self::errorFromResponse($status, (string) $raw, $responseHeaders);
        }
    }

    // --------------------------------------------------------- utilitários

    /**
     * @param array<string, string|null> $docs
     * @return array<string, string>
     */
    private static function umDocumento(array $docs): array
    {
        $informados = [];
        foreach ($docs as $nome => $valor) {
            if ($valor !== null && trim($valor) !== '') {
                $informados[$nome] = trim($valor);
            }
        }
        $nomes = implode(' ou ', array_keys($docs));
        if (\count($informados) === 0) {
            throw new \InvalidArgumentException("Informe {$nomes}.");
        }
        if (\count($informados) > 1) {
            throw new \InvalidArgumentException("Informe apenas um: {$nomes}.");
        }
        return $informados;
    }

    private static function uf(string $uf): string
    {
        $uf = strtoupper(trim($uf));
        if (\strlen($uf) !== 2) {
            throw new \InvalidArgumentException('Informe a UF com 2 letras (ex.: MT).');
        }
        return $uf;
    }

    /** @param array<string, string|bool|null> $query */
    private static function encodeQuery(array $query): string
    {
        $clean = [];
        foreach ($query as $k => $v) {
            if ($v === null) {
                continue;
            }
            $clean[$k] = \is_bool($v) ? ($v ? 'true' : 'false') : $v;
        }
        return $clean === [] ? '' : '?' . http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }

    private static function certificadoBase64(string $certificado): string
    {
        if (\strlen($certificado) < 4096 && @is_file($certificado)) {
            $bytes = file_get_contents($certificado);
            if ($bytes === false) {
                throw new \RuntimeException("Não foi possível ler o certificado: {$certificado}");
            }
            return base64_encode($bytes);
        }
        return preg_replace('/\s+/', '', $certificado) ?? $certificado;
    }

    private static function backoff(int $attempt, ?string $retryAfter): float
    {
        if ($retryAfter !== null && is_numeric($retryAfter) && (float) $retryAfter >= 0) {
            return min((float) $retryAfter, self::MAX_RETRY_AFTER);
        }
        $base = min(self::MAX_BACKOFF, 0.5 * (2 ** $attempt));
        return $base + (mt_rand() / mt_getrandmax()) * ($base / 4);
    }

    private static function sleep(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }

    private static function decodeSuccess(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FiscalAPIException('Resposta da API não é JSON válido.', body: substr($raw, 0, 500), previous: $e);
        }
        return \is_array($data) ? $data : ['data' => $data];
    }

    /** @param array<string, string> $headers */
    private static function errorFromResponse(int $status, string $raw, array $headers): FiscalAPIException
    {
        $body = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $body = \is_array($decoded) ? $decoded : substr($raw, 0, 500);
        }

        $code = null;
        $message = "Erro HTTP {$status}";
        $requestId = null;
        if (\is_array($body)) {
            $c = $body['error'] ?? $body['title'] ?? null;
            $code = \is_string($c) ? $c : null;
            $detail = $body['message'] ?? $body['detail'] ?? null;
            if (\is_string($detail) && $detail !== '') {
                $message = $detail;
            } elseif (\is_array($detail) && $detail !== []) {
                $message = implode('; ', array_map(
                    static fn ($d) => \is_array($d) && isset($d['msg']) ? (string) $d['msg'] : json_encode($d),
                    $detail,
                ));
            }
            $rid = $body['request_id'] ?? null;
            $requestId = \is_string($rid) && $rid !== '' ? $rid : null;
        } elseif (\is_string($body) && $body !== '') {
            $message = $body;
        }

        $args = [$message, $status, $code, $requestId, $body, $headers];
        return match (true) {
            $status === 401, $status === 403 => new AuthenticationException(...$args),
            $status === 402 => new InsufficientBalanceException(...$args),
            $status === 429 => new RateLimitException(...$args),
            $status >= 500 => new ServerException(...$args),
            $status >= 400 => new BadRequestException(...$args),
            default => new FiscalAPIException(...$args),
        };
    }
}
