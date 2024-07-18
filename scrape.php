<?php
function url_to_absolute($base_url, $relative_url)
{
    if (substr($relative_url, 0, 2) == '//') {
        $path = "https:" . $relative_url;
        return $path;
    }

    if (parse_url($relative_url, PHP_URL_SCHEME) != '') {
        return $relative_url;
    }

    if ($relative_url[0] == '/') {
        $path = '';
    } else {
        $path = dirname(parse_url($base_url, PHP_URL_PATH)) . '/';
    }

    $abs = parse_url($base_url);
    $abs['path'] = preg_replace('#/\.?/#', '/', $path . $relative_url);

    $abs['path'] = preg_replace('/(?:\/\.\/)|(?:\/[^\/]*\/\.\.\/)/', '/', $abs['path']);

    $protocol_scheme = isset($abs['scheme']) ? $abs['scheme'] . '://' : '';
    $port = isset($abs['port']) ? ':' . $abs['port'] : '';
    $query = isset($abs['query']) ? '?' . $abs['query'] :'';
    $fragment = isset($abs['fragment']) ? '#' . $abs['fragment'] : '';

    return $protocol_scheme .
           $abs['host'] .
           $port .
           $abs['path'] .
           $query .
           $fragment;
}

function getImageSizeFromUrl($imageUrl) {
    $context = stream_context_create([
        'http' => ['method' => 'HEAD']
    ]);

    $headers = @get_headers($imageUrl, 1, $context);

    if ($headers && isset($headers['Content-Length'])) {
        $contentLength = $headers['Content-Length'];
        return (int) $contentLength;
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $url = filter_var($_POST['url'], FILTER_SANITIZE_URL);
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        $html = @file_get_contents($url); 

        if (!$html) {
            echo "Не удалось загрузить содержимое страницы.";
            exit;
        }

        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $images = $dom->getElementsByTagName('img');

        $imageUrls = [];
        foreach ($images as $img) {
            $src = $img->getAttribute('src');
            $absoluteUrl = url_to_absolute($url, $src);
            $imageUrls[] = $absoluteUrl;
        }

        $totalSize = 0;
        $imageData = [];

        $mh = curl_multi_init();
        $handles = [];

        foreach ($imageUrls as $imageUrl) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $imageUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, 1);
            curl_multi_add_handle($mh, $ch);
            $handles[$imageUrl] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running);

        foreach ($handles as $imageUrl => $ch) {
            $response = curl_multi_getcontent($ch);

            $info = curl_getinfo($ch);

            $size = getImageSizeFromUrl($imageUrl);
            
            if (!$size||$size<0) $size = $info['download_content_length'];
            
            if ($size) {
                $totalSize += $size;
                $imageData[] = ['url' => $imageUrl, 'size' => $size];              
            }

            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        $totalSizeMB = $totalSize / (1024 * 1024);
    } else {
        echo "Неверный URL";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Результаты сканирования изображений</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <h1 style="text-align: center;">Результаты сканирования изображений</h1>
    <?php include 'search.php'; ?>
    <div class="image-grid">
        <?php if (!empty($imageData)): ?>
            <?php foreach ($imageData as $index => $data): ?>
                <div class="image-item">
                    <img src="<?php echo htmlspecialchars($data['url']); ?>" alt="Изображение">
                </div>
                <?php if (($index + 1) % 4 === 0): ?>
                    <div style="width: 100%; clear: both;"></div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="no-images">На странице не найдено изображений</p>
        <?php endif; ?>
    </div>
    <p style="text-align: center;">Всего найдено <?php echo count($imageData); ?> изображений размером <?php echo number_format($totalSizeMB, 2); ?> МБ</p>
</body>
</html>
