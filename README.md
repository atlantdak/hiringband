# WordPress Draft Creator

## 1. Install

Requirements: PHP 8.1+, Composer, `ext-curl`, and `ext-json`.

```bash
composer install
composer check
```

## 2. Create a WordPress Application Password

1. Sign in to WordPress.
2. Open **Users → Profile**.
3. Find **Application Passwords**.
4. Enter a name such as `Draft Creator`.
5. Click **Add New Application Password**.
6. Copy the generated password. WordPress shows it only once.

Use this generated Application Password, not the regular wp-admin password.

## 3. Run from the command line

```bash
php index.php \
  --site="https://example.com" \
  --username="admin"
```

Replace the site and username with your WordPress values. At the prompt, paste the Application
Password and press Enter:

```text
Application Password:
```

The input is hidden. On success:

```text
Draft created successfully. Post ID: 123
```

The parameter is named `password` and is sent unchanged using HTTP Basic Authentication.

## 4. Run in a browser

```bash
composer serve
```

Open:

```text
http://127.0.0.1:49174/index.php
```

Enter the WordPress site URL, username, and Application Password, then click **Create draft**.

## 5. Use the JSON API

```bash
curl http://127.0.0.1:49174/index.php \
  -H 'Content-Type: application/json' \
  --data '{
    "site": "https://example.com",
    "username": "admin",
    "password": "APPLICATION_PASSWORD"
  }'
```

Replace `APPLICATION_PASSWORD` with the generated WordPress Application Password.

The application automatically checks both `/wp-json/` and `?rest_route=/`.

Use HTTPS for the WordPress site. Run the form and JSON API locally only; never send credentials in
query parameters.
