<?php

return [
    // Transport layer protocol ('tcp' for ws:, 'tls' for wss:)
    'transport'     => 'tls',
    // Websocket server host (0.0.0.0 by default)
    'host'          => '0.0.0.0',
    // Websocket server port (1024–49151)
    'port'          => 8443,
    // Enable SSL encryption (required for wss:)
    'enableSsl'     => true,
    // SSL certificate paths
    'sslCertPath'   => [
        'crt'   => '/xxx_le1.crt',
        'key'   => '/xxx_le1.key'
    ]
];
