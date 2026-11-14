#!/bin/sh
# Records the SPX reports of ../real inside the image of ./Dockerfile, with ./scripts at /work and the output
# directory at /out: see ../real/README.md.
set -eu

save() {
    json=$(ls /spx/spx-full-*.json)
    cp "$json" "/out/$1.json"
    cp "${json%.json}.txt.gz" "/out/$1.txt.gz"
    rm -f /spx/spx-full-*
}

record() {
    name=$1
    shift
    env SPX_ENABLED=1 SPX_REPORT=full "$@" > /dev/null
    save "$name"
}

mkdir -p /spx /out
cd /work
record default php calls.php
record metrics SPX_METRICS=wt,ct,zm,zgr,zif,io SPX_BUILTINS=1 php calls.php
record depth SPX_DEPTH=2 php calls.php
record span SPX_AUTO_START=0 SPX_BUILTINS=1 php span.php
record shutdown SPX_BUILTINS=1 php shutdown.php
record sampling SPX_SAMPLING_PERIOD=500 php sampling.php

# A web request, SPX enabled by the cookies of a client it grants the access
php -S 127.0.0.1:8080 -d spx.http_enabled=1 -d spx.http_key=dev -d spx.http_ip_whitelist=127.0.0.1 http.php \
    > /dev/null 2>&1 &
server=$!
sleep 1
curl -s -o /dev/null -X POST -H 'Host: shop.test' -b 'SPX_ENABLED=1; SPX_KEY=dev; SPX_REPORT=full' \
    'http://127.0.0.1:8080/checkout?step=2'
kill $server
sleep 1
save http

# A trace cut mid-stream, as a reader finds it while SPX still writes it
head -c "$(($(wc -c < /out/default.txt.gz) / 2))" /out/default.txt.gz > /out/truncated.txt.gz
cp /out/default.json /out/truncated.json
