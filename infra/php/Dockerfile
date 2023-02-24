FROM php:8.1-apache

ARG USER_UID=1000
ARG LOCAL_PORT=8080

ENV LOCAL_PORT ${LOCAL_PORT}

RUN useradd -m -s /bin/bash devel -u ${USER_UID} -U

RUN apt-get update && \
    apt-get install -y git unzip && \
    apt-get clean

# virtualhost config.
COPY ./hosts/000-default.conf /etc/apache2/sites-available/
# composer bin file
COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /home/devel/workspace
