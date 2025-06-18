<?php

ini_set('display errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

$db_config = [
    'host' => 'localhost',
    'dbname' => 'easy_recipes',
    'username' => 'root',
    'password'=> 'Lyt1cs-dlr0w',
    'charset' => 'utf8mb4'
];

$mpesa_config = [
    'base_url' => 'https://sandbox.safaricom.co.ke',
    'access_token_url' => 'oauth/v1/generate?grant_type=client_credentials',
    'stk_push_url' => 'mpesa/stkpush/v1/processrequest',
    'stk_query_url' => 'mpesa/stkpushquery/v1/query',
    'business_short_code' => '174379',
    'passkey' => 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
    'till_number' => '8976288',
    'callback_url' => 'https://mydomain.com/callback',
    'consumer_key' => 'd1re4kgynWQqZ2rfYFuwuTaedpMwf18IFOmfkzJE7B1axgKS',
    'consumer_secret' => 'aiyAgNABP6ucijARrMAW0pOLjyutS7jOPk9MwF83phx8KhipiNOPd28telaHqP7i'
];

function getDbConnection() {
    global $db_config;

    try{
        $dsn = "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset={$db_config['charset']}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        return new PDO($dsn, $db_config['username'], $db_config['password'], $options);
    } catch (PDOException $e) {
        throw new Exception("Database connection failed: " . $e->getMessage());
    }
}

function createDatabaseTables(){
    $pdo = getDbConnection();

    $pdo -> exec ("
        CREATE TABLE IF NOT EXISTS recipes (
            recipe_id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_name VARCHAR(255) NOT NULL,
            recipe_price DECIMAL (10,2) NOT NULL
        )
    ");

    $pdo -> exec ("
        CREATE TABLE IF NOT EXISTS purchases (
            purchase_id INT AUTO_INCREMENT PRIMARY KEY,
            recipe_id INT NOT NULL,
            phone_number VARCHAR(15) NOT NULL,
            quantity INT NOT NULL,
            total_amount DECIMAL (10,2) NOT NULL,
            payment_status ENUM('Pending' , 'Paid', 'Failed') NOT NULL DEFAULT 'Pending',
            mpesa_receipt_number VARCHAR(100),
            transaction_date DATETIME,
            date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_purchases_recipes FOREIGN KEY (recipe_id) REFERENCES recipes(recipe_id) ON DELETE CASCADE
        )

    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pushRequest(
            push_request_id INT AUTO_INCREMENT PRIMARY KEY,
            purchase_id INT NOT NULL,
            checkout_request_id VARCHAR(255) NOT NULL,
            date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_pushRequest_purchases FOREIGN KEY (purchase_id) REFERENCES purchases(purchase_id) ON DELETE CASCADE
        )
    ");
}

function jsonResponse($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getMpesaAccessToken() {
    global $mpesa_config;
    
    $url = $mpesa_config['base_url'] . '/' . $mpesa_config['access_token_url'];
    $credentials = base64_encode($mpesa_config['consumer_key'] . ':' . $mpesa_config['consumer_secret']);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $credentials
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpcode == 200) {
        $response_data = json_decode($response, true);
        return $response_data['access_token'] ?? null;
    }
    
    return null;
}

function formatPhoneNumber($phone_number) {
    
    $phone_number = preg_replace('/\D/', '', $phone_number);
    
    
    if (substr($phone_number, 0, 1) === '0') {
        $phone_number = '254' . substr($phone_number, 1);
    }
    
   
    elseif (substr($phone_number, 0, 4) === '+254') {
        $phone_number = substr($phone_number, 1);
    }
    
    
    elseif (substr($phone_number, 0, 3) !== '254') {
        $phone_number = '254' . $phone_number;
    }
    
    return $phone_number;
}

function handleIndexRoute() {
    jsonResponse(['message' => 'Welcome to the Recipe Order System API']);
}

function handleGetAllRecipes() {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT * FROM recipes");
        $recipes = $stmt->fetchAll();

      
        foreach ($recipes as &$recipe) {
            $recipe['recipe_price'] = (float) $recipe['recipe_price'];
        }

        jsonResponse(['recipes' => $recipes]);
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function handleGetRecipeById($id) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM recipes WHERE recipe_id = ?");
        $stmt->execute([$id]);
        $recipe = $stmt->fetch();

        if (!$recipe) {
            jsonResponse(['error' => 'Recipe not found'], 404);
        }

  
        $recipe['recipe_price'] = (float) $recipe['recipe_price'];

        jsonResponse(['recipe' => $recipe]);
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function handleAddRecipe() {
    try {
     
        $data = json_decode(file_get_contents('php://input'), true);

      
        $required_fields = ['recipe_name', 'recipe_price'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                jsonResponse(['error' => "Missing required field: {$field}"], 400);
            }
        }

        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            INSERT INTO recipes (recipe_name, recipe_price)
            VALUES (?, ?)
        ");

        $stmt->execute([
            $data['recipe_name'],
            $data['recipe_price']
        ]);

        jsonResponse(['message' => 'Recipe added successfully'], 201);
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function handlePurchaseTicket() {
    handleGetAllRecipes();
}

function handleMakePayment() {
    try {
      
        $data = json_decode(file_get_contents('php://input'), true);

    
        $required_fields = ['recipe_id', 'phone_number', 'quantity'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field])) {
                jsonResponse(['error' => "Missing required field: {$field}"], 400);
            }
        }

        $pdo = getDbConnection();

        
        $stmt = $pdo->prepare("SELECT * FROM recipes WHERE recipe_id = ?");
        $stmt->execute([$data['recipe_id']]);
        $recipe = $stmt->fetch();

        if (!$recipe) {
            jsonResponse(['error' => 'Recipe not found'], 404);
        }

        $quantity = (int) $data['quantity'];

       
        $total_amount = (float) $recipe['recipe_price'] * $quantity;

     
        $formatted_phone = formatPhoneNumber($data['phone_number']);

       
        $stmt = $pdo->prepare("
            INSERT INTO purchases (recipe_id, phone_number, quantity, total_amount, payment_status)
            VALUES (?, ?, ?, ?, 'Pending')
        ");

        $stmt->execute([
            $recipe['recipe_id'],
            $formatted_phone,
            $quantity,
            $total_amount
        ]);

        $purchaseId = $pdo->lastInsertId();

     
        $access_token = getMpesaAccessToken();
        if (!$access_token) {
            $stmt = $pdo -> prepare("UPDATE purchases SET paymentStatus= 'Failed' WHERE purchase_id= ?");
            $stmt->execute(['purchase_id']);
            jsonResponse(['error' => 'Failed to get M-Pesa access token'], 500);
        }

       
        global $mpesa_config;
        $timestamp = date('YmdHis');
        $password = base64_encode($mpesa_config['business_short_code'] . $mpesa_config['passkey'] . $timestamp);

        $stk_push_url = $mpesa_config['base_url'] . '/' . $mpesa_config['stk_push_url'];

        $stk_push_data = [
            'BusinessShortCode' => $mpesa_config['business_short_code'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerBuyGoodsOnline',
            'Amount' => (int) $total_amount,  
            'PartyA' => $formatted_phone,
            'PartyB' => $mpesa_config['till_number'],
            'PhoneNumber' => $formatted_phone,
            'CallBackURL' => $mpesa_config['callback_url'],
            'AccountReference' => "Recipe Purchase",
            'TransactionDesc' => 'Recipe Purchase'
        ];

    
        $ch = curl_init($stk_push_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($stk_push_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $mpesa_response = json_decode($response, true);

     
        if (isset($mpesa_response['ResponseCode']) && $mpesa_response['ResponseCode'] === '0') {
            jsonResponse([
                'message' => 'Payment initiated successfully',
                'purchaseId' => $purchaseId,
                'checkoutRequestId' => $mpesa_response['CheckoutRequestID'],
                'responseDescription' => $mpesa_response['ResponseDescription'] ?? ''
            ]);
        } else {
            jsonResponse(['error' => 'Failed to initiate payment', 'mpesaResponse' => $mpesa_response], 500);
        }
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}


function handleQueryPaymentStatus() {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $checkout_request_id = $data['checkoutRequestId'] ?? null;

        if (!$checkout_request_id) {
            jsonResponse(['error' => 'Checkout Request ID not provided'], 400);
        }

        $access_token = getMpesaAccessToken();
        if (!$access_token) {
            jsonResponse(['error' => 'Failed to get M-Pesa access token'], 500);
        }

        global $mpesa_config;
        $timestamp = date('YmdHis');
        $password = base64_encode($mpesa_config['business_short_code'] . $mpesa_config['passkey'] . $timestamp);

        $query_url = $mpesa_config['base_url'] . '/' . $mpesa_config['stk_query_url'];

        $query_data = [
            'BusinessShortCode' => $mpesa_config['business_short_code'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkout_request_id
        ];

        $ch = curl_init($query_url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query_data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $mpesa_response = json_decode($response, true);

        jsonResponse($mpesa_response);
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

function handleMpesaCallback() {
    try {
        $response = json_decode(file_get_contents('php://input'), true);
        $callback_data = $response['Body']['stkCallback'] ?? null;

        if (!$callback_data) {
            jsonResponse(['error' => 'Invalid callback data'], 400);
        }

        $result_code = $callback_data['ResultCode'] ?? null;
        $checkout_request_id = $callback_data['CheckoutRequestID'] ?? null;

        $pdo = getDbConnection();

        $stmt = $pdo->prepare("SELECT * FROM pushRequest WHERE checkout_request_id = ?");
        $stmt->execute([$checkout_request_id]);
        $push_request = $stmt->fetch();

        if (!$push_request) {
            jsonResponse(['error' => 'No matching push request found'], 404);
        }

        $stmt = $pdo->prepare("SELECT * FROM purchases WHERE purchase_id = ?");
        $stmt->execute([$push_request['purchase_id']]);
        $purchase = $stmt->fetch();

        if (!$purchase) {
            jsonResponse(['error' => 'No matching purchase found'], 404);
        }

        if ($result_code == 0) {
            $callback_metadata = $callback_data['CallbackMetadata']['Item'] ?? [];

            $amount = null;
            $receipt_number = null;
            $transaction_date_str = null;

            foreach ($callback_metadata as $item) {
                if ($item['Name'] === 'Amount') {
                    $amount = $item['Value'];
                } elseif ($item['Name'] === 'MpesaReceiptNumber') {
                    $receipt_number = $item['Value'];
                } elseif ($item['Name'] === 'TransactionDate') {
                    $transaction_date_str = $item['Value'];
                }
            }

            $transaction_date = null;
            if ($transaction_date_str) {
                try {
                    $transaction_date = date('Y-m-d H:i:s', strtotime($transaction_date_str));
                } catch (Exception $e) {
                    $transaction_date = date('Y-m-d H:i:s');
                }
            }

            $stmt = $pdo->prepare("
                UPDATE purchases SET 
                payment_status = 'Paid',
                mpesa_receipt_number = ?,
                transaction_date = ?
                WHERE purchase_id = ?
            ");

            $stmt->execute([$receipt_number, $transaction_date, $purchase['purchase_id']]);

            jsonResponse(['message' => 'Payment completed successfully']);
        }
        else {
            $stmt = $pdo->prepare("UPDATE purchases SET payment_status = 'Failed' WHERE purchase_id = ?");
            $stmt->execute([$purchase['purchase_id']]);

            jsonResponse(['message' => 'Payment failed', 'result_code' => $result_code]);
        }
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}
 
function handleGetPurchase($id) {
    try {
        $pdo = getDbConnection();

        
        $stmt = $pdo->prepare("
            SELECT p.*, r.recipe_name, r.recipe_price
            FROM purchases p
            JOIN recipes r ON p.recipe_id = r.recipe_id
            WHERE p.purchase_id = ?
        ");
        $stmt->execute([$id]);
        $result = $stmt->fetch();

        if (!$result) {
            jsonResponse(['error' => 'Purchase not found'], 404);
        }

       
        $purchase = [
            'purchase_id' => $result['purchase_id'],
            'recipe_id' => $result['recipe_id'],
            'phone_number' => $result['phone_number'],
            'quantity' => (int) $result['quantity'],
            'total_amount' => (float) $result['total_amount'],
            'payment_status' => $result['payment_status'],
            'mpesa_receipt_number' => $result['mpesa_receipt_number'],
            'transaction_date' => $result['transaction_date'],
            'date_created' => $result['date_created'],
            'last_updated' => $result['last_updated'],
            'recipe' => [
                'recipe_id' => $result['recipe_id'],
                'recipe_name' => $result['recipe_name'],
                'recipe_price' => (float) $result['recipe_price']
            ]
        ];

        jsonResponse(['purchase' => $purchase]);
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}


function handleRequest() {
    $method = $_SERVER['REQUEST_METHOD'];
    $uri = $_SERVER['REQUEST_URI'];

    $uri = strtok($uri, '?');
    $uri = rtrim($uri, '/');

    createDatabaseTables();

    if ($uri === '' || $uri === '/') {
        handleIndexRoute();
    } elseif ($uri === '/api/recipes' && $method === 'GET') {
        handleGetAllRecipes();
    } elseif (preg_match('#^/api/recipes/(\d+)$#', $uri, $matches) && $method === 'GET') {
        handleGetRecipeById($matches[1]);
    } elseif ($uri === '/api/recipes' && $method === 'POST') {
        handleAddRecipe();
    } elseif ($uri === '/api/purchase-ticket' && $method === 'GET') {
        handlePurchaseTicket();
    } elseif ($uri === '/api/make-payment' && $method === 'POST') {
        handleMakePayment();
    } else {
        jsonResponse(['error' => 'Route not found'], 404);
    }
}

handleRequest(); 

