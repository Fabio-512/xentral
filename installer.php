<?php

ini_set('display_errors', true);
ini_set('error_reporting', E_ERROR);
date_default_timezone_set('UTC');

$scheme = 'http';
if (isset($_SERVER['REQUEST_SCHEME']) && !empty($_SERVER['REQUEST_SCHEME'])) {
    $scheme = $_SERVER['REQUEST_SCHEME'];
}
if (isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $scheme = 'https';
}

// URLs bestimmen
$scriptUri = sprintf('%s://%s%s', $scheme, $_SERVER['HTTP_HOST'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$setupUri = str_replace('/installer.php', '/www/setup/setup.php', $scriptUri);

// GET-Parameter verarbeiten
$step = isset($_GET['step']) ? filter_var($_GET['step'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) : 'init';

// Bei allererster Ausführung > hier vorbei und HTML unten ausgeben
// Bei allen anderen Ausführungen > JSON zurückliefern
if ($step !== 'init') {
    try {
        $result = Installer::runStep($step);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Ersten Schritt ermitteln
$step = Installer::getNextStep();


class Installer
{
    private static $remoteMetaFile =
        'https://update.xentral.biz/download/installer.json';

    private static $steps = [
        ['name' => 'cleanUpBeforeInstall', 'progress_offset' => 0],
        ['name' => 'checkRequirements', 'progress_offset' => 1],
        ['name' => 'loadInstallerMeta', 'progress_offset' => 2],
        ['name' => 'downloadZip', 'progress_offset' => 3],
        ['name' => 'verifyZip', 'progress_offset' => 69],
        ['name' => 'extractZip', 'progress_offset' => 70],
        ['name' => 'cleanUpAfterInstall', 'progress_offset' => 98],
        ['name' => 'redirectToSetup', 'progress_offset' => 99],
    ];

    public static function runStep($step)
    {
        $currentStep = $step;
        $stepNames = array_column(self::$steps, 'name');
        if (!in_array($step, $stepNames, true)) {
            $step = $stepNames[0];
        }

        $progress = forward_static_call([__CLASS__, $step]);

        // Schritt wurde erfolgreich abgeschlossen
        if ($progress === 'ok') {
            $step = self::getNextStep($step);
        }

        return [
            'step' => $step,
            'progress' => self::calculateTotalProgress($currentStep, $progress),
        ];
    }

    public static function getNextStep($step = null)
    {
        $stepNames = array_column(self::$steps, 'name');
        if (empty($step)) {
            return $stepNames[0];
        }

        $currentIndex = array_search($step, $stepNames, true);
        $nextIndex = $currentIndex + 1;

        return isset($stepNames[$nextIndex]) ? $stepNames[$nextIndex] : $stepNames[$currentIndex];
    }

    public static function cleanUpBeforeInstall()
    {
        $files = [
            __DIR__ . '/installer.json',
            __DIR__ . '/installer.zip',
            __DIR__ . '/zipindex.tmp',
        ];

        forward_static_call([__CLASS__, 'removeFiles'], $files);

        return 'ok';
    }

    public static function checkRequirements()
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Curl-Extension is missing.');
        }
        if (!class_exists(ZipArchive::class, true)) {
            throw new RuntimeException('Zip-Extension is missing.');
        }
        if (!is_writable(__DIR__)) {
            throw new RuntimeException('Current directory is not writable.');
        }

        return 'ok';
    }

    public static function loadInstallerMeta()
    {
        $localMetaFile = __DIR__ . '/installer.json';
        $file = fopen($localMetaFile, 'wb+');
        if (!is_resource($file)) {
            throw new RuntimeException(sprintf('Could not create meta file: %s', $localMetaFile));
        }

        $ch = curl_init(self::$remoteMetaFile);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_FILE, $file);
        $curlResult = curl_exec($ch);
        if ($curlResult === false) {
            throw new RuntimeException(sprintf('Could not load meta file. Error: %s', curl_error($ch)));
        }
        curl_close($ch);
        fclose($file);

        return 'ok';
    }

    public static function downloadZip()
    {
        $localMetaFile = __DIR__ . '/installer.json';
        $file = fopen($localMetaFile, 'rb');
        if (!is_resource($file)) {
            throw new RuntimeException(sprintf('Could not read meta file: %s', $localMetaFile));
        }

        $meta = json_decode(fread($file, filesize($localMetaFile)), true);
        if (empty($meta['uri']) || empty($meta['size']) || empty($meta['hash'])) {
            throw new RuntimeException('Installer meta file is not valid.');
        }

        $download = new FileDownloader($meta['uri'], $meta['size'], __DIR__ . '/installer.zip');
        $download->download();

        return $download->getProgress();
    }

    public static function verifyZip()
    {
        $meta = json_decode(file_get_contents(__DIR__ . '/installer.json'), true);
        $hashDownloaded = sha1_file(__DIR__ . '/installer.zip');
        $hashExpected = $meta['hash'];

        if ($hashDownloaded !== $hashExpected) {
            throw new RuntimeException('Hash of downloaded file is not matching.');
        }

        return 'ok';
    }

    public static function extractZip()
    {
        $zip = new ZipExtractor(__DIR__ . '/installer.zip', __DIR__);
        $zip->extract();

        return $zip->getProgress();
    }

    public static function cleanUpAfterInstall()
    {
        $files = [
            __DIR__ . '/installer.json',
            __DIR__ . '/installer.zip',
            __DIR__ . '/zipindex.tmp',
            __FILE__,
        ];

        forward_static_call([__CLASS__, 'removeFiles'], $files);

        return 'ok';
    }

    public static function redirectToSetup()
    {
        return 'ok';
    }

    private static function calculateTotalProgress($currentStep, $stepProgress)
    {
        if ($stepProgress === 'ok') {
            $stepProgress = 100;
        }

        $stepNames = array_column(self::$steps, 'name');
        $stepOffsets = array_column(self::$steps, 'progress_offset');
        $stepIndex = array_search($currentStep, $stepNames, true);

        $progressOffset = $stepOffsets[$stepIndex];
        $progressOffsetNext = isset($stepOffsets[$stepIndex + 1]) ? $stepOffsets[$stepIndex + 1] : 100;

        $progressOffsetDiff = $progressOffsetNext - $progressOffset;
        $progressOffsetPercent = $progressOffsetDiff / 100 * $stepProgress;

        return (int)($progressOffset + $progressOffsetPercent);
    }

    private static function removeFiles(array $files)
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

class FileDownloader
{
    private $uri;
    private $file;
    private $stepSize;
    private $totalSize;
    private $destination;
    private $currentSize;

    public function __construct($uri, $total, $destination)
    {
        $this->uri = $uri;
        $this->stepSize = 1000000;
        $this->totalSize = (int)$total;
        $this->currentSize = 0;
        $this->destination = $destination;

        if (is_file($destination)) {
            $this->currentSize = filesize($destination);
        }
    }

    public function download()
    {
        if ($this->currentSize >= $this->totalSize) {
            return;
        }

        if (!$this->file = fopen($this->destination, 'ab')) {
            throw new RuntimeException(sprintf('Could not open file "%s" for writing.', $this->destination));
        }

        $range = sprintf('%s-%s', $this->currentSize, $this->currentSize + $this->stepSize);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->uri);
        curl_setopt($ch, CURLOPT_RANGE, $range);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_FILE, $this->file);
        $curlResult = curl_exec($ch);
        if ($curlResult === false) {
            throw new RuntimeException(sprintf('Could not download installer zip file. Error: %s', curl_error($ch)));
        }
        curl_close($ch);
        fclose($this->file);

        $this->currentSize = filesize($this->destination);
    }

    public function getProgress()
    {
        if ($this->currentSize >= $this->totalSize) {
            return 'ok';
        }

        return (int)(($this->currentSize / $this->totalSize) * 100);
    }
}

class ZipExtractor
{
    private $progress;
    private $currentIndex;
    private $destination;
    private $indexFile;
    private $stepSize;
    private $file;

    public function __construct($file, $destination)
    {
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Zip file "%s" not found.', $file));
        }
        if (!is_dir($destination)) {
            throw new RuntimeException(sprintf('Extraction destination "%s" is not a directory.', $destination));
        }

        $this->file = $file;
        $this->progress = 0;
        $this->stepSize = 1000;
        $this->destination = $destination;
        $this->indexFile = __DIR__ . '/zipindex.tmp';
        $this->currentIndex = $this->readIndexFromFile();
    }

    private function readIndexFromFile()
    {
        if (!is_file($this->indexFile)) {
            touch($this->indexFile);
        }

        return (int)file_get_contents($this->indexFile);
    }

    private function writeIndexToFile()
    {
        if (!is_file($this->indexFile)) {
            touch($this->indexFile);
        }

        file_put_contents($this->indexFile, $this->currentIndex);
    }

    public function extract()
    {
        $zip = new ZipArchive;
        if ($zip->open($this->file) !== true) {
            throw new RuntimeException(sprintf('Could not open zip file: %s', $this->file));
        }

        $fileCount = $zip->numFiles;
        $index = $this->currentIndex === 0 ? 0: $this->currentIndex + 1;
        $break = $index + $this->stepSize - 1;

        for (; $index < $fileCount; $index++) {
            $info = $zip->statIndex($index);
            $zip->extractTo($this->destination, $info['name']);

            // Fortschritt in Prozent
            $this->progress = (int)(($index + 1) / $fileCount * 100);
            $this->currentIndex = $index;

            if ($index >= $break) {
                $this->writeIndexToFile();
                $zip->close();
                return;
            }
        }

        // Alles entpackt
        $this->writeIndexToFile();
        $zip->close();

        $this->progress = 'ok';
    }

    public function getProgress()
    {
        return $this->progress;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Xentral One File Installer</title>
  <script src="https://code.jquery.com/jquery-3.3.1.min.js" integrity="sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8=" crossorigin="anonymous"></script>
  <link href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAMAAAAoLQ9TAAAABGdBTUEAALGPC/xhBQAAACBjSFJNAAB6JgAAgIQAAPoAAACA6AAAdTAAAOpgAAA6mAAAF3CculE8AAACYVBMVEX////////9/v78/v78/v79/v7////////+/v79/v7+///////9/v79/v7+/v77/f39/v79/v79/v79/v77/f38/f77/f79/v7////8/f3////8/v7////8/v7+///+///8/f7+/v78/v78/v7+/v7+///+/v7+///+///9/v79/v76/P38/f39/v7+/v7+/v76/Pz8/f38/f37/f3+/v7+/v78/f38/v79/v7+///+/v/9/v76/Pz8/v7+///+///8/v78/v7////7/f38/f7+///+///+///8/f78/f7////9/v7////+///8/f36/Pz+/v79/v77/f79/v7+/v76/Pz+/v79/v78/v76/f79/v7+/v7////////9/v78/v78/v79/v/////////O7O/m9ffj9PbP7fDx+vqF0dim3eOh2+GJ0tn0+/v1+/xkxM5PvMfH6u3F6exMu8ZqxtD4/P3B2drq8vNsx9Aur70tr7xxydLn8PHD2tzX5+hqpaj5+/twydJ0ytP1+flopKfe6+t7rrFIj5P0+/z1+/xCi4+EtLb2+vobcHRUlppMkZUhdHioyswAW2BWmJxOlJcAXGKwz9Gtzc8AW2FMk5b4+/tFjpIAXWK10tP4+/secndMkZVEjJAldnt/sbNCi4/2/Pz3/Pw7h4uItrjZ6OlmoqX2+vp2y9N6zNXy9/dloaXf7Oy+19no8fJyydIsrrwrrrx4y9Tl7/DA2dr4/P1pxs9KusXB5+u+5upHucVvyNHz+vuH0dmf2uCa2N+K0tr2/PzM7O/i9Pbe8/XN7O////9K+uNHAAAAZHRSTlMAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAkdTC+wAAAAFiS0dEAf8CLd4AAAAHdElNRQfiCBcKHCi0s0S/AAABDElEQVQY0w3Mg0IDABQF0HezbdstLtu2bRvL9rLtWraWrb+q8wGHCMjJBQ8vHz/y8iEgSELCKCgsEhGFWHFJKcQlSFJKGmXlFZVV1TW1kJGVI1Yd5KFQ39DYpKikjOYWam1rV1FVU+/o1NDUQld3D6G3D9o66B+Arp4+exCEoeERwMDQyBgmo2OmIDOMT0yag2EBy6npGViRNWbn5m1gy8TC4tIy7Agrq2v2cHB0cobL+obr/7G55ebugW0OPL28d3ZBe/sH8PH1OzyCfwCOT07p7ByBQcEXl1fXIaFhuOFSeEQkbu/uHx6fnqMQHRNLcfF4eX1LABLfPz6RlEwpqfj6Rlp6RiZ+fpGV/Qdh+0+Eu62EewAAACV0RVh0ZGF0ZTpjcmVhdGUAMjAxOC0wOC0yM1QxMjoyODozOCswMjowMJbubHgAAAAldEVYdGRhdGU6bW9kaWZ5ADIwMTgtMDgtMjNUMTI6Mjg6MzgrMDI6MDDns9TEAAAAGXRFWHRTb2Z0d2FyZQBBZG9iZSBJbWFnZVJlYWR5ccllPAAAAABJRU5ErkJggg==" rel="icon" type="image/x-icon">
  <style type="text/css">
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }
    body {
      width: 100%;
      height: 100%;
      font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
      font-size: 13px;
      color: #444;
      background-color: #FFF;
    }
    #wrapper {
      position: relative;
      width: 100vw;
      height: 100vh;
    }
    #inner {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
    }
    #progress-wrapper {
      position: relative;
      display: block;
      width: 300px;
      height: 57px;
    }
    #progress-overlay {
      position: absolute;
      top: 0;
      right: 0;
      display: block;
      width: 100%;
      height: 100%;
      background: rgba(255, 255, 255, .66);
    }
    #progress-image {
      position: absolute;
      top: 0;
      left: 0;
      display: block;
      width: 300px;
      height: 57px;
      background-image: url('data:image/png;base64,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');
      background-repeat: no-repeat;
      background-position: 0 0;
    }
    #status {
      position: relative;
      width: 290px;
      clear: both;
      margin: 10px 5px;
    }
    #status-label {
      position: absolute;
      top: 0;
      left: 0;
    }
    #status-text {
      position: absolute;
      top: 0;
      right: 0;
      background: white;
      border-left: 2px solid white;
    }
  </style>
