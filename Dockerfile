FROM php:8.1-fpm

# Cài đặt các gói cần thiết
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    zip \
    unzip \
    git \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql

# Cài đặt Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Thiết lập thư mục làm việc
WORKDIR /var/www/html

# Sao chép mã nguồn
COPY . .

# Cài đặt dependencies
RUN composer install --no-dev --prefer-dist --no-scripts --no-progress

# Cấp quyền cho storage và cache
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Chạy ứng dụng
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]