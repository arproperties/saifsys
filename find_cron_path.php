<?php
/**
 * Path Finder Script for Cron Job Setup
 * Upload this file to your root directory and visit it in browser
 * It will show you the exact path to use in your cron job command
 * 
 * DELETE THIS FILE after you get the path (for security)
 */

// Security: Only allow if accessed directly
if (basename($_SERVER['PHP_SELF']) !== 'find_cron_path.php') {
    die('Access denied');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Cron Job Path Finder</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .path-box {
            background: #f8f9fa;
            border: 2px solid #007bff;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            word-break: break-all;
        }
        .command-box {
            background: #e7f3ff;
            border-left: 4px solid #007bff;
            padding: 15px;
            margin: 20px 0;
            font-family: 'Courier New', monospace;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
        }
        .success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin: 20px 0;
        }
        .info {
            background: #d1ecf1;
            border-left: 4px solid #17a2b8;
            padding: 15px;
            margin: 20px 0;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Cron Job Path Finder</h1>
        
        <div class="info">
            <strong>📋 Instructions:</strong><br>
            Copy the path below and use it in your Hostinger cron job command. 
            <strong>Delete this file after you get the path!</strong>
        </div>

        <h2>📍 Your Server Paths:</h2>
        
        <div>
            <strong>Current Directory (__DIR__):</strong>
            <div class="path-box"><?= __DIR__ ?></div>
        </div>

        <div>
            <strong>Script File Path (__FILE__):</strong>
            <div class="path-box"><?= __FILE__ ?></div>
        </div>

        <div>
            <strong>Document Root:</strong>
            <div class="path-box"><?= $_SERVER['DOCUMENT_ROOT'] ?? 'Not available' ?></div>
        </div>

        <h2>🎯 Cron Job Command:</h2>
        
        <div class="command-box">
            <strong>For AMC Alert Cron:</strong><br><br>
            <code>php <?= __DIR__ ?>/modules/realestate/amc_alert_cron.php</code>
        </div>

        <div class="command-box">
            <strong>Alternative (with full PHP path):</strong><br><br>
            <code>/usr/bin/php <?= __DIR__ ?>/modules/realestate/amc_alert_cron.php</code>
        </div>

        <div class="command-box">
            <strong>Or using URL (for testing):</strong><br><br>
            <code>curl -s <?= (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] ?>/modules/realestate/amc_alert_cron.php?run_cron=1</code>
        </div>

        <h2>✅ Test Your Cron Job:</h2>
        
        <div class="success">
            <strong>Manual Test URL:</strong><br>
            <a href="../modules/realestate/amc_alert_cron.php?run_cron=1" target="_blank">
                <?= (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] ?>/modules/realestate/amc_alert_cron.php?run_cron=1
            </a>
            <br><br>
            Click the link above to test if the cron job works. You should see: 
            <code>"AMC Alert Cron Job completed. Generated X alerts."</code>
        </div>

        <div class="warning">
            <strong>⚠️ Security Warning:</strong><br>
            <strong>DELETE THIS FILE</strong> after you copy the path information. 
            This file exposes server path information and should not remain on your server.
        </div>

        <h2>📝 Next Steps:</h2>
        <ol>
            <li>Copy the cron job command above</li>
            <li>Go to Hostinger hPanel → Advanced → Cron Jobs</li>
            <li>Create a new cron job</li>
            <li>Paste the command</li>
            <li>Set schedule: <code>0 9 * * *</code> (Daily at 9:00 AM)</li>
            <li>Save and test</li>
            <li><strong>Delete this file</strong> for security</li>
        </ol>

        <div class="info">
            <strong>💡 Tip:</strong> Test the cron job manually first using the test URL above 
            to ensure it works before setting up the automated schedule.
        </div>
    </div>
</body>
</html>
