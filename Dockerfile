FROM ubuntu:16.04

RUN apt-get -y update
RUN apt-get -y upgrade
RUN apt-get -y install locales bind9 curl
RUN apt-get -y install php-cli

COPY bin/* /bin/

RUN mkdir -p /etc/bind/zones

EXPOSE 53/udp

CMD /bin/start.sh
