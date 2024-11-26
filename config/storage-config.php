<?php

/**
 * Storage configuration for file sharing.
 */
return [
    'storage_limit' => env('STORAGE_LIMIT', 10), // Max storage limit, default to 10GB.
    'max_subfolder_depth' => env('SUBFOLDER_DEPTH', 5) // Max subfolder created on a folder or subfolder, default to 5 depth
];
