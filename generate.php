<?php

use SeptianAdi\ApiWilayah\Generator;
use SeptianAdi\ApiWilayah\Repository;

require "vendor/autoload.php";

// Custom Generator untuk mengirim progress via SSE (Server-Sent Events)
class SseGenerator extends Generator {
    private $total;
    private $current = 0;
    private $startTime;

    public function __construct(Repository $repository, string $outputDir, int $total) {
        parent::__construct($repository, $outputDir);
        $this->total = $total;
        $this->startTime = time();
    }

    public function generateApi(string $uri, array $data) {
        // Suppress default echo from parent
        ob_start();
        parent::generateApi($uri, $data);
        ob_end_clean();

        $this->current++;

        // Kirim update setiap 100 file atau jika sudah selesai
        if ($this->current % 100 === 0 || $this->current === $this->total) {
            $elapsed = time() - $this->startTime;
            $percent = round(($this->current / $this->total) * 100, 2);
            
            $speed = $this->current / ($elapsed ?: 1);
            $remainingFiles = $this->total - $this->current;
            $eta = round($remainingFiles / ($speed ?: 1));

            $payload = json_encode([
                'current' => $this->current,
                'total' => $this->total,
                'percent' => $percent,
                'eta' => $eta
            ]);

            echo "data: {$payload}\n\n";
            // pastikan langsung dikirim ke browser
            if (ob_get_level() > 0) ob_flush();
            flush();
        }
    }
}

if (isset($_GET['run'])) {
    // Hindari Caching dari Browser (Penting jika ada penambahan data CSV baru)
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Content-Type: text/event-stream');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Untuk Nginx agar tidak di-buffer
    set_time_limit(0);

    // Matikan output buffering agar SSE bisa streaming langsung
    while (ob_get_level()) { ob_end_clean(); }
    
    $repository = new Repository(__DIR__.'/data');
    
    // Hitung total baris untuk estimasi persentase
    $countP = count(file(__DIR__.'/data/provinces.csv'));
    $countR = count(file(__DIR__.'/data/regencies.csv'));
    $countD = count(file(__DIR__.'/data/districts.csv'));
    $countV = count(file(__DIR__.'/data/villages.csv'));
    
    // Total file yang digenerate (sesuai loop di Generator::generate())
    $totalFiles = 1 + ($countP * 2) + ($countR * 2) + ($countD * 2) + $countV;

    $repository->cache('districts.csv');
    $repository->cache('villages.csv');

    $generator = new SseGenerator($repository, __DIR__.'/static/api', $totalFiles);

    $generator->clearOutputDir();
    
    // Kirim data inisial
    echo "data: " . json_encode(['current' => 0, 'total' => $totalFiles, 'percent' => 0, 'eta' => 0]) . "\n\n";
    flush();

    // Mulai generation
    $generator->generate();
    
    // Kirim event selesai
    echo "event: complete\ndata: " . json_encode(['status' => 'success']) . "\n\n";
    flush();
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate API Wilayah</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background: #f8fafc;
            color: #334155;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container {
            text-align: center;
            background: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            width: 400px;
            max-width: 90%;
        }
        .progress-bar-container {
            width: 100%;
            background-color: #e2e8f0;
            border-radius: 9999px;
            height: 20px;
            margin: 20px 0;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            background-color: #3b82f6;
            width: 0%;
            transition: width 0.3s ease;
        }
        .stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
            color: #64748b;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: #0f172a;
        }
        p {
            color: #64748b;
            font-size: 1rem;
            line-height: 1.5;
            margin: 0 auto;
        }
        .success-text {
            color: #10b981;
            display: none;
            font-size: 1.2rem;
            font-weight: bold;
            margin-top: 20px;
        }
    </style>
</head>
<body>

    <div class="container">
        <h1 id="title">Sedang Men-generate Data...</h1>
        <p id="subtitle">Mohon tunggu, proses ini butuh beberapa saat.</p>
        
        <div id="progress-wrapper">
            <div class="progress-bar-container">
                <div id="progress-bar" class="progress-bar"></div>
            </div>
            <div class="stats">
                <span id="percent-text">0%</span>
                <span id="eta-text">ETA: Menghitung...</span>
            </div>
            <div style="font-size: 0.8rem; color: #94a3b8;" id="file-count">0 / 0 file</div>
        </div>

        <div id="success" class="success-text">Selesai! Mengarahkan ke halaman static...</div>
    </div>

    <script>
        // Gunakan parameter time (Date.now()) agar browser tidak menggunakan request cache yang lama
        const evtSource = new EventSource('?run=1&t=' + Date.now());
        
        evtSource.onmessage = function(event) {
            const data = JSON.parse(event.data);
            
            document.getElementById('progress-bar').style.width = data.percent + '%';
            document.getElementById('percent-text').innerText = data.percent + '%';
            
            // Format ETA
            let etaString = 'Menghitung...';
            if (data.eta > 0) {
                const minutes = Math.floor(data.eta / 60);
                const seconds = data.eta % 60;
                etaString = minutes > 0 ? `${minutes}m ${seconds}s` : `${seconds}s`;
            } else if (data.percent === 100) {
                etaString = '0s';
            }
            document.getElementById('eta-text').innerText = 'ETA: ' + etaString;
            document.getElementById('file-count').innerText = data.current + ' / ' + data.total + ' file';
        };

        evtSource.addEventListener('complete', function(event) {
            evtSource.close();
            
            document.getElementById('title').style.display = 'none';
            document.getElementById('subtitle').style.display = 'none';
            document.getElementById('progress-wrapper').style.display = 'none';
            document.getElementById('success').style.display = 'block';
            
            setTimeout(() => {
                window.location.href = '/api-wilayah-id/static/';
            }, 1500);
        });

        evtSource.onerror = function(err) {
            console.error("EventSource failed:", err);
            evtSource.close();
            document.getElementById('title').innerText = 'Terjadi Kesalahan!';
            document.getElementById('title').style.color = '#ef4444';
            document.getElementById('subtitle').innerText = 'Proses terhenti. Silakan cek console atau refresh halaman.';
        };
    </script>

</body>
</html>
