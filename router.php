<?php
// Solo para pruebas con "php -S": bloquea la carpeta de datos (en Hostinger lo hace el .htaccess)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/vc-data(/|$)#', $uri)) { http_response_code(403); echo 'Forbidden'; return true; }
return false;
