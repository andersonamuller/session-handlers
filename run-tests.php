<?php
chdir(__DIR__);
$handlers = ['default', 'files'];
$parallel = 10;
// execute single test
if ($_SERVER['SERVER_PORT'] ?? 0) {

    header('Content-Type: text/plain');

    $path = trim($_SERVER['REQUEST_URI'], '/');
    list($handlerName, $fileName) = explode('/', $path, 2);

    switch ($handlerName) {
        case 'files':
            include 'src/FilesSessionHandler.php';
            $handler = new MintyPHP\FilesSessionHandler();
            break;
        case 'default':
            $handler = new \SessionHandler();
            break;
        default:
            die('invalid handler name');
    }

    include 'src/LoggingSessionHandler.php';
    session_set_save_handler(new MintyPHP\LoggingSessionHandler($handler), true);

    ob_start();

    if (!preg_match('/^[a-z0-9_-]+$/', $fileName)) {
        die('invalid file name');
    }
    if (file_exists("tests/src/$fileName.php")) {
        include "tests/src/$fileName.php";
    }

    // get timestamp and content
    list($msec, $sec) = explode(' ', microtime());
    $timestamp = $sec . substr($msec, 2, 6);
    header("X-Session-Flush-At: $timestamp");
    ob_end_flush();

    die();
}
// start test runner
foreach ($handlers as $handlerName) {
    $serverPids = [];
    for ($j = 0; $j < $parallel; $j++) {
        $port = 9000 + $j;
        $serverPids[] = trim(exec("php -S localhost:$port run-tests.php > tmp/$port.server.log 2>&1 & echo \$!"));
    }
    foreach (glob("tests/*.log") as $testFile) {
        $content = file_get_contents($testFile);
        list($head, $body) = explode("\n===\n", $content, 2);
        $paths = [];
        foreach (explode("\n", trim($head)) as $line) {
            list($count, $path) = explode(' ', $line);
            $paths[$path] = $count;
        }
        $sessionName = '';
        $sessionId = '';
        $responses = [];
        foreach ($paths as $path => $count) {
            $clientPids = [];
            for ($j = 0; $j < $count; $j++) {
                $port = 9000 + $j;
                $clientPids[] = trim(exec("curl -i -sS -b '$sessionName=$sessionId' http://localhost:$port/$handlerName/$path -o tmp/$port.client.log & echo \$!"));
            }
            exec("wait " . implode(' ', $clientPids));
            flush();
            $results = [];
            for ($j = 0; $j < $count; $j++) {
                $port = 9000 + $j;
                list($header, $logFile) = explode("\r\n\r\n", trim(file_get_contents("tmp/$port.client.log")), 2);
                $headerLines = explode("\r\n", $header);
                $headers = [];
                array_shift($headerLines);
                foreach ($headerLines as $headerLine) {
                    list($key, $value) = explode(': ', $headerLine);
                    $headers[$key] = $value;
                }
                $timestamp = $headers['X-Session-Flush-At'];
                if (isset($headers['Set-Cookie'])) {
                    $oldSessionId = $sessionId;
                    list($sessionName, $sessionId) = explode('=', explode(';', $headers['Set-Cookie'])[0]);
                }
                $results[$timestamp] = str_replace([$sessionId, $oldSessionId], ['{{current_random_session_id}}', '{{previous_random_session_id}}'], $logFile);
            }
            ksort($results);
            $responses = array_merge($responses, $results);
        }
        $newbody = implode("\n---\n", $responses);
        if (trim($body)) {
            if ($body != $newbody) {
                echo "$testFile.$handlerName - FAILED\n";
                file_put_contents("$testFile.$handlerName.out", "$head\n===\n$newbody");
            }
        } else {
            file_put_contents($testFile, "$head\n===\n$newbody");
        }
    }
    foreach ($serverPids as $serverPid) {
        exec("kill $serverPid");
    }
}
