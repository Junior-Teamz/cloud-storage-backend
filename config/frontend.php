<?php

return [
    /**
     * Frontend URL.
     */
    'url' => array_map('trim', explode(',', env('FRONTEND_URL', 'http://localhost:3032'))),
];
