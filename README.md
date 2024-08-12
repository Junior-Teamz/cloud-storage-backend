# Cloud Storage Backend

This repository store backend code for cloud storage Junior Teamz.

## Prerequisites
Before you begin, ensure you have the following installed on your local machine:

- **PHP** (version 8.3, and the [Laravel PHP extension is enabled.](https://laravel.com/docs/11.x/deployment#server-requirements))
- **Composer**
- **MySQL**
- **Git**

## How to clone in your local development?
1. run this command: `git clone https://github.com/Junior-Teamz/cloud-storage-backend.git`
2. install all required packages: `composer install`
3. create new `.env` and copy the entire `.env.example` to a newly created `.env`
4. change the mysql env to yours.
5. generate laravel key app: `php artisan key:generate`
6. generate JWT secret key: `php artisan jwt:secret`
7. link the storage: `php artisan storage:link`
8. run the migration: `php artisan migrate --seed`
9. serve laravel: `php artisan serve`