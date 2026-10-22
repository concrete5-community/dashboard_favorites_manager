<?php

// Credentials and cookie jars stay inside the explicitly selected test container.
if (PHP_SAPI !== 'cli' || getenv('DFM_DISPOSABLE_TEST') !== '1' || trim(file_get_contents('/etc/hostname')) !== '84915c73cb61') {
    throw new RuntimeException('Run only in the selected disposable container.');
}
$state = json_decode(file_get_contents(__DIR__ . '/state.json'), true);
$action = $argv[1] ?? 'before';
$base = 'http://127.0.0.1';
$checks = 0;
$assert = function ($value, $name) use (&$checks) {
    if (!$value) throw new RuntimeException($name);
    $checks++;
    echo "PASS $name\n";
};
$dom = function ($html) {
    $document = new DOMDocument();
    @$document->loadHTML($html);
    return new DOMXPath($document);
};
foreach (['a' => true, 'b' => false] as $suffix => $enabled) {
    $cookieFile = __DIR__ . '/http-' . $suffix . '.cookies';
    if ($action === 'before' && is_file($cookieFile)) unlink($cookieFile);
    $request = function ($path, $data = null, $ajax = false) use ($base, $cookieFile) {
        $curl = curl_init(str_starts_with($path, 'http') ? $path : $base . $path);
        $headers = $ajax ? ['X-Requested-With: XMLHttpRequest', 'Accept: application/json'] : [];
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers]);
        if ($data !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($body === false || $status !== 200) throw new RuntimeException('HTTP failure: ' . $status . ' ' . $error);
        if (is_file($cookieFile)) chmod($cookieFile, 0600);
        return $body;
    };
    if ($action === 'before') {
        $login = $dom($request('/index.php/login'));
        $form = $login->query('//form[.//input[@name="uName"]]')->item(0);
        $assert($form !== null, 'Login form available for user ' . $suffix);
        $data = [];
        foreach ($login->query('.//input[@type="hidden"]', $form) as $input) $data[$input->getAttribute('name')] = $input->getAttribute('value');
        $data['uName'] = $state['users'][$suffix]['name'];
        $data['uPassword'] = $state['users'][$suffix]['password'];
        $request($form->getAttribute('action'), $data);
        $html = $request('/index.php/dashboard/welcome/favorites_manager');
        $xpath = $dom($html);
        $manager = $xpath->query('//div[@class="dashboard-favorites-manager"]')->item(0);
        $assert($manager !== null, 'Authenticated manager loaded for user ' . $suffix);
        // Rows are populated by JS; inspect the actual server-provided data.
        $assert(substr_count($html, 'Disposable 0') >= 1, 'Live fixture favorites present before uninstall for user ' . $suffix);
    } elseif ($action === 'dashboard') {
        $html = $request('/index.php/dashboard/welcome');
        $xpath = $dom($html);
        $assert($xpath->query('//a[contains(@href,"/dashboard/dfm_orphan_fixture")]')->length === 0, 'Dashboard has no orphan links for user ' . $suffix);
    } elseif ($action === 'manager') {
        $html = $request('/index.php/dashboard/welcome/favorites_manager');
        $assert(strpos($html, 'Disposable 0') === false && strpos($html, 'Disposable 1') === false && strpos($html, 'Disposable 2') === false,
            'Manager receives no orphan favorites for user ' . $suffix);
    } else {
        throw new RuntimeException('Unknown HTTP action');
    }
    $hasToolbar = preg_match('/window\.DashboardFavoritesManagerToolbar=(.*);<\/script>/', $html, $match);
    $assert((bool) $hasToolbar === $enabled, 'Star menu setting honored for user ' . $suffix);
    if ($enabled) {
        $config = json_decode($match[1], true);
        $assert(count($config['favorites']) === ($action === 'before' ? 5 : 2), 'Star menu receives expected favorites for user ' . $suffix);
        $assert(strpos($html, 'toolbar_favorites.js') !== false, 'Star menu JS still loaded');
    }
}
echo "$checks HTTP checks passed ($action).\n";
