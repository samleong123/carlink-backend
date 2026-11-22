FROM nginx:1.28-alpine
COPY public /var/www/html/public
COPY deploy/nginx.conf.template /etc/nginx/templates/default.conf.template
