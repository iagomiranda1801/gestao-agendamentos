<?php

namespace App\Services\CarWash;

use Illuminate\Validation\ValidationException;

class CarWashFlowDefinition
{
    public const TYPES = [
        'message' => 'Mensagem (sem IA)',
        'question' => 'Pergunta (sem IA)',
        'menu' => 'Menu (sem IA)',
        'action' => 'Ação do sistema (sem IA)',
        'ai' => 'IA',
        'end' => 'Encerramento (sem IA)',
    ];

    public const FIELDS = [
        'plate' => 'Placa',
        'slot' => 'Número do horário oferecido',
        'name' => 'Nome',
    ];

    public const AI_FIELDS = [
        '' => 'Não coleta um dado',
        'service' => 'Serviço do catálogo',
        'plate' => 'Placa',
    ];

    public const ACTIONS = [
        'list_services' => 'Listar lavagens do catálogo',
        'show_prices' => 'Informar preços cadastrados',
        'offer_slots' => 'Oferecer horários livres',
        'book' => 'Marcar o horário e gravar a placa',
        'handoff' => 'Chamar a equipe e pausar o bot',
    ];

    public const PLACEHOLDERS = ['nome', 'empresa', 'placa', 'servico', 'horario'];

    public function defaults(): array
    {
        return [
            'entry' => 'welcome',
            'name_prompt' => 'Olá! Como você se chama?',
            'off_context_message' => 'Por aqui eu só ajudo com lavagem e horário da {empresa}. Se for outro assunto, a equipe pode te atender.',
            'nodes' => [
                ['id' => 'welcome', 'type' => 'message', 'label' => 'Boas-vindas', 'text' => 'Oi, {nome}! Aqui é o {empresa}.', 'next' => 'menu'],
                ['id' => 'menu', 'type' => 'menu', 'label' => 'Menu principal', 'text' => 'Como posso te ajudar?', 'options' => [
                    ['label' => 'Agendar lavagem', 'next' => 'package'],
                    ['label' => 'Ver preços', 'next' => 'prices'],
                    ['label' => 'Falar com a equipe', 'next' => 'team'],
                ]],
                ['id' => 'package', 'type' => 'ai', 'label' => 'Entender a lavagem', 'field' => 'service',
                    'text' => 'Qual lavagem você quer fazer?',
                    'instruction' => 'Descobrir qual lavagem a pessoa quer, somente entre os serviços do catálogo. Se a frase for sobre outro assunto, marque fora do assunto.',
                    'outcomes' => [
                        ['id' => 'entendeu', 'label' => 'Entendeu o pacote', 'next' => 'plate'],
                    ]],
                ['id' => 'plate', 'type' => 'question', 'label' => 'Placa', 'text' => 'Qual é a placa do carro?', 'field' => 'plate', 'next' => 'offer'],
                ['id' => 'offer', 'type' => 'action', 'label' => 'Horários livres', 'action' => 'offer_slots', 'next' => 'pick'],
                ['id' => 'pick', 'type' => 'question', 'label' => 'Escolha do horário', 'text' => 'Qual desses horários fica melhor? Responda com o número.', 'field' => 'slot', 'next' => 'confirm'],
                ['id' => 'confirm', 'type' => 'menu', 'label' => 'Confirmar horário', 'text' => "{servico}\nPlaca {placa}\n{horario}\nPosso marcar?", 'options' => [
                    ['label' => 'Sim, pode marcar', 'next' => 'book'],
                    ['label' => 'Quero outro horário', 'next' => 'offer'],
                ]],
                ['id' => 'book', 'type' => 'action', 'label' => 'Marcar horário', 'action' => 'book', 'next' => 'done'],
                ['id' => 'prices', 'type' => 'action', 'label' => 'Preços', 'action' => 'show_prices', 'next' => 'menu'],
                ['id' => 'team', 'type' => 'action', 'label' => 'Chamar a equipe', 'action' => 'handoff', 'next' => 'done'],
                ['id' => 'done', 'type' => 'end', 'label' => 'Encerrar', 'text' => 'Para outro atendimento, digite menu.'],
            ],
        ];
    }

