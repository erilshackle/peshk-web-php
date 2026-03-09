<?php

namespace Peshk\Web\Page;

use DOMDocument;

class HtmlOptimizer
{
    protected string $htmlConditions = '';
    protected string $stylesInline = '';
    protected array $stylesTagged = [];
    protected array $scriptsHead = [];
    protected array $scriptsBody = [];

    public function __construct(
        protected bool $format = false,
        protected bool $clean = false
    ) {}

    /**
     * Processa e otimiza o HTML completo.
     */
    public function process(string $content): string
    {
        // 1. Trata condicionais HTML (ex: [if IE])
        $content = $this->handleHtmlConditions($content);

        // 2. Remove scripts/styles comentados
        $content = preg_replace('##is', '', $content);

        // 3. Extrai e processa Tags de Asset
        $content = $this->extractAndProcessAssets($content);

        // 4. Limpeza agressiva (se solicitado)
        if ($this->clean) {
            $content = preg_replace('//s', '', $content); // remove comentários
            $content = preg_replace("/\r?\n\s*/", ' ', $content);           // remove quebras de linha
        }

        // 5. Formatação DOM (se solicitado)
        if ($this->format) {
            $content = $this->formatHtml($content);
        }

        // 6. Re-insere os blocos processados nos lugares corretos
        return $this->rebuild($content);
    }

    protected function handleHtmlConditions(string $content): string
    {
        return preg_replace_callback('//is', function ($matches) {
            $this->htmlConditions = "\n" . preg_replace('/\s{3,}/', '', $matches[0]);
            return '';
        }, $content);
    }

    protected function extractAndProcessAssets(string $content): string
    {
        if (preg_match_all('#(\s*)?<(style|script)([^>]*)>(.*?)</\2>#is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $comment = trim($m[1]) . "\n";
                $tag     = strtolower($m[2]);
                $attrs   = trim($m[3] ?? '');
                $code    = $m[4] ?? '';

                if ($tag === 'style') {
                    $this->processStyle($code, $attrs);
                } else {
                    $this->processScript($content, $m[0], $code, $attrs, $comment);
                }
            }
            // Remove os blocos originais para re-inserção posterior
            return preg_replace('#(\s*)?<(style|script)([^>]*)>.*?</\2>#is', '', $content);
        }
        return $content;
    }

    protected function processStyle(string $code, string $attrs): void
    {
        if ($this->clean) {
            $code = preg_replace('/\/\*.*?\*\//s', '', $code); // remove comentários
            $code = preg_replace("/\r?\n\s*/", '', $code);     // remove quebras
        }

        if ($attrs) {
            $this->stylesTagged[] = "<style {$attrs}>{$code}</style>";
        } else {
            $this->stylesInline .= $code;
        }
    }

    protected function processScript(string $content, string $fullMatch, string $code, string $attrs, string $comment): void
    {
        if (stripos($attrs, 'src=') !== false) {
            $scriptTag = trim($comment . "<script" . ($attrs ? " {$attrs}" : "") . "></script>");
        } else {
            if ($this->clean) {
                $code = preg_replace('/(^|\s)\/\/.*$/m', '', $code); // remove comentários //
                $code = preg_replace('!/\*.*?\*/!s', '', $code);     // remove comentários /* */
                $code = preg_replace('/\n/', ' ', $code);            // remove quebras
            }
            $scriptTag = "<script" . ($attrs ? " {$attrs}" : "") . ">{$code}</script>";
        }

        // Verifica se estava no <head> original
        if (preg_match('#<head.*?' . preg_quote($fullMatch, '#') . '.*?</head>#is', $content)) {
            $this->scriptsHead[] = $scriptTag;
        } else {
            $this->scriptsBody[] = $scriptTag;
        }
    }

    protected function formatHtml(string $content): string
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->encoding = 'utf-8';
        @$dom->loadHTML($content, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
        return html_entity_decode($dom->saveHTML(), ENT_HTML5, 'UTF-8');
    }

    protected function rebuild(string $content): string
    {
        // Inserção de CSS
        if (!empty($this->stylesInline) || !empty($this->stylesTagged)) {
            $cssBlock = $this->stylesInline ? "<style>{$this->stylesInline}</style>\n" : "";
            $cssBlock .= implode("\n", $this->stylesTagged) . "\n";
            $content = $this->injectAt($content, $cssBlock, '#</head>#i');
        }

        // Inserção de Scripts Head + Condicionais
        if (!empty($this->scriptsHead) || !empty($this->htmlConditions)) {
            $headBlock = implode("\n", $this->scriptsHead) . $this->htmlConditions . "\n";
            $content = $this->injectAt($content, $headBlock, '#</head>#i');
        }

        // Inserção de Scripts Body
        if (!empty($this->scriptsBody)) {
            $bodyBlock = implode("\n", $this->scriptsBody) . "\n";
            $content = $this->injectAt($content, $bodyBlock, '#</body>#i');
        }

        return $content;
    }

    protected function injectAt(string $content, string $block, string $pattern): string
    {
        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, $block . "$0", $content, 1);
        }
        return ($pattern === '#</body>#i') ? $content . $block : $block . $content;
    }
}