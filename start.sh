
: ${PORT:=10000}

# Xóa cache
php artisan config:clear
php artisan route:clear
php artisan optimize

# Chạy PHP server với giới hạn upload lớn hơn
# Server built-in cua PHP mac dinh chi phuc vu MOT request tai mot thoi diem.
# Dang stream nhac ma app goi them bat ky API nao (ke ca tai truoc bai ke tiep)
# thi request do phai xep hang, lam buffer phia client can -> nhac giat.
# PHP_CLI_SERVER_WORKERS fork them tien trinh con de phuc vu song song.
export PHP_CLI_SERVER_WORKERS=4

exec php \
  -d upload_max_filesize=50M \
  -d post_max_size=55M \
  -d memory_limit=256M \
  -d max_execution_time=300 \
  -d max_input_time=300 \
  -S 0.0.0.0:$PORT \
  -t public