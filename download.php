<?php

// Carrega o autoloader do Composer
require_once __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

echo "Iniciando o script de download do S3...\n";

// --- Configuração ---
// Lemos as variáveis de ambiente (passadas pelo docker-compose)
// Padrão snake_case para variáveis
$nome_bucket = getenv('S3_BUCKET');
$prefixo_s3 = getenv('S3_PREFIX'); // O "lote" ou "pasta" no S3 que você quer baixar
$caminho_download_base = getenv('DOWNLOAD_PATH'); // Onde salvar localmente (dentro do container)
$regiao_aws = getenv('AWS_REGION');
$aws_key = getenv('AWS_ACCESS_KEY_ID');
$aws_secret = getenv('AWS_SECRET_ACCESS_KEY');

// Validação básica
if (empty($nome_bucket) || empty($regiao_aws) || empty($aws_key) || empty($aws_secret) || empty($caminho_download_base)) {
    die("Erro: Variáveis de ambiente essenciais (BUCKET, REGION, KEY, SECRET, DOWNLOAD_PATH) não estão definidas.\n");
}

echo "Configurações carregadas. Conectando ao S3 no bucket: $nome_bucket (Região: $regiao_aws)\n";
if (!empty($prefixo_s3)) {
    echo "Baixando lote do prefixo: $prefixo_s3\n";
}

// --- Inicialização do Cliente S3 ---
// Padrão UPPER_CASE para constantes (embora 'latest' seja string, é uma configuração fixa)
define('VERSAO_SDK', 'latest');

$s3_client = new S3Client([
    'version'     => VERSAO_SDK,
    'region'      => $regiao_aws,
    'credentials' => [
        'key'    => $aws_key,
        'secret' => $aws_secret,
    ],
]);

// --- Lógica de Download ---
$contador_arquivos = 0;

try {
    // Usamos um Paginator para lidar com buckets que têm milhares de arquivos (mais de 1000)
    $params_lista = [
        'Bucket' => $nome_bucket,
        'Prefix' => $prefixo_s3,
    ];

    // getPaginator é um método, usamos camelCase
    $paginador = $s3_client->getPaginator('ListObjectsV2', $params_lista);

    echo "Listando objetos...\n";

    foreach ($paginador as $pagina_resultado) {
        if (empty($pagina_resultado['Contents'])) {
            continue;
        }

        foreach ($pagina_resultado['Contents'] as $objeto) {
            $chave_s3 = $objeto['Key'];

            // Pula "pastas" (objetos que terminam com / e têm tamanho 0)
            if (substr($chave_s3, -1) === '/') {
                continue;
            }

            // Define o caminho completo de destino no sistema de arquivos local
            $caminho_local_destino = $caminho_download_base . '/' . $chave_s3;

            // Garante que a estrutura de diretórios local exista
            $diretorio_local = dirname($caminho_local_destino);
            if (!is_dir($diretorio_local)) {
                // Criamos recursivamente
                if (!mkdir($diretorio_local, 0777, true)) {
                    echo "Erro: Não foi possível criar o diretório: $diretorio_local\n";
                    continue; // Pula este arquivo
                }
            }

            // Tenta baixar o arquivo
            try {
                // getObject é um método, usamos camelCase
                $s3_client->getObject([
                    'Bucket' => $nome_bucket,
                    'Key'    => $chave_s3,
                    'SaveAs' => $caminho_local_destino,
                ]);
                echo "  [OK] Baixado: $chave_s3\n";
                $contador_arquivos++;
            } catch (AwsException $e) {
                echo "  [ERRO] Falha ao baixar $chave_s3: " . $e->getMessage() . "\n";
            }
        }
    }

    if ($contador_arquivos === 0) {
        echo "Nenhum arquivo encontrado para o prefixo: '$prefixo_s3'\n";
    } else {
        echo "\nDownload do lote concluído. Total de $contador_arquivos arquivos baixados.\n";
    }

} catch (AwsException $e) {
    die("Erro fatal ao listar objetos do S3: " . $e->getMessage() . "\n");
}
