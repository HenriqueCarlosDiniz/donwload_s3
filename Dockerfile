# --- Estágio 1: Builder ---
# Usamos a imagem oficial do Composer para instalar as dependências
FROM composer:2.7 as builder

WORKDIR /app

# Copia apenas o composer.json para aproveitar o cache do Docker
COPY composer.json .

# Instala apenas as dependências de produção, de forma otimizada
RUN composer install --no-dev --optimize-autoloader

# --- Estágio 2: Imagem Final ---
# Usamos uma imagem PHP CLI leve
FROM php:8.3-cli

WORKDIR /app

# Copia as dependências instaladas (pasta vendor) do estágio 'builder'
COPY --from=builder /app/vendor /app/vendor

# Copia o nosso script PHP
COPY download.php .

# Cria o diretório de downloads dentro do container.
# O docker-compose irá montar um volume nesta pasta.
RUN mkdir -p /app/downloads

# Comando padrão que será executado quando o container iniciar
CMD ["php", "download.php"]
