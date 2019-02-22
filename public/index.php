<?php

namespace Knowledge;

mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');
date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL);
ini_set('log_errors', true);
ini_set('error_log', __DIR__ . '/../errors.log');
ini_set('display_errors', false);
header_remove('X-Powered-By');

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

function json(array $output) : string {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    return json_encode($output, JSON_UNESCAPED_UNICODE);
}

function clean(string $text) : string {
    return html_entity_decode(trim($text));
}

$city = trim(filter_input(INPUT_GET, 'city', FILTER_SANITIZE_STRING));

if(empty($city)) {

    header('HTTP/1.1 400 Bad Request');
    echo json([
        'error' => 'BAD_REQUEST',
        'error_message' => 'A city must be specified.',
    ]);
    exit;

}

$results = [];
$cities = getPage('/og.php?suchen=&', [
    'ort' => $city,
]);
$courts = [];
$registers = [];

if(mb_strstr($cities, 'Mehrere Angaben sind zutreffend, bitte wählen Sie aus den folgenden Angaben:')) {

    $pattern = '/<a href="(.*?)&MD=">(?:[0-9]{5}) (.*?)<\/a>/i';
    preg_match_all($pattern, $cities, $matches, PREG_SET_ORDER);

    $ids = [];

    foreach($matches as $match) {

        $uri = $match[1];
        $name = clean($match[2]);

        $id = substr($uri, 11, 7);

        if(!mb_strstr($city, $name) || in_array($id, $ids)) {
            continue;
        }

        $ids[] = $id;
        $courts[] = getPage($uri);

    }

} else {

    $courts = [$cities];

}

foreach($courts as $court) {

    $pattern = '/<span class="adbkonz">Vereinsregistersachen: (.*?)<span class="adbkleiner"><a href="(\N+)">Details<\/a><\/span><\/span>/i';

    if(1 === preg_match($pattern, $court, $match)) {

        $registers[$match[1]] = getPage($match[2]);

    }

}

foreach($registers as $name => $register) {

    $pattern = '/<td class="adbtd1" headers="adbgericht"><strong>(.*?)(?: - Vereinsregister -)?<\/strong><\/td>(?:.*?)<strong>Postanschrift:<\/strong><br>(?:(.*?)<br>)?([0-9]{5}) (\N+)<\/td>/is';

    if(1 === preg_match($pattern, $register, $match)) {

        $results[] = [
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

header('HTTP/1.1 200 OK');
echo json($results);
