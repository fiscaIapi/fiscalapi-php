# FiscalAPI — SDK PHP

Cliente PHP da [FiscalAPI](https://fiscalapi.com.br): Inscrição Estadual nos 27 estados, CNPJ, certidões (CND estadual, CND Federal, CNDT, CRF/FGTS), XML de NF-e por chave, SIGEF, CNIR e perfil do produtor rural.

- PHP 8.1 ou superior, só com a extensão `curl` (nenhum pacote do Composer).
- Autoload PSR-4 (`FiscalApiBr\`).
- Novas tentativas automáticas em `429` e em falhas temporárias.
- Exceções tipadas para chave inválida, falta de saldo e limite de requisições.

## Instalação

```bash
composer require fiscalapi-br/fiscalapi-php
```

## Antes de começar

1. Crie sua conta em [app.fiscalapi.com.br](https://app.fiscalapi.com.br) e copie a chave de API no painel.
2. **O uso pela API exige um plano ativo ou créditos.** As consultas gratuitas do mês valem só no painel; pela API, uma conta sem plano recebe `402` (`InsufficientBalanceException`).
3. Planos e consumo de créditos: [docs.fiscalapi.com.br](https://docs.fiscalapi.com.br/docs/referencia/plans-and-limits).

## Uso rápido

```php
<?php
require 'vendor/autoload.php';

use FiscalApiBr\FiscalAPI;

$api = new FiscalAPI('sua-chave'); // ou defina FISCALAPI_API_KEY

$resp = $api->consultarIe('MT', cpf: '12345678909');
foreach ($resp['results'] as $ie) {
    echo $ie['inscricao_estadual'], ' ', $ie['situacao_ie'], ' ', $ie['municipio'], PHP_EOL;
}
```

## Métodos

Todos os métodos devolvem `array` com o JSON da API. Use argumentos nomeados para o documento.

| Método | Endpoint | O que retorna |
|---|---|---|
| `consultarIe($uf, cpf: \| cnpj: \| ie:, secundarias: false)` | `GET /api/consultar` | Inscrições estaduais em uma UF |
| `consultarIeTodos(cpf: \| cnpj: \| ie:, secundarias: false)` | `GET /api/consultar-ie-todos` | Inscrições em todos os estados |
| `consultarCnpj($cnpj, forceFresh: false)` | `GET /api/consultar-cnpj` | Dados cadastrais da Receita Federal |
| `consultarCnd($uf, cpf: \| cnpj:)` | `GET /api/consultar-cnd` | CND estadual (ICMS) com PDF |
| `consultarCndTodos(cpf: \| cnpj:)` | `GET /api/consultar-cnd-todos` | CND estadual em todos os estados disponíveis |
| `consultarCndFederal(cpf: \| cnpj:, emitirNova: false)` | `GET /api/consultar-cnd-federal` | CND Federal (Receita Federal + PGFN) |
| `consultarCndt(cpf: \| cnpj:)` | `GET /api/consultar-cndt` | Certidão de débitos trabalhistas |
| `consultarCrfFgts(cpf: \| cnpj:)` | `GET /api/consultar-crf-fgts` | Regularidade do FGTS |
| `consultarCertidoesTodas(cpf: \| cnpj:, skipEstaduais: false)` | `GET /api/certidoes-todas` | Todas as certidões em uma chamada |
| `consultarNfeXml($chave, $certificado, $senha, manifestar: true)` | `POST /api/consultar-nfe-xml` | XML completo da NF-e |
| `consultarSigef(cpf: \| cnpj:)` | `GET /api/consultar-sigef` | Parcelas certificadas no SIGEF/INCRA |
| `consultarSigefParcela($uuid, forceFresh: false)` | `GET /api/consultar-sigef-parcela` | Uma parcela com polígono, vértices e confrontantes |
| `consultarCnir(cpf: \| cnpj:)` | `GET /api/consultar-cnir` | Imóveis rurais no CNIR/CAFIR |
| `perfilProdutor(cpf: \| cnpj:)` | `GET /api/perfil-produtor` | Perfil consolidado do produtor |

Informe **um** documento por chamada. Pontuação é aceita.

## Exemplos

### Inscrição Estadual em todos os estados

```php
$resp = $api->consultarIeTodos(cnpj: '12.345.678/0001-95');
if (($resp['complete'] ?? true) === false) {
    echo "Algum estado não respondeu; tente de novo antes de concluir que não há IE.\n";
}
foreach ($resp['results'] as $uf) {
    foreach ($uf['results'] as $ie) {
        echo $uf['uf'], ' ', $ie['inscricao_estadual'], ' ', $ie['situacao_ie'], PHP_EOL;
    }
}
```

### CNPJ

```php
$empresa = $api->consultarCnpj('12345678000195');
echo $empresa['result']['razao_social'], ' — ', $empresa['result']['situacao_cadastral'], PHP_EOL;

// Dado atual, sem cache (consome mais créditos):
$api->consultarCnpj('12345678000195', forceFresh: true);
```

### Certidões com PDF

```php
$cnd = $api->consultarCnd('MT', cpf: '12345678909');
echo $cnd['result']['status'], ' até ', $cnd['result']['validade_iso'], PHP_EOL;
if (!empty($cnd['result']['pdf_base64'])) {
    file_put_contents('cnd-mt.pdf', base64_decode($cnd['result']['pdf_base64']));
}

$federal = $api->consultarCndFederal(cnpj: '12345678000195');
$trabalhista = $api->consultarCndt(cnpj: '12345678000195');
$fgts = $api->consultarCrfFgts(cnpj: '12345678000195');
```

O campo `status` vem como `negativa`, `positiva`, `positiva_com_efeitos_de_negativa`, `nao_contribuinte`, `sem_certidao` ou `erro`.

### XML de NF-e por chave

```php
$resp = $api->consultarNfeXml(
    '35260112345678000195550010000012341000012345',
    'certificado.pfx',           // caminho do arquivo ou conteúdo em base64
    'senha-do-certificado',
);
$nota = $resp['resultados'][0];
if ($nota['status'] === 'ok') {
    file_put_contents($nota['chave'] . '.xml', $nota['xml']);
} else {
    echo $nota['status'], ': ', $nota['mensagem'], PHP_EOL;
}
```

O certificado A1 precisa ser de um participante da nota (emitente, destinatário, transportador ou autorizado) e **não é armazenado** pela API. Só há cobrança quando o XML é devolvido.

### Imóvel rural

```php
$sigef = $api->consultarSigef(cpf: '12345678909');
foreach ($sigef['result']['parcelas'] ?? [] as $p) {
    echo $p['denominacao'], ' — ', $p['area_ha'], ' ha — ', $p['municipio'], '/', $p['uf'], PHP_EOL;
}

$parcela = $api->consultarSigefParcela('0f1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b');
echo $parcela['result']['geometria_wkt'], PHP_EOL;

$cnir = $api->consultarCnir(cpf: '12345678909');
echo $cnir['total_imoveis'], " imóveis\n";
```

### Perfil do produtor

```php
$perfil = $api->perfilProdutor(cpf: '12345678909');
```

## Status da fonte

Uma resposta `200` pode trazer `source_status.status` diferente de `success` (por exemplo, `timeout` quando o órgão não respondeu a tempo). Confira esse campo antes de concluir que o documento não tem registro.

## Erros

```php
use FiscalApiBr\Exception\{
    FiscalAPIException, AuthenticationException, InsufficientBalanceException,
    RateLimitException, BadRequestException, ServerException, APIConnectionException
};

try {
    $api->consultarIe('SP', cnpj: '12345678000195');
} catch (AuthenticationException $e) {
    // chave ausente, inválida ou revogada (401/403)
} catch (InsufficientBalanceException $e) {
    echo $e->getConsultasRestantes();    // sem plano ou saldo esgotado (402)
} catch (RateLimitException $e) {
    echo $e->getRetryAfter();            // limite por minuto do plano (429)
} catch (BadRequestException $e) {
    echo $e->errorCode, ': ', $e->getMessage(); // parâmetro ausente ou inválido (400/422)
} catch (FiscalAPIException $e) {
    echo $e->statusCode, ' ', $e->requestId;    // informe o requestId ao suporte
}
```

## Novas tentativas e timeout

```php
$api = new FiscalAPI('sua-chave', timeout: 200, maxRetries: 3);
```

- `429`: o SDK espera o tempo do cabeçalho `Retry-After` e tenta de novo.
- `500`, `502`, `503`, `504` e falhas de rede: novas tentativas com espera exponencial, **só em consultas GET**. A consulta de NF-e (POST) não é repetida automaticamente.
- Algumas consultas (NF-e, SIGEF) podem levar até 3 minutos; o timeout padrão é 200 s. Ajuste também o `max_execution_time` do PHP, se for o caso.

## Links

- Documentação: [docs.fiscalapi.com.br](https://docs.fiscalapi.com.br)
- Painel: [app.fiscalapi.com.br](https://app.fiscalapi.com.br)
- Suporte: contato@fiscalapi.com.br

## Licença

MIT
