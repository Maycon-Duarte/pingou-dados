<?php
// Gera docs/fiis.json com os fundos do IFIX: preço, proventos, data-com e pagamento.
// Fonte: endpoints públicos da B3. Uso: php atualizar.php

const B3 = 'https://sistemaswebb3-listados.b3.com.br';
const COTACAO = 'https://cotacao.b3.com.br/mds/api/v1/instrumentQuotation/';
const SAIDA = __DIR__ . '/docs/fiis.json';
const VERSAO = 1;
const FALHAS_TOLERADAS = 0.2;

function buscar(string $url): ?array
{
    for ($tentativa = 0; $tentativa < 3; $tentativa++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126 Safari/537.36',
        ]);
        $corpo = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $dados = $http === 200 ? json_decode($corpo, true) : null;
        if (is_array($dados)) {
            return $dados;
        }
        sleep(2);
    }

    return null;
}

function b3(string $caminho, array $parametros): ?array
{
    return buscar(B3 . $caminho . '/' . base64_encode(json_encode($parametros)));
}

function numero(string $valor): float
{
    return (float) str_replace(['.', ','], ['', '.'], $valor);
}

function dataIso(string $data): string
{
    [$dia, $mes, $ano] = explode('/', $data);

    return "$ano-$mes-$dia";
}

$anterior = is_file(SAIDA) ? json_decode(file_get_contents(SAIDA), true)['fundos'] ?? [] : [];
$anterior = array_column($anterior, null, 'ticker');

$ifix = b3('/indexProxy/indexCall/GetPortfolioDay', [
    'language' => 'pt-br', 'pageNumber' => 1, 'pageSize' => 300, 'index' => 'IFIX', 'segment' => '1',
]);

if (count($ifix['results'] ?? []) < 50) {
    fwrite(STDERR, "Carteira do IFIX indisponível. Nada foi alterado.\n");
    exit(1);
}

$fundos = [];
$falhas = [];

foreach ($ifix['results'] as $item) {
    $ticker = trim($item['cod']);

    $suplemento = b3('/fundsProxy/fundsCall/GetListedSupplementFunds', [
        'cnpj' => '0', 'identifierFund' => substr($ticker, 0, 4), 'typeFund' => 7,
    ]);
    $preco = buscar(COTACAO . $ticker)['Trad'][0]['scty']['SctyQtn']['curPrc'] ?? null;

    if (!isset($suplemento['cashDividends']) || !$preco) {
        $falhas[] = $ticker;
        if (isset($anterior[$ticker])) {
            $fundos[] = $anterior[$ticker];
        }
        continue;
    }

    $proventos = [];
    $isin = null;
    foreach ($suplemento['cashDividends'] as $provento) {
        // só a cota principal: recibos de subscrição têm outro ISIN
        if (!preg_match('/CTF\d+$/', $provento['isinCode'])) {
            continue;
        }
        $isin ??= $provento['isinCode'];
        $proventos[] = [
            'tipo' => mb_strtolower(trim($provento['label'])),
            'data_com' => dataIso($provento['lastDatePrior']),
            'pagamento' => dataIso($provento['paymentDate']),
            'valor' => round(numero($provento['rate']), 8),
        ];
    }
    usort($proventos, fn ($a, $b) => strcmp($b['pagamento'], $a['pagamento']));

    $fundos[] = [
        'ticker' => $ticker,
        'isin' => $isin,
        'nome' => trim($suplemento['fund'] ?? $item['asset']),
        'peso_ifix' => numero($item['part']),
        'preco' => (float) $preco,
        'proventos' => $proventos,
    ];

    echo "$ticker ok (" . count($proventos) . " proventos)\n";
    usleep(250000);
}

if (count($falhas) > count($ifix['results']) * FALHAS_TOLERADAS) {
    fwrite(STDERR, 'Falhas demais (' . count($falhas) . "). Nada foi alterado.\n");
    exit(1);
}

usort($fundos, fn ($a, $b) => $b['peso_ifix'] <=> $a['peso_ifix']);

$json = json_encode([
    'versao' => VERSAO,
    'atualizado_em' => gmdate('Y-m-d\TH:i:s\Z'),
    'fonte' => 'B3',
    'fundos' => $fundos,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

file_put_contents(SAIDA . '.tmp', $json);
rename(SAIDA . '.tmp', SAIDA);

echo count($fundos) . ' fundos gravados';
echo $falhas ? ' | mantidos do arquivo anterior ou ausentes: ' . implode(', ', $falhas) . "\n" : "\n";