</head>
<script>
  var uri = '<?php echo $scriptUri; ?>';
  var step = '<?php echo $step; ?>';
  var setupUri = '<?php echo $setupUri; ?>';
</script>
<script>
  var recursiveCall = function() {
    $.ajax({
      url: uri,
      data: {step: step},
      dataType: 'json',
      success: function(data) {

        if (typeof data === 'undefined' || typeof data.step === 'undefined' || typeof data.progress === 'undefined') {
          $progressOverlay.css('width', '100%');
          alert('Unbekannter Fehler. Installer kann nicht ausgeführt werden.');
          return;
        }

        if (data.step === 'redirectToSetup') {
          window.location.href = setupUri;
          return;
        }

        var statusText;
        switch (data.step) {
            case 'cleanUpBeforeInstall':
            case 'checkRequirements':
                statusText = 'Anforderungen prüfen';
                break;
            case 'loadInstallerMeta':
                statusText = 'Installationsdaten laden';
                break;
            case 'downloadZip':
                statusText = 'ZIP herunterladen';
                break;
            case 'verifyZip':
                statusText = 'ZIP überprüfen';
                break;
            case 'extractZip':
                statusText = 'ZIP entpacken';
                break;
            case 'cleanUpAfterInstall':
                statusText = 'Aufräumen';
                break;
            case 'redirectToSetup':
                statusText = 'Umleiten zu Setup';
                break;
            default:
                statusText = '';
                break;
        }
        $('#status-text').html(statusText);

        var progressWidth = 100 - parseInt(data.progress);
        $progressOverlay.css('width', progressWidth + '%');
        step = data.step;

        window.setTimeout(function() {
          recursiveCall();
        }, 100);
      },
      error: function(jqXHR, textStatus, errorThrown) {
        errorMessage = (typeof jqXHR.responseJSON !== 'undefined' && typeof jqXHR.responseJSON.error !== 'undefined')
            ? jqXHR.responseJSON.error
            : errorThrown;
        alert('ERROR: ' + errorMessage);
      }
    });
  };

  $(document).ready(function() {
    $progressOverlay = $('#progress-overlay');
    recursiveCall();
  });
</script>
<body>
<div id="wrapper">
  <div id="inner">
    <div id="progress-wrapper">
      <div id="progress-image"></div>
      <div id="progress-overlay"></div>
    </div>
    <div id="status">
      <div id="status-label">Setup wird vorbereitet: .........................</div>
      <div id="status-text"></div>
    </div>
  </div>
</div>
</body>
</html>
