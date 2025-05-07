FROM php:8.1-fpm

# Cài đặt các dependencies cần thiết
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
    nginx \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql mbstring xml zip bcmath

# Cài đặt Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Thiết lập thư mục làm việc
WORKDIR /var/www/html

# Sao chép mã nguồn ứng dụng
COPY . .

# Cài đặt các Composer dependencies
RUN composer install --no-dev --prefer-dist --no-scripts --no-progress --optimize-autoloader --verbose || { echo "Composer install failed"; exit 1; }

# Thiết lập quyền cho các thư mục cần thiết
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/resources/views
RUN chmod -R 755 /var/www/html

# Tạo thư mục cấu hình Nginx
RUN mkdir -p /etc/nginx/conf.d

# Sao chép cấu hình Nginx tùy chỉnh
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# Xóa cấu hình Nginx mặc định (để tránh xung đột)
RUN if [ -f /etc/nginx/conf.d/default ]; then rm /etc/nginx/conf.d/default; fi

# Chạy các lệnh Artisan quan trọng
RUN php artisan route:clear
RUN php artisan config:clear
RUN php artisan cache:clear
RUN php artisan view:clear
RUN php artisan optimize:clear
RUN php artisan optimize

# Expose cổng 80 cho Nginx
EXPOSE 80

# Lệnh khởi chạy: Khởi động Nginx và PHP-FPM
CMD ["/bin/sh", "-c", "service nginx start && php-fpm"]