#!/bin/bash

php /bin/configure_bind.php

# @TODO move to env
INTERVAL=20

/usr/sbin/named -4 -c /etc/bind/named.conf -u bind

while true
do
    sleep $INTERVAL

    php /bin/configure_bind.php

    if [ $? = 0 ]
    then
        echo "Reloadind bind configuration"
        rndc load
    fi
done
