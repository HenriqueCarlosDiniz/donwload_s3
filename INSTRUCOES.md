Aqui está o novo fluxo de trabalho para controlar a execução do script manualmente.

1. Como Definir a Pasta de Download

Isso não mudou. A definição ainda é feita no docker-compose.yml, na seção volumes do serviço s3_downloader:

volumes:
  - ./meus_downloads:/app/downloads


./meus_downloads: É a pasta no seu computador. Altere este lado se desejar.

/app/downloads: É a pasta dentro do container. Não altere este lado.

2. Nova Sequência de Etapas para Executar

O processo agora é dividido em "ligar o ambiente" e "executar o script".

Passo 1: Crie o Arquivo .env (A Configuração)

(Idêntico a antes. Faça isso apenas uma vez.)

Copie o .env.example para .env: cp .env.example .env

Abra e preencha todas as variáveis (AWS_..., S3_..., DB_...).

Passo 2: Crie as Pastas Locais

(Idêntico a antes. Faça isso apenas uma vez.)

mkdir meus_downloads
mkdir mysql_data


Passo 3: Construa a Imagem Docker (Build)

(Idêntico a antes. Faça isso apenas uma vez ou se alterar o Dockerfile.)

docker-compose build


Passo 4: Inicie os Serviços (Ligar o Ambiente)

Este comando agora não executa o download. Ele apenas "liga" o banco de dados e o container do script (que ficará ocioso).

docker-compose up


(Opcional: use docker-compose up -d para rodar em segundo plano).

Seu ambiente agora está "pronto", com o MySQL rodando e o container s3_downloader conectado à rede, aguardando comandos.

Passo 5: Execute o Download (O Novo Comando)

Este é o comando que você usará sempre que quiser verificar e baixar novos arquivos. Abra um novo terminal (deixe o up rodando no primeiro, se não usou -d) e execute:

docker-compose exec s3_downloader php /app/download.php


Explicação do Comando:

docker-compose exec: Diz ao compose para "executar um comando"

s3_downloader: O nome do serviço (container) que já está rodando.

php /app/download.php: O comando exato que queremos rodar dentro daquele container.

Você verá a saída do script (os echo, [OK], [SKIP], etc.) neste terminal. Você pode rodar este comando quantas vezes quiser.

Passo 6: Desligue o Ambiente

Quando terminar os trabalhos, volte ao terminal do docker-compose up e pressione Ctrl + C, ou execute:

docker-compose down
