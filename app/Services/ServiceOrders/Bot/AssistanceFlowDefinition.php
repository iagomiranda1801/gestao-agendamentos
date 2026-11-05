<?php

namespace App\Services\ServiceOrders\Bot;

use Illuminate\Validation\ValidationException;

class AssistanceFlowDefinition
{
    public const TYPES = ['message' => 'Mensagem', 'question' => 'Pergunta', 'menu' => 'Menu de opções', 'condition' => 'Condição', 'action' => 'Ação do sistema', 'end' => 'Encerramento'];

    public const FIELDS = ['device' => 'Aparelho', 'problem' => 'Problema relatado', 'notes' => 'Observação'];

    public const ACTIONS = ['repair_request' => 'Registrar solicitação de conserto', 'order_status' => 'Consultar OS do cliente', 'accessory_interest' => 'Registrar interesse em acessório'];

    public const CONDITIONS = ['has_open_orders' => 'Cliente tem OS em andamento'];

    public function defaults(): array
    {
        return [
            'entry' => 'welcome',
            'name_prompt' => 'Olá! Como você se chama?',
            'nodes' => [
                ['id' => 'welcome', 'type' => 'message', 'label' => 'Boas-vindas', 'text' => 'Olá, {nome}! Bem-vindo à {empresa}.', 'next' => 'menu'],
                ['id' => 'menu', 'type' => 'menu', 'label' => 'Menu principal', 'text' => 'Como podemos ajudar?', 'options' => [
                    ['label' => 'Consertar meu celular', 'next' => 'repair_device'],
                    ['label' => 'Acompanhar meu conserto', 'next' => 'has_orders'],
                    ['label' => 'Comprar capa ou película', 'next' => 'accessory'],
                ]],
                ['id' => 'repair_device', 'type' => 'question', 'label' => 'Aparelho para conserto', 'text' => 'Qual é a marca e o modelo do celular?', 'field' => 'device', 'next' => 'problem'],
                ['id' => 'problem', 'type' => 'question', 'label' => 'Defeito informado', 'text' => 'O que está acontecendo com o aparelho?', 'field' => 'problem', 'next' => 'confirm'],
                ['id' => 'confirm', 'type' => 'menu', 'label' => 'Confirmar solicitação', 'text' => 'Aparelho: {aparelho}\nProblema: {problema}\nOs dados estão corretos?', 'options' => [
                    ['label' => 'Sim, registrar', 'next' => 'request'], ['label' => 'Corrigir aparelho e problema', 'next' => 'repair_device'],
                ]],
                ['id' => 'request', 'type' => 'action', 'label' => 'Registrar conserto', 'action' => 'repair_request', 'next' => 'finish'],
                ['id' => 'has_orders', 'type' => 'condition', 'label' => 'Possui conserto em andamento?', 'condition' => 'has_open_orders', 'yes' => 'orders', 'no' => 'no_orders'],
                ['id' => 'orders', 'type' => 'action', 'label' => 'Informar andamento', 'action' => 'order_status', 'next' => 'finish'],
                ['id' => 'no_orders', 'type' => 'message', 'label' => 'Nenhuma OS localizada', 'text' => 'Não encontrei conserto em andamento vinculado ao seu número.', 'next' => 'finish'],
                ['id' => 'accessory', 'type' => 'menu', 'label' => 'Tipo de acessório', 'text' => 'Qual acessório você procura?', 'field' => 'accessory_category', 'options' => [
                    ['label' => 'Película', 'value' => 'film', 'next' => 'accessory_device'],
                    ['label' => 'Capa', 'value' => 'case', 'next' => 'accessory_device'],
                ]],
                ['id' => 'accessory_device', 'type' => 'question', 'label' => 'Aparelho para acessório', 'text' => 'Para qual marca e modelo de celular?', 'field' => 'device', 'next' => 'interest'],
                ['id' => 'interest', 'type' => 'action', 'label' => 'Registrar interesse', 'action' => 'accessory_interest', 'next' => 'finish'],
                ['id' => 'finish', 'type' => 'end', 'label' => 'Finalizar atendimento', 'text' => 'Para outro atendimento, digite menu.'],
            ],
        ];
    }

