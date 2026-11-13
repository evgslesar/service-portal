# Service Portal — приложение

Laravel-приложение портала обслуживания. Описание проекта, установка, демо-данные и тесты — в [README в корне репозитория](../README.md).

```bash
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate && npm install && npm run build
php artisan serve
```
