<?php
// DishNet Roundcube overrides — mounted as /var/roundcube/config/custom.inc.php.
//
// Talk to Stalwart over the internal docker network and accept its
// certificate even while it is the self-signed placeholder. Traffic never
// leaves the docker bridge, so peer verification adds nothing here — and
// this keeps webmail working before AND after the real Let's Encrypt
// certificate is installed on the mail ports.
$config['imap_host'] = 'ssl://stalwart:993';
$config['smtp_host'] = 'ssl://stalwart:465';
$config['imap_conn_options'] = [
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
];
$config['smtp_conn_options'] = [
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
];
