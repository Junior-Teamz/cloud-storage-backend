<!DOCTYPE html>
<html>
<head>
    <title>Reset Password</title>
    
</head>
<body>
    <!-- Banner -->
    <div style="text-align: center; margin-bottom: 20px;">
        <img src="{{ asset('KemenkopUKM File Sharing Logo.png') }}" alt="KemenkopUKM Logo" style="max-width: 100%; height: auto;">
    </div>

    <!-- Konten Email -->
    <h3>Halo, Udin Mauludin!</h3>
    <p>Anda menerima email ini karena ada permintaan untuk mereset password akun Anda.</p>
    <p>Klik tautan di bawah ini untuk mengatur ulang password Anda:</p>
    <a href="{{ route('email_template') }}">(Link password disini)</a>
    <p>Jika Anda tidak meminta reset password, abaikan email ini.</p>
    <p>Terima kasih</p>
</body>
</html>
