<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error {{ $code }}</title>
    <style>
        body {
            background-color: #1a202c;
            color: #cbd5e0;
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            text-align: center;
        }
        h1 {
            font-size: 72px;
            margin: 0;
        }
        p {
            font-size: 24px;
            margin: 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>{{ $code }} | {{ $message }}</h1>
    </div>
</body>
</html>
