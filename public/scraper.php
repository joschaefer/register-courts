<?php

namespace Knowledge;

use Ramsey\Uuid\Uuid;

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL);
ini_set('log_errors', true);
ini_set('error_log', __DIR__ . '/../errors.log');
ini_set('display_errors', false);
header_remove('X-Powered-By');

require __DIR__ . '/../vendor/autoload.php';

function getPage(string $uri, array $params = []) {

    $query = sizeof($params) > 0 ? http_build_query($params) : '';
    $data = file_get_contents('http://www.justizadressen.nrw.de' . $uri . $query);

    if(false === $data) {

        header('HTTP/1.1 500 Internal Server Error');
        echo json([
            'error' => 'NO_CONNECTION',
            'error_message' => 'Could not establish a connection to the API endpoint.',
        ]);
        exit;

    }

    return $data;

}

function json(array $output): string {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    return json_encode($output, JSON_UNESCAPED_UNICODE);
}

function clean(string $text): string {
    return html_entity_decode(trim($text));
}

$results = [];
$laender = ['BW', 'BAY', 'B', 'BRA', 'BRE', 'HH', 'HES', 'MV', 'NS', 'NRW', 'RPF', 'SAA', 'SAC', 'SAH', 'SH', 'TH'];

$pattern = '/<td class="adbtd1" headers="adbgericht"><strong>(.*?)(?: - Vereinsregister -)?<\/strong><\/td>(?:.*?)<strong>Postanschrift:<\/strong><br>(?:(\N+)<br>)?([0-9]{5}) (.*?)<\/td>(?:.*?)<span class="adbkleiner">XJustiz-ID: ([A-Z0-9]{5,8})<\/span>/is';

foreach($laender as $land) {

    $register = getPage('/og.php?suchen1=&gerausw=VREG&landausw=' . $land);

    preg_match_all($pattern, $register, $matches, PREG_SET_ORDER);

    foreach($matches as $match) {

        $results[clean($match[1])] = [
            'id' => clean($match[5]),
            'name' => clean($match[1]),
            'address' => [
                'additional_line' => '– Vereinsregister –',
                'street' => clean($match[2]),
                'zip_code' => clean($match[3]),
                'city' => clean($match[4]),
                'country' => 'DE',
            ],
        ];

    }

}

ksort($results);

header('HTTP/1.1 200 OK');

echo count($results) . " Ergebnisse:<br><br>\n";

foreach($results as $result) {

    $courtId = Uuid::uuid4();
    $addressId = Uuid::uuid4();

    echo "INSERT INTO `addresses` (`id`, `additional_line`, `street`, `zip_code`, `city`, `country`) VALUES(UUID_TO_BIN('" . $addressId . "'), '" . $result['address']['additional_line'] . "', '" . $result['address']['street'] . "', '" . $result['address']['zip_code'] . "', '" . $result['address']['city'] . "', '" . $result['address']['country'] . "');<br>\n";
    echo "INSERT INTO `register_courts` (`id`, `xjustiz-id`, `name`, `address`) VALUES (UUID_TO_BIN('" . $courtId . "'), '" . $result['id'] . "', '" . $result['name'] . "', UUID_TO_BIN('" . $addressId . "'));<br>\n";

}
