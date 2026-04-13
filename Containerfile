FROM php:8-apache

# Copy application files
COPY api/ /var/www/html/api/
COPY css/ /var/www/html/css/
COPY js/ /var/www/html/js/
COPY vendor/ /var/www/html/vendor/
COPY index.php /var/www/html/
COPY sw.js /var/www/html/
COPY data/ /var/www/html/data/

# Set permissions so Apache (www-data) can write to data directory
RUN chown -R www-data:www-data /var/www/html \
    && chmod 775 /var/www/html/data \
    && chmod 664 /var/www/html/data/questions.yaml \
    && chmod 664 /var/www/html/data/game_config.php

# Entrypoint fixes permissions when running with a volume mount (development)
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
