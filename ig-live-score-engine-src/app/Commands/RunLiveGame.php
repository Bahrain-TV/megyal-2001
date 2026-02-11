<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;
use Laravel\Dusk\Browser;
use Symfony\Component\Process\Process;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;

class RunLiveGame extends Command
{
    protected $signature = 'run
        {url : Instagram live stream URL}
        {answer : The correct answer to match}
        {--port=9515 : ChromeDriver port}
        {--poll=800 : Polling interval in milliseconds}
        {--case-sensitive : Enable case-sensitive matching}';

    protected $description = 'Instagram Live Score Engine — watch comments and score correct answers';

    protected array $processed = [];
    protected ?Process $chromeDriver = null;
    protected ?RemoteWebDriver $driver = null;
    protected int $totalChecked = 0;

    public function handle(): int
    {
        $url  = $this->argument('url');
        $answer = $this->argument('answer');
        $port = (int) $this->option('port');
        $poll = max(200, (int) $this->option('poll'));
        $caseSensitive = $this->option('case-sensitive');

        $this->info("Starting IG Live Score Engine");
        $this->info("URL:    {$url}");
        $this->info("Answer: {$answer}");
        $this->line('');

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, fn () => $this->shutdown('Interrupted'));
            pcntl_signal(SIGTERM, fn () => $this->shutdown('Terminated'));
        }

        try {
            $this->startChromeDriver($port);
            sleep(2);

            $this->driver = $this->makeDriver($port);
            $browser = new Browser($this->driver);

            $this->info("Navigating to stream...");
            $browser->visit($url)->pause(6000);
            $this->info("Connected. Watching comments...");
            $this->line('');

            $normalizedAnswer = $caseSensitive ? trim($answer) : $this->normalize($answer);

            while (true) {
                $this->pollComments($browser, $normalizedAnswer, $caseSensitive);
                usleep($poll * 1000);
            }
        } catch (\Throwable $e) {
            $this->error("Fatal: {$e->getMessage()}");
            $this->shutdown('Error');
            return self::FAILURE;
        }
    }

    protected function pollComments(Browser $browser, string $normalizedAnswer, bool $caseSensitive): void
    {
        try {
            $elements = $browser->elements('div[role="dialog"] ul li');
        } catch (\Throwable $e) {
            $this->warn("Could not read comments: {$e->getMessage()}");
            return;
        }

        foreach ($elements as $el) {
            try {
                $text = trim($el->getText());
            } catch (\Throwable) {
                continue;
            }

            if ($text === '' || isset($this->processed[$text])) {
                continue;
            }

            $this->processed[$text] = true;
            $this->totalChecked++;

            $parts = explode("\n", $text, 2);
            $user = trim($parts[0] ?? '');
            $msg  = trim($parts[1] ?? '');

            if ($user === '' || $msg === '') {
                continue;
            }

            $normalizedMsg = $caseSensitive ? $msg : $this->normalize($msg);

            if ($normalizedMsg === $normalizedAnswer) {
                $score = $this->incrementScore($user);
                $this->info("[+] {$user} -> {$score} pts");
            }
        }
    }

    /**
     * Increment a user's score with file locking for safety.
     */
    protected function incrementScore(string $user): int
    {
        $file = base_path('storage/scores.json');

        $fp = fopen($file, 'c+');
        if (!$fp) {
            $this->error("Cannot open {$file}");
            return 0;
        }

        flock($fp, LOCK_EX);

        $contents = stream_get_contents($fp);
        $scores = json_decode($contents ?: '{}', true) ?: [];

        $scores[$user] = ($scores[$user] ?? 0) + 1;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($scores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $scores[$user];
    }

    protected function startChromeDriver(int $port): void
    {
        $binary = base_path('vendor/laravel/dusk/bin/chromedriver-linux');

        if (!is_file($binary)) {
            $this->error("ChromeDriver not found at {$binary}");
            $this->error("Run: php application dusk:chrome-driver");
            exit(self::FAILURE);
        }

        $this->chromeDriver = new Process([$binary, "--port={$port}"]);
        $this->chromeDriver->start();
        $this->info("ChromeDriver started on port {$port}");
    }

    protected function makeDriver(int $port): RemoteWebDriver
    {
        $options = new ChromeOptions();
        $options->addArguments([
            '--headless=new',
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--window-size=1280,800',
            '--lang=ar',
        ]);

        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

        return RemoteWebDriver::create(
            "http://localhost:{$port}",
            $capabilities,
            30000, // connect timeout ms
            60000  // request timeout ms
        );
    }

    /**
     * Normalize Arabic text for fuzzy matching:
     * - Strip tashkeel diacritics
     * - Unify alef/yaa/taa marbouta forms
     * - Collapse whitespace
     * - Lowercase
     */
    protected function normalize(string $text): string
    {
        $text = trim($text);
        // Remove Arabic diacritics (tashkeel)
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        // Normalise similar Arabic letters
        $text = str_replace(
            ['أ', 'إ', 'آ', 'ٱ', 'ى', 'ة', 'ؤ', 'ئ'],
            ['ا', 'ا', 'ا', 'ا', 'ي', 'ه', 'و', 'ي'],
            $text
        );
        // Collapse whitespace
        $text = preg_replace('/\s+/', '', $text);
        return mb_strtolower($text);
    }

    protected function shutdown(string $reason = ''): void
    {
        if ($reason) {
            $this->line('');
            $this->warn("Shutting down: {$reason}");
        }

        $this->info("Checked {$this->totalChecked} comments total.");

        try {
            if ($this->driver) {
                $this->driver->quit();
            }
        } catch (\Throwable) {
        }

        if ($this->chromeDriver) {
            $this->chromeDriver->stop(5);
        }

        exit(0);
    }
}
