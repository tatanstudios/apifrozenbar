<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recuperar contraseña</title>
    <style>
        body {
            background-color: #f6f6f6;
            font-family: Arial, Helvetica, sans-serif;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 6px;
            overflow: hidden;
        }
        .header {
            background: #262022;
            color: #ffffff;
            padding: 20px;
            text-align: center;
        }
        .header .logo {
            max-width: 120px;
            margin-bottom: 10px;
        }
        .body {
            padding: 30px;
            color: #333333;
            font-size: 14px;
            line-height: 1.6;
        }
        .btn {
            background: #262022;
            color: #ffffff !important;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 4px;
            display: inline-block;
            font-weight: bold;
        }
        .footer {
            background: #f2f2f2;
            padding: 20px;
            text-align: center;
            font-size: 12px;
            color: #777777;
        }
        .social-links img {
            width: 24px;
            margin: 0 5px;
        }
    </style>
</head>
<body>

<div class="email-container">
    <div class="header">
        <img
            src="{{ $logoUrl }}"
            alt="The Frozen Bar"
            class="logo"
        >
        <h1>Recuperar contraseña</h1>
        <h3>The Frozen Bar</h3>
    </div>

    <div class="body">
        <p>Hola {{ $nombre }},</p>

        <p>
            Hemos recibido una solicitud para restablecer la contraseña de tu cuenta
            en <strong>The Frozen Bar</strong>.
        </p>

        <p>
            Para continuar, haz clic en el siguiente botón:
        </p>

        <p style="text-align:center;">
            <a href="{{ $resetUrl }}" class="btn">
                Restablecer contraseña
            </a>
        </p>

        <p>
            Si no solicitaste este cambio, puedes ignorar este correo.
        </p>

        <p style="margin-top: 20px;">
            Saludos,<br>
            El equipo de <strong>The Frozen Bar</strong>
        </p>
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} The Frozen Bar. Todos los derechos reservados.<br>
        Este enlace expirará en 60 minutos.
    </div>
</div>

</body>
</html>
