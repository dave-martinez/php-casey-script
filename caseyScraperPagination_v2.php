<?php

include_once 'vendor/autoload.php';
require_once 'utils/simple_html_dom.php';

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\Chrome\ChromeOptions;

function uploadFileToS3() {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    if(
        !(isset($_ENV['S3_REGION']) &&
        isset($_ENV['S3_BUCKET']) &&
        isset($_ENV['S3_SECRET_KEY']) &&
        isset($_ENV['S3_ACCESS_KEY']))
    ) {
        throw new \Exception('Incomplete S3 credentials. Please check .env file.');
    }
 
    echo 'Uploading file to S3...' . PHP_EOL;

    $s3 = new Aws\S3\S3Client([
        'region'  => $_ENV['S3_REGION'],
        'version' => 'latest',
        'credentials' => [
            'key'    => $_ENV['S3_ACCESS_KEY'],
            'secret' => $_ENV['S3_SECRET_KEY'],
        ]
    ]);

    $date = date('c');
    $key = 'results_' . $date . '.json';

    $result = $s3->putObject([
        'Bucket' => $_ENV['S3_BUCKET'],
        'Key'    => $key,
        'SourceFile' => 'results.json',
        'StorageClass' => 'REDUCED_REDUNDANCY',
        'ACL'    => 'public-read',
    ]);

    echo 'Results JSON file link: ' . PHP_EOL . PHP_EOL . $result['ObjectURL'] . PHP_EOL . PHP_EOL;
    echo 'If command + click on link gives you access denied, try copying and pasting the link to your browser.';
}


function getTableFromHTML($rawHTML) {
    $html = new simple_html_dom();
    $html->load($rawHTML);


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

    return $articles;
}

function serializeToJSON($obj) {
    return json_encode($obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function getApplicationLink($applicationId) {
    return 'https://eproperty.casey.vic.gov.au/T1PRProd/WebApps/eProperty/P1/eTrack/eTrackApplicationDetails.aspx?r=P1.WEBGUEST&f=%24P1.ETR.APPDET.VIW&ApplicationId=' . $applicationId;
}



function runScript($seleniumServer, $websiteUrl, $outputName, $uploadToS3) {
    $options = new ChromeOptions();
    $options->addArguments(['---headless']);
    $capabilitites = DesiredCapabilities::chrome();
    $capabilitites->setCapability(ChromeOptions::CAPABILITY_W3C, $options);

    $driver = RemoteWebDriver::create($seleniumServer, $capabilitites);
    $driver->get($websiteUrl);


    $response =  $driver->findElement(WebDriverBy::tagName("body"))->getAttribute('innerHTML');
    $html = new simple_html_dom();
    $html->load($response);

    $dateRange = explode(' to ', $html->find('span[id=ctl00_Content_lblFieldValueP1ETRSearchDates]', 0)->plaintext);
    $fromDate =  $dateRange[0];
    $toDate = $dateRange[1];

    $currentPage = 1;
    $totalCount = 0;
    $hasNextPage = true;
    $fetchedData = [];

    while($hasNextPage) {
        $pages =  $driver->findElements(WebDriverBy::cssSelector("tr.pagerRow table tr td a"));
        $response =  $driver->findElement(WebDriverBy::tagName("body"))->getAttribute('innerHTML');

        $parsedData = getTableFromHTML($response);
        $fetchedData = array_merge($fetchedData, $parsedData);

        echo 'Current Page: ' . $currentPage . PHP_EOL;
        echo 'Total for Page ' . $currentPage . ': ' . count($parsedData) . PHP_EOL . PHP_EOL;

        if(count($pages) > 0) {
            $lastPage = end($pages)->getAttribute('innerHTML') + 1;
            foreach($pages as $page) {
                $selectedPage = $page->getAttribute('innerHTML');
                if($currentPage == $lastPage) {
                    $hasNextPage = false;
                    break;
                } else if ($selectedPage < $currentPage) {
                    continue;
                } else if((int)$selectedPage > (int)$currentPage) {
                    $currentPage = $selectedPage;
                    sleep(1);
                    $page->click();
                    break;
                }     
            }
        } else {
            break;
        }
        sleep(1);
    } 

    $data = (object) ["code" => "JOB_SUCESSFUL", "fromDate" => $fromDate, "toDate" => $toDate, "length" => count($fetchedData), "data" => $fetchedData];

    $serializedJSON =  serializeToJSON($data);

    echo 'Total items fetched: ', count($fetchedData) . PHP_EOL;
    echo 'Writing results to file ' . $outputName . PHP_EOL;
    $fp = fopen($outputName, 'w');
    fwrite($fp, $serializedJSON);
    fclose($fp);
    echo 'Results results successfully saved' . PHP_EOL;
    echo 'Open file here: ' . realpath('./results.json') . PHP_EOL . PHP_EOL;

    if($uploadToS3) {
        uploadFileToS3();
    }
}



// Main Script
function main() {
    $OUTPUT_FILENAME = 'results.json';
    $SELENIUM_SERVER = 'http://localhost:4444/wd/hub';
    $URL = 'https://eproperty.casey.vic.gov.au/T1PRProd/WebApps/eProperty/P1/eTrack/eTrackApplicationSearchResults.aspx?Field=S&Period=L7&r=P1.WEBGUEST&f=%24P1.ETR.SEARCH.SL7';

    try {
        $uploadToS3 = true;
        runScript($SELENIUM_SERVER, $URL, $OUTPUT_FILENAME, $uploadToS3);
    } catch (Throwable $e) {
        $data = (object) ["code" => $e->getCode(), "message" => $e->getMessage(), "stackTrace" => $e->getTrace()];
        $serializedJSON =  serializeToJSON($data);
        $fp = fopen($OUTPUT_FILENAME, 'w');
        fwrite($fp, $serializedJSON);
        fclose($fp);

        echo 'An error has occured: ' . PHP_EOL;
        echo $e->getMessage();
    }
}

main();





