<?php

namespace Peshk\Web\Page;

use Psr\Http\Message\ServerRequestInterface;

/**
 * === PageRenderer ===
 * Responsável por processar o conteúdo da página, extrair assets (scripts/head)
 * e envolver o resultado na pilha de layouts detectada.
 */
class PageRenderer
{
    protected array $params = [];
    protected bool $minify = true;
    protected array $slots = [
        'scripts' => '',
        'head'    => ''
    ];

    /**
     * O construtor foca no conteúdo bruto e infraestrutura.
     */
    public function __construct(
        protected string $content,
        protected array $layouts,
        protected ServerRequestInterface $request
    ) {}

    /**
     * Factory estática: Converte um arquivo PHP em conteúdo renderizado e instancia a classe.
     */
    public static function fromFile(string $file, array $layouts, ServerRequestInterface $request, array $data = []): self
    {
        if (!file_exists($file)) {
            throw new \InvalidArgumentException("Página não encontrada: $file");
        }

        // Renderiza o arquivo da página isoladamente
        $content = self::renderIsolated($file, $data);

        $instance = new self($content, $layouts, $request);
        $instance->setParams($data);
        return $instance;
    }

    /**
     * Define as variáveis que estarão disponíveis nos layouts.
     */
    public function setParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    /**
     * Liga ou desliga a otimização de espaço em branco no HTML final.
     */
    public function enableMinify(bool $enable): self
    {
        $this->minify = $enable;
        return $this;
    }

    /**
     * Executa o ciclo de vida da renderização.
     */
    public function render(): string
    {
        $output = $this->content;

        // Se o conteúdo tiver um DOCTYPE, assumimos que é uma página completa (sem layouts)
        $usingLayout = !preg_match('/^\s*<!DOCTYPE\s+html\s*>/i', $output);

        if ($usingLayout && !empty($this->layouts)) {
            $output = $this->applyLayoutStack($output);
        }

        return  $this->optimize($output);
    }

    /**
     * Processa a extração de assets e percorre a pilha de layouts (do interno para o externo).
     */
    protected function applyLayoutStack(string $output): string
    {
        // 1. Extrai <head> e <script> da página original
        $output = $this->extractAssets($output);

        // 2. Renderiza cada layout na ordem: [Sub-layout -> Layout Raiz]
        // Invertemos pois o scanner geralmente coleta [Raiz -> Sub]
        foreach (array_reverse($this->layouts) as $layoutFile) {
            $output = self::renderIsolated($layoutFile, array_merge($this->params, [
                'content' => $output,
                'head'    => $this->slots['head'],
                'scripts' => $this->slots['scripts'],
                'request' => $this->request,
                'view'    => $this // Permite $view->get('slot_nomeado') se necessário
            ]));
        }

        // 3. Garante que os scripts não fiquem "orfãos" se o layout não os imprimiu
        return $this->injectMissingAssets($output);
    }

    /**
     * Isola o escopo do include para que variáveis da classe não vazem para a View.
     */
    protected static function renderIsolated(string $__file, array $__data): string
    {
        return (static function () use ($__file, $__data) {
            extract($__data);
            ob_start();
            require $__file;
            return ob_get_clean();
        })();
    }

    /**
     * Move <script> e <head> do conteúdo para propriedades da classe.
     */
    protected function extractAssets(string $content): string
    {
        // Captura e remove tags <head>
        if (preg_match('#<head[^>]*>(.*?)</head>#is', $content, $matches)) {
            $this->slots['head'] .= $matches[1];
            $content = str_replace($matches[0], '', $content);
        }

        // Captura e remove tags <script>
        if (preg_match_all('#<script[^>]*>.*?</script>#is', $content, $matches)) {
            $this->slots['scripts'] .= implode("\n", $matches[0]);
            $content = preg_replace('#<script[^>]*>.*?</script>#is', '', $content);
        }

        // Processa o atalho @csrf()
        $content = preg_replace_callback('/@csrf\(\)/i', function () {
            return function_exists('csrf') ? call_user_func('csrf') : '';
        }, $content);

        return $content;
    }

    /**
     * Injeta scripts acumulados antes do fechamento do body, caso não tenham sido impressos.
     */
    protected function injectMissingAssets(string $content): string
    {
        if (!empty($this->slots['scripts']) && !str_contains($content, $this->slots['scripts'])) {
            if (preg_match('#</body>#i', $content)) {
                return preg_replace('#</body>#i', $this->slots['scripts'] . "\n</body>", $content);
            }
            return $content . $this->slots['scripts'];
        }
        return $content;
    }

    /**
     * Minificação básica de HTML.
     */
    protected function optimize(string $html): string
    {
        // Usamos as flags configuradas no Renderer
        $optimizer = new HtmlOptimizer(
            format: false,
            clean: (bool)$this->minify
        );

        return $optimizer->process($html);
    }
}
