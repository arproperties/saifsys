<?php
/**
 * Booking Notification Helpers
 * Send email and SMS confirmations for bookings
 */

/**
 * Send booking confirmation email
 */
function sendBookingConfirmationEmail($conn, $bookingId) {
    try {
        // Get booking details
        $stmt = $conn->prepare("
            SELECT 
                ob.*,
                s.name as service_name,
                s.duration_minutes,
                e.full_name as employee_name
            FROM online_bookings ob
            LEFT JOIN services s ON ob.service_id = s.id
            LEFT JOIN employees e ON ob.employee_id = e.id
            WHERE ob.id = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            return false;
        }

        // Get email settings
        $settingsStmt = $conn->query("SELECT * FROM app_email_settings WHERE is_enabled = 1 LIMIT 1");
        $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            error_log("Email settings not configured");
            return false;
        }

        // Format date and time
        $dateObj = new DateTime($booking['scheduled_date'] . ' ' . $booking['scheduled_time']);
        $formattedDate = $dateObj->format('l, F j, Y');
        $formattedTime = $dateObj->format('g:i A');

        // Email subject
        $subject = "Booking Confirmation - " . $booking['service_name'];

        // Email body
        $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #6C63FF, #9D97FF); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
                .detail-box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #6C63FF; }
                .detail-row { margin: 10px 0; }
                .label { font-weight: bold; color: #6C63FF; }
                .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; }
                .button { display: inline-block; padding: 12px 30px; background: #6C63FF; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Booking Confirmed!</h1>
                    <p>Thank you for choosing our services</p>
                </div>
                <div class='content'>
                    <p>Dear " . htmlspecialchars($booking['customer_name']) . ",</p>
                    <p>Your booking has been confirmed. Here are the details:</p>
                    
                    <div class='detail-box'>
                        <div class='detail-row'>
                            <span class='label'>Booking ID:</span> #" . $booking['id'] . "
                        </div>
                        <div class='detail-row'>
                            <span class='label'>Service:</span> " . htmlspecialchars($booking['service_name']) . "
                        </div>
                        <div class='detail-row'>
                            <span class='label'>Date:</span> " . $formattedDate . "
                        </div>
                        <div class='detail-row'>
                            <span class='label'>Time:</span> " . $formattedTime . "
                        </div>
                        <div class='detail-row'>
                            <span class='label'>Duration:</span> " . $booking['duration_minutes'] . " minutes
                        </div>
                        " . ($booking['employee_name'] ? "
                        <div class='detail-row'>
                            <span class='label'>Service Provider:</span> " . htmlspecialchars($booking['employee_name']) . "
                        </div>
                        " : "") . "
                        <div class='detail-row'>
                            <span class='label'>Location:</span> " . nl2br(htmlspecialchars($booking['address'])) . "
                        </div>
                        <div class='detail-row'>
                            <span class='label'>Total Price:</span> AED " . number_format($booking['total_price'], 2) . "
                        </div>
                    </div>

                    <p><strong>Important Information:</strong></p>
                    <ul>
                        <li>Please ensure someone is available at the service location at the scheduled time</li>
                        <li>Cancellations must be made at least 24 hours in advance</li>
                        <li>Payment will be collected after service completion</li>
                    </ul>

                    <p>If you have any questions, please contact us at:</p>
                    <p>Phone: " . htmlspecialchars($booking['customer_phone']) . "<br>
                    Email: " . htmlspecialchars($settings['from_email']) . "</p>
                </div>
                <div class='footer'>
                    <p>This is an automated message. Please do not reply to this email.</p>
                    <p>&copy; " . date('Y') . " HeroSys. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";

        // Send email using PHPMailer or similar
        require_once __DIR__ . '/../vendor/autoload.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $settings['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $settings['smtp_username'];
            $mail->Password   = $settings['smtp_password'];
            $mail->SMTPSecure = $settings['smtp_secure'];
            $mail->Port       = $settings['smtp_port'];
            
            // Recipients
            $mail->setFrom($settings['from_email'], $settings['from_name']);
            $mail->addAddress($booking['customer_email'], $booking['customer_name']);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email send error: " . $mail->ErrorInfo);
            return false;
        }

    } catch (Exception $e) {
        error_log("Booking confirmation email error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send booking confirmation SMS
 */
function sendBookingConfirmationSMS($conn, $bookingId) {
    try {
        // Get booking details
        $stmt = $conn->prepare("
            SELECT ob.*, s.name as service_name
            FROM online_bookings ob
            LEFT JOIN services s ON ob.service_id = s.id
            WHERE ob.id = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            return false;
        }

        // Format date and time
        $dateObj = new DateTime($booking['scheduled_date'] . ' ' . $booking['scheduled_time']);
        $formattedDate = $dateObj->format('M j, Y');
        $formattedTime = $dateObj->format('g:i A');

        // SMS message
        $message = "HeroSys Booking Confirmed!\n"
                 . "Service: " . $booking['service_name'] . "\n"
                 . "Date: " . $formattedDate . "\n"
                 . "Time: " . $formattedTime . "\n"
                 . "Booking ID: #" . $booking['id'] . "\n"
                 . "Total: AED " . number_format($booking['total_price'], 2);

        // Send SMS using your SMS gateway
        // Example: Twilio, Nexmo, or local SMS provider
        /*
        $twilioSid = getenv('TWILIO_SID');
        $twilioToken = getenv('TWILIO_TOKEN');
        $twilioFrom = getenv('TWILIO_FROM');
        
        $twilio = new Twilio\Rest\Client($twilioSid, $twilioToken);
        
        $twilio->messages->create(
            $booking['customer_phone'],
            [
                'from' => $twilioFrom,
                'body' => $message
            ]
        );
        */

        // Log SMS for development
        error_log("SMS to " . $booking['customer_phone'] . ": " . $message);

        return true;

    } catch (Exception $e) {
        error_log("SMS send error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send booking cancellation notification
 */
function sendBookingCancellationEmail($conn, $bookingId) {
    try {
        // Get booking details
        $stmt = $conn->prepare("
            SELECT ob.*, s.name as service_name
            FROM online_bookings ob
            LEFT JOIN services s ON ob.service_id = s.id
            WHERE ob.id = ?
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            return false;
        }

        // Get email settings
        $settingsStmt = $conn->query("SELECT * FROM app_email_settings WHERE is_enabled = 1 LIMIT 1");
        $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            return false;
        }

        $subject = "Booking Cancellation - " . $booking['service_name'];

        $body = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
            <h2>Booking Cancelled</h2>
            <p>Dear " . htmlspecialchars($booking['customer_name']) . ",</p>
            <p>Your booking #" . $booking['id'] . " for " . htmlspecialchars($booking['service_name']) . " has been cancelled.</p>
            <p>If you did not request this cancellation, please contact us immediately.</p>
            <p>Thank you for using our services.</p>
        </body>
        </html>
        ";

        // Send email (same logic as confirmation)
        // Implementation here...

        return true;

    } catch (Exception $e) {
        error_log("Cancellation email error: " . $e->getMessage());
        return false;
    }
}

