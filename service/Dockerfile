FROM php:8.3.22-cli-alpine

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories \
  && apk update --no-cache \
  && apk add --no-cache libpng-dev libjpeg-turbo-dev freetype-dev font-dejavu \
  && docker-php-source extract

# install extensions（gd 需显式启用 freetype/jpeg，水印与图片解码依赖）
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) pdo pdo_mysql gd pcntl

# enable opcache and pcntl
RUN docker-php-ext-enable opcache pcntl
RUN docker-php-source delete && rm -rf /var/cache/apk/*

RUN mkdir -p /app
WORKDIR /app