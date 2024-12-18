<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" href="{{ asset('images/KemenkopUKM Logo Without Name.jpeg') }}" />
    <title>File Sharing Backend</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans text-gray-900 antialiased">
    <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 dark:bg-gray-900">
        <div>
            <a href="{{ config('frontend.url')[0] }}">
                <img src="{{ asset('images/KemenkopUKM File Sharing Logo (crop).png') }}" class="w-50 h-14 fill-current text-gray-500" />
            </a>
        </div>

        <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white dark:bg-gray-800 shadow-md overflow-hidden sm:rounded-lg">
            <h1 class="text-white text-center mb-4">
                WARNING: THIS IS THE LOGIN PAGE FOR FILE SHARING BACKEND MONITORING DASHBOARD.
                IF YOU WANT TO GO TO FILE SHARING, PLEASE GO TO THIS
                <a href="{{ config('frontend.url')[0] }}" class="text-blue-400 underline hover:text-blue-600">
                    LINK INSTEAD.
                </a>
            </h1>
            {{ $slot }}
        </div>
    </div>
</body>

</html>