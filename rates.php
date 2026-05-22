<?php
// rates.php
function getNbrbRates() {
    $cacheFile = __DIR__ . '/rates_cache.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    // Официальные ID валют в API НБ РБ: USD (431), EUR (451), RUB (456), CNY (462)
    $ids = ['USD' => 431, 'EUR' => 451, 'RUB' => 456, 'CNY' => 462];
    $rates = ['BYN' => 1.0, 'USD' => 3.25, 'EUR' => 3.55, 'RUB' => 0.035, 'CNY' => 0.45]; 

    foreach ($ids as $code => $id) {
        // КРИТИЧНО: Используем полный и верный адрес официального API Нацбанка
        $resp = @file_get_contents("nbrb.by" . $id);
        if ($resp) {
            $data = json_decode($resp, true);
            if (isset($data['Cur_OfficialRate'])) {
                $scale = isset($data['Cur_Scale']) ? (float)$data['Cur_Scale'] : 1.0;
                // Рассчитываем стоимость строго за 1 ЕДИНИЦУ валюты в BYN
                $rates[$code] = (float)$data['Cur_OfficialRate'] / $scale;
            }
        }
    }
    file_put_contents($cacheFile, json_encode($rates));
    return $rates;
}

$globalRates = getNbrbRates();
?>