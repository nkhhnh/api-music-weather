FROM php:8.1-fpm

# Cài đặt các gói và extension cần thiết
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql mbstring xml zip bcmath

# Cài đặt Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Thiết lập thư mục làm việc
WORKDIR /var/www/html

# Sao chép mã nguồn
COPY . .

# Debug và cài đặt dependencies
RUN composer --version
RUN ls -la
RUN composer install --no-dev --prefer-dist --no-scripts --no-progress --optimize-autoloader --verbose || { echo "Composer install failed"; exit 1; }

# Cấp quyền
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Chạy ứng dụng
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]