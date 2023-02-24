<?php

$fps = [];
for ($i = 0; $i < 3; $i++) {
    $fps[] = fopen("tmp/$i", 'r');
}

while ($fps) {
    foreach ($fps as $i => $fp) {
        rewind($fp);
        $line = fgets($fp);
        if (preg_match('/finish/', $line)) {
            unset($fps[$i]);
        }
    }
}
