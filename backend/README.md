# RazbudiSe Backend

Laravel API and Filament admin panel for the RazbudiSe custom publishing platform.

## Local Development

From the repository root, copy the example environment files:

```bash
cp backend/.env.example backend/.env
```

Then install dependencies and generate a local app key:

```bash
cd backend
composer install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8001
```

Run the queue worker and scheduler in separate terminals when testing imports, scheduled publishing, ad tracking, or heavy jobs:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=90
php artisan schedule:work
```

## Key Local URLs

- API status: `http://localhost:8001/api/v1/status`
- Public API: `http://localhost:8001/api/articles`
- Filament admin: `http://localhost:8001/backoffice`

The local seeder creates a development-only admin user when `APP_ENV` is `local` or `testing`:

- Email: `admin@example.test`
- Password: `password`

## Verification

```bash
php artisan test
./vendor/bin/pint --dirty --test
php artisan wordpress:migration-readiness
php artisan redirects:qa-report
php artisan seo:public-qa
php artisan launch:qa
```

## Notes

Do not commit production secrets. Keep `WORDPRESS_BASE_URL=https://razbudise.mk` only in migration environments that need to pull from the legacy WordPress site.
