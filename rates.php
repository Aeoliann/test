<?php
// rates.php
// ИСПРАВЛЕНО: Правильный URL API
function getNbrbRates() {
    $cacheFile = __DIR__ . '/rates_cache.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $ids = ['USD' => 431, 'EUR' => 451, 'RUB' => 456, 'CNY' => 462];
    $rates = ['BYN' => 1.0, 'USD' => 3.25, 'EUR' => 3.55, 'RUB' => 0.035, 'CNY' => 0.45];

    foreach ($ids as $code => $id) {
        // ИСПРАВЛЕНО: Полный URL с протоколом
        $url = "https://api.nbrb.by/exrates/rates/" . $id;
        $resp = @file_get_contents($url);
        if ($resp !== false) {
            $data = json_decode($resp, true);
            if (isset($data['Cur_OfficialRate'])) {
                $scale = isset($data['Cur_Scale']) ? (float)$data['Cur_Scale'] : 1.0;
                $rates[$code] = (float)$data['Cur_OfficialRate'] / $scale;
            }
        }
    }
    
    file_put_contents($cacheFile, json_encode($rates));
    return $rates;
}

$globalRates = getNbrbRates();
?>