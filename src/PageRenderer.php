<?php
namespace Peshk\Web;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * === PageRenderer ===
 * Renderiza o conteúdo e os layouts.
 */
class PageRenderer
{
    public string $content;
    public array $layouts;
    public ServerRequestInterface $request;

    public function __construct(string $content, array $layouts, ServerRequestInterface $request)
    {
        $this->content = $content;
        $this->layouts = $layouts;
        $this->request = $request;
    }

    protected function renderFile(string $file, array $vars): string
    {
        extract($vars);
        ob_start();
        include $file;
        return ob_get_clean();
    }

    public function render(): string
    {
        $content = $this->content;
        foreach ($this->layouts as $layout) {
            $content = $this->renderFile($layout, [
                'content' => $content,
                'request' => $this->request
            ]);
        }
        return $content;
    }
}