<?php

$delay = rand(500000, 999000);
usleep($delay);

echo json_encode([
    'status' => 'OK.',
    'delay' => (string) $delay/1000 . 'ms',
]);
