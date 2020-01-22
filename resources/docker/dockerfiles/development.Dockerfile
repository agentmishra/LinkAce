# DOCKERFILE DEVELOPMENT
FROM bitnami/php-fpm:7.4-prod

# Install MySQL Dump for automated backups
RUN install_packages mysql-client

WORKDIR /app