    public function validate(array $definition, bool $publish = true): array
    {
        validator($definition, [
            'entry' => 'required|string|max:60', 'name_prompt' => 'required|string|min:2|max:1000',
            'nodes' => 'required|array|list|min:1|max:50',
            'nodes.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{0,59}$/|distinct',
            'nodes.*.type' => 'required|in:message,question,menu,condition,action,end',
            'nodes.*.label' => 'required|string|min:2|max:100',
            'nodes.*.text' => 'nullable|string|max:2000',
            'nodes.*.next' => 'nullable|string|max:60',
            'nodes.*.field' => 'nullable|string|max:50',
            'nodes.*.action' => 'nullable|string|max:50',
            'nodes.*.condition' => 'nullable|string|max:50',
            'nodes.*.yes' => 'nullable|string|max:60', 'nodes.*.no' => 'nullable|string|max:60',
            'nodes.*.options' => 'sometimes|array|max:6',
            'nodes.*.options.*.label' => 'required|string|min:1|max:100',
            'nodes.*.options.*.next' => 'nullable|string|max:60',
            'nodes.*.options.*.value' => 'nullable|in:film,case',
        ])->validate();

        $ids = array_column($definition['nodes'], 'id');
        $this->check(in_array($definition['entry'], $ids, true), 'Escolha um bloco inicial existente.');
        $nodes = array_column($definition['nodes'], null, 'id');
        foreach ($nodes as $id => $node) {
            $label = $node['label'];
            if (in_array($node['type'], ['message', 'question', 'menu', 'end'], true)) {
                $this->check(trim($node['text'] ?? '') !== '', 'Preencha a mensagem do bloco “'.$label.'”.');
                preg_match_all('/\{([^{}]+)\}/u', $node['text'], $matches);
                $this->check(array_diff($matches[1], ['nome', 'empresa', 'aparelho', 'problema']) === [], 'Use apenas {nome}, {empresa}, {aparelho} e {problema} nas mensagens.');
            }
            if ($node['type'] === 'question') {
                $this->check(isset(self::FIELDS[$node['field'] ?? '']), 'Escolha onde salvar a resposta de “'.$label.'”.');
            }
            if ($node['type'] === 'action') {
                $this->check(isset(self::ACTIONS[$node['action'] ?? '']), 'Escolha uma ação disponível para “'.$label.'”.');
            }
            if ($node['type'] === 'condition') {
                $this->check(isset(self::CONDITIONS[$node['condition'] ?? '']), 'Escolha uma condição disponível.');
            }
            if ($node['type'] === 'menu') {
                $this->check(count($node['options'] ?? []) >= 1, 'Adicione uma opção ao menu “'.$label.'”.');
                $this->check(in_array($node['field'] ?? '', ['', 'accessory_category'], true), 'Destino da resposta do menu inválido.');
                if (($node['field'] ?? '') === 'accessory_category') {
                    foreach ($node['options'] as $option) {
                        $this->check(in_array($option['value'] ?? '', ['film', 'case'], true), 'Informe o tipo de acessório de cada opção.');
                    }
                }
            }
            if ($publish) {
                foreach ($this->edges($node) as $target) {
                    $this->check(in_array($target, $ids, true), 'Defina o próximo passo de “'.$label.'”.');
                }
            }
        }

        if ($publish) {
            $seen = [];
            $walk = function (string $id) use (&$walk, &$seen, $nodes): void {
                if (isset($seen[$id])) {
                    return;
                }
                $seen[$id] = true;
                foreach ($this->edges($nodes[$id]) as $target) {
                    $walk($target);
                }
            };
            $walk($definition['entry']);
            $this->check(count($seen) === count($nodes), 'Existem blocos sem ligação com o início. Conecte-os ou remova-os antes de publicar.');
            $this->check(collect($nodes)->contains(fn ($node) => $node['type'] === 'end'), 'Inclua um bloco de encerramento.');
            // Automatic paths must eventually wait for input or finish; they cannot loop forever.
            $done = [];
            $visit = function (string $id, array $path) use (&$visit, &$done, $nodes): void {
                $this->check(! in_array($id, $path, true), 'Há um ciclo sem pergunta ou menu. Corrija as ligações antes de publicar.');
                if (isset($done[$id])) {
                    return;
                }
                if (in_array($nodes[$id]['type'], ['question', 'menu', 'end'], true)) {
                    $done[$id] = true;

                    return;
                }
                foreach ($this->edges($nodes[$id]) as $target) {
                    $visit($target, [...$path, $id]);
                }
                $done[$id] = true;
            };
            foreach ($ids as $id) {
                $visit($id, []);
            }
        }

        return $definition;
    }

    public function edges(array $node): array
    {
        return match ($node['type']) {
            'end' => [],
            'menu' => array_map(fn ($option) => $option['next'] ?? '', $node['options'] ?? []),
            'condition' => [$node['yes'] ?? '', $node['no'] ?? ''],
            default => [$node['next'] ?? ''],
        };
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['flow' => $message]);
        }
    }
}
