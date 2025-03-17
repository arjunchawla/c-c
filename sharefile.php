<?php

// ------------------------------------------
// 1) Credentials & Access Token retrieval
// ------------------------------------------
$subdomain     = "chawlaandchawla"; // e.g. "mycompany"
$clientId      = "qNL54hl9nFJjUUuCSNHUSmzs2rGami9b";
$clientSecret  = "2fw5bwrEDKsdJ6VhNgbXGq5NvDtsMpKhQ3wUwPn4TgXCiXtg";
$username      = "lchawla@candccpa.com";
$password      = "irlh qqki jz3w e4ky";

// The ShareFile OAuth2 token endpoint
$tokenUrl = "https://{$subdomain}.sf-api.com/oauth/token";

// Prepare POST fields for the password grant
$postFields = [
    'grant_type'     => 'password',
    'client_id'      => $clientId,
    'client_secret'  => $clientSecret,
    'username'       => $username,
    'password'       => $password,
    // The account_domain is usually "<subdomain>.sharefile.com"
    'account_domain' => "{$subdomain}.sharefile.com"
];

// Initialize cURL
$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

// IMPORTANT: Point to your CA cert bundle
curl_setopt($ch, CURLOPT_CAINFO, 'C:/php/extras/ssl/cacert.pem');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

$response  = curl_exec($ch);
$httpError = curl_error($ch);
curl_close($ch);

if ($httpError) {
    die("Error requesting access token: $httpError");
}

$data = json_decode($response, true);

if (isset($data['error'])) {
    die("Error: " . $data['error_description']);
}

// Access token
$accessToken = $data['access_token'];
// (Optional) Refresh token (if needed)
$refreshToken = $data['refresh_token'];

echo "Access Token: " . $accessToken . "\n";
echo "Refresh Token: " . $refreshToken . "\n";

// ------------------------------------------
// 2) Paginate & download items from File Box
// ------------------------------------------
$skip = 17230;
$top  = 15000;

// We'll store a flag to track when to stop
$hasMoreFiles = true;

while ($hasMoreFiles) {
    // Build the URL for listing items in the Box folder, with pagination
    // $url = "https://{$subdomain}.sf-api.com/sf/v3/Items(box)/Children?"
    //     . "\$top={$top}&\$skip={$skip}";
    
    $url = "https://{$subdomain}.sf-api.com/sf/v3/Users(3d8b80af-11d2-44a8-aaec-57c884b3b417)/Box";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_CAINFO, 'C:/php/extras/ssl/cacert.pem');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("Error listing File Box items: $error");
    }
    
    $data = json_decode($response, true);
    
    // var_dump($data);
    // die();
    
    // If the response structure is something like:
    // {
    //   "value": [
    //     { "Id": "...", "Name": "...", ... },
    //     ...
    //   ]
    // }
    // Adjust code based on how the items appear in your response.
    if (!isset($data['value']) || count($data['value']) === 0) {
        // No more files, break out
        $hasMoreFiles = false;
        break;
    }
    
    // Loop through each item returned
    foreach ($data['value'] as $item) {
        // Check if the item is a file or a folder. If it's a folder, you might
        // want to handle it differently or traverse deeper. For now,
        // let's assume everything is a file or you only want to download files.
        
        // The item "Id" and "Name" are typically in these properties:
        $fileID = $item['Id'];
        $fileName = $item['Name'];
        
        // (Optional) Try to extract extension from the Name
        $extension = '';
        if (preg_match('/\.([a-zA-Z0-9]+)$/', $fileName, $matches)) {
            $extension = $matches[1];
        }
        
        // Now build the download URL
        $downloadUrl = "https://{$subdomain}.sf-api.com/sf/v3/Items($fileID)/Download?redirect=true";
        
        // Curl request to download
        $ch = curl_init($downloadUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // We'll write to a file
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_CAINFO, 'C:/php/extras/ssl/cacert.pem');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // Make sure that the 'downloaded' folder exists. If not, create it.
        $downloadDir = __DIR__ . '/downloaded';
        if (!file_exists($downloadDir)) {
            mkdir($downloadDir, 0777, true);
        }
        
        // Instead of using $fileName directly, call our helper to avoid overwrites
        $uniqueFileName = getUniqueFileName($downloadDir, $fileName);
        $localFilePath  = $downloadDir . DIRECTORY_SEPARATOR . $uniqueFileName;
        
        $fh = fopen($localFilePath, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fh);
        
        $downloadResponse = curl_exec($ch);
        $downloadError    = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        
        if ($downloadError) {
            // If there's an error, you may wish to log or handle it
            echo "Error downloading file: {$fileName} - {$downloadError}\n";
        } else {
            echo "Downloaded: {$fileName}\n";
        }
    } // end foreach file
    
    // We processed $top items or fewer, so let's increment skip
    // by the actual number of items processed.
    $processedCount = count($data['value']);
    $skip += $processedCount;
    
    
    // If we got fewer than $top items, it likely means no more items remain.
    // if ($processedCount < $top) {
    //     $hasMoreFiles = false;
    // }
}

echo "All done.\n";







function getUniqueFileName($directory, $fileName) {
    // Build the initial full path
    $fullPath = $directory . DIRECTORY_SEPARATOR . $fileName;
    
    // If the file doesn't exist yet, we can use it as-is
    if (!file_exists($fullPath)) {
        return $fileName;
    }
    
    // Otherwise, we attempt to find a new name using a suffix
    $fileInfo   = pathinfo($fileName);
    $extension  = isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '';
    $baseName   = $fileInfo['filename']; // excludes extension
    
    $counter = 1;
    // Keep generating new file names until we find one that doesn't exist
    do {
        $newName = $baseName . '_(' . $counter . ')' . $extension;
        $newPath = $directory . DIRECTORY_SEPARATOR . $newName;
        $counter++;
    } while (file_exists($newPath));
    
    return $newName;
}
?>
