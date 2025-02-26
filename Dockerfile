FROM composer:2.6 as composer

ARG TESTING=false
ENV TESTING=$TESTING

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install \
    --ignore-platform-reqs \
    --optimize-autoloader \
    --no-plugins  \
    --no-scripts \
    --prefer-dist

FROM php:8.3-cli-alpine

WORKDIR /usr/local/src/

RUN apk update && apk add git

COPY --from=composer /usr/local/src/vendor /usr/local/src/vendor
COPY . /usr/local/src/

CMD [ "tail", "-f", "/dev/null" ]
