# FreshTracker
Трекер запасов продуктов по свежести

Производство - deepseek. Нет, мне не стыдно делать микроутилиту для личных нужд нейросетью.

# NGinx VHost

```
server {
    listen 80;
    server_name freshtracker.local;
    root /var/www/freshtracker;      # путь к папке с проектом
    index index.php;

    # Запрещаем доступ к файлу базы данных
    location ~* \.sqlite$ {
        return 404;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Статические файлы
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Основной location - отдаем index.php
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Логи
    access_log /var/log/nginx/freshtracker_access.log;
    error_log /var/log/nginx/freshtracker_error.log;
}
```




