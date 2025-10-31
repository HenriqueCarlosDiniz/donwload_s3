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

// Nova variável para palavras-chave
$palavras_chave_str = getenv('S3_KEYWORDS'); // Ex: "relatorio,fatura,importante"

// Novas variáveis de ambiente para o MySQL
$db_host = getenv('DB_HOST');
$db_user = getenv('DB_USERNAME');
$db_pass = getenv('DB_PASSWORD');
$db_name = getenv('DB_DATABASE');
$db_port = getenv('DB_PORT') ?: '3306'; // Porta padrão do MySQL

// Validação básica
if (empty($nome_bucket) || empty($regiao_aws) || empty($aws_key) || empty($aws_secret) || empty($caminho_download_base)) {
    die("Erro: Variáveis de ambiente essenciais (BUCKET, REGION, KEY, SECRET, DOWNLOAD_PATH) não estão definidas.\n");
}

// Validação das variáveis de banco
if (empty($db_host) || empty($db_user) || empty($db_pass) || empty($db_name)) {
    die("Erro: Variáveis de ambiente do banco de dados (DB_HOST, DB_USER, DB_PASS, DB_NAME) não estão definidas.\n");
}

echo "Configurações carregadas. Conectando ao S3 no bucket: $nome_bucket (Região: $regiao_aws)\n";
if (!empty($prefixo_s3)) {
    echo "Baixando lote do prefixo: $prefixo_s3\n";
}

