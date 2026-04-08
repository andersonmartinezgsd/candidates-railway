<?php
declare(strict_types=1);

$target = './apply/index.php';
$query = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));

if ($query !== '') {
    $target .= '?'.$query;
}

header('Location: '.$target, true, 302);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="0;url=./apply/index.php">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecting…</title>
</head>
<body>
    <p>Redirecting to the application form…</p>
</body>
</html>
