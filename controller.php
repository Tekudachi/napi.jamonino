<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

// Credenciales de NextCloud
$nextcloud_url = "http://192.168.203.211:8000/remote.php/dav/files/admin/";
$nextcloud_user = "admin";
$nextcloud_pass = "admin";

// Verificar si se subió un archivo
if (!isset($_FILES["file"])) {
    echo json_encode(["error" => "No se ha enviado ningún archivo.", "status" => 400]);
    exit;
}

$file = $_FILES["file"];
$original_name = pathinfo($file["name"], PATHINFO_FILENAME);
$extension = pathinfo($file["name"], PATHINFO_EXTENSION);
$allowed_extensions = ["gif", "jpg", "png"];

if (!in_array($extension, $allowed_extensions)) {
    echo json_encode(["error" => "Formato de archivo no permitido.", "status" => 400]);
    exit;
}

// **Sanitizar el nombre del archivo**
$original_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $original_name);

// **Generar ruta de almacenamiento**
$timestamp = time();  // Evita colisiones de nombres
$folder_path = "$extension/" . date("Y") . "/" . date("m") . "/" . date("d") . "/";
$file_name = "$timestamp-$original_name.$extension";
$file_path = $folder_path . rawurlencode($file_name); // Codificar el nombre del archivo

// **PASO 1: Crear la estructura de directorios en NextCloud**
$folders = explode("/", trim($folder_path, "/"));
$current_path = $nextcloud_url;

foreach ($folders as $folder) {
    $current_path .= rawurlencode($folder) . "/";
    createFolder($current_path, $nextcloud_user, $nextcloud_pass);
}

// **PASO 2: Subir el archivo a NextCloud**
$upload_url = $nextcloud_url . $file_path;
$fp = fopen($file["tmp_name"], "r");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $upload_url);
curl_setopt($ch, CURLOPT_USERPWD, "$nextcloud_user:$nextcloud_pass");
curl_setopt($ch, CURLOPT_PUT, true);
curl_setopt($ch, CURLOPT_INFILE, $fp);
curl_setopt($ch, CURLOPT_INFILESIZE, filesize($file["tmp_name"]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 300);  // Espera hasta 5 minutos si es necesario
curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1024);
curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 30);

$response = curl_exec($ch);
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);

fclose($fp);
curl_close($ch);

if ($http_status === 201) {
    echo json_encode(["message" => "Archivo subido exitosamente.", "status" => 201, "file" => $file_path]);
} else {
    echo json_encode([
        "error" => "Error al subir el archivo a NextCloud.",
        "status" => $http_status,
        "curl_error" => $curl_error,
        "curl_request" => "curl -u '$nextcloud_user:$nextcloud_pass' -T '" . $file["tmp_name"] . "' '$upload_url'"
    ]);
}

/**
 * 📌 Crear directorio en NextCloud si no existe (uno por uno)
 */
function createFolder($folder_url, $user, $pass) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $folder_url);
    curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "MKCOL");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Ignorar errores si el directorio ya existe (405 o 409)
    if ($http_status !== 201 && $http_status !== 405 && $http_status !== 409) {
        echo json_encode([
            "error" => "Error al crear el directorio en NextCloud.",
            "status" => $http_status,
            "folder_url" => $folder_url
        ]);
        exit;
    }
}
