<?php
/**
 * Template for Express PayPal Buttons (No Patching Version)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    if (empty($_GET['rest_route'])) {
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>Direct access not allowed.</body></html>';
        exit;
    }
}

// Get parameters
$amount = isset($amount) ? $amount : (isset($_GET['amount']) ? sanitize_text_field($_GET['amount']) : '0.00');
$currency = isset($currency) ? $currency : (isset($_GET['currency']) ? sanitize_text_field($_GET['currency']) : 'USD');
$api_key = isset($api_key) ? $api_key : (isset($_GET['api_key']) ? sanitize_text_field($_GET['api_key']) : '');
$client_id = isset($client_id) ? $client_id : '';
$environment = isset($environment) ? $environment : 'sandbox';
$site_url = isset($site_url) ? $site_url : (isset($_GET['site_url']) ? sanitize_text_field($_GET['site_url']) : '');
$needs_shipping = isset($needs_shipping) ? $needs_shipping : (isset($_GET['needs_shipping']) && $_GET['needs_shipping'] === 'yes');
$context = isset($_GET['context']) ? sanitize_text_field($_GET['context']) : 'default';
?><!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayPal Express Checkout</title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: transparent;
        }
        
        .container {
            width: 100%;
            position: relative;
        }
        
        #paypal-express-button-container {
            width: 100%;
            min-height: 45px;
        }
        
        #paypal-message, 
        #paypal-error,
        #paypal-success {
            margin: 8px 0;
            padding: 8px;
            border-radius: 4px;
            font-size: 14px;
            display: none;
        }
        
        #paypal-message {
            background-color: #f8f9fa;
            border: 1px solid #d6d8db;
            color: #1e2125;
        }
        
        #paypal-error {
            background-color: #f8d7da;
            border: 1px solid #f5c2c7;
            color: #842029;
        }
        
        #paypal-success {
            background-color: #d1e7dd;
            border: 1px solid #badbcc;
            color: #0f5132;
        }
    </style>
</head>
<body>
    <div class="container">
        <div id="paypal-express-button-container"></div>
        <div id="paypal-message"></div>
        <div id="paypal-error"></div>
        <div id="paypal-success"></div>
    </div>
    
    <input type="hidden" id="api-key" value="<?php echo esc_attr($api_key); ?>">
    <input type="hidden" id="amount" value="<?php echo esc_attr($amount); ?>">
    <input type="hidden" id="currency" value="<?php echo esc_attr($currency); ?>">
    <input type="hidden" id="site-url" value="<?php echo esc_attr($site_url); ?>">
    <input type="hidden" id="needs-shipping" value="<?php echo $needs_shipping ? 'yes' : 'no'; ?>">
    <input type="hidden" id="context" value="<?php echo esc_attr($context); ?>">
    
    <?php if (!empty($client_id)) : ?>
    <script src="https://www.paypal.com/sdk/js?client-id=<?php echo esc_attr($client_id); ?>&currency=<?php echo esc_attr($currency); ?>&intent=capture&components=buttons"></script>
    <?php endif; ?>
    <script>
        // Simple variables for iframe communication
        var parentOrigin = '*';
        var context = document.getElementById('context').value || 'default';
        var iframeId = 'paypal-express-iframe-' + context;
        
        // Utility functions
        function sendMessageToParent(message) {
            message.source = 'paypal-express-proxy';
            message.iframeId = iframeId;
            window.parent.postMessage(message, parentOrigin);
        }
        
        function resizeIframe() {
            var height = document.body.scrollHeight + 20;
            sendMessageToParent({
                action: 'resize_iframe',
                height: height
            });
        }
        
        function showError(message) {
            document.getElementById('paypal-error').textContent = message;
            document.getElementById('paypal-error').style.display = 'block';
            resizeIframe();
        }
        
        function showSuccess(message) {
            document.getElementById('paypal-success').textContent = message;
            document.getElementById('paypal-success').style.display = 'block';
            resizeIframe();
        }
        
        // Initialize PayPal buttons
        function initPayPalButtons() {
            if (typeof paypal === 'undefined') {
                showError('PayPal SDK could not be loaded.');
                return;
            }
            
            sendMessageToParent({ action: 'button_loaded' });
            
            paypal.Buttons({
                style: {
                    layout: 'horizontal',
                    color: 'gold',
                    shape: 'rect',
                    label: 'paypal',
                    tagline: false
                },
                
                createOrder: function() {
                    sendMessageToParent({ action: 'button_clicked' });
                    
                    return new Promise(function(resolve, reject) {
                        var messageHandler = function(event) {
                            var data = event.data;
                            if (!data || !data.action || data.source !== 'woocommerce-client') {
                                return;
                            }
                            
                            if (data.action === 'create_paypal_order') {
                                window.removeEventListener('message', messageHandler);
                                resolve(data.paypal_order_id);
                            }
                        };
                        
                        window.addEventListener('message', messageHandler);
                        
                        setTimeout(function() {
                            window.removeEventListener('message', messageHandler);
                            reject(new Error('Timeout waiting for order data'));
                        }, 30000);
                    });
                },
                
                onApprove: function(data, actions) {
                    sendMessageToParent({
                        action: 'payment_approved',
                        payload: {
                            orderID: data.orderID,
                            paypalData: data
                        }
                    });
                    
                    showSuccess('Payment successful! Finalizing your order...');
                    
                    return actions.order.capture().then(function(captureData) {
                        console.log('PayPal capture complete:', captureData);
                    });
                },
                
                onCancel: function(data) {
                    sendMessageToParent({
                        action: 'payment_cancelled',
                        payload: data
                    });
                },
                
                onError: function(err) {
                    sendMessageToParent({
                        action: 'payment_error',
                        error: {
                            message: err.message || 'An error occurred'
                        }
                    });
                }
            }).render('#paypal-express-button-container');
            
            setTimeout(resizeIframe, 500);
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initPayPalButtons);
        } else {
            initPayPalButtons();
        }
    </script>
</body>
</html>