<?php
include_once 'vendor/autoload.php';
use simplehtmldom\HtmlWeb;

function serializeToJSON($obj) {
    return json_encode($obj, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
}

function getApplicationLink($applicationId) {
    return 'https://eproperty.casey.vic.gov.au/T1PRProd/WebApps/eProperty/P1/eTrack/eTrackApplicationDetails.aspx?r=P1.WEBGUEST&f=%24P1.ETR.APPDET.VIW&ApplicationId=' . $applicationId;
}

function fetchPermitsFromCasey() {
    $URL = 'https://eproperty.casey.vic.gov.au/T1PRProd/WebApps/eProperty/P1/eTrack/eTrackApplicationSearchResults.aspx?Field=S&Period=L14&r=P1.WEBGUEST&f=%24P1.ETR.SEARCH.SL14';
    $client = new HtmlWeb();
    $header = 'Landchecker Application Scraping';

    $html = $client->load($URL);

    if(empty($html)) {
        $error = (object) ["code" => "FAILED_TO_CONNECT", "message" => "Script failed to connect to the website."];
        die(serializeToJSON($error));
    }

    $title = $html->find('title', 0)->plaintext;
    $dateRange = explode(' to ', $html->find('span[id=ctl00_Content_lblFieldValueP1ETRSearchDates]', 0)->plaintext);
    $fromDate =  $dateRange[0];
    $toDate = $dateRange[1];


    foreach($html->find('tr.alternateRow, tr.normalRow') as $article) {
        $item['id'] = $article->find('a', 0)->plaintext;
        $item['applicationLink'] = getApplicationLink($item['id']);
        $item['lodgementDate'] = $article->find('td', 1)->plaintext;
        $item['proposal'] = $article->find('td', 2)->plaintext;
        $item['applicationType'] = $article->find('td', 3)->plaintext;
        $item['categoryDescription'] = $article->find('td', 4)->plaintext;
        $item['address'] = $article->find('td', 5)->plaintext;
        $item['caption'] = $article->find('td', 6)->plaintext;

        $articles[] = $item;
    }
    $data = (object) ["code" => "JOB_SUCESSFUL", "fromDate" => $fromDate, "toDate" => $toDate, "length" => ($articles) ? count($articles) : 0, "data" => $articles];
    $data = serializeToJSON($data);

    $values = (object) ["header" => $header, "title" => $title, "data" => $data];
    return $values;
}

$response = fetchPermitsFromCasey();

$header = $response->header ;
$title = $response->title;
$data = $response->data;

?>

<h1><?= $header ?></h1>
<h3><?= $title ?></h3>
<pre><?= $data ?></pre>