<?php
// list_models.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// TU API KEY (La tomé de tu código anterior)
$apiKey = 'AIzaSyBmW_WmXbkUyM0iUIUtyIuMMmnbxyF7JBU'; 

echo "<h1>🔍 Consultando Modelos Disponibles...</h1>";

// Endpoint para listar modelos (GET)
$url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Fix para Localhost
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p>Estado HTTP: <strong>$httpCode</strong></p>";

if ($httpCode === 200) {
    $data = json_decode($response, true);
    echo "<h3>✅ Modelos Habilitados para ti:</h3><ul>";
    
    if (isset($data['models'])) {
        foreach ($data['models'] as $model) {
            // Filtramos solo los que sirven para generar contenido
            if (strpos($model['supportedGenerationMethods'][0], 'generateContent') !== false) {
                $name = str_replace('models/', '', $model['name']);
                echo "<li><strong>" . $name . "</strong> (<code>" . $model['name'] . "</code>)</li>";
            }
        }
    }
    echo "</ul>";
    echo "<hr><strong>Copia uno de los nombres en negrita (ej: gemini-pro) y pégalo en el chat.</strong>";
} else {
    echo "<h3 style='color:red'>❌ Error al listar modelos:</h3>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    echo "<p>Si ves un error de 'API key not valid', debes crear una nueva clave en Google AI Studio.</p>";
}
?>