    public function validate(array $definition, bool $publish = true): array
    {
        validator($definition, [
            'entry' => 'required|string|max:60',
            'name_prompt' => 'required|string|min:2|max:1000',
            'off_context_message' => 'required|string|min:2|max:1000',
            'nodes' => 'required|array|list|min:1|max:50',
            'nodes.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{0,59}$/|distinct',
            'nodes.*.type' => 'required|in:message,question,menu,action,ai,end',
            'nodes.*.label' => 'required|string|min:2|max:100',
            'nodes.*.text' => 'nullable|string|max:2000',
            'nodes.*.instruction' => 'nullable|string|max:1000',
            'nodes.*.next' => 'nullable|string|max:60',
            'nodes.*.field' => 'nullable|string|max:50',
            'nodes.*.action' => 'nullable|string|max:50',
            'nodes.*.options' => 'sometimes|array|max:6',
            'nodes.*.options.*.label' => 'required|string|min:1|max:100',
            'nodes.*.options.*.next' => 'nullable|string|max:60',
            'nodes.*.outcomes' => 'sometimes|array|max:6',
            'nodes.*.outcomes.*.id' => 'required|string|regex:/^[a-z][a-z0-9_]{0,59}$/',
            'nodes.*.outcomes.*.label' => 'required|string|min:1|max:100',
            'nodes.*.outcomes.*.next' => 'nullable|string|max:60',
        ])->validate();

        $ids = array_column($definition['nodes'], 'id');
        $this->check(in_array($definition['entry'], $ids, true), 'Escolha um bloco inicial existente.');
        $this->checkPlaceholders($definition['name_prompt'], 'pergunta do nome');
        $this->checkPlaceholders($definition['off_context_message'], 'aviso fora do assunto');
        $nodes = array_column($definition['nodes'], null, 'id');
        foreach ($nodes as $node) {
            $label = $node['label'];
            if (in_array($node['type'], ['message', 'question', 'menu', 'end', 'ai'], true)) {
                $this->check(trim((string) ($node['text'] ?? '')) !== '', 'Preencha a mensagem do bloco “'.$label.'”.');
                $this->checkPlaceholders((string) $node['text'], $label);
            }
            if ($node['type'] === 'question') {
                $this->check(isset(self::FIELDS[$node['field'] ?? '']), 'Escolha onde salvar a resposta de “'.$label.'”.');
            }
            if ($node['type'] === 'action') {
                $this->check(isset(self::ACTIONS[$node['action'] ?? '']), 'Escolha uma ação disponível para “'.$label.'”.');
            }
            if ($node['type'] === 'menu') {
                $this->check(count($node['options'] ?? []) >= 1, 'Adicione uma opção ao menu “'.$label.'”.');
            }
            if ($node['type'] === 'ai') {
                $this->check(trim((string) ($node['instruction'] ?? '')) !== '', 'Escreva a instrução da IA em “'.$label.'”.');
                $this->check(isset(self::AI_FIELDS[$node['field'] ?? '']), 'Escolha o que a IA coleta em “'.$label.'”.');
                $this->check(count($node['outcomes'] ?? []) >= 1, 'Adicione uma saída ao bloco de IA “'.$label.'”.');
                $outcomeIds = array_column($node['outcomes'], 'id');
                $this->check(count($outcomeIds) === count(array_unique($outcomeIds)), 'As saídas de “'.$label.'” precisam de identificadores diferentes.');
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
            $done = [];
            $visit = function (string $id, array $path) use (&$visit, &$done, $nodes): void {
                $this->check(! in_array($id, $path, true), 'Há um ciclo sem pergunta, menu ou IA. Corrija as ligações antes de publicar.');
                if (isset($done[$id])) {
                    return;
                }
                if (in_array($nodes[$id]['type'], ['question', 'menu', 'ai', 'end'], true)) {
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
            'ai' => array_map(fn ($outcome) => $outcome['next'] ?? '', $node['outcomes'] ?? []),
            default => [$node['next'] ?? ''],
        };
    }

    private function checkPlaceholders(string $text, string $label): void
    {
        preg_match_all('/\{([^{}]+)\}/u', $text, $matches);
        $this->check(array_diff($matches[1], self::PLACEHOLDERS) === [], 'Use apenas {nome}, {empresa}, {placa}, {servico} e {horario} em “'.$label.'”.');
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['flow' => $message]);
        }
    }
}
