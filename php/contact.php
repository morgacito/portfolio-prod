<?php
// php/contact.php

// Habilitar reporte de errores para depuración
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Permitir solicitudes CORS (útil para desarrollo)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

// Manejar preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Solo permitir solicitudes POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido. Solo se admite POST.']);
    exit;
}

// Cargar clases de PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

// Leer los datos JSON del cuerpo de la petición
$inputData = json_decode(file_get_contents("php://input"), true);

$name = trim($inputData['name'] ?? '');
$email = trim($inputData['email'] ?? '');
$subject = trim($inputData['subject'] ?? '');
if (empty($subject)) {
    $subject = 'Mensaje enviado a tu Portfolio';
}
$message = trim($inputData['message'] ?? '');

// Validación básica de campos
if (empty($name) || empty($email) || empty($message)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Los campos Nombre, Email y Mensaje son obligatorios.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'El formato del correo electrónico no es válido.']);
    exit;
}

// Cargar configuración (config.php) si existe, o usar variables de entorno
$config = [];
if (file_exists(__DIR__ . '/config.php')) {
    $config = require __DIR__ . '/config.php';
}

$smtpUser = $config['SMTP_USER'] ?? getenv('SMTP_USER') ?? '';
$smtpPass = $config['SMTP_PASS'] ?? getenv('SMTP_PASS') ?? '';
$smtpTo   = $config['SMTP_TO'] ?? getenv('SMTP_TO') ?? $smtpUser;

// Validar que tengamos las credenciales mínimas configuradas
if (empty($smtpUser) || empty($smtpPass)) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'El servidor de correo no está configurado. Por favor, crea el archivo config.php con tus credenciales.'
    ]);
    exit;
}

$mail = new PHPMailer(true);

try {
    // Configuración del servidor SMTP
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Habilitar TLS
    $mail->Port       = 587;                            // Puerto TCP para TLS
    $mail->CharSet    = 'UTF-8';

    // Opciones SSL para evitar errores de verificación de certificados (muy común en local/Docker y hostings compartidos)
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true
        ]
    ];

    // Destinatarios
    $mail->setFrom($smtpUser, 'Portfolio Leads');
    $mail->addAddress($smtpTo);
    $mail->addReplyTo($email, $name); // Permitir responder directamente al emisor del formulario

    // Contenido
    $mail->isHTML(true);
    $mail->Subject = $subject . ($name ? ' - ' . $name : '');
    
    // Cuerpo en formato HTML
    $mail->Body    = "
        <html>
        <head>
            <title>Nuevo Lead de Contacto</title>
        </head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9;'>
                <h2 style='color: #0d9488; border-bottom: 2px solid #0d9488; padding-bottom: 10px;'>Nuevo contacto desde tu Portfolio</h2>
                <p><strong>Nombre:</strong> " . htmlspecialchars($name) . "</p>
                <p><strong>Correo electrónico:</strong> <a href='mailto:" . htmlspecialchars($email) . "'>" . htmlspecialchars($email) . "</a></p>
                <p><strong>Asunto:</strong> " . htmlspecialchars($subject) . "</p>
                <p style='margin-top: 20px;'><strong>Mensaje:</strong></p>
                <div style='background: #fff; padding: 15px; border-left: 4px solid #0d9488; border-radius: 4px; font-style: italic;'>
                    " . nl2br(htmlspecialchars($message)) . "
                </div>
                <footer style='margin-top: 30px; font-size: 0.8em; color: #777; text-align: center; border-top: 1px solid #eee; padding-top: 10px;'>
                    Este correo fue generado de forma automática desde tu sistema de leads en PHP.
                </footer>
            </div>
        </body>
        </html>
    ";

    // Texto alternativo plano
    $mail->AltBody = "Nuevo Lead desde el Portfolio\n\n" .
                     "Nombre: $name\n" .
                     "Email: $email\n" .
                     "Asunto: $subject\n\n" .
                     "Mensaje:\n$message";

    $mail->send();
    echo json_encode(['status' => 'success', 'message' => 'El mensaje ha sido enviado correctamente.']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'No se pudo enviar el correo electrónico.',
        'debug' => $mail->ErrorInfo ?? '',
        'error_detail' => $e->getMessage()
    ]);
}
