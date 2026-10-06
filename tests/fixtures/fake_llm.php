<?php
// Atrapa serwera modeli. Tryb w zmiennej FAKE_LLM_MODE, licznik żądań w FAKE_LLM_COUNTER.
$counter = getenv('FAKE_LLM_COUNTER');
if (is_string($counter) && $counter !== '') {
    file_put_contents($counter, 'x', FILE_APPEND | LOCK_EX);
}
$mode = getenv('FAKE_LLM_MODE');
header('Content-Type: application/x-ndjson');
if ($mode === 'failing') {
    echo json_encode(['error' => 'model runner terminated']) . "\n";
    return;
}
if ($mode === 'silent-then-fast') {
    // Udaje długie przetwarzanie promptu: ani bajtu przez 2 s, potem cała odpowiedź naraz.
    flush();
    sleep(2);
    echo json_encode(['response' => 'Ala ma kota', 'done' => true]) . "\n";
    flush();
    return;
}
foreach (['Ala ', 'ma ', 'kota'] as $chunk) {
    echo json_encode(['response' => $chunk, 'done' => false]) . "\n";
    flush();
    usleep(300000);
}
echo json_encode(['response' => '', 'done' => true]) . "\n";
flush();
