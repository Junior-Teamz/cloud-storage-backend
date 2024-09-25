<?php

return [
    /**
     * Frontend URL untuk CORS.
     * 
     * Menggunakan array_map untuk membersihkan spasi ekstra di setiap URL
     */
    'url_for_cors' => array_map('trim', explode(',', env('FRONTEND_URL_FOR_CORS'))),
    
    /**
     * Frontend URL untuk share link
     */
    'url_for_share' => env('FRONTEND_URL_FOR_SHARE', 'http://localhost:3032'),
];
