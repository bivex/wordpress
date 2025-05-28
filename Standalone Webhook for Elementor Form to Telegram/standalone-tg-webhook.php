<?php
/**
 * Standalone Webhook for Elementor Form to Telegram
 * 
 * This script receives form data and forwards it to a Telegram bot.
 * It can be used independently of WordPress.
 */

// ======== CONFIGURATION - EDIT THESE SETTINGS ========
// Your Telegram bot token from @BotFather
$CONFIG_BOT_TOKEN = '';

// Chat ID where messages will be sent
$CONFIG_CHAT_ID = '';

// Default timezone (used if can't detect user's timezone)
$CONFIG_DEFAULT_TIMEZONE = 'Europe/Kiev';

// Fields to skip (technical fields that shouldn't be included in the message)
$CONFIG_SKIP_FIELDS = ['form_id', 'referer', 'queried_id', 'action', 'token'];

// Enable timezone detection via IP address
$CONFIG_DETECT_TIMEZONE = true;

// Message format: 'HTML' or 'Markdown'
// Use HTML to avoid escaping issues with special characters
$CONFIG_MESSAGE_FORMAT = 'HTML';
// ===================================================

/**
 * Send form data to Telegram
 * This function handles the webhook logic
 */
function process_form_data() {
    global $CONFIG_BOT_TOKEN, $CONFIG_CHAT_ID, $CONFIG_DEFAULT_TIMEZONE, $CONFIG_SKIP_FIELDS, $CONFIG_DETECT_TIMEZONE, $CONFIG_MESSAGE_FORMAT;
    
    // Only proceed if this is a POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('HTTP/1.1 405 Method Not Allowed');
        exit('Method not allowed');
    }
    
    // Get the POST data - supports both form data and JSON
    $form_data = [];
    
    // Check if the request is JSON
    $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (strpos($content_type, 'application/json') !== false) {
        // Get JSON data
        $json_data = file_get_contents('php://input');
        $decoded_data = json_decode($json_data, true);
        
        if ($decoded_data) {
            $form_data = $decoded_data;
        } else {
            header('HTTP/1.1 400 Bad Request');
            exit('Invalid JSON data');
        }
    } else {
        // Get standard form POST data
        $form_data = $_POST;
        
        // Also check for files if needed
        if (!empty($_FILES)) {
            foreach ($_FILES as $key => $file_info) {
                if ($file_info['error'] === UPLOAD_ERR_OK) {
                    $form_data[$key] = 'File uploaded: ' . $file_info['name'];
                }
            }
        }
    }
    
    // Validate that we have data
    if (empty($form_data)) {
        header('HTTP/1.1 400 Bad Request');
        exit('No form data received');
    }

    // Get form name if available
    $form_name = isset($form_data['form_name']) ? $form_data['form_name'] : 'Form Submission';
    
    // Format the message
    if ($CONFIG_MESSAGE_FORMAT === 'HTML') {
        // HTML formatting
        $message = "<b>📝 New Form Submission" . ($form_name ? ": " . htmlspecialchars($form_name) : "") . "</b>\n\n";
        
        // Add all form fields to the message
        foreach ($form_data as $field_name => $field_value) {
            // Skip technical fields
            if (in_array($field_name, $CONFIG_SKIP_FIELDS)) {
                continue;
            }
            
            // Handle arrays (like in some complex form fields)
            if (is_array($field_value)) {
                $field_value = implode(', ', $field_value);
            }
            
            // Format the field name to be more readable
            $display_name = ucfirst(str_replace('_', ' ', $field_name));
            
            // Sanitize HTML
            $display_name = htmlspecialchars($display_name);
            $field_value = htmlspecialchars($field_value);
            
            $message .= "<b>" . $display_name . ":</b> " . $field_value . "\n";
        }
        
        // Add submission time in both Kiev time and user's local time
        $message .= "\n<b>📅 Date/Time:</b>\n";
        
        // Get current time in UTC
        $time_utc = new DateTime('now', new DateTimeZone('UTC'));
        
        // Kiev time (Europe/Kiev)
        $time_kiev = clone $time_utc;
        $time_kiev->setTimezone(new DateTimeZone($CONFIG_DEFAULT_TIMEZONE));
        $message .= "🇺🇦 <b>Kiev:</b> " . $time_kiev->format('Y-m-d H:i:s') . "\n";
        
        // Try to detect user timezone from IP or use server's timezone as fallback
        $user_timezone = get_user_timezone($CONFIG_DEFAULT_TIMEZONE, $CONFIG_DETECT_TIMEZONE);
        $time_user = clone $time_utc;
        $time_user->setTimezone(new DateTimeZone($user_timezone));
        $message .= "🌐 <b>Local:</b> " . $time_user->format('Y-m-d H:i:s') . " (" . htmlspecialchars($user_timezone) . ")";
    } else {
        // Markdown formatting - using version 2 for better escaping
        $message = "*📝 New Form Submission" . ($form_name ? ": " . escape_markdown($form_name) : "") . "*\n\n";
        
        // Add all form fields to the message
        foreach ($form_data as $field_name => $field_value) {
            // Skip technical fields
            if (in_array($field_name, $CONFIG_SKIP_FIELDS)) {
                continue;
            }
            
            // Handle arrays (like in some complex form fields)
            if (is_array($field_value)) {
                $field_value = implode(', ', $field_value);
            }
            
            // Format the field name to be more readable
            $display_name = ucfirst(str_replace('_', ' ', $field_name));
            
            // Escape Markdown characters
            $display_name = escape_markdown($display_name);
            $field_value = escape_markdown($field_value);
            
            $message .= "*" . $display_name . ":* " . $field_value . "\n";
        }
        
        // Add submission time in both Kiev time and user's local time
        $message .= "\n*📅 Date/Time:*\n";
        
        // Get current time in UTC
        $time_utc = new DateTime('now', new DateTimeZone('UTC'));
        
        // Kiev time (Europe/Kiev)
        $time_kiev = clone $time_utc;
        $time_kiev->setTimezone(new DateTimeZone($CONFIG_DEFAULT_TIMEZONE));
        $message .= "🇺🇦 *Kiev:* " . $time_kiev->format('Y-m-d H:i:s') . "\n";
        
        // Try to detect user timezone from IP or use server's timezone as fallback
        $user_timezone = get_user_timezone($CONFIG_DEFAULT_TIMEZONE, $CONFIG_DETECT_TIMEZONE);
        $time_user = clone $time_utc;
        $time_user->setTimezone(new DateTimeZone($user_timezone));
        $message .= "🌐 *Local:* " . $time_user->format('Y-m-d H:i:s') . " (" . escape_markdown($user_timezone) . ")";
    }
    
    // Send to Telegram
    $result = send_telegram_message($message, $CONFIG_BOT_TOKEN, $CONFIG_CHAT_ID, $CONFIG_MESSAGE_FORMAT);
    
    // Return response
    header('Content-Type: application/json');
    echo json_encode(['success' => $result]);
    exit;
}

