<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\FeedService;
use App\Support\Request;
use App\Support\Response;

final class FeedController
{
    public function __construct(private readonly array $config)
    {
    }

    public function sitemapIndex(): void
    {
        $this->cache(3600);
        Response::xml(FeedService::sitemapIndex($this->config['app']['url']));
    }

    public function sitemapContent(): void
    {
        $this->cache(3600);
        Response::xml(FeedService::sitemapContent($this->config['app']['url']));
    }

    public function sitemapNews(): void
    {
        $this->cache(300);
        Response::xml(FeedService::sitemapNews($this->config['app']['url'], $this->config['app']['name']));
    }

    public function sitemapVideos(): void
    {
        $this->cache(3600);
        Response::xml(FeedService::sitemapVideos($this->config['app']['url']));
    }

    public function sitemapSections(): void
    {
        $this->cache(3600);
        Response::xml(FeedService::sitemapSections($this->config['app']['url']));
    }

    public function rss(): void
    {
        $this->cache(900);
        http_response_code(200);
        header('Content-Type: application/rss+xml; charset=utf-8');
        echo FeedService::rss($this->config['app']['url'], $this->config);
    }

    public function robots(): void
    {
        $this->cache(3600);
        // En entorno local no se invita a los buscadores.
        $allow = $this->config['app']['env'] === 'produccion';
        Response::text(FeedService::robots($this->config['app']['url'], $allow));
    }

    private function cache(int $seconds): void
    {
        header('Cache-Control: public, max-age=' . $seconds);
    }
}
