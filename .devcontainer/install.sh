#!/bin/bash

export PATH=/usr/bin:/usr/local/bin:/usr/local/py-utils/bin:.:$PATH

sudo apt update
sudo apt install -y software-properties-common
# Repo needed for PHP 8.X php-mysql packages
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y mariadb-server apache2 php8.3 php8.3-mysql libapache2-mod-php8.3

# Setup MySQL
sudo service mysql start
sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY 'password'; FLUSH PRIVILEGES;"
sudo mysql -u root -p"password" -e "CREATE DATABASE wordpress;"

# Setup Apache
sudo mkdir -p /var/www/html
sudo rm -rf /var/www/html/*
sudo chmod -R 755 /var/www/html
sudo chown -R codespace:codespace /var/www/html
sudo a2enmod rewrite
sudo service apache2 start

# Setup PHP
sudo sed -i 's/^memory_limit = .*/memory_limit = 512M/' /usr/local/php/current/ini/php.ini
echo xdebug.log_level=0 | sudo tee -a /usr/local/php/current/ini/conf.d/xdebug.ini

# Install WordPress

# --- Install WP CLI ---
wget -q https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -O /tmp/wp
chmod +x /tmp/wp
sudo mv /tmp/wp /usr/local/bin/wp

# --- Download the latest version ---
wp core download --path=/var/www/html

# --- Create wp-config.php ---
wp config create \
  --dbname=wordpress \
  --dbuser=root \
  --dbpass=password \
  --dbhost=localhost \
  --path=/var/www/html

# --- WordPress Install ---
wp core install \
  --url="https://${CODESPACE_NAME}" \
  --title="WordPress" \
  --admin_user=admin \
  --admin_password=admin \
  --admin_email=admin@home.arpa \
  --path=/var/www/html \
  --skip-email

# --- Adjust the WordPress configuration for Codespaces Proxy ---
# Prepend a Codespaces configuration block to wp-config.php
CONFIG_PATH="/var/www/html/wp-config.php"
TEMP_PATH="/var/www/html/wp-config.tmp.php"

cat <<'EOF' > "$TEMP_PATH"
<?php

if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_X_FORWARDED_HOST'];
}

define('WP_HOME', 'https://' . $_SERVER['HTTP_HOST']);
define('WP_SITEURL', 'https://' . $_SERVER['HTTP_HOST']);

?>
EOF
cat "$CONFIG_PATH" >> "$TEMP_PATH"
mv "$TEMP_PATH" "$CONFIG_PATH"

# Setup for interactive sessions
echo 'export PATH=/usr/bin:/usr/local/bin:.:$PATH' >> /home/codespace/.bashrc
source /home/codespace/.bashrc

# Install SemGrep
pipx install semgrep

# Install wp-auctions plugin and add user for it
cp -r plugins/wp-auctions /var/www/html/wp-content/plugins
wp plugin activate wp-auctions --path=/var/www/html
wp user create editor editor@example.com --user_pass=editor --role=editor --path=/var/www/html

echo "\n\nConfiguration finished\n\n"
