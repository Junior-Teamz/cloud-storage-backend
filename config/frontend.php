<?php

return [
    // Gunakan array_map untuk membersihkan spasi ekstra di setiap URL
    'urls' => array_map('trim', explode(',', env('FRONTEND_URL'))),
];
