#!/bin/bash

echo "File Sharing Setup"
echo "Skrip ini akan menjalankan proses setup untuk aplikasi File Sharing. Pastikan Anda sudah memiliki composer, npm, dan PHP, dan mysql diinstal pada sistem Anda."

# buat interaksi y/n agar konfirmasi lanjutkan setup atau tidak
read -p "Apakah Anda ingin melanjutkan setup? (y/n): " confirm
if [ "$confirm" != "y" ]; then
    echo "Setup dibatalkan."
    exit 0
fi

# Step 0: Check if composer, npm, and PHP are installed
echo "Memeriksa php, composer, dan npm..."

if ! [ -x "$(command -v php)" ]; then
    echo "Kesalahan: php tidak terdeteksi. Setup dibatalkan." >&2
    exit 1
fi

if ! [ -x "$(command -v composer)" ]; then
    echo "Error: composer tidak terdeteksi. Setup dibatalkan" >&2
    exit 1
fi

if ! [ -x "$(command -v npm)" ]; then
    echo "Error: npm tidak terdeteksi. Setup dibatalkan." >&2
    exit 1
fi

# Step 1: Run composer install
echo "Menjalankan 'composer install'..."
composer install

# Step 2: Run npm install
echo "Menjalankan 'npm install'..."
npm install

# Step 3: Run npm run build
echo "Menjalankan 'npm run build'..."
npm run build

# Step 4: Copy .env.example to .env
echo "Menyalin '.env.example' ke '.env'..."
cp .env.example .env

# Step 5: Update .env file
echo "Mengupdate '.env' file..."
sed -i 's|APP_NAME=.*|APP_NAME="File Sharing"|' .env
sed -i 's|APP_ENV=.*|APP_ENV=production|' .env
sed -i 's|APP_DEBUG=.*|APP_DEBUG=false|' .env

# Step 6: Run php artisan key:generate
echo "Menjalankan 'php artisan key:generate'..."
php artisan key:generate

# Step 7: Run php artisan jwt:secret
echo "Menjalankan 'php artisan jwt:secret' ..."
yes | php artisan jwt:secret

# Step 8: Interactive input for environment variables
while true; do
    read -p "Masukan limit penyimpanan yang di inginkan (dalam satuan GB): " STORAGE_LIMIT
    if [[ "$STORAGE_LIMIT" =~ ^[0-9]+$ ]]; then
        break
    else
        echo "Input tidak valid. Harap masukkan angka."
    fi
done