// Processa as palavras-chave
$palavras_chave_filtro = [];
if (!empty($palavras_chave_str)) {
    // 1. Divide a string pela vírgula
    $palavras_chave_filtro = explode(',', $palavras_chave_str);
    // 2. Remove espaços em branco de cada item
    $palavras_chave_filtro = array_map('trim', $palavras_chave_filtro);
    // 3. Remove quaisquer itens vazios (ex: "a,,b")
    $palavras_chave_filtro = array_filter($palavras_chave_filtro);

    if (count($palavras_chave_filtro) > 0) {
        echo "Filtrando por " . count($palavras_chave_filtro) . " palavras-chave: " . implode(', ', $palavras_chave_filtro) . "\n";
    }
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

// --- Conexão e Configuração do MySQL ---

/**
 * Tenta conectar ao MySQL com retentativas,
 * essencial em ambientes Docker onde o app pode iniciar antes do DB.
 */
function conectar_mysql($host, $user, $pass, $db, $port, $tentativas = 10, $delay_segundos = 3) {
    for ($i = 1; $i <= $tentativas; $i++) {
        try {
            // Usamos mysqli, que precisa da extensão no Dockerfile
            $mysqli = new mysqli($host, $user, $pass, $db, (int)$port);
            if ($mysqli->connect_error) {
                throw new Exception($mysqli->connect_error);
            }
            echo "Conexão MySQL estabelecida com sucesso!\n";
            return $mysqli;
        } catch (Exception $e) {
            echo "Tentativa $i/$tentativas: Falha ao conectar ao MySQL ({$e->getMessage()}). Aguardando $delay_segundos s...\n";
            sleep($delay_segundos);
        }
    }
    die("Erro fatal: Não foi possível conectar ao banco de dados após $tentativas tentativas.\n");
}

/**
 * Cria a tabela de logs se ela não existir.
 */
function criar_tabela_logs($mysqli) {
    $query_criar_tabela = "
    CREATE TABLE IF NOT EXISTS arquivos_baixados (
        id INT AUTO_INCREMENT PRIMARY KEY,
        s3_key VARCHAR(1024) NOT NULL,
        s3_last_modified TIMESTAMP NULL,
        data_download TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

        -- CORREÇÃO AQUI: Especificamos um prefixo de 767 caracteres para a chave --
        UNIQUE KEY idx_s3_key (s3_key(767))
    );";

    if (!$mysqli->query($query_criar_tabela)) {
        die("Erro ao criar tabela de logs 'arquivos_baixados': " . $mysqli->error . "\n");
    }
    echo "Tabela 'arquivos_baixados' verificada/criada.\n";
}

// --- Funções Auxiliares do Banco ---

/**
 * Verifica se a chave S3 já existe no banco.
 * (Padrão camelCase para funções)
 */
function arquivoJaBaixado($mysqli, $s3_key) {
    // Usamos prepared statements para segurança
    $stmt = $mysqli->prepare("SELECT id FROM arquivos_baixados WHERE s3_key = ?");
    $stmt->bind_param("s", $s3_key);
    $stmt->execute();
    $stmt->store_result();
    $count = $stmt->num_rows;
    $stmt->close();
    return $count > 0;
}

/**
 * Registra a chave S3 no banco após o download.
 * (Padrão camelCase para funções)
 *
 * @param mysqli $mysqli A conexão com o banco
 * @param string $s3_key A chave do objeto no S3
 * @param \DateTime|null $s3_last_modified A data de última modificação do S3
 */
function registrarArquivoBaixado($mysqli, $s3_key, $s3_last_modified) {

    // Formata a data para o formato DATETIME do MySQL
    // O SDK retorna um objeto DateTime
    $data_formatada = $s3_last_modified ? $s3_last_modified->format('Y-m-d H:i:s') : null;

    // Insere ou atualiza (caso já exista, apenas por segurança)
    $stmt = $mysqli->prepare("INSERT INTO arquivos_baixados (s3_key, s3_last_modified) VALUES (?, ?)
                              ON DUPLICATE KEY UPDATE
                              data_download = CURRENT_TIMESTAMP,
                              s3_last_modified = VALUES(s3_last_modified)");

    // "ss" = string (s3_key), string (data_formatada)
    $stmt->bind_param("ss", $s3_key, $data_formatada);
    $stmt->execute();
    $stmt->close();
}


// --- Inicialização do DB ---
$mysqli_conn = conectar_mysql($db_host, $db_user, $db_pass, $db_name, $db_port);
criar_tabela_logs($mysqli_conn);


// --- Lógica de Download ---
$contador_arquivos = 0;
$contador_arquivos_filtrados = 0;

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
            // Captura a data de modificação do S3
            $data_modificacao_s3 = $objeto['LastModified'];

            // Pula "pastas" (objetos que terminam com / e têm tamanho 0)
            if (substr($chave_s3, -1) === '/') {
                continue;
            }

            // --- LÓGICA DE FILTRO DE PALAVRA-CHAVE ---
            // Se definimos palavras-chave, aplicamos o filtro
            if (count($palavras_chave_filtro) > 0) {
                $encontrou_palavra_chave = false;
                foreach ($palavras_chave_filtro as $palavra) {
                    // Usamos stripos para busca case-insensitive (não diferencia maiúsculas/minúsculas)
                    if (stripos($chave_s3, $palavra) !== false) {
                        $encontrou_palavra_chave = true;
                        break; // Encontrou, não precisa checar as outras palavras
                    }
                }

                // Se nenhuma palavra-chave foi encontrada no nome do arquivo, pula
                if (!$encontrou_palavra_chave) {
                    // echo "  [FILTRADO] Não contém palavra-chave: $chave_s3\n"; // Descomente para debug
                    $contador_arquivos_filtrados++;
                    continue; // Pula este arquivo
                }
            }
            // --- FIM DO FILTRO ---


            // --- VERIFICAÇÃO NO BANCO ---
            if (arquivoJaBaixado($mysqli_conn, $chave_s3)) {
                echo "  [SKIP] Já baixado (registrado no DB): $chave_s3\n";
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

                // --- REGISTRO NO BANCO ---
                // Passa a chave S3 e a data de modificação
                registrarArquivoBaixado($mysqli_conn, $chave_s3, $data_modificacao_s3);

                $contador_arquivos++;
            } catch (AwsException $e) {
                echo "  [ERRO] Falha ao baixar $chave_s3: " . $e->getMessage() . "\n";
            }
        }
    }

    if (count($palavras_chave_filtro) > 0) {
        echo "\nFiltragem concluída. $contador_arquivos_filtrados arquivos não corresponderam às palavras-chave.\n";
    }

    if ($contador_arquivos === 0) {
        echo "Nenhum arquivo novo (que corresponde ao filtro) foi baixado.\n";
    } else {
        echo "\nDownload do lote concluído. Total de $contador_arquivos arquivos baixados.\n";
    }

} catch (AwsException $e) {
    die("Erro fatal ao listar objetos do S3: " . $e->getMessage() . "\n");
}

// Fecha a conexão com o banco
$mysqli_conn->close();
echo "Conexão MySQL fechada.\n";

