#!/bin/bash
# OPcache with the server's settings (validate_timestamps=1, revalidate_freq=2): a PHP file whose content changes while
# its modification time keeps the same whole second is still served from the cache. One second later, it is not.
set -e; T=$(mktemp -d); cd "$T"
printf '<?php echo "version A\\n";\n' > a.php; M=$(stat -c %Y a.php); sleep 3
php -n -d zend_extension=opcache -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.validate_timestamps=1 \
    -d opcache.revalidate_freq=2 -S 127.0.0.1:18099 -t "$T" >/dev/null 2>&1 & SRV=$!; sleep 1
echo "first request:                               $(curl -s http://127.0.0.1:18099/a.php)"
printf '<?php echo "version B\\n";\n' > a.php; touch -d "@$M" a.php; sleep 3
echo "new content, same second, 3 s later:        $(curl -s http://127.0.0.1:18099/a.php)"
sleep 3; echo "6 s later:                                   $(curl -s http://127.0.0.1:18099/a.php)"
touch -d "@$((M+1))" a.php; sleep 3
echo "timestamp moved by one second, 3 s later:   $(curl -s http://127.0.0.1:18099/a.php)"
kill $SRV; php -v | head -1
