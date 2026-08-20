<?php

declare(strict_types=1);

namespace XArticlePdf;

final class App
{
    public function __construct(private readonly string $root)
    {
    }

    public function run(): void
    {
        if (isset($_GET['models'])) {
            $this->listModels();
            return;
        }
        if (isset($_GET['history'])) {
            $this->listHistory();
            return;
        }
        $fileId = (string) ($_GET['file'] ?? $_GET['download'] ?? '');
        if ($fileId !== '') {
            $this->serveFile($fileId, isset($_GET['inline']));
            return;
        }
        if (isset($_GET['delete'])) {
            $this->deleteFile((string) $_GET['delete']);
            return;
        }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'POST') {
            $this->generate();
            return;
        }
        $this->home();
    }

    private function home(?string $error = null, string $url = '', string $format = 'pdf', string $lang = '', string $langCustom = ''): void
    {
        $errorHtml = $error !== null ? '<p class="error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>' : '';
        $urlValue = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $pdfChecked = $format === 'md' ? '' : ' checked';
        $mdChecked = $format === 'md' ? ' checked' : '';
        $knownLang = isset(LanguageCatalog::options()[$lang]);
        $useCustom = $lang === '__custom__' || ($lang !== '' && $lang !== 'off' && !$knownLang);
        $langOptions = '<option value="">off</option>';
        foreach (LanguageCatalog::options() as $value => $label) {
            $sel = !$useCustom && $lang === $value ? ' selected' : '';
            $langOptions .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $customSel = $useCustom ? ' selected' : '';
        $langOptions .= '<option value="__custom__"' . $customSel . '>other…</option>';
        $customName = $langCustom !== '' ? $langCustom : ($lang === '__custom__' ? '' : $lang);
        $customValue = htmlspecialchars($useCustom ? $customName : $langCustom, ENT_QUOTES, 'UTF-8');
        $defaultModel = htmlspecialchars(
            getenv('OLLAMA_TRANSLATE_MODEL') ?: 'gemma4:e2b',
            ENT_QUOTES,
            'UTF-8',
        );
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>X CAPTURE</title>
  <link rel="stylesheet" href="/console.css">
</head>
<body>
  <div class="shell">
    <aside class="rail">
      <div class="brand">
        <span class="mark">X CAPTURE</span>
        <span class="sig">LOCAL / NODE-07</span>
      </div>
      {$errorHtml}
      <form id="job" method="post" action="/">
        <div class="k">source</div>
        <input type="url" name="url" placeholder="https://x.com/user/status/…" value="{$urlValue}" required>
        <div class="k">output</div>
        <div class="formats">
          <label><input type="radio" name="format" value="pdf"{$pdfChecked}> PDF</label>
          <label><input type="radio" name="format" value="md"{$mdChecked}> MD</label>
        </div>
        <div class="k">translate</div>
        <select name="lang" id="lang">
          {$langOptions}
        </select>
        <input type="text" name="lang_custom" id="lang_custom" placeholder="language name" value="{$customValue}" hidden>
        <select name="model" id="model" data-default="{$defaultModel}" disabled>
          <option value="">ładowanie modeli…</option>
        </select>
        <button class="run" type="submit">Capture</button>
        <p class="hint">status URL only — …/status/id</p>
      </form>
      <div class="progress" id="progress">
        <div class="bar"><div class="fill" id="fill"></div></div>
        <div class="meta-row">
          <p class="status" id="status">idle</p>
          <p class="timer" id="timer">0:00</p>
        </div>
      </div>
      <section class="history">
        <h2>archive</h2>
        <div id="hist-list"></div>
      </section>
    </aside>
    <section class="stage">
      <div class="stage-head">
        <span id="stage-name">VIEWPORT</span>
        <a id="stage-link" hidden href="#">download</a>
      </div>
      <div class="viewport">
        <div class="idle" id="idle"><b>NO SIGNAL</b><span>awaiting capture</span></div>
        <iframe id="frame" hidden title="podgląd"></iframe>
        <pre id="md-view" hidden></pre>
      </div>
    </section>
  </div>
  <script src="/console.js"></script>
</body>
</html>
HTML;
    }

    private function generate(): void
    {
        $progress = isset($_POST['progress']);
        $url = trim((string) ($_POST['url'] ?? ''));
        $format = (string) ($_POST['format'] ?? 'pdf') === 'md' ? 'md' : 'pdf';
        $targetLang = LanguageCatalog::resolve(
            (string) ($_POST['lang'] ?? ''),
            (string) ($_POST['lang_custom'] ?? ''),
        );
        $parsed = XUrlParser::parse($url);
        if ($parsed === null) {
            if ($progress) {
                $this->beginProgress();
                $this->emit(['stage' => 'error', 'message' => 'To nie wygląda na link do X (x.com / twitter.com).']);
                return;
            }
            $this->home('To nie wygląda na link do X (x.com / twitter.com).', $url, $format, (string) ($_POST['lang'] ?? ''), (string) ($_POST['lang_custom'] ?? ''));
            return;
        }

        set_time_limit($targetLang !== null ? 900 : 180);
        ignore_user_abort(true);
        $jobDir = $this->root . '/storage/tmp/' . bin2hex(random_bytes(8));
        if ($progress) {
            $this->beginProgress();
        }
        try {
            if ($progress) {
                $this->emit(['stage' => 'fetch', 'percent' => 4, 'label' => 'Pobieranie artykułu…']);
            }
            $client = new FxTwitterClient();
            $doc = (new DocumentBuilder($client))->fromParsedUrl($parsed);
            if ($targetLang !== null) {
                $model = trim((string) ($_POST['model'] ?? ''));
                if ($model !== '' && !OllamaCatalog::isValidName($model)) {
                    throw new FetchException('Nieprawidłowa nazwa modelu Ollama.');
                }
                $translator = OllamaTranslator::fromEnvironment($model !== '' ? $model : null);
                if ($progress) {
                    $this->emit(['stage' => 'translate', 'percent' => 8, 'label' => 'Ładowanie modelu…']);
                }
                $translator->warmup(function (int $try, int $max) use ($progress): void {
                    if (!$progress) {
                        return;
                    }
                    $this->emit([
                        'stage' => 'translate',
                        'percent' => 8,
                        'label' => 'Ładowanie modelu… (' . $try . '/' . $max . ')',
                    ]);
                });
                $doc = (new DocumentTranslator($translator))->translate(
                    $doc,
                    $targetLang,
                    function (int $current, int $total) use ($progress): void {
                        if (!$progress) {
                            return;
                        }
                        $percent = 8 + (int) round(80 * ($current / max(1, $total)));
                        $this->emit([
                            'stage' => 'translate',
                            'current' => $current,
                            'total' => $total,
                            'percent' => $percent,
                            'label' => 'Tłumaczenie ' . $current . '/' . $total,
                        ]);
                    },
                );
            }
            if ($progress) {
                $this->emit([
                    'stage' => 'build',
                    'percent' => $targetLang !== null ? 90 : 40,
                    'label' => $format === 'md' ? 'Składanie Markdown…' : 'Składanie PDF…',
                ]);
            }
            if ($format === 'md') {
                $body = (new MarkdownExporter())->build($doc);
                $filename = MarkdownExporter::filename($doc);
                $mime = 'text/markdown; charset=utf-8';
            } else {
                $media = new MediaStore($jobDir . '/media');
                $html = (new HtmlRenderer($media))->render($doc);
                $body = (new PdfExporter($jobDir . '/mpdf'))->build($html);
                $filename = PdfExporter::filename($doc);
                $mime = 'application/pdf';
            }
            if ($progress) {
                $item = $this->archive()->save($body, $filename, $mime, $doc->title, $doc->url, $format);
                $this->emit([
                    'stage' => 'done',
                    'percent' => 100,
                    'label' => 'Gotowe',
                    'item' => $item,
                    'filename' => $filename,
                ]);
                return;
            }
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . (string) strlen($body));
            echo $body;
        } catch (FetchException $e) {
            if ($progress) {
                $this->emit(['stage' => 'error', 'message' => $e->getMessage()]);
                return;
            }
            $this->home($e->getMessage(), $url, $format, (string) ($_POST['lang'] ?? ''), (string) ($_POST['lang_custom'] ?? ''));
        } catch (\Throwable $e) {
            if ($progress) {
                $this->emit(['stage' => 'error', 'message' => 'Nie udało się złożyć pliku: ' . $e->getMessage()]);
                return;
            }
            $this->home('Nie udało się złożyć pliku: ' . $e->getMessage(), $url, $format, (string) ($_POST['lang'] ?? ''), (string) ($_POST['lang_custom'] ?? ''));
        } finally {
            $this->removeDir($jobDir);
        }
    }

    private function listModels(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $models = OllamaCatalog::fromEnvironment()->models();
            echo json_encode(['ok' => true, 'models' => $models], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (FetchException $e) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'models' => [], 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    private function beginProgress(): void
    {
        ini_set('display_errors', '0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
    }

    /**
     * @param array<string, mixed> $event
     */
    private function emit(array $event): void
    {
        echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        flush();
    }

    private function archive(): FileArchive
    {
        return new FileArchive($this->root . '/storage/archive');
    }

    private function listHistory(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['items' => $this->archive()->list()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function deleteFile(string $id): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!$this->archive()->delete($id)) {
            http_response_code(404);
            echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
            return;
        }
        echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function serveFile(string $id, bool $inline): void
    {
        $found = $this->archive()->get($id);
        if ($found === null) {
            http_response_code(404);
            echo 'Brak pliku.';
            return;
        }
        $item = $found['item'];
        $filename = is_string($item['filename'] ?? null) ? $item['filename'] : 'download';
        $mime = is_string($item['mime'] ?? null) ? $item['mime'] : 'application/octet-stream';
        if ($inline && str_contains($mime, 'markdown')) {
            $mime = 'text/plain; charset=utf-8';
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($found['path']));
        readfile($found['path']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
