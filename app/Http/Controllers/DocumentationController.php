<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;

class DocumentationController extends Controller
{
    private const GUIDES = [
        'user' => ['title' => 'User guide', 'file' => 'USER_GUIDE.md'],
        'owner' => ['title' => 'Owner & handover', 'file' => 'OWNER_GUIDE.md'],
    ];

    public function index()
    {
        $chapters = [];
        foreach (self::GUIDES as $key => $guide) {
            preg_match_all('/^## ([^\r\n]+)\R(.*?)(?=^## |\z)/ms', $this->source($guide['file']), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $html = Str::markdown($match[2], ['html_input' => 'strip', 'allow_unsafe_links' => false]);
                $chapters[] = [
                    'id' => $key.'-'.Str::slug($match[1]),
                    'group' => $guide['title'],
                    'title' => $match[1],
                    'html' => $html,
                    'text' => html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ];
            }
        }

        return Inertia::render('Documentation/Index', ['chapters' => $chapters]);
    }

    public function download()
    {
        $content = implode("\n\n---\n\n", array_map(fn ($guide) => $this->source($guide['file']), self::GUIDES));

        return response($content, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="WP-Hub-Guide.md"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function source(string $file): string
    {
        return str_replace("\r\n", "\n", File::get(base_path('docs/'.$file)));
    }
}
