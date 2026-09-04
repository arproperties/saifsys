<?php
/**
 * SMS Service for Sending OTP
 * Supports multiple SMS gateways
 */

// SMS Configuration
define('SMS_PROVIDER', getenv('SMS_PROVIDER') ?: 'log'); // Options: twilio, unifonic, aws_sns, log
define('SMS_ENABLED', getenv('SMS_ENABLED') === 'true'); // Set to true to enable real SMS

// Twilio Configuration
define('TWILIO_ACCOUNT_SID', getenv('TWILIO_ACCOUNT_SID') ?: '');
define('TWILIO_AUTH_TOKEN', getenv('TWILIO_AUTH_TOKEN') ?: '');
define('TWILIO_FROM_NUMBER', getenv('TWILIO_FROM_NUMBER') ?: '');

// Unifonic Configuration
define('UNIFONIC_APP_SID', getenv('UNIFONIC_APP_SID') ?: '');
define('UNIFONIC_API_URL', 'https://el.cloud.unifonic.com/rest/SMS/messages');

/**
 * Send OTP SMS
 * 
 * @param string $phone Phone number with country code (e.g., +971501234567)
 * @param string $otp The OTP code
 * @param string $appName Your app name
 * @return bool Success status
 */
function sendOTPSMS($phone, $otp, $appName = 'HeroSys') {
    // Format message
    $message = "Your {$appName} verification code is: {$otp}. Valid for 10 minutes. Do not share this code.";
    
    // If SMS is disabled, just log
    if (!SMS_ENABLED) {
        error_log("SMS (DEV MODE) to {$phone}: {$otp}");
        return true;
    }
    
    // Route to appropriate provider
    switch (SMS_PROVIDER) {
        case 'twilio':
            return sendViaTwilio($phone, $message);
        
        case 'unifonic':
            return sendViaUnifonic($phone, $message);
        
        case 'aws_sns':
            return sendViaAWSSNS($phone, $message);
        
        default:
            error_log("SMS to {$phone}: {$otp}");
            return true;
    }
}

/**
 * Send SMS via Twilio
 */
function sendViaTwilio($phone, $message) {
    if (empty(TWILIO_ACCOUNT_SID) || empty(TWILIO_AUTH_TOKEN)) {
        error_log("Twilio credentials not configured");
        return false;
    }
    
    $url = "https://api.twilio.com/2010-04-01/Accounts/" . TWILIO_ACCOUNT_SID . "/Messages.json";
    
    $data = [
        'From' => TWILIO_FROM_NUMBER,
        'To' => $phone,
        'Body' => $message
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 201) {
        error_log("SMS sent successfully via Twilio to: {$phone}");
        return true;
    } else {
        error_log("Failed to send SMS via Twilio: " . $response);
        return false;
    }
}

/**
 * Send SMS via Unifonic
 */
function sendViaUnifonic($phone, $message) {
    if (empty(UNIFONIC_APP_SID)) {
        error_log("Unifonic credentials not configured");
        return false;
    }
    
    $data = [
        'AppSid' => UNIFONIC_APP_SID,
        'SenderID' => 'HeroSys', // Your registered sender ID
        'Recipient' => $phone,
        'Body' => $message
    ];
    
    $ch = curl_init(UNIFONIC_API_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($httpCode === 200 && isset($result['success']) && $result['success']) {
        error_log("SMS sent successfully via Unifonic to: {$phone}");
        return true;
    } else {
        error_log("Failed to send SMS via Unifonic: " . $response);
        return false;
    }
}

/**
 * Send SMS via AWS SNS
 */
function sendViaAWSSNS($phone, $message) {
    // Requires AWS SDK
    // composer require aws/aws-sdk-php
    
    if (!class_exists('Aws\Sns\SnsClient')) {
        error_log("AWS SDK not installed. Run: composer require aws/aws-sdk-php");
        return false;
    }
    
    try {
        $client = new Aws\Sns\SnsClient([
            'version' => 'latest',
            'region' => getenv('AWS_REGION') ?: 'us-east-1',
            'credentials' => [
                'key' => getenv('AWS_ACCESS_KEY_ID'),
                'secret' => getenv('AWS_SECRET_ACCESS_KEY'),
            ]
        ]);
        
        $result = $client->publish([
            'Message' => $message,
            'PhoneNumber' => $phone,
        ]);
        
        error_log("SMS sent successfully via AWS SNS to: {$phone}");
        return true;
    } catch (Exception $e) {
        error_log("Failed to send SMS via AWS SNS: " . $e->getMessage());
        return false;
    }
}

/**
 * Send SMS (Generic function - auto-detects provider)
 */
function sendSMS($phone, $message) {
    if (!SMS_ENABLED) {
        error_log("SMS (DEV MODE) to {$phone}: {$message}");
        return true;
    }
    
    switch (SMS_PROVIDER) {
        case 'twilio':
            return sendViaTwilio($phone, $message);
        
        case 'unifonic':
            return sendViaUnifonic($phone, $message);
        
        case 'aws_sns':
            return sendViaAWSSNS($phone, $message);
        
        default:
            error_log("SMS to {$phone}: {$message}");
            return true;
    }
}

