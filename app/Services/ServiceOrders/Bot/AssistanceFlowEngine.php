<?php

namespace App\Services\ServiceOrders\Bot;

use Illuminate\Support\Str;
use RuntimeException;

/** Shared executor for the simulator and WhatsApp. Side effects are injected, never editable PHP. */
class AssistanceFlowEngine
{
    public function advance(array $definition, array $state, ?string $input, array $context, callable $action): array
    {
        $nodes = array_column($definition['nodes'], null, 'id');
        $state += ['cursor' => $definition['entry'], 'waiting' => false, 'data' => [], 'ended' => false];
        $output = [];
        if ($state['ended']) {
            return ['state' => $state, 'reply' => 'Digite menu para começar outro atendimento.'];
        }

        if ($state['waiting'] && $input !== null) {
            $node = $nodes[$state['cursor']];
            if ($node['type'] === 'question') {
                $value = Str::squish($input);
                if (mb_strlen($value) < 2 || mb_strlen($value) > 1000) {
                    return ['state' => $state, 'reply' => "Informe uma resposta de 2 a 1.000 caracteres.\n".$this->text($node['text'], $state, $context)];
                }
                $state['data'][$node['field']] = $value;
                $state['cursor'] = $node['next'];
            } elseif ($node['type'] === 'menu') {
                $index = ctype_digit(trim($input)) ? (int) trim($input) - 1 : null;
                $option = $index !== null ? ($node['options'][$index] ?? null) : collect($node['options'])->first(fn ($option) => Str::lower(Str::ascii($option['label'])) === Str::lower(Str::ascii(trim($input))));
                if (! $option) {
                    return ['state' => $state, 'reply' => 'Escolha uma das opções abaixo.'."\n".$this->prompt($node, $state, $context)];
                }
                if (($node['field'] ?? '') === 'accessory_category') {
                    $state['data']['accessory_category'] = $option['value'];
                }
                $state['cursor'] = $option['next'];
            }
            $state['waiting'] = false;
        }

        for ($i = 0; $i < 50; $i++) {
            $node = $nodes[$state['cursor']] ?? throw new RuntimeException('Invalid assistance flow cursor.');
            switch ($node['type']) {
                case 'question':
                case 'menu':
                    $output[] = $this->prompt($node, $state, $context);
                    $state['waiting'] = true;

                    return ['state' => $state, 'reply' => implode("\n\n", $output)];
                case 'end':
                    $output[] = $this->text($node['text'], $state, $context);
                    $state['ended'] = true;

                    return ['state' => $state, 'reply' => implode("\n\n", $output)];
                case 'message':
                    $output[] = $this->text($node['text'], $state, $context);
                    $state['cursor'] = $node['next'];
                    break;
                case 'condition':
                    $state['cursor'] = ($context[$node['condition']] ?? false) ? $node['yes'] : $node['no'];
                    break;
                case 'action':
                    $output[] = $action($node['action'], $state['data'], $node['id']);
                    $state['cursor'] = $node['next'];
                    break;
            }
        }

        throw new RuntimeException('Assistance flow exceeded automatic step limit.');
    }

    private function text(string $text, array $state, array $context): string
    {
        return strtr(str_replace('\\n', "\n", $text), [
            '{nome}' => $context['name'] ?? 'Cliente', '{empresa}' => $context['company'] ?? 'Assistência',
            '{aparelho}' => $state['data']['device'] ?? '', '{problema}' => $state['data']['problem'] ?? '',
        ]);
    }

    private function prompt(array $node, array $state, array $context): string
    {
        $text = $this->text($node['text'], $state, $context);
        if ($node['type'] === 'menu') {
            foreach ($node['options'] as $i => $option) {
                $text .= "\n".($i + 1).'. '.$option['label'];
            }
        }

        return $text;
    }
}
