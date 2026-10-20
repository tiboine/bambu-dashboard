#!/bin/bash
# Reserve for klokkesynk når nettverket blokkerer NTP (UDP 123).
# Gir NTP noen sekunder; er klokka fortsatt ikke synkronisert, settes den fra
# Date-headeren til en vanlig nettside (HTTP, så det virker selv om klokka er så
# feil at HTTPS-sertifikater avvises). Kjøres som root av msr-timesync.service.

for i in $(seq 1 10); do
    if [ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = "yes" ]; then
        echo "NTP er synkronisert – ingenting å gjøre"
        exit 0
    fi
    sleep 2
done

for url in http://google.com http://cloudflare.com http://microsoft.com; do
    for attempt in 1 2 3 4 5; do
        d=$(curl -sI --max-time 5 "$url" | grep -i '^date:' | cut -d' ' -f2- | tr -d '\r')
        if [ -n "$d" ] && date -s "$d" >/dev/null 2>&1; then
            echo "Klokke satt fra $url: $d"
            exit 0
        fi
        sleep 3
    done
done

echo "Fikk ikke satt klokka (ingen nettverk?)"
exit 0  # aldri blokker oppstarten av dashboardet
