<?php

namespace App\Commands\ChangeLog;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;
use function Termwind\{render};

class Pull extends Command
{
    const GITHUB_TOKEN_CONFIG_KEY = 'github-token';
    const GITHUB_SUBSCRIBES_CONFIG_KEY = 'subscribes';
    const CACHE_KEY_RELEASES = 'releases';
    const CACHE_KEY_RELEASE_BODY = 'releases-body';
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'changelog:pull';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'This command provides package change logs for subscribing to github';

    protected string $githubToken;

    protected array $subscribes;

    protected array $urlByRepoAndOwner;

    private array $httpOptions = [
        'Accept'=>'application/vnd.github+json',
        'Authorization'=>'',
    ];

    protected Client $client;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if ($this->shouldBeHandledWithLaravelChangelog()){
            $this->getLatestChangelogByRepoUrl();
        }

    }

    protected function getLatestChangelogByRepoUrl()
    {
        $this->client = new Client();
        $this->getReleases();
        $this->getReleaseContent();
    }

    protected function getReleases():void
    {
        if (!file_exists(self::getCachePathByKey(self::CACHE_KEY_RELEASES))){
            foreach ($this->urlByRepoAndOwner as $ownerAndRepo => $url) {
                $response = $this->client->request(
                    'GET',
                    'https://api.github.com/repos/'.$ownerAndRepo.'/releases',
                    $this->httpOptions
                );
                $this->recordCache(self::CACHE_KEY_RELEASES,json_decode($response->getBody(),true)[0]['url']);
            }
        }
    }

    protected function getReleaseContent():void
    {
        if (!file_exists(self::getCachePathByKey(self::CACHE_KEY_RELEASE_BODY))){
            foreach ($this->getCacheByKey(self::CACHE_KEY_RELEASES) as $release_uri) {
                try {
                    $response = $this->client->request(
                        'GET',
                        $release_uri,
                        $this->httpOptions
                    )->getBody()->getContents();
                    $response_array = json_decode(str_replace(['\r\n','\n','\r'],'<br>',$response),true);
                    $this->recordCache(self::CACHE_KEY_RELEASE_BODY,serialize($response_array));
                }catch (ClientException $exception){
                    $message = $exception->getMessage();
                    render(<<<HTML
            <p class="text-red-500">$message</p>
        HTML);
                    break;
                }
            }
        }
        $response_serializes = $this->getCacheByKey(self::CACHE_KEY_RELEASE_BODY);
        foreach ($response_serializes as $response_serialize){
            $response_array = unserialize($response_serialize);
            $tag_name = $response_array['tag_name'];
            $body = trim($response_array['body']);
            [$owner,$repo] = self::getOwnerAndRepo($response_array['url']);
            $html = <<<HTML
            <p>
            Package: $owner/$repo <br/>
            Tag: $tag_name <br/> 
            Changelog:<br/> 
            $body
            </p>
        HTML;
            render($html);
        }

    }
    protected function handleWithLaravelChangelog(): bool
    {
        $argumentsAndOptions = (string)$this->input;

        $process = Process::fromShellCommandline("php artisan changelog {$argumentsAndOptions}");

        $process->setTty(true);
        $process->run();

        return $process->isSuccessful();
    }
    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function schedule(Schedule $schedule)
    {
        // $schedule->command(static::class)->everyMinute();
    }

    protected function shouldBeHandledWithLaravelChangelog(): bool
    {
        if (!$this->checkConfig()){
            return false;
        }
        foreach ($this->subscribes as $subscribe) {
            $this->setOwnerAndRepoByUrl($subscribe);
        }
        return true;
    }

    protected static function getCachePathByKey(string $key): string
    {
        return getcwd() . '/changelog-'.$key.'.cache';

    }
    protected function recordCache(string $key,$data):void
    {
        $handle = fopen(self::getCachePathByKey($key),'a+');
        fwrite($handle,$data.PHP_EOL);
        fclose($handle);
    }

    protected function getCacheByKey($key):array
    {
        return array_filter(explode(PHP_EOL,file_get_contents(self::getCachePathByKey($key))),fn($item)=>$item !== '');
    }
    protected function checkConfig(): bool
    {
        $config_path = getcwd() . '/changelog.conf';

        if (! file_exists($config_path)){
            return false;
        }
        $config_json_content = json_decode(file_get_contents($config_path), true);

        $this->githubToken = $config_json_content[self::GITHUB_TOKEN_CONFIG_KEY] ?? '';
        $this->subscribes = $config_json_content[self::GITHUB_SUBSCRIBES_CONFIG_KEY] ?? [];

        if ($this->githubToken === '' || $this->subscribes === []) {
            return false;
        }
        $this->httpOptions['Authorization'] = $this->githubToken;

        return true;
    }

    protected function setOwnerAndRepoByUrl(string $url)
    {
        preg_match("/^(http[s]?){1}:\/\/github\.com\/([\w-]+)\/([\w-]+){1}\/?/i",$url,$matches);
        [$url,,$owner,$repo] = $matches;
        $this->urlByRepoAndOwner[$owner.'/'.$repo] = $url;
    }
    protected static function getOwnerAndRepo($url)
    {
        preg_match("/^(http[s]?){1}:\/\/(api\.)?github\.com\/(repos\/)?([\w-]+)\/([\w-]+){1}\/?/i",$url,$matches);
        [,,,,$owner,$repo] = $matches;
        return [$owner,$repo];
    }

}