/**
 * Escape special characters for Markdown
 * 
 * @param string $text The text to escape
 * @return string Escaped text safe for Markdown
 */
function escape_markdown($text) {
    // Characters that need to be escaped in MarkdownV2
    $special_chars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
    
    // Escape each character with a backslash
    foreach ($special_chars as $char) {
        $text = str_replace($char, '\\' . $char, $text);
    }
    
    return $text;
}

/**
 * Try to determine user's timezone
 * 
 * @param string $default_timezone Default timezone to use if detection fails
 * @param bool $detect_via_ip Whether to try to detect timezone via IP
 * @return string Timezone identifier
 */
function get_user_timezone($default_timezone = 'Europe/Kiev', $detect_via_ip = true) {
    // Check if timezone was sent in the form
    if (isset($_POST['timezone'])) {
        return $_POST['timezone'];
    }
    
    // Try to detect from IP using ipinfo.io (optional)
    if ($detect_via_ip && isset($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
        
        // Skip local IPs
        if (!in_array($ip, ['127.0.0.1', '::1']) && filter_var($ip, FILTER_VALIDATE_IP)) {
            try {
                $response = file_get_contents("https://ipinfo.io/{$ip}/json");
                $data = json_decode($response, true);
                
                if (isset($data['timezone'])) {
                    return $data['timezone'];
                }
            } catch (Exception $e) {
                // Failed to get timezone from IP, continue with default
            }
        }
    }
    
    return $default_timezone;
}

/**
 * Send message to Telegram
 *
 * @param string $message The message to send
 * @param string $bot_token Telegram bot token
 * @param string $chat_id Telegram chat ID
 * @param string $format Message format ('HTML' or 'Markdown')
 * @return bool Whether the message was sent successfully
 */
function send_telegram_message($message, $bot_token, $chat_id, $format = 'HTML') {
    // Endpoint for Telegram API
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
    
    // Set the proper parse mode
    $parse_mode = ($format === 'HTML') ? 'HTML' : 'MarkdownV2';
    
    // Parameters for the API request
    $params = [
        'chat_id' => $chat_id,
        'text' => $message,
        'parse_mode' => $parse_mode,
    ];
    
    // Make the API request using cURL
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Check for errors
    if (curl_errno($ch) || $http_code !== 200) {
        $error = curl_error($ch);
        curl_close($ch);
        
        // Log error
        error_log("Telegram API Error: " . ($error ?: "HTTP Code: $http_code"));
        
        // If there was an error with Markdown formatting, try again with HTML as fallback
        if ($parse_mode === 'MarkdownV2' && $http_code === 400) {
            $html_message = "<b>📝 Error with Markdown formatting</b>\n\nThe message is being sent using HTML format instead.\n\n";
            $html_message .= nl2br(htmlspecialchars($message));
            
            return send_telegram_message($html_message, $bot_token, $chat_id, 'HTML');
        }
        
        return false;
    }
    
    curl_close($ch);
    
    // Decode response
    $response_data = json_decode($response, true);
    
    // Check if the API request was successful
    return isset($response_data['ok']) && $response_data['ok'] === true;
}

// Process the webhook request
process_form_data();
?> 
