<?php
/**
 * speedtest_api.php - 서버 속도 테스트 API 
 * 
 * 이 파일은 서버의 네트워크 속도를 테스트하고 결과를 JSON으로 반환합니다.
 * 외부 서버의 속도를 테스트할 수도 있습니다.
 */

error_reporting(0);

// CORS 설정
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Encoding, Content-Type');

// 캐시 방지
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

/**
 * 테스트할 서버 목록
 * 기본적으로 미리 정의된 몇 개의 서버를 포함하고 있습니다.
 * 필요에 따라 수정하거나 GET 파라미터로 전달 가능합니다.
 */
$defaultServers = [
    [
        "name" => "Local Server",
        "server" => "//" . $_SERVER['HTTP_HOST'] . "/",
        "dlURL" => "backend/garbage.php",
        "ulURL" => "backend/empty.php",
        "pingURL" => "backend/empty.php",
        "getIpURL" => "backend/getIP.php"
    ]
];

/**
 * cURL을 이용한 다운로드 속도 측정
 * 
 * @param string $url 테스트할 URL
 * @param int $timeout 타임아웃 (초)
 * @return array 다운로드 속도(Mbps)와 소요 시간(ms)
 */
function testDownloadSpeed($url, $timeout = 5) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $start = microtime(true);
    $data = curl_exec($ch);
    $end = microtime(true);
    
    $size = strlen($data);
    $time = ($end - $start);
    $speed = ($size * 8) / ($time * 1000000); // Mbps로 변환
    
    curl_close($ch);
    
    return [
        'speed' => round($speed, 2),
        'time' => round($time * 1000, 2) // ms로 변환
    ];
}

/**
 * cURL을 이용한 업로드 속도 측정
 * 
 * @param string $url 테스트할 URL
 * @param int $size 업로드할 크기 (바이트)
 * @param int $timeout 타임아웃 (초)
 * @return array 업로드 속도(Mbps)와 소요 시간(ms)
 */
function testUploadSpeed($url, $size = 1000000, $timeout = 5) {
    // 임의의 데이터 생성
    $data = str_repeat('x', $size);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $start = microtime(true);
    curl_exec($ch);
    $end = microtime(true);
    
    $time = ($end - $start);
    $speed = ($size * 8) / ($time * 1000000); // Mbps로 변환
    
    curl_close($ch);
    
    return [
        'speed' => round($speed, 2),
        'time' => round($time * 1000, 2) // ms로 변환
    ];
}

/**
 * cURL을 이용한 핑 테스트
 * 
 * @param string $url 테스트할 URL
 * @param int $count 테스트 횟수
 * @return array 평균 핑(ms)과 지터(ms)
 */
function testPing($url, $count = 3) {
    $times = [];
    
    for ($i = 0; $i < $count; $i++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $start = microtime(true);
        curl_exec($ch);
        $end = microtime(true);
        
        curl_close($ch);
        
        $times[] = ($end - $start) * 1000; // ms로 변환
        
        // 잠시 기다림
        usleep(100000); // 0.1초
    }
    
    // 평균 핑
    $avgPing = array_sum($times) / count($times);
    
    // 지터 계산 (연속된 핑 간의 차이의 평균)
    $jitter = 0;
    if ($count > 1) {
        $diffs = [];
        for ($i = 1; $i < $count; $i++) {
            $diffs[] = abs($times[$i] - $times[$i - 1]);
        }
        $jitter = array_sum($diffs) / count($diffs);
    }
    
    return [
        'ping' => round($avgPing, 2),
        'jitter' => round($jitter, 2)
    ];
}

// API 매개변수 처리
$servers = $defaultServers;

// 사용자가 서버 URL을 제공한 경우
if (isset($_GET['server'])) {
    $userServer = [
        "name" => "User Specified Server",
        "server" => $_GET['server'],
        "dlURL" => isset($_GET['dlURL']) ? $_GET['dlURL'] : "backend/garbage.php",
        "ulURL" => isset($_GET['ulURL']) ? $_GET['ulURL'] : "backend/empty.php",
        "pingURL" => isset($_GET['pingURL']) ? $_GET['pingURL'] : "backend/empty.php",
        "getIpURL" => isset($_GET['getIpURL']) ? $_GET['getIpURL'] : "backend/getIP.php"
    ];
    
    // URL이 //로 시작하면 프로토콜 추가
    if (strpos($userServer["server"], '//') === 0) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https:' : 'http:';
        $userServer["server"] = $protocol . $userServer["server"];
    }
    
    // URL이 /로 끝나지 않으면 추가
    if (substr($userServer["server"], -1) !== '/') {
        $userServer["server"] .= '/';
    }
    
    // 사용자 지정 서버만 테스트
    $servers = [$userServer];
}

// 테스트 모드
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'all';
$timeout = isset($_GET['timeout']) ? intval($_GET['timeout']) : 5;
$pingCount = isset($_GET['pingCount']) ? intval($_GET['pingCount']) : 3;

// 결과 저장 배열
$results = [];

foreach ($servers as $server) {
    $serverUrl = $server["server"];
    $result = [
        'name' => $server["name"],
        'url' => $serverUrl
    ];
    
    // 다운로드 테스트
    if ($mode === 'all' || $mode === 'download') {
        $dlUrl = $serverUrl . $server["dlURL"];
        $dlResult = testDownloadSpeed($dlUrl, $timeout);
        $result['download'] = $dlResult;
    }
    
    // 업로드 테스트
    if ($mode === 'all' || $mode === 'upload') {
        $ulUrl = $serverUrl . $server["ulURL"];
        $ulResult = testUploadSpeed($ulUrl, 1000000, $timeout);
        $result['upload'] = $ulResult;
    }
    
    // 핑 테스트
    if ($mode === 'all' || $mode === 'ping') {
        $pingUrl = $serverUrl . $server["pingURL"];
        $pingResult = testPing($pingUrl, $pingCount);
        $result['ping'] = $pingResult;
    }
    
    $results[] = $result;
}

// 최종 결과 반환
echo json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'servers' => $results
], JSON_PRETTY_PRINT);