FROM php:8.2-apache
RUN docker-php-ext-install curl
RUN echo "upload_max_filesize = 50M\npost_max_size = 55M\nmax_execution_time = 300\nmemory_limit = 512M" > /usr/local/etc/php/conf.d/limits.ini
COPY . /var/www/html/
EXPOSE 80
CMD ["apache2-foreground"]