while true; do
    read -p "Masukan URL/Link frontend (dipisahkan dengan koma): " FRONTEND_URL
    if [[ "$FRONTEND_URL" =~ ^https?:// ]]; then
        break
    else
        echo "Input tidak valid. Harap masukkan URL yang benar."
    fi
done

while true; do
    read -p "Masukan batas kedalaman suatu folder (isi dengan angka): " SUBFOLDER_DEPTH
    if [[ "$SUBFOLDER_DEPTH" =~ ^[0-9]+$ ]]; then
        break
    else
        echo "Input tidak valid. Harap masukkan angka."
    fi
done

read -p "Masukan url host database (default 127.0.0.1): " DB_HOST
DB_HOST=${DB_HOST:-127.0.0.1}

while true; do
    read -p "Masukan port database (default 3306): " DB_PORT
    DB_PORT=${DB_PORT:-3306}
    if [[ "$DB_PORT" =~ ^[0-9]+$ ]]; then
        break
    else
        echo "Input tidak valid. Harap masukkan angka."
    fi
done

read -p "Masukan nama database (default file_sharing_database): " DB_DATABASE
DB_DATABASE=${DB_DATABASE:-file_sharing_database}

read -p "Masukan username database: " DB_USERNAME

read -p "Masukan password database (default kosong): " DB_PASSWORD

# Update .env with user inputs
sed -i "s|STORAGE_LIMIT=.*|STORAGE_LIMIT=${STORAGE_LIMIT}|" .env
sed -i "s|FRONTEND_URL=.*|FRONTEND_URL=${FRONTEND_URL}|" .env
sed -i "s|SUBFOLDER_DEPTH=.*|SUBFOLDER_DEPTH=${SUBFOLDER_DEPTH}|" .env
sed -i "s|DB_HOST=.*|DB_HOST=${DB_HOST}|" .env
sed -i "s|DB_PORT=.*|DB_PORT=${DB_PORT}|" .env
sed -i "s|DB_DATABASE=.*|DB_DATABASE=${DB_DATABASE}|" .env
sed -i "s|DB_USERNAME=.*|DB_USERNAME=${DB_USERNAME}|" .env
sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env

# Step 9: Optional SMTP configuration
read -p "Apakah anda ingin mengatur konfigurasi SMTP untuk email? [PERINGATAN: JIKA ANDA TIDAK MENGATUR INI, FITUR LUPA PASSWORD TIDAK AKAN BERFUNGSI] (y/n) " configure_smtp
if [ "$configure_smtp" == "y" ]; then
    read -p "Enter MAIL_MAILER: " MAIL_MAILER
    read -p "Enter MAIL_HOST: " MAIL_HOST
    read -p "Enter MAIL_PORT: " MAIL_PORT
    read -p "Enter MAIL_USERNAME: " MAIL_USERNAME
    read -p "Enter MAIL_PASSWORD: " MAIL_PASSWORD
    read -p "Enter MAIL_ENCRYPTION: " MAIL_ENCRYPTION
    sed -i "s|MAIL_MAILER=.*|MAIL_MAILER=${MAIL_MAILER}|" .env
    sed -i "s|MAIL_HOST=.*|MAIL_HOST=${MAIL_HOST}|" .env
    sed -i "s|MAIL_PORT=.*|MAIL_PORT=${MAIL_PORT}|" .env
    sed -i "s|MAIL_USERNAME=.*|MAIL_USERNAME=${MAIL_USERNAME}|" .env
    sed -i "s|MAIL_PASSWORD=.*|MAIL_PASSWORD=${MAIL_PASSWORD}|" .env
    sed -i "s|MAIL_ENCRYPTION=.*|MAIL_ENCRYPTION=${MAIL_ENCRYPTION}|" .env
    sed -i "s|MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=\"${APP_NAME}@example.com\"|" .env
else
    echo "Melewati konfigurasi SMTP. Fitur lupa password tidak akan berfungsi."
fi

# Step 10: Interactive input for seeder
read -p "Masukan nama instansi yang di inginkan: " INSTANCE_NAME
read -p "Masukan alamat instansi yang di inginkan: " INSTANCE_ADDRESS
read -p "Masukan nama unit kerja instansi awal: " INSTANCE_SECTION_NAME

# Create seeder file with user inputs
cat <<EOL > database/seeders/InstanceAndInstanceSectionSeeder.php
<?php

namespace Database\Seeders;

use App\Models\Instance;
use App\Models\InstanceSection;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class InstanceAndInstanceSectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \$instance = Instance::updateOrCreate([
            'name' => '${INSTANCE_NAME}',
            'address' => '${INSTANCE_ADDRESS}',
        ]);

        InstanceSection::updateOrCreate([
            'name' => '${INSTANCE_SECTION_NAME}',
            'instance_id' => \$instance->id,
        ]);
    }
}
EOL

# Step 11: Run php artisan migrate --seed
echo "Menjalankan 'php artisan migrate --seed'..."
yes | php artisan migrate --seed

# Step 12: Run php artisan optimize:clear
echo "Menjalankan 'php artisan optimize:clear'..."
php artisan optimize:clear

# Step 13: Run php artisan optimize
echo "Menjalankan 'php artisan optimize'..."
php artisan optimize

echo "Setup selesai! Aplikasi File Sharing siap digunakan."
exit